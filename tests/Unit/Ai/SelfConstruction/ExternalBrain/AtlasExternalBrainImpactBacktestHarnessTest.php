<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainImpactBacktestHarness;
use Tests\TestCase;

final class AtlasExternalBrainImpactBacktestHarnessTest extends TestCase
{
    private function svc(): AtlasExternalBrainImpactBacktestHarness
    {
        return new AtlasExternalBrainImpactBacktestHarness;
    }

    private function task(
        string $id,
        float $predicted,
        float $actual,
        string $outcome = 'commit_success',
    ): array {
        return [
            'task_id' => $id,
            'predicted_leverage' => $predicted,
            'actual_leverage' => $actual,
            'actual_outcome' => $outcome,
        ];
    }

    private function bt(array $tasks): array
    {
        return $this->svc()->backtest(['scored_tasks' => $tasks]);
    }

    // ── calibration buckets ───────────────────────────────────────────────────

    public function test_calibration_bucket_groups_by_outcome(): void
    {
        $r = $this->bt([
            $this->task('t1', 7.0, 6.5),
            $this->task('t2', 7.0, 2.0, 'give_back'),
        ]);

        $this->assertArrayHasKey('commit_success', $r['calibration_buckets']);
        $this->assertArrayHasKey('give_back', $r['calibration_buckets']);
    }

    public function test_calibration_bucket_computes_avg_predicted_and_actual(): void
    {
        $r = $this->bt([
            $this->task('t1', 6.0, 5.0),
            $this->task('t2', 8.0, 7.0),
        ]);

        $bucket = $r['calibration_buckets']['commit_success'];
        $this->assertSame(7.0, $bucket['avg_predicted_leverage']);
        $this->assertSame(6.0, $bucket['avg_actual_leverage']);
    }

    public function test_well_calibrated_bucket_status(): void
    {
        // delta = 0.5 < CALIBRATION_GOOD_THRESHOLD(1.0)
        $r = $this->bt([$this->task('t1', 5.5, 5.0)]);
        $this->assertSame('well_calibrated', $r['calibration_buckets']['commit_success']['calibration_status']);
    }

    public function test_overclaimed_bucket_status(): void
    {
        // avg delta = 3.0 >= OVERCLAIM_THRESHOLD(2.0)
        $r = $this->bt([$this->task('t1', 9.0, 6.0)]);
        $this->assertSame('overclaimed', $r['calibration_buckets']['commit_success']['calibration_status']);
    }

    // ── overclaim warnings ────────────────────────────────────────────────────

    public function test_overclaim_warning_emitted_when_delta_at_threshold(): void
    {
        // delta = 9.0 - 7.0 = 2.0 = OVERCLAIM_THRESHOLD → warning
        $r = $this->bt([$this->task('t1', 9.0, 7.0)]);
        $this->assertCount(1, $r['overclaim_warnings']);
        $this->assertSame('t1', $r['overclaim_warnings'][0]['task_id']);
    }

    public function test_no_overclaim_warning_below_threshold(): void
    {
        // delta = 1.5 < 2.0
        $r = $this->bt([$this->task('t1', 7.5, 6.0)]);
        $this->assertSame([], $r['overclaim_warnings']);
    }

    public function test_overclaim_warning_includes_delta(): void
    {
        $r = $this->bt([$this->task('t1', 9.0, 6.0)]);
        $this->assertSame(3.0, $r['overclaim_warnings'][0]['overclaim_delta']);
    }

    // ── penalized tasks ───────────────────────────────────────────────────────

    public function test_give_back_with_high_predicted_is_penalized(): void
    {
        $r = $this->bt([$this->task('t1', 7.0, 1.0, 'give_back')]);
        $this->assertCount(1, $r['penalized_tasks']);
        $this->assertSame('t1', $r['penalized_tasks'][0]['task_id']);
    }

    public function test_proxy_implementation_with_high_predicted_is_penalized(): void
    {
        $r = $this->bt([$this->task('t1', 6.0, 1.0, 'proxy_implementation')]);
        $this->assertCount(1, $r['penalized_tasks']);
    }

    public function test_no_capability_delta_with_high_predicted_is_penalized(): void
    {
        $r = $this->bt([$this->task('t1', 8.0, 0.0, 'no_capability_delta')]);
        $this->assertCount(1, $r['penalized_tasks']);
    }

    public function test_give_back_with_low_predicted_not_penalized(): void
    {
        // predicted = 4.0 < HIGH_LEVERAGE_FLOOR(5.0)
        $r = $this->bt([$this->task('t1', 4.0, 0.0, 'give_back')]);
        $this->assertSame([], $r['penalized_tasks']);
    }

    public function test_commit_success_not_penalized(): void
    {
        $r = $this->bt([$this->task('t1', 9.0, 9.0, 'commit_success')]);
        $this->assertSame([], $r['penalized_tasks']);
    }

    // ── scorer adjustment hints ───────────────────────────────────────────────

    public function test_hint_emitted_when_overclaim_rate_exceeds_30_pct(): void
    {
        // 2 out of 3 overclaim = 66%
        $r = $this->bt([
            $this->task('t1', 9.0, 6.0),  // overclaim delta=3.0
            $this->task('t2', 9.0, 6.0),  // overclaim delta=3.0
            $this->task('t3', 5.0, 5.0),  // well calibrated
        ]);

        $hints = array_column($r['scorer_adjustment_hints'], 'hint');
        $this->assertContains('reduce_predicted_leverage', $hints);
    }

    public function test_no_hint_when_overclaim_rate_below_30_pct(): void
    {
        // 1 out of 5 = 20%
        $r = $this->bt([
            $this->task('t1', 9.0, 6.0),
            $this->task('t2', 5.0, 5.0),
            $this->task('t3', 5.0, 5.0),
            $this->task('t4', 5.0, 5.0),
            $this->task('t5', 5.0, 5.0),
        ]);

        $hints = array_column($r['scorer_adjustment_hints'], 'hint');
        $this->assertNotContains('reduce_predicted_leverage', $hints);
    }

    public function test_give_back_penalty_triggers_downweight_hint(): void
    {
        $r = $this->bt([$this->task('t1', 7.0, 0.0, 'give_back')]);
        $hints = array_column($r['scorer_adjustment_hints'], 'hint');
        $this->assertContains('downweight_give_back_prone_predictions', $hints);
    }

    public function test_proxy_implementation_penalty_triggers_hint(): void
    {
        $r = $this->bt([$this->task('t1', 7.0, 0.0, 'proxy_implementation')]);
        $hints = array_column($r['scorer_adjustment_hints'], 'hint');
        $this->assertContains('penalize_proxy_implementation_patterns', $hints);
    }

    // ── calibration score ─────────────────────────────────────────────────────

    public function test_calibration_score_is_fraction_within_threshold(): void
    {
        // 2 well-calibrated (delta<1.0), 1 not
        $r = $this->bt([
            $this->task('t1', 5.0, 5.0),    // delta=0.0 ✓
            $this->task('t2', 5.5, 5.0),    // delta=0.5 ✓
            $this->task('t3', 9.0, 6.0),    // delta=3.0 ✗
        ]);

        $this->assertEqualsWithDelta(0.67, $r['calibration_score'], 0.01);
    }

    // ── empty + schema ────────────────────────────────────────────────────────

    public function test_empty_tasks_returns_null_calibration_score(): void
    {
        $r = $this->svc()->backtest([]);
        $this->assertNull($r['calibration_score']);
        $this->assertSame(0, $r['total_tasks']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->backtest([]);
        $this->assertSame(AtlasExternalBrainImpactBacktestHarness::SCHEMA, $r['schema_version']);
    }

    // ── quarantine penalty ────────────────────────────────────────────────────

    public function test_quarantine_with_high_predicted_is_penalized(): void
    {
        $r = $this->bt([$this->task('q1', 7.0, 1.0, 'quarantine')]);

        $this->assertCount(1, $r['penalized_tasks']);
        $this->assertSame('quarantine', $r['penalized_tasks'][0]['actual_outcome']);
    }

    public function test_quarantine_penalty_triggers_downweight_hint(): void
    {
        $r = $this->bt([$this->task('q1', 7.0, 1.0, 'quarantine')]);

        $hints = array_column($r['scorer_adjustment_hints'], 'hint');
        $this->assertContains('downweight_quarantine_prone_predictions', $hints);
    }

    // ── no downstream unlock penalty ──────────────────────────────────────────

    public function test_high_predicted_with_zero_downstream_unlocks_is_penalized_even_on_success(): void
    {
        $task = $this->task('u1', 7.0, 7.0, 'commit_success');
        $task['downstream_unlocks'] = 0;

        $r = $this->bt([$task]);

        $this->assertCount(1, $r['penalized_tasks']);
        $this->assertSame('no_downstream_unlock_despite_high_predicted_leverage', $r['penalized_tasks'][0]['penalty_reason']);
    }

    public function test_high_predicted_with_positive_downstream_unlocks_is_not_penalized_for_unlocks(): void
    {
        $task = $this->task('u2', 7.0, 7.0, 'commit_success');
        $task['downstream_unlocks'] = 3;

        $r = $this->bt([$task]);

        $this->assertSame([], $r['penalized_tasks']);
    }

    public function test_no_downstream_unlock_penalty_triggers_discount_hint(): void
    {
        $task = $this->task('u3', 7.0, 7.0, 'commit_success');
        $task['downstream_unlocks'] = 0;

        $r = $this->bt([$task]);

        $hints = array_column($r['scorer_adjustment_hints'], 'hint');
        $this->assertContains('discount_leverage_without_downstream_unlocks', $hints);
    }

    public function test_no_capability_delta_penalty_triggers_downweight_hint(): void
    {
        $r = $this->bt([$this->task('nc1', 7.0, 7.0, 'no_capability_delta')]);

        $hints = array_column($r['scorer_adjustment_hints'], 'hint');
        $this->assertContains('downweight_no_capability_delta_predictions', $hints);
    }

    // ── calibration_error ─────────────────────────────────────────────────────

    public function test_calibration_error_is_average_absolute_delta(): void
    {
        $r = $this->bt([
            $this->task('e1', 5.0, 3.0),
            $this->task('e2', 2.0, 4.0),
        ]);

        // |5-3|=2, |2-4|=2 → avg = 2.0
        $this->assertEqualsWithDelta(2.0, $r['calibration_error'], 0.001);
    }

    public function test_calibration_error_null_when_no_tasks(): void
    {
        $r = $this->svc()->backtest([]);
        $this->assertNull($r['calibration_error']);
    }

    // ── family_calibration ────────────────────────────────────────────────────

    private function familyTask(
        string $id,
        float $predicted,
        float $actual,
        string $family,
        string $outcome = 'commit_success',
    ): array {
        return array_merge($this->task($id, $predicted, $actual, $outcome), ['task_family' => $family]);
    }

    public function test_family_calibration_present_with_required_keys(): void
    {
        $r = $this->bt([$this->familyTask('t1', 5.0, 5.0, 'gate-impl')]);

        $fc = $r['family_calibration'][0];
        foreach (['task_family', 'average_predicted', 'average_actual', 'average_error', 'correction_direction', 'sample_count'] as $k) {
            $this->assertArrayHasKey($k, $fc, "Missing key: {$k}");
        }
        $this->assertSame('gate-impl', $fc['task_family']);
    }

    public function test_family_calibration_empty_when_no_tasks(): void
    {
        $r = $this->svc()->backtest([]);
        $this->assertSame([], $r['family_calibration']);
    }

    public function test_family_calibration_groups_by_task_family(): void
    {
        $r = $this->bt([
            $this->familyTask('t1', 5.0, 5.0, 'family-a'),
            $this->familyTask('t2', 5.0, 5.0, 'family-b'),
        ]);

        $families = array_column($r['family_calibration'], 'task_family');
        $this->assertContains('family-a', $families);
        $this->assertContains('family-b', $families);
    }

    public function test_family_correction_direction_down_when_repeated_give_back_despite_high_predicted(): void
    {
        $r = $this->bt([
            $this->familyTask('t1', 8.0, 8.0, 'risky-family', 'give_back'),
            $this->familyTask('t2', 8.0, 8.0, 'risky-family', 'give_back'),
        ]);

        $fc = $r['family_calibration'][0];
        $this->assertSame('down', $fc['correction_direction']);
    }

    public function test_family_correction_direction_down_when_proxy_implementation_present(): void
    {
        $r = $this->bt([$this->familyTask('t1', 8.0, 8.0, 'proxy-family', 'proxy_implementation')]);

        $this->assertSame('down', $r['family_calibration'][0]['correction_direction']);
    }

    public function test_family_correction_direction_down_when_no_capability_delta_present(): void
    {
        $r = $this->bt([$this->familyTask('t1', 8.0, 8.0, 'ncd-family', 'no_capability_delta')]);

        $this->assertSame('down', $r['family_calibration'][0]['correction_direction']);
    }

    public function test_family_correction_direction_down_when_quarantine_present(): void
    {
        $r = $this->bt([$this->familyTask('t1', 8.0, 8.0, 'quarantine-family', 'quarantine')]);

        $this->assertSame('down', $r['family_calibration'][0]['correction_direction']);
    }

    public function test_family_correction_direction_none_when_well_calibrated_and_no_penalty_outcomes(): void
    {
        $r = $this->bt([$this->familyTask('t1', 5.0, 5.0, 'stable-family')]);

        $this->assertSame('none', $r['family_calibration'][0]['correction_direction']);
    }

    public function test_family_correction_direction_up_when_underclaimed(): void
    {
        $r = $this->bt([$this->familyTask('t1', 2.0, 6.0, 'underclaim-family')]);

        $this->assertSame('up', $r['family_calibration'][0]['correction_direction']);
    }

    public function test_family_sample_count_reflects_task_count(): void
    {
        $r = $this->bt([
            $this->familyTask('t1', 5.0, 5.0, 'family-a'),
            $this->familyTask('t2', 5.0, 5.0, 'family-a'),
            $this->familyTask('t3', 5.0, 5.0, 'family-a'),
        ]);

        $this->assertSame(3, $r['family_calibration'][0]['sample_count']);
    }

    // ── AC2: proof_strength / runtime_evidence calibration ──────────────────────

    public function test_proof_strength_calibration_absent_when_no_task_supplies_it(): void
    {
        $r = $this->bt([$this->task('t1', 5.0, 5.0)]);

        $this->assertSame([], $r['proof_strength_calibration']);
        $this->assertSame([], $r['runtime_evidence_calibration']);
    }

    public function test_proof_strength_calibration_buckets_weak_and_strong(): void
    {
        $weak = $this->task('w1', 9.0, 6.0);
        $weak['proof_strength'] = 0.2;
        $strong = $this->task('s1', 5.0, 5.0);
        $strong['proof_strength'] = 0.9;

        $r = $this->bt([$weak, $strong]);

        $this->assertArrayHasKey('weak', $r['proof_strength_calibration']);
        $this->assertArrayHasKey('strong', $r['proof_strength_calibration']);
        $this->assertGreaterThan(
            $r['proof_strength_calibration']['strong']['avg_error'],
            $r['proof_strength_calibration']['weak']['avg_error'],
        );
    }

    public function test_runtime_evidence_calibration_buckets_with_and_without(): void
    {
        $withEvidence = $this->task('e1', 5.0, 5.0);
        $withEvidence['runtime_evidence_present'] = true;
        $withoutEvidence = $this->task('e2', 9.0, 6.0);
        $withoutEvidence['runtime_evidence_present'] = false;

        $r = $this->bt([$withEvidence, $withoutEvidence]);

        $this->assertSame(1, $r['runtime_evidence_calibration']['with_runtime_evidence']['count']);
        $this->assertSame(1, $r['runtime_evidence_calibration']['without_runtime_evidence']['count']);
    }

    // ── AC3: penalizes overclaiming originator patterns ─────────────────────────

    private function originatorTask(string $id, float $predicted, float $actual, string $originator): array
    {
        return array_merge($this->task($id, $predicted, $actual), ['originator_pattern' => $originator]);
    }

    public function test_originator_pattern_absent_when_no_task_supplies_it(): void
    {
        $r = $this->bt([$this->task('t1', 5.0, 5.0)]);

        $this->assertSame([], $r['originator_pattern_penalties']);
    }

    public function test_originator_pattern_with_high_overclaim_rate_is_penalized(): void
    {
        $r = $this->bt([
            $this->originatorTask('o1', 9.0, 6.0, 'pattern-x'),
            $this->originatorTask('o2', 9.0, 6.0, 'pattern-x'),
            $this->originatorTask('o3', 5.0, 5.0, 'pattern-x'),
        ]);

        $penalized = array_column($r['originator_pattern_penalties'], 'originator_pattern');
        $this->assertContains('pattern-x', $penalized);
        $penalty = $r['originator_pattern_penalties'][0];
        $this->assertSame(3, $penalty['sample_count']);
        $this->assertSame('overclaim_rate_exceeds_threshold', $penalty['penalty_reason']);
    }

    public function test_originator_pattern_with_good_calibration_is_not_penalized(): void
    {
        $r = $this->bt([
            $this->originatorTask('o1', 5.0, 5.0, 'pattern-good'),
            $this->originatorTask('o2', 5.0, 5.0, 'pattern-good'),
        ]);

        $this->assertSame([], $r['originator_pattern_penalties']);
    }

    // ── AC4: impact-weight suggestions for future Task Fabric admission ────────

    public function test_impact_weight_suggestions_present_per_family(): void
    {
        $r = $this->bt([$this->familyTask('t1', 9.0, 6.0, 'overclaiming-family')]);

        $suggestion = array_values(array_filter(
            $r['impact_weight_suggestions'],
            static fn (array $s): bool => $s['scope'] === 'task_family' && $s['scope_id'] === 'overclaiming-family',
        ))[0];
        $this->assertLessThan(1.0, $suggestion['suggested_weight'], 'an overclaiming family must be discounted below neutral');
    }

    public function test_impact_weight_suggestions_present_per_originator_pattern(): void
    {
        $r = $this->bt([$this->originatorTask('o1', 2.0, 6.0, 'underclaiming-pattern')]);

        $suggestion = array_values(array_filter(
            $r['impact_weight_suggestions'],
            static fn (array $s): bool => $s['scope'] === 'originator_pattern' && $s['scope_id'] === 'underclaiming-pattern',
        ))[0];
        $this->assertGreaterThan(1.0, $suggestion['suggested_weight'], 'an underclaiming pattern should be boosted above neutral');
    }

    public function test_impact_weight_suggestion_neutral_when_well_calibrated(): void
    {
        $r = $this->bt([$this->familyTask('t1', 5.0, 5.0, 'stable-family')]);

        $suggestion = array_values(array_filter(
            $r['impact_weight_suggestions'],
            static fn (array $s): bool => $s['scope'] === 'task_family' && $s['scope_id'] === 'stable-family',
        ))[0];
        $this->assertSame(1.0, $suggestion['suggested_weight']);
    }

    public function test_impact_weight_suggestions_empty_when_no_tasks(): void
    {
        $r = $this->svc()->backtest([]);

        $this->assertSame([], $r['impact_weight_suggestions']);
    }

    // AC: calibration_update with overestimate, underestimate, calibrated counts
    public function test_calibration_update_has_counts(): void
    {
        $r = $this->svc()->backtest(['scored_tasks' => [
            ['task_id' => 't1', 'predicted_leverage' => 9.0, 'actual_leverage' => 1.0, 'actual_outcome' => 'overestimate_case'],
            ['task_id' => 't2', 'predicted_leverage' => 1.0, 'actual_leverage' => 9.0, 'actual_outcome' => 'underestimate_case'],
            ['task_id' => 't3', 'predicted_leverage' => 5.0, 'actual_leverage' => 5.0, 'actual_outcome' => 'calibrated_case'],
        ]]);

        $update = $r['calibration_update'];
        $this->assertArrayHasKey('overestimate_count', $update);
        $this->assertArrayHasKey('underestimate_count', $update);
        $this->assertArrayHasKey('calibrated_count', $update);
        $this->assertArrayHasKey('calibration_score', $update);
        $this->assertArrayHasKey('calibration_error', $update);
        $this->assertSame(1, $update['overestimate_count']);
        $this->assertSame(1, $update['underestimate_count']);
        $this->assertSame(1, $update['calibrated_count']);
    }

    // AC: task_family_adjustment for future ranking
    public function test_task_family_adjustment_present(): void
    {
        $r = $this->svc()->backtest(['scored_tasks' => [
            ['task_id' => 't1', 'predicted_leverage' => 8.0, 'actual_leverage' => 2.0, 'actual_outcome' => 'give_back', 'task_family' => 'refactor'],
            ['task_id' => 't2', 'predicted_leverage' => 5.0, 'actual_leverage' => 5.0, 'actual_outcome' => 'commit_success', 'task_family' => 'testing'],
        ]]);

        $adjustments = $r['task_family_adjustment'];
        $this->assertCount(2, $adjustments);
        foreach ($adjustments as $adj) {
            $this->assertArrayHasKey('task_family', $adj);
            $this->assertArrayHasKey('correction_direction', $adj);
            $this->assertArrayHasKey('average_error', $adj);
            $this->assertArrayHasKey('sample_count', $adj);
        }
    }

    // AC: calibration_update empty when no tasks
    public function test_calibration_update_empty_when_no_tasks(): void
    {
        $r = $this->svc()->backtest([]);

        $this->assertSame(0, $r['calibration_update']['overestimate_count']);
        $this->assertSame(0, $r['calibration_update']['underestimate_count']);
        $this->assertSame(0, $r['calibration_update']['calibrated_count']);
        $this->assertNull($r['calibration_update']['calibration_score']);
        $this->assertNull($r['calibration_update']['calibration_error']);
        $this->assertSame([], $r['task_family_adjustment']);
    }
}
