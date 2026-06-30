<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionQueueContinuityForecaster;
use Tests\TestCase;

class AtlasSelfConstructionQueueContinuityForecasterWorkerFloorTest extends TestCase
{
    private function forecaster(): AtlasSelfConstructionQueueContinuityForecaster
    {
        return new AtlasSelfConstructionQueueContinuityForecaster();
    }

    public function test_servable_below_worker_floor_yields_high_or_critical_risk_and_gap_covering_batch(): void
    {
        $result = $this->forecaster()->forecast([
            'servable_depth' => 9,
            'claimable_depth' => 9,
            'throughput_per_hour' => 5.0,
            'throughput_data_age_seconds' => 60,
            'active_worker_count' => 6,
            'minimum_claimable_per_worker' => 2,
        ]);

        $this->assertContains($result['risk_level'], [
            AtlasSelfConstructionQueueContinuityForecaster::RISK_HIGH,
            AtlasSelfConstructionQueueContinuityForecaster::RISK_CRITICAL,
        ]);
        // floor = 6 * 2 = 12; servable = 9; gap = 3
        $this->assertSame(3, $result['worker_floor_gap']);
        $this->assertGreaterThanOrEqual(3, $result['recommended_originator_batch_size']);
        $this->assertFalse($result['fail_closed']);
    }

    public function test_servable_above_worker_floor_does_not_escalate_risk(): void
    {
        $result = $this->forecaster()->forecast([
            'servable_depth' => 100,
            'claimable_depth' => 100,
            'throughput_per_hour' => 5.0,
            'throughput_data_age_seconds' => 60,
            'active_worker_count' => 6,
            'minimum_claimable_per_worker' => 2,
        ]);

        $this->assertSame(0, $result['worker_floor_gap']);
        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::RISK_LOW, $result['risk_level']);
    }

    public function test_no_worker_signals_behaves_as_before(): void
    {
        $result = $this->forecaster()->forecast([
            'servable_depth' => 100,
            'claimable_depth' => 100,
            'throughput_per_hour' => 5.0,
            'throughput_data_age_seconds' => 60,
        ]);

        $this->assertSame(0, $result['worker_floor']);
        $this->assertSame(0, $result['worker_floor_gap']);
        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::RISK_LOW, $result['risk_level']);
    }

    public function test_stale_throughput_still_fails_closed_even_with_worker_floor_signals(): void
    {
        $result = $this->forecaster()->forecast([
            'servable_depth' => 9,
            'claimable_depth' => 9,
            'throughput_per_hour' => 5.0,
            'throughput_data_age_seconds' => 999999,
            'active_worker_count' => 6,
            'minimum_claimable_per_worker' => 2,
        ]);

        $this->assertTrue($result['fail_closed']);
        $this->assertSame('throughput_data_stale', $result['fail_closed_reason']);
        $this->assertSame(AtlasSelfConstructionQueueContinuityForecaster::RISK_CRITICAL, $result['risk_level']);
    }

    public function test_missing_throughput_still_fails_closed_even_with_worker_floor_signals(): void
    {
        $result = $this->forecaster()->forecast([
            'servable_depth' => 9,
            'claimable_depth' => 9,
            'active_worker_count' => 6,
            'minimum_claimable_per_worker' => 2,
        ]);

        $this->assertTrue($result['fail_closed']);
        $this->assertSame('throughput_data_missing', $result['fail_closed_reason']);
    }
}
