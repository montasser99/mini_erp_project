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
 * StockService — Logique métier commune pour la gestion de stock multi-entrepôts.
 *
 * Garanties architecturales :
 * - Cohérence transactionnelle ACID (DB::transaction)
 * - Verrouillage pessimiste (SELECT ... FOR UPDATE) ordonné pour éliminer les race conditions
 * - Prévention des deadlocks lors des transferts croisés (tri canonique min/max sur les identifiants)
 * - Interdiction formelle du stock négatif (quantité demandée <= stock actuel vérifié sous verrou)
 * - Gestion sécurisée des enregistrements absents et collisions d'insertions concurrentes
 */
class StockService
{
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
            $type = $data['type'];
            $productId = (int) $data['product_id'];
            $warehouseId = (int) $data['warehouse_id'];
            $quantity = (int) $data['quantity'];
            $destinationWarehouseId = isset($data['destination_warehouse_id']) ? (int) $data['destination_warehouse_id'] : null;

            // 1. Validation de la quantité : doit être un entier strictement positif
            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['La quantité doit être supérieure à 0.'],
                ]);
            }

            // 2. Validation d'existence des entités de base
            Product::findOrFail($productId);
            Warehouse::findOrFail($warehouseId);

            // 3. Exécution métier selon le type d'opération
            switch ($type) {
                case 'IN':
                    $this->processEntry($productId, $warehouseId, $quantity);
                    break;

                case 'OUT':
                    $this->processExit($productId, $warehouseId, $quantity);
                    break;

                case 'TRANSFER':
                    if (!$destinationWarehouseId) {
                        throw ValidationException::withMessages([
                            'destination_warehouse_id' => ['L\'entrepôt de destination est requis pour un transfert.'],
                        ]);
                    }
                    if ($warehouseId === $destinationWarehouseId) {
                        throw ValidationException::withMessages([
                            'destination_warehouse_id' => ['L\'entrepôt source et destination doivent être différents.'],
                        ]);
                    }
                    Warehouse::findOrFail($destinationWarehouseId);
                    $this->processTransfer($productId, $warehouseId, $destinationWarehouseId, $quantity);
                    break;

                default:
                    throw ValidationException::withMessages([
                        'type' => ['Le type de mouvement doit être IN, OUT ou TRANSFER.'],
                    ]);
            }

            // 4. Enregistrement immuable dans le journal d'audit (Ledger)
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
        });
    }

    /**
     * Entrée de stock — Crédit sécurisé avec verrouillage pessimiste.
     */
    private function processEntry(int $productId, int $warehouseId, int $quantity): void
    {
        $this->getOrCreateStockLevel($productId, $warehouseId);

        $stockLevel = StockLevel::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->firstOrFail();

        $stockLevel->quantity += $quantity;
        $stockLevel->save();
    }

    /**
     * Sortie de stock — Débit avec contrôle sous verrouillage pessimiste (anti-stock négatif).
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
     * Pour éliminer les deadlocks lors de transferts bidirectionnels simultanés :
     * Les verrous sont systématiquement posés dans l'ordre croissant de warehouse_id
     * en ciblant TOUJOURS le couple spécifique (product_id, warehouse_id).
     */
    private function processTransfer(int $productId, int $sourceId, int $destinationId, int $quantity): void
    {
        $firstWarehouseId = min($sourceId, $destinationId);
        $secondWarehouseId = max($sourceId, $destinationId);

        // Garantir l'existence des deux enregistrements avant verrouillage
        $this->getOrCreateStockLevel($productId, $firstWarehouseId);
        $this->getOrCreateStockLevel($productId, $secondWarehouseId);

        // Verrouillage dans l'ordre strict par produit ET entrepôt
        $firstLevel = StockLevel::where('product_id', $productId)
            ->where('warehouse_id', $firstWarehouseId)
            ->lockForUpdate()
            ->firstOrFail();

        $secondLevel = StockLevel::where('product_id', $productId)
            ->where('warehouse_id', $secondWarehouseId)
            ->lockForUpdate()
            ->firstOrFail();

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
     * Récupère ou initialise un niveau de stock de façon résiliente aux collisions concurrentes.
     */
    private function getOrCreateStockLevel(int $productId, int $warehouseId): StockLevel
    {
        try {
            return StockLevel::firstOrCreate(
                [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                ],
                ['quantity' => 0]
            );
        } catch (QueryException $e) {
            // Collision d'insertion concurrente (code MySQL 1062 - clef unique dupliquée)
            return StockLevel::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->firstOrFail();
        }
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
