<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

/**
 * Tests d'Audit Sécurité et Conformité API :
 * 1. Protection CSRF : session sans token CSRF rejetée (419), Bearer token autorisé (201).
 * 2. Filtrage strict GET /stock : valeurs non numériques rejetées avec 422.
 * 3. Protection Brute-force sur /api/login : limitation de débit (HTTP 429).
 * 4. Sanitization des erreurs 500 : aucune fuite de trace SQL ou message interne.
 * 5. Gestion des accès non authentifiés (401).
 * 6. Centralisation de validation StoreMovementRequest.
 */
class ApiSecurityAndSanitizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private Warehouse $warehouseA;
    private Warehouse $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'sec_admin@erp.com',
            'password' => Hash::make('password123'),
        ]);

        $this->product = Product::factory()->create(['is_active' => true]);
        $this->warehouseA = Warehouse::factory()->create(['is_active' => true]);
        $this->warehouseB = Warehouse::factory()->create(['is_active' => true]);
    }

    /**
     * Test 1 : GET /stock avec des paramètres invalides (non numériques, négatifs) renvoie 422.
     */
    public function test_get_stock_with_invalid_query_params_returns_422(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        // productId non numérique
        $res1 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/stock?productId=invalid_string');

        $res1->assertStatus(422)
            ->assertJsonValidationErrors(['productId']);

        // warehouseId négatif
        $res2 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/stock?warehouseId=-5');

        $res2->assertStatus(422)
            ->assertJsonValidationErrors(['warehouseId']);

        // Alias /api/stock avec query param invalide
        $res3 = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/stock?productId=abc&warehouseId=xyz');

        $res3->assertStatus(422)
            ->assertJsonValidationErrors(['productId', 'warehouseId']);
    }

    /**
     * Test 2 : GET /stock sans filtre ou avec filtres valides renvoie 200.
     */
    public function test_get_stock_with_valid_query_params_returns_200(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/stock?productId={$this->product->id}&warehouseId={$this->warehouseA->id}");

        $res->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    /**
     * Test 3 : Route /api/login protégée par Rate Limiting contre les attaques brute-force.
     */
    public function test_api_login_rate_limiting_blocks_brute_force(): void
    {
        // Effectuer 10 tentatives échouées consécutives (seuil = 10 par minute)
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => 'sec_admin@erp.com',
                'password' => 'wrong_pass_' . $i,
            ]);
            $response->assertStatus(422);
        }

        // La 11ème tentative doit être immédiatement bloquée avec HTTP 429 Too Many Requests
        $blockedResponse = $this->postJson('/api/login', [
            'email' => 'sec_admin@erp.com',
            'password' => 'wrong_pass_11',
        ]);

        $blockedResponse->assertStatus(429);
    }

    /**
     * Test 4 : Protection CSRF : VerifyCsrfToken bloque les requêtes de session sans token CSRF valide.
     */
    public function test_web_session_post_movements_without_csrf_is_blocked(): void
    {
        // Classe anonyme simulant l'exécution en production hors bypass de test unitaire (runningUnitTests = false)
        $middleware = new class(app(), app('encrypter')) extends \App\Http\Middleware\VerifyCsrfToken {
            protected function runningUnitTests() { return false; }
        };

        $request = \Illuminate\Http\Request::create('/movements', 'POST', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 10,
        ]);
        $request->setLaravelSession(session()->driver());

        $this->expectException(\Illuminate\Session\TokenMismatchException::class);
        $this->expectExceptionMessage('CSRF token mismatch.');

        $middleware->handle($request, function () {
            return response('OK');
        });
    }

    /**
     * Test 4b : Protection CSRF : VerifyCsrfToken autorise les requêtes avec Bearer token sans exiger de token CSRF.
     */
    public function test_bearer_token_bypasses_csrf_safely_even_outside_test_mode(): void
    {
        $middleware = new class(app(), app('encrypter')) extends \App\Http\Middleware\VerifyCsrfToken {
            protected function runningUnitTests() { return false; }
        };

        $request = \Illuminate\Http\Request::create('/movements', 'POST');
        $request->headers->set('Authorization', 'Bearer some_secret_token');

        $response = $middleware->handle($request, function ($req) {
            return response()->json(['status' => 'OK'], 200);
        });

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test 5 : Requête API avec Bearer Token exemptée de CSRF en toute sécurité.
     */
    public function test_bearer_token_request_is_safely_exempt_from_csrf(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/movements', [
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouseA->id,
                'type' => 'IN',
                'quantity' => 15,
                'reference' => 'SEC-CSRF-001',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'data']);
    }

    /**
     * Test 6 : Requêtes non authentifiées retournent HTTP 401.
     */
    public function test_unauthenticated_requests_return_401(): void
    {
        $resMovements = $this->postJson('/movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouseA->id,
            'type' => 'IN',
            'quantity' => 5,
        ]);
        $resMovements->assertStatus(401);

        $resStock = $this->getJson('/stock');
        $resStock->assertStatus(401);
    }

    /**
     * Test 7 : Réponses HTTP 500 sécurisées — Aucune trace SQL ni message interne divulgué.
     */
    public function test_internal_server_errors_do_not_leak_sql_traces(): void
    {
        $token = $this->user->createToken('test')->plainTextToken;

        // Créer un mock du StockService qui lève une exception interne contenant du SQL sensible
        $mock = Mockery::mock(StockService::class);
        $mock->shouldReceive('createMovement')
            ->andThrow(new \RuntimeException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'mini_erp.secret_table' doesn't exist (SQL: SELECT * FROM secret_table)"));

        $this->app->instance(StockService::class, $mock);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/movements', [
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouseA->id,
                'type' => 'IN',
                'quantity' => 5,
            ]);

        $response->assertStatus(500);

        // Vérifier que la réponse JSON est générique et propre
        $this->assertEquals(
            'Une erreur interne est survenue lors du traitement du mouvement.',
            $response->json('message')
        );

        // Vérifier qu'aucune bribe de SQL ou de nom de table interne ne fuite
        $this->assertNull($response->json('error'));
        $this->assertStringNotContainsString('SQLSTATE', json_encode($response->json()));
        $this->assertStringNotContainsString('secret_table', json_encode($response->json()));
    }
}
