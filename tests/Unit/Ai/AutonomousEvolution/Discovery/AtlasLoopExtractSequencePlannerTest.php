<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExtractSequencePlanner;
use PHPUnit\Framework\TestCase;

/**
 * ACDE lever #6 — the sequenced single-method extract planner. Pure + deterministic: it turns a per-method
 * cyclomatic census into a worst-first, threshold-bounded decomposition of a god-class. Each step is one
 * tractable method the weak engine can land; re-measuring after each makes the chain self-correcting.
 */
final class AtlasLoopExtractSequencePlannerTest extends TestCase
{
    private function planner(): AtlasLoopExtractSequencePlanner
    {
        return new AtlasLoopExtractSequencePlanner;
    }

    public function test_next_step_targets_the_current_worst_method_above_threshold(): void
    {
        $census = ['a' => 4, 'big' => 19, 'mid' => 12, 'small' => 2];
        $this->assertSame(['target_method' => 'big', 'target_method_bare' => 'big', 'cyclomatic' => 19], $this->planner()->nextStep($census, 10));
    }

    public function test_bare_method_name_strips_the_qualified_identity(): void
    {
        // R1 identity shim: the census keys FQ (Class::method / \function); the objective builder wants bare.
        $this->assertSame('classify', AtlasLoopExtractSequencePlanner::bareMethodName('App\\Services\\Calc::classify'));
        $this->assertSame('handle', AtlasLoopExtractSequencePlanner::bareMethodName('\\handle'));
        $this->assertSame('classify', AtlasLoopExtractSequencePlanner::bareMethodName('classify'));
    }

    public function test_next_step_exposes_the_bare_name_for_the_objective_builder(): void
    {
        // A real FQ census key (as fileComplexity emits): nextStep returns the FQ identity AND the bare name
        // the framework-refactor objective text consumes — so the armed chain pins the same method today's
        // worst_method (bare) would.
        $step = $this->planner()->nextStep(['App\\Calc::small' => 3, 'App\\Calc::big' => 17], 10);
        $this->assertSame('App\\Calc::big', $step['target_method']);
        $this->assertSame('big', $step['target_method_bare']);
    }

    public function test_next_step_is_null_when_every_method_is_tractable(): void
    {
        $this->assertNull($this->planner()->nextStep(['a' => 8, 'b' => 10, 'c' => 3], 10),
            'all methods <= threshold => the sequence is done');
    }

    public function test_next_step_tie_breaks_deterministically_by_name(): void
    {
        // two methods share the worst score; the lexicographically smaller identity wins (reproducible chain)
        $this->assertSame('alpha', $this->planner()->nextStep(['beta' => 15, 'alpha' => 15], 10)['target_method']);
    }

    public function test_plan_projects_worst_first_filters_threshold_and_caps(): void
    {
        $census = ['w' => 20, 'x' => 15, 'y' => 12, 'z_tractable' => 9, 'tiny' => 1];
        $plan = $this->planner()->plan($census, 10, 2);

        $this->assertCount(2, $plan, 'capped at maxSteps');
        $this->assertSame(['step_index' => 0, 'target_method' => 'w', 'cyclomatic' => 20], $plan[0]);
        $this->assertSame(['step_index' => 1, 'target_method' => 'x', 'cyclomatic' => 15], $plan[1]);
        // z_tractable (9 <= 10) and tiny are below threshold => never in the plan.
    }

    public function test_plan_is_empty_when_nothing_exceeds_threshold(): void
    {
        $this->assertSame([], $this->planner()->plan(['a' => 5, 'b' => 10], 10, 6));
    }

    public function test_sequence_id_is_stable_per_target_path(): void
    {
        $p = $this->planner();
        $this->assertSame($p->sequenceId('app/Foo/Bar.php'), $p->sequenceId('app/Foo/Bar.php'));
        $this->assertNotSame($p->sequenceId('app/Foo/Bar.php'), $p->sequenceId('app/Foo/Baz.php'));
        $this->assertStringStartsWith('xseq-', $p->sequenceId('app/Foo/Bar.php'));
    }
}
