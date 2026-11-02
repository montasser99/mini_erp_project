<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProductController extends Controller
{
    /**
     * Afficher la liste des produits.
     */
    public function index()
    {
        $products = Product::with('stockLevels.warehouse')
            ->orderBy('name')
            ->get();

        return Inertia::render('Products/Index', [
            'products' => $products,
        ]);
    }

    /**
     * Enregistrer un nouveau produit.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:50', 'unique:products,sku'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        Product::create($validated);

        return redirect()->route('products.index')
            ->with('success', 'Produit créé avec succès.');
    }

    /**
     * Mettre à jour un produit.
     */
    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:50', 'unique:products,sku,' . $product->id],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $product->update($validated);

        return redirect()->route('products.index')
            ->with('success', 'Produit mis à jour avec succès.');
    }

    /**
     * Supprimer un produit avec protection d'intégrité ERP.
     *
     * Règle d'or ERP : Un article rattaché à des stocks ou à un journal
     * d'audit (mouvements) NE PEUT PAS être supprimé physiquement.
     * Il doit être archivé (is_active = false).
     */
    public function destroy(Product $product)
    {
        // 1. Vérifier s'il y a des mouvements historiques
        if ($product->stockMovements()->exists()) {
            return redirect()->route('products.index')
                ->with('error', "Suppression interdite : le produit '{$product->name}' possède un historique de mouvements d'audit immuable. Veuillez plutôt le désactiver.");
        }

        // 2. Vérifier s'il y a du stock actif
        $hasActiveStock = $product->stockLevels()->where('quantity', '>', 0)->exists();
        if ($hasActiveStock) {
            return redirect()->route('products.index')
                ->with('error', "Suppression interdite : le produit '{$product->name}' a du stock disponible en entrepôt.");
        }

        // 3. Suppression autorisée uniquement si aucun mouvement et stock nul
        $product->stockLevels()->delete();
        $product->delete();

        return redirect()->route('products.index')
            ->with('success', 'Produit supprimé avec succès.');
    }
}
