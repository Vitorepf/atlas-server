<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphIncrementalPlanner;
use PHPUnit\Framework\TestCase;

class CodeGraphIncrementalPlannerTest extends TestCase
{
    public function test_changed_picks_up_modified_and_new_paths(): void
    {
        $previous = [
            'app/A.php' => 'h1',
            'app/B.php' => 'h2',
            'app/C.php' => 'h3',
        ];
        $current = [
            'app/A.php' => 'h1',        // unchanged
            'app/B.php' => 'h2-NEW',    // modified
            'app/C.php' => 'h3',        // unchanged
            'app/D.php' => 'h4',        // new
        ];

        $plan = (new CodeGraphIncrementalPlanner)->plan($previous, $current);

        $this->assertSame(CodeGraphIncrementalPlanner::SCHEMA, $plan['schema_version']);
        // changed = modified (B) + new (D), sorted by path.
        $this->assertSame(['app/B.php', 'app/D.php'], $plan['changed']);
        $this->assertSame([], $plan['removed']);
        $this->assertSame(2, $plan['unchanged_count']); // A and C
    }

    public function test_removed_picks_up_paths_gone_in_current(): void
    {
        $previous = [
            'app/A.php' => 'h1',
            'app/Gone1.php' => 'g1',
            'app/Gone2.php' => 'g2',
        ];
        $current = [
            'app/A.php' => 'h1',
        ];

        $plan = (new CodeGraphIncrementalPlanner)->plan($previous, $current);

        $this->assertSame([], $plan['changed']);
        $this->assertSame(['app/Gone1.php', 'app/Gone2.php'], $plan['removed']);
        $this->assertSame(1, $plan['unchanged_count']);
    }

    public function test_full_diff_combines_changed_new_removed_unchanged(): void
    {
        $previous = [
            'keep.php' => 'k',
            'edit.php' => 'old',
            'drop.php' => 'd',
        ];
        $current = [
            'keep.php' => 'k',     // unchanged
            'edit.php' => 'new',   // changed
            'add.php' => 'a',      // new
        ];

        $plan = (new CodeGraphIncrementalPlanner)->plan($previous, $current);

        $this->assertSame(['add.php', 'edit.php'], $plan['changed']);
        $this->assertSame(['drop.php'], $plan['removed']);
        $this->assertSame(1, $plan['unchanged_count']);
    }

    public function test_empty_previous_treats_everything_as_new(): void
    {
        $current = [
            'b.php' => 'x',
            'a.php' => 'y',
        ];

        $plan = (new CodeGraphIncrementalPlanner)->plan([], $current);

        $this->assertSame(['a.php', 'b.php'], $plan['changed']); // sorted
        $this->assertSame([], $plan['removed']);
        $this->assertSame(0, $plan['unchanged_count']);
    }

    public function test_empty_current_treats_everything_as_removed(): void
    {
        $previous = [
            'b.php' => 'x',
            'a.php' => 'y',
        ];

        $plan = (new CodeGraphIncrementalPlanner)->plan($previous, []);

        $this->assertSame([], $plan['changed']);
        $this->assertSame(['a.php', 'b.php'], $plan['removed']); // sorted
        $this->assertSame(0, $plan['unchanged_count']);
    }

    public function test_identical_maps_yield_no_work(): void
    {
        $map = [
            'a.php' => 'h1',
            'b.php' => 'h2',
        ];

        $plan = (new CodeGraphIncrementalPlanner)->plan($map, $map);

        $this->assertSame([], $plan['changed']);
        $this->assertSame([], $plan['removed']);
        $this->assertSame(2, $plan['unchanged_count']);
    }

    public function test_output_is_deterministic_regardless_of_input_order(): void
    {
        $previousA = [
            'z.php' => '1',
            'm.php' => '2',
            'a.php' => '3',
            'gone.php' => '9',
        ];
        $currentA = [
            'm.php' => '2-edit',
            'a.php' => '3',
            'z.php' => '1',
            'new.php' => '7',
        ];

        // Same logical content, different key insertion order.
        $previousB = [
            'gone.php' => '9',
            'a.php' => '3',
            'z.php' => '1',
            'm.php' => '2',
        ];
        $currentB = [
            'new.php' => '7',
            'z.php' => '1',
            'a.php' => '3',
            'm.php' => '2-edit',
        ];

        $planner = new CodeGraphIncrementalPlanner;
        $planA = $planner->plan($previousA, $currentA);
        $planB = $planner->plan($previousB, $currentB);

        $this->assertSame($planA, $planB);
        $this->assertSame(['m.php', 'new.php'], $planA['changed']);
        $this->assertSame(['gone.php'], $planA['removed']);
        $this->assertSame(2, $planA['unchanged_count']); // a.php, z.php
    }

    public function test_hash_type_coercion_does_not_falsely_flag_changes(): void
    {
        // Previous stored ints, current scan stored equivalent strings.
        $previous = ['a.php' => 1, 'b.php' => 2];
        $current = ['a.php' => '1', 'b.php' => '2'];

        $plan = (new CodeGraphIncrementalPlanner)->plan($previous, $current);

        $this->assertSame([], $plan['changed']);
        $this->assertSame([], $plan['removed']);
        $this->assertSame(2, $plan['unchanged_count']);
    }

    public function test_blank_and_nonstring_paths_are_ignored(): void
    {
        $previous = [
            'real.php' => 'h',
            '   ' => 'blank-key',
        ];
        $current = [
            'real.php' => 'h',
            '' => 'empty-key',
        ];

        $plan = (new CodeGraphIncrementalPlanner)->plan($previous, $current);

        $this->assertSame([], $plan['changed']);
        $this->assertSame([], $plan['removed']);
        $this->assertSame(1, $plan['unchanged_count']);
    }
}
