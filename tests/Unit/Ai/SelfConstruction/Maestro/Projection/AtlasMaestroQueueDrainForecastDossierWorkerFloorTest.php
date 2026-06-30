<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroQueueDrainForecastDossier;
use PHPUnit\Framework\TestCase;

/**
 * Pins the worker-floor starvation signal: effective_ready below
 * active_worker_count * minimum_claimable_per_worker must surface as a high/critical
 * productivity_risk with next_action=originate, unless poison or unblock take precedence.
 */
final class AtlasMaestroQueueDrainForecastDossierWorkerFloorTest extends TestCase
{
    public function test_effective_ready_below_worker_floor_yields_originate_and_high_risk(): void
    {
        $result = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 8, 'blocked_count' => 0, 'claimed_count' => 2],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy'],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'active_worker_count' => 10,
            'minimum_claimable_per_worker' => 1.0,
        ]);

        // effective_ready=8, worker_floor=10*1=10, 8<10 => starvation.
        $this->assertSame(8, $result['effective_ready']);
        $this->assertTrue($result['below_worker_floor']);
        $this->assertSame('originate', $result['next_action']);
        $this->assertContains($result['productivity_risk'], [
            AtlasMaestroQueueDrainForecastDossier::RISK_HIGH,
            AtlasMaestroQueueDrainForecastDossier::RISK_CRITICAL,
        ]);
    }

    public function test_effective_ready_at_or_above_worker_floor_does_not_trigger_starvation(): void
    {
        $result = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 12, 'blocked_count' => 0, 'claimed_count' => 2],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy'],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'active_worker_count' => 10,
            'minimum_claimable_per_worker' => 1.0,
        ]);

        $this->assertSame(12, $result['effective_ready']);
        $this->assertFalse($result['below_worker_floor']);
        $this->assertSame('monitor', $result['next_action']);
        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::RISK_LOW, $result['productivity_risk']);
    }

    public function test_high_poison_risk_still_wins_over_worker_floor_starvation(): void
    {
        $result = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 8, 'blocked_count' => 0, 'claimed_count' => 2],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy'],
            'poison_risk' => ['high_risk_count' => 1, 'overall_risk' => 'high'],
            'active_worker_count' => 10,
            'minimum_claimable_per_worker' => 1.0,
        ]);

        $this->assertTrue($result['below_worker_floor']);
        $this->assertSame('drain_poison', $result['next_action']);
    }

    public function test_blocked_count_dominating_effective_ready_still_wins_over_worker_floor(): void
    {
        $result = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 20, 'blocked_count' => 9, 'claimed_count' => 2],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy'],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'active_worker_count' => 10,
            'minimum_claimable_per_worker' => 1.0,
        ]);

        // effective_ready = 20 - 9 = 11; blocked(9) < effective_ready(11) so unblock doesn't fire
        // on its own dominance rule, but worker_floor(10) <= 11 means no starvation either.
        $this->assertFalse($result['below_worker_floor']);

        // Now push blocked_count to dominate effective_ready directly.
        $dominated = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 20, 'blocked_count' => 15, 'claimed_count' => 2],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy'],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'active_worker_count' => 10,
            'minimum_claimable_per_worker' => 1.0,
        ]);
        // effective_ready = 5, blocked(15) >= effective_ready(5) => unblock wins over starvation.
        $this->assertTrue($dominated['below_worker_floor']);
        $this->assertSame('unblock', $dominated['next_action']);
    }

    public function test_zero_active_worker_count_does_not_trigger_starvation(): void
    {
        $result = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 1, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy'],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'active_worker_count' => 0,
        ]);

        $this->assertFalse($result['below_worker_floor']);
    }

    public function test_custom_minimum_claimable_per_worker_scales_the_floor(): void
    {
        $result = (new AtlasMaestroQueueDrainForecastDossier)->compile([
            'queue_health' => ['ready_count' => 8, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy'],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'active_worker_count' => 5,
            'minimum_claimable_per_worker' => 2.0,
        ]);

        // worker_floor = 5*2 = 10 > effective_ready(8) => starvation.
        $this->assertSame(10.0, $result['worker_floor']);
        $this->assertTrue($result['below_worker_floor']);
    }

    public function test_worker_floor_dossier_compile_is_deterministic(): void
    {
        $dossier = new AtlasMaestroQueueDrainForecastDossier;
        $facts = [
            'queue_health' => ['ready_count' => 8, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour' => 4.0, 'risk' => 'healthy'],
            'poison_risk' => ['high_risk_count' => 0, 'overall_risk' => 'low'],
            'active_worker_count' => 10,
        ];

        $this->assertSame($dossier->compile($facts), $dossier->compile($facts));
    }
}
