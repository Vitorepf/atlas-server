<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasTaskGraphStrategicChainPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasTaskGraphStrategicChainPlannerTest extends TestCase
{
    private AtlasTaskGraphStrategicChainPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasTaskGraphStrategicChainPlanner;
    }

    public function test_schema_present(): void
    {
        $result = $this->planner->plan([]);
        $this->assertSame(AtlasTaskGraphStrategicChainPlanner::SCHEMA, $result['schema']);
    }

    public function test_empty_tasks_returns_empty_chains(): void
    {
        $result = $this->planner->plan([]);
        $this->assertSame(0, $result['chain_count']);
        $this->assertSame(0, $result['total_steps']);
    }

    // ── AC2: dependency unlock tasks outrank independent low-impact tasks ──

    public function test_dependency_unlock_outranks_independent_low_impact(): void
    {
        $result = $this->planner->plan([
            ['id' => 'low-impact', 'kind' => 'independent', 'risk_level' => 'low', 'impact_score' => 0.1],
            ['id' => 'unlock', 'kind' => 'independent', 'risk_level' => 'low', 'impact_score' => 0.1, 'unlocks_dependencies' => ['dep-1']],
        ]);

        $chains = $result['chains'];
        $this->assertNotEmpty($chains);
        $firstStep = $chains[0]['steps'][0];
        $this->assertSame('unlock', $firstStep['id']);
    }

    // ── AC3: worker feed target keeps enough ready work ──

    public function test_worker_feed_target_keeps_enough_ready_work(): void
    {
        $result = $this->planner->plan([
            ['id' => 't1', 'kind' => 'independent', 'risk_level' => 'low', 'impact_score' => 0.5],
            ['id' => 't2', 'kind' => 'independent', 'risk_level' => 'low', 'impact_score' => 0.5],
            ['id' => 't3', 'kind' => 'independent', 'risk_level' => 'low', 'impact_score' => 0.5],
            ['id' => 't4', 'kind' => 'independent', 'risk_level' => 'low', 'impact_score' => 0.5],
            ['id' => 't5', 'kind' => 'independent', 'risk_level' => 'low', 'impact_score' => 0.5],
        ], ['worker_feed_target' => 3]);

        $this->assertSame(3, $result['worker_feed_target']);
        // Chains should have at least 3 steps in the first chain
        $this->assertGreaterThanOrEqual(1, $result['chain_count']);
        $this->assertSame(5, $result['executable_steps']);
    }

    // ── AC4: high-risk tasks require proof predecessors ──

    public function test_high_risk_without_proof_predecessors_not_executable(): void
    {
        $result = $this->planner->plan([
            ['id' => 'high-risk', 'kind' => 'independent', 'risk_level' => 'high', 'impact_score' => 0.9],
        ]);

        $this->assertSame(0, $result['executable_steps']);
        $this->assertSame(1, $result['blocked_steps']);
    }

    public function test_high_risk_with_proof_predecessors_executable(): void
    {
        $result = $this->planner->plan([
            ['id' => 'high-risk', 'kind' => 'independent', 'risk_level' => 'high', 'impact_score' => 0.9, 'proof_predecessors' => ['proof-1']],
        ]);

        $this->assertSame(1, $result['executable_steps']);
        $this->assertSame(0, $result['blocked_steps']);
    }

    public function test_read_only_flags(): void
    {
        $result = $this->planner->plan([]);
        $this->assertTrue($result['read_only']);
        $this->assertFalse($result['runtime_execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
    }
}
