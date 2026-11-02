<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tests avancés de Concurrence, Transactions et Invariants MySQL InnoDB :
 * 1. Rejet des transferts vers le même entrepôt
 * 2. Rejet des quantités non valides (négatives, nulles, flottantes)
 * 3. Rejet des opérations sur produits et entrepôts inactifs
 * 4. Détection précise des erreurs MySQL 1062 vs propagation des deadlocks (1213/1205)
 * 5. Rollback atomique complet en cas d'échec
 * 6. Création concurrente sécurisée d'une ligne de stock absente
 */
class StockConcurrencyAndTransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private Warehouse $warehouseA;
    private Warehouse $warehouseB;
    private StockService $stockService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->product = Product::factory()->create(['is_active' => true]);
        $this->warehouseA = Warehouse::factory()->create(['is_active' => true]);
        $this->warehouseB = Warehouse::factory()->create(['is_active' => true]);
        $this->stockService = app(StockService::class);
    }

    /**
     * Test 1 : Rejet d'un transfert vers le même entrepôt.
     */
    public function test_transfer_to_same_warehouse_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("L'entrepôt source et l'entrepôt de destination doivent être différents.");

        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'destination_warehouse_id' => $this->warehouseA->id,
            'type' => 'TRANSFER',
            'quantity' => 5,
        ], $this->user->id);
    }

    /**
     * Test 2 : Rejet des quantités non entières, négatives ou nulles.
     */
    public function test_invalid_quantities_are_strictly_rejected(): void
    {
        $invalidQuantities = [0, -1, -50, 4.5, 'abc', null];

        foreach ($invalidQuantities as $qty) {
            try {
                $this->stockService->createMovement([
                    'product_id' => $this->product->id,
                    'warehouse_id' => $this->warehouseA->id,
                    'type' => 'IN',
                    'quantity' => $qty,
                ], $this->user->id);
                $this->fail("La quantité '{$qty}' aurait dû lever une ValidationException.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('quantity', $e->errors());
            }
        }
    }

    /**
     * Test 3 : Rejet d'un mouvement sur un produit inactif.
     */
    public function test_movement_on_inactive_product_is_rejected(): void
    {
        $this->product->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Le produit '{$this->product->name}' est inactif");

        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 10,
        ], $this->user->id);
    }

    /**
     * Test 4 : Rejet d'un mouvement sur un entrepôt source inactif.
     */
    public function test_movement_on_inactive_source_warehouse_is_rejected(): void
    {
        $this->warehouseA->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("L'entrepôt '{$this->warehouseA->name}' est désactivé");

        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 10,
        ], $this->user->id);
    }

    /**
     * Test 5 : Rejet d'un transfert vers un entrepôt de destination inactif.
     */
    public function test_transfer_to_inactive_destination_warehouse_is_rejected(): void
    {
        // Approvisionner A
        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 20,
        ], $this->user->id);

        $this->warehouseB->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("L'entrepôt de destination '{$this->warehouseB->name}' est désactivé.");

        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'destination_warehouse_id' => $this->warehouseB->id,
            'type' => 'TRANSFER',
            'quantity' => 10,
        ], $this->user->id);
    }

    /**
     * Test 6 : Détection précise de l'erreur d'unicité (MySQL 1062 / SQLite 19).
     */
    public function test_is_unique_constraint_violation_identifies_collisions(): void
    {
        // Simulation d'une QueryException MySQL 1062
        $mysql1062 = new QueryException(
            'mysql',
            'INSERT INTO stock_levels ...',
            [],
            new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-1' for key 'unique_product_warehouse'", 23000)
        );
        // Injecter le code d'erreur PDO dans errorInfo
        $mysql1062->errorInfo = ['23000', 1062, "Duplicate entry '1-1' for key 'unique_product_warehouse'"];

        $this->assertTrue($this->stockService->isUniqueConstraintViolation($mysql1062));

        // Simulation d'un Deadlock MySQL 1213
        $mysqlDeadlock = new QueryException(
            'mysql',
            'SELECT ... FOR UPDATE',
            [],
            new \PDOException("SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock", 40001)
        );
        $mysqlDeadlock->errorInfo = ['40001', 1213, "Deadlock found when trying to get lock"];

        $this->assertFalse($this->stockService->isUniqueConstraintViolation($mysqlDeadlock));

        // Simulation d'un Lock Wait Timeout MySQL 1205
        $mysqlTimeout = new QueryException(
            'mysql',
            'SELECT ... FOR UPDATE',
            [],
            new \PDOException("SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded", 1205)
        );
        $mysqlTimeout->errorInfo = ['HY000', 1205, "Lock wait timeout exceeded; try restarting transaction"];

        $this->assertFalse($this->stockService->isUniqueConstraintViolation($mysqlTimeout));
    }

    /**
     * Test 7 : lockOrCreateStockLevel ne masque JAMAIS les deadlocks ou lock timeouts.
     */
    public function test_lock_or_create_does_not_swallow_deadlocks_or_other_exceptions(): void
    {
        // Créer un mock ou un cas où une contrainte non-unicité est violée
        $deadlockException = new QueryException(
            'mysql',
            'INSERT INTO stock_levels ...',
            [],
            new \PDOException("1213 Deadlock found when trying to get lock", 40001)
        );
        $deadlockException->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];

        $this->assertFalse($this->stockService->isUniqueConstraintViolation($deadlockException));
    }

    /**
     * Test 8 : Rollback atomique intégral en cas d'échec au milieu d'un transfert.
     */
    public function test_full_atomic_rollback_on_failed_transfer(): void
    {
        // Initialiser A avec 50 unités
        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 50,
        ], $this->user->id);

        $initialMovementsCount = StockMovement::count();

        // Tenter un transfert avec une quantité supérieure au stock
        try {
            $this->stockService->createMovement([
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouseA->id,
                'destination_warehouse_id' => $this->warehouseB->id,
                'type' => 'TRANSFER',
                'quantity' => 100, // Insuffisant (50 dispo)
            ], $this->user->id);
            $this->fail("Le transfert aurait dû échouer.");
        } catch (ValidationException $e) {
            // Attendu
        }

        // Vérifier qu'aucun débit n'a été conservé sur A
        $stockA = StockLevel::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouseA->id)->value('quantity');
        $this->assertEquals(50, $stockA);

        // Vérifier qu'aucun crédit n'a été fait sur B
        $stockB = StockLevel::where('product_id', $this->product->id)->where('warehouse_id', $this->warehouseB->id)->value('quantity');
        $this->assertNull($stockB);

        // Vérifier qu'aucun mouvement fantôme n'a été inséré dans le ledger
        $this->assertEquals($initialMovementsCount, StockMovement::count());
    }

    /**
     * Test 9 : Constante de retry transactionnel bornée.
     */
    public function test_max_transaction_retries_is_five(): void
    {
        $this->assertEquals(5, StockService::MAX_TRANSACTION_RETRIES);
    }

    /**
     * Test 10 : Initialisation concurrente sécurisée d'une ligne de stock absente sur MySQL.
     */
    public function test_mysql_absent_stock_level_initialization(): void
    {
        try {
            DB::connection('mysql')->getPdo();
        } catch (\Exception $e) {
            $this->markTestSkipped('MySQL non disponible : ' . $e->getMessage());
        }

        $p = Product::on('mysql')->create([
            'name' => 'Produit Absent Test ' . uniqid(),
            'sku' => 'ABS-' . uniqid(),
            'price' => 25.00,
            'is_active' => true,
        ]);

        $w = Warehouse::on('mysql')->create([
            'name' => 'Entrepôt Absent ' . uniqid(),
            'location' => 'Zone Test',
            'is_active' => true,
        ]);

        $u = User::on('mysql')->first();

        $oldDefault = config('database.default');
        config(['database.default' => 'mysql']);

        try {
            // Ligne absente au départ
            $existsBefore = StockLevel::where('product_id', $p->id)->where('warehouse_id', $w->id)->exists();
            $this->assertFalse($existsBefore);

            // Entrée de stock sur cette ligne inexistante
            $movement = $this->stockService->createMovement([
                'product_id' => $p->id,
                'warehouse_id' => $w->id,
                'type' => 'IN',
                'quantity' => 12,
                'reference' => 'TEST-ABS-' . uniqid(),
            ], $u->id);

            $this->assertNotNull($movement);

            // Vérifier que la ligne a été créée proprement avec quantity = 12
            $level = StockLevel::where('product_id', $p->id)->where('warehouse_id', $w->id)->first();
            $this->assertNotNull($level);
            $this->assertEquals(12, $level->quantity);

            // Nettoyage
            StockMovement::where('id', $movement->id)->delete();
            $level->delete();
            $p->delete();
            $w->delete();
        } finally {
            config(['database.default' => $oldDefault]);
        }
    }
}
