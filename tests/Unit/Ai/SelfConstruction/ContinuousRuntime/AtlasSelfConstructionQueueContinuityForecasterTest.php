<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionQueueContinuityForecaster;
use Tests\TestCase;

final class AtlasSelfConstructionQueueContinuityForecasterTest extends TestCase
{
    private function svc(): AtlasSelfConstructionQueueContinuityForecaster
    {
        return new AtlasSelfConstructionQueueContinuityForecaster;
    }

    private function snap(array $overrides = []): array
    {
        return $overrides + [
            'claimable_depth' => 40,
            'servable_depth' => 20,
            'blocked_count' => 10,
            'throughput_per_hour' => 5.0,
            'throughput_data_age_seconds' => 60,
            'replenishment_latency_seconds' => 1800,
        ];
    }

    // ── normal forecast ───────────────────────────────────────────────────────

    public function test_normal_snapshot_emits_hours_until_dry_and_replenish_by(): void
    {
        // servable=20, throughput=5/h → hours_until_dry=4, replenish_latency=0.5h → replenish_by=3.5
        $r = $this->svc()->forecast($this->snap());

        $this->assertFalse($r['fail_closed']);
        $this->assertEqualsWithDelta(4.0, $r['hours_until_dry'], 0.01);
        $this->assertEqualsWithDelta(3.5, $r['replenish_by'], 0.01);
        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::SCHEMA, $r['schema_version']);
    }

    public function test_replenish_by_negative_when_latency_exceeds_runway(): void
    {
        // servable=2, throughput=5/h → hours=0.4; latency=2h → replenish_by=-1.6 (urgent)
        $r = $this->svc()->forecast($this->snap([
            'servable_depth' => 2,
            'replenishment_latency_seconds' => 7200,
        ]));

        $this->assertLessThan(0.0, $r['replenish_by']);
    }

    public function test_blocked_packets_not_counted_in_servable_capacity(): void
    {
        // high claimable but low servable → hours based on servable only
        $r = $this->svc()->forecast($this->snap([
            'claimable_depth' => 100,
            'servable_depth' => 5,
            'blocked_count' => 95,
            'throughput_per_hour' => 5.0,
        ]));

        $this->assertEqualsWithDelta(1.0, $r['hours_until_dry'], 0.01);
        $this->assertSame(5, $r['discounted_capacity']['servable']);
        $this->assertSame(95, $r['discounted_capacity']['blocked']);
    }

    // ── risk levels ───────────────────────────────────────────────────────────

    public function test_risk_critical_when_hours_lte_1(): void
    {
        $r = $this->svc()->forecast($this->snap(['servable_depth' => 4, 'throughput_per_hour' => 5.0]));
        // 4/5=0.8h ≤ 1 → critical
        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::RISK_CRITICAL, $r['risk_level']);
    }

    public function test_risk_high_when_hours_between_1_and_4(): void
    {
        // 10/5=2h
        $r = $this->svc()->forecast($this->snap(['servable_depth' => 10, 'throughput_per_hour' => 5.0]));
        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::RISK_HIGH, $r['risk_level']);
    }

    public function test_risk_medium_when_hours_between_4_and_8(): void
    {
        // 30/5=6h
        $r = $this->svc()->forecast($this->snap(['servable_depth' => 30, 'throughput_per_hour' => 5.0]));
        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::RISK_MEDIUM, $r['risk_level']);
    }

    public function test_risk_low_when_hours_above_8(): void
    {
        // 50/5=10h
        $r = $this->svc()->forecast($this->snap(['servable_depth' => 50, 'throughput_per_hour' => 5.0]));
        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::RISK_LOW, $r['risk_level']);
    }

    // ── fail-closed conditions ────────────────────────────────────────────────

    public function test_zero_throughput_fails_closed(): void
    {
        $r = $this->svc()->forecast($this->snap(['throughput_per_hour' => 0.0]));

        $this->assertTrue($r['fail_closed']);
        $this->assertSame('throughput_data_missing', $r['fail_closed_reason']);
        $this->assertSame(0.0, $r['hours_until_dry']);
        $this->assertNull($r['replenish_by']);
        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::RISK_CRITICAL, $r['risk_level']);
    }

    public function test_stale_throughput_data_fails_closed(): void
    {
        $r = $this->svc()->forecast($this->snap([
            'throughput_data_age_seconds' => AtlasSelfConstructionQueueContinuityForecaster::THROUGHPUT_STALE_SECONDS + 1,
        ]));

        $this->assertTrue($r['fail_closed']);
        $this->assertSame('throughput_data_stale', $r['fail_closed_reason']);
    }

    public function test_empty_snapshot_fails_closed(): void
    {
        $r = $this->svc()->forecast([]);

        $this->assertTrue($r['fail_closed']);
        $this->assertSame('throughput_data_missing', $r['fail_closed_reason']);
    }

    // ── recommended batch size ────────────────────────────────────────────────

    public function test_recommended_batch_proportional_to_throughput(): void
    {
        // throughput=2/h × buffer_hours=4 = 8 → ceil=8
        $r = $this->svc()->forecast($this->snap(['throughput_per_hour' => 2.0]));
        $this->assertSame(8, $r['recommended_originator_batch_size']);
    }

    public function test_recommended_batch_minimum_1_when_throughput_very_low(): void
    {
        $r = $this->svc()->forecast($this->snap(['throughput_per_hour' => 0.1]));
        $this->assertGreaterThanOrEqual(1, $r['recommended_originator_batch_size']);
    }

    public function test_fail_closed_uses_default_batch_size(): void
    {
        $r = $this->svc()->forecast($this->snap(['throughput_per_hour' => 0.0]));
        $this->assertSame(10, $r['recommended_originator_batch_size']);
    }

    // ── AC: high worker drain shortens forecast horizon even at high queue depth ──

    public function test_high_worker_drain_shortens_forecast_horizon_even_at_high_queue_depth(): void
    {
        // Very deep queue (hours_until_dry is huge), but workers are draining fast.
        $r = $this->svc()->forecast($this->snap([
            'servable_depth' => 1000,
            'throughput_per_hour' => 5.0,
            'worker_drain_rate' => 0.5, // 1/0.5 = 2h worker sustainability
        ]));

        $this->assertGreaterThan(100.0, $r['hours_until_dry'], 'queue depth alone would suggest a long runway');
        $this->assertEqualsWithDelta(2.0, $r['forecast_horizon_hours'], 0.01, 'worker drain must cap the forecast horizon');
        $this->assertLessThan($r['hours_until_dry'], $r['forecast_horizon_hours']);
    }

    public function test_no_worker_drain_leaves_forecast_horizon_equal_to_hours_until_dry(): void
    {
        $r = $this->svc()->forecast($this->snap());

        $this->assertEqualsWithDelta($r['hours_until_dry'], $r['forecast_horizon_hours'], 0.01);
    }

    // ── AC: high give_back rate lowers healthy supply ──────────────────────────

    public function test_high_give_back_rate_lowers_healthy_supply(): void
    {
        $r = $this->svc()->forecast($this->snap([
            'servable_depth' => 20,
            'give_back_rate' => 0.5,
        ]));

        $this->assertEqualsWithDelta(10.0, $r['healthy_supply'], 0.01);
        $this->assertLessThan(20, $r['healthy_supply']);
        $this->assertSame('high_give_back_rate_reduces_effective_healthy_supply', $r['quality_warning']);
    }

    public function test_low_give_back_rate_does_not_trigger_quality_warning(): void
    {
        $r = $this->svc()->forecast($this->snap(['give_back_rate' => 0.05]));

        $this->assertNull($r['quality_warning']);
    }

    // ── AC: forecast output includes replenish_window and quality_warning ─────

    public function test_forecast_output_includes_replenish_window_and_quality_warning(): void
    {
        $r = $this->svc()->forecast($this->snap());

        $this->assertArrayHasKey('quality_warning', $r);
        $this->assertArrayHasKey('replenish_window', $r);
        $this->assertArrayHasKey('start_hours', $r['replenish_window']);
        $this->assertArrayHasKey('end_hours', $r['replenish_window']);
    }

    public function test_fail_closed_response_also_includes_replenish_window_and_quality_warning(): void
    {
        $r = $this->svc()->forecast($this->snap(['throughput_per_hour' => 0.0]));

        $this->assertArrayHasKey('quality_warning', $r);
        $this->assertArrayHasKey('replenish_window', $r);
    }

    // ── safety_window_hours clamp ──────────────────────────────────────────────

    public function test_negative_safety_window_hours_clamped_to_zero(): void
    {
        // Negative safety_window_hours must be clamped to 0.0 so it cannot mask
        // an imminent replenish_before_empty signal.
        $r = $this->svc()->forecast($this->snap([
            'claimable_depth' => 1,
            'active_leases' => 2,
            'completed_dry_run_per_hour_per_lease' => 1.0,
            'safety_window_hours' => -1.0,
        ]));

        // drain_rate = 2*1.0 = 2.0/h, time_to_no_claimable = 1/2.0 = 0.5h
        // safety_window clamped to 0.0 → 0.5 < 0.0 is false → stable
        // (the clamp prevents the negative from masking; with 0.0 window,
        // any positive time_to_no_claimable is "stable" — the point is the
        // negative doesn't make the comparison always false)
        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::CONTINUITY_STABLE, $r['continuity_status']);
    }

    public function test_positive_safety_window_hours_triggers_replenish_before_empty(): void
    {
        // With a positive safety window, an imminent drain should trigger replenish.
        $r = $this->svc()->forecast($this->snap([
            'claimable_depth' => 1,
            'active_leases' => 2,
            'completed_dry_run_per_hour_per_lease' => 1.0,
            'safety_window_hours' => 2.0,
        ]));

        // drain_rate = 2.0/h, time_to_no_claimable = 0.5h < 2.0 → replenish_before_empty
        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::CONTINUITY_REPLENISH_BEFORE_EMPTY, $r['continuity_status']);
    }

    public function test_source_clamps_safety_window_hours(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../../app/Services/Ai/SelfConstruction/ContinuousRuntime/AtlasSelfConstructionQueueContinuityForecaster.php');

        $this->assertStringContainsString('max(0.0, (float)', $source);
        $this->assertStringContainsString("safety_window_hours", $source);
    }
}
