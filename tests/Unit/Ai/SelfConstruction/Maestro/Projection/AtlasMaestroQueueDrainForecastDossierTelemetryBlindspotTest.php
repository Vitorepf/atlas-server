<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroQueueDrainForecastDossier;
use PHPUnit\Framework\TestCase;

/**
 * Pins the telemetry-blind-spot signal: active workers + claimable backlog + zero serve_total
 * means the drain telemetry itself cannot be trusted. Without a fallback estimate, the dossier
 * must recommend fix_drain_telemetry instead of silently reporting low pressure / wait. With a
 * fallback estimate, it must surface both the caveat and the estimated dry-time while keeping the
 * normal decision machinery. Direct (non-blind-spot) telemetry must be untouched.
 */
final class AtlasMaestroQueueDrainForecastDossierTelemetryBlindspotTest extends TestCase
{
    public function test_blind_spot_without_fallback_recommends_fix_drain_telemetry_over_wait(): void
    {
        $result = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy', 'serve_total' => 0],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'active_worker_count' => 3,
        ]);

        $this->assertTrue($result['telemetry_blind_spot']);
        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::ACTION_FIX_DRAIN_TELEMETRY, $result['next_action']);
        $this->assertNotEmpty($result['telemetry_caveat']);
        $this->assertNull($result['estimated_dry_time']);
    }

    public function test_blind_spot_with_fallback_estimate_includes_caveat_and_estimated_dry_time(): void
    {
        $result = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy', 'serve_total' => 0],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'active_worker_count' => 3,
            'fallback_drain_eta_hours' => 2.5,
        ]);

        $this->assertTrue($result['telemetry_blind_spot']);
        $this->assertNotEmpty($result['telemetry_caveat']);
        $this->assertNotNull($result['estimated_dry_time']);
        $this->assertSame('3h', $result['estimated_dry_time']);
    }

    public function test_normal_direct_telemetry_keeps_existing_decision_fields_untouched(): void
    {
        $result = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy', 'serve_total' => 20],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'active_worker_count' => 3,
        ]);

        $this->assertFalse($result['telemetry_blind_spot']);
        $this->assertNull($result['telemetry_caveat']);
        $this->assertNull($result['estimated_dry_time']);
        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::ACTION_MONITOR, $result['next_action']);
    }

    public function test_no_active_workers_does_not_auto_detect_blind_spot(): void
    {
        $result = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy', 'serve_total' => 0],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'active_worker_count' => 0,
        ]);

        $this->assertFalse($result['telemetry_blind_spot']);
    }

    public function test_explicit_telemetry_blind_spot_flag_is_honored_even_without_auto_detection(): void
    {
        $result = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy'],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'telemetry_blind_spot' => true,
        ]);

        $this->assertTrue($result['telemetry_blind_spot']);
        $this->assertNotEmpty($result['telemetry_caveat']);
    }

    public function test_poison_drain_still_takes_priority_over_blind_spot_fix_action(): void
    {
        $result = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy', 'serve_total' => 0],
            'poison_risk' => ['high_risk_count' => 1, 'overall_risk' => 'high'],
            'active_worker_count' => 3,
        ]);

        $this->assertTrue($result['telemetry_blind_spot']);
        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::ACTION_DRAIN_POISON, $result['next_action']);
    }

    public function test_telemetry_blind_spot_dossier_compile_is_deterministic(): void
    {
        $dossier = new AtlasMaestroQueueDrainForecastDossier;
        $facts = [
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy', 'serve_total' => 0],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'active_worker_count' => 3,
        ];

        $this->assertSame($dossier->compile($facts), $dossier->compile($facts));
    }
}
