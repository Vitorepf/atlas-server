<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroQueueDrainForecastDossier;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroQueueDrainForecastDossierTest extends TestCase
{
    private AtlasMaestroQueueDrainForecastDossier $dossier;

    protected function setUp(): void
    {
        $this->dossier = new AtlasMaestroQueueDrainForecastDossier;
    }

    private function healthy(array $overrides = []): array
    {
        return array_replace_recursive([
            'queue_health' => [
                'ready_count'   => 10,
                'blocked_count' => 0,
                'claimed_count' => 2,
            ],
            'workload_projection' => [
                'throughput_per_hour' => 4.0,
                'risk'                => 'healthy',
            ],
            'blocked_plan' => ['blocked_count' => 0],
            'poison_risk'  => ['high_risk_count' => 0, 'overall_risk' => 'low'],
        ], $overrides);
    }

    // ── Schema / required keys ───────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->dossier->compile($this->healthy());

        foreach (['schema', 'drain_eta', 'productivity_risk', 'next_action', 'evidence_refs', 'effective_ready'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::SCHEMA, $result['schema']);
    }

    // ── AC2: blocked + high-poison NOT counted as effective ready ────────────

    public function test_effective_ready_excludes_blocked_packets(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 3, 'claimed_count' => 0],
        ]));

        $this->assertSame(7, $result['effective_ready']);
    }

    public function test_effective_ready_excludes_high_poison_packets(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 0, 'claimed_count' => 0],
            'poison_risk'  => ['high_risk_count' => 4, 'overall_risk' => 'medium'],
        ]));

        $this->assertSame(6, $result['effective_ready']);
    }

    public function test_effective_ready_excludes_both_blocked_and_high_poison(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 2, 'claimed_count' => 0],
            'poison_risk'  => ['high_risk_count' => 3, 'overall_risk' => 'medium'],
        ]));

        $this->assertSame(5, $result['effective_ready']);
    }

    public function test_effective_ready_never_goes_below_zero(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health' => ['ready_count' => 3, 'blocked_count' => 5, 'claimed_count' => 0],
            'poison_risk'  => ['high_risk_count' => 2, 'overall_risk' => 'high'],
        ]));

        $this->assertSame(0, $result['effective_ready']);
    }

    // ── AC1: drain_eta ───────────────────────────────────────────────────────

    public function test_drain_eta_uses_effective_ready_not_raw_ready(): void
    {
        // 10 ready, 7 blocked → effective_ready=3; throughput=3/h → 1h
        $result = $this->dossier->compile($this->healthy([
            'queue_health'        => ['ready_count' => 10, 'blocked_count' => 7, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour' => 3.0, 'risk' => 'healthy'],
        ]));

        $this->assertSame('1h', $result['drain_eta']);
    }

    public function test_drain_eta_unknown_when_no_throughput(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'workload_projection' => ['throughput_per_hour' => 0.0, 'risk' => 'healthy'],
        ]));

        $this->assertStringContainsString('unknown', $result['drain_eta']);
    }

    public function test_drain_eta_queue_effectively_empty_when_no_effective_ready(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health' => ['ready_count' => 2, 'blocked_count' => 2, 'claimed_count' => 0],
        ]));

        $this->assertSame('queue_effectively_empty', $result['drain_eta']);
    }

    // ── AC1: productivity_risk ───────────────────────────────────────────────

    public function test_productivity_risk_critical_when_effective_ready_is_zero(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health' => ['ready_count' => 1, 'blocked_count' => 1, 'claimed_count' => 0],
        ]));

        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::RISK_CRITICAL, $result['productivity_risk']);
    }

    public function test_productivity_risk_high_when_overall_poison_high(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'poison_risk' => ['high_risk_count' => 2, 'overall_risk' => 'high'],
        ]));

        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::RISK_HIGH, $result['productivity_risk']);
    }

    public function test_productivity_risk_low_in_healthy_conditions(): void
    {
        $result = $this->dossier->compile($this->healthy());
        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::RISK_LOW, $result['productivity_risk']);
    }

    // ── AC1: next_action ─────────────────────────────────────────────────────

    public function test_next_action_drain_poison_when_poison_risk_high(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'poison_risk' => ['high_risk_count' => 5, 'overall_risk' => 'high'],
        ]));

        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::ACTION_DRAIN_POISON, $result['next_action']);
    }

    public function test_next_action_unblock_when_blocked_ge_effective_ready(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 8, 'claimed_count' => 0],
            'poison_risk'  => ['high_risk_count' => 0, 'overall_risk' => 'low'],
        ]));

        // effective_ready = 2; blocked = 8 ≥ 2 → unblock
        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::ACTION_UNBLOCK, $result['next_action']);
    }

    public function test_next_action_originate_when_queue_starving(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health'        => ['ready_count' => 2, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour' => 2.0, 'risk' => 'replenish_now'],
        ]));

        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::ACTION_ORIGINATE, $result['next_action']);
    }

    public function test_next_action_monitor_when_conditions_healthy(): void
    {
        $result = $this->dossier->compile($this->healthy());
        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::ACTION_MONITOR, $result['next_action']);
    }

    // ── evidence_refs ────────────────────────────────────────────────────────

    // ── AC: productive_ready / poison_blocked_ready never inflated ───────────

    public function test_productive_ready_equals_effective_ready(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 2, 'claimed_count' => 0],
            'poison_risk'  => ['high_risk_count' => 3, 'overall_risk' => 'medium'],
        ]));

        $this->assertSame($result['effective_ready'], $result['productive_ready']);
        $this->assertSame(5, $result['productive_ready']);
    }

    public function test_poison_blocked_ready_reflects_excluded_poison_packets(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health' => ['ready_count' => 10, 'blocked_count' => 0, 'claimed_count' => 0],
            'poison_risk'  => ['high_risk_count' => 4, 'overall_risk' => 'medium'],
        ]));

        $this->assertSame(4, $result['poison_blocked_ready']);
    }

    // ── AC: five-worker throughput shorter eta than one-worker ───────────────

    public function test_five_worker_eta_is_shorter_than_one_worker_eta(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health'        => ['ready_count' => 40, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour_per_worker' => 2.0, 'risk' => 'healthy'],
        ]));

        $etas = $result['eta_to_dry_by_worker_count'];
        $this->assertArrayHasKey('1', $etas);
        $this->assertArrayHasKey('5', $etas);
        $this->assertSame('20h', $etas['1']);
        $this->assertSame('4h', $etas['5']);
    }

    public function test_low_effective_ready_recommends_originate_before_muscles_starve(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health'        => ['ready_count' => 2, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour_per_worker' => 2.0, 'risk' => 'replenish_now'],
        ]));

        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::ACTION_ORIGINATE, $result['next_action']);
    }

    // ── AC: poison-heavy queues recommend drain_poison/unblock not blind originate ──

    public function test_poison_heavy_queue_recommends_drain_poison_not_originate(): void
    {
        $result = $this->dossier->compile($this->healthy([
            'queue_health'        => ['ready_count' => 2, 'blocked_count' => 0, 'claimed_count' => 0],
            'workload_projection' => ['throughput_per_hour_per_worker' => 2.0, 'risk' => 'replenish_now'],
            'poison_risk'         => ['high_risk_count' => 5, 'overall_risk' => 'high'],
        ]));

        $this->assertSame(AtlasMaestroQueueDrainForecastDossier::ACTION_DRAIN_POISON, $result['next_action']);
        $this->assertNotSame(AtlasMaestroQueueDrainForecastDossier::ACTION_ORIGINATE, $result['next_action']);
    }

    public function test_evidence_refs_is_non_empty_list_of_strings(): void
    {
        $result = $this->dossier->compile($this->healthy());

        $this->assertIsArray($result['evidence_refs']);
        $this->assertNotEmpty($result['evidence_refs']);
        foreach ($result['evidence_refs'] as $ref) {
            $this->assertIsString($ref);
        }
    }
}
