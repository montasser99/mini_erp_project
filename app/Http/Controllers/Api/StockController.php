<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMovementRequest;
use App\Services\StockService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * StockController — Contrôleur API REST conforme aux spécifications ERP.
 *
 * Endpoints :
 * - POST /movements  (alias POST /api/movements)  → Créer un mouvement de stock
 * - GET  /stock      (alias GET  /api/stock)      → Consulter le stock filtrable
 */
class StockController extends Controller
{
    public function __construct(
        private StockService $stockService
    ) {}

    /**
     * POST /movements ou POST /api/movements
     *
     * Créer un mouvement de stock (IN, OUT, TRANSFER).
     *
     * Codes HTTP retournés :
     * - 201 Created : Mouvement enregistré avec succès.
     * - 401 Unauthorized : Non authentifié (Bearer token absent ou révoqué).
     * - 404 Not Found : Produit ou entrepôt introuvable.
     * - 422 Unprocessable Content : Échec de validation métier ou stock insuffisant.
     * - 500 Internal Server Error : Erreur inattendue sécurisée (sans divulgation de trace interne).
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
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation.',
                'errors' => $e->errors(),
            ], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Ressource introuvable.',
            ], 404);
        } catch (Throwable $e) {
            Log::error('Erreur interne lors de la création du mouvement : ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Une erreur interne est survenue lors du traitement du mouvement.',
            ], 500);
        }
    }

    /**
     * GET /stock ou GET /api/stock?productId=&warehouseId=
     *
     * Consulter le stock actuel, filtrable par productId et/ou warehouseId.
     *
     * Validation stricte des query parameters :
     * - productId : entier positif >= 1
     * - warehouseId : entier positif >= 1
     */
    public function getStock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'productId' => ['nullable', 'integer', 'min:1'],
            'warehouseId' => ['nullable', 'integer', 'min:1'],
        ], [
            'productId.integer' => 'Le paramètre productId doit être un nombre entier valide.',
            'productId.min' => 'Le paramètre productId doit être strictement supérieur à 0.',
            'warehouseId.integer' => 'Le paramètre warehouseId doit être un nombre entier valide.',
            'warehouseId.min' => 'Le paramètre warehouseId doit être strictement supérieur à 0.',
        ]);

        $productId = isset($validated['productId']) && $validated['productId'] !== ''
            ? (int) $validated['productId']
            : null;

        $warehouseId = isset($validated['warehouseId']) && $validated['warehouseId'] !== ''
            ? (int) $validated['warehouseId']
            : null;

        $stock = $this->stockService->getStock($productId, $warehouseId);

        return response()->json([
            'data' => $stock,
        ], 200);
    }
}
