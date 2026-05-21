<?php

declare(strict_types=1);

namespace App\Services\Ai\ControlPlane;

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
use App\Services\Ai\Learning\AtlasAiLearningLoopService;
use App\Services\Ai\OperatorApproval\OperatorApprovalCanon;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AgentMergeReviewPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentRuntimeRegistryHandoffProtocolBuilder;
use App\Services\Ai\SelfConstruction\AgentValidationGateDryRunEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Atlas AI Observability & Control Plane (trace-level).
 *
 * Aggregates the per-trace signals that Atlas AI emits — router decisions,
 * receipts, evidence refs, quality evaluations, remediation actions, Dev/Forge
 * handoffs, provider distribution, rich_input usage — into a single
 * deterministic read model.
 *
 * Distinct from {@see AtlasControlPlaneSnapshotService}, which aggregates the
 * mission-level Atlas brain (domains/policies/tools/approvals). This service
 * is the read model for Atlas AI runtime auditing: "what flows ran in the
 * last N hours, did any silently drop a receipt or leak raw evidence?".
 *
 * Hard contract:
 *  - read-only and side-effect-free;
 *  - never executes a provider, never runs benchmark/rivals;
 *  - never declares external superiority;
 *  - tolerant of missing tables — degraded sections surface
 *    `status: missing` instead of throwing;
 *  - never returns raw response_text / operator_input; only hashes, ids, refs;
 *  - hash is deterministic over canonical content (excluding generated_at).
 */
class AtlasAiControlPlaneService
{
    public const SCHEMA_VERSION = 'atlas.ai.control_plane.v1';

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public const DEFAULT_WINDOW_HOURS = 24;

    /** Max items per list section to keep the report bounded. */
    private const RECENT_LIMIT = 20;

    private const BLOCKER_LIMIT = 50;

    public function __construct(
        private readonly AtlasAiLearningLoopService $learningLoop,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(int $hours = self::DEFAULT_WINDOW_HOURS): array
    {
        $hours = max(1, $hours);
        $generatedAt = CarbonImmutable::now();
        $since = $generatedAt->subHours($hours);

        $tracesSection = $this->tracesSection($since);
        $flowsSection = $this->flowsSection($since, $tracesSection['ids']);
        $recentTraces = $this->recentTraces($since);
        $failures = $this->failures($since);
        $handoffs = $this->handoffs($since, $tracesSection['ids']);
        $receipts = $this->receipts($since);
        $evidence = $this->evidence($since, $tracesSection['ids']);
        $quality = $this->quality($since, $tracesSection['ids']);
        $providerDecisions = $this->providerDecisions($since);
        $contextOperations = $this->contextOperations($tracesSection['ids']);
        $persistentContext = $this->persistentContext($since);
        $aemor = $this->aemor($since);
        $intelligenceFactory = $this->intelligenceFactory($since);
        $strategicReality = $this->strategicReality($since);
        $runtimeEfficiency = $this->runtimeEfficiency($since);
        $agenticWorkcell = $this->agenticWorkcell($since);
        $autonomousWorkExecution = $this->autonomousWorkExecution($since);
        $verifiedExecution = $this->verifiedExecution($since);
        $swarmCompany = $this->swarmCompany($since);
        $externalExecution = $this->externalExecution($since);
        $blockers = $this->blockers($since, $tracesSection['ids']);
        foreach ((array) ($externalExecution['blockers'] ?? []) as $blocker) {
            if (count($blockers) >= self::BLOCKER_LIMIT) {
                break;
            }
            if (is_array($blocker)) {
                $blockers[] = $blocker;
            }
        }
        foreach ((array) ($persistentContext['blockers'] ?? []) as $blocker) {
            if (count($blockers) >= self::BLOCKER_LIMIT) {
                break;
            }
            if (is_array($blocker)) {
                $blockers[] = $blocker;
            }
        }
        $blockerClassification = $this->classifyBlockers($blockers);
        $learning = $this->learningLoop->controlPlaneSummary($since);
        $approvals = $this->approvalsSection($since);

        $summary = [
            'total_traces' => $tracesSection['total'],
            'succeeded' => $tracesSection['by_status']['succeeded'] ?? 0,
            'failed' => $tracesSection['by_status']['failed'] ?? 0,
            'processing' => $tracesSection['by_status']['processing'] ?? 0,
            'queued' => $tracesSection['by_status']['queued'] ?? 0,
            'cancelled' => $tracesSection['by_status']['cancelled'] ?? 0,
            'unique_flows' => count($flowsSection),
            'unique_providers' => count($tracesSection['by_provider']),
            'blockers_count' => count($blockers),
            'system_blockers_count' => $blockerClassification['system_blockers_count'],
            'operator_queue_count' => $blockerClassification['operator_queue_count'],
            'clarification_queue_count' => $blockerClassification['clarification_queue_count'],
            'handoffs_count' => $handoffs['dev']['count'] + $handoffs['forge']['count'],
            'failures_count' => count($failures),
            'context_operations_blockers_count' => $contextOperations['blockers_count'],
            'verified_compactions_count' => $contextOperations['verified_compaction']['total'],
            'persistent_context_total' => $persistentContext['total'],
            'persistent_context_blocked' => $persistentContext['blocked'],
            'aemor_episodes_total' => $aemor['summary']['episodes_total'] ?? 0,
            'aemor_blocked_outcomes' => $aemor['summary']['blocked'] ?? 0,
            'aemor_failed_outcomes' => $aemor['summary']['failed'] ?? 0,
            'intelligence_factory_capabilities_total' => $intelligenceFactory['summary']['capabilities_total'] ?? 0,
            'intelligence_factory_open_gaps' => $intelligenceFactory['summary']['open_gaps'] ?? 0,
            'intelligence_factory_blocked_decisions' => $intelligenceFactory['summary']['blocked_decisions'] ?? 0,
            'intelligence_factory_evolution_events' => $intelligenceFactory['summary']['evolution_events_total'] ?? 0,
            'intelligence_factory_capability_used_events' => $intelligenceFactory['summary']['capability_used_events'] ?? 0,
            'strategic_reality_entities_total' => $strategicReality['summary']['reality_entities_total'] ?? 0,
            'strategic_reality_decisions_total' => $strategicReality['summary']['strategic_decisions_total'] ?? 0,
            'strategic_reality_blocked_decisions' => $strategicReality['summary']['blocked_decisions'] ?? 0,
            'strategic_reality_critical_risks' => $strategicReality['summary']['critical_risks'] ?? 0,
            'runtime_efficiency_decisions_total' => $runtimeEfficiency['summary']['decisions_total'] ?? 0,
            'runtime_efficiency_fast_path' => $runtimeEfficiency['summary']['fast_path'] ?? 0,
            'runtime_efficiency_deep_path' => $runtimeEfficiency['summary']['deep_path'] ?? 0,
            'runtime_efficiency_forge_path' => $runtimeEfficiency['summary']['forge_path'] ?? 0,
            'runtime_efficiency_blocked' => $runtimeEfficiency['summary']['blocked'] ?? 0,
            'agentic_workcells_total' => $agenticWorkcell['summary']['workcells_total'] ?? 0,
            'agentic_workcell_blocked' => $agenticWorkcell['summary']['blocked'] ?? 0,
            'agentic_workcell_org_patterns' => $agenticWorkcell['summary']['org_patterns_total'] ?? 0,
            'aweos_executions_total' => $autonomousWorkExecution['summary']['executions_total'] ?? 0,
            'aweos_blocked' => $autonomousWorkExecution['summary']['blocked'] ?? 0,
            'aweos_certified' => $autonomousWorkExecution['summary']['certified'] ?? 0,
            'aweos_gold_outcomes' => $autonomousWorkExecution['summary']['gold_outcomes'] ?? 0,
            'verified_execution_total' => $verifiedExecution['summary']['executions_total'] ?? 0,
            'verified_execution_blocked' => $verifiedExecution['summary']['blocked'] ?? 0,
            'verified_execution_certified' => $verifiedExecution['summary']['certified'] ?? 0,
            'verified_execution_gold_certifications' => $verifiedExecution['summary']['gold_certifications'] ?? 0,
            'swarm_company_roles_total' => $swarmCompany['summary']['role_runs_total'] ?? 0,
            'swarm_company_blocked_releases' => $swarmCompany['summary']['blocked_release_packs'] ?? 0,
            'external_execution_mandates_total' => $externalExecution['summary']['mandates_total'] ?? 0,
            'external_execution_pending_approval' => $externalExecution['summary']['pending_operator_review'] ?? 0,
            'external_execution_unsafe_enabled' => $externalExecution['summary']['unsafe_external_execution_enabled'] ?? 0,
            'external_execution_missing_receipt_bindings' => $externalExecution['summary']['missing_receipt_binding_count'] ?? 0,
        ];

        $status = $this->resolveStatus($summary, $blockers);

        $readinessRefs = $this->readinessRefs();

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $generatedAt->toJSON(),
            'window' => [
                'hours' => $hours,
                'since' => $since->toJSON(),
                'until' => $generatedAt->toJSON(),
            ],
            'status' => $status,
            'summary' => $summary,
            'flows' => $flowsSection,
            'recent_traces' => $recentTraces,
            'failures' => $failures,
            'handoffs' => $handoffs,
            'receipts' => $receipts,
            'evidence' => $evidence,
            'quality' => $quality,
            'provider_decisions' => $providerDecisions,
            'context_operations' => $contextOperations,
            'persistent_context' => $persistentContext,
            'aemor' => $aemor,
            'intelligence_factory' => $intelligenceFactory,
            'strategic_reality' => $strategicReality,
            'runtime_efficiency' => $runtimeEfficiency,
            'agentic_workcell' => $agenticWorkcell,
            'autonomous_work_execution' => $autonomousWorkExecution,
            'verified_execution' => $verifiedExecution,
            'swarm_company' => $swarmCompany,
            'external_execution' => $externalExecution,
            'blockers' => $blockers,
            'learning' => $learning,
            'approvals' => $approvals,
            'readiness_refs' => $readinessRefs,
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'allows_external_superiority_claim' => false,
                'declares_atlas_complete' => false,
            ],
        ];

        $payload['hash'] = $this->hashPayload($payload);

        return $payload;
    }

    /**
     * @return array{total:int,by_status:array<string,int>,by_provider:array<string,int>,by_flow:array<string,int>,ids:array<int,string>}
     */
    private function tracesSection(CarbonImmutable $since): array
    {
        $empty = ['total' => 0, 'by_status' => [], 'by_provider' => [], 'by_flow' => [], 'ids' => []];
        if (! Schema::hasTable('ai_traces')) {
            return $empty;
        }

        try {
            $traces = AiTrace::query()
                ->where('created_at', '>=', $since)
                ->get(['id', 'status', 'provider', 'intent', 'metadata']);
        } catch (Throwable) {
            return $empty;
        }

        $byStatus = [];
        $byProvider = [];
        $byFlow = [];
        $ids = [];

        foreach ($traces as $trace) {
            $ids[] = (string) $trace->id;
            $status = $this->stringOrNull($trace->status) ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $provider = $this->stringOrNull($trace->provider) ?? 'unknown';
            $byProvider[$provider] = ($byProvider[$provider] ?? 0) + 1;
            $flowId = $this->flowIdFromTrace($trace);
            $byFlow[$flowId] = ($byFlow[$flowId] ?? 0) + 1;
        }

        return [
            'total' => count($traces),
            'by_status' => $byStatus,
            'by_provider' => $byProvider,
            'by_flow' => $byFlow,
            'ids' => $ids,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function persistentContext(CarbonImmutable $since): array
    {
        $empty = [
            'status' => 'missing',
            'total' => 0,
            'ready' => 0,
            'degraded' => 0,
            'blocked' => 0,
            'by_flow' => [],
            'by_scope_type' => [],
            'recent' => [],
            'blockers' => [],
        ];
        if (! Schema::hasTable('atlas_persistent_context_packs')) {
            return $empty;
        }

        try {
            $packs = AtlasPersistentContextPack::query()
                ->where('created_at', '>=', $since)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get(['id', 'uuid', 'status', 'scope_type', 'scope_id', 'workspace', 'domain', 'flow_id', 'sufficiency_status', 'context_pack_hash', 'must_know_ledger_hash', 'provider_handoff', 'created_at']);
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        $section = $empty;
        $section['status'] = 'ready';
        $section['total'] = count($packs);
        foreach ($packs as $pack) {
            $status = $this->stringOrNull($pack->status) ?? 'unknown';
            if (isset($section[$status]) && is_int($section[$status])) {
                $section[$status]++;
            }
            $flowId = $this->stringOrNull($pack->flow_id) ?? 'unknown';
            $scopeType = $this->stringOrNull($pack->scope_type) ?? 'unknown';
            $section['by_flow'][$flowId] = ($section['by_flow'][$flowId] ?? 0) + 1;
            $section['by_scope_type'][$scopeType] = ($section['by_scope_type'][$scopeType] ?? 0) + 1;

            if ($status === 'blocked') {
                $section['blockers'][] = [
                    'kind' => 'persistent_context_blocked',
                    'persistent_context_pack_uuid' => $this->stringOrNull($pack->uuid),
                    'flow_id' => $flowId,
                    'scope_type' => $scopeType,
                    'scope_id' => $this->stringOrNull($pack->scope_id),
                    'detail' => 'APCR sufficiency gate blocked provider handoff',
                ];
            }

            if (count($section['recent']) < self::RECENT_LIMIT) {
                $section['recent'][] = [
                    'uuid' => $this->stringOrNull($pack->uuid),
                    'status' => $status,
                    'sufficiency_status' => $this->stringOrNull($pack->sufficiency_status),
                    'scope_type' => $scopeType,
                    'scope_id' => $this->stringOrNull($pack->scope_id),
                    'domain' => $this->stringOrNull($pack->domain),
                    'flow_id' => $flowId,
                    'context_pack_hash' => $this->stringOrNull($pack->context_pack_hash),
                    'must_know_ledger_hash' => $this->stringOrNull($pack->must_know_ledger_hash),
                    'execution_allowed' => (bool) data_get($pack->provider_handoff, 'execution_allowed', false),
                    'created_at' => $pack->created_at?->toJSON(),
                ];
            }
        }

        ksort($section['by_flow']);
        ksort($section['by_scope_type']);

        return $section;
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<int,array<string,mixed>>
     */
    private function flowsSection(CarbonImmutable $since, array $traceIds): array
    {
        if (! Schema::hasTable('ai_traces')) {
            return [];
        }

        try {
            $traces = AiTrace::query()
                ->where('created_at', '>=', $since)
                ->get(['id', 'status', 'provider', 'created_at', 'metadata']);
        } catch (Throwable) {
            return [];
        }

        $byFlow = [];
        foreach ($traces as $trace) {
            $flowId = $this->flowIdFromTrace($trace);
            $byFlow[$flowId] ??= [
                'flow_id' => $flowId,
                'is_programming_anchored' => in_array($flowId, RouterRuntimeCanon::PROGRAMMING_FLOW_IDS, true),
                'is_canonical' => in_array($flowId, RouterRuntimeCanon::ALLOWED_FLOW_IDS, true),
                'total' => 0,
                'by_status' => [],
                'providers' => [],
                'trace_ids' => [],
                'last_seen_at' => null,
            ];
            $byFlow[$flowId]['total']++;
            $status = $this->stringOrNull($trace->status) ?? 'unknown';
            $byFlow[$flowId]['by_status'][$status] = ($byFlow[$flowId]['by_status'][$status] ?? 0) + 1;
            $provider = $this->stringOrNull($trace->provider) ?? 'unknown';
            $byFlow[$flowId]['providers'][$provider] = ($byFlow[$flowId]['providers'][$provider] ?? 0) + 1;
            $byFlow[$flowId]['trace_ids'][] = (string) $trace->id;
            $createdAt = $trace->created_at?->toJSON();
            if ($createdAt !== null && ($byFlow[$flowId]['last_seen_at'] === null || $createdAt > $byFlow[$flowId]['last_seen_at'])) {
                $byFlow[$flowId]['last_seen_at'] = $createdAt;
            }
        }

        // Enrich with evidence/receipt/handoff/quality counts per flow.
        $receiptsByTrace = $this->receiptCountsByTraceIds($traceIds);
        $evidenceByTrace = $this->evidenceCountsByTraceIds($traceIds);
        $qualityByTrace = $this->qualityByTraceIds($traceIds);
        $handoffsByTrace = $this->handoffCountsByTraceIds($traceIds);

        foreach ($byFlow as $flowId => &$flow) {
            $flow['receipt_count'] = $this->sumByKeys($receiptsByTrace, $flow['trace_ids']);
            $flow['evidence_count'] = $this->sumByKeys($evidenceByTrace, $flow['trace_ids']);
            $flow['handoff_count'] = $this->sumByKeys($handoffsByTrace, $flow['trace_ids']);
            $flow['quality_signals'] = $this->aggregateQuality($qualityByTrace, $flow['trace_ids']);
            // trace_ids list is internal; drop from output to keep the report bounded.
            unset($flow['trace_ids']);
        }
        unset($flow);

        ksort($byFlow);

        return array_values($byFlow);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recentTraces(CarbonImmutable $since): array
    {
        if (! Schema::hasTable('ai_traces')) {
            return [];
        }

        try {
            $traces = AiTrace::query()
                ->where('created_at', '>=', $since)
                ->orderByDesc('created_at')
                ->limit(self::RECENT_LIMIT)
                ->get(['id', 'status', 'provider', 'intent', 'latency_ms', 'created_at', 'metadata']);
        } catch (Throwable) {
            return [];
        }

        return $traces->map(fn (AiTrace $trace): array => [
            'trace_id' => (string) $trace->id,
            'flow_id' => $this->flowIdFromTrace($trace),
            'status' => $this->stringOrNull($trace->status),
            'provider' => $this->stringOrNull($trace->provider),
            'intent' => $this->stringOrNull($trace->intent),
            'latency_ms' => $trace->latency_ms,
            'created_at' => $trace->created_at?->toJSON(),
        ])->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function failures(CarbonImmutable $since): array
    {
        if (! Schema::hasTable('ai_traces')) {
            return [];
        }

        try {
            $traces = AiTrace::query()
                ->where('created_at', '>=', $since)
                ->where('status', 'failed')
                ->orderByDesc('created_at')
                ->limit(self::RECENT_LIMIT)
                ->with(['job' => fn ($q) => $q->select(['id', 'trace_id', 'error_code', 'error_message', 'attempts'])])
                ->get(['id', 'status', 'provider', 'created_at', 'metadata']);
        } catch (Throwable) {
            return [];
        }

        return $traces->map(function (AiTrace $trace): array {
            $job = $trace->job;

            return [
                'trace_id' => (string) $trace->id,
                'flow_id' => $this->flowIdFromTrace($trace),
                'provider' => $this->stringOrNull($trace->provider),
                'error_code' => $job?->error_code,
                'error_message' => $this->truncate($job?->error_message, 240),
                'attempts' => $job?->attempts,
                'created_at' => $trace->created_at?->toJSON(),
            ];
        })->all();
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,array<string,mixed>>
     */
    private function handoffs(CarbonImmutable $since, array $traceIds): array
    {
        $devHandoffs = [];
        if (Schema::hasTable('ai_traces') && $traceIds !== []) {
            try {
                $traces = AiTrace::query()
                    ->whereIn('id', $traceIds)
                    ->get(['id', 'metadata']);
                foreach ($traces as $trace) {
                    $handoff = $this->extractDevHandoff($trace);
                    if ($handoff !== null) {
                        $devHandoffs[] = $handoff;
                    }
                }
            } catch (Throwable) {
                // tolerate; degraded section.
            }
        }

        $forgeHandoffs = [];
        if (Schema::hasTable('ai_real_execution_forge_handoffs')) {
            try {
                $rows = AiRealExecutionForgeHandoff::query()
                    ->where('created_at', '>=', $since)
                    ->orderByDesc('created_at')
                    ->limit(self::RECENT_LIMIT)
                    ->get(['id', 'goal_record_id', 'handoff_id', 'status', 'handoff_hash', 'created_at']);
                foreach ($rows as $row) {
                    $forgeHandoffs[] = [
                        'handoff_id' => $this->stringOrNull($row->handoff_id),
                        'goal_record_id' => $this->stringOrNull($row->goal_record_id),
                        'status' => $this->stringOrNull($row->status),
                        'handoff_hash' => $this->stringOrNull($row->handoff_hash),
                        'created_at' => $row->created_at?->toJSON(),
                    ];
                }
            } catch (Throwable) {
                // degraded.
            }
        }

        return [
            'dev' => [
                'count' => count($devHandoffs),
                'items' => array_slice($devHandoffs, 0, self::RECENT_LIMIT),
            ],
            'forge' => [
                'count' => count($forgeHandoffs),
                'items' => $forgeHandoffs,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function receipts(CarbonImmutable $since): array
    {
        $empty = ['status' => 'missing', 'total' => 0, 'by_type' => [], 'recent_hashes' => []];
        if (! Schema::hasTable('ai_atlas_decision_receipts')) {
            return $empty;
        }

        try {
            $receipts = AiAtlasDecisionReceipt::query()
                ->where('created_at', '>=', $since)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get(['id', 'receipt_type', 'receipt_hash', 'created_at']);
            $byType = [];
            $recent = [];
            foreach ($receipts as $receipt) {
                $type = $this->stringOrNull($receipt->receipt_type) ?? 'unknown';
                $byType[$type] = ($byType[$type] ?? 0) + 1;
                if (count($recent) < self::RECENT_LIMIT) {
                    $recent[] = [
                        'type' => $type,
                        'hash' => $this->stringOrNull($receipt->receipt_hash),
                        'created_at' => $receipt->created_at?->toJSON(),
                    ];
                }
            }

            return [
                'status' => 'ready',
                'total' => count($receipts),
                'by_type' => $byType,
                'recent' => $recent,
            ];
        } catch (Throwable) {
            return ['status' => 'degraded', 'total' => 0, 'by_type' => [], 'recent' => []];
        }
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,mixed>
     */
    private function evidence(CarbonImmutable $since, array $traceIds): array
    {
        $contextRefsCount = 0;
        $richInputJobs = 0;
        $sourceManifestRefs = 0;

        if (Schema::hasTable('ai_jobs') && $traceIds !== []) {
            try {
                $jobs = AiJob::query()
                    ->whereIn('trace_id', $traceIds)
                    ->get(['id', 'context_refs', 'payload']);
                foreach ($jobs as $job) {
                    $contextRefs = is_array($job->context_refs) ? $job->context_refs : [];
                    $contextRefsCount += count($contextRefs);
                    $payload = is_array($job->payload) ? $job->payload : [];
                    if (isset($payload['rich_input_payload']) && is_array($payload['rich_input_payload'])) {
                        $richInputJobs++;
                        $manifest = $payload['rich_input_payload']['source_manifest'] ?? null;
                        if (is_array($manifest)) {
                            $sourceManifestRefs += count($manifest);
                        }
                    }
                }
            } catch (Throwable) {
                // degraded.
            }
        }

        $evidencePackCount = 0;
        if (Schema::hasTable('ai_evidence_packs')) {
            try {
                $evidencePackCount = (int) AiEvidencePack::query()
                    ->where('created_at', '>=', $since)
                    ->count();
            } catch (Throwable) {
                // degraded.
            }
        }

        return [
            'evidence_packs_total' => $evidencePackCount,
            'context_refs_total' => $contextRefsCount,
            'rich_input_jobs_total' => $richInputJobs,
            'source_manifest_refs_total' => $sourceManifestRefs,
        ];
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,mixed>
     */
    private function quality(CarbonImmutable $since, array $traceIds): array
    {
        $empty = ['status' => 'missing', 'total' => 0, 'by_status' => [], 'actions' => ['total' => 0, 'by_status' => []], 'needs_review_recent' => []];
        if (! Schema::hasTable('ai_quality_evaluations')) {
            return $empty;
        }

        try {
            $evaluations = AiQualityEvaluation::query()
                ->where('created_at', '>=', $since)
                ->get(['id', 'trace_id', 'status', 'score', 'flags', 'created_at']);
            $byStatus = [];
            $scoreSum = 0;
            $scoreCount = 0;
            $needsReview = [];
            foreach ($evaluations as $eval) {
                $status = $this->stringOrNull($eval->status) ?? 'unknown';
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
                if (is_numeric($eval->score)) {
                    $scoreSum += (int) $eval->score;
                    $scoreCount++;
                }
                if (in_array($status, ['needs_review', 'failed'], true) && count($needsReview) < self::RECENT_LIMIT) {
                    $flags = collect(is_array($eval->flags) ? $eval->flags : [])
                        ->map(fn ($flag): ?string => is_array($flag) ? ($this->stringOrNull($flag['code'] ?? null)) : $this->stringOrNull($flag))
                        ->filter()
                        ->values()
                        ->all();
                    $needsReview[] = [
                        'trace_id' => $this->stringOrNull($eval->trace_id),
                        'status' => $status,
                        'score' => is_numeric($eval->score) ? (int) $eval->score : null,
                        'flags' => $flags,
                        'created_at' => $eval->created_at?->toJSON(),
                    ];
                }
            }

            $actions = ['total' => 0, 'by_status' => []];
            if (Schema::hasTable('ai_quality_actions')) {
                try {
                    $actionRows = AiQualityAction::query()
                        ->where('created_at', '>=', $since)
                        ->get(['id', 'status']);
                    $byActionStatus = [];
                    foreach ($actionRows as $row) {
                        $st = $this->stringOrNull($row->status) ?? 'unknown';
                        $byActionStatus[$st] = ($byActionStatus[$st] ?? 0) + 1;
                    }
                    $actions = ['total' => count($actionRows), 'by_status' => $byActionStatus];
                } catch (Throwable) {
                    // degraded.
                }
            }

            return [
                'status' => 'ready',
                'total' => count($evaluations),
                'average_score' => $scoreCount > 0 ? (int) round($scoreSum / $scoreCount) : null,
                'by_status' => $byStatus,
                'actions' => $actions,
                'needs_review_recent' => $needsReview,
            ];
        } catch (Throwable) {
            return ['status' => 'degraded', 'total' => 0, 'by_status' => [], 'actions' => ['total' => 0, 'by_status' => []], 'needs_review_recent' => []];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function providerDecisions(CarbonImmutable $since): array
    {
        $empty = ['status' => 'missing', 'total' => 0, 'by_primary_domain' => [], 'by_routing_mode' => []];
        if (! Schema::hasTable('ai_atlas_router_decisions')) {
            return $empty;
        }

        try {
            $decisions = AiAtlasRouterDecision::query()
                ->where('created_at', '>=', $since)
                ->get(['id', 'primary_domain', 'routing_mode', 'policy_required', 'evidence_required', 'tool_plan_required']);
            $byDomain = [];
            $byMode = [];
            $policyRequired = 0;
            $evidenceRequired = 0;
            $toolPlanRequired = 0;
            foreach ($decisions as $decision) {
                $domain = $this->stringOrNull($decision->primary_domain) ?? 'unknown';
                $byDomain[$domain] = ($byDomain[$domain] ?? 0) + 1;
                $mode = $this->stringOrNull($decision->routing_mode) ?? 'unknown';
                $byMode[$mode] = ($byMode[$mode] ?? 0) + 1;
                if ($decision->policy_required) {
                    $policyRequired++;
                }
                if ($decision->evidence_required) {
                    $evidenceRequired++;
                }
                if ($decision->tool_plan_required) {
                    $toolPlanRequired++;
                }
            }

            return [
                'status' => 'ready',
                'total' => count($decisions),
                'by_primary_domain' => $byDomain,
                'by_routing_mode' => $byMode,
                'policy_required_count' => $policyRequired,
                'evidence_required_count' => $evidenceRequired,
                'tool_plan_required_count' => $toolPlanRequired,
            ];
        } catch (Throwable) {
            return ['status' => 'degraded', 'total' => 0, 'by_primary_domain' => [], 'by_routing_mode' => []];
        }
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,mixed>
     */
    private function contextOperations(array $traceIds): array
    {
        $empty = [
            'status' => 'missing',
            'total' => 0,
            'by_status' => [],
            'context_intelligence' => ['by_status' => [], 'blocked' => 0, 'degraded' => 0],
            'conversation_ops' => ['by_status' => [], 'blocked' => 0, 'watch' => 0],
            'verified_compaction' => ['total' => 0, 'passed' => 0, 'blocked' => 0, 'skipped' => 0, 'required' => 0],
            'handoff_packets' => ['total' => 0],
            'blockers_count' => 0,
            'recent' => [],
        ];

        if (! Schema::hasTable('ai_traces') || $traceIds === []) {
            return $empty;
        }

        try {
            $traces = AiTrace::query()
                ->whereIn('id', $traceIds)
                ->orderByDesc('created_at')
                ->limit(200)
                ->get(['id', 'metadata', 'created_at']);
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        $section = $empty;
        $section['status'] = 'ready';
        foreach ($traces as $trace) {
            $metadata = is_array($trace->metadata) ? $trace->metadata : [];
            $runtime = (array) data_get($metadata, 'hyperflow_runtime', []);
            $operations = (array) data_get($runtime, 'context_operations', []);
            if ($operations === []) {
                continue;
            }

            $section['total']++;
            $status = $this->stringOrNull(data_get($operations, 'status')) ?? 'unknown';
            $section['by_status'][$status] = ($section['by_status'][$status] ?? 0) + 1;

            $contextStatus = $this->stringOrNull(data_get($operations, 'context_intelligence.status'))
                ?? $this->stringOrNull(data_get($runtime, 'context_intelligence.status'))
                ?? 'unknown';
            $section['context_intelligence']['by_status'][$contextStatus] = ($section['context_intelligence']['by_status'][$contextStatus] ?? 0) + 1;
            if ($contextStatus === 'blocked') {
                $section['context_intelligence']['blocked']++;
            }
            if ($contextStatus === 'degraded') {
                $section['context_intelligence']['degraded']++;
            }

            $conversationStatus = $this->stringOrNull(data_get($operations, 'conversation_ops.status'))
                ?? $this->stringOrNull(data_get($runtime, 'conversation_ops.status'))
                ?? 'unknown';
            $section['conversation_ops']['by_status'][$conversationStatus] = ($section['conversation_ops']['by_status'][$conversationStatus] ?? 0) + 1;
            if ($conversationStatus === 'blocked') {
                $section['conversation_ops']['blocked']++;
            }
            if ($conversationStatus === 'watch') {
                $section['conversation_ops']['watch']++;
            }

            $compactionStatus = $this->stringOrNull(data_get($operations, 'verified_compaction.status'))
                ?? $this->stringOrNull(data_get($runtime, 'verified_compaction.status'));
            if ($compactionStatus !== null) {
                $section['verified_compaction']['total']++;
                $section['verified_compaction'][$compactionStatus] = ($section['verified_compaction'][$compactionStatus] ?? 0) + 1;
            }
            if ((bool) data_get($operations, 'integration_policy.verified_compaction_required', false)) {
                $section['verified_compaction']['required']++;
            }
            if (is_array(data_get($operations, 'handoff_packet')) || is_array(data_get($runtime, 'context_handoff_packet'))) {
                $section['handoff_packets']['total']++;
            }

            $blockerCount = count((array) data_get($operations, 'context_intelligence.blockers', []))
                + count((array) data_get($operations, 'verified_compaction.blockers', []))
                + count((array) data_get($operations, 'compression_critic.blockers', []));
            if ($status === 'blocked') {
                $blockerCount = max(1, $blockerCount);
            }
            $section['blockers_count'] += $blockerCount;

            if (count($section['recent']) < self::RECENT_LIMIT) {
                $section['recent'][] = [
                    'trace_id' => (string) $trace->id,
                    'flow_id' => $this->flowIdFromTrace($trace),
                    'status' => $status,
                    'context_status' => $contextStatus,
                    'conversation_status' => $conversationStatus,
                    'verified_compaction_status' => $compactionStatus,
                    'operations_runtime_hash' => $this->stringOrNull(data_get($operations, 'operations_runtime_hash')),
                    'created_at' => $trace->created_at?->toJSON(),
                ];
            }
        }

        return $section;
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<int,array<string,mixed>>
     */
    private function blockers(CarbonImmutable $since, array $traceIds): array
    {
        $blockers = [];

        // 1. Failed traces without an error_message (silent failure).
        if (Schema::hasTable('ai_traces')) {
            try {
                $silentFailures = AiTrace::query()
                    ->where('created_at', '>=', $since)
                    ->where('status', 'failed')
                    ->with(['job' => fn ($q) => $q->select(['id', 'trace_id', 'error_message'])])
                    ->limit(self::BLOCKER_LIMIT)
                    ->get(['id', 'status', 'metadata']);
                foreach ($silentFailures as $trace) {
                    $err = trim((string) ($trace->job?->error_message ?? ''));
                    if ($err === '') {
                        $blockers[] = [
                            'kind' => 'silent_failure',
                            'class' => 'system_blocker',
                            'severity' => 'critical',
                            'trace_id' => (string) $trace->id,
                            'flow_id' => $this->flowIdFromTrace($trace),
                            'detail' => 'trace failed without persisted error_message',
                        ];
                    }
                }
            } catch (Throwable) {
                // tolerate.
            }
        }

        // 2. Dispatch with persisted blockers list.
        if (Schema::hasTable('ai_atlas_runtime_dispatches')) {
            try {
                $dispatches = AiAtlasRuntimeDispatch::query()
                    ->where('created_at', '>=', $since)
                    ->whereNotNull('blockers')
                    ->limit(self::BLOCKER_LIMIT)
                    ->get(['id', 'dispatch_status', 'blockers']);
                foreach ($dispatches as $dispatch) {
                    $reasons = is_array($dispatch->blockers) ? $dispatch->blockers : [];
                    if ($reasons === []) {
                        continue;
                    }
                    foreach ($reasons as $reason) {
                        $detail = is_array($reason) ? ($this->stringOrNull($reason['reason'] ?? null) ?? 'unspecified') : (string) $reason;
                        $class = $this->dispatchBlockerClass($detail);
                        $blockers[] = [
                            'kind' => 'dispatch_blocker',
                            'class' => $class,
                            'severity' => $class === 'system_blocker' ? 'high' : 'operator_queue',
                            'dispatch_id' => (string) $dispatch->id,
                            'dispatch_status' => $this->stringOrNull($dispatch->dispatch_status),
                            'detail' => $detail,
                        ];
                        if (count($blockers) >= self::BLOCKER_LIMIT) {
                            break 2;
                        }
                    }
                }
            } catch (Throwable) {
                // tolerate.
            }
        }

        // 3. Quality evaluations with status=failed (hard blockers).
        if (Schema::hasTable('ai_quality_evaluations')) {
            try {
                $rows = AiQualityEvaluation::query()
                    ->where('created_at', '>=', $since)
                    ->where('status', 'failed')
                    ->limit(self::BLOCKER_LIMIT)
                    ->get(['id', 'trace_id', 'flags']);
                foreach ($rows as $row) {
                    $blockers[] = [
                        'kind' => 'quality_failed',
                        'class' => 'system_blocker',
                        'severity' => 'critical',
                        'trace_id' => $this->stringOrNull($row->trace_id),
                        'detail' => 'quality evaluation marked failed',
                    ];
                    if (count($blockers) >= self::BLOCKER_LIMIT) {
                        break;
                    }
                }
            } catch (Throwable) {
                // tolerate.
            }
        }

        // 4. Forge handoff with status != succeeded after window age.
        if (Schema::hasTable('ai_real_execution_forge_handoffs')) {
            try {
                $stale = AiRealExecutionForgeHandoff::query()
                    ->where('created_at', '>=', $since)
                    ->whereNotIn('status', ['succeeded', 'completed', 'closed'])
                    ->limit(self::BLOCKER_LIMIT)
                    ->get(['id', 'handoff_id', 'status', 'created_at']);
                foreach ($stale as $row) {
                    $blockers[] = [
                        'kind' => 'handoff_incomplete',
                        'class' => 'operator_queue',
                        'severity' => 'operator_queue',
                        'handoff_id' => $this->stringOrNull($row->handoff_id),
                        'status' => $this->stringOrNull($row->status),
                        'detail' => 'Forge handoff not in terminal succeeded/completed state',
                    ];
                    if (count($blockers) >= self::BLOCKER_LIMIT) {
                        break;
                    }
                }
            } catch (Throwable) {
                // tolerate.
            }
        }

        // 5. ACIE/ACOL runtime blockers persisted in Hyperflow metadata.
        if (Schema::hasTable('ai_traces') && $traceIds !== []) {
            try {
                $traces = AiTrace::query()
                    ->whereIn('id', $traceIds)
                    ->limit(self::BLOCKER_LIMIT)
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
                        'flow_id' => $this->flowIdFromTrace($trace),
                        'detail' => 'ACIE/ACOL operations runtime marked the flow blocked',
                    ];
                    if (count($blockers) >= self::BLOCKER_LIMIT) {
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
    private function classifyBlockers(array $blockers): array
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
    private function blockerClass(array $blocker): string
    {
        $class = $this->stringOrNull($blocker['class'] ?? null);
        if ($class === 'system_blocker' || $class === 'operator_queue') {
            return $class;
        }

        return match ($this->stringOrNull($blocker['kind'] ?? null)) {
            'silent_failure',
            'quality_failed',
            'context_operations_blocked',
            'persistent_context_blocked' => 'system_blocker',
            'handoff_incomplete' => 'operator_queue',
            'dispatch_blocker' => $this->dispatchBlockerClass($this->stringOrNull($blocker['detail'] ?? null) ?? ''),
            default => 'system_blocker',
        };
    }

    private function dispatchBlockerClass(string $detail): string
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
    private function isClarificationBlocker(array $blocker): bool
    {
        $detail = $this->stringOrNull($blocker['detail'] ?? null) ?? '';

        return ($this->stringOrNull($blocker['kind'] ?? null) === 'dispatch_blocker')
            && (str_starts_with($detail, 'clarification_needed') || str_contains($detail, 'high_ambiguity'));
    }

    /**
     * Operator Approval Gate observability section.
     *
     * Mirrors {@see OperatorApprovalGateService::controlPlaneSnapshot()} but
     * windowed by the report's `since` so the runtime control plane stays
     * consistent with the rest of the report. Tolerates missing table.
     *
     * @return array<string,mixed>
     */
    private function approvalsSection(CarbonImmutable $since): array
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

        if (! Schema::hasTable('ai_operator_approvals')) {
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
            $status = $this->stringOrNull($approval->status) ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $mode = $this->stringOrNull($approval->gate_mode) ?? 'unknown';
            $byMode[$mode] = ($byMode[$mode] ?? 0) + 1;
            $risk = $this->stringOrNull($approval->risk_level) ?? 'unknown';
            $byRisk[$risk] = ($byRisk[$risk] ?? 0) + 1;

            $serialized = [
                'uuid' => $approval->uuid,
                'mission_id' => $this->stringOrNull($approval->mission_id),
                'trace_id' => $this->stringOrNull($approval->trace_id),
                'requested_action' => $this->stringOrNull($approval->requested_action),
                'gate_mode' => $mode,
                'risk_level' => $risk,
                'status' => $status,
                'operator_decision' => $this->stringOrNull($approval->operator_decision),
                'operator' => $this->stringOrNull($approval->operator),
                'reason' => $this->truncate($approval->reason, 200),
                'expires_at' => $approval->expires_at?->toJSON(),
                'decided_at' => $approval->decided_at?->toJSON(),
                'receipt_hash' => $this->stringOrNull($approval->receipt_hash),
                'hash' => $this->stringOrNull($approval->hash),
                'created_at' => $approval->created_at?->toJSON(),
            ];

            if ($status === OperatorApprovalCanon::STATUS_PENDING && count($pending) < self::RECENT_LIMIT) {
                $pending[] = $serialized;
            }
            if (in_array($status, [OperatorApprovalCanon::STATUS_APPROVED, OperatorApprovalCanon::STATUS_DENIED, OperatorApprovalCanon::STATUS_EXPIRED, OperatorApprovalCanon::STATUS_CANCELLED], true)) {
                if (count($decisions) < self::RECENT_LIMIT) {
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
    private function aemor(CarbonImmutable $since): array
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
        if (! Schema::hasTable('atlas_aemor_execution_episodes')) {
            return $empty;
        }

        try {
            $episodes = AtlasAemorExecutionEpisode::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'scope_type', 'scope_id', 'flow_id', 'episode_hash', 'created_at']);
            $outcomes = Schema::hasTable('atlas_aemor_outcomes')
                ? AtlasAemorOutcome::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'episode_id', 'status', 'failure_signature', 'outcome_hash', 'created_at'])
                : collect();
            $judgments = Schema::hasTable('atlas_aemor_judgment_reports')
                ? AtlasAemorJudgmentReport::query()->where('created_at', '>=', $since)->latest()->limit(self::RECENT_LIMIT)->get(['id', 'episode_id', 'outcome_id', 'status', 'quality_score', 'judgment_hash', 'created_at'])
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
                'learning_signals' => Schema::hasTable('atlas_aemor_learning_signals') ? AtlasAemorLearningSignal::query()->where('created_at', '>=', $since)->count() : 0,
                'memory_candidates' => Schema::hasTable('atlas_aemor_memory_candidates') ? AtlasAemorMemoryCandidate::query()->where('created_at', '>=', $since)->count() : 0,
                'judgment_reports' => $judgments->count(),
            ],
            'recent_blockers' => $outcomes
                ->filter(fn (AtlasAemorOutcome $outcome): bool => in_array($outcome->status, ['failed', 'blocked'], true))
                ->take(self::RECENT_LIMIT)
                ->map(fn (AtlasAemorOutcome $outcome): array => [
                    'episode_id' => (string) $outcome->episode_id,
                    'outcome_id' => (string) $outcome->id,
                    'status' => (string) $outcome->status,
                    'failure_signature' => $this->stringOrNull($outcome->failure_signature),
                    'outcome_hash' => $this->stringOrNull($outcome->outcome_hash),
                    'created_at' => $outcome->created_at?->toJSON(),
                ])
                ->values()
                ->all(),
            'recent_judgments' => $judgments
                ->map(fn (AtlasAemorJudgmentReport $report): array => [
                    'episode_id' => (string) $report->episode_id,
                    'outcome_id' => $this->stringOrNull($report->outcome_id),
                    'status' => (string) $report->status,
                    'quality_status' => $this->stringOrNull(data_get($report->quality_score, 'status')),
                    'quality_score' => data_get($report->quality_score, 'score'),
                    'judgment_hash' => $this->stringOrNull($report->judgment_hash),
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
    private function intelligenceFactory(CarbonImmutable $since): array
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
        if (! Schema::hasTable('atlas_intelligence_factory_capabilities')) {
            return $empty;
        }

        try {
            $capabilities = AtlasIntelligenceFactoryCapability::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'capability_key', 'capability_type', 'domain', 'flow_id', 'certification_hash', 'created_at']);
            $gaps = Schema::hasTable('atlas_intelligence_factory_gaps')
                ? AtlasIntelligenceFactoryGap::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'gap_type', 'severity', 'gap_hash', 'created_at'])
                : collect();
            $decisions = Schema::hasTable('atlas_intelligence_factory_decisions')
                ? AtlasIntelligenceFactoryDecision::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'decision', 'status', 'decision_hash', 'created_at'])
                : collect();
            $simulations = Schema::hasTable('atlas_intelligence_factory_simulations')
                ? AtlasIntelligenceFactorySimulation::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'mode', 'simulation_hash', 'created_at'])
                : collect();
            $evolutionEvents = Schema::hasTable('atlas_intelligence_factory_evolution_events')
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
            'recent_gaps' => $gaps->take(self::RECENT_LIMIT)->map(fn (AtlasIntelligenceFactoryGap $gap): array => [
                'gap_id' => (string) $gap->id,
                'status' => (string) $gap->status,
                'gap_type' => (string) $gap->gap_type,
                'severity' => (string) $gap->severity,
                'gap_hash' => $this->stringOrNull($gap->gap_hash),
                'created_at' => $gap->created_at?->toJSON(),
            ])->values()->all(),
            'recent_decisions' => $decisions->take(self::RECENT_LIMIT)->map(fn (AtlasIntelligenceFactoryDecision $decision): array => [
                'decision_id' => (string) $decision->id,
                'decision' => (string) $decision->decision,
                'status' => (string) $decision->status,
                'decision_hash' => $this->stringOrNull($decision->decision_hash),
                'created_at' => $decision->created_at?->toJSON(),
            ])->values()->all(),
            'recent_evolution_events' => $evolutionEvents->take(self::RECENT_LIMIT)->map(fn (AtlasIntelligenceFactoryEvolutionEvent $event): array => [
                'event_id' => (string) $event->id,
                'capability_id' => $this->stringOrNull($event->capability_id),
                'source_type' => $this->stringOrNull($event->source_type),
                'event_type' => (string) $event->event_type,
                'status' => (string) $event->status,
                'event_hash' => $this->stringOrNull($event->event_hash),
                'created_at' => $event->created_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    /**
     * ASRE read model. Aggregate-only; no raw strategic question text.
     *
     * @return array<string,mixed>
     */
    private function strategicReality(CarbonImmutable $since): array
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
        if (! Schema::hasTable('atlas_strategic_decisions')) {
            return $empty;
        }

        try {
            $entities = Schema::hasTable('atlas_reality_entities')
                ? AtlasRealityEntity::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'entity_type', 'entity_hash', 'created_at'])
                : collect();
            $decisions = AtlasStrategicDecision::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(200)
                ->get(['id', 'status', 'question_hash', 'recommended_action', 'confidence', 'decision_hash', 'created_at']);
            $opportunities = Schema::hasTable('atlas_opportunity_signals')
                ? AtlasOpportunitySignal::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'status', 'opportunity_type', 'opportunity_hash', 'created_at'])
                : collect();
            $risks = Schema::hasTable('atlas_risk_signals')
                ? AtlasRiskSignal::query()->where('created_at', '>=', $since)->latest()->limit(200)->get(['id', 'risk_type', 'severity', 'risk_hash', 'created_at'])
                : collect();
            $briefings = Schema::hasTable('atlas_executive_briefings')
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
            'recent_decisions' => $decisions->take(self::RECENT_LIMIT)->map(fn (AtlasStrategicDecision $decision): array => [
                'decision_id' => (string) $decision->id,
                'status' => (string) $decision->status,
                'question_hash' => $this->stringOrNull($decision->question_hash),
                'recommended_action_excerpt' => $this->truncate($decision->recommended_action, 160),
                'confidence' => $decision->confidence,
                'decision_hash' => $this->stringOrNull($decision->decision_hash),
                'created_at' => $decision->created_at?->toJSON(),
            ])->values()->all(),
            'recent_risks' => $risks->take(self::RECENT_LIMIT)->map(fn (AtlasRiskSignal $risk): array => [
                'risk_id' => (string) $risk->id,
                'risk_type' => (string) $risk->risk_type,
                'severity' => (string) $risk->severity,
                'risk_hash' => $this->stringOrNull($risk->risk_hash),
                'created_at' => $risk->created_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    /**
     * AREG read model. Aggregate-only; never exposes raw prompts.
     *
     * @return array<string,mixed>
     */
    private function runtimeEfficiency(CarbonImmutable $since): array
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
        if (! Schema::hasTable('atlas_runtime_efficiency_decisions')) {
            return $empty;
        }

        try {
            $decisions = AtlasRuntimeEfficiencyDecision::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(200)
                ->get(['id', 'status', 'domain', 'flow_id', 'path', 'prompt_hash', 'context_budget_tokens', 'decision_hash', 'created_at']);
            $outcomes = Schema::hasTable('atlas_runtime_efficiency_outcomes')
                ? AtlasRuntimeEfficiencyOutcome::query()->where('created_at', '>=', $since)->limit(200)->get(['id'])
                : collect();
        } catch (Throwable) {
            return ['status' => 'degraded'] + $empty;
        }

        $byFlow = [];
        foreach ($decisions as $decision) {
            $flowId = $this->stringOrNull($decision->flow_id) ?? 'unknown';
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
            'recent_decisions' => $decisions->take(self::RECENT_LIMIT)->map(fn (AtlasRuntimeEfficiencyDecision $decision): array => [
                'decision_id' => (string) $decision->id,
                'status' => (string) $decision->status,
                'domain' => $this->stringOrNull($decision->domain),
                'flow_id' => $this->stringOrNull($decision->flow_id),
                'path' => (string) $decision->path,
                'prompt_hash' => $this->stringOrNull($decision->prompt_hash),
                'context_budget_tokens' => (int) $decision->context_budget_tokens,
                'decision_hash' => $this->stringOrNull($decision->decision_hash),
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
    private function agenticWorkcell(CarbonImmutable $since): array
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
        if (! Schema::hasTable('atlas_agentic_workcells')) {
            return $empty;
        }

        try {
            $workcells = AtlasAgenticWorkcell::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(200)
                ->get(['id', 'status', 'domain', 'flow_id', 'topology', 'maturity_level', 'objective_hash', 'workcell_hash', 'created_at']);
            $outcomes = Schema::hasTable('atlas_agentic_workcell_outcomes')
                ? AtlasAgenticWorkcellOutcome::query()->where('created_at', '>=', $since)->limit(200)->get(['id', 'quality_score', 'coordination_roi_score'])
                : collect();
            $patterns = Schema::hasTable('atlas_agentic_workcell_org_patterns')
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
            'by_topology' => $this->countsBy($workcells, 'topology'),
            'by_flow' => $this->countsBy($workcells, 'flow_id'),
            'recent_workcells' => $workcells->take(self::RECENT_LIMIT)->map(fn (AtlasAgenticWorkcell $workcell): array => [
                'workcell_id' => (string) $workcell->id,
                'status' => (string) $workcell->status,
                'domain' => $this->stringOrNull($workcell->domain),
                'flow_id' => $this->stringOrNull($workcell->flow_id),
                'topology' => (string) $workcell->topology,
                'maturity_level' => (string) $workcell->maturity_level,
                'objective_hash' => $this->stringOrNull($workcell->objective_hash),
                'workcell_hash' => $this->stringOrNull($workcell->workcell_hash),
                'created_at' => $workcell->created_at?->toJSON(),
            ])->values()->all(),
            'recent_patterns' => $patterns->take(10)->map(fn (AtlasAgenticWorkcellOrgPattern $pattern): array => [
                'pattern_id' => (string) $pattern->id,
                'status' => (string) $pattern->status,
                'flow_id' => $this->stringOrNull($pattern->flow_id),
                'topology' => (string) $pattern->topology,
                'pattern_hash' => $this->stringOrNull($pattern->pattern_hash),
            ])->values()->all(),
        ];
    }

    /**
     * AWEOS read model. No raw objective, no provider output.
     *
     * @return array<string,mixed>
     */
    private function autonomousWorkExecution(CarbonImmutable $since): array
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
        if (! Schema::hasTable('atlas_aweos_executions')) {
            return $empty;
        }

        try {
            $executions = AtlasAweosExecution::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(200)
                ->get(['id', 'status', 'maturity_level', 'domain', 'flow_id', 'objective_hash', 'execution_hash', 'strategic_next_action', 'created_at']);
            $outcomes = Schema::hasTable('atlas_aweos_certified_outcomes')
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
            'recent_executions' => $executions->take(self::RECENT_LIMIT)->map(fn (AtlasAweosExecution $execution): array => [
                'execution_id' => (string) $execution->id,
                'status' => (string) $execution->status,
                'maturity_level' => (string) $execution->maturity_level,
                'domain' => $this->stringOrNull($execution->domain),
                'flow_id' => $this->stringOrNull($execution->flow_id),
                'objective_hash' => $this->stringOrNull($execution->objective_hash),
                'execution_hash' => $this->stringOrNull($execution->execution_hash),
                'next_action' => $this->stringOrNull(data_get($execution->strategic_next_action, 'next_action')),
                'created_at' => $execution->created_at?->toJSON(),
            ])->values()->all(),
        ];
    }

    /**
     * AVER read model. No raw objectives, command text, stdout or stderr.
     *
     * @return array<string,mixed>
     */
    private function verifiedExecution(CarbonImmutable $since): array
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
        if (! Schema::hasTable('atlas_aver_executions')) {
            return $empty;
        }

        try {
            $executions = AtlasAverExecution::query()
                ->where('created_at', '>=', $since)
                ->latest()
                ->limit(200)
                ->get(['id', 'status', 'maturity_level', 'domain', 'flow_id', 'objective_hash', 'execution_hash', 'aweos_execution_id', 'created_at']);
            $certifications = Schema::hasTable('atlas_aver_certified_executions')
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
            'by_flow' => $this->countsBy($executions, 'flow_id'),
            'recent_executions' => $executions->take(self::RECENT_LIMIT)->map(fn (AtlasAverExecution $execution): array => [
                'execution_id' => (string) $execution->id,
                'aweos_execution_id' => $this->stringOrNull($execution->aweos_execution_id),
                'status' => (string) $execution->status,
                'maturity_level' => (string) $execution->maturity_level,
                'domain' => $this->stringOrNull($execution->domain),
                'flow_id' => $this->stringOrNull($execution->flow_id),
                'objective_hash' => $this->stringOrNull($execution->objective_hash),
                'execution_hash' => $this->stringOrNull($execution->execution_hash),
                'created_at' => $execution->created_at?->toJSON(),
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
    private function swarmCompany(CarbonImmutable $since): array
    {
        $tables = [
            'engagements' => Schema::hasTable('ai_engineering_company_engagements'),
            'role_runs' => Schema::hasTable('ai_engineering_company_role_runs'),
            'release_packs' => Schema::hasTable('ai_engineering_company_release_packs'),
            'certifications' => Schema::hasTable('ai_engineering_company_certifications'),
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
                ->limit(self::RECENT_LIMIT)
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
                'engagement_id' => $this->stringOrNull($engagement->engagement_id),
                'status' => $this->stringOrNull($engagement->status),
                'receipt_hash' => $this->stringOrNull($engagement->receipt_hash),
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
    private function externalExecution(CarbonImmutable $since): array
    {
        $tables = [
            'mandates' => Schema::hasTable('ai_holding_external_action_mandates'),
            'work_orders' => Schema::hasTable('ai_holding_external_cutover_work_orders'),
            'work_items' => Schema::hasTable('ai_holding_external_cutover_work_items'),
            'runtime_invocations' => Schema::hasTable('ai_holding_external_cutover_runtime_invocations'),
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
            fn (AiHoldingExternalCutoverWorkItem $workItem): bool => $this->stringOrNull($workItem->bound_receipt_hash) !== null
                || $this->stringOrNull($workItem->receipt_binding_hash) !== null,
        )->count() + $invocations->filter(
            fn (AiHoldingExternalCutoverRuntimeInvocation $invocation): bool => (int) $invocation->execution_receipt_count > 0
                || $this->stringOrNull($invocation->last_execution_receipt_hash) !== null
                || (int) $invocation->manual_closeout_receipt_count > 0
                || $this->stringOrNull($invocation->last_manual_closeout_receipt_hash) !== null,
        )->count();
        $missingReceiptBindings = $workItems->filter(
            fn (AiHoldingExternalCutoverWorkItem $workItem): bool => count((array) $workItem->required_receipt_ids_json) > 0
                && $this->stringOrNull($workItem->bound_receipt_hash) === null
                && $this->stringOrNull($workItem->receipt_binding_hash) === null,
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
            'recent_mandates' => $mandates->take(self::RECENT_LIMIT)->map(fn (AiHoldingExternalActionMandate $mandate): array => [
                'company_id' => $this->stringOrNull($mandate->company_id),
                'flow_id' => $this->stringOrNull($mandate->flow_id),
                'status' => $this->stringOrNull($mandate->status),
                'mandate_packet_hash' => $this->stringOrNull($mandate->mandate_packet_hash),
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

    private function externalExecutionBlocker(string $kind, mixed $companyId, mixed $flowId, mixed $ref): array
    {
        return [
            'kind' => $kind,
            'class' => 'system_blocker',
            'severity' => 'critical',
            'company_id' => $this->stringOrNull($companyId),
            'flow_id' => $this->stringOrNull($flowId),
            'ref' => $this->stringOrNull($ref),
            'detail' => 'External execution or side effects are enabled even though the Atlas AI control plane policy is block-by-default.',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readinessRefs(): array
    {
        return [
            [
                'name' => 'router_runtime',
                'command' => 'php artisan atlas:ai:router-runtime readiness --json',
                'endpoint' => '/ai/router-runtime/readiness',
                'schema' => 'atlas.ai.router_runtime_readiness.v1',
            ],
            [
                'name' => 'hyperflow_specialist_flows',
                'command' => 'php artisan atlas:ai:hyperflow-specialists readiness --json',
                'schema' => 'atlas.ai.hyperflow_specialist_flows_readiness.v1',
            ],
            [
                'name' => 'hyperflow_certification',
                'service' => 'AtlasAiHyperflowCertificationService',
                'schema' => 'atlas.ai.hyperflow_certification.v1',
            ],
            [
                'name' => 'context_intelligence',
                'command' => 'php artisan atlas:context-intelligence:certify --json --strict',
                'schema' => 'atlas.context_intelligence.certification.v1',
            ],
            [
                'name' => 'conversation_ops',
                'command' => 'php artisan atlas:conversation-ops:certify --json --strict',
                'schema' => 'atlas.conversation_ops.certification.v1',
            ],
            [
                'name' => 'persistent_context_runtime',
                'command' => 'php artisan atlas:persistent-context:certify --json --strict',
                'schema' => 'atlas.persistent_context.certification.v1',
            ],
            [
                'name' => 'aemor_runtime',
                'command' => 'php artisan atlas:aemor:certify --json --strict',
                'schema' => 'atlas.aemor.certification.v1',
            ],
            [
                'name' => 'intelligence_factory',
                'command' => 'php artisan atlas:intelligence-factory:certify --json --strict',
                'schema' => 'atlas.intelligence_factory.certification.v1',
            ],
            [
                'name' => 'strategic_reality_engine',
                'command' => 'php artisan atlas:strategic-reality:certify --json --strict',
                'schema' => 'atlas.strategic_reality.certification.v1',
            ],
            [
                'name' => 'runtime_efficiency_governor',
                'command' => 'php artisan atlas:runtime-efficiency:certify --json --strict',
                'schema' => 'atlas.runtime_efficiency_governor.certification.v1',
            ],
            [
                'name' => 'agentic_workcell_runtime',
                'command' => 'php artisan atlas:agentic-workcell:certify --json --strict',
                'schema' => 'atlas.agentic_workcell.certification.v1',
            ],
            [
                'name' => 'autonomous_work_execution_os',
                'command' => 'php artisan atlas:aweos:certify --json --strict',
                'schema' => 'atlas.aweos.certification.v1',
            ],
            [
                'name' => 'verified_execution_runtime',
                'command' => 'php artisan atlas:aver:certify --json --strict',
                'schema' => 'atlas.aver.certification.v1',
            ],
            [
                'name' => 'swarm_company_runtime',
                'service' => 'AtlasRealEngineeringCompanyRuntimeService + AgentControlPlane runtime',
                'schema' => 'atlas.ai.engineering_company.control_plane.v1',
            ],
            [
                'name' => 'governed_external_execution',
                'service' => 'ExternalActionMandateRegistryService',
                'schema' => 'atlas.ai.holding.enterprise_external_action_mandate_registry.v1',
            ],
        ];
    }

    /**
     * @param  array<string,int>  $summary
     * @param  array<int,array<string,mixed>>  $blockers
     */
    private function resolveStatus(array $summary, array $blockers): string
    {
        if (($summary['system_blockers_count'] ?? 0) > 0 || ($summary['failed'] ?? 0) > 0) {
            return self::STATUS_BLOCKED;
        }
        if (
            ($summary['operator_queue_count'] ?? 0) > 0
            || ($summary['external_execution_pending_approval'] ?? 0) > 0
            || ($summary['processing'] ?? 0) > 0
            || ($summary['queued'] ?? 0) > 0
        ) {
            return self::STATUS_WATCH;
        }

        return self::STATUS_HEALTHY;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        $canonical = $payload;
        // Exclude time-sensitive and self-referential fields from hash so the
        // hash is deterministic over the *content* of the report.
        unset($canonical['generated_at'], $canonical['window']['since'], $canonical['window']['until'], $canonical['hash']);
        $canonical = $this->canonicalize($canonical);

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->canonicalize($item);
        }
        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }

    private function flowIdFromTrace(AiTrace $trace): string
    {
        $metadata = is_array($trace->metadata) ? $trace->metadata : [];
        $flow = $this->stringOrNull(data_get($metadata, 'hyperflow_runtime.flow_route.flow_id'))
            ?? $this->stringOrNull(data_get($metadata, 'hyperflow_runtime.flow_id'))
            ?? $this->stringOrNull(data_get($metadata, 'specialist_flow_runtime.flow_id'))
            ?? $this->stringOrNull(data_get($metadata, 'flow_id'))
            ?? $this->stringOrNull($trace->intent);

        return $flow ?? 'unknown';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractDevHandoff(AiTrace $trace): ?array
    {
        $metadata = is_array($trace->metadata) ? $trace->metadata : [];
        $handoffTarget = $this->stringOrNull(data_get($metadata, 'hyperflow_runtime.handoff_target.kind'))
            ?? $this->stringOrNull(data_get($metadata, 'hyperflow_runtime.handoff_target'))
            ?? $this->stringOrNull(data_get($metadata, 'specialist_flow_runtime.delegation.target_flow_id'));
        if ($handoffTarget !== 'atlas_dev') {
            return null;
        }

        return [
            'trace_id' => (string) $trace->id,
            'flow_id' => $this->flowIdFromTrace($trace),
            'handoff_target' => $handoffTarget,
            'delegation_status' => $this->stringOrNull(data_get($metadata, 'specialist_flow_runtime.delegation.status'))
                ?? $this->stringOrNull(data_get($metadata, 'hyperflow_runtime.dispatch.dispatch_status')),
        ];
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,int>
     */
    private function receiptCountsByTraceIds(array $traceIds): array
    {
        // Receipts in this codebase are not directly linked to trace_id; they
        // link to router_decision_id + runtime_dispatch_id. For the per-flow
        // aggregation we approximate: count receipts whose router decision
        // intersects the window. A real join requires a separate index column
        // (future hardening). For now we return an empty map and rely on the
        // global receipts section.
        return [];
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,int>
     */
    private function evidenceCountsByTraceIds(array $traceIds): array
    {
        if (! Schema::hasTable('ai_jobs') || $traceIds === []) {
            return [];
        }
        try {
            $rows = AiJob::query()
                ->whereIn('trace_id', $traceIds)
                ->get(['trace_id', 'context_refs']);
        } catch (Throwable) {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            $traceId = (string) $row->trace_id;
            $refs = is_array($row->context_refs) ? count($row->context_refs) : 0;
            $counts[$traceId] = ($counts[$traceId] ?? 0) + $refs;
        }

        return $counts;
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,int>
     */
    private function handoffCountsByTraceIds(array $traceIds): array
    {
        if (! Schema::hasTable('ai_traces') || $traceIds === []) {
            return [];
        }

        try {
            $traces = AiTrace::query()
                ->whereIn('id', $traceIds)
                ->get(['id', 'metadata']);
        } catch (Throwable) {
            return [];
        }

        $counts = [];
        foreach ($traces as $trace) {
            $traceId = (string) $trace->id;
            $hasDev = $this->extractDevHandoff($trace) !== null;
            $counts[$traceId] = $hasDev ? 1 : 0;
        }

        return $counts;
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,array{status:?string,score:?int}>
     */
    private function qualityByTraceIds(array $traceIds): array
    {
        if (! Schema::hasTable('ai_quality_evaluations') || $traceIds === []) {
            return [];
        }

        try {
            $rows = AiQualityEvaluation::query()
                ->whereIn('trace_id', $traceIds)
                ->get(['trace_id', 'status', 'score']);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $traceId = $this->stringOrNull($row->trace_id);
            if ($traceId === null) {
                continue;
            }
            $out[$traceId] = [
                'status' => $this->stringOrNull($row->status),
                'score' => is_numeric($row->score) ? (int) $row->score : null,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,array{status:?string,score:?int}>  $quality
     * @param  array<int,string>  $traceIds
     * @return array<string,mixed>
     */
    private function aggregateQuality(array $quality, array $traceIds): array
    {
        $byStatus = [];
        $scoreSum = 0;
        $scoreCount = 0;
        foreach ($traceIds as $traceId) {
            $entry = $quality[$traceId] ?? null;
            if ($entry === null) {
                continue;
            }
            $status = $entry['status'] ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            if ($entry['score'] !== null) {
                $scoreSum += $entry['score'];
                $scoreCount++;
            }
        }

        return [
            'evaluated' => $scoreCount,
            'by_status' => $byStatus,
            'average_score' => $scoreCount > 0 ? (int) round($scoreSum / $scoreCount) : null,
        ];
    }

    /**
     * @param  array<string,int>  $map
     * @param  array<int,string>  $keys
     */
    private function sumByKeys(array $map, array $keys): int
    {
        $total = 0;
        foreach ($keys as $key) {
            $total += $map[$key] ?? 0;
        }

        return $total;
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return array<string,int>
     */
    private function countsBy(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy(fn (object $row): string => (string) ($row->{$field} ?? 'unknown'))
            ->map(fn (Collection $group): int => $group->count())
            ->sortKeys()
            ->all();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) <= $max) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, $max - 1).'…';
    }
}
