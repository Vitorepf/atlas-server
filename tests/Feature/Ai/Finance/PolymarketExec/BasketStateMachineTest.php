<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\BasketPlan;
use App\Services\Ai\Finance\PolymarketExec\BasketStateMachine;
use App\Services\Ai\Finance\PolymarketExec\FillResult;
use App\Services\Ai\Finance\PolymarketExec\PolyExecConfig;
use App\Services\Ai\Finance\PolymarketExec\PolyExecGate;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPolyExecTables;
use Tests\TestCase;

final class BasketStateMachineTest extends TestCase
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
            liveEnabled: $o['liveEnabled'] ?? false,
            maxBasketUsd: $o['maxBasketUsd'] ?? 8.0,
            dailyCapUsd: $o['dailyCapUsd'] ?? 25.0,
            maxConcurrentBaskets: $o['maxConcurrentBaskets'] ?? 2,
            minDepthMultiple: $o['minDepthMultiple'] ?? 3.0,
            minPersistenceSeconds: $o['minPersistenceSeconds'] ?? 600,
            minNetEdgePerSet: $o['minNetEdgePerSet'] ?? 0.01,
            maxResolutionHours: $o['maxResolutionHours'] ?? 72.0,
            slippageBps: $o['slippageBps'] ?? 100,
            takerFeeRate: $o['takerFeeRate'] ?? 0.0,
            estGasUsdPerBasket: $o['estGasUsdPerBasket'] ?? 0.0,
            killSwitchPath: $o['killSwitchPath'] ?? sys_get_temp_dir().'/atlas-poly-kill-none-'.uniqid(),
        );
    }

    /**
     * 3-leg plan, each best ask 0.30 (sum 0.90, edge 0.10/set), already ordered
     * thinnest-first as the planner would produce.
     */
    private function plan(float $targetSets = 2.0, array $tokens = ['THIN', 'MID', 'THICK']): BasketPlan
    {
        $legs = [];
        foreach (array_values($tokens) as $i => $t) {
            $legs[] = [
                'token' => $t, 'question' => 'Q '.$t, 'position' => $i,
                'plan_depth' => 1000.0, 'target_price' => 0.30, 'limit_price' => 0.303, 'target_size' => $targetSets,
            ];
        }

        return new BasketPlan(
            eventSlug: 'evt-x', kind: 'long_sum_under', executionClass: 'simple_buy_all_legs',
            legs: $legs, targetSets: $targetSets, targetSum: 0.90, targetCostUsd: round(0.90 * $targetSets, 4),
            estProfitUsd: round(0.10 * $targetSets, 4), estEdgePerSet: 0.10, estFeeUsd: 0.0, estGasUsd: 0.0,
            capUsd: 8.0, slippageBps: 100, executableDepthShares: 1000.0, persistenceSeconds: 1200,
            resolutionAt: '2999-01-01T00:00:00+00:00', resolutionHours: 24.0, minLegPrice: 0.30, maxLegPrice: 0.30,
        );
    }

    /** Fresh-book source so verifyFreshBook passes (deep books at the ask). */
    private function books(array $tokens = ['THIN', 'MID', 'THICK']): callable
    {
        return function (string $token) use ($tokens): ?array {
            if (! in_array($token, $tokens, true)) {
                return null;
            }

            return ['asks' => [['price' => 0.30, 'size' => 1000]], 'bids' => [['price' => 0.28, 'size' => 1000]]];
        };
    }

    private function machine(PolyExecConfig $cfg, ScriptedPolyExecClient $client): BasketStateMachine
    {
        return new BasketStateMachine($cfg, $client, new PolyExecGate($cfg), $this->books());
    }

    public function test_happy_path_fills_all_legs_thinnest_first_and_carries_to_resolution(): void
    {
        $cfg = $this->cfg();
        $client = new ScriptedPolyExecClient('sim');
        $summary = $this->machine($cfg, $client)->execute($this->plan(2.0), 'b1', 's1');

        $this->assertSame('filled', $summary['status']);
        $this->assertSame(3, $summary['legs_filled']);
        $this->assertSame(['THIN', 'MID', 'THICK'], array_column($client->buys, 'token'), 'fills follow thinnest-first plan order');
        // default fake fills at the limit price (0.303): 3 legs * 2 sets * 0.303.
        $this->assertEqualsWithDelta(3 * 2.0 * 0.303, $summary['realized_cost_usd'], 1e-4);
        $this->assertTrue($summary['pnl_is_locked_at_resolution']);
        $this->assertEqualsWithDelta($summary['est_profit_usd'], $summary['realized_pnl_usd'], 1e-6);
        $this->assertSame([], $client->sells, 'a clean fill never unwinds');

        $daily = DB::table('atlas_poly_exec_daily')->where('mode', 'sim')->first();
        $this->assertSame(1, (int) $daily->baskets_filled);
    }

    public function test_a_failing_leg_aborts_and_unwinds_already_filled_legs(): void
    {
        $cfg = $this->cfg();
        // Unwind sells at 0.28 (the bid, below our 0.303 buy) — a realistic loss.
        $client = new ScriptedPolyExecClient('sim', defaultSellPrice: 0.28);
        // THIN fills (default), MID fails to fill at all -> abort before THICK.
        $client->scriptBuy('MID', FillResult::nothing('no_fill_at_limit'));

        $summary = $this->machine($cfg, $client)->execute($this->plan(2.0), 'b2', 's1');

        $this->assertSame('unwound', $summary['status']);
        $this->assertSame(['THIN', 'MID'], array_column($client->buys, 'token'), 'stops at the failed leg; never buys THICK');
        $this->assertSame(['THIN'], array_column($client->sells, 'token'), 'unwinds the one filled leg');
        // Realized = unwind proceeds (THIN @0.28) - cost (THIN @0.303): a small loss.
        $this->assertLessThan(0.0, $summary['realized_pnl_usd']);
        $this->assertFalse($summary['pnl_is_locked_at_resolution']);

        $daily = DB::table('atlas_poly_exec_daily')->where('mode', 'sim')->first();
        $this->assertSame(1, (int) $daily->baskets_aborted);
    }

    public function test_idempotent_rerun_does_not_re_buy(): void
    {
        $cfg = $this->cfg();
        $client = new ScriptedPolyExecClient('sim');
        $machine = $this->machine($cfg, $client);

        $machine->execute($this->plan(2.0), 'b3', 's1');
        $countAfterFirst = count($client->buys);
        $this->assertSame(3, $countAfterFirst);

        $again = $machine->execute($this->plan(2.0), 'b3', 's1');
        $this->assertSame('filled', $again['status']);
        $this->assertCount($countAfterFirst, $client->buys, 're-running a terminal basket buys nothing more');
        $this->assertSame(1, DB::table('atlas_poly_exec_baskets')->where('basket_id', 'b3')->count());
    }

    public function test_resume_skips_already_filled_legs(): void
    {
        $cfg = $this->cfg();
        $client = new ScriptedPolyExecClient('sim');

        // Simulate a crash after THIN filled: basket 'filling', THIN done, others pending.
        DB::table('atlas_poly_exec_baskets')->insert([
            'basket_id' => 'b4', 'session_id' => 's1', 'mode' => 'sim', 'event_slug' => 'evt-x',
            'kind' => 'long_sum_under', 'execution_class' => 'simple_buy_all_legs', 'status' => 'filling',
            'n_legs' => 3, 'legs_filled' => 1, 'target_sets' => 2.0, 'target_sum' => 0.90,
            'target_cost_usd' => 1.80, 'est_profit_usd' => 0.20, 'est_edge_per_set' => 0.10,
            'realized_cost_usd' => 0.606, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $legs = [['THIN', 0, 'filled', 2.0], ['MID', 1, 'pending', 0.0], ['THICK', 2, 'pending', 0.0]];
        foreach ($legs as [$t, $pos, $st, $fs]) {
            DB::table('atlas_poly_exec_legs')->insert([
                'basket_id' => 'b4', 'position' => $pos, 'token' => $t, 'question' => 'Q', 'plan_depth' => 1000,
                'target_price' => 0.30, 'limit_price' => 0.303, 'target_size' => 2.0, 'status' => $st,
                'filled_size' => $fs, 'avg_fill_price' => $st === 'filled' ? 0.303 : 0, 'cost_usd' => $st === 'filled' ? 0.606 : 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $summary = $this->machine($cfg, $client)->execute($this->plan(2.0), 'b4', 's1');

        $this->assertSame('filled', $summary['status']);
        $this->assertSame(['MID', 'THICK'], array_column($client->buys, 'token'), 'resume buys only the remaining legs');
    }

    public function test_daily_cap_halts_after_the_budget_is_consumed(): void
    {
        // cap exactly equals one basket's cost: after the fill the day halts.
        $cfg = $this->cfg(['dailyCapUsd' => 1.80]);
        $client = new ScriptedPolyExecClient('sim');
        $machine = $this->machine($cfg, $client);

        $first = $machine->execute($this->plan(2.0), 'cap1', 's1'); // cost 1.80
        $this->assertSame('filled', $first['status']);
        $this->assertTrue((bool) DB::table('atlas_poly_exec_daily')->where('mode', 'sim')->value('halted'));

        $second = $machine->execute($this->plan(2.0, ['T2A', 'T2B', 'T2C']), 'cap2', 's1');
        $this->assertSame('halted', $second['status']);
        $this->assertSame(0, $second['legs_filled']);
    }

    public function test_kill_switch_mid_flight_aborts_and_unwinds(): void
    {
        $kill = sys_get_temp_dir().'/atlas-poly-kill-'.uniqid();
        $cfg = $this->cfg(['killSwitchPath' => $kill]);
        $client = new ScriptedPolyExecClient('sim');
        // Engage the kill right after the FIRST buy returns.
        $client->afterEachBuy(function (string $token) use ($kill): void {
            if ($token === 'THIN') {
                touch($kill);
            }
        });

        $summary = $this->machine($cfg, $client)->execute($this->plan(2.0), 'k1', 's1');

        $this->assertSame('unwound', $summary['status']);
        $this->assertSame(['THIN'], array_column($client->buys, 'token'), 'kill stops further buys');
        $this->assertSame(['THIN'], array_column($client->sells, 'token'), 'in-flight position is unwound');
        @unlink($kill);
    }

    public function test_slippage_breach_is_rejected_and_aborts(): void
    {
        $cfg = $this->cfg();
        $client = new ScriptedPolyExecClient('sim');
        // MID "fills" but above the 0.303 limit -> must be rejected as slippage.
        $client->scriptBuy('MID', new FillResult(true, 2.0, 0.40, 0.80, 'over'));

        $summary = $this->machine($cfg, $client)->execute($this->plan(2.0), 'sl1', 's1');

        $this->assertSame('unwound', $summary['status']);
        $this->assertNotContains('THICK', array_column($client->buys, 'token'));
        $err = DB::table('atlas_poly_exec_legs')->where('basket_id', 'sl1')->where('position', 1)->value('error');
        $this->assertStringContainsString('slippage', (string) $err);
    }

    public function test_verify_rejects_when_fresh_book_lost_its_edge(): void
    {
        $cfg = $this->cfg();
        $client = new ScriptedPolyExecClient('sim');
        // Fresh book now sums to >= 1 (edge gone) -> gated, no fills, nothing to unwind.
        $deadBooks = fn (string $t): ?array => ['asks' => [['price' => 0.40, 'size' => 1000]], 'bids' => [['price' => 0.38, 'size' => 1000]]];
        $machine = new BasketStateMachine($cfg, $client, new PolyExecGate($cfg), $deadBooks);

        $summary = $machine->execute($this->plan(2.0), 'v1', 's1');

        $this->assertSame('gated', $summary['status']);
        $this->assertSame([], $client->buys, 'a collapsed edge means we never commit capital');
    }
}
