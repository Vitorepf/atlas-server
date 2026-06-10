<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\BasketPlan;
use App\Services\Ai\Finance\PolymarketExec\BasketStateMachine;
use App\Services\Ai\Finance\PolymarketExec\PolyExecConfig;
use App\Services\Ai\Finance\PolymarketExec\PolyExecGate;
use App\Services\Ai\Finance\PolymarketExec\SimulatedPolyExecClient;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPolyExecTables;
use Tests\TestCase;

/**
 * Proves shadow-sim — the default mode — runs the WHOLE state machine against a
 * book and signs nothing, including the realistic case where liquidity vanishes
 * between verification and the fill (which must abort + unwind).
 */
final class PolyExecShadowSimTest extends TestCase
{
    use CreatesPolyExecTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPolyExecTables();
    }

    private function cfg(array $o = []): PolyExecConfig
    {
        return new PolyExecConfig(
            liveEnabled: false, maxBasketUsd: $o['maxBasketUsd'] ?? 8.0, dailyCapUsd: 25.0,
            maxConcurrentBaskets: 2, minDepthMultiple: $o['minDepthMultiple'] ?? 3.0, minPersistenceSeconds: 600,
            minNetEdgePerSet: 0.01, maxResolutionHours: 72.0, slippageBps: 100, takerFeeRate: 0.0,
            estGasUsdPerBasket: 0.0, killSwitchPath: sys_get_temp_dir().'/atlas-poly-kill-none-'.uniqid(),
        );
    }

    private function plan(float $targetSets = 2.0): BasketPlan
    {
        $legs = [];
        foreach (['A', 'B', 'C'] as $i => $t) {
            $legs[] = ['token' => $t, 'question' => 'Q'.$t, 'position' => $i,
                'plan_depth' => 1000.0, 'target_price' => 0.30, 'limit_price' => 0.303, 'target_size' => $targetSets];
        }

        return new BasketPlan('evt', 'long_sum_under', 'simple_buy_all_legs', $legs, $targetSets, 0.90,
            round(0.90 * $targetSets, 4), round(0.10 * $targetSets, 4), 0.10, 0.0, 0.0, 8.0, 100, 1000.0, 1200,
            '2999-01-01T00:00:00+00:00', 24.0, 0.30, 0.30);
    }

    private function deepBook(): callable
    {
        return fn (string $t): ?array => ['asks' => [['price' => 0.30, 'size' => 1000]], 'bids' => [['price' => 0.28, 'size' => 1000]]];
    }

    public function test_sim_end_to_end_fills_and_reconciles_against_the_book(): void
    {
        $cfg = $this->cfg();
        $book = $this->deepBook();
        $client = new SimulatedPolyExecClient($book);          // sim fills off the book
        $machine = new BasketStateMachine($cfg, $client, new PolyExecGate($cfg), $book);

        $summary = $machine->execute($this->plan(2.0), 'sim-1', 's1');

        $this->assertSame('filled', $summary['status']);
        $this->assertSame(3, $summary['legs_filled']);
        // Reconciliation: the simulated position equals what we believe we filled.
        foreach (['A', 'B', 'C'] as $t) {
            $this->assertEqualsWithDelta(2.0, $client->positionSize($t), 1e-6);
        }
        $reconcile = DB::table('atlas_poly_exec_events')->where('basket_id', 'sim-1')->where('kind', 'reconcile')->first();
        $this->assertNotNull($reconcile);
        $detail = json_decode((string) $reconcile->detail, true);
        $this->assertSame([], $detail['discrepancies'], 'sim position must reconcile exactly');
    }

    public function test_sim_aborts_and_unwinds_when_liquidity_vanishes_after_verify(): void
    {
        $cfg = $this->cfg();
        // Verify sees deep books; the fill book is empty on leg B (liquidity gone).
        $verifyBook = $this->deepBook();
        $fillBook = function (string $t): ?array {
            if ($t === 'B') {
                return ['asks' => [], 'bids' => [['price' => 0.28, 'size' => 1000]]]; // no asks to buy
            }

            return ['asks' => [['price' => 0.30, 'size' => 1000]], 'bids' => [['price' => 0.28, 'size' => 1000]]];
        };
        $client = new SimulatedPolyExecClient($fillBook);
        $machine = new BasketStateMachine($cfg, $client, new PolyExecGate($cfg), $verifyBook);

        $summary = $machine->execute($this->plan(2.0), 'sim-2', 's1');

        $this->assertSame('unwound', $summary['status']);
        // A filled, B unfillable -> abort; A unwound back to flat.
        $this->assertEqualsWithDelta(0.0, $client->positionSize('A'), 1e-6, 'A must be unwound to flat');
        $this->assertEqualsWithDelta(0.0, $client->positionSize('C'), 1e-6, 'C never bought');
        $this->assertLessThanOrEqual(0.0, $summary['realized_pnl_usd']);
    }

    public function test_command_preflight_emits_json_without_touching_money(): void
    {
        $this->artisan('atlas:finance:poly-exec', ['action' => 'preflight', '--mode' => 'sim', '--json'])
            ->assertExitCode(0);
    }

    public function test_command_run_sim_is_a_clean_noop_with_no_candidates(): void
    {
        // Empty lifecycle -> nothing to execute; must exit cleanly, not error.
        $this->artisan('atlas:finance:poly-exec', ['action' => 'run', '--mode' => 'sim', '--json'])
            ->assertExitCode(0);
        $this->assertSame(0, DB::table('atlas_poly_exec_baskets')->count());
    }

    public function test_command_run_live_is_refused_without_flag_and_confirm(): void
    {
        $this->artisan('atlas:finance:poly-exec', ['action' => 'run', '--mode' => 'live'])
            ->assertExitCode(1); // flag off -> refused, signs nothing
    }
}
