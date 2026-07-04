<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainFrontierLiftBenchmarkHarness;
use Tests\TestCase;

final class AtlasExternalBrainFrontierLiftBenchmarkHarnessTest extends TestCase
{
    private AtlasExternalBrainFrontierLiftBenchmarkHarness $harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = new AtlasExternalBrainFrontierLiftBenchmarkHarness();
    }

    private function challenge(array $small, array $scaffolded, array $frontier = [], bool $heldOut = true, array $overrides = []): array
    {
        return array_merge([
            'small_model' => $small,
            'scaffolded_small' => $scaffolded,
            'frontier' => $frontier,
            'held_out' => $heldOut,
        ], $overrides);
    }

    private function fullScores(float $v): array
    {
        return [
            'implementability' => $v,
            'non_duplication' => $v,
            'structural_leverage' => $v,
            'evidence_strength' => $v,
            'anti_goodhart_resistance' => $v,
        ];
    }

    // ── Schema and output structure ──────────────────────────────────────────────

    public function test_schema_constant(): void
    {
        $this->assertSame('atlas.external_brain.frontier_lift_benchmark_harness.v1', AtlasExternalBrainFrontierLiftBenchmarkHarness::SCHEMA);
    }

    public function test_output_has_all_canonical_keys(): void
    {
        $result = $this->harness->measure(['benchmark_challenges' => []]);

        $expectedKeys = ['schema_version', 'tier_scores', 'lift_summary', 'failing_dimensions', 'next_scaffold_improvement', 'regression_flags', 'lift_result', 'held_out_sample_count', 'missing_evidence_count'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_schema_version_present(): void
    {
        $result = $this->harness->measure(['benchmark_challenges' => []]);
        $this->assertSame(AtlasExternalBrainFrontierLiftBenchmarkHarness::SCHEMA, $result['schema_version']);
    }

    // ── Empty / missing input ────────────────────────────────────────────────────

    public function test_empty_challenges_returns_inconclusive(): void
    {
        $result = $this->harness->measure(['benchmark_challenges' => []]);

        $this->assertSame(AtlasExternalBrainFrontierLiftBenchmarkHarness::LIFT_RESULT_INCONCLUSIVE, $result['lift_result']);
        $this->assertSame([], $result['tier_scores']);
        $this->assertSame(0, $result['held_out_sample_count']);
        $this->assertSame(0, $result['missing_evidence_count']);
    }

    public function test_missing_benchmark_challenges_key_returns_inconclusive(): void
    {
        $result = $this->harness->measure([]);

        $this->assertSame(AtlasExternalBrainFrontierLiftBenchmarkHarness::LIFT_RESULT_INCONCLUSIVE, $result['lift_result']);
    }

    public function test_challenges_missing_required_tiers_are_excluded_and_counted(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(5), $this->fullScores(6)),
            ['invalid_challenge' => true], // missing small_model and scaffolded_small
            $this->challenge($this->fullScores(4), $this->fullScores(5)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(1, $result['missing_evidence_count']);
        // tier_scores has 3 entries (small_model, scaffolded_small, frontier) — the invalid challenge is excluded from averaging
        $this->assertCount(3, $result['tier_scores']);
    }

    // ── Tier scores computation ──────────────────────────────────────────────────

    public function test_tier_scores_computed_correctly_for_uniform_scores(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(5), $this->fullScores(7), $this->fullScores(9)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(5.0, $result['tier_scores']['small_model']);
        $this->assertSame(7.0, $result['tier_scores']['scaffolded_small']);
        $this->assertSame(9.0, $result['tier_scores']['frontier']);
    }

    public function test_tier_scores_averaged_across_multiple_challenges(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(4), $this->fullScores(6), $this->fullScores(8)),
            $this->challenge($this->fullScores(6), $this->fullScores(8), $this->fullScores(10)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(5.0, $result['tier_scores']['small_model']);
        $this->assertSame(7.0, $result['tier_scores']['scaffolded_small']);
        $this->assertSame(9.0, $result['tier_scores']['frontier']);
    }

    // ── Lift summary ─────────────────────────────────────────────────────────────

    public function test_lift_from_scaffold_positive_when_scaffolded_beats_small(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(5), $this->fullScores(7), $this->fullScores(9)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(2.0, $result['lift_summary']['lift_from_scaffold']);
    }

    public function test_frontier_multiplier_null_when_small_model_avg_is_zero(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(0), $this->fullScores(0), $this->fullScores(5)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertNull($result['lift_summary']['frontier_multiplier']);
    }

    public function test_frontier_multiplier_computed_when_small_model_avg_positive(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(5), $this->fullScores(7), $this->fullScores(10)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(2.0, $result['lift_summary']['frontier_multiplier']);
    }

    // ── Failing dimensions ───────────────────────────────────────────────────────

    public function test_failing_dimensions_when_scaffolded_below_floor(): void
    {
        $challenges = [
            $this->challenge(
                ['implementability' => 5, 'non_duplication' => 5, 'structural_leverage' => 5, 'evidence_strength' => 5, 'anti_goodhart_resistance' => 5],
                ['implementability' => 6, 'non_duplication' => 8, 'structural_leverage' => 6, 'evidence_strength' => 8, 'anti_goodhart_resistance' => 8],
            ),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertCount(2, $result['failing_dimensions']);
        $failingDims = array_column($result['failing_dimensions'], 'dimension');
        $this->assertContains('implementability', $failingDims);
        $this->assertContains('structural_leverage', $failingDims);
    }

    public function test_no_failing_dimensions_when_all_above_floor(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(5), $this->fullScores(8), $this->fullScores(9)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame([], $result['failing_dimensions']);
    }

    public function test_next_scaffold_improvement_is_worst_failing_dimension(): void
    {
        $challenges = [
            $this->challenge(
                ['implementability' => 5, 'non_duplication' => 5, 'structural_leverage' => 5, 'evidence_strength' => 5, 'anti_goodhart_resistance' => 5],
                ['implementability' => 3, 'non_duplication' => 8, 'structural_leverage' => 6, 'evidence_strength' => 8, 'anti_goodhart_resistance' => 8],
            ),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame('implementability', $result['next_scaffold_improvement']);
    }

    public function test_next_scaffold_improvement_null_when_no_failing_dimensions(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(5), $this->fullScores(8), $this->fullScores(9)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertNull($result['next_scaffold_improvement']);
    }

    // ── Regression flags ─────────────────────────────────────────────────────────

    public function test_regression_flags_when_scaffolded_worse_than_small(): void
    {
        $challenges = [
            $this->challenge(
                ['implementability' => 7, 'non_duplication' => 7, 'structural_leverage' => 7, 'evidence_strength' => 7, 'anti_goodhart_resistance' => 7],
                ['implementability' => 5, 'non_duplication' => 8, 'structural_leverage' => 6, 'evidence_strength' => 8, 'anti_goodhart_resistance' => 8],
            ),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertCount(2, $result['regression_flags']);
        $regressionDims = array_column($result['regression_flags'], 'dimension');
        $this->assertContains('implementability', $regressionDims);
        $this->assertContains('structural_leverage', $regressionDims);
    }

    public function test_no_regression_flags_when_scaffolded_never_worse(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(5), $this->fullScores(7), $this->fullScores(9)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame([], $result['regression_flags']);
    }

    public function test_regression_flags_include_delta(): void
    {
        $challenges = [
            $this->challenge(
                ['implementability' => 8, 'non_duplication' => 8, 'structural_leverage' => 8, 'evidence_strength' => 8, 'anti_goodhart_resistance' => 8],
                ['implementability' => 6, 'non_duplication' => 8, 'structural_leverage' => 8, 'evidence_strength' => 8, 'anti_goodhart_resistance' => 8],
            ),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $flag = $result['regression_flags'][0];
        $this->assertSame('implementability', $flag['dimension']);
        $this->assertSame(-2.0, $flag['delta']);
    }

    // ── Lift result ──────────────────────────────────────────────────────────────

    public function test_lift_result_inconclusive_when_fewer_than_min_held_out_samples(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(5), $this->fullScores(7), $this->fullScores(9), true),
            $this->challenge($this->fullScores(5), $this->fullScores(7), $this->fullScores(9), true),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(AtlasExternalBrainFrontierLiftBenchmarkHarness::LIFT_RESULT_INCONCLUSIVE, $result['lift_result']);
    }

    public function test_lift_result_scaffold_sufficient_when_scaffolded_above_floor_with_enough_held_out(): void
    {
        $challenges = [];
        for ($i = 0; $i < 5; $i++) {
            $challenges[] = $this->challenge($this->fullScores(5), $this->fullScores(8), $this->fullScores(9), true);
        }

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(AtlasExternalBrainFrontierLiftBenchmarkHarness::LIFT_RESULT_SCAFFOLD_SUFFICIENT, $result['lift_result']);
    }

    public function test_lift_result_frontier_wins_when_frontier_beats_scaffolded_by_margin(): void
    {
        $challenges = [];
        for ($i = 0; $i < 5; $i++) {
            $challenges[] = $this->challenge($this->fullScores(5), $this->fullScores(6), $this->fullScores(9), true);
        }

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(AtlasExternalBrainFrontierLiftBenchmarkHarness::LIFT_RESULT_FRONTIER_WINS, $result['lift_result']);
    }

    public function test_lift_result_inconclusive_when_frontier_edge_case_no_clear_winner(): void
    {
        $challenges = [];
        for ($i = 0; $i < 5; $i++) {
            $challenges[] = $this->challenge($this->fullScores(5), $this->fullScores(6), $this->fullScores(6.3), true);
        }

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(AtlasExternalBrainFrontierLiftBenchmarkHarness::LIFT_RESULT_INCONCLUSIVE, $result['lift_result']);
    }

    public function test_non_held_out_challenges_do_not_count_toward_lift_result(): void
    {
        $challenges = [];
        for ($i = 0; $i < 10; $i++) {
            $challenges[] = $this->challenge($this->fullScores(5), $this->fullScores(8), $this->fullScores(9), false);
        }

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(0, $result['held_out_sample_count']);
        $this->assertSame(AtlasExternalBrainFrontierLiftBenchmarkHarness::LIFT_RESULT_INCONCLUSIVE, $result['lift_result']);
    }

    // ── Held out sample count ────────────────────────────────────────────────────

    public function test_held_out_sample_count_reflects_only_held_out_challenges(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(5), $this->fullScores(7), $this->fullScores(9), true),
            $this->challenge($this->fullScores(5), $this->fullScores(7), $this->fullScores(9), false),
            $this->challenge($this->fullScores(5), $this->fullScores(7), $this->fullScores(9), true),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(2, $result['held_out_sample_count']);
    }

    // ── Determinism ──────────────────────────────────────────────────────────────

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $input = ['benchmark_challenges' => [
            $this->challenge($this->fullScores(5), $this->fullScores(7), $this->fullScores(9)),
            $this->challenge($this->fullScores(6), $this->fullScores(8), $this->fullScores(10)),
        ]];

        $result1 = $this->harness->measure($input);
        $result2 = $this->harness->measure($input);

        $this->assertSame($result1, $result2);
    }

    // ── Dimensions constant ──────────────────────────────────────────────────────

    public function test_dimensions_constant_has_five_dimensions(): void
    {
        $this->assertCount(5, AtlasExternalBrainFrontierLiftBenchmarkHarness::DIMENSIONS);
        $this->assertContains('implementability', AtlasExternalBrainFrontierLiftBenchmarkHarness::DIMENSIONS);
        $this->assertContains('non_duplication', AtlasExternalBrainFrontierLiftBenchmarkHarness::DIMENSIONS);
        $this->assertContains('structural_leverage', AtlasExternalBrainFrontierLiftBenchmarkHarness::DIMENSIONS);
        $this->assertContains('evidence_strength', AtlasExternalBrainFrontierLiftBenchmarkHarness::DIMENSIONS);
        $this->assertContains('anti_goodhart_resistance', AtlasExternalBrainFrontierLiftBenchmarkHarness::DIMENSIONS);
    }

    // ── Acceptable floor constant ────────────────────────────────────────────────

    public function test_acceptable_floor_is_seven(): void
    {
        $this->assertSame(7.0, AtlasExternalBrainFrontierLiftBenchmarkHarness::ACCEPTABLE_FLOOR);
    }

    // ── Min held out samples constant ────────────────────────────────────────────

    public function test_min_held_out_samples_is_five(): void
    {
        $this->assertSame(5, AtlasExternalBrainFrontierLiftBenchmarkHarness::MIN_HELD_OUT_SAMPLES);
    }

    // ── Lift result constants ────────────────────────────────────────────────────

    public function test_lift_result_constants(): void
    {
        $this->assertSame('frontier_wins', AtlasExternalBrainFrontierLiftBenchmarkHarness::LIFT_RESULT_FRONTIER_WINS);
        $this->assertSame('scaffold_sufficient', AtlasExternalBrainFrontierLiftBenchmarkHarness::LIFT_RESULT_SCAFFOLD_SUFFICIENT);
        $this->assertSame('inconclusive', AtlasExternalBrainFrontierLiftBenchmarkHarness::LIFT_RESULT_INCONCLUSIVE);
    }

    // ── Scaffold gap measurement ─────────────────────────────────────────────────

    public function test_scaffold_gap_positive_when_scaffolded_improves_over_small(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(4), $this->fullScores(7), $this->fullScores(9)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertGreaterThan(0, $result['lift_summary']['lift_from_scaffold']);
    }

    public function test_scaffold_gap_negative_when_scaffolded_worse_than_small(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(7), $this->fullScores(5), $this->fullScores(9)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertLessThan(0, $result['lift_summary']['lift_from_scaffold']);
    }

    public function test_scaffold_gap_zero_when_scores_identical(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(6), $this->fullScores(6), $this->fullScores(9)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(0.0, $result['lift_summary']['lift_from_scaffold']);
    }

    // ── Per-dimension scaffold gap analysis ──────────────────────────────────────

    public function test_failing_dimensions_show_per_dimension_scaffolded_and_small_avg(): void
    {
        $challenges = [
            $this->challenge(
                ['implementability' => 6, 'non_duplication' => 6, 'structural_leverage' => 6, 'evidence_strength' => 6, 'anti_goodhart_resistance' => 6],
                ['implementability' => 5, 'non_duplication' => 8, 'structural_leverage' => 5, 'evidence_strength' => 8, 'anti_goodhart_resistance' => 8],
            ),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $implFlag = array_find($result['failing_dimensions'], fn ($f) => $f['dimension'] === 'implementability');
        $this->assertNotNull($implFlag);
        $this->assertSame(5.0, $implFlag['scaffolded_avg']);
        $this->assertSame(6.0, $implFlag['small_avg']);
    }

    // ── Missing evidence handling ────────────────────────────────────────────────

    public function test_missing_evidence_count_includes_challenges_without_small_model(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(5), $this->fullScores(7)),
            ['scaffolded_small' => $this->fullScores(7)], // missing small_model
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(1, $result['missing_evidence_count']);
    }

    public function test_missing_evidence_count_includes_challenges_without_scaffolded_small(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(5), $this->fullScores(7)),
            ['small_model' => $this->fullScores(5)], // missing scaffolded_small
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(1, $result['missing_evidence_count']);
    }

    // ── Frontier multiplier edge cases ───────────────────────────────────────────

    public function test_frontier_multiplier_one_when_frontier_equals_small(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(7), $this->fullScores(8), $this->fullScores(7)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertSame(1.0, $result['lift_summary']['frontier_multiplier']);
    }

    public function test_frontier_multiplier_greater_than_one_when_frontier_exceeds_small(): void
    {
        $challenges = [
            $this->challenge($this->fullScores(5), $this->fullScores(7), $this->fullScores(10)),
        ];

        $result = $this->harness->measure(['benchmark_challenges' => $challenges]);

        $this->assertGreaterThan(1.0, $result['lift_summary']['frontier_multiplier']);
    }
}
