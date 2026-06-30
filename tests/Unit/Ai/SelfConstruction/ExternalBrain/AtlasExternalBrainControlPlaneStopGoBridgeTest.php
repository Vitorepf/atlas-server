<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneStopGoBridge;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainControlPlaneStopGoBridgeTest extends TestCase
{
    private AtlasExternalBrainControlPlaneStopGoBridge $bridge;

    protected function setUp(): void
    {
        $this->bridge = new AtlasExternalBrainControlPlaneStopGoBridge;
    }

    private function healthy(array $overrides = []): array
    {
        return array_merge([
            'queue_pressure'     => 'low',
            'quality_trend'      => 'high',
            'sprawl_pressure'    => 'low',
            'malformed_risk'     => false,
            'queue_health'       => 'healthy',
            'maturity_gap_count' => 0,
            'claimable_depth'    => 'low',
            'value_density'      => 'stable',
            'give_back_pressure' => 'low',
        ], $overrides);
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->bridge->decide($this->healthy());

        foreach (['schema', 'stop_go_decision', 'reasons', 'next_action', 'provider_free'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::SCHEMA, $result['schema']);
        $this->assertTrue($result['provider_free']);
    }

    // ── create_more_tasks ─────────────────────────────────────────────────────

    public function test_healthy_high_quality_returns_create_more_tasks(): void
    {
        $result = $this->bridge->decide($this->healthy());

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_CREATE_MORE_TASKS, $result['stop_go_decision']);
    }

    // ── escalate_ambition ─────────────────────────────────────────────────────

    public function test_healthy_high_quality_with_gaps_escalates_ambition(): void
    {
        $result = $this->bridge->decide($this->healthy(['maturity_gap_count' => 3]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_ESCALATE_AMBITION, $result['stop_go_decision']);
        $this->assertStringContainsString('maturity_gap_count:3', implode(' ', $result['reasons']));
    }

    // ── run_consolidation ─────────────────────────────────────────────────────

    public function test_low_quality_trend_returns_run_consolidation(): void
    {
        $result = $this->bridge->decide($this->healthy(['quality_trend' => 'low']));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_RUN_CONSOLIDATION, $result['stop_go_decision']);
        $this->assertStringContainsString('quality_trend:low', implode(' ', $result['reasons']));
    }

    public function test_high_sprawl_returns_run_consolidation(): void
    {
        $result = $this->bridge->decide($this->healthy(['sprawl_pressure' => 'high']));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_RUN_CONSOLIDATION, $result['stop_go_decision']);
        $this->assertStringContainsString('sprawl_pressure:high', implode(' ', $result['reasons']));
    }

    public function test_low_quality_beats_maturity_gaps(): void
    {
        $result = $this->bridge->decide($this->healthy([
            'quality_trend'      => 'low',
            'maturity_gap_count' => 10,
        ]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_RUN_CONSOLIDATION, $result['stop_go_decision']);
    }

    // ── self_heal_queue ───────────────────────────────────────────────────────

    public function test_malformed_risk_returns_self_heal_queue(): void
    {
        $result = $this->bridge->decide($this->healthy(['malformed_risk' => true]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_SELF_HEAL_QUEUE, $result['stop_go_decision']);
        $this->assertStringContainsString('malformed_risk:true', implode(' ', $result['reasons']));
    }

    public function test_malformed_risk_beats_create(): void
    {
        $result = $this->bridge->decide($this->healthy([
            'malformed_risk' => true,
            'quality_trend'  => 'high',
            'queue_health'   => 'healthy',
        ]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_SELF_HEAL_QUEUE, $result['stop_go_decision']);
    }

    public function test_degraded_queue_health_triggers_self_heal(): void
    {
        $result = $this->bridge->decide($this->healthy(['queue_health' => 'degraded']));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_SELF_HEAL_QUEUE, $result['stop_go_decision']);
        $this->assertStringContainsString('queue_health:degraded', implode(' ', $result['reasons']));
    }

    public function test_give_back_pressure_high_triggers_self_heal(): void
    {
        $result = $this->bridge->decide($this->healthy(['give_back_pressure' => 'high']));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_SELF_HEAL_QUEUE, $result['stop_go_decision']);
        $this->assertStringContainsString('give_back_pressure:high', implode(' ', $result['reasons']));
    }

    public function test_self_heal_beats_consolidation_when_both_signals_present(): void
    {
        $result = $this->bridge->decide($this->healthy([
            'malformed_risk' => true,
            'quality_trend'  => 'low',
        ]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_SELF_HEAL_QUEUE, $result['stop_go_decision']);
    }

    // ── drain_existing_queue ──────────────────────────────────────────────────

    public function test_high_queue_pressure_with_medium_quality_drains(): void
    {
        $result = $this->bridge->decide($this->healthy([
            'queue_pressure' => 'high',
            'quality_trend'  => 'medium',
        ]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_DRAIN_EXISTING_QUEUE, $result['stop_go_decision']);
    }

    // ── AC2: saturation guard ─────────────────────────────────────────────────

    public function test_saturation_guard_drains_when_depth_high_and_value_falling(): void
    {
        $result = $this->bridge->decide($this->healthy([
            'claimable_depth' => 'high',
            'value_density'   => 'falling',
        ]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_DRAIN_EXISTING_QUEUE, $result['stop_go_decision']);
        $reasons = implode(' ', $result['reasons']);
        $this->assertStringContainsString('claimable_depth:high', $reasons);
        $this->assertStringContainsString('value_density:falling', $reasons);
        $this->assertStringContainsString('saturation_guard', $reasons);
    }

    public function test_saturation_guard_blocks_even_when_quota_pressure_high(): void
    {
        $result = $this->bridge->decide($this->healthy([
            'queue_pressure'  => 'high',
            'claimable_depth' => 'high',
            'value_density'   => 'falling',
            'quality_trend'   => 'high',
        ]));

        // Saturation guard fires BEFORE drain-from-queue-pressure check
        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_DRAIN_EXISTING_QUEUE, $result['stop_go_decision']);
        $this->assertStringContainsString('saturation_guard', implode(' ', $result['reasons']));
    }

    public function test_saturation_guard_does_not_fire_when_value_stable(): void
    {
        $result = $this->bridge->decide($this->healthy([
            'claimable_depth' => 'high',
            'value_density'   => 'stable',
        ]));

        $this->assertNotSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_DRAIN_EXISTING_QUEUE, $result['stop_go_decision']);
    }

    public function test_saturation_guard_does_not_fire_when_depth_not_high(): void
    {
        $result = $this->bridge->decide($this->healthy([
            'claimable_depth' => 'medium',
            'value_density'   => 'falling',
        ]));

        $this->assertNotSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_DRAIN_EXISTING_QUEUE, $result['stop_go_decision']);
    }

    // ── pause as default ──────────────────────────────────────────────────────

    public function test_medium_quality_no_pressure_returns_pause(): void
    {
        $result = $this->bridge->decide($this->healthy(['quality_trend' => 'medium']));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_PAUSE, $result['stop_go_decision']);
    }

    // ── reasons always present, next_action always non-empty ─────────────────

    public function test_reasons_are_non_empty_for_all_decisions(): void
    {
        $cases = [
            $this->healthy(['malformed_risk' => true]),
            $this->healthy(['quality_trend' => 'low']),
            $this->healthy(['maturity_gap_count' => 5]),
            $this->healthy(),
            $this->healthy(['give_back_pressure' => 'high']),
            $this->healthy(['claimable_depth' => 'high', 'value_density' => 'falling']),
        ];

        foreach ($cases as $input) {
            $result = $this->bridge->decide($input);
            $this->assertNotEmpty($result['reasons']);
        }
    }

    public function test_next_action_is_always_non_empty(): void
    {
        $result = $this->bridge->decide($this->healthy());

        $this->assertNotEmpty($result['next_action']);
    }

    public function test_next_action_differs_by_decision(): void
    {
        $healAction   = $this->bridge->decide($this->healthy(['malformed_risk' => true]))['next_action'];
        $createAction = $this->bridge->decide($this->healthy())['next_action'];

        $this->assertNotSame($healAction, $createAction);
    }
}
