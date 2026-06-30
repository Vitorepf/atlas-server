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

    // ── AC2: healthy + high quality → create ─────────────────────────────────

    public function test_healthy_high_quality_returns_create_more_tasks(): void
    {
        $result = $this->bridge->decide($this->healthy());

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_CREATE_MORE_TASKS, $result['stop_go_decision']);
    }

    // ── AC2: healthy + high quality + gaps → escalate ────────────────────────

    public function test_healthy_high_quality_with_gaps_escalates_ambition(): void
    {
        $result = $this->bridge->decide($this->healthy(['maturity_gap_count' => 3]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_ESCALATE_AMBITION, $result['stop_go_decision']);
        $this->assertStringContainsString('maturity_gap_count:3', implode(' ', $result['reasons']));
    }

    // ── AC3: consolidate when quality_trend is low ────────────────────────────

    public function test_low_quality_trend_returns_consolidate_or_self_heal(): void
    {
        $result = $this->bridge->decide($this->healthy(['quality_trend' => 'low']));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_CONSOLIDATE_OR_SELF_HEAL, $result['stop_go_decision']);
        $this->assertStringContainsString('quality_trend:low', implode(' ', $result['reasons']));
    }

    // ── AC3: consolidate when malformed_risk is true ──────────────────────────

    public function test_malformed_risk_returns_consolidate_or_self_heal(): void
    {
        $result = $this->bridge->decide($this->healthy(['malformed_risk' => true]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_CONSOLIDATE_OR_SELF_HEAL, $result['stop_go_decision']);
        $this->assertStringContainsString('malformed_risk:true', implode(' ', $result['reasons']));
    }

    // ── AC3: consolidate when sprawl_pressure is high ────────────────────────

    public function test_high_sprawl_returns_consolidate_or_self_heal(): void
    {
        $result = $this->bridge->decide($this->healthy(['sprawl_pressure' => 'high']));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_CONSOLIDATE_OR_SELF_HEAL, $result['stop_go_decision']);
        $this->assertStringContainsString('sprawl_pressure:high', implode(' ', $result['reasons']));
    }

    // ── AC3: consolidate beats create when value is low (even with gaps) ──────

    public function test_low_quality_beats_maturity_gaps(): void
    {
        $result = $this->bridge->decide($this->healthy([
            'quality_trend'      => 'low',
            'maturity_gap_count' => 10,
        ]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_CONSOLIDATE_OR_SELF_HEAL, $result['stop_go_decision']);
    }

    // ── AC3: consolidate beats create when malformed risk is high ─────────────

    public function test_malformed_risk_beats_create(): void
    {
        $result = $this->bridge->decide($this->healthy([
            'malformed_risk'  => true,
            'quality_trend'   => 'high',
            'queue_health'    => 'healthy',
        ]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_CONSOLIDATE_OR_SELF_HEAL, $result['stop_go_decision']);
    }

    // ── AC2: drain when high pressure but not high quality ────────────────────

    public function test_high_queue_pressure_with_medium_quality_drains(): void
    {
        $result = $this->bridge->decide($this->healthy([
            'queue_pressure' => 'high',
            'quality_trend'  => 'medium',
        ]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_DRAIN_QUEUE, $result['stop_go_decision']);
    }

    // ── Pause as default ──────────────────────────────────────────────────────

    public function test_degraded_queue_medium_quality_returns_pause(): void
    {
        $result = $this->bridge->decide($this->healthy([
            'queue_health'  => 'degraded',
            'quality_trend' => 'medium',
        ]));

        $this->assertSame(AtlasExternalBrainControlPlaneStopGoBridge::DECISION_PAUSE, $result['stop_go_decision']);
    }

    // ── Reasons are always present ────────────────────────────────────────────

    public function test_reasons_are_non_empty_for_action_decisions(): void
    {
        $cases = [
            $this->healthy(['malformed_risk' => true]),
            $this->healthy(['quality_trend' => 'low']),
            $this->healthy(['maturity_gap_count' => 5]),
            $this->healthy(),
        ];

        foreach ($cases as $input) {
            $result = $this->bridge->decide($input);
            $this->assertNotEmpty($result['reasons']);
        }
    }

    // ── next_action is always non-empty ──────────────────────────────────────

    public function test_next_action_is_always_non_empty(): void
    {
        $result = $this->bridge->decide($this->healthy());

        $this->assertNotEmpty($result['next_action']);
    }
}
