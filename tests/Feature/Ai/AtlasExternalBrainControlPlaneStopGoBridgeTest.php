<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneStopGoBridge;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainControlPlaneStopGoBridgeTest extends TestCase
{
    private AtlasExternalBrainControlPlaneStopGoBridge $bridge;

    protected function setUp(): void
    {
        $this->bridge = new AtlasExternalBrainControlPlaneStopGoBridge;
    }

    private function decide(array $overrides = []): array
    {
        return $this->bridge->decide($overrides);
    }

    // ── AC1: red control-plane → stop + self_heal or consolidate ─────────────

    public function test_malformed_risk_maps_to_stop_and_self_heal(): void
    {
        $r = $this->decide(['malformed_risk' => true]);

        $this->assertSame('stop', $r['stop_go_signal']);
        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_SELF_HEAL_QUEUE, $r['stop_go_decision']);
        $this->assertSame('self_heal_queue_before_creating', $r['next_action']);
    }

    public function test_unhealthy_queue_maps_to_stop_and_self_heal(): void
    {
        $r = $this->decide(['queue_health' => 'degraded']);

        $this->assertSame('stop', $r['stop_go_signal']);
        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_SELF_HEAL_QUEUE, $r['stop_go_decision']);
        $this->assertSame('self_heal_queue_before_creating', $r['next_action']);
    }

    public function test_give_back_pressure_high_maps_to_stop_and_self_heal(): void
    {
        $r = $this->decide(['give_back_pressure' => 'high']);

        $this->assertSame('stop', $r['stop_go_signal']);
        $this->assertSame('self_heal_queue_before_creating', $r['next_action']);
    }

    public function test_low_quality_trend_maps_to_stop_and_consolidate(): void
    {
        $r = $this->decide(['quality_trend' => 'low']);

        $this->assertSame('stop', $r['stop_go_signal']);
        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_RUN_CONSOLIDATION, $r['stop_go_decision']);
        $this->assertSame('consolidate_existing_tasks', $r['next_action']);
    }

    public function test_high_sprawl_pressure_maps_to_stop_and_consolidate(): void
    {
        $r = $this->decide(['sprawl_pressure' => 'high']);

        $this->assertSame('stop', $r['stop_go_signal']);
        $this->assertSame('consolidate_existing_tasks', $r['next_action']);
    }

    public function test_stop_decisions_come_before_any_create_action(): void
    {
        // Even when queue_pressure=high (might suggest creating), malformed_risk wins
        $r = $this->decide(['malformed_risk' => true, 'queue_pressure' => 'high', 'quality_trend' => 'high']);

        $this->assertSame('stop', $r['stop_go_signal']);
        $this->assertNotSame('create_high_leverage_batch', $r['next_action']);
    }

    // ── AC2: green status + maturity gaps → go + create_high_leverage_batch ──

    public function test_green_with_maturity_gaps_maps_to_go_and_create_high_leverage(): void
    {
        $r = $this->decide([
            'queue_health'      => 'healthy',
            'quality_trend'     => 'high',
            'maturity_gap_count' => 3,
        ]);

        $this->assertSame('go', $r['stop_go_signal']);
        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_ESCALATE_AMBITION, $r['stop_go_decision']);
        $this->assertSame('create_high_leverage_batch', $r['next_action']);
    }

    public function test_green_without_maturity_gaps_maps_to_go_and_create(): void
    {
        $r = $this->decide([
            'queue_health'  => 'healthy',
            'quality_trend' => 'high',
        ]);

        $this->assertSame('go', $r['stop_go_signal']);
        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_CREATE_MORE_TASKS, $r['stop_go_decision']);
        $this->assertSame('create_high_leverage_batch', $r['next_action']);
    }

    // ── AC3: yellow → watch + consolidate_existing_tasks ─────────────────────

    public function test_high_queue_pressure_non_high_quality_maps_to_watch_and_consolidate(): void
    {
        $r = $this->decide(['queue_pressure' => 'high', 'quality_trend' => 'medium']);

        $this->assertSame('watch', $r['stop_go_signal']);
        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_DRAIN_EXISTING_QUEUE, $r['stop_go_decision']);
        $this->assertSame('consolidate_existing_tasks', $r['next_action']);
    }

    public function test_saturation_guard_maps_to_watch_and_consolidate(): void
    {
        $r = $this->decide(['claimable_depth' => 'high', 'value_density' => 'falling']);

        $this->assertSame('watch', $r['stop_go_signal']);
        $this->assertSame('consolidate_existing_tasks', $r['next_action']);
    }

    // ── AC4: provider-free and deterministic ─────────────────────────────────

    public function test_provider_free_is_always_true(): void
    {
        foreach ([[], ['malformed_risk' => true], ['quality_trend' => 'high', 'queue_health' => 'healthy']] as $input) {
            $this->assertTrue($this->decide($input)['provider_free']);
        }
    }

    public function test_output_is_deterministic(): void
    {
        $input = ['queue_health' => 'healthy', 'quality_trend' => 'high', 'maturity_gap_count' => 2];

        $this->assertSame(json_encode($this->decide($input)), json_encode($this->decide($input)));
    }

    // ── schema / envelope ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->decide([]);

        foreach (['schema', 'stop_go_decision', 'stop_go_signal', 'reasons', 'next_action', 'provider_free'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::SCHEMA, $r['schema']);
    }

    public function test_stop_go_signal_is_one_of_valid_values(): void
    {
        $valid = ['stop', 'go', 'watch', 'hold'];

        $cases = [
            [],
            ['malformed_risk' => true],
            ['quality_trend' => 'high', 'queue_health' => 'healthy'],
            ['queue_pressure' => 'high'],
            ['quality_trend' => 'low'],
        ];

        foreach ($cases as $input) {
            $this->assertContains($this->decide($input)['stop_go_signal'], $valid);
        }
    }
}
