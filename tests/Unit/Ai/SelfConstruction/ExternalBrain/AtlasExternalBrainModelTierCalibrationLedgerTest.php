<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainModelTierCalibrationLedger;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainModelTierCalibrationLedgerTest extends TestCase
{
    private AtlasExternalBrainModelTierCalibrationLedger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new AtlasExternalBrainModelTierCalibrationLedger;
    }

    private function makeRun(array $overrides = []): array
    {
        return array_merge([
            'model_tier'           => AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL,
            'scaffold_variant'     => 'default',
            'critique_depth'       => 'none',
            'task_class'           => 'extraction',
            'outcome'              => AtlasExternalBrainModelTierCalibrationLedger::OUTCOME_SUCCESS,
            'value_proof_strength' => 0.8,
        ], $overrides);
    }

    private function nRuns(int $n, array $overrides = []): array
    {
        return array_fill(0, $n, $this->makeRun($overrides));
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->ledger->calibrate(['runs' => [$this->makeRun()]]);

        foreach (['schema', 'tier_stats', 'routing_recommendations', 'under_sampled_segments', 'evidence_thresholds'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainModelTierCalibrationLedger::SCHEMA, $result['schema']);
    }

    // ── AC2: tier_stats aggregation ───────────────────────────────────────────

    public function test_tier_stats_aggregates_success_rate_correctly(): void
    {
        $runs = [
            $this->makeRun(['outcome' => 'success']),
            $this->makeRun(['outcome' => 'success']),
            $this->makeRun(['outcome' => 'give_back']),
            $this->makeRun(['outcome' => 'low_value']),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);
        $stats  = $result['tier_stats'][AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL];

        $this->assertSame(4, $stats['sample_count']);
        $this->assertEqualsWithDelta(0.5, $stats['success_rate'], 0.001);
        $this->assertEqualsWithDelta(0.25, $stats['give_back_rate'], 0.001);
    }

    public function test_tier_stats_includes_avg_value_proof_strength(): void
    {
        $runs = [
            $this->makeRun(['value_proof_strength' => 0.6]),
            $this->makeRun(['value_proof_strength' => 1.0]),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);
        $stats  = $result['tier_stats'][AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL];

        $this->assertEqualsWithDelta(0.8, $stats['avg_value_proof_strength'], 0.001);
    }

    public function test_multiple_tiers_are_tracked_separately(): void
    {
        $runs = [
            $this->makeRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL,      'outcome' => 'success']),
            $this->makeRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_FRONTIER,   'outcome' => 'give_back']),
            $this->makeRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SCAFFOLDED, 'outcome' => 'success']),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertArrayHasKey(AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL,      $result['tier_stats']);
        $this->assertArrayHasKey(AtlasExternalBrainModelTierCalibrationLedger::TIER_FRONTIER,   $result['tier_stats']);
        $this->assertArrayHasKey(AtlasExternalBrainModelTierCalibrationLedger::TIER_SCAFFOLDED, $result['tier_stats']);
    }

    // ── AC3: under-sampled segments ───────────────────────────────────────────

    public function test_segment_below_min_samples_is_under_sampled(): void
    {
        $runs = $this->nRuns(4); // < MIN_SAMPLES (5)

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertNotEmpty($result['under_sampled_segments']);
        $this->assertSame('inconclusive', $result['under_sampled_segments'][0]['verdict']);
    }

    public function test_segment_at_min_samples_is_not_under_sampled(): void
    {
        $runs = $this->nRuns(AtlasExternalBrainModelTierCalibrationLedger::MIN_SAMPLES_FOR_RECOMMENDATION);

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertSame([], $result['under_sampled_segments']);
    }

    public function test_under_sampled_segment_shows_sample_count(): void
    {
        $runs   = $this->nRuns(2);
        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertSame(2, $result['under_sampled_segments'][0]['sample_count']);
    }

    // ── AC3: routing recommendations ─────────────────────────────────────────

    public function test_high_give_back_rate_triggers_retire_recommendation(): void
    {
        $runs = [
            ...$this->nRuns(1, ['outcome' => 'success']),
            ...$this->nRuns(4, ['outcome' => 'give_back']),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertNotEmpty($result['routing_recommendations']);
        $this->assertStringContainsString('retire', $result['routing_recommendations'][0]['action']);
    }

    public function test_low_success_rate_triggers_downgrade_recommendation(): void
    {
        // 1/5 success rate = 0.20 < FLOOR (0.30)
        $runs = [
            ...$this->nRuns(1, ['outcome' => 'success']),
            ...$this->nRuns(4, ['outcome' => 'low_value']),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertNotEmpty($result['routing_recommendations']);
        $this->assertStringContainsString('downgrade', $result['routing_recommendations'][0]['action']);
    }

    public function test_high_success_rate_triggers_promote_recommendation(): void
    {
        // 5/5 success rate = 1.0 >= CEILING (0.80)
        $runs = $this->nRuns(5, ['outcome' => 'success']);

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertNotEmpty($result['routing_recommendations']);
        $this->assertStringContainsString('promote', $result['routing_recommendations'][0]['action']);
    }

    public function test_adequate_segment_emits_no_recommendation(): void
    {
        // 3/5 = 0.60 — between floor and ceiling, no danger give_back rate
        $runs = [
            ...$this->nRuns(3, ['outcome' => 'success']),
            ...$this->nRuns(1, ['outcome' => 'give_back']),
            ...$this->nRuns(1, ['outcome' => 'low_value']),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertSame([], $result['routing_recommendations']);
    }

    // ── retry cost ────────────────────────────────────────────────────────────

    public function test_tier_stats_includes_avg_retry_count(): void
    {
        $runs = [
            $this->makeRun(['retry_count' => 2]),
            $this->makeRun(['retry_count' => 4]),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);
        $stats  = $result['tier_stats'][AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL];

        $this->assertEqualsWithDelta(3.0, $stats['avg_retry_count'], 0.001);
    }

    // ── confidence scales with sample size ───────────────────────────────────

    public function test_confidence_is_low_for_thin_sample_and_high_for_deep_sample(): void
    {
        $thin = $this->ledger->calibrate(['runs' => $this->nRuns(2)]);
        $deep = $this->ledger->calibrate(['runs' => $this->nRuns(20)]);

        $thinConfidence = $thin['tier_stats'][AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL]['confidence'];
        $deepConfidence = $deep['tier_stats'][AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL]['confidence'];

        $this->assertLessThan($deepConfidence, $thinConfidence);
        $this->assertSame(1.0, $deepConfidence);
    }

    public function test_under_sampled_segment_carries_low_confidence(): void
    {
        $result = $this->ledger->calibrate(['runs' => $this->nRuns(2)]);

        $this->assertArrayHasKey('confidence', $result['under_sampled_segments'][0]);
        $this->assertLessThan(0.5, $result['under_sampled_segments'][0]['confidence']);
    }

    public function test_routing_recommendation_carries_confidence_and_sample_size(): void
    {
        $result = $this->ledger->calibrate(['runs' => $this->nRuns(5, ['outcome' => 'success'])]);

        $rec = $result['routing_recommendations'][0];
        $this->assertArrayHasKey('confidence', $rec);
        $this->assertArrayHasKey('sample_size', $rec);
        $this->assertSame(5, $rec['sample_size']);
    }

    // ── frontier overfit: label alone never justifies promotion ─────────────

    public function test_frontier_tier_with_poor_outcomes_triggers_downgrade_not_promotion(): void
    {
        // "frontier" tier, but real outcomes are bad — the label must not save it.
        $runs = [
            ...$this->nRuns(1, ['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_FRONTIER, 'outcome' => 'success']),
            ...$this->nRuns(4, ['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_FRONTIER, 'outcome' => 'low_value']),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertNotEmpty($result['routing_recommendations']);
        $rec = $result['routing_recommendations'][0];
        $this->assertSame(AtlasExternalBrainModelTierCalibrationLedger::TIER_FRONTIER, $rec['model_tier']);
        $this->assertStringContainsString('downgrade', $rec['action']);
    }

    // ── AC4: evidence_thresholds ──────────────────────────────────────────────

    public function test_evidence_thresholds_key_is_present(): void
    {
        $result = $this->ledger->calibrate(['runs' => []]);

        $this->assertArrayHasKey('min_samples_for_recommendation', $result['evidence_thresholds']);
        $this->assertSame(
            AtlasExternalBrainModelTierCalibrationLedger::MIN_SAMPLES_FOR_RECOMMENDATION,
            $result['evidence_thresholds']['min_samples_for_recommendation'],
        );
    }

    // ── AC2: scaffold_variant and critique_depth as segment dimensions ────────

    public function test_different_scaffold_variants_are_tracked_as_distinct_segments(): void
    {
        $runs = [
            ...$this->nRuns(4, ['scaffold_variant' => 'chain-of-thought']),
            ...$this->nRuns(4, ['scaffold_variant' => 'plain']),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);

        // Both segments are under-sampled (4 each < 5)
        $this->assertCount(2, $result['under_sampled_segments']);
    }

    public function test_different_critique_depths_are_distinct_segments(): void
    {
        $runs = [
            ...$this->nRuns(4, ['critique_depth' => 'deep']),
            ...$this->nRuns(4, ['critique_depth' => 'none']),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertCount(2, $result['under_sampled_segments']);
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function test_empty_runs_returns_empty_stats_and_no_recommendations(): void
    {
        $result = $this->ledger->calibrate(['runs' => []]);

        $this->assertSame([], $result['tier_stats']);
        $this->assertSame([], $result['routing_recommendations']);
        $this->assertSame([], $result['under_sampled_segments']);
    }

    // ── Task family tier classification ───────────────────────────────────────

    private function verifiedRun(array $overrides = []): array
    {
        return $this->makeRun(array_merge(['verified' => true], $overrides));
    }

    public function test_strong_verified_small_model_evidence_classifies_small_model_ok(): void
    {
        $runs = array_fill(0, 5, $this->verifiedRun([
            'model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL,
            'outcome' => 'success',
        ]));

        $result = $this->ledger->calibrate(['runs' => $runs]);
        $classification = $result['task_class_classifications'][0];

        $this->assertSame('extraction', $classification['task_class']);
        $this->assertSame(AtlasExternalBrainModelTierCalibrationLedger::CLASS_SMALL_MODEL_OK, $classification['classification']);
    }

    public function test_weak_small_but_strong_scaffolded_classifies_scaffold_required(): void
    {
        $runs = [
            ...array_fill(0, 5, $this->verifiedRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL, 'outcome' => 'give_back'])),
            ...array_fill(0, 5, $this->verifiedRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SCAFFOLDED, 'outcome' => 'success'])),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);
        $classification = $result['task_class_classifications'][0];

        $this->assertSame(AtlasExternalBrainModelTierCalibrationLedger::CLASS_SCAFFOLD_REQUIRED, $classification['classification']);
    }

    public function test_only_frontier_succeeds_classifies_frontier_required(): void
    {
        $runs = [
            ...array_fill(0, 3, $this->verifiedRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL, 'outcome' => 'success'])),
            ...array_fill(0, 2, $this->verifiedRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL, 'outcome' => 'give_back'])),
            ...array_fill(0, 4, $this->verifiedRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_FRONTIER, 'outcome' => 'success'])),
            ...array_fill(0, 1, $this->verifiedRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_FRONTIER, 'outcome' => 'give_back'])),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);
        $classification = $result['task_class_classifications'][0];

        $this->assertSame(AtlasExternalBrainModelTierCalibrationLedger::CLASS_FRONTIER_REQUIRED, $classification['classification']);
    }

    public function test_frontier_far_exceeding_small_classifies_frontier_high_lift(): void
    {
        $runs = [
            ...array_fill(0, 5, $this->verifiedRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL, 'outcome' => 'low_value'])),
            ...array_fill(0, 5, $this->verifiedRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_FRONTIER, 'outcome' => 'success'])),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);
        $classification = $result['task_class_classifications'][0];

        $this->assertSame(AtlasExternalBrainModelTierCalibrationLedger::CLASS_FRONTIER_HIGH_LIFT, $classification['classification']);
    }

    public function test_single_unverified_sample_does_not_overclaim_small_model_ok(): void
    {
        $runs = [$this->makeRun(['outcome' => 'success', 'verified' => false])];

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertSame([], $result['task_class_classifications']);
    }

    public function test_self_reported_unverified_runs_never_count_toward_classification(): void
    {
        $runs = array_fill(0, 10, $this->makeRun(['outcome' => 'success', 'verified' => false]));

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertSame([], $result['task_class_classifications']);
    }

    public function test_below_threshold_verified_samples_is_insufficient_evidence(): void
    {
        $runs = array_fill(0, 3, $this->verifiedRun(['outcome' => 'success']));

        $result = $this->ledger->calibrate(['runs' => $runs]);
        $classification = $result['task_class_classifications'][0];

        $this->assertSame(AtlasExternalBrainModelTierCalibrationLedger::CLASS_INSUFFICIENT_EVIDENCE, $classification['classification']);
    }

    // ── New: poison_rate, mean_impact_score ──────────────────────────────────

    public function test_tier_stats_includes_poison_rate_and_mean_impact_score(): void
    {
        $runs = [
            $this->makeRun(['outcome' => 'success', 'impact_score' => 8.0]),
            $this->makeRun(['outcome' => 'poison', 'impact_score' => 2.0]),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);
        $stats  = $result['tier_stats'][AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL];

        $this->assertArrayHasKey('poison_rate', $stats);
        $this->assertArrayHasKey('mean_impact_score', $stats);
        $this->assertEqualsWithDelta(0.5, $stats['poison_rate'], 0.001);
        $this->assertEqualsWithDelta(5.0, $stats['mean_impact_score'], 0.001);
    }

    // ── New: scaffold_recommendations ────────────────────────────────────────

    public function test_low_small_model_success_emits_scaffold_recommendation(): void
    {
        // Small model with success_rate < 0.80 ceiling and >= 5 samples.
        $runs = array_fill(0, 5, $this->makeRun([
            'model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL,
            'outcome' => 'low_value',
        ]));

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertNotEmpty($result['scaffold_recommendations']);
        $this->assertSame('add_scaffold', $result['scaffold_recommendations'][0]['recommendation']);
    }

    public function test_high_small_model_success_does_not_emit_scaffold_recommendation(): void
    {
        $runs = array_fill(0, 5, $this->makeRun([
            'model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL,
            'outcome' => 'success',
        ]));

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertSame([], $result['scaffold_recommendations']);
    }

    // ── New: escalation_needed ───────────────────────────────────────────────

    public function test_escalation_needed_when_frontier_required_classification(): void
    {
        // Small model inadequate (< 0.80 success), scaffolded inadequate,
        // frontier adequate (>= 0.80) with a small delta (< 0.30 over lower tiers),
        // so it's frontier_required, not frontier_high_lift.
        $runs = [
            // Small: 3 success / 2 failed = 0.60 success — inadequate
            ...array_fill(0, 3, $this->verifiedRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL, 'outcome' => 'success'])),
            ...array_fill(0, 2, $this->verifiedRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL, 'outcome' => 'give_back'])),
            // Frontier: 4 success / 1 give_back = 0.80 success — adequate
            ...array_fill(0, 4, $this->verifiedRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_FRONTIER, 'outcome' => 'success'])),
            ...array_fill(0, 1, $this->verifiedRun(['model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_FRONTIER, 'outcome' => 'give_back'])),
        ];

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertTrue($result['escalation_needed']);
    }

    public function test_escalation_not_needed_when_small_model_ok(): void
    {
        $runs = array_fill(0, 5, $this->verifiedRun([
            'model_tier' => AtlasExternalBrainModelTierCalibrationLedger::TIER_SMALL,
            'outcome' => 'success',
        ]));

        $result = $this->ledger->calibrate(['runs' => $runs]);

        $this->assertFalse($result['escalation_needed']);
    }
}
