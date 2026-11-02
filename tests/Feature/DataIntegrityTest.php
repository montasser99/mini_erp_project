<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests de non-régression d'intégrité des données ERP :
 * 1. Protection du journal d'audit contre les suppressions (ON DELETE RESTRICT).
 * 2. Contrôleur ProductController & WarehouseController : interdiction des suppressions destructrices.
 * 3. Contrainte SQL CHECK(quantity >= 0) sur le moteur MySQL 9.1 InnoDB.
 * 4. Idempotence de DatabaseSeeder lors des réexécutions.
 * 5. Relations Eloquent (User->stockMovements, Warehouse->destinationStockMovements).
 */
class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * Test 1 : Le contrôleur interdit la suppression d'un produit ayant des mouvements historiques.
     */
    public function test_product_cannot_be_deleted_via_controller_if_it_has_movements(): void
    {
        $warehouse = Warehouse::create(['name' => 'W1', 'location' => 'Paris', 'is_active' => true]);
        $product = Product::create(['name' => 'P1', 'sku' => 'SKU-P1', 'price' => 10, 'is_active' => true]);

        StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'IN',
            'quantity' => 5,
            'reference' => 'REF-001',
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)->delete(route('products.destroy', $product));

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    /**
     * Test 2 : Le contrôleur interdit la suppression d'un entrepôt ayant des mouvements (source ou destination).
     */
    public function test_warehouse_cannot_be_deleted_via_controller_if_it_has_movements(): void
    {
        $w1 = Warehouse::create(['name' => 'W1', 'location' => 'Paris', 'is_active' => true]);
        $w2 = Warehouse::create(['name' => 'W2', 'location' => 'Lyon', 'is_active' => true]);
        $product = Product::create(['name' => 'P1', 'sku' => 'SKU-P1', 'price' => 10, 'is_active' => true]);

        StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $w1->id,
            'destination_warehouse_id' => $w2->id,
            'type' => 'TRANSFER',
            'quantity' => 3,
            'reference' => 'TR-001',
            'user_id' => $this->user->id,
        ]);

        // Essai de suppression de l'entrepôt source
        $res1 = $this->actingAs($this->user)->delete(route('warehouses.destroy', $w1));
        $res1->assertRedirect(route('warehouses.index'));
        $res1->assertSessionHas('error');
        $this->assertDatabaseHas('warehouses', ['id' => $w1->id]);

        // Essai de suppression de l'entrepôt destination
        $res2 = $this->actingAs($this->user)->delete(route('warehouses.destroy', $w2));
        $res2->assertRedirect(route('warehouses.index'));
        $res2->assertSessionHas('error');
        $this->assertDatabaseHas('warehouses', ['id' => $w2->id]);
    }

    /**
     * Test 3 : Le contrôleur interdit la suppression d'un produit avec du stock actif.
     */
    public function test_product_cannot_be_deleted_with_active_stock(): void
    {
        $warehouse = Warehouse::create(['name' => 'W1', 'location' => 'Paris', 'is_active' => true]);
        $product = Product::create(['name' => 'P2', 'sku' => 'SKU-P2', 'price' => 20, 'is_active' => true]);

        StockLevel::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 15,
        ]);

        $response = $this->actingAs($this->user)->delete(route('products.destroy', $product));

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    /**
     * Test 4 : Le contrôleur interdit la suppression d'un entrepôt avec du stock actif.
     */
    public function test_warehouse_cannot_be_deleted_with_active_stock(): void
    {
        $warehouse = Warehouse::create(['name' => 'W3', 'location' => 'Lille', 'is_active' => true]);
        $product = Product::create(['name' => 'P3', 'sku' => 'SKU-P3', 'price' => 30, 'is_active' => true]);

        StockLevel::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($this->user)->delete(route('warehouses.destroy', $warehouse));

        $response->assertRedirect(route('warehouses.index'));
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
    }

    /**
     * Test 5 : Suppression permise si aucun mouvement et aucun stock.
     */
    public function test_clean_product_and_warehouse_can_be_deleted(): void
    {
        $warehouse = Warehouse::create(['name' => 'W_Clean', 'location' => 'Nice', 'is_active' => true]);
        $product = Product::create(['name' => 'P_Clean', 'sku' => 'SKU-CLEAN', 'price' => 5, 'is_active' => true]);

        $resProduct = $this->actingAs($this->user)->delete(route('products.destroy', $product));
        $resProduct->assertRedirect(route('products.index'));
        $this->assertDatabaseMissing('products', ['id' => $product->id]);

        $resWarehouse = $this->actingAs($this->user)->delete(route('warehouses.destroy', $warehouse));
        $resWarehouse->assertRedirect(route('warehouses.index'));
        $this->assertDatabaseMissing('warehouses', ['id' => $warehouse->id]);
    }

    /**
     * Test 6 : Relations Eloquent requises.
     */
    public function test_eloquent_relationships(): void
    {
        $warehouse1 = Warehouse::create(['name' => 'W_Src', 'location' => 'A', 'is_active' => true]);
        $warehouse2 = Warehouse::create(['name' => 'W_Dst', 'location' => 'B', 'is_active' => true]);
        $product = Product::create(['name' => 'P_Rel', 'sku' => 'SKU-REL', 'price' => 10, 'is_active' => true]);

        $movement = StockMovement::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse1->id,
            'destination_warehouse_id' => $warehouse2->id,
            'type' => 'TRANSFER',
            'quantity' => 4,
            'reference' => 'REL-001',
            'user_id' => $this->user->id,
        ]);

        // User -> stockMovements
        $this->assertTrue($this->user->stockMovements->contains($movement));

        // Warehouse -> destinationStockMovements
        $this->assertTrue($warehouse2->destinationStockMovements->contains($movement));

        // Product -> stockMovements
        $this->assertTrue($product->stockMovements->contains($movement));

        // Product is_active cast
        $this->assertIsBool($product->is_active);
    }

    /**
     * Test 7 : Idempotence de DatabaseSeeder.
     */
    public function test_database_seeder_is_idempotent(): void
    {
        $seeder = new DatabaseSeeder();
        $seeder->run();
        $countAfterFirst = StockMovement::count();

        // Réexécution
        $seeder->run();
        $countAfterSecond = StockMovement::count();

        $this->assertGreaterThan(0, $countAfterFirst);
        $this->assertEquals($countAfterFirst, $countAfterSecond, 'DatabaseSeeder doit être idempotent et ne pas dupliquer les mouvements.');
    }

    /**
     * Test 8 : Contrainte MySQL ON DELETE RESTRICT au niveau du moteur InnoDB.
     */
    public function test_mysql_innodb_foreign_key_restrict_prevents_cascade(): void
    {
        try {
            DB::connection('mysql')->getPdo();
        } catch (\Exception $e) {
            $this->markTestSkipped('MySQL non disponible : ' . $e->getMessage());
        }

        $w = Warehouse::on('mysql')->create(['name' => 'MySQL_W_' . uniqid(), 'location' => 'Test', 'is_active' => true]);
        $p = Product::on('mysql')->create(['name' => 'MySQL_P_' . uniqid(), 'sku' => 'SKU-MY-' . uniqid(), 'price' => 10, 'is_active' => true]);
        $u = User::on('mysql')->first() ?? User::on('mysql')->create(['name' => 'Test', 'email' => 'my_' . uniqid() . '@erp.com', 'password' => 'secret']);

        StockMovement::on('mysql')->create([
            'product_id' => $p->id,
            'warehouse_id' => $w->id,
            'type' => 'IN',
            'quantity' => 10,
            'reference' => 'MY-REF-' . uniqid(),
            'user_id' => $u->id,
        ]);

        // Essayer un DELETE SQL direct sur le produit référencé dans les mouvements
        $caught = false;
        try {
            DB::connection('mysql')->statement("DELETE FROM products WHERE id = {$p->id}");
        } catch (QueryException $e) {
            $caught = true;
            // Code 1451: Cannot delete or update a parent row: a foreign key constraint fails
            $this->assertStringContainsString('1451', $e->getMessage());
        }
        $this->assertTrue($caught, 'MySQL InnoDB doit rejeter la suppression physique avec une erreur de contrainte RESTRICT (1451).');

        // Nettoyage sécurisé
        StockMovement::on('mysql')->where('product_id', $p->id)->delete();
        $p->delete();
        $w->delete();
    }

    /**
     * Test 9 : Contrainte MySQL CHECK(quantity >= 0) empêche le stock négatif en base.
     */
    public function test_mysql_innodb_check_constraint_rejects_negative_stock(): void
    {
        try {
            DB::connection('mysql')->getPdo();
        } catch (\Exception $e) {
            $this->markTestSkipped('MySQL non disponible : ' . $e->getMessage());
        }

        $w = Warehouse::on('mysql')->create(['name' => 'MySQL_W_Chk_' . uniqid(), 'location' => 'Test', 'is_active' => true]);
        $p = Product::on('mysql')->create(['name' => 'MySQL_P_Chk_' . uniqid(), 'sku' => 'SKU-CHK-' . uniqid(), 'price' => 10, 'is_active' => true]);

        $caught = false;
        try {
            DB::connection('mysql')->table('stock_levels')->insert([
                'product_id' => $p->id,
                'warehouse_id' => $w->id,
                'quantity' => -5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            $caught = true;
            // MySQL error 3819: Check constraint 'chk_stock_levels_quantity' is violated.
            $this->assertTrue(
                str_contains($e->getMessage(), '3819') || str_contains($e->getMessage(), 'chk_stock_levels_quantity'),
                'La violation de la contrainte CHECK doit être interceptée par MySQL.'
            );
        }
        $this->assertTrue($caught, 'MySQL 9.1 doit refuser nativement l insertion de quantity < 0 via la contrainte CHECK.');

        // Nettoyage
        $p->delete();
        $w->delete();
    }
}
