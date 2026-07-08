<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ChainIntegrity;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneChainIntegrityCorridorAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AgentControlPlaneCycleHorizonAnalyzer is wired via thin seams on
 * AgentControlPlaneChainIntegrityCorridorAnalyzer, mirroring how the sibling
 * AgentControlPlaneCorridorProjector (extracted from the same god-class) is wired.
 */
final class AgentControlPlaneCycleHorizonAnalyzerWiringWiredTest extends TestCase
{
    public function test_cycle_integrity_delegates_to_static_analyzer(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $deepChain = [
            ['slice_key' => 'a', 'activate_key' => 'activate_a'],
        ];

        $result = $analyzer->cycleIntegrity($deepChain, 'activate_a');

        $this->assertSame('atlas.self_construction.agent_control_plane_cycle_integrity.v1', $result['schema_version']);
        $this->assertArrayHasKey('cycle_ok', $result);
    }

    public function test_analyze_chain_horizon_delegates_to_static_analyzer(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $result = $analyzer->analyzeChainHorizon([
            ['task_id' => 't1', 'status' => 'queued', 'family' => 'default'],
        ]);

        $this->assertSame('healthy', $result['classification']);
        $this->assertFalse($result['dead_end']);
        $this->assertSame(['t1'], $result['servable_descendants']);
    }

    public function test_analyze_chain_horizon_detects_dead_end(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $result = $analyzer->analyzeChainHorizon([
            ['task_id' => 't1', 'status' => 'give_back', 'family' => 'foo'],
        ]);

        $this->assertTrue($result['dead_end']);
        $this->assertSame('dead_end', $result['classification']);
    }

    public function test_terminal_horizon_analysis_delegates_to_static_analyzer(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $deepChain = [
            ['slice_key' => 'a', 'activate_key' => 'activate_a'],
            ['slice_key' => 'b', 'activate_key' => 'activate_b'],
        ];
        $cycleIntegrity = $analyzer->cycleIntegrity($deepChain, 'activate_a');

        $result = $analyzer->terminalHorizonAnalysis($deepChain, 'activate_a', $cycleIntegrity);

        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_horizon_analysis.v1', $result['schema_version']);
        $this->assertSame('linear_next', $result['horizon_type']);
        $this->assertTrue($result['horizon_ok']);
        $this->assertSame('activate_b', $result['next_safe_macro_batch']);
    }
}
