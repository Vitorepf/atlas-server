<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;

/**
 * Factory wiring for the Agent Control Plane task-queue orchestrator and
 * auto-replenishment service.
 *
 * Extracted from AtlasSelfConstructionReadinessService to reduce the god-class.
 * Pure construction — no instance state, no constructor arguments.
 */
final class ReadinessAgentControlPlaneOrchestratorFactory
{
    /**
     * Lane-aware readiness config. Requires explicit project_lane, queue_namespace
     * and worker_class so readiness orchestration can never silently default into
     * the wrong project's queue namespace or worker class. Never builds the
     * orchestrator when lane context is missing or ambiguous.
     *
     * @param  array<string,mixed>  $context
     * @return array{orchestrator_config: ?array<string,mixed>, lane_context_valid: bool, blocking_reason: ?string}
     */
    public static function buildLaneAwareOrchestratorConfig(array $context): array
    {
        $projectLane = trim((string) ($context['project_lane'] ?? ''));
        $queueNamespace = trim((string) ($context['queue_namespace'] ?? ''));
        $workerClass = trim((string) ($context['worker_class'] ?? ''));

        $missing = [];
        if ($projectLane === '') {
            $missing[] = 'project_lane';
        }
        if ($queueNamespace === '') {
            $missing[] = 'queue_namespace';
        }
        if ($workerClass === '') {
            $missing[] = 'worker_class';
        }

        if ($missing !== []) {
            return [
                'orchestrator_config' => null,
                'lane_context_valid' => false,
                'blocking_reason' => 'missing_lane_context:'.implode(',', $missing),
            ];
        }

        if (! class_exists($workerClass)) {
            return [
                'orchestrator_config' => null,
                'lane_context_valid' => false,
                'blocking_reason' => 'ambiguous_worker_class:'.$workerClass,
            ];
        }

        return [
            'orchestrator_config' => [
                'project_lane' => $projectLane,
                'queue_namespace' => $queueNamespace,
                'worker_class' => $workerClass,
            ],
            'lane_context_valid' => true,
            'blocking_reason' => null,
        ];
    }

    public static function buildTaskQueueOrchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    public static function buildTaskAutoReplenishmentService(): AgentControlPlaneTaskAutoReplenishmentService
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        return new AgentControlPlaneTaskAutoReplenishmentService(
            new AgentControlPlaneTaskQueueOrchestrator(
                new AgentControlPlaneTaskPacketBuilder,
                new AgentControlPlaneScopeLockRuntimeValidator,
                $queue,
                new AgentControlPlaneClaimLeaseRepository,
                new AgentControlPlaneEvidenceLedgerDryRun,
                new AgentControlPlaneContinuationSummaryBuilder,
            ),
            $queue,
        );
    }
}
