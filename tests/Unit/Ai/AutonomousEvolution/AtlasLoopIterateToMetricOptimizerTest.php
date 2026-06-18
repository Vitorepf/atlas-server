<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopIterateToMetricOptimizer;
use Tests\TestCase;

/**
 * Freezes the Arbor iterate-to-metric loop: edit -> measure -> keep-if-STRICTLY-better
 * -> repeat, on ONE already-prepared workspace, in-place. The comparison MIRRORS the
 * loop's improvesBest()/isStrictlyBetter() (passing > failing, then metricKind direction).
 *
 * Pure: $edit and $measure are fake closures returning canned attempts/verdicts, so the
 * test needs NO real provider and NO real git.
 */
class AtlasLoopIterateToMetricOptimizerTest extends TestCase
{
    private function optimizer(): AtlasLoopIterateToMetricOptimizer
    {
        return new AtlasLoopIterateToMetricOptimizer;
    }

    /** Build a fake $edit from a list of attempt rows (cost/tokens default 0). */
    private function scriptedEdit(array $attempts): callable
    {
        return function (int $round, ?array $lastVerdict) use ($attempts): array {
            $a = $attempts[$round] ?? ['diff_text' => '', 'diff_size' => 0, 'cost_cents' => 0, 'tokens' => 0];

            return [
                'diff_text' => (string) ($a['diff_text'] ?? "diff-$round"),
                'diff_size' => (int) ($a['diff_size'] ?? 1),
                'cost_cents' => (int) ($a['cost_cents'] ?? 0),
                'tokens' => (int) ($a['tokens'] ?? 0),
            ];
        };
    }

    /** Build a fake $measure that yields the scripted verdicts in order. */
    private function scriptedMeasure(array $verdicts): callable
    {
        $i = 0;

        return function () use (&$i, $verdicts): array {
            $v = $verdicts[$i] ?? $verdicts[count($verdicts) - 1];
            $i++;

            return [
                'passed' => (bool) ($v['passed'] ?? true),
                'metric' => (float) ($v['metric'] ?? 0.0),
                'metric_finite' => (bool) ($v['metric_finite'] ?? true),
            ];
        };
    }

    public function test_maximize_reaches_target_and_stops_early(): void
    {
        $edit = $this->scriptedEdit([[], [], []]);
        // 0.73 -> 0.80 -> 0.84 ; target 0.84 reached at round 2 -> stop, never reach round 3.
        $measure = $this->scriptedMeasure([
            ['metric' => 0.73],
            ['metric' => 0.80],
            ['metric' => 0.84],
            ['metric' => 0.99], // must NOT be consumed
        ]);

        $r = $this->optimizer()->optimize($edit, $measure, 'maximize', 0.84, 10, 3);

        $this->assertTrue($r['target_reached']);
        $this->assertSame(0.84, $r['best_verdict']['metric']);
        $this->assertSame(3, $r['rounds']); // rounds 0,1,2 ran; stopped before round 3
        $this->assertCount(3, $r['history']);
    }

    public function test_minimize_converges_on_patience_with_no_strict_improvement(): void
    {
        $edit = $this->scriptedEdit([[], [], [], []]);
        // 10 -> 8 (improve) -> 8 (no, tie) -> 8 (no, tie) ; patience 2 -> converge after 2 stale.
        $measure = $this->scriptedMeasure([
            ['metric' => 10.0],
            ['metric' => 8.0],
            ['metric' => 8.0],
            ['metric' => 8.0],
        ]);

        $r = $this->optimizer()->optimize($edit, $measure, 'minimize', null, 10, 2);

        $this->assertTrue($r['converged']);
        $this->assertFalse($r['target_reached']);
        $this->assertSame(8.0, $r['best_verdict']['metric']);
        // round0 (best), round1 (improve), round2 (stale#1), round3 (stale#2 -> break)
        $this->assertSame(4, $r['rounds']);
    }

    public function test_never_improves_keeps_round0_best_and_converges(): void
    {
        $edit = $this->scriptedEdit([[], [], []]);
        // maximize: 0.50 then everything worse -> round-0 stays best, patience trips.
        $measure = $this->scriptedMeasure([
            ['metric' => 0.50],
            ['metric' => 0.40],
            ['metric' => 0.30],
        ]);

        $r = $this->optimizer()->optimize($edit, $measure, 'maximize', null, 10, 2);

        $this->assertTrue($r['converged']);
        $this->assertSame(0.50, $r['best_verdict']['metric']);
        $this->assertFalse($r['history'][1]['improved']);
        $this->assertFalse($r['history'][2]['improved']);
    }

    public function test_gate_stops_on_first_pass(): void
    {
        $edit = $this->scriptedEdit([[], [], [], []]);
        // fail, fail, pass -> best.passed true, target reached at the pass.
        $measure = $this->scriptedMeasure([
            ['passed' => false, 'metric' => 0.0],
            ['passed' => false, 'metric' => 0.0],
            ['passed' => true, 'metric' => 1.0],
            ['passed' => true, 'metric' => 1.0], // must NOT be consumed
        ]);

        $r = $this->optimizer()->optimize($edit, $measure, 'gate', null, 10, 5);

        $this->assertTrue($r['best_verdict']['passed']);
        $this->assertTrue($r['target_reached']);
        $this->assertSame(3, $r['rounds']);
    }

    public function test_cost_and_tokens_accumulate_across_rounds(): void
    {
        $edit = $this->scriptedEdit([
            ['cost_cents' => 10, 'tokens' => 100],
            ['cost_cents' => 20, 'tokens' => 200],
            ['cost_cents' => 30, 'tokens' => 300],
        ]);
        // never reaches target, never improves after r0 -> still runs all paid rounds within patience.
        $measure = $this->scriptedMeasure([
            ['metric' => 0.90],
            ['metric' => 0.10],
            ['metric' => 0.05],
        ]);

        $r = $this->optimizer()->optimize($edit, $measure, 'maximize', 0.99, 10, 2);

        $this->assertSame(60, $r['total_cost_cents']);
        $this->assertSame(600, $r['total_tokens']);
    }

    public function test_nonfinite_metric_never_beats_a_finite_one_for_maximize(): void
    {
        $edit = $this->scriptedEdit([[], [], []]);
        // r0 finite 0.50 ; r1 "huge" but non-finite (clamped INF) -> must NOT be picked as best.
        $measure = $this->scriptedMeasure([
            ['metric' => 0.50, 'metric_finite' => true],
            ['metric' => 9999.0, 'metric_finite' => false],
            ['metric' => 0.40, 'metric_finite' => true],
        ]);

        $r = $this->optimizer()->optimize($edit, $measure, 'maximize', null, 10, 2);

        $this->assertSame(0.50, $r['best_verdict']['metric']);
        $this->assertTrue($r['best_verdict']['metric_finite']);
        $this->assertFalse($r['history'][1]['improved']);
    }

    public function test_maxedits_bounds_the_run_when_no_convergence_or_target(): void
    {
        // strictly improving forever, patience never trips, no target -> stop at maxEdits.
        $edit = $this->scriptedEdit([[], [], []]);
        $measure = $this->scriptedMeasure([
            ['metric' => 0.10],
            ['metric' => 0.20],
            ['metric' => 0.30],
        ]);

        $r = $this->optimizer()->optimize($edit, $measure, 'maximize', null, 3, 5);

        $this->assertSame(3, $r['rounds']);
        $this->assertFalse($r['converged']);
        $this->assertFalse($r['target_reached']);
        $this->assertSame(0.30, $r['best_verdict']['metric']);
    }
}
