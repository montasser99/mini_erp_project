<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Page d'accueil / redirection vers Dashboard
Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// =========================================================================
// Routes Web protégées par Authentification Session (Inertia.js Frontend)
// =========================================================================
Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Produits CRUD
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

    // Entrepôts CRUD
    Route::get('/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
    Route::post('/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
    Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
    Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');

    // Mouvements de stock (Web UI)
    Route::get('/movements', [MovementController::class, 'index'])->name('movements.index');
    Route::post('/movements', function (Request $request) {
        if ($request->wantsJson()) {
            return app(StockController::class)->storeMovement(
                app(\App\Http\Requests\StoreMovementRequest::class)
            );
        }
        return app(MovementController::class)->store($request);
    })->name('movements.store');

    // Stock direct (Web ou JSON)
    Route::get('/stock', [StockController::class, 'getStock'])->name('stock.get');

    // Profil utilisateur
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// =========================================================================
// API REST (Auth Token Sanctum + Support Authentification Session)
// =========================================================================

// Endpoint d'authentification pour Postman / clients externes
Route::post('/api/login', [AuthController::class, 'login']);

// Groupe API avec support double auth (Sanctum Token ou Session Authentifiée)
Route::middleware(['auth:sanctum'])->group(function () {
    // Déconnexion API
    Route::post('/api/logout', [AuthController::class, 'logout']);

    // Endpoints API officiels demandés dans le sujet :
    // 1) POST /movements (et alias POST /api/movements)
    Route::post('/api/movements', [StockController::class, 'storeMovement']);

    // 2) GET /stock?productId=&warehouseId= (et alias GET /api/stock)
    Route::get('/api/stock', [StockController::class, 'getStock']);
});

require __DIR__.'/auth.php';
