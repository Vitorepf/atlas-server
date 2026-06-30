<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierLiftBenchmarkHarness;
use Tests\TestCase;

final class AtlasExternalBrainFrontierLiftBenchmarkHarnessTest extends TestCase
{
    private function svc(): AtlasExternalBrainFrontierLiftBenchmarkHarness
    {
        return new AtlasExternalBrainFrontierLiftBenchmarkHarness;
    }

    private function tierScores(int $impl, int $nonDup, int $leverage, int $evidence, int $antiGood): array
    {
        return [
            'implementability' => $impl,
            'non_duplication' => $nonDup,
            'structural_leverage' => $leverage,
            'evidence_strength' => $evidence,
            'anti_goodhart_resistance' => $antiGood,
        ];
    }

    private function challenge(array $small, array $scaffolded, array $frontier): array
    {
        return [
            'small_model' => $small,
            'scaffolded_small' => $scaffolded,
            'frontier' => $frontier,
        ];
    }

    private function measure(array $challenges): array
    {
        return $this->svc()->measure(['benchmark_challenges' => $challenges]);
    }

    // ── scoring ───────────────────────────────────────────────────────────────

    public function test_tier_scores_are_averages_of_five_dimensions(): void
    {
        // All scores = 6 → avg = 6.0
        $r = $this->measure([
            $this->challenge(
                $this->tierScores(6, 6, 6, 6, 6),
                $this->tierScores(8, 8, 8, 8, 8),
                $this->tierScores(9, 9, 9, 9, 9),
            ),
        ]);

        $this->assertEqualsWithDelta(6.0, $r['tier_scores']['small_model'], 0.01);
        $this->assertEqualsWithDelta(8.0, $r['tier_scores']['scaffolded_small'], 0.01);
        $this->assertEqualsWithDelta(9.0, $r['tier_scores']['frontier'], 0.01);
    }

    public function test_tier_scores_averaged_across_multiple_challenges(): void
    {
        $r = $this->measure([
            $this->challenge(
                $this->tierScores(4, 4, 4, 4, 4),  // small avg=4
                $this->tierScores(6, 6, 6, 6, 6),
                $this->tierScores(9, 9, 9, 9, 9),
            ),
            $this->challenge(
                $this->tierScores(6, 6, 6, 6, 6),  // small avg=6
                $this->tierScores(8, 8, 8, 8, 8),
                $this->tierScores(10, 10, 10, 10, 10),
            ),
        ]);

        // small avg = (4+6)/2 = 5
        $this->assertEqualsWithDelta(5.0, $r['tier_scores']['small_model'], 0.01);
    }

    // ── lift_summary ──────────────────────────────────────────────────────────

    public function test_lift_from_scaffold_is_scaffolded_minus_small(): void
    {
        $r = $this->measure([
            $this->challenge(
                $this->tierScores(5, 5, 5, 5, 5),   // small=5
                $this->tierScores(8, 8, 8, 8, 8),   // scaffolded=8
                $this->tierScores(9, 9, 9, 9, 9),
            ),
        ]);

        $this->assertEqualsWithDelta(3.0, $r['lift_summary']['lift_from_scaffold'], 0.01);
    }

    public function test_frontier_multiplier_is_frontier_over_small(): void
    {
        $r = $this->measure([
            $this->challenge(
                $this->tierScores(5, 5, 5, 5, 5),   // small=5
                $this->tierScores(7, 7, 7, 7, 7),
                $this->tierScores(10, 10, 10, 10, 10), // frontier=10
            ),
        ]);

        // 10/5 = 2.0
        $this->assertEqualsWithDelta(2.0, $r['lift_summary']['frontier_multiplier'], 0.01);
    }

    public function test_frontier_multiplier_is_null_when_small_avg_is_zero(): void
    {
        $r = $this->measure([
            $this->challenge(
                $this->tierScores(0, 0, 0, 0, 0),
                $this->tierScores(5, 5, 5, 5, 5),
                $this->tierScores(9, 9, 9, 9, 9),
            ),
        ]);

        $this->assertNull($r['lift_summary']['frontier_multiplier']);
    }

    // ── failing_dimensions ────────────────────────────────────────────────────

    public function test_failing_dimensions_lists_scaffolded_below_floor(): void
    {
        $r = $this->measure([
            $this->challenge(
                $this->tierScores(3, 8, 8, 8, 8),
                $this->tierScores(5, 8, 8, 8, 8),  // implementability=5 < 7 → fails
                $this->tierScores(9, 9, 9, 9, 9),
            ),
        ]);

        $dims = array_column($r['failing_dimensions'], 'dimension');
        $this->assertContains('implementability', $dims);
        $this->assertNotContains('non_duplication', $dims);
    }

    public function test_all_dimensions_above_floor_gives_empty_failing(): void
    {
        $r = $this->measure([
            $this->challenge(
                $this->tierScores(7, 7, 7, 7, 7),
                $this->tierScores(8, 8, 8, 8, 8),
                $this->tierScores(9, 9, 9, 9, 9),
            ),
        ]);

        $this->assertSame([], $r['failing_dimensions']);
    }

    // ── next_scaffold_improvement ─────────────────────────────────────────────

    public function test_next_scaffold_improvement_is_worst_failing_dimension(): void
    {
        // evidence_strength=3, anti_goodhart=5, both fail — evidence is lower
        $r = $this->measure([
            $this->challenge(
                $this->tierScores(8, 8, 3, 2, 8),
                $this->tierScores(8, 8, 5, 3, 8),
                $this->tierScores(9, 9, 9, 9, 9),
            ),
        ]);

        // structural_leverage scaffolded=5, evidence_strength scaffolded=3 → evidence_strength wins as worst
        $this->assertSame('evidence_strength', $r['next_scaffold_improvement']);
    }

    public function test_next_scaffold_improvement_is_null_when_no_failing_dims(): void
    {
        $r = $this->measure([
            $this->challenge(
                $this->tierScores(8, 8, 8, 8, 8),
                $this->tierScores(9, 9, 9, 9, 9),
                $this->tierScores(10, 10, 10, 10, 10),
            ),
        ]);

        $this->assertNull($r['next_scaffold_improvement']);
    }

    // ── empty + schema ────────────────────────────────────────────────────────

    public function test_empty_challenges_returns_safe_defaults(): void
    {
        $r = $this->svc()->measure([]);

        $this->assertSame([], $r['tier_scores']);
        $this->assertNull($r['next_scaffold_improvement']);
        $this->assertSame([], $r['failing_dimensions']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->measure([]);

        $this->assertSame(AtlasExternalBrainFrontierLiftBenchmarkHarness::SCHEMA, $r['schema_version']);
    }
}
