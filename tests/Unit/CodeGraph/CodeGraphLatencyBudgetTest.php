<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphLatencyBudget;
use RuntimeException;
use Tests\TestCase;

/**
 * AP-815 · D-2 — CodeGraphLatencyBudget (interactive SLO meter).
 *
 * Pure unit test: no DB, no real sleeping. A fake clock returns a scripted queue of
 * millisecond readings so elapsed time is fully deterministic. `run()` reads the clock
 * exactly twice per call (start, then end), so each test scripts pairs of readings.
 */
class CodeGraphLatencyBudgetTest extends TestCase
{
    /**
     * Build a budget whose injected clock yields the given readings in order.
     * Once exhausted it repeats the last reading (so an unexpected extra read is benign).
     *
     * @param  list<int|float>  $readings
     */
    private function withClock(array $readings): CodeGraphLatencyBudget
    {
        $i = 0;

        return new CodeGraphLatencyBudget(function () use (&$i, $readings): int|float {
            $last = $readings === [] ? 0 : $readings[count($readings) - 1];
            $value = $readings[$i] ?? $last;
            $i++;

            return $value;
        });
    }

    public function test_fast_op_is_within_budget_and_records_no_breach(): void
    {
        // start=1000ms, end=1010ms => elapsed 10ms, budget 1500ms.
        $budget = $this->withClock([1000, 1010]);

        $out = $budget->run(fn () => 'graph-answer', 1500, 'neighbourhood');

        $this->assertSame('graph-answer', $out['result']);
        $this->assertSame(10.0, $out['elapsed_ms']);
        $this->assertTrue($out['within_budget']);
        $this->assertSame(1500, $out['budget_ms']);
        $this->assertSame('neighbourhood', $out['label']);
        $this->assertSame(0.0, $out['over_by_ms']);

        $this->assertFalse($budget->hasBreaches());
        $this->assertSame(0, $budget->breachCount());
        $this->assertSame([], $budget->breaches());
    }

    public function test_slow_op_breaches_budget_and_is_recorded(): void
    {
        $t0 = 5000;
        $budgetMs = 200;
        // end = t0 + budget + 50 => 50ms over budget.
        $budget = $this->withClock([$t0, $t0 + $budgetMs + 50]);

        $out = $budget->run(fn () => ['nodes' => 3], $budgetMs, 'traversal');

        $this->assertSame(['nodes' => 3], $out['result']);
        $this->assertSame(250.0, $out['elapsed_ms']);
        $this->assertFalse($out['within_budget']);
        $this->assertSame(50.0, $out['over_by_ms']);

        $this->assertTrue($budget->hasBreaches());
        $this->assertSame(1, $budget->breachCount());

        $breaches = $budget->breaches();
        $this->assertCount(1, $breaches);
        $this->assertSame('traversal', $breaches[0]['label']);
        $this->assertSame(250.0, $breaches[0]['elapsed_ms']);
        $this->assertSame(200, $breaches[0]['budget_ms']);
        $this->assertSame(50.0, $breaches[0]['over_by_ms']);
        $this->assertSame(1, $breaches[0]['at']);
    }

    public function test_elapsed_exactly_at_budget_is_within_budget_not_a_breach(): void
    {
        // Inclusive SLO boundary: elapsed == budget is NOT a breach.
        $budget = $this->withClock([100, 100 + 300]);

        $out = $budget->run(fn () => true, 300);

        $this->assertSame(300.0, $out['elapsed_ms']);
        $this->assertTrue($out['within_budget']);
        $this->assertSame(0.0, $out['over_by_ms']);
        $this->assertSame('code_graph.query', $out['label']); // default label when blank
        $this->assertFalse($budget->hasBreaches());
    }

    public function test_exception_from_op_captures_elapsed_records_breach_and_rethrows(): void
    {
        $t0 = 2000;
        $budgetMs = 100;
        // Failing op runs 400ms over budget before throwing.
        $budget = $this->withClock([$t0, $t0 + $budgetMs + 400]);

        $caught = null;
        try {
            $budget->run(function (): void {
                throw new RuntimeException('graph blew up');
            }, $budgetMs, 'failing-walk');
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        // Original exception is rethrown UNCHANGED (meter, not error boundary).
        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertSame('graph blew up', $caught->getMessage());

        // A slow FAILURE is still a recorded breach.
        $this->assertSame(1, $budget->breachCount());
        $breach = $budget->breaches()[0];
        $this->assertSame('failing-walk', $breach['label']);
        $this->assertSame(500.0, $breach['elapsed_ms']);
        $this->assertSame(100, $breach['budget_ms']);
        $this->assertSame(400.0, $breach['over_by_ms']);
    }

    public function test_fast_op_that_throws_records_no_breach_but_still_rethrows(): void
    {
        // Throws within budget => rethrow, but NO breach (within budget).
        $budget = $this->withClock([0, 10]);

        $caught = false;
        try {
            $budget->run(function (): void {
                throw new RuntimeException('fast fail');
            }, 1000, 'fast-fail');
        } catch (RuntimeException) {
            $caught = true;
        }

        $this->assertTrue($caught);
        $this->assertFalse($budget->hasBreaches());
        $this->assertSame(0, $budget->breachCount());
    }

    public function test_non_positive_budget_is_unbounded_and_never_breaches(): void
    {
        // Huge elapsed, but budget 0 => "no interactive bound": within budget, no breach.
        $budget = $this->withClock([0, 999999]);

        $out = $budget->run(fn () => 'x', 0, 'batch');

        $this->assertSame(999999.0, $out['elapsed_ms']);
        $this->assertTrue($out['within_budget']);
        $this->assertSame(0, $out['budget_ms']);
        $this->assertSame(0.0, $out['over_by_ms']);
        $this->assertFalse($budget->hasBreaches());
    }

    public function test_negative_budget_is_clamped_to_zero_and_unbounded(): void
    {
        $budget = $this->withClock([0, 5000]);

        $out = $budget->run(fn () => 'x', -250, 'weird-budget');

        $this->assertSame(0, $out['budget_ms'], 'negative budget clamps to 0');
        $this->assertTrue($out['within_budget']);
        $this->assertFalse($budget->hasBreaches());
    }

    public function test_backwards_clock_yields_zero_elapsed_not_negative(): void
    {
        // Non-monotonic injected clock: end < start. Must clamp to 0.0, never negative.
        $budget = $this->withClock([1000, 400]);

        $out = $budget->run(fn () => 'x', 100, 'ntp-jump');

        $this->assertSame(0.0, $out['elapsed_ms']);
        $this->assertTrue($out['within_budget']);
        $this->assertFalse($budget->hasBreaches());
    }

    public function test_non_finite_clock_reading_degrades_to_zero_elapsed_safely(): void
    {
        // A clock returning NAN must not poison the meter (fail-safe to 0.0 elapsed).
        $budget = $this->withClock([0.0, NAN]);

        $out = $budget->run(fn () => 'x', 50, 'nan-clock');

        $this->assertSame(0.0, $out['elapsed_ms']);
        $this->assertTrue(is_finite($out['elapsed_ms']));
        $this->assertTrue($out['within_budget']);
        $this->assertFalse($budget->hasBreaches());
    }

    public function test_blank_label_falls_back_to_stable_default(): void
    {
        $budget = $this->withClock([0, 9999]);

        $out = $budget->run(fn () => 'x', 1, '   '); // whitespace-only label

        $this->assertSame('code_graph.query', $out['label']);
        // It breached (9999 > 1), and the breach carries the fallback label too.
        $this->assertSame('code_graph.query', $budget->breaches()[0]['label']);
    }

    public function test_multiple_breaches_accumulate_in_order_with_monotonic_sequence(): void
    {
        // Three runs, two of which breach; assert order + sequence stamping.
        $budget = $this->withClock([
            0, 500,    // run 1: 500ms over a 100ms budget => breach (at=1)
            0, 10,     // run 2: within 1000ms budget => no breach
            0, 300,    // run 3: 200ms over a 100ms budget => breach (at=2)
        ]);

        $budget->run(fn () => 'a', 100, 'first');
        $budget->run(fn () => 'b', 1000, 'second');
        $budget->run(fn () => 'c', 100, 'third');

        $breaches = $budget->breaches();
        $this->assertCount(2, $breaches);
        $this->assertSame('first', $breaches[0]['label']);
        $this->assertSame(1, $breaches[0]['at']);
        $this->assertSame('third', $breaches[1]['label']);
        $this->assertSame(2, $breaches[1]['at']);
    }

    public function test_reset_clears_breach_log_and_sequence(): void
    {
        $budget = $this->withClock([0, 500, 0, 500]);

        $budget->run(fn () => 'a', 100, 'one');
        $this->assertSame(1, $budget->breachCount());

        $budget->reset();
        $this->assertFalse($budget->hasBreaches());
        $this->assertSame(0, $budget->breachCount());
        $this->assertSame([], $budget->breaches());

        // After reset the sequence restarts at 1 for the next breach.
        $budget->run(fn () => 'b', 100, 'two');
        $this->assertSame(1, $budget->breaches()[0]['at']);
    }

    public function test_breaches_getter_returns_a_copy_caller_cannot_mutate_internal_log(): void
    {
        $budget = $this->withClock([0, 500]);
        $budget->run(fn () => 'a', 100, 'one');

        $copy = $budget->breaches();
        $copy[0]['label'] = 'mutated';
        $copy[] = ['label' => 'injected', 'elapsed_ms' => 0.0, 'budget_ms' => 0, 'over_by_ms' => 0.0, 'at' => 99];

        // Internal log is unaffected by mutating the returned array.
        $fresh = $budget->breaches();
        $this->assertCount(1, $fresh);
        $this->assertSame('one', $fresh[0]['label']);
    }

    public function test_default_clock_is_real_monotonic_and_measures_a_no_op(): void
    {
        // No injected clock => real hrtime-based clock. A no-op must yield a finite,
        // non-negative elapsed within a generous budget (no sleeping, no flakiness).
        $budget = new CodeGraphLatencyBudget();

        $out = $budget->run(fn () => 42, 60000, 'real-clock');

        $this->assertSame(42, $out['result']);
        $this->assertTrue(is_finite($out['elapsed_ms']));
        $this->assertGreaterThanOrEqual(0.0, $out['elapsed_ms']);
        $this->assertTrue($out['within_budget']);
        $this->assertFalse($budget->hasBreaches());
    }
}
