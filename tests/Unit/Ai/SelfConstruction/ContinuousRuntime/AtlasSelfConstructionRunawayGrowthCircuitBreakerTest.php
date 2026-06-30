<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionRunawayGrowthCircuitBreaker;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionRunawayGrowthCircuitBreakerTest extends TestCase
{
    private function breaker(): AtlasSelfConstructionRunawayGrowthCircuitBreaker
    {
        return new AtlasSelfConstructionRunawayGrowthCircuitBreaker;
    }

    private function healthy(): array
    {
        return [
            'files_added_rolling'          => 5,
            'task_count_delta'             => 3,
            'orphaned_organs_count'        => 5,
            'integration_proof_count'      => 5,
            'value_proof_density'          => 0.8,
            'capability_sampling_coverage' => 0.6,
            'backlog_cost_rolling'         => 100,
        ];
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->breaker()->evaluate([]);
        $this->assertSame(AtlasSelfConstructionRunawayGrowthCircuitBreaker::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('tripped', $r);
        $this->assertArrayHasKey('trip_reason', $r);
        $this->assertArrayHasKey('backpressure_action', $r);
        $this->assertArrayHasKey('diagnostics', $r);
    }

    public function test_healthy_metrics_do_not_trip_breaker(): void
    {
        $r = $this->breaker()->evaluate($this->healthy());
        $this->assertFalse($r['tripped']);
        $this->assertNull($r['trip_reason']);
        $this->assertNull($r['backpressure_action']);
    }

    // ── AC1 + AC2: trip conditions ────────────────────────────────────────────

    public function test_fail_closed_when_proof_metric_absent_with_growth(): void
    {
        $facts = ['files_added_rolling' => 10, 'task_count_delta' => 5];
        // value_proof_density key is absent → fail-closed.
        $r = $this->breaker()->evaluate($facts);
        $this->assertTrue($r['tripped']);
        $this->assertSame('missing_proof_metric_with_growth', $r['trip_reason']);
        $this->assertSame('pause_origination', $r['backpressure_action']);
    }

    public function test_absent_proof_metric_with_zero_growth_does_not_trip(): void
    {
        // No growth → fail-closed condition is not triggered.
        $r = $this->breaker()->evaluate(['value_proof_density' => 0.9]);
        $this->assertFalse($r['tripped']);
    }

    public function test_thin_integration_proof_with_growth_trips_with_pause(): void
    {
        $facts = array_merge($this->healthy(), [
            'integration_proof_count' => 2,  // < MIN (3)
            'files_added_rolling'     => 5,  // growth present
        ]);
        $r = $this->breaker()->evaluate($facts);
        $this->assertTrue($r['tripped']);
        $this->assertSame('thin_integration_proof_with_growth', $r['trip_reason']);
        $this->assertSame('pause_origination', $r['backpressure_action']);
    }

    public function test_thin_capability_sampling_with_high_growth_trips(): void
    {
        $facts = array_merge($this->healthy(), [
            'files_added_rolling'          => 25,  // > FILES_THRESHOLD (20)
            'capability_sampling_coverage' => 0.1, // < MIN_SAMPLING (0.25)
        ]);
        $r = $this->breaker()->evaluate($facts);
        $this->assertTrue($r['tripped']);
        $this->assertSame('thin_capability_sampling_with_high_growth', $r['trip_reason']);
        $this->assertSame('pause_origination', $r['backpressure_action']);
    }

    public function test_orphan_accumulation_without_proof_trips_with_throttle(): void
    {
        $facts = array_merge($this->healthy(), [
            'orphaned_organs_count' => 25,   // > ORPHAN_THRESHOLD (20)
            'value_proof_density'   => 0.15, // < VALUE_PROOF_MIN (0.3)
            // files_added stays low so condition 3 doesn't fire first
            'files_added_rolling'   => 5,
        ]);
        $r = $this->breaker()->evaluate($facts);
        $this->assertTrue($r['tripped']);
        $this->assertSame('orphan_accumulation_without_proof', $r['trip_reason']);
        $this->assertSame('throttle_task_intake', $r['backpressure_action']);
    }

    public function test_high_file_growth_without_value_proof_trips(): void
    {
        $facts = array_merge($this->healthy(), [
            'files_added_rolling' => 30,   // > FILES_THRESHOLD
            'value_proof_density' => 0.1,  // < VALUE_PROOF_MIN
        ]);
        $r = $this->breaker()->evaluate($facts);
        $this->assertTrue($r['tripped']);
        $this->assertSame('growth_without_value_proof', $r['trip_reason']);
        $this->assertSame('require_proof_before_next_batch', $r['backpressure_action']);
    }

    public function test_high_sampling_with_high_growth_does_not_trip_sampling_condition(): void
    {
        $facts = array_merge($this->healthy(), [
            'files_added_rolling'          => 30,
            'capability_sampling_coverage' => 0.8, // above MIN_SAMPLING
        ]);
        $r = $this->breaker()->evaluate($facts);
        // Should not trip on sampling condition; value_proof_density is healthy.
        $this->assertFalse($r['tripped']);
    }

    // ── AC2: integration-proof takes precedence when both are thin ────────────

    public function test_integration_proof_condition_fires_before_sampling(): void
    {
        $facts = array_merge($this->healthy(), [
            'integration_proof_count'      => 1,
            'capability_sampling_coverage' => 0.1,
            'files_added_rolling'          => 25,
        ]);
        $r = $this->breaker()->evaluate($facts);
        $this->assertSame('thin_integration_proof_with_growth', $r['trip_reason']);
    }

    public function test_diagnostics_carry_raw_input_values(): void
    {
        $r = $this->breaker()->evaluate($this->healthy());
        $d = $r['diagnostics'];
        $this->assertSame(5, $d['files_added_rolling']);
        $this->assertSame(5, $d['integration_proof_count']);
        $this->assertSame(0.8, $d['value_proof_density']);
        $this->assertTrue($d['has_growth']);
    }

    public function test_output_is_deterministic(): void
    {
        $facts = $this->healthy();
        $a     = $this->breaker()->evaluate($facts);
        $b     = $this->breaker()->evaluate($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
