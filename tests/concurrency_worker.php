<?php

// Forcer la connexion MySQL réelle pour le test de concurrence
putenv('DB_CONNECTION=mysql');
putenv('DB_DATABASE=mini_erp_stock');
$_ENV['DB_CONNECTION'] = 'mysql';
$_ENV['DB_DATABASE'] = 'mini_erp_stock';

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// S'assurer que la connexion par défaut est bien MySQL
config(['database.default' => 'mysql']);

$productId = (int)$argv[1];
$warehouseId = (int)$argv[2];
$qty = (int)$argv[3];
$userId = (int)$argv[4];

try {
    $service = app(\App\Services\StockService::class);
    $movement = $service->createMovement([
        'product_id' => $productId,
        'warehouse_id' => $warehouseId,
        'type' => 'OUT',
        'quantity' => $qty,
        'reference' => 'CONC-' . $qty,
    ], $userId);

    echo json_encode(['status' => 'SUCCESS', 'qty' => $qty]);
    exit(0);
} catch (\Illuminate\Validation\ValidationException $e) {
    echo json_encode(['status' => 'REJECTED', 'errors' => $e->errors()]);
    exit(1);
} catch (\Exception $e) {
    echo json_encode(['status' => 'ERROR', 'message' => $e->getMessage()]);
    exit(2);
}
