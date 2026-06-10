<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\FillResult;
use App\Services\Ai\Finance\PolymarketExec\MintSellStateMachine;
use App\Services\Ai\Finance\PolymarketExec\PolyExecConfig;
use App\Services\Ai\Finance\PolymarketExec\PolyExecGate;
use App\Services\Ai\Finance\PolymarketExec\ShortBasketPlan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPolyExecTables;
use Tests\TestCase;

/**
 * The SHORT motor: mint a full set on-chain, sell the sellable legs, freeroll
 * the rest. Proven against scripted CLOB fills + a scripted on-chain client.
 */
final class MintSellStateMachineTest extends TestCase
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
            estGasUsdPerBasket: 0.0,
            killSwitchPath: $o['killSwitchPath'] ?? sys_get_temp_dir().'/atlas-poly-kill-none-'.uniqid(),
            estMintGasUsd: $o['estMintGasUsd'] ?? 0.05,
            estMergeGasUsd: $o['estMergeGasUsd'] ?? 0.05,
            shortMergeOnNoSell: $o['shortMergeOnNoSell'] ?? false,
        );
    }

    /**
     * 3 sellable legs each best-bid 0.40 (sum 1.20, edge 0.20/set), floor 0.396,
     * plus one freeroll outcome FREE with no bid. Already ordered thinnest-first.
     */
    private function plan(float $targetSets = 2.0): ShortBasketPlan
    {
        $legs = [];
        foreach (['THIN', 'MID', 'THICK'] as $i => $t) {
            $legs[] = [
                'token' => $t, 'question' => 'Q '.$t, 'position' => $i,
                'plan_depth' => 1000.0, 'target_price' => 0.40, 'limit_price' => 0.396, 'target_size' => $targetSets,
            ];
        }

        return new ShortBasketPlan(
            eventSlug: 'evt-short', legs: $legs,
            allTokenIds: ['THIN', 'MID', 'THICK', 'FREE'], freerollTokens: ['FREE'],
            conditionId: 'cond-1', negRisk: true,
            targetSets: $targetSets, targetSumBids: 1.20, mintCostUsd: round(1.0 * $targetSets, 4),
            estProfitUsd: round(0.20 * $targetSets - 0.05, 4), estEdgePerSet: 0.20, estFeeUsd: 0.0, estGasUsd: 0.05,
            capUsd: 8.0, slippageBps: 100, executableDepthShares: 1000.0, persistenceSeconds: 1200,
            resolutionAt: '2999-01-01T00:00:00+00:00', resolutionHours: 24.0, minLegPrice: 0.40, maxLegPrice: 0.40,
        );
    }

    /** Fresh-book source so verifyFreshBids passes (deep bids at 0.40 for sellable legs). */
    private function bids(): callable
    {
        return function (string $token): ?array {
            if ($token === 'FREE') {
                return ['asks' => [['price' => 0.30, 'size' => 1000]], 'bids' => []];
            }
            if (! in_array($token, ['THIN', 'MID', 'THICK'], true)) {
                return null;
            }

            return ['asks' => [['price' => 0.42, 'size' => 1000]], 'bids' => [['price' => 0.40, 'size' => 1000]]];
        };
    }

    private function machine(PolyExecConfig $cfg, ScriptedPolyExecClient $client, ?ScriptedPolyOnChainClient $chain = null): MintSellStateMachine
    {
        $chain ??= new ScriptedPolyOnChainClient(
            onMint: fn (array $tokens, float $sets) => $client->creditMinted($tokens, $sets),
            onMerge: fn (array $tokens, float $sets) => $client->debitMerged($tokens, $sets),
        );

        return new MintSellStateMachine($cfg, $client, $chain, new PolyExecGate($cfg), $this->bids());
    }

    public function test_happy_path_mints_then_sells_all_sellable_legs_and_banks_now(): void
    {
        $cfg = $this->cfg();
        $client = new ScriptedPolyExecClient('sim');
        $summary = $this->machine($cfg, $client)->execute($this->plan(2.0), 's1', 'sess1');

        $this->assertSame('settled', $summary['status']);
        $this->assertSame('sold', $summary['realize_method']);
        $this->assertSame(3, $summary['legs_sold']);
        $this->assertSame(1, $summary['legs_freeroll'], 'the no-bid FREE outcome is held as a freeroll');
        $this->assertSame(['THIN', 'MID', 'THICK'], array_column($client->sellLimits, 'token'), 'sells follow thinnest-first order');
        $this->assertNotNull($summary['mint_tx_hash']);
        // cash_in = 3 legs * 2 sets * 0.396 (scripted fills at floor) = 2.376; pnl = 2.376 - 2.0 - 0.05.
        $this->assertEqualsWithDelta(2.376, $summary['cash_in_usd'], 1e-4);
        $this->assertEqualsWithDelta(2.376 - 2.0 - 0.05, $summary['realized_pnl_usd'], 1e-4);
        $this->assertFalse($summary['pnl_is_locked_at_resolution'], 'short banks now, not at resolution');
        $this->assertGreaterThan(0.0, $summary['realized_pnl_usd']);

        $daily = DB::table('atlas_poly_exec_daily')->where('mode', 'sim')->first();
        $this->assertSame(1, (int) $daily->baskets_filled);
        $this->assertEqualsWithDelta(2.0, (float) $daily->deployed_usd, 1e-6, 'capital deployed = the $1/set mint cost');
    }

    public function test_mint_failure_leaves_no_position(): void
    {
        $cfg = $this->cfg();
        $client = new ScriptedPolyExecClient('sim');
        $chain = (new ScriptedPolyOnChainClient(
            onMint: fn (array $t, float $s) => $client->creditMinted($t, $s),
        ))->failSplitWith('onchain_requires_eoa');

        $summary = $this->machine($cfg, $client, $chain)->execute($this->plan(2.0), 's2', 'sess1');

        $this->assertSame('failed', $summary['status']);
        $this->assertNull($summary['mint_tx_hash']);
        $this->assertSame([], $client->sellLimits, 'a failed mint never sells');
        $this->assertStringContainsString('onchain_requires_eoa', (string) $summary['error']);
    }

    public function test_a_leg_that_will_not_sell_becomes_a_freeroll_not_an_abort(): void
    {
        $cfg = $this->cfg();
        $client = new ScriptedPolyExecClient('sim');
        // MID cannot sell at the floor.
        $client->scriptSellLimit('MID', FillResult::nothing('no_bid_at_limit'));

        $summary = $this->machine($cfg, $client)->execute($this->plan(2.0), 's3', 'sess1');

        $this->assertSame('settled', $summary['status'], 'a failed sell settles with a freeroll; it never aborts the whole basket');
        $this->assertSame('sold_partial', $summary['realize_method']);
        $this->assertSame(2, $summary['legs_sold'], 'THIN + THICK sold');
        $this->assertSame(2, $summary['legs_freeroll'], 'MID held + FREE held');
        $mid = DB::table('atlas_poly_exec_legs')->where('basket_id', 's3')->where('token', 'MID')->first();
        $this->assertSame('freeroll', $mid->status);
        // Honest bounded risk: a leg's liquidity vanishing AFTER the mint turns locked
        // arb into a contingent position. Banked cash (2 legs * 2 sets * 0.396 = 1.584)
        // is below the $2 mint; realized P&L is the guaranteed-banked figure, and the
        // held MID + FREE outcomes are the contingent recovery (value 0 in realized P&L).
        $this->assertEqualsWithDelta(1.584 - 2.0 - 0.05, $summary['realized_pnl_usd'], 1e-4);
        $this->assertFalse($summary['pnl_is_locked_at_resolution']);
    }

    public function test_below_floor_fill_is_rejected_to_freeroll(): void
    {
        $cfg = $this->cfg();
        $client = new ScriptedPolyExecClient('sim');
        // THICK "fills" but BELOW the 0.396 floor -> must be held, not sold at a loss.
        $client->scriptSellLimit('THICK', new FillResult(true, 2.0, 0.20, 0.40, 'under'));

        $summary = $this->machine($cfg, $client)->execute($this->plan(2.0), 's3b', 'sess1');

        $this->assertSame('settled', $summary['status']);
        $thick = DB::table('atlas_poly_exec_legs')->where('basket_id', 's3b')->where('token', 'THICK')->first();
        $this->assertSame('freeroll', $thick->status);
        $this->assertStringContainsString('below_floor', (string) $thick->error);
    }

    public function test_no_legs_sell_holds_the_complete_set_to_resolution(): void
    {
        $cfg = $this->cfg(['shortMergeOnNoSell' => false]);
        $client = new ScriptedPolyExecClient('sim');
        foreach (['THIN', 'MID', 'THICK'] as $t) {
            $client->scriptSellLimit($t, FillResult::nothing('no_bid_at_limit'));
        }

        $summary = $this->machine($cfg, $client)->execute($this->plan(2.0), 's4', 'sess1');

        $this->assertSame('settled', $summary['status']);
        $this->assertSame('hold', $summary['realize_method']);
        $this->assertSame(0, $summary['legs_sold']);
        $this->assertSame(4, $summary['legs_freeroll'], 'the whole minted set is held');
        $this->assertTrue($summary['pnl_is_locked_at_resolution'], 'collateral returns at resolution; only gas is lost');
        $this->assertEqualsWithDelta(-0.05, $summary['realized_pnl_usd'], 1e-6);
    }

    public function test_no_legs_sell_with_merge_enabled_recovers_collateral_now(): void
    {
        $cfg = $this->cfg(['shortMergeOnNoSell' => true]);
        $client = new ScriptedPolyExecClient('sim');
        foreach (['THIN', 'MID', 'THICK'] as $t) {
            $client->scriptSellLimit($t, FillResult::nothing('no_bid_at_limit'));
        }

        $summary = $this->machine($cfg, $client)->execute($this->plan(2.0), 's5', 'sess1');

        $this->assertSame('settled', $summary['status']);
        $this->assertSame('merge', $summary['realize_method']);
        $this->assertNotNull($summary['merge_tx_hash']);
        $this->assertSame(0, $summary['legs_freeroll']);
        // Merge returns the $2 collateral; net = -(mint gas + merge gas) = -0.10.
        $this->assertEqualsWithDelta(-0.10, $summary['realized_pnl_usd'], 1e-6);
        $this->assertFalse($summary['pnl_is_locked_at_resolution']);
    }

    public function test_kill_switch_before_mint_gates_with_no_cost(): void
    {
        $kill = sys_get_temp_dir().'/atlas-poly-kill-'.uniqid();
        touch($kill);
        $cfg = $this->cfg(['killSwitchPath' => $kill]);
        $client = new ScriptedPolyExecClient('sim');

        $summary = $this->machine($cfg, $client)->execute($this->plan(2.0), 's6', 'sess1');

        // A kill engaged before execute is caught at the runtime-caps layer (halted);
        // a kill engaged after creation but before mint is caught in drive() (gated).
        // Both mean: no mint, no cost.
        $this->assertContains($summary['status'], ['halted', 'gated']);
        $this->assertNull($summary['mint_tx_hash'], 'kill before mint commits nothing');
        $this->assertSame([], $client->sellLimits);
        @unlink($kill);
    }

    public function test_kill_switch_mid_selling_holds_the_residual(): void
    {
        $kill = sys_get_temp_dir().'/atlas-poly-kill-'.uniqid();
        $cfg = $this->cfg(['killSwitchPath' => $kill]);
        $client = new ScriptedPolyExecClient('sim');
        // Engage the kill right after the FIRST sell returns.
        $client->afterEachSell(function (string $token) use ($kill): void {
            if ($token === 'THIN') {
                touch($kill);
            }
        });

        $summary = $this->machine($cfg, $client)->execute($this->plan(2.0), 's7', 'sess1');

        $this->assertSame('settled', $summary['status']);
        $this->assertSame(['THIN'], array_column($client->sellLimits, 'token'), 'kill stops further sells');
        $this->assertSame(1, $summary['legs_sold']);
        $this->assertSame(3, $summary['legs_freeroll'], 'MID + THICK + FREE all held');
        @unlink($kill);
    }

    public function test_idempotent_rerun_does_not_re_mint_or_re_sell(): void
    {
        $cfg = $this->cfg();
        $client = new ScriptedPolyExecClient('sim');
        $machine = $this->machine($cfg, $client);

        $machine->execute($this->plan(2.0), 's8', 'sess1');
        $sellsAfterFirst = count($client->sellLimits);
        $this->assertSame(3, $sellsAfterFirst);

        $again = $machine->execute($this->plan(2.0), 's8', 'sess1');
        $this->assertSame('settled', $again['status']);
        $this->assertCount($sellsAfterFirst, $client->sellLimits, 're-running a terminal basket sells nothing more');
        $this->assertSame(1, DB::table('atlas_poly_exec_baskets')->where('basket_id', 's8')->count());
        // Exactly one mint event ever recorded (no re-mint on resume).
        $this->assertSame(1, DB::table('atlas_poly_exec_events')->where('basket_id', 's8')->where('kind', 'mint')->count());
    }

    public function test_daily_cap_halts_after_the_mint_budget_is_consumed(): void
    {
        $cfg = $this->cfg(['dailyCapUsd' => 2.0]); // exactly one $2 mint
        $client = new ScriptedPolyExecClient('sim');
        $machine = $this->machine($cfg, $client);

        $first = $machine->execute($this->plan(2.0), 'cap1', 'sess1');
        $this->assertSame('settled', $first['status']);
        $this->assertTrue((bool) DB::table('atlas_poly_exec_daily')->where('mode', 'sim')->value('halted'));

        $second = $machine->execute($this->plan(2.0), 'cap2', 'sess1');
        $this->assertSame('halted', $second['status']);
        $this->assertNull($second['mint_tx_hash']);
    }

    public function test_short_accepts_a_far_future_market_the_long_horizon_would_reject(): void
    {
        // 200h out: beyond the 72h long ceiling, within the 720h short ceiling. The
        // short banks now, so it must NOT be rejected on resolution horizon.
        $cfg = $this->cfg(['maxResolutionHours' => 72.0]); // shortMaxResolutionHours defaults to 720
        $client = new ScriptedPolyExecClient('sim');
        $plan = $this->plan(2.0);
        $far = new ShortBasketPlan(
            eventSlug: $plan->eventSlug, legs: $plan->legs, allTokenIds: $plan->allTokenIds,
            freerollTokens: $plan->freerollTokens, conditionId: $plan->conditionId, negRisk: $plan->negRisk,
            targetSets: $plan->targetSets, targetSumBids: $plan->targetSumBids, mintCostUsd: $plan->mintCostUsd,
            estProfitUsd: $plan->estProfitUsd, estEdgePerSet: $plan->estEdgePerSet, estFeeUsd: $plan->estFeeUsd,
            estGasUsd: $plan->estGasUsd, capUsd: $plan->capUsd, slippageBps: $plan->slippageBps,
            executableDepthShares: $plan->executableDepthShares, persistenceSeconds: $plan->persistenceSeconds,
            resolutionAt: '2030-01-01T00:00:00+00:00', resolutionHours: 200.0,
            minLegPrice: $plan->minLegPrice, maxLegPrice: $plan->maxLegPrice,
        );

        $summary = $this->machine($cfg, $client)->execute($far, 'far1', 'sess1');

        $this->assertSame('settled', $summary['status'], 'short reaches the slow markets where the arb lives');
        $this->assertSame(3, $summary['legs_sold']);
    }

    public function test_verify_rejects_when_fresh_bids_lost_their_edge(): void
    {
        $cfg = $this->cfg();
        $client = new ScriptedPolyExecClient('sim');
        $chain = new ScriptedPolyOnChainClient(onMint: fn (array $t, float $s) => $client->creditMinted($t, $s));
        // Fresh bids now sum < 1 (edge gone) -> gated, never minted.
        $deadBids = fn (string $t): ?array => ['asks' => [['price' => 0.40, 'size' => 1000]], 'bids' => [['price' => 0.20, 'size' => 1000]]];
        $machine = new MintSellStateMachine($cfg, $client, $chain, new PolyExecGate($cfg), $deadBids);

        $summary = $machine->execute($this->plan(2.0), 'v1', 'sess1');

        $this->assertSame('gated', $summary['status']);
        $this->assertNull($summary['mint_tx_hash'], 'a collapsed edge means we never mint');
        $this->assertSame([], $chain->calls);
    }
}
