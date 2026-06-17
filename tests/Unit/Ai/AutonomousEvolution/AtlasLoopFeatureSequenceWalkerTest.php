<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopFeatureSequenceWalker;
use PHPUnit\Framework\TestCase;

/**
 * ACDE F4 — the executable walk of the F1 feature-sequence plan. Each step's ACTIVE atoms are the new
 * sub-acceptance; every PRIOR step's atoms are carried as REGRESSION; the last step's cumulative atoms ARE
 * the full feature. Pure — only ever GROUPS the human-frozen atoms (never re-authors a bar).
 */
final class AtlasLoopFeatureSequenceWalkerTest extends TestCase
{
    private function atoms(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['id' => 'a'.$i, 'description' => 'atom '.$i];
        }

        return $out;
    }

    public function test_walk_holds_prior_steps_as_regression_and_accumulates_to_the_full_feature(): void
    {
        $atoms = $this->atoms(5);
        $steps = (new AtlasLoopFeatureSequenceWalker)->steps($atoms, 2);

        $this->assertCount(3, $steps, '5 atoms / 2 per step => 3 steps');

        // Step 1: active a0,a1; no regression yet.
        $this->assertSame(['a0', 'a1'], array_column($steps[0]['active_atoms'], 'id'));
        $this->assertSame([], $steps[0]['regression_atoms']);
        $this->assertSame(['a0', 'a1'], array_column($steps[0]['cumulative_atoms'], 'id'));
        $this->assertFalse($steps[0]['is_last']);

        // Step 2: active a2,a3; step 1 held as regression.
        $this->assertSame(['a2', 'a3'], array_column($steps[1]['active_atoms'], 'id'));
        $this->assertSame(['a0', 'a1'], array_column($steps[1]['regression_atoms'], 'id'));
        $this->assertSame(['a0', 'a1', 'a2', 'a3'], array_column($steps[1]['cumulative_atoms'], 'id'));

        // Step 3 (last): active a4; steps 1-2 held; cumulative == the WHOLE feature.
        $this->assertSame(['a4'], array_column($steps[2]['active_atoms'], 'id'));
        $this->assertSame(['a0', 'a1', 'a2', 'a3'], array_column($steps[2]['regression_atoms'], 'id'));
        $this->assertSame(['a0', 'a1', 'a2', 'a3', 'a4'], array_column($steps[2]['cumulative_atoms'], 'id'));
        $this->assertTrue($steps[2]['is_last'], 'the last step composes the full feature');
        $this->assertSame(array_column($atoms, 'id'), array_column($steps[2]['cumulative_atoms'], 'id'));
    }

    public function test_a_single_step_feature_has_no_regression_and_is_last(): void
    {
        $steps = (new AtlasLoopFeatureSequenceWalker)->steps($this->atoms(2), 2);
        $this->assertCount(1, $steps);
        $this->assertSame([], $steps[0]['regression_atoms']);
        $this->assertTrue($steps[0]['is_last']);
        $this->assertSame(['a0', 'a1'], array_column($steps[0]['cumulative_atoms'], 'id'));
    }

    public function test_empty_atoms_walk_to_no_steps(): void
    {
        $this->assertSame([], (new AtlasLoopFeatureSequenceWalker)->steps([], 2));
    }

    public function test_step_size_one_makes_every_atom_its_own_step(): void
    {
        $steps = (new AtlasLoopFeatureSequenceWalker)->steps($this->atoms(3), 1);
        $this->assertCount(3, $steps);
        $this->assertSame(['a0'], array_column($steps[0]['active_atoms'], 'id'));
        $this->assertSame(['a0', 'a1'], array_column($steps[2]['regression_atoms'], 'id'));
        $this->assertTrue($steps[2]['is_last']);
    }
}
