<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneConvergenceRuntimeBridge;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainControlPlaneConvergenceRuntimeBridgeTest extends TestCase
{
    private AtlasExternalBrainControlPlaneConvergenceRuntimeBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new AtlasExternalBrainControlPlaneConvergenceRuntimeBridge();
    }

    // AC 1: Bridge emits next_runtime_action from integration coverage, blocked organs, ornamental organs and stop/go decision
    public function test_next_runtime_action_is_consolidate_when_blocked_high(): void
    {
        $result = $this->bridge->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 50.0,
            'blocked_organs' => ['A', 'B', 'C', 'D', 'E'],
            'stop_go_reasons' => ['coverage_low'],
        ]);

        $this->assertNotEmpty($result['next_runtime_action']);
        $this->assertStringContainsString('consolidate', strtolower($result['next_runtime_action']));
    }

    public function test_next_runtime_action_is_monitor_when_healthy(): void
    {
        $result = $this->bridge->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 95.0,
            'blocked_organs' => [],
            'stop_go_reasons' => [],
        ]);

        $this->assertNotEmpty($result['next_runtime_action']);
        $this->assertStringContainsString('monitor', strtolower($result['next_runtime_action']));
    }

    public function test_next_runtime_action_is_rewire_when_ornamental(): void
    {
        $result = $this->bridge->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 80.0,
            'blocked_organs' => [],
            'ornamental_organs' => ['X', 'Y'],
            'stop_go_reasons' => [],
        ]);

        $this->assertNotEmpty($result['next_runtime_action']);
        $this->assertStringContainsString('rewire', strtolower($result['next_runtime_action']));
    }

    public function test_next_runtime_action_is_stop_when_stop_decision(): void
    {
        $result = $this->bridge->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 30.0,
            'blocked_organs' => ['A', 'B', 'C', 'D', 'E'],
            'stop_go_decision' => 'stop',
            'stop_go_reasons' => ['critical'],
        ]);

        $this->assertNotEmpty($result['next_runtime_action']);
        $this->assertStringContainsString('stop', strtolower($result['next_runtime_action']));
    }

    // AC 2: Bridge downgrades effective readiness when simplification pressure is high even if raw coverage is high
    public function test_readiness_band_is_degraded_when_simplification_pressure_high(): void
    {
        $result = $this->bridge->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 90.0,
            'blocked_organs' => ['A', 'B', 'C', 'D', 'E'],
            'stop_go_reasons' => [],
        ]);

        $this->assertNotSame('ready', $result['readiness_band']);
        $this->assertContains($result['readiness_band'], ['degraded', 'blocked']);
    }

    public function test_readiness_band_is_ready_when_healthy(): void
    {
        $result = $this->bridge->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 95.0,
            'blocked_organs' => [],
            'stop_go_reasons' => [],
        ]);

        $this->assertSame('ready', $result['readiness_band']);
    }

    public function test_readiness_band_is_blocked_when_stop_decision(): void
    {
        $result = $this->bridge->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 30.0,
            'blocked_organs' => ['A', 'B', 'C', 'D', 'E'],
            'stop_go_decision' => 'stop',
            'stop_go_reasons' => ['critical'],
        ]);

        $this->assertSame('blocked', $result['readiness_band']);
    }

    // AC 3: Output includes next_runtime_action, readiness_band, simplification_pressure and reasons
    public function test_output_has_required_fields(): void
    {
        $result = $this->bridge->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 70.0,
            'blocked_organs' => ['A'],
            'stop_go_reasons' => [],
        ]);

        $this->assertArrayHasKey('next_runtime_action', $result);
        $this->assertArrayHasKey('readiness_band', $result);
        $this->assertArrayHasKey('simplification_pressure', $result);
        $this->assertArrayHasKey('reasons', $result);
    }

    public function test_output_has_schema(): void
    {
        $result = $this->bridge->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 70.0,
            'blocked_organs' => [],
            'stop_go_reasons' => [],
        ]);

        $this->assertArrayHasKey('schema', $result);
        $this->assertSame('atlas.external_brain.control_plane_convergence_runtime_bridge.v1', $result['schema']);
    }

    public function test_translate_is_deterministic(): void
    {
        $input = [
            'total_organs' => 10,
            'integration_coverage_percent' => 70.0,
            'blocked_organs' => ['A'],
            'ornamental_organs' => ['B'],
            'stop_go_decision' => 'watch',
            'stop_go_reasons' => [],
        ];

        $a = $this->bridge->translate($input);
        $b = $this->bridge->translate($input);

        $this->assertSame($a, $b);
    }

    public function test_high_coverage_low_blocked_is_ready(): void
    {
        $result = $this->bridge->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 95.0,
            'blocked_organs' => [],
            'stop_go_reasons' => [],
        ]);

        $this->assertSame('ready', $result['readiness_band']);
        $this->assertSame('low', $result['simplification_pressure']);
    }

    public function test_low_coverage_high_blocked_is_blocked(): void
    {
        $result = $this->bridge->translate([
            'total_organs' => 10,
            'integration_coverage_percent' => 20.0,
            'blocked_organs' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'stop_go_reasons' => ['critical'],
        ]);

        $this->assertSame('blocked', $result['readiness_band']);
        $this->assertSame('high', $result['simplification_pressure']);
    }
}
