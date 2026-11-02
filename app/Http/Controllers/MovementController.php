<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMovementRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MovementController extends Controller
{
    public function __construct(
        private StockService $stockService
    ) {}

    /**
     * Afficher la liste des mouvements et le formulaire.
     */
    public function index(Request $request)
    {
        $movements = StockMovement::with(['product', 'warehouse', 'destinationWarehouse', 'user'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $products = Product::orderBy('name')->get();
        $warehouses = Warehouse::where('is_active', true)->orderBy('name')->get();

        return Inertia::render('Movements/Index', [
            'movements' => $movements,
            'products' => $products,
            'warehouses' => $warehouses,
        ]);
    }

    /**
     * Créer un mouvement de stock via le formulaire web (Inertia).
     * Utilise le FormRequest centralisé pour garantir la parité stricte des règles avec l'API.
     */
    public function store(StoreMovementRequest $request)
    {
        try {
            $this->stockService->createMovement($request->validated(), $request->user()->id);

            return redirect()->route('movements.index')
                ->with('success', 'Mouvement de stock créé avec succès.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        }
    }
}

