<?php

declare(strict_types=1);

namespace App\Services\Ai\ControlPlane\ControlPlane;

use App\Models\AiAtlasDecisionReceipt;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiAtlasRuntimeDispatch;
use App\Models\AiEngineeringCompanyCertification;
use App\Models\AiEngineeringCompanyEngagement;
use App\Models\AiEngineeringCompanyReleasePack;
use App\Models\AiEngineeringCompanyRoleRun;
use App\Models\AiEvidencePack;
use App\Models\AiHoldingExternalActionMandate;
use App\Models\AiHoldingExternalCutoverRuntimeInvocation;
use App\Models\AiHoldingExternalCutoverWorkItem;
use App\Models\AiHoldingExternalCutoverWorkOrder;
use App\Models\AiJob;
use App\Models\AiOperatorApproval;
use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiRealExecutionForgeHandoff;
use App\Models\AiTrace;
use App\Models\AtlasAaelAuditReport;
use App\Models\AtlasAaelEvolutionExperiment;
use App\Models\AtlasAaelOpportunity;
use App\Models\AtlasAaelPortfolioCycle;
use App\Models\AtlasAaelPromotionDecision;
use App\Models\AtlasAarsCertification;
use App\Models\AtlasAarsRiskProjection;
use App\Models\AtlasAarsScenario;
use App\Models\AtlasAarsSimulation;
use App\Models\AtlasAemorExecutionEpisode;
use App\Models\AtlasAemorJudgmentReport;
use App\Models\AtlasAemorLearningSignal;
use App\Models\AtlasAemorMemoryCandidate;
use App\Models\AtlasAemorOutcome;
use App\Models\AtlasAgenticWorkcell;
use App\Models\AtlasAgenticWorkcellOrgPattern;
use App\Models\AtlasAgenticWorkcellOutcome;
use App\Models\AtlasAverCertifiedExecution;
use App\Models\AtlasAverExecution;
use App\Models\AtlasAweosCertifiedOutcome;
use App\Models\AtlasAweosExecution;
use App\Models\AtlasExecutiveBriefing;
use App\Models\AtlasIntelligenceFactoryCapability;
use App\Models\AtlasIntelligenceFactoryDecision;
use App\Models\AtlasIntelligenceFactoryEvolutionEvent;
use App\Models\AtlasIntelligenceFactoryGap;
use App\Models\AtlasIntelligenceFactorySimulation;
use App\Models\AtlasOpportunitySignal;
use App\Models\AtlasPersistentContextPack;
use App\Models\AtlasRealityEntity;
use App\Models\AtlasRiskSignal;
use App\Models\AtlasRuntimeEfficiencyDecision;
use App\Models\AtlasRuntimeEfficiencyOutcome;
use App\Models\AtlasStrategicDecision;
use App\Models\AtlasWorkspaceArtifactGraphSnapshot;
use App\Models\AtlasWorkspaceRuntimeProjectionSnapshot;
use App\Services\Ai\Compounding\AtlasLearningSignalScanner;
use App\Services\Ai\OperatorApproval\OperatorApprovalCanon;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentMergeReviewPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentRuntimeRegistryHandoffProtocolBuilder;
use App\Services\Ai\SelfConstruction\Support\AgentValidationGateDryRunEvaluator;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceArtifactShadowExecutionService;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Throwable;
use App\Services\Ai\ControlPlane\AtlasAiControlPlaneService;

final class BlockerSection
{
    public function __construct(private readonly ControlPlaneSupport $support) {}

    /**
     * @param  array<int,string>  $traceIds
     * @return array<int,array<string,mixed>>
     */
    public function blockers(CarbonImmutable $since, array $traceIds): array
    {
        $blockers = [];

        // 1. Failed traces without an error_message (silent failure).
        if (DatabaseTableAvailability::has('ai_traces')) {
            try {
                $silentFailures = AiTrace::query()
                    ->where('created_at', '>=', $since)
                    ->where('status', 'failed')
                    ->with(['job' => fn ($q) => $q->select(['id', 'trace_id', 'error_message'])])
                    ->limit(AtlasAiControlPlaneService::BLOCKER_LIMIT)
                    ->get(['id', 'status', 'metadata']);
                foreach ($silentFailures as $trace) {
                    $err = trim((string) ($trace->job?->error_message ?? ''));
                    if ($err === '') {
                        $blockers[] = [
                            'kind' => 'silent_failure',
                            'class' => 'system_blocker',
                            'severity' => 'critical',
                            'trace_id' => (string) $trace->id,
                            'flow_id' => $this->support->flowIdFromTrace($trace),
                            'detail' => 'trace failed without persisted error_message',
                        ];
                    }
                }
            } catch (Throwable) {
                // tolerate.
            }
        }

        // 2. Dispatch with persisted blockers list.
        if (DatabaseTableAvailability::has('ai_atlas_runtime_dispatches')) {
            try {
                $dispatches = AiAtlasRuntimeDispatch::query()
                    ->where('created_at', '>=', $since)
                    ->whereNotNull('blockers')
                    ->limit(AtlasAiControlPlaneService::BLOCKER_LIMIT)
                    ->get(['id', 'dispatch_status', 'blockers']);
                foreach ($dispatches as $dispatch) {
                    $reasons = is_array($dispatch->blockers) ? $dispatch->blockers : [];
                    if ($reasons === []) {
                        continue;
                    }
                    foreach ($reasons as $reason) {
                        $detail = is_array($reason) ? ($this->support->stringOrNull($reason['reason'] ?? null) ?? 'unspecified') : (string) $reason;
                        $class = $this->dispatchBlockerClass($detail);
                        $blockers[] = [
                            'kind' => 'dispatch_blocker',
                            'class' => $class,
                            'severity' => $class === 'system_blocker' ? 'high' : 'operator_queue',
                            'dispatch_id' => (string) $dispatch->id,
                            'dispatch_status' => $this->support->stringOrNull($dispatch->dispatch_status),
                            'detail' => $detail,
                        ];
                        if (count($blockers) >= AtlasAiControlPlaneService::BLOCKER_LIMIT) {
                            break 2;
                        }
                    }
                }
            } catch (Throwable) {
                // tolerate.
            }
        }

        // 3. Quality evaluations with status=failed (hard blockers).
        if (DatabaseTableAvailability::has('ai_quality_evaluations')) {
            try {
                $rows = AiQualityEvaluation::query()
                    ->where('created_at', '>=', $since)
                    ->where('status', 'failed')
                    ->limit(AtlasAiControlPlaneService::BLOCKER_LIMIT)
                    ->get(['id', 'trace_id', 'flags']);
                foreach ($rows as $row) {
                    $blockers[] = [
                        'kind' => 'quality_failed',
                        'class' => 'system_blocker',
                        'severity' => 'critical',
                        'trace_id' => $this->support->stringOrNull($row->trace_id),
                        'detail' => 'quality evaluation marked failed',
                    ];
                    if (count($blockers) >= AtlasAiControlPlaneService::BLOCKER_LIMIT) {
                        break;
                    }
                }
            } catch (Throwable) {
                // tolerate.
            }
        }

        // 4. Forge handoff with status != succeeded after window age.
        if (DatabaseTableAvailability::has('ai_real_execution_forge_handoffs')) {
            try {
                $stale = AiRealExecutionForgeHandoff::query()
                    ->where('created_at', '>=', $since)
                    ->whereNotIn('status', ['succeeded', 'completed', 'closed'])
                    ->limit(AtlasAiControlPlaneService::BLOCKER_LIMIT)
                    ->get(['id', 'handoff_id', 'status', 'created_at']);
                foreach ($stale as $row) {
                    $blockers[] = [
                        'kind' => 'handoff_incomplete',
                        'class' => 'operator_queue',
                        'severity' => 'operator_queue',
                        'handoff_id' => $this->support->stringOrNull($row->handoff_id),
                        'status' => $this->support->stringOrNull($row->status),
                        'detail' => 'Forge handoff not in terminal succeeded/completed state',
                    ];
                    if (count($blockers) >= AtlasAiControlPlaneService::BLOCKER_LIMIT) {
                        break;
                    }
                }
            } catch (Throwable) {
                // tolerate.
            }
        }

        // 5. ACIE/ACOL runtime blockers persisted in Hyperflow metadata.
        if (DatabaseTableAvailability::has('ai_traces') && $traceIds !== []) {
            try {
                $traces = AiTrace::query()
                    ->whereIn('id', $traceIds)
                    ->limit(AtlasAiControlPlaneService::BLOCKER_LIMIT)
                    ->get(['id', 'metadata']);
                foreach ($traces as $trace) {
                    $metadata = is_array($trace->metadata) ? $trace->metadata : [];
                    $operations = (array) data_get($metadata, 'hyperflow_runtime.context_operations', []);
                    if (($operations['status'] ?? null) !== 'blocked') {
                        continue;
                    }
                    $blockers[] = [
                        'kind' => 'context_operations_blocked',
                        'class' => 'system_blocker',
                        'severity' => 'critical',
                        'trace_id' => (string) $trace->id,
                        'flow_id' => $this->support->flowIdFromTrace($trace),
                        'detail' => 'ACIE/ACOL operations runtime marked the flow blocked',
                    ];
                    if (count($blockers) >= AtlasAiControlPlaneService::BLOCKER_LIMIT) {
                        break;
                    }
                }
            } catch (Throwable) {
                // tolerate.
            }
        }

        return $blockers;
    }

    /**
     * @param  array<int,array<string,mixed>>  $blockers
     * @return array{system_blockers_count:int,operator_queue_count:int,clarification_queue_count:int}
     */
    public function classifyBlockers(array $blockers): array
    {
        $summary = [
            'system_blockers_count' => 0,
            'operator_queue_count' => 0,
            'clarification_queue_count' => 0,
        ];

        foreach ($blockers as $blocker) {
            $class = $this->blockerClass($blocker);
            if ($class === 'system_blocker') {
                $summary['system_blockers_count']++;
            } else {
                $summary['operator_queue_count']++;
            }

            if ($this->isClarificationBlocker($blocker)) {
                $summary['clarification_queue_count']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string,mixed>  $blocker
     */
    public function blockerClass(array $blocker): string
    {
        $class = $this->support->stringOrNull($blocker['class'] ?? null);
        if ($class === 'system_blocker' || $class === 'operator_queue') {
            return $class;
        }

        return match ($this->support->stringOrNull($blocker['kind'] ?? null)) {
            'silent_failure',
            'quality_failed',
            'context_operations_blocked',
            'persistent_context_blocked' => 'system_blocker',
            'handoff_incomplete' => 'operator_queue',
            'dispatch_blocker' => $this->dispatchBlockerClass($this->support->stringOrNull($blocker['detail'] ?? null) ?? ''),
            default => 'system_blocker',
        };
    }

    public function dispatchBlockerClass(string $detail): string
    {
        return str_starts_with($detail, 'clarification_needed')
            || str_contains($detail, 'high_ambiguity')
            || str_contains($detail, 'operator_review')
            || str_contains($detail, 'approval_required')
            ? 'operator_queue'
            : 'system_blocker';
    }

    /**
     * @param  array<string,mixed>  $blocker
     */
    public function isClarificationBlocker(array $blocker): bool
    {
        $detail = $this->support->stringOrNull($blocker['detail'] ?? null) ?? '';

        return ($this->support->stringOrNull($blocker['kind'] ?? null) === 'dispatch_blocker')
            && (str_starts_with($detail, 'clarification_needed') || str_contains($detail, 'high_ambiguity'));
    }
}
