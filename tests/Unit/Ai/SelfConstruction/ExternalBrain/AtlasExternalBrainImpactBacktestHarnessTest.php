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
}
