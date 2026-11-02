<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Migration corrective non-destructive :
     * 1. Conversion formelle du moteur MySQL vers InnoDB (Transactions + Verrous + Clés étrangères).
     * 2. Remplacement des suppressions en cascade (ON DELETE CASCADE) par ON DELETE RESTRICT
     *    pour sanctuariser l'immuabilité du journal d'audit (stock_movements) et des stocks (stock_levels).
     * 3. Ajout de contraintes SQL CHECK natives (quantity >= 0 sur stock_levels, quantity > 0 sur stock_movements).
     * 4. Ajout de la colonne is_active sur products pour favoriser l'archivage logique plutôt que la suppression physique.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1. Assurer le moteur InnoDB sur MySQL
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE users ENGINE = InnoDB');
            DB::statement('ALTER TABLE products ENGINE = InnoDB');
            DB::statement('ALTER TABLE warehouses ENGINE = InnoDB');
            DB::statement('ALTER TABLE stock_levels ENGINE = InnoDB');
            DB::statement('ALTER TABLE stock_movements ENGINE = InnoDB');
        }

        // 2. Colonne is_active sur products (Archivage logique)
        if (!Schema::hasColumn('products', 'is_active')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('price');
            });
        }

        // 3. Verrouillage strict de l'intégrité référentielle (RESTRICT)
        if ($driver === 'mysql') {
            $databaseName = DB::getDatabaseName();

            // Fonction helper pour lister les clés étrangères existantes
            $getFkNames = function (string $table) use ($databaseName) {
                return DB::table('information_schema.TABLE_CONSTRAINTS')
                    ->where('CONSTRAINT_SCHEMA', $databaseName)
                    ->where('TABLE_NAME', $table)
                    ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
                    ->pluck('CONSTRAINT_NAME')
                    ->toArray();
            };

            // Table stock_levels
            $slFks = $getFkNames('stock_levels');
            if (in_array('stock_levels_product_id_foreign', $slFks)) {
                DB::statement('ALTER TABLE stock_levels DROP FOREIGN KEY stock_levels_product_id_foreign');
            }
            if (in_array('stock_levels_warehouse_id_foreign', $slFks)) {
                DB::statement('ALTER TABLE stock_levels DROP FOREIGN KEY stock_levels_warehouse_id_foreign');
            }

            DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE RESTRICT');

            // Table stock_movements
            $smFks = $getFkNames('stock_movements');
            if (in_array('stock_movements_product_id_foreign', $smFks)) {
                DB::statement('ALTER TABLE stock_movements DROP FOREIGN KEY stock_movements_product_id_foreign');
            }
            if (in_array('stock_movements_warehouse_id_foreign', $smFks)) {
                DB::statement('ALTER TABLE stock_movements DROP FOREIGN KEY stock_movements_warehouse_id_foreign');
            }
            if (in_array('stock_movements_destination_warehouse_id_foreign', $smFks)) {
                DB::statement('ALTER TABLE stock_movements DROP FOREIGN KEY stock_movements_destination_warehouse_id_foreign');
            }
            if (in_array('stock_movements_user_id_foreign', $smFks)) {
                DB::statement('ALTER TABLE stock_movements DROP FOREIGN KEY stock_movements_user_id_foreign');
            }

            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_product_id_foreign FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_warehouse_id_foreign FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_destination_warehouse_id_foreign FOREIGN KEY (destination_warehouse_id) REFERENCES warehouses (id) ON DELETE RESTRICT');
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_user_id_foreign FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT');

            // 4. Contraintes SQL CHECK natives (MySQL 8.0.16+ et 9.x)
            $checks = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $databaseName)
                ->where('CONSTRAINT_TYPE', 'CHECK')
                ->pluck('CONSTRAINT_NAME')
                ->toArray();

            if (!in_array('chk_stock_levels_quantity', $checks)) {
                DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT chk_stock_levels_quantity CHECK (quantity >= 0)');
            }

            if (!in_array('chk_stock_movements_quantity', $checks)) {
                DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movements_quantity CHECK (quantity > 0)');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            try { DB::statement('ALTER TABLE stock_levels DROP CONSTRAINT chk_stock_levels_quantity'); } catch (\Throwable $e) {}
            try { DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT chk_stock_movements_quantity'); } catch (\Throwable $e) {}
        }

        if (Schema::hasColumn('products', 'is_active')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
