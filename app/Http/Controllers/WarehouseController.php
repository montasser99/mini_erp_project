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
     * Supprimer un entrepôt.
     */
    public function destroy(Warehouse $warehouse)
    {
        $warehouse->delete();

        return redirect()->route('warehouses.index')
            ->with('success', 'Entrepôt supprimé avec succès.');
    }
}

