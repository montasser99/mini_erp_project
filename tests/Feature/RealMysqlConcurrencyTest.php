<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test de Concurrence Réel sur Base de Données MySQL InnoDB.
 *
 * Scénario audité :
 * - Stock initial = 10 unités.
 * - Deux processus concurrents A et B tentent de retirer respectivement 8 et 7 unités en parallèle.
 * - Grâce au verrouillage pessimiste (SELECT ... FOR UPDATE) et aux transactions InnoDB :
 *   1. Exactement UNE SEULE transaction doit réussir.
 *   2. La seconde transaction DOIT échouer avec une exception "Stock insuffisant" (HTTP 422).
 *   3. Le stock final en base doit être strictement cohérent (10 - 8 = 2 ou 10 - 7 = 3).
 *   4. Aucun stock négatif ne peut survenir.
 */
class RealMysqlConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('mysql')->getPdo();
        } catch (\Exception $e) {
            $this->markTestSkipped('Serveur MySQL non accessible : ' . $e->getMessage());
        }
    }

    public function test_concurrent_withdrawals_prevent_race_condition_on_mysql(): void
    {
        // 1. Initialisation sur la base MySQL
        $user = User::on('mysql')->firstOrCreate(
            ['email' => 'admin@erp.com'],
            ['name' => 'Montasser Benouirane', 'password' => bcrypt('password')]
        );

        $product = Product::on('mysql')->create([
            'name' => 'Article Concurrence Test',
            'sku' => 'SKU-CONC-' . uniqid(),
            'price' => 100.00,
        ]);

        $warehouse = Warehouse::on('mysql')->create([
            'name' => 'Entrepôt Test Concurrence',
            'is_active' => true,
        ]);

        // Stock initial = 10 unités
        StockLevel::on('mysql')->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 10,
        ]);

        $workerScript = base_path('tests/concurrency_worker.php');

        // 2. Lancement de 2 processus système concurrents (retrait 8 vs retrait 7)
        $cmdA = sprintf('php "%s" %d %d 8 %d', $workerScript, $product->id, $warehouse->id, $user->id);
        $cmdB = sprintf('php "%s" %d %d 7 %d', $workerScript, $product->id, $warehouse->id, $user->id);

        $spec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pA = proc_open($cmdA, $spec, $pipesA);
        $pB = proc_open($cmdB, $spec, $pipesB);

        $outA = stream_get_contents($pipesA[1]);
        $errA = stream_get_contents($pipesA[2]);
        $outB = stream_get_contents($pipesB[1]);
        $errB = stream_get_contents($pipesB[2]);

        fclose($pipesA[1]); fclose($pipesA[2]);
        fclose($pipesB[1]); fclose($pipesB[2]);

        $codeA = proc_close($pA);
        $codeB = proc_close($pB);

        // Debug assertions si besoin
        if ($codeA !== 0 && $codeB !== 0) {
            $this->fail("Les deux processus ont échoué.\nProc A (code $codeA): Out: $outA | Err: $errA\nProc B (code $codeB): Out: $outB | Err: $errB");
        }

        // 3. Assertions : Une seule transaction a réussi (code 0), l'autre est rejetée (code 1)
        $successCount = ($codeA === 0 ? 1 : 0) + ($codeB === 0 ? 1 : 0);
        $rejectedCount = ($codeA === 1 ? 1 : 0) + ($codeB === 1 ? 1 : 0);

        $this->assertEquals(1, $successCount, "Exactement UNE sortie doit réussir sous concurrence.");
        $this->assertEquals(1, $rejectedCount, "La deuxième sortie concurrentielle DOIT être refusée pour stock insuffisant.");

        // 4. Vérification de l'intégrité du stock final dans MySQL
        $finalStock = StockLevel::on('mysql')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        $this->assertNotNull($finalStock);
        $this->assertTrue(
            in_array($finalStock->quantity, [2, 3]),
            "Le stock final doit être de 2 ou 3. Constaté : {$finalStock->quantity}"
        );

        // 5. Nettoyage
        StockMovement::on('mysql')->where('product_id', $product->id)->delete();
        StockLevel::on('mysql')->where('product_id', $product->id)->delete();
        $product->delete();
        $warehouse->delete();
    }
}
