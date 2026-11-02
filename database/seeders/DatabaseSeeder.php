<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Utilisateurs
        $admin = User::firstOrCreate(
            ['email' => 'admin@erp.com'],
            [
                'name' => 'Montasser Benouirane',
                'password' => Hash::make('password'),
            ]
        );

        $operator = User::firstOrCreate(
            ['email' => 'operator@erp.com'],
            [
                'name' => 'Sarah Logistique',
                'password' => Hash::make('password'),
            ]
        );

        // 2. Entrepôts
        $wParis = Warehouse::firstOrCreate(
            ['name' => 'Entrepôt Central Paris'],
            [
                'location' => 'Zone Industrielle Nord, 93290 Tremblay',
                'is_active' => true,
            ]
        );

        $wLyon = Warehouse::firstOrCreate(
            ['name' => 'Entrepôt Régional Lyon'],
            [
                'location' => 'Parc Logistique Sud, 69800 Saint-Priest',
                'is_active' => true,
            ]
        );

        $wLille = Warehouse::firstOrCreate(
            ['name' => 'Hub Logistique Lille'],
            [
                'location' => 'Plateforme Delta 3, 62119 Dourges',
                'is_active' => true,
            ]
        );

        // 3. Produits
        $productsData = [
            [
                'name' => 'Dell Latitude 5540 i7 32GB',
                'sku' => 'LAP-DELL-5540',
                'description' => 'PC Portable Pro 15.6" Full HD, Intel Core i7, 32 Go RAM, SSD 1 To',
                'price' => 1250.00,
            ],
            [
                'name' => 'Écran Dell UltraSharp 27" 4K',
                'sku' => 'MON-DELL-U2723QE',
                'description' => 'Écran 4K IPS Black avec concentrateur USB-C 90W',
                'price' => 549.90,
            ],
            [
                'name' => 'Souris Logitech MX Master 3S',
                'sku' => 'ACC-LOGI-MX3S',
                'description' => 'Souris sans fil ergonomique haute précision 8000 DPI',
                'price' => 99.00,
            ],
            [
                'name' => 'Clavier Logitech MX Keys S',
                'sku' => 'ACC-LOGI-MXKEYS',
                'description' => 'Clavier sans fil rétroéclairé Bluetooth / Logi Bolt',
                'price' => 119.00,
            ],
            [
                'name' => 'Serveur Dell PowerEdge R750',
                'sku' => 'SRV-DELL-R750',
                'description' => 'Serveur Rack 2U Bi-processeur Xeon Silver, 64 Go RAM, SAS 8x2.4TB',
                'price' => 4890.00,
            ],
            [
                'name' => 'Câble USB-C Thunderbolt 4 (2m)',
                'sku' => 'CAB-TB4-2M',
                'description' => 'Câble 40 Gbps charge 100W et affichage 8K',
                'price' => 35.00,
            ],
        ];

        $products = [];
        foreach ($productsData as $data) {
            $products[] = Product::firstOrCreate(['sku' => $data['sku']], $data);
        }

        // 4. Mouvements initiaux avec StockService pour garantir la cohérence
        // Règle d'or ERP : Seeder idempotent — vérifie la référence unique avant insertion.
        $stockService = app(StockService::class);
        $seedMovement = function (array $data, int $userId) use ($stockService) {
            if (!StockMovement::where('reference', $data['reference'])->exists()) {
                $stockService->createMovement($data, $userId);
            }
        };

        // Entrées de stock à Paris
        $seedMovement([
            'product_id' => $products[0]->id, // Dell Laptop
            'warehouse_id' => $wParis->id,
            'type' => 'IN',
            'quantity' => 100,
            'reference' => 'PO-2026-001',
            'notes' => 'Livraison fournisseur Dell usine',
        ], $admin->id);

        $seedMovement([
            'product_id' => $products[1]->id, // Écran
            'warehouse_id' => $wParis->id,
            'type' => 'IN',
            'quantity' => 60,
            'reference' => 'PO-2026-002',
            'notes' => 'Stock initial écrans 4K',
        ], $admin->id);

        $seedMovement([
            'product_id' => $products[2]->id, // Souris
            'warehouse_id' => $wParis->id,
            'type' => 'IN',
            'quantity' => 150,
            'reference' => 'PO-2026-003',
            'notes' => 'Accessoires ergonomiques',
        ], $operator->id);

        $seedMovement([
            'product_id' => $products[3]->id, // Clavier
            'warehouse_id' => $wParis->id,
            'type' => 'IN',
            'quantity' => 80,
            'reference' => 'PO-2026-004',
            'notes' => 'Périphériques de saisie',
        ], $operator->id);

        $seedMovement([
            'product_id' => $products[4]->id, // Serveur
            'warehouse_id' => $wParis->id,
            'type' => 'IN',
            'quantity' => 8,
            'reference' => 'PO-2026-005',
            'notes' => 'Serveurs infrastructure datacenter',
        ], $admin->id);

        $seedMovement([
            'product_id' => $products[5]->id, // Câbles
            'warehouse_id' => $wParis->id,
            'type' => 'IN',
            'quantity' => 200,
            'reference' => 'PO-2026-006',
            'notes' => 'Stock connectique',
        ], $operator->id);

        // Entrées directes à Lyon
        $seedMovement([
            'product_id' => $products[0]->id,
            'warehouse_id' => $wLyon->id,
            'type' => 'IN',
            'quantity' => 25,
            'reference' => 'PO-2026-007',
            'notes' => 'Réception locale Lyon',
        ], $operator->id);

        $seedMovement([
            'product_id' => $products[2]->id,
            'warehouse_id' => $wLyon->id,
            'type' => 'IN',
            'quantity' => 40,
            'reference' => 'PO-2026-008',
            'notes' => 'Stock local souris Lyon',
        ], $operator->id);

        // Transferts Inter-Entrepôts de Paris vers Lyon et Lille
        $seedMovement([
            'product_id' => $products[0]->id, // Transfert 15 PC vers Lyon
            'warehouse_id' => $wParis->id,
            'destination_warehouse_id' => $wLyon->id,
            'type' => 'TRANSFER',
            'quantity' => 15,
            'reference' => 'TR-2026-001',
            'notes' => 'Réapprovisionnement agence Auvergne-Rhône-Alpes',
        ], $admin->id);

        $seedMovement([
            'product_id' => $products[1]->id, // Transfert 20 Écrans vers Lille
            'warehouse_id' => $wParis->id,
            'destination_warehouse_id' => $wLille->id,
            'type' => 'TRANSFER',
            'quantity' => 20,
            'reference' => 'TR-2026-002',
            'notes' => 'Déploiement nouvel open space Nord',
        ], $operator->id);

        $seedMovement([
            'product_id' => $products[5]->id, // Transfert 50 Câbles vers Lille
            'warehouse_id' => $wParis->id,
            'destination_warehouse_id' => $wLille->id,
            'type' => 'TRANSFER',
            'quantity' => 50,
            'reference' => 'TR-2026-003',
            'notes' => 'Dotation câblage Lille',
        ], $operator->id);

        // Sorties de stock (Expéditions clients)
        $seedMovement([
            'product_id' => $products[0]->id, // Sortie 10 PC
            'warehouse_id' => $wParis->id,
            'type' => 'OUT',
            'quantity' => 10,
            'reference' => 'EXP-2026-012',
            'notes' => 'Commande client Société Générale',
        ], $admin->id);

        $seedMovement([
            'product_id' => $products[2]->id, // Sortie 25 Souris
            'warehouse_id' => $wParis->id,
            'type' => 'OUT',
            'quantity' => 25,
            'reference' => 'EXP-2026-013',
            'notes' => 'Pack bienvenue nouveaux collaborateurs',
        ], $operator->id);

        $seedMovement([
            'product_id' => $products[4]->id, // Sortie 2 Serveurs
            'warehouse_id' => $wParis->id,
            'type' => 'OUT',
            'quantity' => 2,
            'reference' => 'EXP-2026-014',
            'notes' => 'Installation baie datacenter Telehouse',
        ], $admin->id);
    }
}
