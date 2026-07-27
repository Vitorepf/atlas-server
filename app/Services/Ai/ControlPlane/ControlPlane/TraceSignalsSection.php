<?php

declare(strict_types=1);

namespace App\Services\Ai\ControlPlane\ControlPlane;

use App\Models\AiAtlasDecisionReceipt;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiEvidencePack;
use App\Models\AiJob;
use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiRealExecutionForgeHandoff;
use App\Models\AiTrace;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Throwable;
use App\Services\Ai\ControlPlane\AtlasAiControlPlaneService;

final class TraceSignalsSection
{
    public function __construct(private readonly ControlPlaneSupport $support) {}

    /**
     * @return array{total:int,by_status:array<string,int>,by_provider:array<string,int>,by_flow:array<string,int>,ids:array<int,string>}
     */
    public function tracesSection(CarbonImmutable $since): array
    {
        $empty = ['total' => 0, 'by_status' => [], 'by_provider' => [], 'by_flow' => [], 'ids' => []];
        if (! DatabaseTableAvailability::has('ai_traces')) {
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
            $status = $this->support->stringOrNull($trace->status) ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $provider = $this->support->stringOrNull($trace->provider) ?? 'unknown';
            $byProvider[$provider] = ($byProvider[$provider] ?? 0) + 1;
            $flowId = $this->support->flowIdFromTrace($trace);
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
    public function flowsSection(CarbonImmutable $since, array $traceIds): array
    {
        if (! DatabaseTableAvailability::has('ai_traces')) {
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
            $flowId = $this->support->flowIdFromTrace($trace);
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
            $status = $this->support->stringOrNull($trace->status) ?? 'unknown';
            $byFlow[$flowId]['by_status'][$status] = ($byFlow[$flowId]['by_status'][$status] ?? 0) + 1;
            $provider = $this->support->stringOrNull($trace->provider) ?? 'unknown';
            $byFlow[$flowId]['providers'][$provider] = ($byFlow[$flowId]['providers'][$provider] ?? 0) + 1;
            $byFlow[$flowId]['trace_ids'][] = (string) $trace->id;
            $createdAt = $trace->created_at?->toJSON();
            if ($createdAt !== null && ($byFlow[$flowId]['last_seen_at'] === null || $createdAt > $byFlow[$flowId]['last_seen_at'])) {
                $byFlow[$flowId]['last_seen_at'] = $createdAt;
            }
        }

        // Enrich with evidence/receipt/handoff/quality counts per flow.
        $receiptsByTrace = $this->support->receiptCountsByTraceIds($traceIds);
        $evidenceByTrace = $this->support->evidenceCountsByTraceIds($traceIds);
        $qualityByTrace = $this->support->qualityByTraceIds($traceIds);
        $handoffsByTrace = $this->support->handoffCountsByTraceIds($traceIds);

        foreach ($byFlow as $flowId => &$flow) {
            $flow['receipt_count'] = $this->support->sumByKeys($receiptsByTrace, $flow['trace_ids']);
            $flow['evidence_count'] = $this->support->sumByKeys($evidenceByTrace, $flow['trace_ids']);
            $flow['handoff_count'] = $this->support->sumByKeys($handoffsByTrace, $flow['trace_ids']);
            $flow['quality_signals'] = $this->support->aggregateQuality($qualityByTrace, $flow['trace_ids']);
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
    public function recentTraces(CarbonImmutable $since): array
    {
        if (! DatabaseTableAvailability::has('ai_traces')) {
            return [];
        }

        try {
            $traces = AiTrace::query()
                ->where('created_at', '>=', $since)
                ->orderByDesc('created_at')
                ->limit(AtlasAiControlPlaneService::RECENT_LIMIT)
                ->get(['id', 'status', 'provider', 'intent', 'latency_ms', 'created_at', 'metadata']);
        } catch (Throwable) {
            return [];
        }

        return $traces->map(fn (AiTrace $trace): array => [
            'trace_id' => (string) $trace->id,
            'flow_id' => $this->support->flowIdFromTrace($trace),
            'status' => $this->support->stringOrNull($trace->status),
            'provider' => $this->support->stringOrNull($trace->provider),
            'intent' => $this->support->stringOrNull($trace->intent),
            'latency_ms' => $trace->latency_ms,
            'created_at' => $trace->created_at?->toJSON(),
        ])->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function failures(CarbonImmutable $since): array
    {
        if (! DatabaseTableAvailability::has('ai_traces')) {
            return [];
        }

        try {
            $traces = AiTrace::query()
                ->where('created_at', '>=', $since)
                ->where('status', 'failed')
                ->orderByDesc('created_at')
                ->limit(AtlasAiControlPlaneService::RECENT_LIMIT)
                ->with(['job' => fn ($q) => $q->select(['id', 'trace_id', 'error_code', 'error_message', 'attempts'])])
                ->get(['id', 'status', 'provider', 'created_at', 'metadata']);
        } catch (Throwable) {
            return [];
        }

        return $traces->map(function (AiTrace $trace): array {
            $job = $trace->job;

            return [
                'trace_id' => (string) $trace->id,
                'flow_id' => $this->support->flowIdFromTrace($trace),
                'provider' => $this->support->stringOrNull($trace->provider),
                'error_code' => $job?->error_code,
                'error_message' => $this->support->truncate($job?->error_message, 240),
                'attempts' => $job?->attempts,
                'created_at' => $trace->created_at?->toJSON(),
            ];
        })->all();
    }

    /**
     * @param  array<int,string>  $traceIds
     * @return array<string,array<string,mixed>>
     */
    public function handoffs(CarbonImmutable $since, array $traceIds): array
    {
        $devHandoffs = [];
        if (DatabaseTableAvailability::has('ai_traces') && $traceIds !== []) {
            try {
                $traces = AiTrace::query()
                    ->whereIn('id', $traceIds)
                    ->get(['id', 'metadata']);
                foreach ($traces as $trace) {
                    $handoff = $this->support->extractDevHandoff($trace);
                    if ($handoff !== null) {
                        $devHandoffs[] = $handoff;
                    }
                }
            } catch (Throwable) {
                // tolerate; degraded section.
            }
        }

        $forgeHandoffs = [];
        if (DatabaseTableAvailability::has('ai_real_execution_forge_handoffs')) {
            try {
                $rows = AiRealExecutionForgeHandoff::query()
                    ->where('created_at', '>=', $since)
                    ->orderByDesc('created_at')
                    ->limit(AtlasAiControlPlaneService::RECENT_LIMIT)
                    ->get(['id', 'goal_record_id', 'handoff_id', 'status', 'handoff_hash', 'created_at']);
                foreach ($rows as $row) {
                    $forgeHandoffs[] = [
                        'handoff_id' => $this->support->stringOrNull($row->handoff_id),
                        'goal_record_id' => $this->support->stringOrNull($row->goal_record_id),
                        'status' => $this->support->stringOrNull($row->status),
                        'handoff_hash' => $this->support->stringOrNull($row->handoff_hash),
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
                'items' => array_slice($devHandoffs, 0, AtlasAiControlPlaneService::RECENT_LIMIT),
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
    public function receipts(CarbonImmutable $since): array
    {
        $empty = ['status' => 'missing', 'total' => 0, 'by_type' => [], 'recent_hashes' => []];
        if (! DatabaseTableAvailability::has('ai_atlas_decision_receipts')) {
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
                $type = $this->support->stringOrNull($receipt->receipt_type) ?? 'unknown';
                $byType[$type] = ($byType[$type] ?? 0) + 1;
                if (count($recent) < AtlasAiControlPlaneService::RECENT_LIMIT) {
                    $recent[] = [
                        'type' => $type,
                        'hash' => $this->support->stringOrNull($receipt->receipt_hash),
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
    public function evidence(CarbonImmutable $since, array $traceIds): array
    {
        $contextRefsCount = 0;
        $richInputJobs = 0;
        $sourceManifestRefs = 0;

        if (DatabaseTableAvailability::has('ai_jobs') && $traceIds !== []) {
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
        if (DatabaseTableAvailability::has('ai_evidence_packs')) {
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
    public function quality(CarbonImmutable $since, array $traceIds): array
    {
        $empty = ['status' => 'missing', 'total' => 0, 'by_status' => [], 'actions' => ['total' => 0, 'by_status' => []], 'needs_review_recent' => []];
        if (! DatabaseTableAvailability::has('ai_quality_evaluations')) {
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
                $status = $this->support->stringOrNull($eval->status) ?? 'unknown';
                $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
                if (is_numeric($eval->score)) {
                    $scoreSum += (int) $eval->score;
                    $scoreCount++;
                }
                if (in_array($status, ['needs_review', 'failed'], true) && count($needsReview) < AtlasAiControlPlaneService::RECENT_LIMIT) {
                    $flags = collect(is_array($eval->flags) ? $eval->flags : [])
                        ->map(fn ($flag): ?string => is_array($flag) ? ($this->support->stringOrNull($flag['code'] ?? null)) : $this->support->stringOrNull($flag))
                        ->filter()
                        ->values()
                        ->all();
                    $needsReview[] = [
                        'trace_id' => $this->support->stringOrNull($eval->trace_id),
                        'status' => $status,
                        'score' => is_numeric($eval->score) ? (int) $eval->score : null,
                        'flags' => $flags,
                        'created_at' => $eval->created_at?->toJSON(),
                    ];
                }
            }

            $actions = ['total' => 0, 'by_status' => []];
            if (DatabaseTableAvailability::has('ai_quality_actions')) {
                try {
                    $actionRows = AiQualityAction::query()
                        ->where('created_at', '>=', $since)
                        ->get(['id', 'status']);
                    $byActionStatus = [];
                    foreach ($actionRows as $row) {
                        $st = $this->support->stringOrNull($row->status) ?? 'unknown';
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
    public function providerDecisions(CarbonImmutable $since): array
    {
        $empty = ['status' => 'missing', 'total' => 0, 'by_primary_domain' => [], 'by_routing_mode' => []];
        if (! DatabaseTableAvailability::has('ai_atlas_router_decisions')) {
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
                $domain = $this->support->stringOrNull($decision->primary_domain) ?? 'unknown';
                $byDomain[$domain] = ($byDomain[$domain] ?? 0) + 1;
                $mode = $this->support->stringOrNull($decision->routing_mode) ?? 'unknown';
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
    public function contextOperations(array $traceIds): array
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

        if (! DatabaseTableAvailability::has('ai_traces') || $traceIds === []) {
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
            $status = $this->support->stringOrNull(data_get($operations, 'status')) ?? 'unknown';
            $section['by_status'][$status] = ($section['by_status'][$status] ?? 0) + 1;

            $contextStatus = $this->support->stringOrNull(data_get($operations, 'context_intelligence.status'))
                ?? $this->support->stringOrNull(data_get($runtime, 'context_intelligence.status'))
                ?? 'unknown';
            $section['context_intelligence']['by_status'][$contextStatus] = ($section['context_intelligence']['by_status'][$contextStatus] ?? 0) + 1;
            if ($contextStatus === 'blocked') {
                $section['context_intelligence']['blocked']++;
            }
            if ($contextStatus === 'degraded') {
                $section['context_intelligence']['degraded']++;
            }

            $conversationStatus = $this->support->stringOrNull(data_get($operations, 'conversation_ops.status'))
                ?? $this->support->stringOrNull(data_get($runtime, 'conversation_ops.status'))
                ?? 'unknown';
            $section['conversation_ops']['by_status'][$conversationStatus] = ($section['conversation_ops']['by_status'][$conversationStatus] ?? 0) + 1;
            if ($conversationStatus === 'blocked') {
                $section['conversation_ops']['blocked']++;
            }
            if ($conversationStatus === 'watch') {
                $section['conversation_ops']['watch']++;
            }

            $compactionStatus = $this->support->stringOrNull(data_get($operations, 'verified_compaction.status'))
                ?? $this->support->stringOrNull(data_get($runtime, 'verified_compaction.status'));
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

            if (count($section['recent']) < AtlasAiControlPlaneService::RECENT_LIMIT) {
                $section['recent'][] = [
                    'trace_id' => (string) $trace->id,
                    'flow_id' => $this->support->flowIdFromTrace($trace),
                    'status' => $status,
                    'context_status' => $contextStatus,
                    'conversation_status' => $conversationStatus,
                    'verified_compaction_status' => $compactionStatus,
                    'operations_runtime_hash' => $this->support->stringOrNull(data_get($operations, 'operations_runtime_hash')),
                    'created_at' => $trace->created_at?->toJSON(),
                ];
            }
        }

        return $section;
    }
}
