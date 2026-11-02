<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * StockService — Moteur Transactionnel d'Intégrité du Stock Multi-Entrepôts.
 *
 * Architecture & Garanties Mathématiques :
 *
 * 1. Transactions ACID & Atomicité :
 *    Toutes les opérations (débit, crédit, enregistrement du ledger) sont exécutées
 *    dans une transaction unifiée DB::transaction. En cas d'erreur ou d'exception,
 *    un ROLLBACK intégral garantit l'absence de modifications partielles.
 *
 * 2. Positivité Stricte du Stock :
 *    Le stock disponible est vérifié sous verrou pessimiste exclusif (X-lock).
 *    Aucun prélèvement ne peut excéder le stock actuel (∀ s, s.quantity >= 0).
 *    Doublé en base par la contrainte native MySQL 9.1 CHECK(quantity >= 0).
 *
 * 3. Prévention & Résolution des Deadlocks (MySQL Error 1213 / 1205) :
 *    - Prévention : Ordonnancement canonique strict des verrous par tri croissant
 *      sur le couple (product_id, min(w1, w2)) puis (product_id, max(w1, w2)).
 *    - Récupération : Stratégie de retry transactionnel bornée (5 tentatives)
 *      via DB::transaction($callback, MAX_TRANSACTION_RETRIES) avec détection native
 *      des Deadlocks (40001 / 1213) et Lock Wait Timeouts (1205).
 *    - Note d'architecture : Aucun système relationnel à verrous fins ne peut garantir
 *      mathématiquement 0 deadlock (conversions de verrous S->X, gap locks, verrous
 *      de clés étrangères). La combinaison Prévention + Retry automatique est le
 *      standard de l'état de l'art recommandé par MySQL InnoDB.
 *
 * 4. Gestion Sécurisée des Lignes de Stock Absentes :
 *    Création concurrente sécurisée sans verrous de gap destructeurs. En cas de
 *    collision d'insertion simultanée (code MySQL 1062 - ER_DUP_ENTRY), l'erreur
 *    spécifique est interceptée et la ligne insérée par la transaction concurrente
 *    est immédiatement verrouillée via lockForUpdate(). Les autres erreurs SQL
 *    (deadlocks, timeouts, contraintes FK) ne sont JAMAIS masquées et sont propagées.
 *
 * 5. Intégrité des Statuts d'Entrepôt et Produit :
 *    Interdiction stricte de mouvement sur un produit inactif ou un entrepôt désactivé.
 */
class StockService
{
    /**
     * Nombre maximum de tentatives de réexécution automatique en cas de deadlock (1213)
     * ou de timeout de verrouillage (1205).
     */
    public const MAX_TRANSACTION_RETRIES = 5;

    /**
     * Créer un mouvement de stock (IN, OUT, TRANSFER).
     *
     * @param array $data
     * @param int $userId
     * @return StockMovement
     * @throws ValidationException
     */
    public function createMovement(array $data, int $userId): StockMovement
    {
        return DB::transaction(function () use ($data, $userId) {
            $type = $data['type'] ?? '';
            $productId = (int) ($data['product_id'] ?? 0);
            $warehouseId = (int) ($data['warehouse_id'] ?? 0);
            $rawQuantity = $data['quantity'] ?? null;
            $destinationWarehouseId = isset($data['destination_warehouse_id']) && $data['destination_warehouse_id'] !== ''
                ? (int) $data['destination_warehouse_id']
                : null;

            // 1. Validation stricte de la quantité : entier positif (> 0)
            if (!is_numeric($rawQuantity) || (int) $rawQuantity <= 0 || (float) $rawQuantity != (int) $rawQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => ['La quantité doit être un nombre entier strictement supérieur à 0.'],
                ]);
            }
            $quantity = (int) $rawQuantity;

            // 2. Validation d'existence et d'activité du produit
            $product = Product::findOrFail($productId);
            if (!$product->is_active) {
                throw ValidationException::withMessages([
                    'product_id' => ["Le produit '{$product->name}' est inactif et ne peut plus faire l'objet de mouvements de stock."],
                ]);
            }

            // 3. Validation d'existence et d'activité de l'entrepôt source
            $warehouse = Warehouse::findOrFail($warehouseId);
            if (!$warehouse->is_active) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ["L'entrepôt '{$warehouse->name}' est désactivé et ne peut participer à des mouvements de stock."],
                ]);
            }

            // 4. Exécution selon le type d'opération avec garanties d'invariants
            switch ($type) {
                case 'IN':
                    $destinationWarehouseId = null; // Garantir null dans le journal d'audit pour les entrées
                    $this->processEntry($productId, $warehouseId, $quantity);
                    break;

                case 'OUT':
                    $destinationWarehouseId = null; // Garantir null dans le journal d'audit pour les sorties
                    $this->processExit($productId, $warehouseId, $quantity);
                    break;

                case 'TRANSFER':
                    if (!$destinationWarehouseId) {
                        throw ValidationException::withMessages([
                            'destination_warehouse_id' => ["L'entrepôt de destination est requis pour un transfert inter-entrepôts."],
                        ]);
                    }

                    if ($warehouseId === $destinationWarehouseId) {
                        throw ValidationException::withMessages([
                            'destination_warehouse_id' => ["L'entrepôt source et l'entrepôt de destination doivent être différents."],
                        ]);
                    }

                    $destWarehouse = Warehouse::findOrFail($destinationWarehouseId);
                    if (!$destWarehouse->is_active) {
                        throw ValidationException::withMessages([
                            'destination_warehouse_id' => ["L'entrepôt de destination '{$destWarehouse->name}' est désactivé."],
                        ]);
                    }

                    $this->processTransfer($productId, $warehouseId, $destinationWarehouseId, $quantity);
                    break;

                default:
                    throw ValidationException::withMessages([
                        'type' => ['Le type de mouvement doit être IN, OUT ou TRANSFER.'],
                    ]);
            }

            // 5. Enregistrement immuable dans le journal d'audit (Grand Livre comptable)
            return StockMovement::create([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'type' => $type,
                'quantity' => $quantity,
                'destination_warehouse_id' => $destinationWarehouseId,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'user_id' => $userId,
            ]);
        }, self::MAX_TRANSACTION_RETRIES);
    }

    /**
     * Entrée de stock — Crédit avec verrouillage exclusif ordonné.
     */
    private function processEntry(int $productId, int $warehouseId, int $quantity): void
    {
        $stockLevel = $this->lockOrCreateStockLevel($productId, $warehouseId);
        $stockLevel->quantity += $quantity;
        $stockLevel->save();
    }

    /**
     * Sortie de stock — Débit sous verrouillage exclusif pessimiste (Anti-Stock Négatif).
     */
    private function processExit(int $productId, int $warehouseId, int $quantity): void
    {
        $stockLevel = StockLevel::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        $currentQty = $stockLevel ? $stockLevel->quantity : 0;

        if (!$stockLevel || $currentQty < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => [
                    "Stock insuffisant. Stock actuel : {$currentQty}, demandé : {$quantity}."
                ],
            ]);
        }

        $stockLevel->quantity -= $quantity;
        $stockLevel->save();
    }

    /**
     * Transfert inter-entrepôts — Débit/Crédit atomique avec ordonnancement canonique des verrous.
     *
     * Prévention des Deadlocks :
     * Les verrous sont posés de façon strictement déterministe dans l'ordre croissant de warehouse_id
     * (min puis max). Deux transferts croisés simultanés (A -> B et B -> A) acquièrent donc les verrous
     * dans le même ordre séquentiel, éliminant la formation d'un cycle d'attente circulaire (Wait-For Graph).
     */
    private function processTransfer(int $productId, int $sourceId, int $destinationId, int $quantity): void
    {
        $firstWarehouseId = min($sourceId, $destinationId);
        $secondWarehouseId = max($sourceId, $destinationId);

        // Verrouillage ordonné canonique
        $firstLevel = $this->lockOrCreateStockLevel($productId, $firstWarehouseId);
        $secondLevel = $this->lockOrCreateStockLevel($productId, $secondWarehouseId);

        // Réassignation exacte source / destination
        $sourceLevel = ($sourceId === $firstWarehouseId) ? $firstLevel : $secondLevel;
        $destLevel = ($destinationId === $firstWarehouseId) ? $firstLevel : $secondLevel;

        if ($sourceLevel->quantity < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => [
                    "Stock insuffisant dans l'entrepôt source. Stock actuel : {$sourceLevel->quantity}, demandé : {$quantity}."
                ],
            ]);
        }

        $sourceLevel->quantity -= $quantity;
        $sourceLevel->save();

        $destLevel->quantity += $quantity;
        $destLevel->save();
    }

    /**
     * Verrouille une ligne de stock existante ou l'initialise de manière résiliente aux accès concurrents.
     *
     * Stratégie Concurrente :
     * 1. SELECT ... FOR UPDATE immédiat (chemin nominal 99% : la ligne existe déjà).
     * 2. Si absente, tentative d'INSERT avec quantity = 0.
     * 3. Si collision concurrente (Code MySQL 1062 - ER_DUP_ENTRY) : une autre transaction vient d'insérer
     *    la ligne. On la relit et on la verrouille immédiatement avec lockForUpdate().
     * 4. Toute autre erreur SQL (Deadlock 1213, Timeout 1205, violation de contrainte 3819 ou 1452) est
     *    propagée sans être étouffée pour permettre le retry de la transaction.
     */
    public function lockOrCreateStockLevel(int $productId, int $warehouseId): StockLevel
    {
        // 1. Essai de lecture sous verrou exclusif
        $stockLevel = StockLevel::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if ($stockLevel !== null) {
            return $stockLevel;
        }

        // 2. Ligne absente : insertion contrôlée
        try {
            return StockLevel::create([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'quantity' => 0,
            ]);
        } catch (QueryException $e) {
            // Interception STRICTE de l'erreur d'unicité (MySQL 1062 / SQLite 19)
            if ($this->isUniqueConstraintViolation($e)) {
                return StockLevel::where('product_id', $productId)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            // Ne JAMAIS masquer les autres erreurs SQL (deadlocks, timeouts, etc.)
            throw $e;
        }
    }

    /**
     * Détermine si l'exception SQL correspond spécifiquement à une collision de contrainte UNIQUE.
     *
     * - MySQL : Code d'erreur 1062 (ER_DUP_ENTRY) ou SQLSTATE 23000
     * - SQLite : Code d'erreur 19 (SQLITE_CONSTRAINT) avec clause UNIQUE
     */
    public function isUniqueConstraintViolation(QueryException $e): bool
    {
        $errorCode = $e->errorInfo[1] ?? null;

        // Code MySQL 1062 : ER_DUP_ENTRY
        if ($errorCode === 1062) {
            return true;
        }

        // Code SQLite 19 : SQLITE_CONSTRAINT
        if ($errorCode === 19 && str_contains($e->getMessage(), 'UNIQUE')) {
            return true;
        }

        $message = $e->getMessage();
        return str_contains($message, 'Duplicate entry') || str_contains($message, 'UNIQUE constraint failed');
    }

    /**
     * Consultation du stock (GET /stock ou GET /api/stock).
     *
     * @param int|null $productId
     * @param int|null $warehouseId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getStock(?int $productId = null, ?int $warehouseId = null)
    {
        $query = StockLevel::with(['product', 'warehouse']);

        if ($productId) {
            $query->where('product_id', $productId);
        }

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->get();
    }
}
