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

    /** Queue context older than this is refused as stale rather than trusted blind. */
    private const QUEUE_STALE_AFTER_SECONDS = 3600;

    /**
     * Builds the minimal orchestrator PLAN (queue, evidence and context inputs only — never an
     * actual orchestrator instance) once workspace, queue freshness, evidence boundary and
     * project-lane facts are all proven safe. Refuses to construct anything partial: any single
     * unsafe boundary blocks the whole plan.
     *
     * @param  array<string,mixed>  $context  {workspace?, queue_last_synced_seconds_ago?,
     *   evidence_ledger_path?, project_lane?, candidate_lanes?}
     * @return array{orchestrator_plan: ?array<string,mixed>, ready: bool, blockers: list<string>}
     */
    public static function buildReadinessOrchestratorPlan(array $context): array
    {
        $blockers = [];

        $workspace = trim((string) ($context['workspace'] ?? ''));
        if ($workspace === '') {
            $blockers[] = 'missing_workspace';
        } elseif (! str_starts_with($workspace, '/')) {
            $blockers[] = 'workspace_must_be_an_absolute_path';
        }

        $queueAgeProvided = array_key_exists('queue_last_synced_seconds_ago', $context);
        $queueAge = max(0, (int) ($context['queue_last_synced_seconds_ago'] ?? 0));
        if (! $queueAgeProvided) {
            $blockers[] = 'missing_queue_context';
        } elseif ($queueAge > self::QUEUE_STALE_AFTER_SECONDS) {
            $blockers[] = 'stale_queue_context:'.$queueAge;
        }

        $evidenceLedgerPath = trim((string) ($context['evidence_ledger_path'] ?? ''));
        if ($evidenceLedgerPath === '') {
            $blockers[] = 'missing_evidence_boundary';
        } elseif ($workspace !== '' && ! str_starts_with($evidenceLedgerPath, $workspace)) {
            $blockers[] = 'evidence_boundary_outside_workspace';
        }

        $projectLane = trim((string) ($context['project_lane'] ?? ''));
        $candidateLanes = array_values(array_filter(array_map('strval', (array) ($context['candidate_lanes'] ?? []))));
        if ($projectLane === '') {
            $blockers[] = 'missing_project_lane';
        } elseif (count($candidateLanes) > 1) {
            $blockers[] = 'ambiguous_project_lane:'.implode(',', $candidateLanes);
        }

        if ($blockers !== []) {
            return [
                'orchestrator_plan' => null,
                'ready' => false,
                'blockers' => $blockers,
            ];
        }

        return [
            'orchestrator_plan' => [
                'workspace' => $workspace,
                'queue_context' => ['last_synced_seconds_ago' => $queueAge],
                'evidence_boundary' => $evidenceLedgerPath,
                'project_lane' => $projectLane,
            ],
            'ready' => true,
            'blockers' => [],
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
