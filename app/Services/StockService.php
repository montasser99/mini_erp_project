<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * StockService — Logique métier commune pour la gestion de stock.
 *
 * Garantit :
 * - La cohérence transactionnelle (DB::transaction)
 * - Le verrouillage pessimiste (lockForUpdate) contre les accès concurrents
 * - L'interdiction de stock négatif
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
            $productId = $data['product_id'];
            $warehouseId = $data['warehouse_id'];
            $quantity = (int) $data['quantity'];
            $destinationWarehouseId = $data['destination_warehouse_id'] ?? null;

            // Validation : la quantité doit être positive
            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['La quantité doit être supérieure à 0.'],
                ]);
            }

            // Validation : le produit et l'entrepôt doivent exister
            Product::findOrFail($productId);
            Warehouse::findOrFail($warehouseId);

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
                    if ($warehouseId == $destinationWarehouseId) {
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

            // Enregistrer le mouvement dans l'historique
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
     * Entrée de stock — ajouter au stock de l'entrepôt.
     */
    private function processEntry(int $productId, int $warehouseId, int $quantity): void
    {
        $stockLevel = $this->getOrCreateStockLevel($productId, $warehouseId);

        // Verrouillage pessimiste pour éviter les race conditions
        $stockLevel = StockLevel::where('id', $stockLevel->id)->lockForUpdate()->first();

        $stockLevel->quantity += $quantity;
        $stockLevel->save();
    }

    /**
     * Sortie de stock — retirer du stock avec vérification de stock négatif.
     */
    private function processExit(int $productId, int $warehouseId, int $quantity): void
    {
        $stockLevel = StockLevel::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->first();

        if (!$stockLevel || $stockLevel->quantity < $quantity) {
            $currentQty = $stockLevel ? $stockLevel->quantity : 0;
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
     * Transfert inter-entrepôts — retirer de la source, ajouter à la destination.
     *
     * Le verrouillage est toujours fait dans l'ordre croissant des IDs
     * pour éviter les deadlocks.
     */
    private function processTransfer(int $productId, int $sourceId, int $destinationId, int $quantity): void
    {
        // Éviter les deadlocks : verrouiller dans l'ordre croissant des warehouse IDs
        $firstId = min($sourceId, $destinationId);
        $secondId = max($sourceId, $destinationId);

        // Créer ou récupérer les niveaux de stock
        $this->getOrCreateStockLevel($productId, $firstId);
        $this->getOrCreateStockLevel($productId, $secondId);

        // Verrouiller dans l'ordre
        $firstLevel = StockLevel::where('product_id', $productId)
            ->where('warehouse_id', $firstId)
            ->lockForUpdate()
            ->first();

        $secondLevel = StockLevel::where('product_id', $productId)
            ->where('warehouse_id', $secondId)
            ->lockForUpdate()
            ->first();

        // Identifier source et destination
        $sourceLevel = ($sourceId === $firstId) ? $firstLevel : $secondLevel;
        $destLevel = ($destinationId === $firstId) ? $firstLevel : $secondLevel;

        // Vérifier le stock suffisant
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
     * Créer ou récupérer le StockLevel pour un produit/entrepôt.
     */
    private function getOrCreateStockLevel(int $productId, int $warehouseId): StockLevel
    {
        return StockLevel::firstOrCreate(
            [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
            ],
            ['quantity' => 0]
        );
    }

    /**
     * Consulter le stock (GET /stock).
     *
     * @param int|null $productId
     * @param int|null $warehouseId
     * @return \Illuminate\Support\Collection
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

