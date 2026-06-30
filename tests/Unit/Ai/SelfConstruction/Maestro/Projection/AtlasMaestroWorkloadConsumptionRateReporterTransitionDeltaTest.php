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
}
