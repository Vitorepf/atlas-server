<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroWorkloadConsumptionRateReporter;
use PHPUnit\Framework\TestCase;

/**
 * Proves reportFromVerifiedTransitions() reports raw consumption, green delivery, give_back
 * drag, active lease pressure and confidence — all derived from verified transition facts,
 * never from bare pulls or claimable depth.
 */
final class AtlasMaestroWorkloadConsumptionRateReporterTest extends TestCase
{
    // ── AC: verified transitions produce raw/green/drag/pressure/confidence ────

    public function test_verified_transitions_report_raw_green_drag_pressure_and_confidence(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportFromVerifiedTransitions([
            'window_seconds' => 3600,
            'transitions' => [
                ['task_packet_id' => 'a', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
                ['task_packet_id' => 'a', 'to' => 'resolved', 'at' => '2026-06-30T00:05:00Z'],
                ['task_packet_id' => 'b', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
                ['task_packet_id' => 'b', 'to' => 'give_back', 'at' => '2026-06-30T00:05:00Z'],
            ],
        ]);

        foreach ([
            'raw_consumption_rate_per_hour',
            'green_delivery_rate_per_hour',
            'give_back_drag',
            'active_lease_pressure',
            'confidence',
        ] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key {$key}");
        }
        $this->assertSame(2.0, $r['raw_consumption_rate_per_hour']);
        $this->assertSame(1.0, $r['green_delivery_rate_per_hour']);
        $this->assertSame(0.5, $r['give_back_drag']);
    }

    // ── AC: fallback snapshots (reportWithTransitionFallback) still work ────────

    public function test_fallback_snapshot_estimated_mode_still_reports_positive_rate(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportWithTransitionFallback([
            'completed_delta' => 4,
            'window_seconds' => 3600,
        ]);

        $this->assertSame(AtlasMaestroWorkloadConsumptionRateReporter::MODE_ESTIMATED, $r['mode']);
        $this->assertGreaterThan(0.0, $r['consumption_rate_per_hour']);
    }

    // ── AC: zero-window safety ──────────────────────────────────────────────────

    public function test_zero_window_seconds_never_divides_by_zero(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportFromVerifiedTransitions([
            'window_seconds' => 0,
            'transitions' => [
                ['task_packet_id' => 'a', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
                ['task_packet_id' => 'a', 'to' => 'resolved', 'at' => '2026-06-30T00:05:00Z'],
            ],
        ]);

        $this->assertIsFloat($r['raw_consumption_rate_per_hour']);
        $this->assertIsFloat($r['green_delivery_rate_per_hour']);
    }

    public function test_no_transitions_at_all_is_safe_and_zero(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportFromVerifiedTransitions([
            'window_seconds' => 3600,
            'transitions' => [],
        ]);

        $this->assertSame(0.0, $r['raw_consumption_rate_per_hour']);
        $this->assertSame(0.0, $r['green_delivery_rate_per_hour']);
        $this->assertSame(0.0, $r['give_back_drag']);
        $this->assertSame(0.0, $r['active_lease_pressure']);
        $this->assertSame('low', $r['confidence']);
    }

    // ── AC: poison-heavy throughput — green rate lower than pull(raw) rate ──────

    public function test_poison_heavy_throughput_green_rate_is_lower_than_raw_pull_rate(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportFromVerifiedTransitions([
            'window_seconds' => 3600,
            'transitions' => [
                ['task_packet_id' => 'a', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
                ['task_packet_id' => 'a', 'to' => 'resolved', 'at' => '2026-06-30T00:05:00Z'],
                ['task_packet_id' => 'b', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
                ['task_packet_id' => 'b', 'to' => 'give_back', 'at' => '2026-06-30T00:05:00Z'],
                ['task_packet_id' => 'c', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
                ['task_packet_id' => 'c', 'to' => 'give_back', 'at' => '2026-06-30T00:05:00Z'],
                ['task_packet_id' => 'd', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
                ['task_packet_id' => 'd', 'to' => 'give_back', 'at' => '2026-06-30T00:05:00Z'],
            ],
        ]);

        $this->assertLessThan($r['raw_consumption_rate_per_hour'], $r['green_delivery_rate_per_hour']);
        $this->assertGreaterThan(0.5, $r['give_back_drag'], 'poison-heavy queue must show heavy give_back drag');
    }

    public function test_active_lease_pressure_reflects_share_of_unresolved_claims(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportFromVerifiedTransitions([
            'window_seconds' => 3600,
            'transitions' => [
                ['task_packet_id' => 'a', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
                ['task_packet_id' => 'a', 'to' => 'resolved', 'at' => '2026-06-30T00:05:00Z'],
                ['task_packet_id' => 'b', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
            ],
        ]);

        $this->assertSame(0.5, $r['active_lease_pressure'], '1 of 2 observed tasks is still just claimed, unresolved');
    }
}
