<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\Route;

// Authentification API avec protection contre le brute-force (10 tentatives / min)
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Routes protégées par Sanctum Token
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Endpoints officiels demandés dans le sujet :
    Route::post('/movements', [StockController::class, 'storeMovement']);
    Route::get('/stock', [StockController::class, 'getStock']);
});

