<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopBudgetScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Absurd-leap 5 — budget-optimal multi-obra scheduler. Picks the cheapest provider per obra and greedily
 * allocates by value-per-cost within a budget + concurrency cap; defers the rest (never silently dropped).
 */
final class AtlasLoopBudgetSchedulerTest extends TestCase
{
    public function test_picks_the_cheapest_provider_per_obra(): void
    {
        $r = (new AtlasLoopBudgetScheduler)->schedule([
            ['id' => 'o1', 'value' => 1.0, 'costs' => ['codex' => 10.0, 'minimax_m27' => 3.0, 'hermes_cli' => 6.0]],
        ], 100.0, 4);
        $this->assertSame('minimax_m27', $r['scheduled'][0]['provider'], 'cheapest configured provider wins');
        $this->assertEqualsWithDelta(3.0, $r['scheduled'][0]['cost'], 0.0001);
    }

    public function test_budget_limits_to_highest_value_per_cost(): void
    {
        $r = (new AtlasLoopBudgetScheduler)->schedule([
            ['id' => 'cheap_high_value', 'value' => 10.0, 'costs' => ['m' => 2.0]],  // ratio 5
            ['id' => 'pricey_low_value', 'value' => 1.0, 'costs' => ['m' => 5.0]],   // ratio 0.2
        ], 2.0, 4); // budget only fits one
        $ids = array_map(fn ($s) => $s['id'], $r['scheduled']);
        $this->assertSame(['cheap_high_value'], $ids, 'highest value/cost is scheduled first within budget');
        $this->assertSame(['pricey_low_value'], $r['deferred']);
        $this->assertEqualsWithDelta(2.0, $r['spent'], 0.0001);
    }

    public function test_concurrency_cap_is_respected(): void
    {
        $obras = [];
        for ($i = 0; $i < 5; $i++) {
            $obras[] = ['id' => 'o'.$i, 'value' => 1.0, 'costs' => ['m' => 1.0]];
        }
        $r = (new AtlasLoopBudgetScheduler)->schedule($obras, 1000.0, 2);
        $this->assertCount(2, $r['scheduled'], 'never exceeds the concurrency cap even with budget to spare');
        $this->assertCount(3, $r['deferred']);
    }

    public function test_obra_with_no_configured_provider_is_skipped(): void
    {
        $r = (new AtlasLoopBudgetScheduler)->schedule([
            ['id' => 'no_cost', 'value' => 9.0, 'costs' => []],
            ['id' => 'ok', 'value' => 1.0, 'costs' => ['m' => 1.0]],
        ], 100.0, 4);
        $ids = array_map(fn ($s) => $s['id'], $r['scheduled']);
        $this->assertSame(['ok'], $ids, 'an obra with no configured provider/cost is not schedulable');
    }

    public function test_all_fit_within_generous_budget(): void
    {
        $r = (new AtlasLoopBudgetScheduler)->schedule([
            ['id' => 'a', 'value' => 1.0, 'costs' => ['m' => 1.0]],
            ['id' => 'b', 'value' => 1.0, 'costs' => ['m' => 1.0]],
        ], 100.0, 4);
        $this->assertCount(2, $r['scheduled']);
        $this->assertSame([], $r['deferred']);
        $this->assertEqualsWithDelta(2.0, $r['expected_value'], 0.0001);
    }

    public function test_nan_and_infinite_costs_are_sanitized(): void
    {
        // (workflow fix #18) NaN/INF costs (realistic from live telemetry) must not poison the schedule —
        // they are rejected; a finite alternative provider is used instead.
        $r = (new AtlasLoopBudgetScheduler)->schedule([
            ['id' => 'mixed', 'value' => 1.0, 'costs' => ['bad' => NAN, 'worse' => INF, 'good' => 4.0]],
        ], 100.0, 4);
        $this->assertCount(1, $r['scheduled']);
        $this->assertSame('good', $r['scheduled'][0]['provider'], 'NaN/INF rejected, finite provider chosen');
        $this->assertEqualsWithDelta(4.0, $r['scheduled'][0]['cost'], 0.0001);
    }

    public function test_unschedulable_obra_is_deferred_not_silently_dropped(): void
    {
        // (workflow fix #19) an obra with no usable (finite/positive) provider cost must appear in deferred,
        // honoring the documented "never silently dropped" invariant.
        $r = (new AtlasLoopBudgetScheduler)->schedule([
            ['id' => 'all_nan', 'value' => 9.0, 'costs' => ['x' => NAN]],
            ['id' => 'no_costs', 'value' => 5.0, 'costs' => []],
            ['id' => 'ok', 'value' => 1.0, 'costs' => ['m' => 1.0]],
        ], 100.0, 4);
        $this->assertSame(['ok'], array_map(fn ($s) => $s['id'], $r['scheduled']));
        $this->assertContains('all_nan', $r['deferred']);
        $this->assertContains('no_costs', $r['deferred']);
    }
}
