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
}
