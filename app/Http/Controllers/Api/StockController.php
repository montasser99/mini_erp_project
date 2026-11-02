<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMovementRequest;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * StockController — API REST pour la gestion de stock.
 *
 * Endpoints :
 * - POST /api/movements  → Créer un mouvement de stock
 * - GET  /api/stock      → Consulter le stock (filtrable par productId, warehouseId)
 */
class StockController extends Controller
{
    public function __construct(
        private StockService $stockService
    ) {}

    /**
     * POST /api/movements
     *
     * Créer un mouvement de stock (IN, OUT, TRANSFER).
     * Utilise des transactions et un verrouillage pessimiste.
     */
    public function storeMovement(StoreMovementRequest $request): JsonResponse
    {
        try {
            $movement = $this->stockService->createMovement(
                $request->validated(),
                $request->user()->id
            );

            return response()->json([
                'message' => 'Mouvement de stock créé avec succès.',
                'data' => $movement->load(['product', 'warehouse', 'destinationWarehouse', 'user']),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur interne du serveur.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/stock?productId=&warehouseId=
     *
     * Consulter le stock par produit et/ou entrepôt.
     */
    public function getStock(Request $request): JsonResponse
    {
        $productId = $request->query('productId');
        $warehouseId = $request->query('warehouseId');

        $stock = $this->stockService->getStock(
            $productId ? (int) $productId : null,
            $warehouseId ? (int) $warehouseId : null
        );

        return response()->json([
            'data' => $stock,
        ]);
    }
}

