<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCriterionStabilitySelector;
use PHPUnit\Framework\TestCase;

/**
 * ACDE V3 — the criterion-stability selector keeps the fan-out candidate that satisfies the MOST sub-criteria,
 * ties broken by the smaller change then lexicographic id. Pure + deterministic => hang-free.
 */
final class AtlasLoopCriterionStabilitySelectorTest extends TestCase
{
    private function sel(): AtlasLoopCriterionStabilitySelector
    {
        return new AtlasLoopCriterionStabilitySelector;
    }

    public function test_picks_the_candidate_passing_the_most_criteria(): void
    {
        $best = $this->sel()->select([
            ['id' => 'a', 'criteria_passed' => 2, 'criteria_total' => 4],
            ['id' => 'b', 'criteria_passed' => 4, 'criteria_total' => 4],
            ['id' => 'c', 'criteria_passed' => 1, 'criteria_total' => 4],
        ]);

        $this->assertSame('b', $best['id']);
    }

    public function test_counts_a_passed_list(): void
    {
        $best = $this->sel()->select([
            ['id' => 'a', 'criteria_passed' => ['x', 'y', 'z']],
            ['id' => 'b', 'criteria_passed' => ['x']],
        ]);

        $this->assertSame('a', $best['id']);
    }

    public function test_tie_on_criteria_prefers_smaller_change(): void
    {
        $best = $this->sel()->select([
            ['id' => 'a', 'criteria_passed' => 3, 'change_size' => 120],
            ['id' => 'b', 'criteria_passed' => 3, 'change_size' => 20],
        ]);

        $this->assertSame('b', $best['id'], 'equal criteria => the smaller, lower-risk change wins');
    }

    public function test_full_tie_breaks_lexicographically_by_id(): void
    {
        $best = $this->sel()->select([
            ['id' => 'zeta', 'criteria_passed' => 2, 'change_size' => 10],
            ['id' => 'alpha', 'criteria_passed' => 2, 'change_size' => 10],
        ]);

        $this->assertSame('alpha', $best['id']);
    }

    public function test_empty_or_idless_candidates_yield_null(): void
    {
        $this->assertNull($this->sel()->select([]));
        $this->assertNull($this->sel()->select([['criteria_passed' => 5], ['id' => '  ']]));
    }
}
