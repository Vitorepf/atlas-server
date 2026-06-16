<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopFeatureSequencePlanner;
use PHPUnit\Framework\TestCase;

/**
 * ACDE F1 — pins the sequenced-feature planner: human-frozen atoms partitioned into an ordered chain of small
 * steps, order + content preserved (never re-authored). Pure (no container).
 */
final class AtlasLoopFeatureSequencePlannerTest extends TestCase
{
    private function atoms(int $n): array
    {
        $atoms = [];
        for ($i = 1; $i <= $n; $i++) {
            $atoms[] = ['type' => 'method_return', 'method' => 'm'.$i, 'expected' => $i];
        }

        return $atoms;
    }

    public function test_partitions_into_ordered_steps_preserving_atom_order_and_content(): void
    {
        $planner = new AtlasLoopFeatureSequencePlanner;
        $plan = $planner->plan($this->atoms(5), 2);

        // 5 atoms, step size 2 => [2,2,1]
        $this->assertSame([1, 2, 3], array_column($plan, 'step'));
        $this->assertCount(2, $plan[0]['atoms']);
        $this->assertCount(2, $plan[1]['atoms']);
        $this->assertCount(1, $plan[2]['atoms']);
        // order preserved: the human's atom order IS the build order.
        $this->assertSame('m1', $plan[0]['atoms'][0]['method']);
        $this->assertSame('m2', $plan[0]['atoms'][1]['method']);
        $this->assertSame('m5', $plan[2]['atoms'][0]['method']);
        $this->assertSame(3, $planner->stepCount($this->atoms(5), 2));
    }

    public function test_step_atoms_returns_the_frozen_subset_or_empty_when_done(): void
    {
        $planner = new AtlasLoopFeatureSequencePlanner;
        $atoms = $this->atoms(3);

        $this->assertSame(['m1', 'm2'], array_column($planner->stepAtoms($atoms, 1, 2), 'method'));
        $this->assertSame(['m3'], array_column($planner->stepAtoms($atoms, 2, 2), 'method'));
        $this->assertSame([], $planner->stepAtoms($atoms, 3, 2), 'past the last step the chain is done');
    }

    public function test_empty_atoms_yield_empty_plan_and_stable_sequence_id(): void
    {
        $planner = new AtlasLoopFeatureSequencePlanner;
        $this->assertSame([], $planner->plan([], 2));
        $this->assertSame(0, $planner->stepCount([], 2));
        // sequence id is deterministic for the same (target, intent).
        $this->assertSame(
            $planner->sequenceId('app/Foo.php', 'add x'),
            $planner->sequenceId('app/Foo.php', 'add x'),
        );
        $this->assertNotSame(
            $planner->sequenceId('app/Foo.php', 'add x'),
            $planner->sequenceId('app/Foo.php', 'add y'),
        );
    }
}
