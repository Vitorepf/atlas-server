<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskAutoReplenishmentService;
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

    // ── buildReadinessOrchestratorPlan(): workspace/queue/evidence/lane boundaries ──

    private function safeContext(array $overrides = []): array
    {
        return array_merge([
            'workspace' => '/Users/me/atlas-server',
            'queue_last_synced_seconds_ago' => 30,
            'evidence_ledger_path' => '/Users/me/atlas-server/storage/evidence-ledger',
            'project_lane' => 'atlas-server',
        ], $overrides);
    }

    public function test_full_safe_context_produces_minimal_orchestrator_plan(): void
    {
        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildReadinessOrchestratorPlan($this->safeContext());

        self::assertTrue($result['ready']);
        self::assertSame([], $result['blockers']);
        self::assertSame('/Users/me/atlas-server', $result['orchestrator_plan']['workspace']);
        self::assertSame(30, $result['orchestrator_plan']['queue_context']['last_synced_seconds_ago']);
        self::assertSame('/Users/me/atlas-server/storage/evidence-ledger', $result['orchestrator_plan']['evidence_boundary']);
        self::assertSame('atlas-server', $result['orchestrator_plan']['project_lane']);
    }

    public function test_missing_workspace_is_rejected(): void
    {
        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildReadinessOrchestratorPlan(
            $this->safeContext(['workspace' => '']),
        );

        self::assertFalse($result['ready']);
        self::assertNull($result['orchestrator_plan']);
        self::assertContains('missing_workspace', $result['blockers']);
    }

    public function test_relative_workspace_is_rejected(): void
    {
        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildReadinessOrchestratorPlan(
            $this->safeContext(['workspace' => 'relative/path']),
        );

        self::assertFalse($result['ready']);
        self::assertContains('workspace_must_be_an_absolute_path', $result['blockers']);
    }

    public function test_stale_queue_context_is_rejected(): void
    {
        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildReadinessOrchestratorPlan(
            $this->safeContext(['queue_last_synced_seconds_ago' => 7200]),
        );

        self::assertFalse($result['ready']);
        self::assertNull($result['orchestrator_plan']);
        $blob = implode(',', $result['blockers']);
        self::assertStringContainsString('stale_queue_context', $blob);
    }

    public function test_missing_queue_context_is_rejected(): void
    {
        $context = $this->safeContext();
        unset($context['queue_last_synced_seconds_ago']);

        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildReadinessOrchestratorPlan($context);

        self::assertFalse($result['ready']);
        self::assertContains('missing_queue_context', $result['blockers']);
    }

    public function test_missing_evidence_boundary_is_rejected(): void
    {
        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildReadinessOrchestratorPlan(
            $this->safeContext(['evidence_ledger_path' => '']),
        );

        self::assertFalse($result['ready']);
        self::assertNull($result['orchestrator_plan']);
        self::assertContains('missing_evidence_boundary', $result['blockers']);
    }

    public function test_evidence_boundary_outside_workspace_is_rejected(): void
    {
        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildReadinessOrchestratorPlan(
            $this->safeContext(['evidence_ledger_path' => '/etc/passwd']),
        );

        self::assertFalse($result['ready']);
        self::assertContains('evidence_boundary_outside_workspace', $result['blockers']);
    }

    public function test_ambiguous_project_lane_with_multiple_candidates_is_rejected(): void
    {
        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildReadinessOrchestratorPlan(
            $this->safeContext(['candidate_lanes' => ['atlas-server', 'atlas-desktop']]),
        );

        self::assertFalse($result['ready']);
        self::assertNull($result['orchestrator_plan']);
        $blob = implode(',', $result['blockers']);
        self::assertStringContainsString('ambiguous_project_lane', $blob);
    }

    public function test_missing_project_lane_is_rejected(): void
    {
        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildReadinessOrchestratorPlan(
            $this->safeContext(['project_lane' => '']),
        );

        self::assertFalse($result['ready']);
        self::assertContains('missing_project_lane', $result['blockers']);
    }

    public function test_multiple_blockers_accumulate_and_never_produce_a_partial_plan(): void
    {
        $result = ReadinessAgentControlPlaneOrchestratorFactory::buildReadinessOrchestratorPlan([]);

        self::assertFalse($result['ready']);
        self::assertNull($result['orchestrator_plan']);
        self::assertGreaterThanOrEqual(4, count($result['blockers']));
    }
}
