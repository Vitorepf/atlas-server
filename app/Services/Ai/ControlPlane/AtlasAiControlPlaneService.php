<?php

declare(strict_types=1);

namespace App\Services\Ai\ControlPlane;

use App\Models\AiAtlasDecisionReceipt;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiAtlasRuntimeDispatch;
use App\Models\AiEvidencePack;
use App\Models\AiJob;
use App\Models\AiOperatorApproval;
use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiRealExecutionForgeHandoff;
use App\Models\AiTrace;
use App\Services\Ai\Learning\AtlasAiLearningLoopService;
use App\Services\Ai\OperatorApproval\OperatorApprovalCanon;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use Carbon\CarbonImmutable;
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
        $blockers = $this->blockers($since, $tracesSection['ids']);
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
            'handoffs_count' => $handoffs['dev']['count'] + $handoffs['forge']['count'],
            'failures_count' => count($failures),
            'context_operations_blockers_count' => $contextOperations['blockers_count'],
            'verified_compactions_count' => $contextOperations['verified_compaction']['total'],
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
                        $blockers[] = [
                            'kind' => 'dispatch_blocker',
                            'dispatch_id' => (string) $dispatch->id,
                            'dispatch_status' => $this->stringOrNull($dispatch->dispatch_status),
                            'detail' => is_array($reason) ? ($this->stringOrNull($reason['reason'] ?? null) ?? 'unspecified') : (string) $reason,
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
        ];
    }

    /**
     * @param  array<string,int>  $summary
     * @param  array<int,array<string,mixed>>  $blockers
     */
    private function resolveStatus(array $summary, array $blockers): string
    {
        if ($blockers !== [] || ($summary['failed'] ?? 0) > 0) {
            return self::STATUS_BLOCKED;
        }
        if (($summary['processing'] ?? 0) > 0 || ($summary['queued'] ?? 0) > 0) {
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
