<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WarehouseController extends Controller
{
    /**
     * Afficher la liste des entrepôts.
     */
    public function index()
    {
        $warehouses = Warehouse::withCount(['stockLevels as total_quantity' => function ($query) {
            $query->select(\Illuminate\Support\Facades\DB::raw('COALESCE(SUM(quantity), 0)'));
        }])->orderBy('name')->get();

        return Inertia::render('Warehouses/Index', [
            'warehouses' => $warehouses,
        ]);
    }

    /**
     * Enregistrer un nouvel entrepôt.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        Warehouse::create($validated);

        return redirect()->route('warehouses.index')
            ->with('success', 'Entrepôt créé avec succès.');
    }

    /**
     * Mettre à jour un entrepôt.
     */
    public function update(Request $request, Warehouse $warehouse)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $warehouse->update($validated);

        return redirect()->route('warehouses.index')
            ->with('success', 'Entrepôt mis à jour avec succès.');
    }

    /**
     * Supprimer un entrepôt avec protection d'intégrité ERP.
     *
     * Règle d'or ERP : Un entrepôt référencé dans des mouvements de stock
     * (source ou destination) ou détenant du stock actif NE PEUT PAS être
     * supprimé physiquement afin de garantir l'immuabilité de l'audit trail.
     * L'utilisateur doit plutôt le désactiver (is_active = false).
     */
    public function destroy(Warehouse $warehouse)
    {
        // 1. Vérifier les mouvements historiques source ou destination
        if ($warehouse->stockMovements()->exists() || $warehouse->destinationStockMovements()->exists()) {
            return redirect()->route('warehouses.index')
                ->with('error', "Suppression interdite : l'entrepôt '{$warehouse->name}' est référencé dans des mouvements de stock immuables. Veuillez plutôt le désactiver.");
        }

        // 2. Vérifier s'il y a du stock physique résiduel
        $hasActiveStock = $warehouse->stockLevels()->where('quantity', '>', 0)->exists();
        if ($hasActiveStock) {
            return redirect()->route('warehouses.index')
                ->with('error', "Suppression interdite : l'entrepôt '{$warehouse->name}' contient encore des articles en stock.");
        }

        // 3. Suppression autorisée uniquement si aucun mouvement et stock nul
        $warehouse->stockLevels()->delete();
        $warehouse->delete();

        return redirect()->route('warehouses.index')
            ->with('success', 'Entrepôt supprimé avec succès.');
    }
}

