<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\ArbAllocator;
use App\Services\Ai\Finance\PolymarketExec\BasketPlanner;
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

final class ArbAllocatorTest extends TestCase
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
            liveEnabled: false, maxBasketUsd: $o['maxBasketUsd'] ?? 8.0, dailyCapUsd: $o['dailyCapUsd'] ?? 25.0,
            maxConcurrentBaskets: 2, minDepthMultiple: 3.0, minPersistenceSeconds: 600,
            minNetEdgePerSet: 0.01, maxResolutionHours: 72.0, slippageBps: 100, takerFeeRate: 0.0,
            estGasUsdPerBasket: 0.0, killSwitchPath: $o['killSwitchPath'] ?? sys_get_temp_dir().'/k'.uniqid(),
            estMintGasUsd: 0.05, estMergeGasUsd: 0.05,
        );
    }

    /** Per-token book. Short legs carry bids; long legs carry asks. */
    private function book(): callable
    {
        return function (string $t): ?array {
            // short events: A* bids 0.40, B* bids 0.45; long event: L* asks 0.30.
            return match (true) {
                str_starts_with($t, 'A') => ['asks' => [['price' => 0.62, 'size' => 1000]], 'bids' => [['price' => 0.40, 'size' => 1000]]],
                str_starts_with($t, 'B') => ['asks' => [['price' => 0.66, 'size' => 1000]], 'bids' => [['price' => 0.45, 'size' => 1000]]],
                str_starts_with($t, 'L') => ['asks' => [['price' => 0.30, 'size' => 1000]], 'bids' => [['price' => 0.28, 'size' => 1000]]],
                str_starts_with($t, 'P') => ['asks' => [['price' => 0.346, 'size' => 1000]], 'bids' => [['price' => 0.335, 'size' => 1000]]], // pennies short
                default => null,
            };
        };
    }

    private function allocator(PolyExecConfig $cfg, ?string $idempotencyScope = null): ArbAllocator
    {
        $book = $this->book();
        $meta = fn (string $s): array => ['endDate' => now()->addDay()->toIso8601String(), 'negRisk' => true, 'negRiskMarketID' => 'cond-'.$s];
        $exec = new SimulatedPolyExecClient($book);
        $onChain = new SimulatedPolyOnChainClient(
            mintGasUsd: 0.05, mergeGasUsd: 0.05,
            onMint: fn (array $t, float $s) => $exec->creditMinted($t, $s),
            onMerge: fn (array $t, float $s) => $exec->debitMerged($t, $s),
        );
        $gate = new PolyExecGate($cfg);

        return new ArbAllocator(
            $cfg, $gate,
            new BasketPlanner($cfg, $book, $meta),
            new ShortBasketPlanner($cfg, $book, $meta),
            new BasketStateMachine($cfg, $exec, $gate, $book, null, $onChain),
            new MintSellStateMachine($cfg, $exec, $onChain, $gate, $book),
            $idempotencyScope,
        );
    }

    /** @return list<array<string, mixed>> */
    private function candidates(): array
    {
        return [
            ['event_slug' => 'long-evt', 'kind' => 'long_sum_under', 'persistence_seconds' => 1200, 'rank_profit_usd' => 0.5,
                'legs' => [['token' => 'L1'], ['token' => 'L2'], ['token' => 'L3']]],
            ['event_slug' => 'short-a', 'kind' => 'short_sum_over', 'persistence_seconds' => 1200, 'rank_profit_usd' => 1.5,
                'legs' => [['token' => 'A1'], ['token' => 'A2'], ['token' => 'A3']]],
            ['event_slug' => 'short-b', 'kind' => 'short_sum_over', 'persistence_seconds' => 1200, 'rank_profit_usd' => 2.8,
                'legs' => [['token' => 'B1'], ['token' => 'B2'], ['token' => 'B3']]],
        ];
    }

    public function test_dispatches_both_kinds_ranked_by_value_under_the_budget(): void
    {
        $cfg = $this->cfg(['dailyCapUsd' => 25.0]);
        $out = $this->allocator($cfg)->allocate('sim', 'sess1', $this->candidates());

        $this->assertSame(3, $out['dispatched']);
        $this->assertNull($out['blocked']);
        // Ranked by value desc: short-b (2.8), short-a (1.5), long-evt (0.5).
        $this->assertSame(['short-b', 'short-a', 'long-evt'], array_column($out['results'], 'event_slug'));
        $this->assertSame(['short_sum_over', 'short_sum_over', 'long_sum_under'], array_column($out['results'], 'kind'));
        foreach ($out['results'] as $r) {
            $this->assertContains($r['status'], ['settled', 'filled'], 'every dispatched basket reached a terminal success state');
        }
        // The same bankroll served multiple baskets in one pass (the short recycle).
        $this->assertSame(3, DB::table('atlas_poly_exec_baskets')->where('status', '!=', 'gated')->count());
    }

    public function test_sim_idempotency_scope_allows_fresh_shadow_windows_without_double_running_same_scope(): void
    {
        $cfg = $this->cfg(['dailyCapUsd' => 25.0]);
        $candidate = [array_values(array_filter(
            $this->candidates(),
            fn (array $candidate): bool => $candidate['event_slug'] === 'long-evt',
        ))[0]];
        $candidate[0]['attempt_key'] = 'scan-1';
        $candidateNextTick = $candidate;
        $candidateNextTick[0]['attempt_key'] = 'scan-2';

        $first = $this->allocator($cfg, 'window-a')->allocate('sim', 'sess-a1', $candidate);
        $sameScope = $this->allocator($cfg, 'window-a')->allocate('sim', 'sess-a2', $candidate);
        $sameScopeNewAttempt = $this->allocator($cfg, 'window-a')->allocate('sim', 'sess-a3', $candidateNextTick);
        $freshScope = $this->allocator($cfg, 'window-b')->allocate('sim', 'sess-b1', $candidate);

        $this->assertSame('filled', $first['results'][0]['status']);
        $this->assertSame('filled', $sameScope['results'][0]['status']);
        $this->assertTrue($sameScope['results'][0]['idempotent_replay']);
        $this->assertSame($first['results'][0]['basket_id'], $sameScope['results'][0]['basket_id']);
        $this->assertSame('filled', $sameScopeNewAttempt['results'][0]['status']);
        $this->assertArrayNotHasKey('idempotent_replay', $sameScopeNewAttempt['results'][0]);
        $this->assertNotSame($first['results'][0]['basket_id'], $sameScopeNewAttempt['results'][0]['basket_id']);
        $this->assertSame('filled', $freshScope['results'][0]['status']);
        $this->assertArrayNotHasKey('idempotent_replay', $freshScope['results'][0]);
        $this->assertNotSame($first['results'][0]['basket_id'], $freshScope['results'][0]['basket_id']);
    }

    public function test_terminal_replay_skips_planning_book_reads(): void
    {
        $cfg = $this->cfg(['dailyCapUsd' => 25.0]);
        $candidate = [array_values(array_filter(
            $this->candidates(),
            fn (array $candidate): bool => $candidate['event_slug'] === 'long-evt',
        ))[0]];
        $candidate[0]['attempt_key'] = 'scan-1';

        $first = $this->allocator($cfg, 'window-fast-replay')->allocate('sim', 'sess-a1', $candidate);
        $this->assertSame('filled', $first['results'][0]['status']);

        $book = function (): ?array {
            $this->fail('terminal replay should not read the book or planner');
        };
        $meta = fn (string $s): array => ['endDate' => now()->addDay()->toIso8601String(), 'negRisk' => true, 'negRiskMarketID' => 'cond-'.$s];
        $exec = new SimulatedPolyExecClient($book);
        $onChain = new SimulatedPolyOnChainClient(
            mintGasUsd: 0.05, mergeGasUsd: 0.05,
            onMint: fn (array $t, float $s) => $exec->creditMinted($t, $s),
            onMerge: fn (array $t, float $s) => $exec->debitMerged($t, $s),
        );
        $gate = new PolyExecGate($cfg);
        $allocator = new ArbAllocator(
            $cfg, $gate,
            new BasketPlanner($cfg, $book, $meta),
            new ShortBasketPlanner($cfg, $book, $meta),
            new BasketStateMachine($cfg, $exec, $gate, $book, null, $onChain),
            new MintSellStateMachine($cfg, $exec, $onChain, $gate, $book),
            'window-fast-replay',
        );

        $sameScope = $allocator->allocate('sim', 'sess-a2', $candidate);

        $this->assertSame(1, $sameScope['processed']);
        $this->assertSame(1, $sameScope['dispatched']);
        $this->assertSame('filled', $sameScope['results'][0]['status']);
        $this->assertTrue($sameScope['results'][0]['idempotent_replay']);
        $this->assertSame($first['results'][0]['basket_id'], $sameScope['results'][0]['basket_id']);
    }

    public function test_does_not_pre_filter_a_pennies_sized_opportunity(): void
    {
        // P* bids ~0.335*3 = 1.005 -> ~半 cent/set edge; still net-positive past gas -> taken.
        $cfg = $this->cfg();
        $cands = [
            ['event_slug' => 'pennies', 'kind' => 'short_sum_over', 'persistence_seconds' => 1200, 'rank_profit_usd' => 0.02,
                'legs' => [['token' => 'P1'], ['token' => 'P2'], ['token' => 'P3']]],
        ];
        // Lower the net-edge floor so a sub-cent edge clears (the operator captures cents).
        $cfg = new PolyExecConfig(
            liveEnabled: false, maxBasketUsd: 8.0, dailyCapUsd: 25.0, maxConcurrentBaskets: 2,
            minDepthMultiple: 3.0, minPersistenceSeconds: 600, minNetEdgePerSet: 0.001, maxResolutionHours: 72.0,
            slippageBps: 100, takerFeeRate: 0.0, estGasUsdPerBasket: 0.0,
            killSwitchPath: sys_get_temp_dir().'/k'.uniqid(), estMintGasUsd: 0.0, estMergeGasUsd: 0.0,
        );

        $out = $this->allocator($cfg)->allocate('sim', 'sess1', $cands);

        $this->assertSame(1, $out['dispatched']);
        $this->assertSame('settled', $out['results'][0]['status'], 'a cents-sized but net-positive opportunity is NOT pre-filtered');
    }

    public function test_kill_switch_stops_the_whole_pass(): void
    {
        $kill = sys_get_temp_dir().'/k'.uniqid();
        touch($kill);
        $cfg = $this->cfg(['killSwitchPath' => $kill]);

        $out = $this->allocator($cfg)->allocate('sim', 'sess1', $this->candidates());

        $this->assertSame(0, $out['dispatched']);
        $this->assertStringContainsString('kill', (string) $out['blocked']);
        @unlink($kill);
    }

    public function test_unplannable_candidate_is_processed_but_not_dispatched(): void
    {
        $cfg = $this->cfg();
        $out = $this->allocator($cfg)->allocate('sim', 'sess1', [[
            'event_slug' => 'missing-book',
            'kind' => 'long_sum_under',
            'persistence_seconds' => 1200,
            'rank_profit_usd' => 10.0,
            'legs' => [['token' => 'UNKNOWN1'], ['token' => 'UNKNOWN2']],
        ]]);

        $this->assertSame(1, $out['processed']);
        $this->assertSame(0, $out['dispatched']);
        $this->assertSame('unplannable', $out['results'][0]['status']);
        $this->assertSame('long_planner_returned_null', $out['results'][0]['status_reason']);
    }

    public function test_daily_budget_stops_dispatch_when_exhausted(): void
    {
        // Cap fits ~1 short mint (8 sets * $1) then halts.
        $cfg = $this->cfg(['dailyCapUsd' => 8.0]);
        $out = $this->allocator($cfg)->allocate('sim', 'sess1', $this->candidates());

        $this->assertGreaterThanOrEqual(1, $out['dispatched']);
        $this->assertNotNull($out['blocked'], 'the pass stops once the daily budget is spent');
        // The highest-value short ran first.
        $this->assertSame('short-b', $out['results'][0]['event_slug']);
    }
}
