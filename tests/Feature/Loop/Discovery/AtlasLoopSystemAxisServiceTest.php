<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExpectedValueDecider;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSystemAxisService;
use ReflectionClass;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 4 · Slice 6 — the system-axis vector is a MOVING objective, re-resolved each cycle.
 */
final class AtlasLoopSystemAxisServiceTest extends TestCase
{
    public function test_binding_axis_is_the_largest_WEIGHTED_gap_not_the_lowest_raw_value(): void
    {
        $svc = new AtlasLoopSystemAxisService();

        // safety has the lowest raw value (0.2) but the SMALLEST weight (0.10 ⇒ gap 0.08); non_trivial
        // (0.2 raw, weight 0.15 ⇒ gap 0.12) is the binding bottleneck. The weighted gap, not the raw
        // deficiency, must decide — that is the whole point of the moving objective.
        $v = $svc->fromGrade(['axes' => [
            'wired' => 0.9,         // gap 0.35*0.1 = 0.035
            'real_target' => 0.5,   // gap 0.20*0.5 = 0.10
            'non_trivial' => 0.2,   // gap 0.15*0.8 = 0.12  ← largest
            'compounding' => 0.9,   // gap 0.20*0.1 = 0.02
            'safety' => 0.2,        // gap 0.10*0.8 = 0.08
        ], 'graded_merges' => 7]);

        $this->assertSame('non_trivial', $v['binding_axis']);
        $this->assertEqualsWithDelta(0.12, $v['weighted_gap'], 1e-9);
        $this->assertSame(7, $v['graded_merges']);
        // axis_values are passed through clamped to [0,1] for the decider's context.
        $this->assertSame(0.2, $v['axis_values']['non_trivial']);
    }

    public function test_vector_is_RE_RESOLVED_every_call_and_the_objective_MOVES(): void
    {
        // A counting grader returns a DIFFERENT axis profile each call: first the WIRED axis binds,
        // then (as if wired was relieved) COMPOUNDING binds. A stored/cached field could not do this.
        $calls = 0;
        $grader = function (string $repoRoot, ?int $window) use (&$calls): array {
            $calls++;

            return ['graded_merges' => 5, 'axes' => $calls === 1
                ? ['wired' => 0.2, 'real_target' => 1.0, 'non_trivial' => 1.0, 'compounding' => 1.0, 'safety' => 1.0]
                : ['wired' => 1.0, 'real_target' => 1.0, 'non_trivial' => 1.0, 'compounding' => 0.1, 'safety' => 1.0]];
        };
        $svc = new AtlasLoopSystemAxisService($grader);

        $first = $svc->vector('/tmp/whatever');
        $second = $svc->vector('/tmp/whatever');

        $this->assertSame('wired', $first['binding_axis'], 'cycle 1: wired is the binding bottleneck');
        $this->assertSame('compounding', $second['binding_axis'], 'cycle 2: relief pivoted the objective to compounding');
        $this->assertSame(2, $calls, 'grade() is re-resolved on EVERY vector() call — never a memoized field');
    }

    public function test_a_fully_satisfied_grade_has_zero_weighted_gap_deterministically(): void
    {
        $svc = new AtlasLoopSystemAxisService();
        $v = $svc->fromGrade(['axes' => [
            'wired' => 1.0, 'real_target' => 1.0, 'non_trivial' => 1.0, 'compounding' => 1.0, 'safety' => 1.0,
        ]]);

        $this->assertSame(0.0, $v['weighted_gap']);
        // deterministic tie-break (no flapping when every gap is equal)
        $this->assertSame('compounding', $v['binding_axis']);
    }

    public function test_default_path_resolves_a_wellformed_vector_from_the_real_grade(): void
    {
        // No grader override ⇒ the DEFAULT path runs the real (frozen) AtlasLoopUtilityGradeService::grade()
        // against the repo. With no merged proposals it yields a well-formed all-deficient vector — proving
        // the production wiring resolves and shapes correctly (not just the stubbed mechanism).
        $v = (new AtlasLoopSystemAxisService())->vector(base_path());

        $this->assertSame(AtlasLoopSystemAxisService::SCHEMA_VERSION, $v['schema_version']);
        $this->assertSame(['wired', 'real_target', 'non_trivial', 'compounding', 'safety'], array_keys($v['axis_values']));
        $this->assertContains($v['binding_axis'], array_keys(AtlasLoopSystemAxisService::AXIS_WEIGHTS));
        $this->assertIsFloat($v['weighted_gap']);
    }

    public function test_axis_weights_do_not_drift_from_the_decider(): void
    {
        // The decider RANKS candidates with its own AXIS_WEIGHTS; if this service computed the bottleneck
        // off different weights the whole EV math would mis-rank. Pin them to the same source of truth.
        $deciderWeights = (new ReflectionClass(AtlasLoopExpectedValueDecider::class))->getConstant('AXIS_WEIGHTS');

        $this->assertSame(AtlasLoopSystemAxisService::AXIS_WEIGHTS, $deciderWeights);
        // And to the AtlasLoopUtilityGradeService U formula literals (0.35/0.20/0.15/0.20/0.10).
        $this->assertSame([
            'wired' => 0.35, 'real_target' => 0.20, 'non_trivial' => 0.15, 'compounding' => 0.20, 'safety' => 0.10,
        ], AtlasLoopSystemAxisService::AXIS_WEIGHTS);
    }
}
