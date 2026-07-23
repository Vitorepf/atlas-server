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

final class SubsystemSection
{
    public function __construct(private readonly ControlPlaneSupport $support) {}

    /**
     * Operator Approval Gate observability section.
     *
     * Mirrors {@see OperatorApprovalGateService::controlPlaneSnapshot()} but
     * windowed by the report's `since` so the runtime control plane stays
     * consistent with the rest of the report. Tolerates missing table.
     *
     * @return array<string,mixed>
     */
    public function approvalsSection(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'totals' => ['all' => 0, 'pending' => 0, 'approved' => 0, 'denied' => 0, 'expired' => 0, 'cancelled' => 0, 'auto_approved' => 0],
            'by_status' => [],
            'by_mode' => [],
            'by_risk' => [],
            'recent_pending' => [],
            'recent_decisions' => [],
            'last_decided_at' => null,
        ];

        if (! DatabaseTableAvailability::has('ai_operator_approvals')) {
            return $empty;
        }

        try {
            $approvals = AiOperatorApproval::query()
                ->where(function ($query) use ($since): void {
                    $query->where('created_at', '>=', $since)
                        ->orWhere('decided_at', '>=', $since);
                })
                ->get([
                    'id', 'uuid', 'mission_id', 'trace_id', 'job_id',
                    'requested_action', 'risk_level', 'gate_mode', 'status',
                    'operator_decision', 'operator', 'reason',
                    'expires_at', 'decided_at', 'receipt_hash', 'hash', 'created_at',
                ]);
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        $byStatus = [];
        $byMode = [];
        $byRisk = [];
        $pending = [];
        $decisions = [];
        $lastDecidedAt = null;

        foreach ($approvals as $approval) {
            $status = $this->support->stringOrNull($approval->status) ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $mode = $this->support->stringOrNull($approval->gate_mode) ?? 'unknown';
            $byMode[$mode] = ($byMode[$mode] ?? 0) + 1;
            $risk = $this->support->stringOrNull($approval->risk_level) ?? 'unknown';
            $byRisk[$risk] = ($byRisk[$risk] ?? 0) + 1;

            $serialized = [
                'uuid' => $approval->uuid,
                'mission_id' => $this->support->stringOrNull($approval->mission_id),
                'trace_id' => $this->support->stringOrNull($approval->trace_id),
                'requested_action' => $this->support->stringOrNull($approval->requested_action),
                'gate_mode' => $mode,
                'risk_level' => $risk,
                'status' => $status,
                'operator_decision' => $this->support->stringOrNull($approval->operator_decision),
                'operator' => $this->support->stringOrNull($approval->operator),
                'reason' => $this->support->truncate($approval->reason, 200),
                'expires_at' => $approval->expires_at?->toJSON(),
                'decided_at' => $approval->decided_at?->toJSON(),
                'receipt_hash' => $this->support->stringOrNull($approval->receipt_hash),
                'hash' => $this->support->stringOrNull($approval->hash),
                'created_at' => $approval->created_at?->toJSON(),
            ];

            if ($status === OperatorApprovalCanon::STATUS_PENDING && count($pending) < AtlasAiControlPlaneService::RECENT_LIMIT) {
                $pending[] = $serialized;
            }
            if (in_array($status, [OperatorApprovalCanon::STATUS_APPROVED, OperatorApprovalCanon::STATUS_DENIED, OperatorApprovalCanon::STATUS_EXPIRED, OperatorApprovalCanon::STATUS_CANCELLED], true)) {
                if (count($decisions) < AtlasAiControlPlaneService::RECENT_LIMIT) {
                    $decisions[] = $serialized;
                }
                $decidedAt = $approval->decided_at?->toJSON();
                if ($decidedAt !== null && ($lastDecidedAt === null || $decidedAt > $lastDecidedAt)) {
                    $lastDecidedAt = $decidedAt;
                }
            }
        }

        return [
            'status' => 'ready',
            'totals' => [
                'all' => count($approvals),
                'pending' => (int) ($byStatus[OperatorApprovalCanon::STATUS_PENDING] ?? 0),
                'approved' => (int) ($byStatus[OperatorApprovalCanon::STATUS_APPROVED] ?? 0),
                'denied' => (int) ($byStatus[OperatorApprovalCanon::STATUS_DENIED] ?? 0),
                'expired' => (int) ($byStatus[OperatorApprovalCanon::STATUS_EXPIRED] ?? 0),
                'cancelled' => (int) ($byStatus[OperatorApprovalCanon::STATUS_CANCELLED] ?? 0),
                'auto_approved' => (int) ($byStatus[OperatorApprovalCanon::STATUS_AUTO_APPROVED] ?? 0),
            ],
            'by_status' => $byStatus,
            'by_mode' => $byMode,
            'by_risk' => $byRisk,
            'recent_pending' => $pending,
            'recent_decisions' => $decisions,
            'last_decided_at' => $lastDecidedAt,
        ];
    }

    /**
     * AEMOR read model. This is intentionally aggregate-only: no raw prompt,
     * no response text, no provider output.
     *
     * @return array<string,mixed>
     */
    public function aemor(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'summary' => [
                'episodes_total' => 0,
                'open' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'blocked' => 0,
                'learning_signals' => 0,
                'memory_candidates' => 0,
                'judgment_reports' => 0,
            ],
            'recent_blockers' => [],
            'recent_judgments' => [],
        ];
        if (! DatabaseTableAvailability::has('atlas_aemor_execution_episodes')) {
            return $empty;
        }

        try {
            $episodes = AtlasAemorExecutionEpisode::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'scope_type', 'scope_id', 'flow_id', 'episode_hash', 'created_at']);
            $outcomes = DatabaseTableAvailability::has('atlas_aemor_outcomes')
                ? AtlasAemorOutcome::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'episode_id', 'status', 'failure_signature', 'outcome_hash', 'created_at'])
                : collect();
            $judgments = DatabaseTableAvailability::has('atlas_aemor_judgment_reports')
                ? AtlasAemorJudgmentReport::query()->where('created_at', '>=', $since)->latest()->limit(AtlasAiControlPlaneService::RECENT_LIMIT)->get(['id', 'episode_id', 'outcome_id', 'status', 'quality_score', 'judgment_hash', 'created_at'])
                : collect();
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        return [
            'status' => 'ready',
            'summary' => [
                'episodes_total' => $episodes->count(),
                'open' => $episodes->where('status', 'open')->count(),
                'succeeded' => $outcomes->where('status', 'succeeded')->count(),
                'failed' => $outcomes->where('status', 'failed')->count(),
                'blocked' => $outcomes->where('status', 'blocked')->count(),
                'learning_signals' => DatabaseTableAvailability::has('atlas_aemor_learning_signals') ? AtlasAemorLearningSignal::query()->where('created_at', '>=', $since)->count() : 0,
                'memory_candidates' => DatabaseTableAvailability::has('atlas_aemor_memory_candidates') ? AtlasAemorMemoryCandidate::query()->where('created_at', '>=', $since)->count() : 0,
                'judgment_reports' => $judgments->count(),
            ],
            'recent_blockers' => $outcomes
                ->filter(fn (AtlasAemorOutcome $outcome): bool => in_array($outcome->status, ['failed', 'blocked'], true))
                ->take(AtlasAiControlPlaneService::RECENT_LIMIT)
                ->map(fn (AtlasAemorOutcome $outcome): array => [
                    'episode_id' => (string) $outcome->episode_id,
                    'outcome_id' => (string) $outcome->id,
                    'status' => (string) $outcome->status,
                    'failure_signature' => $this->support->stringOrNull($outcome->failure_signature),
                    'outcome_hash' => $this->support->stringOrNull($outcome->outcome_hash),
                    'created_at' => $outcome->created_at?->toJSON(),
                ])
                ->values()
                ->all(),
            'recent_judgments' => $judgments
                ->map(fn (AtlasAemorJudgmentReport $report): array => [
                    'episode_id' => (string) $report->episode_id,
                    'outcome_id' => $this->support->stringOrNull($report->outcome_id),
                    'status' => (string) $report->status,
                    'quality_status' => $this->support->stringOrNull(data_get($report->quality_score, 'status')),
                    'quality_score' => data_get($report->quality_score, 'score'),
                    'judgment_hash' => $this->support->stringOrNull($report->judgment_hash),
                    'created_at' => $report->created_at?->toJSON(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * ASEIF read model. Aggregate-only; no raw prompts or provider output.
     *
     * @return array<string,mixed>
     */
    public function intelligenceFactory(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'summary' => [
                'capabilities_total' => 0,
                'certified_capabilities' => 0,
                'open_gaps' => 0,
                'blocked_decisions' => 0,
                'simulations_total' => 0,
                'blocked_simulations' => 0,
                'evolution_events_total' => 0,
                'capability_used_events' => 0,
            ],
            'recent_gaps' => [],
            'recent_decisions' => [],
            'recent_evolution_events' => [],
        ];
        if (! DatabaseTableAvailability::has('atlas_intelligence_factory_capabilities')) {
            return $empty;
        }

        try {
            $capabilities = AtlasIntelligenceFactoryCapability::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'capability_key', 'capability_type', 'domain', 'flow_id', 'certification_hash', 'created_at']);
            $gaps = DatabaseTableAvailability::has('atlas_intelligence_factory_gaps')
                ? AtlasIntelligenceFactoryGap::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'gap_type', 'severity', 'gap_hash', 'created_at'])
                : collect();
            $decisions = DatabaseTableAvailability::has('atlas_intelligence_factory_decisions')
                ? AtlasIntelligenceFactoryDecision::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'decision', 'status', 'decision_hash', 'created_at'])
                : collect();
            $simulations = DatabaseTableAvailability::has('atlas_intelligence_factory_simulations')
                ? AtlasIntelligenceFactorySimulation::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'mode', 'simulation_hash', 'created_at'])
                : collect();
            $evolutionEvents = DatabaseTableAvailability::has('atlas_intelligence_factory_evolution_events')
                ? AtlasIntelligenceFactoryEvolutionEvent::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'capability_id', 'source_type', 'event_type', 'status', 'event_hash', 'created_at'])
                : collect();
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        return [
            'status' => 'ready',
            'summary' => [
                'capabilities_total' => $capabilities->count(),
                'certified_capabilities' => $capabilities->where('status', 'certified')->count(),
                'open_gaps' => $gaps->where('status', 'open')->count(),
                'blocked_decisions' => $decisions->where('status', 'blocked')->count(),
                'simulations_total' => $simulations->count(),
                'blocked_simulations' => $simulations->where('status', 'blocked')->count(),
                'evolution_events_total' => $evolutionEvents->count(),
                'capability_used_events' => $evolutionEvents->where('event_type', 'capability_used')->count(),
            ],
            'recent_gaps' => $gaps->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasIntelligenceFactoryGap $gap): array => [
                'gap_id' => (string) $gap->id,
                'status' => (string) $gap->status,
                'gap_type' => (string) $gap->gap_type,
                'severity' => (string) $gap->severity,
                'gap_hash' => $this->support->stringOrNull($gap->gap_hash),
                'created_at' => $gap->created_at?->toJSON(),
            ])->values()->all(),
            'recent_decisions' => $decisions->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasIntelligenceFactoryDecision $decision): array => [
                'decision_id' => (string) $decision->id,
                'decision' => (string) $decision->decision,
                'status' => (string) $decision->status,
                'decision_hash' => $this->support->stringOrNull($decision->decision_hash),
                'created_at' => $decision->created_at?->toJSON(),
            ])->values()->all(),
            'recent_evolution_events' => $evolutionEvents->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasIntelligenceFactoryEvolutionEvent $event): array => [
                'event_id' => (string) $event->id,
                'capability_id' => $this->support->stringOrNull($event->capability_id),
                'source_type' => $this->support->stringOrNull($event->source_type),
                'event_type' => (string) $event->event_type,
                'status' => (string) $event->status,
                'event_hash' => $this->support->stringOrNull($event->event_hash),
                'created_at' => $event->created_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    /**
     * ASRE read model. Aggregate-only; no raw strategic question text.
     *
     * @return array<string,mixed>
     */
    public function strategicReality(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'summary' => [
                'reality_entities_total' => 0,
                'strategic_decisions_total' => 0,
                'ready_decisions' => 0,
                'watch_decisions' => 0,
                'blocked_decisions' => 0,
                'opportunities_total' => 0,
                'risks_total' => 0,
                'critical_risks' => 0,
                'executive_briefings_total' => 0,
            ],
            'recent_decisions' => [],
            'recent_risks' => [],
        ];
        if (! DatabaseTableAvailability::has('atlas_strategic_decisions')) {
            return $empty;
        }

        try {
            $entities = DatabaseTableAvailability::has('atlas_reality_entities')
                ? AtlasRealityEntity::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'entity_type', 'entity_hash', 'created_at'])
                : collect();
            $decisions = AtlasStrategicDecision::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(200)
                ->get(['id', 'status', 'question_hash', 'recommended_action', 'confidence', 'decision_hash', 'created_at']);
            $opportunities = DatabaseTableAvailability::has('atlas_opportunity_signals')
                ? AtlasOpportunitySignal::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'opportunity_type', 'opportunity_hash', 'created_at'])
                : collect();
            $risks = DatabaseTableAvailability::has('atlas_risk_signals')
                ? AtlasRiskSignal::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'risk_type', 'severity', 'risk_hash', 'created_at'])
                : collect();
            $briefings = DatabaseTableAvailability::has('atlas_executive_briefings')
                ? AtlasExecutiveBriefing::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'briefing_hash', 'created_at'])
                : collect();
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        return [
            'status' => $decisions->where('status', 'blocked')->isNotEmpty() || $risks->where('severity', 'critical')->isNotEmpty() ? 'watch' : 'ready',
            'summary' => [
                'reality_entities_total' => $entities->count(),
                'strategic_decisions_total' => $decisions->count(),
                'ready_decisions' => $decisions->where('status', 'ready')->count(),
                'watch_decisions' => $decisions->where('status', 'watch')->count(),
                'blocked_decisions' => $decisions->where('status', 'blocked')->count(),
                'opportunities_total' => $opportunities->count(),
                'risks_total' => $risks->count(),
                'critical_risks' => $risks->where('severity', 'critical')->count(),
                'executive_briefings_total' => $briefings->count(),
            ],
            'recent_decisions' => $decisions->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasStrategicDecision $decision): array => [
                'decision_id' => (string) $decision->id,
                'status' => (string) $decision->status,
                'question_hash' => $this->support->stringOrNull($decision->question_hash),
                'recommended_action_excerpt' => $this->support->truncate($decision->recommended_action, 160),
                'confidence' => $decision->confidence,
                'decision_hash' => $this->support->stringOrNull($decision->decision_hash),
                'created_at' => $decision->created_at?->toJSON(),
            ])->values()->all(),
            'recent_risks' => $risks->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasRiskSignal $risk): array => [
                'risk_id' => (string) $risk->id,
                'risk_type' => (string) $risk->risk_type,
                'severity' => (string) $risk->severity,
                'risk_hash' => $this->support->stringOrNull($risk->risk_hash),
                'created_at' => $risk->created_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    /**
     * AREG read model. Aggregate-only; never exposes raw prompts.
     *
     * @return array<string,mixed>
     */
    public function runtimeEfficiency(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'summary' => [
                'decisions_total' => 0,
                'ready' => 0,
                'watch' => 0,
                'blocked' => 0,
                'fast_path' => 0,
                'standard_path' => 0,
                'deep_path' => 0,
                'forge_path' => 0,
                'average_context_budget_tokens' => null,
                'outcomes_total' => 0,
            ],
            'by_flow' => [],
            'recent_decisions' => [],
        ];
        if (! DatabaseTableAvailability::has('atlas_runtime_efficiency_decisions')) {
            return $empty;
        }

        try {
            $decisions = AtlasRuntimeEfficiencyDecision::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(200)
                ->get(['id', 'status', 'domain', 'flow_id', 'path', 'prompt_hash', 'context_budget_tokens', 'decision_hash', 'created_at']);
            $outcomes = DatabaseTableAvailability::has('atlas_runtime_efficiency_outcomes')
                ? AtlasRuntimeEfficiencyOutcome::query()->where('created_at', '>=', $since)->limit(200)->get(['id'])
                : collect();
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        $byFlow = [];
        foreach ($decisions as $decision) {
            $flowId = $this->support->stringOrNull($decision->flow_id) ?? 'unknown';
            $byFlow[$flowId] = ($byFlow[$flowId] ?? 0) + 1;
        }
        ksort($byFlow);

        return [
            'status' => $decisions->where('status', 'blocked')->isNotEmpty() ? 'watch' : 'ready',
            'summary' => [
                'decisions_total' => $decisions->count(),
                'ready' => $decisions->where('status', 'ready')->count(),
                'watch' => $decisions->where('status', 'watch')->count(),
                'blocked' => $decisions->where('status', 'blocked')->count(),
                'fast_path' => $decisions->where('path', 'fast_path')->count(),
                'standard_path' => $decisions->where('path', 'standard_path')->count(),
                'deep_path' => $decisions->where('path', 'deep_path')->count(),
                'forge_path' => $decisions->where('path', 'forge_path')->count(),
                'average_context_budget_tokens' => $decisions->isEmpty() ? null : round((float) $decisions->avg('context_budget_tokens'), 2),
                'outcomes_total' => $outcomes->count(),
            ],
            'by_flow' => $byFlow,
            'recent_decisions' => $decisions->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasRuntimeEfficiencyDecision $decision): array => [
                'decision_id' => (string) $decision->id,
                'status' => (string) $decision->status,
                'domain' => $this->support->stringOrNull($decision->domain),
                'flow_id' => $this->support->stringOrNull($decision->flow_id),
                'path' => (string) $decision->path,
                'prompt_hash' => $this->support->stringOrNull($decision->prompt_hash),
                'context_budget_tokens' => (int) $decision->context_budget_tokens,
                'decision_hash' => $this->support->stringOrNull($decision->decision_hash),
                'created_at' => $decision->created_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    /**
     * AAWR read model. It is planning-only: exposes organizational contracts,
     * topology counts and outcome learning without raw objectives.
     *
     * @return array<string,mixed>
     */
    public function agenticWorkcell(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'summary' => [
                'workcells_total' => 0,
                'ready' => 0,
                'watch' => 0,
                'blocked' => 0,
                'outcomes_total' => 0,
                'org_patterns_total' => 0,
                'average_quality_score' => null,
                'average_coordination_roi_score' => null,
            ],
            'by_topology' => [],
            'by_flow' => [],
            'recent_workcells' => [],
            'recent_patterns' => [],
        ];
        if (! DatabaseTableAvailability::has('atlas_agentic_workcells')) {
            return $empty;
        }

        try {
            $workcells = AtlasAgenticWorkcell::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(200)
                ->get(['id', 'status', 'domain', 'flow_id', 'topology', 'maturity_level', 'objective_hash', 'workcell_hash', 'created_at']);
            $outcomes = DatabaseTableAvailability::has('atlas_agentic_workcell_outcomes')
                ? AtlasAgenticWorkcellOutcome::query()->where('created_at', '>=', $since)->limit(200)->get(['id', 'quality_score', 'coordination_roi_score'])
                : collect();
            $patterns = DatabaseTableAvailability::has('atlas_agentic_workcell_org_patterns')
                ? AtlasAgenticWorkcellOrgPattern::query()->where('created_at', '>=', $since)->latest()->limit(50)->get(['id', 'status', 'flow_id', 'topology', 'pattern_hash'])
                : collect();
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        return [
            'status' => $workcells->where('status', 'blocked')->isNotEmpty() ? 'watch' : 'ready',
            'summary' => [
                'workcells_total' => $workcells->count(),
                'ready' => $workcells->where('status', 'ready')->count(),
                'watch' => $workcells->where('status', 'watch')->count(),
                'blocked' => $workcells->where('status', 'blocked')->count(),
                'outcomes_total' => $outcomes->count(),
                'org_patterns_total' => $patterns->count(),
                'average_quality_score' => $outcomes->isEmpty() ? null : round((float) $outcomes->avg('quality_score'), 2),
                'average_coordination_roi_score' => $outcomes->isEmpty() ? null : round((float) $outcomes->avg('coordination_roi_score'), 2),
            ],
            'by_topology' => $this->support->countsBy($workcells, 'topology'),
            'by_flow' => $this->support->countsBy($workcells, 'flow_id'),
            'recent_workcells' => $workcells->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasAgenticWorkcell $workcell): array => [
                'workcell_id' => (string) $workcell->id,
                'status' => (string) $workcell->status,
                'domain' => $this->support->stringOrNull($workcell->domain),
                'flow_id' => $this->support->stringOrNull($workcell->flow_id),
                'topology' => (string) $workcell->topology,
                'maturity_level' => (string) $workcell->maturity_level,
                'objective_hash' => $this->support->stringOrNull($workcell->objective_hash),
                'workcell_hash' => $this->support->stringOrNull($workcell->workcell_hash),
                'created_at' => $workcell->created_at?->toJSON(),
            ])->values()->all(),
            'recent_patterns' => $patterns->take(10)->map(fn (AtlasAgenticWorkcellOrgPattern $pattern): array => [
                'pattern_id' => (string) $pattern->id,
                'status' => (string) $pattern->status,
                'flow_id' => $this->support->stringOrNull($pattern->flow_id),
                'topology' => (string) $pattern->topology,
                'pattern_hash' => $this->support->stringOrNull($pattern->pattern_hash),
            ])->values()->all(),
        ];
    }

    /**
     * AWEOS read model. No raw objective, no provider output.
     *
     * @return array<string,mixed>
     */
    public function autonomousWorkExecution(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'summary' => [
                'executions_total' => 0,
                'ready' => 0,
                'watch' => 0,
                'blocked' => 0,
                'certified' => 0,
                'certified_outcomes_total' => 0,
                'gold_outcomes' => 0,
                'silver_outcomes' => 0,
                'bronze_outcomes' => 0,
            ],
            'by_flow' => [],
            'recent_executions' => [],
        ];
        if (! DatabaseTableAvailability::has('atlas_aweos_executions')) {
            return $empty;
        }

        try {
            $executions = AtlasAweosExecution::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(200)
                ->get(['id', 'status', 'maturity_level', 'domain', 'flow_id', 'objective_hash', 'execution_hash', 'strategic_next_action', 'created_at']);
            $outcomes = DatabaseTableAvailability::has('atlas_aweos_certified_outcomes')
                ? AtlasAweosCertifiedOutcome::query()->where('created_at', '>=', $since)->latest()->limit(100)->get(['id', 'status', 'certification_level', 'outcome_hash'])
                : collect();
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        return [
            'status' => $executions->where('status', 'blocked')->isNotEmpty() ? 'watch' : 'ready',
            'summary' => [
                'executions_total' => $executions->count(),
                'ready' => $executions->where('status', 'ready')->count(),
                'watch' => $executions->where('status', 'watch')->count(),
                'blocked' => $executions->where('status', 'blocked')->count(),
                'certified' => $executions->where('status', 'certified')->count(),
                'certified_outcomes_total' => $outcomes->count(),
                'gold_outcomes' => $outcomes->where('certification_level', 'gold')->count(),
                'silver_outcomes' => $outcomes->where('certification_level', 'silver')->count(),
                'bronze_outcomes' => $outcomes->where('certification_level', 'bronze')->count(),
            ],
            'by_flow' => $executions->groupBy(fn (AtlasAweosExecution $execution): string => (string) ($execution->flow_id ?? 'unknown'))->map(fn ($items): int => $items->count())->sortKeys()->all(),
            'recent_executions' => $executions->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasAweosExecution $execution): array => [
                'execution_id' => (string) $execution->id,
                'status' => (string) $execution->status,
                'maturity_level' => (string) $execution->maturity_level,
                'domain' => $this->support->stringOrNull($execution->domain),
                'flow_id' => $this->support->stringOrNull($execution->flow_id),
                'objective_hash' => $this->support->stringOrNull($execution->objective_hash),
                'execution_hash' => $this->support->stringOrNull($execution->execution_hash),
                'next_action' => $this->support->stringOrNull(data_get($execution->strategic_next_action, 'next_action')),
                'created_at' => $execution->created_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    /**
     * AVER read model. No raw objectives, command text, stdout or stderr.
     *
     * @return array<string,mixed>
     */
    public function verifiedExecution(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'summary' => [
                'executions_total' => 0,
                'ready' => 0,
                'blocked' => 0,
                'certified' => 0,
                'certifications_total' => 0,
                'gold_certifications' => 0,
            ],
            'by_flow' => [],
            'recent_executions' => [],
        ];
        if (! DatabaseTableAvailability::has('atlas_aver_executions')) {
            return $empty;
        }

        try {
            $executions = AtlasAverExecution::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(200)
                ->get(['id', 'status', 'maturity_level', 'domain', 'flow_id', 'objective_hash', 'execution_hash', 'aweos_execution_id', 'created_at']);
            $certifications = DatabaseTableAvailability::has('atlas_aver_certified_executions')
                ? AtlasAverCertifiedExecution::query()->where('created_at', '>=', $since)->latest()->limit(100)->get(['id', 'status', 'certification_level', 'certification_hash'])
                : collect();
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        return [
            'status' => $executions->where('status', 'blocked')->isNotEmpty() ? 'watch' : 'ready',
            'summary' => [
                'executions_total' => $executions->count(),
                'ready' => $executions->where('status', 'ready')->count(),
                'blocked' => $executions->where('status', 'blocked')->count(),
                'certified' => $executions->where('status', 'certified')->count(),
                'certifications_total' => $certifications->count(),
                'gold_certifications' => $certifications->where('certification_level', 'gold')->count(),
            ],
            'by_flow' => $this->support->countsBy($executions, 'flow_id'),
            'recent_executions' => $executions->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasAverExecution $execution): array => [
                'execution_id' => (string) $execution->id,
                'aweos_execution_id' => $this->support->stringOrNull($execution->aweos_execution_id),
                'status' => (string) $execution->status,
                'maturity_level' => (string) $execution->maturity_level,
                'domain' => $this->support->stringOrNull($execution->domain),
                'flow_id' => $this->support->stringOrNull($execution->flow_id),
                'objective_hash' => $this->support->stringOrNull($execution->objective_hash),
                'execution_hash' => $this->support->stringOrNull($execution->execution_hash),
                'created_at' => $execution->created_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    /**
     * AAEL read model. Aggregate-only; no raw objectives or operator prompts.
     *
     * @return array<string,mixed>
     */
    public function autonomousEvolution(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'summary' => [
                'opportunities_total' => 0,
                'cycles_total' => 0,
                'experiments_total' => 0,
                'promotion_decisions_total' => 0,
                'audit_reports_total' => 0,
                'operator_review_required' => 0,
                'blocked' => 0,
            ],
            'recent_cycles' => [],
            'recent_decisions' => [],
        ];
        if (! DatabaseTableAvailability::has('atlas_aael_portfolio_cycles')) {
            return $empty;
        }

        try {
            $opportunities = DatabaseTableAvailability::has('atlas_aael_opportunities')
                ? AtlasAaelOpportunity::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'opportunity_type', 'risk_level', 'priority_score', 'opportunity_hash'])
                : collect();
            $cycles = AtlasAaelPortfolioCycle::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(100)
                ->get(['id', 'status', 'portfolio_snapshot', 'cycle_hash', 'created_at']);
            $experiments = DatabaseTableAvailability::has('atlas_aael_evolution_experiments')
                ? AtlasAaelEvolutionExperiment::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'lane', 'experiment_hash'])
                : collect();
            $decisions = DatabaseTableAvailability::has('atlas_aael_promotion_decisions')
                ? AtlasAaelPromotionDecision::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'trust_level', 'decision_hash', 'created_at'])
                : collect();
            $audits = DatabaseTableAvailability::has('atlas_aael_audit_reports')
                ? AtlasAaelAuditReport::query()->where('created_at', '>=', $since)->latest()->limit(100)->get(['id', 'status', 'audit_hash'])
                : collect();
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        return [
            'status' => $decisions->where('status', 'blocked')->isNotEmpty() ? 'watch' : 'ready',
            'summary' => [
                'opportunities_total' => $opportunities->count(),
                'cycles_total' => $cycles->count(),
                'experiments_total' => $experiments->count(),
                'promotion_decisions_total' => $decisions->count(),
                'audit_reports_total' => $audits->count(),
                'operator_review_required' => $decisions->where('status', 'operator_review_required')->count(),
                'blocked' => $decisions->where('status', 'blocked')->count(),
            ],
            'by_opportunity_type' => $this->support->countsBy($opportunities, 'opportunity_type'),
            'by_experiment_lane' => $this->support->countsBy($experiments, 'lane'),
            'recent_cycles' => $cycles->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasAaelPortfolioCycle $cycle): array => [
                'cycle_id' => (string) $cycle->id,
                'status' => (string) $cycle->status,
                'selected_count' => (int) data_get($cycle->portfolio_snapshot, 'selected_count', 0),
                'operator_queue_count' => (int) data_get($cycle->portfolio_snapshot, 'operator_queue_count', 0),
                'cycle_hash' => $this->support->stringOrNull($cycle->cycle_hash),
                'created_at' => $cycle->created_at?->toJSON(),
            ])->values()->all(),
            'recent_decisions' => $decisions->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasAaelPromotionDecision $decision): array => [
                'decision_id' => (string) $decision->id,
                'status' => (string) $decision->status,
                'trust_level' => (string) $decision->trust_level,
                'decision_hash' => $this->support->stringOrNull($decision->decision_hash),
                'created_at' => $decision->created_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    /**
     * AARS read model. Aggregate-only; no raw objectives or scenario text.
     *
     * @return array<string,mixed>
     */
    public function autonomousRealitySandbox(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'summary' => [
                'scenarios_total' => 0,
                'simulations_total' => 0,
                'risk_projections_total' => 0,
                'certifications_total' => 0,
                'high_risk' => 0,
                'blocked' => 0,
            ],
            'recent_scenarios' => [],
            'recent_certifications' => [],
        ];
        if (! DatabaseTableAvailability::has('atlas_aars_scenarios')) {
            return $empty;
        }

        try {
            $scenarios = AtlasAarsScenario::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(100)
                ->get(['id', 'status', 'domain', 'flow_id', 'scenario_type', 'objective_hash', 'scenario_hash', 'created_at']);
            $simulations = DatabaseTableAvailability::has('atlas_aars_simulations')
                ? AtlasAarsSimulation::query()->where('created_at', '>=', $since)->latest()->limit(100)->get(['id', 'status', 'mode', 'simulation_hash'])
                : collect();
            $risks = DatabaseTableAvailability::has('atlas_aars_risk_projections')
                ? AtlasAarsRiskProjection::query()->where('created_at', '>=', $since)->latest()->limit(100)->get(['id', 'status', 'risk_level', 'risk_hash'])
                : collect();
            $certifications = DatabaseTableAvailability::has('atlas_aars_certifications')
                ? AtlasAarsCertification::query()->where('created_at', '>=', $since)->latest()->limit(100)->get(['id', 'status', 'certification_hash', 'created_at'])
                : collect();
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        $blocked = $risks->where('status', 'blocked')->count() + $certifications->where('status', 'blocked')->count();
        $highRisk = $risks->whereIn('risk_level', ['high', 'critical'])->count();

        return [
            'status' => $blocked > 0 ? 'blocked' : ($highRisk > 0 ? 'watch' : 'ready'),
            'summary' => [
                'scenarios_total' => $scenarios->count(),
                'simulations_total' => $simulations->count(),
                'risk_projections_total' => $risks->count(),
                'certifications_total' => $certifications->count(),
                'high_risk' => $highRisk,
                'blocked' => $blocked,
            ],
            'by_scenario_type' => $this->support->countsBy($scenarios, 'scenario_type'),
            'by_risk_level' => $this->support->countsBy($risks, 'risk_level'),
            'recent_scenarios' => $scenarios->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasAarsScenario $scenario): array => [
                'scenario_id' => (string) $scenario->id,
                'status' => (string) $scenario->status,
                'domain' => $this->support->stringOrNull($scenario->domain),
                'flow_id' => $this->support->stringOrNull($scenario->flow_id),
                'scenario_type' => (string) $scenario->scenario_type,
                'objective_hash' => $this->support->stringOrNull($scenario->objective_hash),
                'scenario_hash' => $this->support->stringOrNull($scenario->scenario_hash),
                'created_at' => $scenario->created_at?->toJSON(),
            ])->values()->all(),
            'recent_certifications' => $certifications->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AtlasAarsCertification $certification): array => [
                'certification_id' => (string) $certification->id,
                'status' => (string) $certification->status,
                'certification_hash' => $this->support->stringOrNull($certification->certification_hash),
                'created_at' => $certification->created_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    /**
     * Swarm/Company read model. This does not start agents; it proves whether
     * the Atlas agent/company substrate is visible as an operational runtime:
     * roles, releases, certifications and agent-control services.
     *
     * @return array<string,mixed>
     */
    public function swarmCompany(CarbonImmutable $since): array
    {
        $tables = [
            'engagements' => DatabaseTableAvailability::has('ai_engineering_company_engagements'),
            'role_runs' => DatabaseTableAvailability::has('ai_engineering_company_role_runs'),
            'release_packs' => DatabaseTableAvailability::has('ai_engineering_company_release_packs'),
            'certifications' => DatabaseTableAvailability::has('ai_engineering_company_certifications'),
        ];
        $agentRuntimeClasses = [
            'scheduler' => class_exists(AgentControlPlaneTaskQueueOrchestrator::class),
            'task_packet_builder' => class_exists(AgentControlPlaneTaskPacketBuilder::class),
            'handoff_protocol' => class_exists(AgentRuntimeRegistryHandoffProtocolBuilder::class),
            'critic_validation' => class_exists(AgentValidationGateDryRunEvaluator::class),
            'merge_review' => class_exists(AgentMergeReviewPacketBuilder::class),
        ];

        $empty = [
            'status' => in_array(false, $tables, true) ? 'missing' : 'ready',
            'tables' => $tables,
            'agent_runtime_classes' => $agentRuntimeClasses,
            'summary' => [
                'engagements_total' => 0,
                'role_runs_total' => 0,
                'blocked_role_runs' => 0,
                'release_packs_total' => 0,
                'ready_release_packs' => 0,
                'blocked_release_packs' => 0,
                'certifications_total' => 0,
                'passed_certifications' => 0,
            ],
            'recent_engagements' => [],
        ];
        if (in_array(false, $tables, true)) {
            return $empty;
        }

        try {
            $engagements = AiEngineeringCompanyEngagement::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(AtlasAiControlPlaneService::RECENT_LIMIT)
                ->get(['id', 'engagement_id', 'status', 'receipt_hash', 'created_at']);
            $roleRuns = AiEngineeringCompanyRoleRun::query()
                ->where('created_at', '>=', $since)
                ->limit(500)
                ->get(['id', 'role_id', 'status']);
            $releasePacks = AiEngineeringCompanyReleasePack::query()
                ->where('created_at', '>=', $since)
                ->limit(200)
                ->get(['id', 'status', 'release_hash']);
            $certifications = AiEngineeringCompanyCertification::query()
                ->where('created_at', '>=', $since)
                ->limit(200)
                ->get(['id', 'status', 'certification_hash']);
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        return [
            'status' => in_array(false, $agentRuntimeClasses, true) ? 'degraded' : 'ready',
            'tables' => $tables,
            'agent_runtime_classes' => $agentRuntimeClasses,
            'summary' => [
                'engagements_total' => $engagements->count(),
                'role_runs_total' => $roleRuns->count(),
                'blocked_role_runs' => $roleRuns->where('status', 'blocked')->count(),
                'release_packs_total' => $releasePacks->count(),
                'ready_release_packs' => $releasePacks->where('status', 'ready_for_internal_delivery')->count(),
                'blocked_release_packs' => $releasePacks->where('status', 'blocked')->count(),
                'certifications_total' => $certifications->count(),
                'passed_certifications' => $certifications->where('status', 'passed')->count(),
            ],
            'recent_engagements' => $engagements->map(fn (AiEngineeringCompanyEngagement $engagement): array => [
                'engagement_id' => $this->support->stringOrNull($engagement->engagement_id),
                'status' => $this->support->stringOrNull($engagement->status),
                'receipt_hash' => $this->support->stringOrNull($engagement->receipt_hash),
                'created_at' => $engagement->created_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    /**
     * Governed external execution read model. It shows mandates, cutover work
     * and runtime invocations without enabling external side effects.
     *
     * @return array<string,mixed>
     */
    public function externalExecution(CarbonImmutable $since): array
    {
        $tables = [
            'mandates' => DatabaseTableAvailability::has('ai_holding_external_action_mandates'),
            'work_orders' => DatabaseTableAvailability::has('ai_holding_external_cutover_work_orders'),
            'work_items' => DatabaseTableAvailability::has('ai_holding_external_cutover_work_items'),
            'runtime_invocations' => DatabaseTableAvailability::has('ai_holding_external_cutover_runtime_invocations'),
        ];
        $empty = [
            'status' => in_array(false, $tables, true) ? 'missing' : 'ready',
            'tables' => $tables,
            'summary' => [
                'mandates_total' => 0,
                'pending_operator_review' => 0,
                'preflight_green' => 0,
                'awaiting_signatures' => 0,
                'signed_manual_handoff' => 0,
                'work_orders_total' => 0,
                'work_items_total' => 0,
                'runtime_invocations_total' => 0,
                'manual_handoff_ready' => 0,
                'unsafe_external_execution_enabled' => 0,
                'signature_protection_coverage' => 1.0,
                'receipt_binding_count' => 0,
                'missing_receipt_binding_count' => 0,
            ],
            'recent_mandates' => [],
            'blockers' => [],
            'policy' => [
                'external_side_effects_allowed_by_control_plane' => false,
                'operator_signature_required' => true,
                'second_reviewer_required' => true,
                'manual_handoff_only_even_after_approval' => true,
                'benchmark_not_run' => true,
            ],
        ];
        if (in_array(false, $tables, true)) {
            return $empty;
        }

        try {
            $mandates = AiHoldingExternalActionMandate::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(200)
                ->get([
                    'id', 'company_id', 'flow_id', 'status', 'mandate_packet_hash',
                    'operator_signature_required', 'second_reviewer_required',
                    'auto_execute_allowed', 'external_side_effects_enabled', 'created_at',
                ]);
            $workOrders = AiHoldingExternalCutoverWorkOrder::query()
                ->where('created_at', '>=', $since)
                ->limit(200)
                ->get([
                    'id', 'work_order_id', 'company_id', 'flow_id', 'status',
                    'bound_receipt_count', 'external_execution_allowed',
                    'external_side_effects_enabled',
                ]);
            $workItems = AiHoldingExternalCutoverWorkItem::query()
                ->where('created_at', '>=', $since)
                ->limit(500)
                ->get([
                    'id', 'work_item_id', 'work_order_id', 'company_id', 'flow_id',
                    'status', 'required_receipt_ids_json', 'bound_receipt_hash',
                    'receipt_binding_hash', 'external_execution_allowed',
                    'external_side_effects_enabled',
                ]);
            $invocations = AiHoldingExternalCutoverRuntimeInvocation::query()
                ->where('created_at', '>=', $since)
                ->limit(200)
                ->get([
                    'id', 'invocation_id', 'work_order_id', 'company_id', 'flow_id',
                    'status', 'execution_receipt_count', 'last_execution_receipt_hash',
                    'manual_handoff_packet_hash', 'manual_closeout_receipt_count',
                    'last_manual_closeout_receipt_hash', 'external_execution_allowed',
                    'external_side_effects_enabled',
                ]);
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        $unsafeMandates = $mandates->filter(
            fn (AiHoldingExternalActionMandate $mandate): bool => (bool) $mandate->auto_execute_allowed
                || (bool) $mandate->external_side_effects_enabled,
        );
        $unsafeWorkOrders = $workOrders->filter(
            fn (AiHoldingExternalCutoverWorkOrder $workOrder): bool => (bool) $workOrder->external_execution_allowed
                || (bool) $workOrder->external_side_effects_enabled,
        );
        $unsafeWorkItems = $workItems->filter(
            fn (AiHoldingExternalCutoverWorkItem $workItem): bool => (bool) $workItem->external_execution_allowed
                || (bool) $workItem->external_side_effects_enabled,
        );
        $unsafeInvocations = $invocations->filter(
            fn (AiHoldingExternalCutoverRuntimeInvocation $invocation): bool => (bool) $invocation->external_execution_allowed
                || (bool) $invocation->external_side_effects_enabled,
        );
        $unsafeExternalExecutionEnabled = $unsafeMandates->count()
            + $unsafeWorkOrders->count()
            + $unsafeWorkItems->count()
            + $unsafeInvocations->count();
        $receiptBindingCount = $workItems->filter(
            fn (AiHoldingExternalCutoverWorkItem $workItem): bool => $this->support->stringOrNull($workItem->bound_receipt_hash) !== null
                || $this->support->stringOrNull($workItem->receipt_binding_hash) !== null,
        )->count() + $invocations->filter(
            fn (AiHoldingExternalCutoverRuntimeInvocation $invocation): bool => (int) $invocation->execution_receipt_count > 0
                || $this->support->stringOrNull($invocation->last_execution_receipt_hash) !== null
                || (int) $invocation->manual_closeout_receipt_count > 0
                || $this->support->stringOrNull($invocation->last_manual_closeout_receipt_hash) !== null,
        )->count();
        $missingReceiptBindings = $workItems->filter(
            fn (AiHoldingExternalCutoverWorkItem $workItem): bool => count((array) $workItem->required_receipt_ids_json) > 0
                && $this->support->stringOrNull($workItem->bound_receipt_hash) === null
                && $this->support->stringOrNull($workItem->receipt_binding_hash) === null,
        )->count();
        $signatureProtected = $mandates->filter(
            fn (AiHoldingExternalActionMandate $mandate): bool => (bool) $mandate->operator_signature_required
                && (bool) $mandate->second_reviewer_required
                && ! (bool) $mandate->auto_execute_allowed
                && ! (bool) $mandate->external_side_effects_enabled,
        )->count();
        $signatureProtectionCoverage = $mandates->count() > 0 ? round($signatureProtected / $mandates->count(), 4) : 1.0;
        $externalBlockers = [];
        foreach ($unsafeMandates as $mandate) {
            $externalBlockers[] = $this->externalExecutionBlocker('external_mandate_enabled_without_policy', $mandate->company_id, $mandate->flow_id, $mandate->mandate_packet_hash);
        }
        foreach ($unsafeWorkOrders as $workOrder) {
            $externalBlockers[] = $this->externalExecutionBlocker('external_work_order_enabled_without_policy', $workOrder->company_id, $workOrder->flow_id, $workOrder->work_order_id);
        }
        foreach ($unsafeWorkItems as $workItem) {
            $externalBlockers[] = $this->externalExecutionBlocker('external_work_item_enabled_without_policy', $workItem->company_id, $workItem->flow_id, $workItem->work_item_id);
        }
        foreach ($unsafeInvocations as $invocation) {
            $externalBlockers[] = $this->externalExecutionBlocker('external_runtime_invocation_enabled_without_policy', $invocation->company_id, $invocation->flow_id, $invocation->invocation_id);
        }

        return [
            'status' => $unsafeExternalExecutionEnabled > 0
                ? 'blocked'
                : ($mandates->whereIn('status', ['preflight_blocked', 'approval_denied'])->isNotEmpty() ? 'degraded' : 'ready'),
            'tables' => $tables,
            'summary' => [
                'mandates_total' => $mandates->count(),
                'pending_operator_review' => $mandates->where('status', 'queued_for_operator_review')->count(),
                'preflight_green' => $mandates->where('status', 'preflight_green_awaiting_signatures')->count(),
                'awaiting_signatures' => $mandates->where('status', 'awaiting_operator_and_reviewer_signatures')->count(),
                'signed_manual_handoff' => $mandates->where('status', 'signed_mandate_ready_manual_execution_only')->count(),
                'work_orders_total' => $workOrders->count(),
                'work_items_total' => $workItems->count(),
                'runtime_invocations_total' => $invocations->count(),
                'manual_handoff_ready' => $invocations->where('status', 'manual_handoff_ready')->count(),
                'unsafe_external_execution_enabled' => $unsafeExternalExecutionEnabled,
                'signature_protection_coverage' => $signatureProtectionCoverage,
                'receipt_binding_count' => $receiptBindingCount,
                'missing_receipt_binding_count' => $missingReceiptBindings,
            ],
            'recent_mandates' => $mandates->take(AtlasAiControlPlaneService::RECENT_LIMIT)->map(fn (AiHoldingExternalActionMandate $mandate): array => [
                'company_id' => $this->support->stringOrNull($mandate->company_id),
                'flow_id' => $this->support->stringOrNull($mandate->flow_id),
                'status' => $this->support->stringOrNull($mandate->status),
                'mandate_packet_hash' => $this->support->stringOrNull($mandate->mandate_packet_hash),
                'operator_signature_required' => (bool) $mandate->operator_signature_required,
                'second_reviewer_required' => (bool) $mandate->second_reviewer_required,
                'auto_execute_allowed' => (bool) $mandate->auto_execute_allowed,
                'external_side_effects_enabled' => (bool) $mandate->external_side_effects_enabled,
                'created_at' => $mandate->created_at?->toJSON(),
            ])->values()->all(),
            'blockers' => $externalBlockers,
            'policy' => [
                ...$empty['policy'],
                'unsafe_external_execution_blocks_runtime_status' => true,
                'pending_operator_review_is_operator_queue_not_system_failure' => true,
                'requires_receipt_binding_before_real_cutover' => true,
            ],
        ];
    }

    public function externalExecutionBlocker(string $kind, mixed $companyId, mixed $flowId, mixed $ref): array
    {
        return [
            'kind' => $kind,
            'class' => 'system_blocker',
            'severity' => 'critical',
            'company_id' => $this->support->stringOrNull($companyId),
            'flow_id' => $this->support->stringOrNull($flowId),
            'ref' => $this->support->stringOrNull($ref),
            'detail' => 'External execution or side effects are enabled even though the Atlas AI control plane policy is block-by-default.',
        ];
    }
}
