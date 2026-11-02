<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Afficher le tableau de bord avec les statistiques.
     */
    public function index()
    {
        $totalProducts = Product::count();
        $totalWarehouses = Warehouse::where('is_active', true)->count();
        $totalMovements = StockMovement::count();
        $totalStockValue = StockLevel::join('products', 'stock_levels.product_id', '=', 'products.id')
            ->selectRaw('SUM(stock_levels.quantity * products.price) as total')
            ->value('total') ?? 0;

        $recentMovements = StockMovement::with(['product', 'warehouse', 'destinationWarehouse', 'user'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $lowStockAlerts = StockLevel::with(['product', 'warehouse'])
            ->where('quantity', '<=', 10)
            ->where('quantity', '>', 0)
            ->get();

        $outOfStock = StockLevel::with(['product', 'warehouse'])
            ->where('quantity', 0)
            ->get();

        // Données pour graphiques : mouvements par jour (7 derniers jours)
        $movementsByDay = StockMovement::selectRaw('DATE(created_at) as date, type, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy('date', 'type')
            ->orderBy('date')
            ->get();

        // Stock par entrepôt
        $stockByWarehouse = Warehouse::withCount(['stockLevels as total_quantity' => function ($query) {
            $query->select(\Illuminate\Support\Facades\DB::raw('COALESCE(SUM(quantity), 0)'));
        }])->where('is_active', true)->get();

        return Inertia::render('Dashboard', [
            'stats' => [
                'totalProducts' => $totalProducts,
                'totalWarehouses' => $totalWarehouses,
                'totalMovements' => $totalMovements,
                'totalStockValue' => number_format((float) $totalStockValue, 2, ',', ' '),
            ],
            'recentMovements' => $recentMovements,
            'lowStockAlerts' => $lowStockAlerts,
            'outOfStock' => $outOfStock,
            'movementsByDay' => $movementsByDay,
            'stockByWarehouse' => $stockByWarehouse,
        ]);
    }
}

