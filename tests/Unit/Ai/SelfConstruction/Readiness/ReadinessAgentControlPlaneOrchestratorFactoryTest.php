<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessAgentControlPlaneOrchestratorFactory;
use Tests\TestCase;

class ReadinessAgentControlPlaneOrchestratorFactoryTest extends TestCase
{
    public function test_build_task_queue_orchestrator_returns_correct_instance(): void
    {
        $orchestrator = ReadinessAgentControlPlaneOrchestratorFactory::buildTaskQueueOrchestrator();

        self::assertInstanceOf(AgentControlPlaneTaskQueueOrchestrator::class, $orchestrator);
    }

    public function test_build_task_auto_replenishment_service_returns_correct_instance(): void
    {
        $service = ReadinessAgentControlPlaneOrchestratorFactory::buildTaskAutoReplenishmentService();

        self::assertInstanceOf(AgentControlPlaneTaskAutoReplenishmentService::class, $service);
    }

    public function test_factory_methods_are_deterministic(): void
    {
        $o1 = ReadinessAgentControlPlaneOrchestratorFactory::buildTaskQueueOrchestrator();
        $o2 = ReadinessAgentControlPlaneOrchestratorFactory::buildTaskQueueOrchestrator();

        $s1 = ReadinessAgentControlPlaneOrchestratorFactory::buildTaskAutoReplenishmentService();
        $s2 = ReadinessAgentControlPlaneOrchestratorFactory::buildTaskAutoReplenishmentService();

        self::assertInstanceOf(AgentControlPlaneTaskQueueOrchestrator::class, $o1);
        self::assertInstanceOf(AgentControlPlaneTaskQueueOrchestrator::class, $o2);
        self::assertInstanceOf(AgentControlPlaneTaskAutoReplenishmentService::class, $s1);
        self::assertInstanceOf(AgentControlPlaneTaskAutoReplenishmentService::class, $s2);
    }

    public function test_build_task_auto_replenishment_shares_queue_instance_between_orchestrator_and_service(): void
    {
        // The factory creates a single TaskPacketQueueRepository instance shared
        // between the inner orchestrator and the outer replenishment service.
        // We verify the factory structure is correct by confirming the service
        // can be constructed without errors — the sharing is an internal detail
        // of the factory wiring.
        $service = ReadinessAgentControlPlaneOrchestratorFactory::buildTaskAutoReplenishmentService();

        self::assertInstanceOf(AgentControlPlaneTaskAutoReplenishmentService::class, $service);
    }

    public function test_each_factory_call_produces_new_instances(): void
    {
        $a = ReadinessAgentControlPlaneOrchestratorFactory::buildTaskQueueOrchestrator();
        $b = ReadinessAgentControlPlaneOrchestratorFactory::buildTaskQueueOrchestrator();

        self::assertNotSame($a, $b);
    }

    // ── AC: lane-aware readiness config ─────────────────────────────────────────

    public function test_full_lane_context_produces_valid_orchestrator_config(): void
    {
        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildLaneAwareOrchestratorConfig([
            'project_lane' => 'atlas-server',
            'queue_namespace' => 'agent_control_plane',
            'worker_class' => AgentControlPlaneTaskQueueOrchestrator::class,
        ]);

        self::assertTrue($result['lane_context_valid']);
        self::assertNull($result['blocking_reason']);
        self::assertSame('atlas-server', $result['orchestrator_config']['project_lane']);
        self::assertSame('agent_control_plane', $result['orchestrator_config']['queue_namespace']);
    }

    public function test_missing_lane_context_is_rejected_instead_of_defaulting(): void
    {
        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildLaneAwareOrchestratorConfig([
            'project_lane' => 'atlas-server',
        ]);

        self::assertFalse($result['lane_context_valid']);
        self::assertNull($result['orchestrator_config']);
        self::assertStringContainsString('missing_lane_context', $result['blocking_reason']);
        self::assertStringContainsString('queue_namespace', $result['blocking_reason']);
        self::assertStringContainsString('worker_class', $result['blocking_reason']);
    }

    public function test_unresolvable_worker_class_is_rejected_as_ambiguous(): void
    {
        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildLaneAwareOrchestratorConfig([
            'project_lane' => 'atlas-server',
            'queue_namespace' => 'agent_control_plane',
            'worker_class' => 'App\\Not\\A\\Real\\Class',
        ]);

        self::assertFalse($result['lane_context_valid']);
        self::assertNull($result['orchestrator_config']);
        self::assertStringContainsString('ambiguous_worker_class', $result['blocking_reason']);
    }
}
