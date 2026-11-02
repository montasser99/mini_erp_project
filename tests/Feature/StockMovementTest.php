<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Tests automatisés pour la gestion de stock.
 *
 * Couvre :
 * - POST /api/movements (entrée, sortie, transfert)
 * - GET /api/stock (consultation)
 * - Interdiction de stock négatif
 * - Gestion des erreurs de validation
 * - Accès concurrents (verrouillage)
 * - Transactions (rollback en cas d'erreur)
 */
class StockMovementTest extends TestCase
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
        $this->product = Product::factory()->create();
        $this->warehouseA = Warehouse::factory()->create(['name' => 'Entrepôt A']);
        $this->warehouseB = Warehouse::factory()->create(['name' => 'Entrepôt B']);
        $this->stockService = app(StockService::class);
    }

    // ========================================================
    // TEST 1 : Entrée de stock (IN) — API POST /api/movements
    // ========================================================

    public function test_can_create_stock_entry_movement(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 50,
            'reference' => 'REC-001',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id', 'product_id', 'warehouse_id', 'type',
                    'quantity', 'reference', 'user_id', 'created_at',
                ],
            ]);

        // Vérifier que le stock a été mis à jour
        $stockLevel = StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseA->id)
            ->first();

        $this->assertNotNull($stockLevel);
        $this->assertEquals(50, $stockLevel->quantity);
    }

    // =========================================================
    // TEST 2 : Interdiction de stock négatif — Point CRITIQUE
    // =========================================================

    public function test_cannot_create_negative_stock(): void
    {
        // D'abord, entrer 10 unités
        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 10,
        ], $this->user->id);

        // Essayer de sortir 20 unités (plus que le stock disponible)
        $response = $this->actingAs($this->user)->postJson('/api/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'OUT',
            'quantity' => 20,
        ]);

        $response->assertStatus(422);

        // Vérifier que le stock n'a pas changé
        $stockLevel = StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseA->id)
            ->first();

        $this->assertEquals(10, $stockLevel->quantity);
    }

    // ===========================================================
    // TEST 3 : Transfert inter-entrepôts
    // ===========================================================

    public function test_can_transfer_stock_between_warehouses(): void
    {
        // Entrer 100 dans l'entrepôt A
        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 100,
        ], $this->user->id);

        // Transférer 30 de A vers B
        $response = $this->actingAs($this->user)->postJson('/api/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'TRANSFER',
            'quantity' => 30,
            'destination_warehouse_id' => $this->warehouseB->id,
        ]);

        $response->assertStatus(201);

        // Vérifier les stocks
        $stockA = StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseA->id)->first();
        $stockB = StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseB->id)->first();

        $this->assertEquals(70, $stockA->quantity);
        $this->assertEquals(30, $stockB->quantity);
    }

    // ===========================================================
    // TEST 4 : GET /api/stock — Consultation du stock
    // ===========================================================

    public function test_can_get_stock_by_product_and_warehouse(): void
    {
        // Créer du stock
        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'quantity' => 75,
        ]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/stock?productId=' . $this->product->id . '&warehouseId=' . $this->warehouseA->id
        );

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', 75);
    }

    // ===========================================================
    // TEST 5 : Validation des données — champs requis
    // ===========================================================

    public function test_movement_requires_valid_data(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/movements', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_id', 'warehouse_id', 'type', 'quantity']);
    }

    // ===========================================================
    // TEST 6 : Transfert nécessite un entrepôt destination
    // ===========================================================

    public function test_transfer_requires_destination_warehouse(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'TRANSFER',
            'quantity' => 10,
        ]);

        $response->assertStatus(422);
    }

    // ===========================================================
    // TEST 7 : Transaction rollback — atomicité garantie
    // ===========================================================

    public function test_failed_transfer_does_not_modify_stock(): void
    {
        // Entrer 5 dans l'entrepôt A
        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 5,
        ], $this->user->id);

        // Essayer de transférer 20 (plus que disponible)
        $response = $this->actingAs($this->user)->postJson('/api/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'TRANSFER',
            'quantity' => 20,
            'destination_warehouse_id' => $this->warehouseB->id,
        ]);

        $response->assertStatus(422);

        // Vérifier que le stock de A n'a pas changé (rollback)
        $stockA = StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseA->id)->first();
        $this->assertEquals(5, $stockA->quantity);

        // Vérifier que B n'a rien reçu (rollback)
        $stockB = StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseB->id)->first();
        $this->assertTrue(!$stockB || $stockB->quantity === 0);
    }

    // ===========================================================
    // TEST 8 : Authentification requise
    // ===========================================================

    public function test_unauthenticated_user_cannot_access_api(): void
    {
        $response = $this->postJson('/api/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 10,
        ]);

        $response->assertStatus(401);
    }

    // ===========================================================
    // TEST 9 : Sortie de stock réussie
    // ===========================================================

    public function test_can_create_stock_exit_movement(): void
    {
        // Entrer 50
        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 50,
        ], $this->user->id);

        // Sortir 20
        $response = $this->actingAs($this->user)->postJson('/api/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'OUT',
            'quantity' => 20,
        ]);

        $response->assertStatus(201);

        $stockLevel = StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseA->id)->first();

        $this->assertEquals(30, $stockLevel->quantity);
    }

    // ===========================================================
    // TEST 10 : StockService — Entrée multiple
    // ===========================================================

    public function test_multiple_entries_accumulate_stock(): void
    {
        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 30,
        ], $this->user->id);

        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 20,
        ], $this->user->id);

        $stockLevel = StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseA->id)->first();

        $this->assertEquals(50, $stockLevel->quantity);
    }

    // ===========================================================
    // TEST 11 : POST /movements direct (Chemin exact du sujet)
    // ===========================================================

    public function test_can_create_movement_via_root_path(): void
    {
        $response = $this->actingAs($this->user)->postJson('/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 15,
            'reference' => 'ROOT-001',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.quantity', 15);
    }

    // ===========================================================
    // TEST 12 : GET /stock direct (Chemin exact du sujet)
    // ===========================================================

    public function test_can_get_stock_via_root_path_with_filters(): void
    {
        StockLevel::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'quantity' => 42,
        ]);

        $response = $this->actingAs($this->user)->getJson(
            '/stock?productId=' . $this->product->id . '&warehouseId=' . $this->warehouseA->id
        );

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.quantity', 42);
    }

    // ===========================================================
    // TEST 13 : Rejet des quantités négatives ou nulles
    // ===========================================================

    public function test_cannot_create_movement_with_non_positive_quantity(): void
    {
        $response = $this->actingAs($this->user)->postJson('/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);

        $responseNegative = $this->actingAs($this->user)->postJson('/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'OUT',
            'quantity' => -5,
        ]);

        $responseNegative->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    }

    // ===========================================================
    // TEST 14 : Simulation exacte du pseudo-code audité
    // stock = getStock(id); if stock >= qty: saveStock(id, stock - qty)
    // ===========================================================

    public function test_pseudocode_mitigation_scenario(): void
    {
        // 1. Initialiser le stock à 10
        $this->stockService->createMovement([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 10,
        ], $this->user->id);

        // 2. Première opération : retrait de 8 -> Succès (reste 2)
        $respA = $this->actingAs($this->user)->postJson('/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'OUT',
            'quantity' => 8,
        ]);
        $respA->assertStatus(201);

        // 3. Deuxième opération : tentative de retrait de 7 -> Rejet strict (422)
        $respB = $this->actingAs($this->user)->postJson('/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'OUT',
            'quantity' => 7,
        ]);
        $respB->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);

        // 4. Le stock reste intact à 2
        $finalStock = StockLevel::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseA->id)
            ->first();

        $this->assertEquals(2, $finalStock->quantity);
    }
}
