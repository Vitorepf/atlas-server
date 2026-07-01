<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroWorkloadConsumptionRateReporter;
use PHPUnit\Framework\TestCase;

/**
 * Pins reportWithTransitionFallback(): direct serve telemetry wins when present (mode=direct);
 * absent that, queue status transition deltas (completed/claimed) produce an estimated rate
 * (mode=estimated); with neither source, the rate is honestly reported as 0 (mode=blind) rather
 * than fabricated.
 */
final class AtlasMaestroWorkloadConsumptionRateReporterTransitionDeltaTest extends TestCase
{
    public function test_direct_serve_telemetry_emits_mode_direct_and_the_direct_rate(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportWithTransitionFallback([
            'direct_rate' => 12.5,
            'window_seconds' => 3600,
        ]);

        $this->assertSame(AtlasMaestroWorkloadConsumptionRateReporter::MODE_DIRECT, $r['mode']);
        $this->assertSame(12.5, $r['consumption_rate_per_hour']);
    }

    public function test_direct_event_count_is_converted_via_window_when_no_explicit_rate(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportWithTransitionFallback([
            'direct_event_count' => 5,
            'window_seconds' => 1800, // 30 minutes
        ]);

        $this->assertSame(AtlasMaestroWorkloadConsumptionRateReporter::MODE_DIRECT, $r['mode']);
        $this->assertSame(10.0, $r['consumption_rate_per_hour']);
    }

    public function test_no_direct_telemetry_with_completed_delta_emits_estimated_positive_rate(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportWithTransitionFallback([
            'completed_delta' => 4,
            'window_seconds' => 3600,
        ]);

        $this->assertSame(AtlasMaestroWorkloadConsumptionRateReporter::MODE_ESTIMATED, $r['mode']);
        $this->assertGreaterThan(0.0, $r['consumption_rate_per_hour']);
        $this->assertSame(4.0, $r['consumption_rate_per_hour']);
    }

    public function test_no_direct_telemetry_with_claimed_delta_emits_estimated_positive_rate(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportWithTransitionFallback([
            'claimed_delta' => 6,
            'window_seconds' => 3600,
        ]);

        $this->assertSame(AtlasMaestroWorkloadConsumptionRateReporter::MODE_ESTIMATED, $r['mode']);
        $this->assertGreaterThan(0.0, $r['consumption_rate_per_hour']);
    }

    public function test_neither_source_emits_mode_blind_and_zero_rate(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportWithTransitionFallback([]);

        $this->assertSame(AtlasMaestroWorkloadConsumptionRateReporter::MODE_BLIND, $r['mode']);
        $this->assertSame(0.0, $r['consumption_rate_per_hour']);
    }

    public function test_direct_telemetry_takes_priority_over_transition_deltas(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportWithTransitionFallback([
            'direct_rate' => 9.0,
            'completed_delta' => 100,
        ]);

        $this->assertSame(AtlasMaestroWorkloadConsumptionRateReporter::MODE_DIRECT, $r['mode']);
        $this->assertSame(9.0, $r['consumption_rate_per_hour']);
    }

    public function test_evidence_explains_which_source_backed_the_mode(): void
    {
        $direct = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportWithTransitionFallback(['direct_rate' => 1.0]);
        $estimated = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportWithTransitionFallback(['completed_delta' => 1]);
        $blind = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportWithTransitionFallback([]);

        $this->assertNotEmpty($direct['evidence']);
        $this->assertNotEmpty($estimated['evidence']);
        $this->assertNotEmpty($blind['evidence']);
    }

    public function test_report_with_transition_fallback_is_deterministic(): void
    {
        $reporter = new AtlasMaestroWorkloadConsumptionRateReporter;
        $input = ['completed_delta' => 3, 'window_seconds' => 1800];

        $this->assertSame(
            $reporter->reportWithTransitionFallback($input),
            $reporter->reportWithTransitionFallback($input),
        );
    }

    // ── AC: reportFromVerifiedTransitions() consumes real transitions, never bare leases ──

    public function test_counts_only_claimable_to_claimed_to_resolved_or_give_back_transitions_with_timestamps(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportFromVerifiedTransitions([
            'window_seconds' => 3600,
            'transitions' => [
                ['task_packet_id' => 'a', 'to' => 'claimable', 'at' => '2026-06-30T00:00:00Z'],
                ['task_packet_id' => 'a', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
                ['task_packet_id' => 'a', 'to' => 'resolved', 'at' => '2026-06-30T00:05:00Z'],
                ['task_packet_id' => 'b', 'to' => 'claimable', 'at' => '2026-06-30T00:00:00Z'],
                ['task_packet_id' => 'b', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
                ['task_packet_id' => 'b', 'to' => 'give_back', 'at' => '2026-06-30T00:05:00Z'],
            ],
        ]);

        $this->assertSame(1, $r['transition_counts']['resolved']);
        $this->assertSame(1, $r['transition_counts']['give_back']);
        $this->assertGreaterThan(0.0, $r['consumption_rate_per_hour']);
    }

    public function test_does_not_treat_active_leases_alone_as_completed_throughput(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportFromVerifiedTransitions([
            'window_seconds' => 3600,
            'transitions' => [
                ['task_packet_id' => 'a', 'to' => 'claimable', 'at' => '2026-06-30T00:00:00Z'],
                ['task_packet_id' => 'a', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
            ],
        ]);

        $this->assertSame([], $r['transition_counts']);
        $this->assertSame(0.0, $r['consumption_rate_per_hour']);
        $this->assertSame(1, $r['ignored_claim_only_count']);
    }

    public function test_output_includes_consumption_rate_transition_counts_confidence_and_ignored_claim_only_count(): void
    {
        $r = (new AtlasMaestroWorkloadConsumptionRateReporter)->reportFromVerifiedTransitions([
            'window_seconds' => 3600,
            'transitions' => [
                ['task_packet_id' => 'a', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
                ['task_packet_id' => 'a', 'to' => 'resolved', 'at' => '2026-06-30T00:05:00Z'],
                ['task_packet_id' => 'b', 'to' => 'claimed', 'at' => '2026-06-30T00:01:00Z'],
            ],
        ]);

        foreach (['consumption_rate_per_hour', 'transition_counts', 'confidence', 'ignored_claim_only_count'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
        $this->assertSame(1, $r['ignored_claim_only_count']);
    }
}
