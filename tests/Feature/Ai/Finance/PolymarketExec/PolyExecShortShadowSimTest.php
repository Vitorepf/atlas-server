<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\BasketPlan;
use App\Services\Ai\Finance\PolymarketExec\BasketStateMachine;
use App\Services\Ai\Finance\PolymarketExec\MintSellStateMachine;
use App\Services\Ai\Finance\PolymarketExec\OnChain\SimulatedPolyOnChainClient;
use App\Services\Ai\Finance\PolymarketExec\PolyExecConfig;
use App\Services\Ai\Finance\PolymarketExec\PolyExecGate;
use App\Services\Ai\Finance\PolymarketExec\ShortBasketPlanner;
use App\Services\Ai\Finance\PolymarketExec\SimulatedPolyExecClient;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPolyExecTables;
use Tests\TestCase;

/**
 * Shadow-sim, the default mode: the WHOLE short machine (mint a full set, sell
 * the sellable legs, freeroll the rest) and the long early-merge run against a
 * book while signing/minting NOTHING. The simulated position must reconcile.
 */
final class PolyExecShortShadowSimTest extends TestCase
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
            maxConcurrentBaskets: 2, minDepthMultiple: 3.0, minPersistenceSeconds: 600,
            minNetEdgePerSet: 0.01, maxResolutionHours: 72.0, slippageBps: 100, takerFeeRate: 0.0,
            estGasUsdPerBasket: 0.0, killSwitchPath: sys_get_temp_dir().'/k'.uniqid(),
            estMintGasUsd: 0.05, estMergeGasUsd: 0.05,
            longRealizeMethod: $o['longRealizeMethod'] ?? 'hold',
        );
    }

    /** A/B/C: bid 0.40 (sum 1.20); FREE: no bid. */
    private function shortBook(): callable
    {
        return function (string $t): ?array {
            if ($t === 'FREE') {
                return ['asks' => [['price' => 0.30, 'size' => 1000]], 'bids' => []];
            }
            if (! in_array($t, ['A', 'B', 'C'], true)) {
                return null;
            }

            return ['asks' => [['price' => 0.42, 'size' => 1000]], 'bids' => [['price' => 0.40, 'size' => 1000]]];
        };
    }

    public function test_short_sim_mints_sells_and_reconciles_against_the_book(): void
    {
        $cfg = $this->cfg();
        $book = $this->shortBook();
        $meta = fn (string $s): array => ['endDate' => now()->addDay()->toIso8601String(), 'negRisk' => true, 'negRiskMarketID' => 'cond-1'];

        $exec = new SimulatedPolyExecClient($book);
        $onChain = new SimulatedPolyOnChainClient(
            mintGasUsd: 0.05, mergeGasUsd: 0.05,
            onMint: fn (array $t, float $s) => $exec->creditMinted($t, $s),
            onMerge: fn (array $t, float $s) => $exec->debitMerged($t, $s),
        );
        $machine = new MintSellStateMachine($cfg, $exec, $onChain, new PolyExecGate($cfg), $book);
        $plan = (new ShortBasketPlanner($cfg, $book, $meta))
            ->plan('evt', [['token' => 'A'], ['token' => 'B'], ['token' => 'C'], ['token' => 'FREE']], 1200, 8.0);

        $this->assertNotNull($plan);
        $summary = $machine->execute($plan, 'sim-short-1', 's1');

        $this->assertSame('settled', $summary['status']);
        $this->assertSame('sold', $summary['realize_method']);
        $this->assertSame(3, $summary['legs_sold']);
        $this->assertSame(1, $summary['legs_freeroll']);
        // sold 3 legs * 8 sets * 0.40 (best bid) = 9.60; pnl = 9.60 - 8 mint - 0.05 gas.
        $this->assertEqualsWithDelta(9.60, $summary['cash_in_usd'], 1e-4);
        $this->assertEqualsWithDelta(9.60 - 8.0 - 0.05, $summary['realized_pnl_usd'], 1e-4);

        // Reconciliation: sold legs held 0; the freeroll FREE leg holds the minted 8.
        foreach (['A', 'B', 'C'] as $t) {
            $this->assertEqualsWithDelta(0.0, $exec->positionSize($t), 1e-6);
        }
        $this->assertEqualsWithDelta(8.0, $exec->positionSize('FREE'), 1e-6, 'the freeroll outcome is still held');
        $reconcile = DB::table('atlas_poly_exec_events')->where('basket_id', 'sim-short-1')->where('kind', 'reconcile')->first();
        $this->assertNotNull($reconcile);
        $this->assertSame([], json_decode((string) $reconcile->detail, true)['discrepancies'], 'sim short must reconcile exactly');
    }

    public function test_long_early_merge_banks_now_instead_of_waiting_for_resolution(): void
    {
        $cfg = $this->cfg(['longRealizeMethod' => 'merge']);
        // Long legs each best-ask 0.30 (sum 0.90, edge 0.10/set), deep books.
        $book = fn (string $t): ?array => ['asks' => [['price' => 0.30, 'size' => 1000]], 'bids' => [['price' => 0.28, 'size' => 1000]]];
        $exec = new SimulatedPolyExecClient($book);
        $onChain = new SimulatedPolyOnChainClient(
            mintGasUsd: 0.05, mergeGasUsd: 0.05,
            onMerge: fn (array $t, float $s) => $exec->debitMerged($t, $s),
        );
        $machine = new BasketStateMachine($cfg, $exec, new PolyExecGate($cfg), $book, null, $onChain);

        $legs = [];
        foreach (['A', 'B', 'C'] as $i => $t) {
            $legs[] = ['token' => $t, 'question' => 'Q', 'position' => $i,
                'plan_depth' => 1000.0, 'target_price' => 0.30, 'limit_price' => 0.303, 'target_size' => 2.0];
        }
        $plan = new BasketPlan('evt', 'long_sum_under', 'simple_buy_all_legs', $legs, 2.0, 0.90,
            1.80, 0.20, 0.10, 0.0, 0.0, 8.0, 100, 1000.0, 1200, '2999-01-01T00:00:00+00:00', 24.0, 0.30, 0.30,
            conditionId: 'cond-long', negRisk: true);

        $summary = $machine->execute($plan, 'merge-1', 's1');

        $this->assertSame('filled', $summary['status']);
        $this->assertSame('merge', $summary['realize_method']);
        $this->assertNotNull($summary['merge_tx_hash']);
        $this->assertFalse($summary['pnl_is_locked_at_resolution'], 'merge banks the profit now');
        // Sim buys at the real book ask (0.30): 3 legs * 2 sets * 0.30 = 1.80; merge
        // returns 2 sets * $1 = 2.00; pnl = 2.00 - 1.80 - 0.05 gas.
        $this->assertEqualsWithDelta(2.00, $summary['cash_in_usd'], 1e-4);
        $this->assertEqualsWithDelta(2.00 - 1.80 - 0.05, $summary['realized_pnl_usd'], 1e-4);

        $daily = DB::table('atlas_poly_exec_daily')->where('mode', 'sim')->first();
        $this->assertGreaterThan(0.0, (float) $daily->realized_pnl_usd, 'merge realizes pnl into the daily ledger');
    }

    public function test_long_hold_default_is_unchanged_when_no_onchain_client(): void
    {
        $cfg = $this->cfg(['longRealizeMethod' => 'hold']);
        $book = fn (string $t): ?array => ['asks' => [['price' => 0.30, 'size' => 1000]], 'bids' => [['price' => 0.28, 'size' => 1000]]];
        $exec = new SimulatedPolyExecClient($book);
        // No on-chain client -> classic carry-to-resolution behavior.
        $machine = new BasketStateMachine($cfg, $exec, new PolyExecGate($cfg), $book);

        $legs = [];
        foreach (['A', 'B', 'C'] as $i => $t) {
            $legs[] = ['token' => $t, 'question' => 'Q', 'position' => $i,
                'plan_depth' => 1000.0, 'target_price' => 0.30, 'limit_price' => 0.303, 'target_size' => 2.0];
        }
        $plan = new BasketPlan('evt', 'long_sum_under', 'simple_buy_all_legs', $legs, 2.0, 0.90,
            1.80, 0.20, 0.10, 0.0, 0.0, 8.0, 100, 1000.0, 1200, '2999-01-01T00:00:00+00:00', 24.0, 0.30, 0.30);

        $summary = $machine->execute($plan, 'hold-1', 's1');

        $this->assertSame('filled', $summary['status']);
        $this->assertTrue($summary['pnl_is_locked_at_resolution'], 'without merge the position carries to resolution');
        $this->assertNull($summary['merge_tx_hash']);
    }
}
