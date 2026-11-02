<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

// Authentification API (pour Postman / clients externes)
Route::post('/login', [AuthController::class, 'login']);

// Routes protégées par Sanctum Token
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Endpoints officiels demandés dans le sujet :
    Route::post('/movements', [StockController::class, 'storeMovement']);
    Route::get('/stock', [StockController::class, 'getStock']);
});

