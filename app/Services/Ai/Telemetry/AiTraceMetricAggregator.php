<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiContextSnapshot;
use App\Models\AiDecision;
use App\Models\AiJob;
use App\Models\AiOutcomeLink;
use App\Models\AiRouterDecision;
use App\Models\AiSpecialistFlowExecution;
use App\Models\AiTelemetryEvent;
use App\Models\AiToolEvent;
use App\Models\AiTrace;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\AiProviderModelResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AiTraceMetricAggregator
{
    /**
     * Weights applied to each quality component when composing final_quality_score.
     *
     * Sums to 1.00. weightedScore() renormalizes by the sum of present (non-null) weights,
     * so when components are missing the remaining weights pro-rate. Keep the sum exact
     * so the declared weights match each component's actual contribution when fully covered.
     *
     * Values were derived as the legacy weights (0.45/0.20/0.20/0.15/0.10) divided by their
     * legacy sum (1.10), then rounded to two decimals. This preserves historical scores
     * within ±1 point in the all-components-present case (the dominant production scenario).
     */
    public const QUALITY_SCORE_WEIGHTS = [
        'auto_quality' => 0.41,
        'continuity' => 0.18,
        'human_feedback' => 0.18,
        'outcome' => 0.14,
        'remediation' => 0.09,
    ];

    public function __construct(
        private readonly AiCostEstimator $costEstimator,
        private readonly AiProviderModelResolver $models,
    ) {}

    public function recomputeTrace(string $traceId): AiTraceMetricSummary
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            throw new \RuntimeException('ai_trace_metric_summaries table is not available.');
        }

        // Conditional eager-loads — older test fixtures and partial schemas may
        // not have these tables yet. Skipping when the table is missing prevents
        // "no such table" errors. Downstream code uses schema-safe accessors.
        $eagerLoads = ['jobs.attemptHistory', 'qualityEvaluation', 'qualityActions'];
        if (Schema::hasTable('ai_router_decisions')) {
            $eagerLoads[] = 'routerDecision';
        }
        if (Schema::hasTable('ai_decisions')) {
            $eagerLoads[] = 'atlasDecision';
        }
        if (Schema::hasTable('ai_specialist_flow_executions')) {
            $eagerLoads[] = 'specialistFlowExecution';
        }
        if (Schema::hasTable('ai_tool_events')) {
            $eagerLoads[] = 'toolEvents';
        }

        $trace = AiTrace::query()
            ->with($eagerLoads)
            ->findOrFail($traceId);

        $jobs = $trace->jobs;
        $job = $jobs->first();
        $clientId = $this->clientId($trace, $jobs);
        $events = $this->eventsFor($trace);
        $context = $this->contextSignals($trace);
        $outcomes = $this->outcomesFor($trace);
        $quality = $trace->qualityEvaluation;
        $actions = $trace->qualityActions;
        $cost = $this->costEstimator->estimate($trace, $job instanceof AiJob ? $job : null);

        $appSendToAccept = $this->eventDuration($events, 'interaction_accepted')
            ?? $this->eventDelta($events, 'message_send_pressed', 'interaction_accepted');
        $appSendToVisible = $this->eventDuration($events, 'trace_visible_in_ui')
            ?? $this->eventDelta($events, 'message_send_pressed', 'trace_visible_in_ui');
        $queueWait = $this->queueWaitMs($jobs);
        $providerLatency = $this->providerLatencyMs($jobs) ?? $trace->latency_ms;
        $firstToken = $this->eventDuration($events, 'provider_first_token')
            ?? $this->firstTokenMs($trace, $jobs);
        $totalLatency = $trace->latency_ms
            ?? $this->diffMs($trace->created_at, $trace->completed_at)
            ?? $appSendToVisible
            ?? $providerLatency;

        $flags = $this->qualityFlags($quality?->flags ?? []);
        $neededRemediation = $actions->isNotEmpty() || in_array($quality?->status, ['needs_review', 'failed'], true);
        $reaskDetected = $outcomes->contains('outcome_type', 'user_reasked_same_intent')
            || $events->contains('event_name', 'outcome_reask_detected');
        $providerSwitchedAfterResponse = $outcomes->contains('outcome_type', 'provider_switched_after_bad_answer')
            || $events->contains('event_name', 'thread_switched_while_trace_active');
        $firstPassSuccess = $trace->status === 'succeeded' && ! $neededRemediation && ! $reaskDetected;

        $autoQualityScore = $this->autoQualityScore($trace, $quality?->score);
        $humanFeedbackScore = $this->humanFeedbackScore($trace->feedback_score, $trace->feedback_action);
        $outcomeScore = $this->outcomeScore($outcomes);
        $continuityScore = $this->continuityScore(
            $trace,
            $events,
            $flags,
            (bool) $context['compaction_used'],
            (bool) $context['provider_handoff_used'],
            $reaskDetected,
            $providerSwitchedAfterResponse,
        );
        $remediationScore = $this->remediationScore($actions, $neededRemediation);
        $contextEfficiencyScore = $this->contextEfficiencyScore(
            $autoQualityScore,
            $continuityScore,
            $context['context_tokens'],
            $context['context_refs_count'],
            $context['useful_context_refs_count'],
            $flags,
        );
        $finalQualityScore = $this->weightedScore([
            [$autoQualityScore, self::QUALITY_SCORE_WEIGHTS['auto_quality']],
            [$continuityScore, self::QUALITY_SCORE_WEIGHTS['continuity']],
            [$humanFeedbackScore, self::QUALITY_SCORE_WEIGHTS['human_feedback']],
            [$outcomeScore, self::QUALITY_SCORE_WEIGHTS['outcome']],
            [$remediationScore, self::QUALITY_SCORE_WEIGHTS['remediation']],
        ]);
        $finalEfficiencyScore = $this->weightedScore([
            [$this->latencyScore($totalLatency), 0.35],
            [$this->costScore($cost['cost_microusd'], $cost['cost_confidence']), 0.20],
            [$firstPassSuccess ? 100 : 45, 0.25],
            [$contextEfficiencyScore, 0.20],
        ]);

        $scoreComponents = [
            'quality' => [
                'auto_quality' => $autoQualityScore,
                'continuity' => $continuityScore,
                'human_feedback' => $humanFeedbackScore,
                'outcome' => $outcomeScore,
                'remediation' => $remediationScore,
                'final' => $finalQualityScore,
            ],
            'efficiency' => [
                'latency' => $this->latencyScore($totalLatency),
                'cost' => $this->costScore($cost['cost_microusd'], $cost['cost_confidence']),
                'cost_confidence' => $cost['cost_confidence'],
                'cost_source' => $cost['cost_source'],
                'cost_mode' => $cost['cost_mode'],
                'first_pass_success' => $firstPassSuccess ? 100 : 45,
                'context_efficiency' => $contextEfficiencyScore,
                'final' => $finalEfficiencyScore,
            ],
            'context' => $context,
            'flags' => $flags,
            // Router signal — surfaces what the router decided so the daily report can
            // break quality/efficiency by router_mode and detect bad routing patterns.
            // signals/reason are the rich attribution payload kept inside score_components
            // (not promoted to columns) since they're free-form JSON for diagnosis.
            'router' => $this->routerDiagnostics($this->routerDecisionFor($trace)),
            'specialist_flow' => $this->specialistFlowDiagnostics($trace, $jobs),
            'atlas_decide' => $this->atlasDecideDiagnostics($trace, $jobs),
            // Diagnostic: telemetry events grouped by event_phase. Forward-looking signal —
            // when phases get differentiated (pre_provider/provider/post_provider) the
            // aggregator already has the count breakdown without further changes.
            'diagnostics' => [
                'events_by_phase' => $this->eventsByPhase($events),
                'numeric_signals' => $this->numericSignals($events),
            ],
            // Tools — populated when ai_tool_events table exists AND the trace has
            // tool events. Otherwise an "available => false" stub so consumers can
            // distinguish "no tools used" from "tool data not captured for this trace".
            // See routerDiagnostics for the same pattern.
            'tools' => $this->toolDiagnosticsFor($trace),
        ];

        // Router columns are conditionally written. They were added in
        // migration 2026_05_01_005000 — older test fixtures and deploys without
        // that migration applied still have the table but not the columns.
        // Schema::hasColumn check keeps the aggregator schema-tolerant.
        $routerDecision = $this->routerDecisionFor($trace);
        $routerColumns = Schema::hasColumn('ai_trace_metric_summaries', 'router_mode')
            ? [
                'router_mode' => $routerDecision?->mode,
                'router_selected_provider' => $routerDecision?->selected_provider,
                'router_fallback_provider' => $routerDecision?->fallback_provider,
                'router_was_overridden' => (bool) ($routerDecision?->was_overridden ?? false),
            ]
            : [];

        return AiTraceMetricSummary::query()->updateOrCreate(
            ['trace_id' => $trace->id],
            array_merge([
                'thread_id' => $trace->thread_id,
                'session_id' => $trace->session_id,
                'client_id' => $clientId,
                'surface' => $this->surface($trace, $events, $job instanceof AiJob ? $job : null),
                'runtime' => $events->pluck('runtime')->filter()->first(),
                'provider' => $trace->provider ?: $job?->provider,
                'model' => $this->models->resolve($trace->provider ?: $job?->provider, $trace->model ?: $job?->model),
                'agent_slug' => $trace->agent_slug ?: $job?->agent_slug,
                'task_type' => $this->taskType($trace, $job instanceof AiJob ? $job : null),
                'status' => $trace->status,
                'app_send_to_accept_ms' => $appSendToAccept,
                'app_send_to_visible_ms' => $appSendToVisible,
                'queue_wait_ms' => $queueWait,
                'first_token_ms' => $firstToken,
                'provider_latency_ms' => $providerLatency,
                'total_latency_ms' => $totalLatency,
                'backgrounded_during_run' => $events->contains('event_name', 'app_backgrounded_during_trace'),
                'recovered_from_pending' => $events->contains('event_name', 'pending_submission_recovered'),
                'prompt_tokens' => $cost['prompt_tokens'],
                'completion_tokens' => $cost['completion_tokens'],
                'total_tokens' => $cost['total_tokens'],
                'estimated_tokens' => $cost['estimated_tokens'],
                'token_source' => $cost['token_source'],
                'cost_microusd' => $cost['cost_microusd'],
                'cost_confidence' => $cost['cost_confidence'],
                'cost_source' => $cost['cost_source'],
                'cost_mode' => $cost['cost_mode'],
                'context_tokens' => $context['context_tokens'],
                'context_refs_count' => $context['context_refs_count'],
                'useful_context_refs_count' => $context['useful_context_refs_count'],
                'compaction_used' => $context['compaction_used'],
                'provider_handoff_used' => $context['provider_handoff_used'],
                'context_efficiency_score' => $contextEfficiencyScore,
                'auto_quality_score' => $autoQualityScore,
                'continuity_score' => $continuityScore,
                'human_feedback_score' => $humanFeedbackScore,
                'outcome_score' => $outcomeScore,
                'remediation_score' => $remediationScore,
                'final_quality_score' => $finalQualityScore,
                'final_efficiency_score' => $finalEfficiencyScore,
                'first_pass_success' => $firstPassSuccess,
                'needed_remediation' => $neededRemediation,
                'remediation_count' => $actions->count(),
                'reask_detected' => $reaskDetected,
                'provider_switched_after_response' => $providerSwitchedAfterResponse,
                'score_components' => $scoreComponents,
                'metadata' => [
                    // v3 bump: traces aggregated after Atlas Decide multi-stage work carry
                    // score_components.atlas_decide. v2 introduced score_components.tools.
                    // Statistical analysis layer (planned Phase 5 engine) uses this as a
                    // discriminator so trend tests don't compare older traces missing
                    // diagnostics with newer traces in the same window.
                    // See docs/atlas-ai-aggregator-versions.md for the changelog.
                    'aggregator_version' => AiTraceMetricAggregatorVersions::CURRENT,
                    'events_count' => $events->count(),
                    'jobs_count' => $jobs->count(),
                    'outcomes_count' => $outcomes->count(),
                    'tool_events_count' => ($scoreComponents['tools']['available'] ?? false)
                        ? (int) ($scoreComponents['tools']['tool_calls_total'] ?? 0)
                        : 0,
                ],
                'computed_at' => now(),
            ], $routerColumns),
        );
    }

    public function recomputeWindow(CarbonInterface $since, ?CarbonInterface $until = null): int
    {
        $until ??= now();
        $count = 0;

        AiTrace::query()
            ->where('created_at', '>=', $since)
            ->where('created_at', '<=', $until)
            ->orderBy('created_at')
            ->pluck('id')
            ->each(function (string $traceId) use (&$count): void {
                $this->recomputeTrace($traceId);
                $count++;
            });

        return $count;
    }

    private function clientId(AiTrace $trace, Collection $jobs): ?string
    {
        $jobClientId = $jobs->pluck('client_id')->filter()->first();
        if (is_string($jobClientId) && $jobClientId !== '') {
            return $jobClientId;
        }

        if (! Schema::hasTable('ai_telemetry_events')) {
            return null;
        }

        return AiTelemetryEvent::query()
            ->where('trace_id', $trace->id)
            ->whereNotNull('client_id')
            ->orderBy('received_at')
            ->value('client_id');
    }

    private function eventsFor(AiTrace $trace): Collection
    {
        if (! Schema::hasTable('ai_telemetry_events')) {
            return collect();
        }

        $events = AiTelemetryEvent::query()
            ->where('trace_id', $trace->id)
            ->orderBy('received_at')
            ->get();
        $correlationIds = $events->pluck('correlation_id')->filter()->unique()->values();
        if ($correlationIds->isNotEmpty()) {
            $events = AiTelemetryEvent::query()
                ->where(function ($query) use ($trace, $correlationIds): void {
                    $query->where('trace_id', $trace->id)
                        ->orWhereIn('correlation_id', $correlationIds->all());
                })
                ->orderBy('received_at')
                ->get();
        }

        return $events->unique('id')->values();
    }

    private function outcomesFor(AiTrace $trace): Collection
    {
        if (! Schema::hasTable('ai_outcome_links')) {
            return collect();
        }

        return AiOutcomeLink::query()
            ->where('trace_id', $trace->id)
            ->orderBy('occurred_at')
            ->get();
    }

    private function contextSignals(AiTrace $trace): array
    {
        $contextRefs = is_array($trace->context_refs) ? count($trace->context_refs) : null;
        $snapshot = null;

        if (Schema::hasTable('ai_context_snapshots')) {
            $snapshot = AiContextSnapshot::query()
                ->where('trace_id', $trace->id)
                ->latest('created_at')
                ->first();
        }

        $usefulRefs = data_get($trace->metadata, 'context_pack.useful_refs_count')
            ?? data_get($snapshot?->metadata, 'useful_context_refs_count');
        $usefulRefs = is_numeric($usefulRefs) ? max(0, (int) $usefulRefs) : null;

        return [
            'context_tokens' => $snapshot?->token_estimate,
            'context_refs_count' => $contextRefs,
            'useful_context_refs_count' => $usefulRefs,
            'compaction_used' => $snapshot?->compaction_id !== null || data_get($trace->metadata, 'resume_compaction_id') !== null,
            'provider_handoff_used' => $snapshot?->provider_handoff_id !== null || data_get($trace->metadata, 'provider_handoff_id') !== null,
        ];
    }

    private function surface(AiTrace $trace, Collection $events, ?AiJob $job): string
    {
        $surface = $events->pluck('surface')->filter()->first();
        if (is_string($surface) && $surface !== '') {
            return $surface;
        }

        $appSurface = data_get($trace->metadata, 'app_surface') ?? data_get($job?->payload, 'app_surface');
        if ($appSurface === 'atlas_cli') {
            return 'cli';
        }
        if ($appSurface === 'atlas_ai_sheet' || $trace->source_type === 'app') {
            return 'mobile';
        }

        return 'server';
    }

    private function taskType(AiTrace $trace, ?AiJob $job): ?string
    {
        return data_get($trace->metadata, 'atlas_workflow_mode')
            ?? data_get($job?->payload, 'atlas_workflow_mode')
            ?? $job?->kind
            ?? $trace->intent
            ?? $trace->source_type;
    }

    private function eventDuration(Collection $events, string $eventName): ?int
    {
        $value = $events->firstWhere('event_name', $eventName)?->duration_ms;

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function eventDelta(Collection $events, string $from, string $to): ?int
    {
        $start = $events->firstWhere('event_name', $from)?->received_at;
        $end = $events->firstWhere('event_name', $to)?->received_at;

        return $this->diffMs($start, $end);
    }

    private function queueWaitMs(Collection $jobs): ?int
    {
        return $jobs
            ->map(fn (AiJob $job): ?int => $this->diffMs($job->created_at, $job->started_at ?? $job->reserved_at))
            ->filter(fn (?int $value): bool => $value !== null)
            ->min();
    }

    private function providerLatencyMs(Collection $jobs): ?int
    {
        $latencies = $jobs
            ->map(fn (AiJob $job): ?int => $this->diffMs($job->started_at, $job->finished_at))
            ->filter(fn (?int $value): bool => $value !== null)
            ->values();

        if ($latencies->isNotEmpty()) {
            return (int) $latencies->max();
        }

        return $jobs
            ->flatMap(fn (AiJob $job): Collection => $job->attemptHistory)
            ->pluck('duration_ms')
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->max();
    }

    private function firstTokenMs(AiTrace $trace, Collection $jobs): ?int
    {
        $firstToken = $trace->streamEvents()
            ->whereIn('event_type', ['token', 'response'])
            ->orderBy('occurred_at')
            ->first();
        $startedAt = $jobs
            ->pluck('started_at')
            ->filter()
            ->sortBy(fn (\DateTimeInterface $date): int => $this->epochMs($date))
            ->first();

        return $this->diffMs($startedAt, $firstToken?->occurred_at);
    }

    private function diffMs(mixed $start, mixed $end): ?int
    {
        if (! $start || ! $end || ! $start instanceof \DateTimeInterface || ! $end instanceof \DateTimeInterface) {
            return null;
        }

        return max(0, $this->epochMs($end) - $this->epochMs($start));
    }

    private function epochMs(\DateTimeInterface $value): int
    {
        return ((int) $value->format('U') * 1000) + (int) floor(((int) $value->format('u')) / 1000);
    }

    private function qualityFlags(array $flags): array
    {
        return collect($flags)
            ->map(fn (mixed $flag): ?string => is_array($flag) && is_string($flag['code'] ?? null) ? $flag['code'] : null)
            ->filter()
            ->values()
            ->all();
    }

    private function autoQualityScore(AiTrace $trace, ?int $qualityScore): int
    {
        if ($qualityScore !== null) {
            return $this->clampScore($qualityScore);
        }

        return match ($trace->status) {
            'succeeded' => 72,
            'failed', 'cancelled' => 20,
            default => 50,
        };
    }

    private function humanFeedbackScore(?int $feedbackScore, ?string $feedbackAction): ?int
    {
        if ($feedbackScore !== null) {
            return $this->clampScore($feedbackScore * 20);
        }

        return match ($feedbackAction) {
            'useful' => 90,
            'not_useful', 'wrong_agent', 'wrong_context', 'unsafe' => 20,
            'too_slow', 'too_expensive' => 45,
            'dismissed' => 50,
            default => null,
        };
    }

    private function continuityScore(
        AiTrace $trace,
        Collection $events,
        array $flags,
        bool $compactionUsed,
        bool $providerHandoffUsed,
        bool $reaskDetected,
        bool $providerSwitchedAfterResponse,
    ): int {
        $score = 62;
        if ($trace->thread_id) {
            $score += 12;
        }
        if ($events->contains('event_name', 'pending_submission_recovered')) {
            $score += 8;
        }
        if ($events->contains('event_name', 'outcome_conversation_continued')) {
            $score += 10;
        }
        if ($compactionUsed) {
            $score += 6;
        }
        if ($providerHandoffUsed) {
            $score += 6;
        }
        if (array_intersect($flags, ['lost_continuity', 'wrong_context', 'context_leak', 'internal_prompt_leak'])) {
            $score -= 30;
        }
        if ($reaskDetected) {
            $score -= 20;
        }
        if ($providerSwitchedAfterResponse) {
            $score -= 12;
        }

        return $this->clampScore($score);
    }

    private function outcomeScore(Collection $outcomes): ?int
    {
        if ($outcomes->isEmpty()) {
            return null;
        }

        $scores = $outcomes->map(function (AiOutcomeLink $outcome): int {
            if ($outcome->value_score !== null) {
                return $this->clampScore((int) $outcome->value_score);
            }

            return match ($outcome->outcome_type) {
                'user_reasked_same_intent', 'user_abandoned_thread', 'provider_switched_after_bad_answer' => 25,
                'human_marked_wrong_context', 'human_marked_unsafe' => 15,
                'human_marked_not_useful' => 25,
                'human_marked_too_slow', 'human_marked_too_expensive' => 45,
                'human_dismissed' => 50,
                'conversation_continued' => 70,
                'human_marked_useful' => 90,
                default => 85,
            };
        });

        return (int) round($scores->avg());
    }

    private function remediationScore(Collection $actions, bool $neededRemediation): ?int
    {
        if (! $neededRemediation) {
            return 100;
        }
        if ($actions->isEmpty()) {
            return 45;
        }
        if ($actions->contains('status', 'completed')) {
            return 75;
        }
        if ($actions->contains(fn ($action): bool => in_array($action->status, ['failed', 'blocked'], true))) {
            return 30;
        }

        return 55;
    }

    private function contextEfficiencyScore(
        int $qualityScore,
        int $continuityScore,
        ?int $contextTokens,
        ?int $contextRefsCount,
        ?int $usefulContextRefsCount,
        array $flags,
    ): int {
        $compactness = match (true) {
            $contextTokens === null => 70,
            $contextTokens <= 4000 => 100,
            $contextTokens <= 12000 => 80,
            $contextTokens <= 30000 => 55,
            default => 30,
        };
        $relevance = $usefulContextRefsCount !== null && $contextRefsCount
            ? (int) round(min(1, $usefulContextRefsCount / max(1, $contextRefsCount)) * 100)
            : 70;
        $leakPenalty = array_intersect($flags, ['context_leak', 'internal_prompt_leak']) ? 25 : 0;

        return $this->clampScore((int) round(
            $qualityScore * 0.30
            + $continuityScore * 0.30
            + $compactness * 0.25
            + $relevance * 0.15
            - $leakPenalty
        ));
    }

    private function latencyScore(?int $durationMs): int
    {
        return match (true) {
            $durationMs === null => 65,
            $durationMs <= 5_000 => 100,
            $durationMs <= 30_000 => 82,
            $durationMs <= 120_000 => 55,
            $durationMs <= 300_000 => 35,
            default => 20,
        };
    }

    private function costScore(?int $costMicrousd, string $confidence): int
    {
        if ($costMicrousd === null || $confidence === 'unknown') {
            return 70;
        }

        return match (true) {
            $costMicrousd <= 1_000 => 100,
            $costMicrousd <= 10_000 => 90,
            $costMicrousd <= 100_000 => 70,
            $costMicrousd <= 500_000 => 45,
            default => 25,
        };
    }

    /**
     * @param  array<int,array{0:?int,1:float}>  $parts
     */
    private function weightedScore(array $parts): int
    {
        $weighted = 0.0;
        $weight = 0.0;

        foreach ($parts as [$score, $partWeight]) {
            if ($score === null) {
                continue;
            }
            $weighted += $this->clampScore($score) * $partWeight;
            $weight += $partWeight;
        }

        return $weight > 0 ? $this->clampScore((int) round($weighted / $weight)) : 0;
    }

    private function clampScore(int $score): int
    {
        return max(0, min(100, $score));
    }

    /**
     * Schema-safe accessor for $trace->routerDecision. Returns null when the
     * ai_router_decisions table is missing (legacy schema, partial test fixtures)
     * instead of throwing "no such table". This keeps the aggregator runnable on
     * any subset of the metric stack.
     */
    private function routerDecisionFor(AiTrace $trace): ?AiRouterDecision
    {
        if (! Schema::hasTable('ai_router_decisions')) {
            return null;
        }

        return $trace->routerDecision;
    }

    /**
     * Schema-safe accessor for $trace->atlasDecision. The Atlas Decide decision
     * table is optional in older installs and in narrow test fixtures.
     */
    private function atlasDecisionFor(AiTrace $trace): ?AiDecision
    {
        if (! Schema::hasTable('ai_decisions')) {
            return null;
        }

        return $trace->atlasDecision;
    }

    /**
     * Project Atlas Decide into a telemetry block that can compare planned vs.
     * actual provider execution. This is intentionally JSON, not columns: the
     * stage graph is sparse, provider-specific, and evolves faster than summary
     * dimensions.
     *
     * @param  Collection<int,AiJob>  $jobs
     * @return array<string,mixed>
     */
    private function atlasDecideDiagnostics(AiTrace $trace, Collection $jobs): array
    {
        $decision = $this->atlasDecisionFor($trace);
        $traceMetadata = is_array($trace->metadata) ? $trace->metadata : [];
        $traceExecution = data_get($traceMetadata, 'atlas_decide_execution');
        $traceExecution = is_array($traceExecution) ? $traceExecution : [];
        $stageExecutions = $jobs
            ->map(fn (AiJob $job): ?array => $this->atlasJobExecution($job))
            ->filter()
            ->sortBy(fn (array $execution): int => match ($execution['stage'] ?? null) {
                'context_scout' => 10,
                'primary_executor' => 20,
                default => 30,
            })
            ->values();

        if (! $decision instanceof AiDecision && $traceExecution === [] && $stageExecutions->isEmpty()) {
            return ['available' => false];
        }

        $scout = $stageExecutions->firstWhere('stage', 'context_scout');
        $executor = $stageExecutions->firstWhere('stage', 'primary_executor');
        $fallbacks = $this->providerFallbacks($trace, $jobs);
        $dependencyState = data_get($traceExecution, 'dependency_state')
            ?? data_get($executor, 'dependency_state')
            ?? data_get($scout, 'dependency_state');
        $runtimeStrategy = data_get($traceExecution, 'strategy');
        $executionStrategy = $decision?->execution_strategy ?? $runtimeStrategy;
        $contextStrategy = $decision?->context_strategy ?? data_get($traceExecution, 'context_strategy');
        $scoutPlanned = $scout !== null
            || $contextStrategy === 'gemini_scout_then_executor'
            || $executionStrategy === 'scout_then_execute_planned'
            || $runtimeStrategy === 'scout_then_execute';
        $scoutEnabled = $scout !== null
            || $runtimeStrategy === 'scout_then_execute'
            || is_string(data_get($traceExecution, 'dependency_provider'));
        $scoutProvider = $scoutEnabled
            ? (data_get($scout, 'provider') ?? data_get($traceExecution, 'dependency_provider'))
            : null;
        if ($scoutEnabled && (! is_string($scoutProvider) || $scoutProvider === '')) {
            $scoutProvider = 'gemini_cli';
        }
        $scoutPlannedProvider = $scoutProvider;
        if ($scoutPlanned && (! is_string($scoutPlannedProvider) || $scoutPlannedProvider === '')) {
            $scoutPlannedProvider = 'gemini_cli';
        }
        $scoutModel = data_get($scout, 'model') ?? data_get($traceExecution, 'dependency_model');

        return [
            'available' => true,
            'decision_available' => $decision instanceof AiDecision,
            'decision_mode' => $decision?->decision_mode,
            'route_mode' => $decision?->route_mode,
            'task_type' => $decision?->task_type,
            'risk_level' => $decision?->risk_level,
            'context_strategy' => $contextStrategy,
            'execution_strategy' => $executionStrategy,
            'runtime_strategy' => $runtimeStrategy,
            'activation_status' => data_get($traceExecution, 'activation_status')
                ?? data_get($decision?->execution_graph, 'activation_status'),
            'blocked_reason' => data_get($traceExecution, 'blocked_reason')
                ?? data_get($decision?->execution_graph, 'activation_blocked_reason'),
            'dependency_state' => $dependencyState,
            'scout_planned' => $scoutPlanned,
            'scout_enabled' => $scoutEnabled,
            'scout_planned_provider' => $scoutPlannedProvider,
            'scout_provider' => $scoutProvider,
            'scout_model' => $scoutModel,
            'scout_status' => data_get($scout, 'status'),
            'executor_provider' => data_get($executor, 'provider') ?? $decision?->selected_provider,
            'executor_model' => data_get($executor, 'model') ?? $decision?->selected_model,
            'executor_status' => data_get($executor, 'status'),
            'selected_provider' => $decision?->selected_provider,
            'selected_model' => $decision?->selected_model,
            'fallback_provider' => $decision?->fallback_provider,
            'was_overridden' => $decision instanceof AiDecision ? (bool) $decision->was_overridden : null,
            'confidence_score' => $decision?->confidence_score,
            'quality_gates' => data_get($decision?->execution_graph, 'quality_gates', []),
            'providers_used' => $this->providersUsed($jobs),
            'provider_sequence' => $stageExecutions->all(),
            'fallbacks' => $fallbacks,
            'degraded' => in_array($dependencyState, ['degraded', 'failed'], true) || $fallbacks !== [],
            'task_profile' => is_array($decision?->task_profile) ? $decision->task_profile : [],
            'signals' => is_array($decision?->signals) ? $decision->signals : [],
        ];
    }

    private function atlasJobExecution(AiJob $job): ?array
    {
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $payload = is_array($job->payload) ? $job->payload : [];
        $metadataExecution = data_get($metadata, 'atlas_decide_execution');
        $payloadExecution = data_get($payload, 'atlas_decide_execution');
        $execution = is_array($metadataExecution)
            ? $metadataExecution
            : (is_array($payloadExecution) ? $payloadExecution : []);
        $stage = data_get($metadata, 'atlas_decide_stage') ?: data_get($execution, 'atlas_decide_stage');
        $dependencyState = data_get($metadata, 'dependency_state') ?: data_get($execution, 'dependency_state');

        if (! is_string($stage) && $execution === [] && ! is_string($dependencyState)) {
            return null;
        }

        return [
            'job_id' => $job->id,
            'stage' => $stage,
            'strategy' => data_get($execution, 'strategy'),
            'provider' => $job->provider,
            'model' => $job->model,
            'status' => $job->status,
            'kind' => $job->kind,
            'priority' => $job->priority,
            'attempts' => $job->attempts,
            'duration_ms' => $this->diffMs($job->started_at, $job->finished_at),
            'dependency_state' => $dependencyState,
            'dependency_job_id' => data_get($metadata, 'dependency_job_id') ?: data_get($execution, 'dependency_job_id'),
            'dependent_job_id' => data_get($metadata, 'dependent_job_id') ?: data_get($execution, 'dependent_job_id'),
        ];
    }

    /**
     * @param  Collection<int,AiJob>  $jobs
     * @return array<int,string>
     */
    private function providersUsed(Collection $jobs): array
    {
        $jobProviders = $jobs->pluck('provider');
        $attemptProviders = $jobs
            ->flatMap(fn (AiJob $job): Collection => $job->relationLoaded('attemptHistory') ? $job->attemptHistory : collect())
            ->pluck('provider');

        return $jobProviders
            ->merge($attemptProviders)
            ->filter(fn (mixed $provider): bool => is_string($provider) && $provider !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AiJob>  $jobs
     * @return array<int,array<string,mixed>>
     */
    private function providerFallbacks(AiTrace $trace, Collection $jobs): array
    {
        $traceMetadata = is_array($trace->metadata) ? $trace->metadata : [];
        $fallbacks = collect([data_get($traceMetadata, 'provider_fallback')]);

        $jobs->each(function (AiJob $job) use ($fallbacks): void {
            $payload = is_array($job->payload) ? $job->payload : [];
            $metadata = is_array($job->metadata) ? $job->metadata : [];
            $fallbacks->push(data_get($payload, 'provider_fallback'));
            $fallbacks->push(data_get($metadata, 'provider_fallback'));
        });

        return $fallbacks
            ->filter(fn (mixed $fallback): bool => is_array($fallback))
            ->values()
            ->all();
    }

    /**
     * Schema-safe accessor for $trace->toolEvents. Returns empty Collection
     * when ai_tool_events is missing — same pattern as routerDecisionFor.
     */
    private function toolEventsFor(AiTrace $trace): Collection
    {
        if (! Schema::hasTable('ai_tool_events')) {
            return collect();
        }

        return $trace->toolEvents ?? collect();
    }

    /**
     * Project the trace's tool events into a structured diagnostic block.
     *
     * Output (when tool events present):
     *   - available: true
     *   - tools_used: distinct tool count
     *   - tool_calls_total: total event count
     *   - tool_failures: events with exit_code != 0 OR error present
     *   - permission_denied_count / permission_approved_count
     *   - total_duration_ms: sum across all tool calls
     *   - per_tool: [{ tool, count, failures, denied, duration_ms }]
     *   - risk_distribution: { low, medium, high, critical }
     *   - changed_files_count: distinct files touched
     *
     * Output when no tool events: { available: false }. Consumers distinguish
     * "no tools used" from "tool data not captured" by this flag.
     *
     * @return array<string,mixed>
     */
    private function toolDiagnosticsFor(AiTrace $trace): array
    {
        $events = $this->toolEventsFor($trace);
        if ($events->isEmpty()) {
            return ['available' => false];
        }

        $perTool = $events
            ->groupBy('tool')
            ->map(fn (Collection $group): array => [
                'count' => $group->count(),
                'failures' => $group->filter(fn (AiToolEvent $e): bool => $this->isToolFailure($e))->count(),
                'denied' => $group->where('permission_status', 'denied')->count(),
                'duration_ms' => (int) $group->sum('duration_ms'),
            ])
            ->map(fn (array $stats, string $tool): array => array_merge(['tool' => $tool], $stats))
            ->values()
            ->all();

        $riskDistribution = $events
            ->groupBy('risk')
            ->map(fn (Collection $group): int => $group->count())
            ->all();

        // Distinct changed_files across all tool events. JSON column → array via cast.
        $changedFiles = $events
            ->flatMap(fn (AiToolEvent $e): array => is_array($e->changed_files) ? $e->changed_files : [])
            ->unique()
            ->values()
            ->all();

        return [
            'available' => true,
            'tools_used' => count($perTool),
            'tool_calls_total' => $events->count(),
            'tool_failures' => $events->filter(fn (AiToolEvent $e): bool => $this->isToolFailure($e))->count(),
            'permission_denied_count' => $events->where('permission_status', 'denied')->count(),
            'permission_approved_count' => $events->where('permission_status', 'approved')->count(),
            'total_duration_ms' => (int) $events->sum('duration_ms'),
            'per_tool' => $perTool,
            'risk_distribution' => array_merge([
                // Always emit all 4 levels so downstream consumers don't need null
                // checks. Zero count is a real signal (no critical risk operations).
                'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0,
            ], $riskDistribution),
            'changed_files_count' => count($changedFiles),
        ];
    }

    /**
     * A tool event is "failed" when exit_code != 0 OR an error message is present.
     * Either signal alone is sufficient — some failure paths set error without
     * exit_code (runtime exceptions, permission_denied), others set exit_code
     * without error (process returned non-zero with empty stderr).
     */
    private function isToolFailure(AiToolEvent $event): bool
    {
        if ($event->exit_code !== null && $event->exit_code !== 0) {
            return true;
        }

        return is_string($event->error) && $event->error !== '';
    }

    /**
     * Project the router decision into the score_components.router block.
     * Lives in JSON (not promoted columns) because signals/reason are free-form
     * diagnostic payload, not aggregation keys.
     *
     * @return array<string,mixed>
     */
    private function routerDiagnostics(?AiRouterDecision $decision): array
    {
        if (! $decision) {
            return [
                'available' => false,
            ];
        }

        return [
            'available' => true,
            'schema_version' => $decision->schema_version,
            'surface_id' => $decision->surface_id,
            'flow_id' => $decision->flow_id,
            'flow_origin' => $decision->flow_origin,
            'command_intent' => $decision->command_intent,
            'routing_reason' => $decision->routing_reason,
            'routing_confidence' => $decision->routing_confidence,
            'workspace_present' => (bool) $decision->workspace_present,
            'mode' => $decision->mode,
            'selected_provider' => $decision->selected_provider,
            'fallback_provider' => $decision->fallback_provider,
            'was_overridden' => (bool) $decision->was_overridden,
            'reason' => $decision->reason,
            'signals' => is_array($decision->signals) ? $decision->signals : [],
            'handoff_payload' => is_array($decision->handoff_payload) ? $decision->handoff_payload : [],
            'alternative_flow_ids' => is_array($decision->alternative_flow_ids) ? $decision->alternative_flow_ids : [],
        ];
    }

    /**
     * Project the specialist flow runtime contract into score_components.
     *
     * @return array<string,mixed>
     */
    private function specialistFlowDiagnostics(AiTrace $trace, Collection $jobs): array
    {
        $record = $this->specialistFlowExecutionRecordFor($trace);
        if ($record instanceof AiSpecialistFlowExecution) {
            return [
                'available' => true,
                'source' => 'ai_specialist_flow_executions',
                'record_id' => $record->id,
                'schema_version' => $record->runtime_schema_version,
                'flow_id' => $record->flow_id,
                'owner' => data_get($record->runtime_payload, 'owner'),
                'execution_mode' => data_get($record->runtime_payload, 'execution_mode'),
                'side_effect_policy' => data_get($record->runtime_payload, 'side_effect_policy'),
                'workspace_present' => (bool) data_get($record->runtime_payload, 'workspace_present', false),
                'delegation' => is_array($record->delegation) ? $record->delegation : [],
                'required_evidence' => is_array(data_get($record->runtime_payload, 'required_evidence')) ? data_get($record->runtime_payload, 'required_evidence') : [],
                'output_contract' => is_array(data_get($record->runtime_payload, 'output_contract')) ? data_get($record->runtime_payload, 'output_contract') : [],
                'forbidden_actions' => is_array(data_get($record->runtime_payload, 'forbidden_actions')) ? data_get($record->runtime_payload, 'forbidden_actions') : [],
                'receipt' => is_array($record->receipt) ? $record->receipt : [],
                'execution' => [
                    'available' => true,
                    'schema_version' => $record->execution_schema_version,
                    'status' => $record->status,
                    'handler_id' => $record->handler_id,
                    'handler_version' => $record->handler_version,
                    'runtime_receipt_id' => $record->runtime_receipt_id,
                    'runtime_contract_hash' => $record->runtime_contract_hash,
                    'audit_checks' => is_array($record->audit_checks) ? $record->audit_checks : [],
                    'response_shape' => is_array($record->response_shape) ? $record->response_shape : [],
                    'quality_rubric' => is_array(data_get($record->execution_payload, 'quality_rubric')) ? data_get($record->execution_payload, 'quality_rubric') : [],
                    'completion_checks' => is_array(data_get($record->execution_payload, 'completion_checks')) ? data_get($record->execution_payload, 'completion_checks') : [],
                    'failure_modes' => is_array(data_get($record->execution_payload, 'failure_modes')) ? data_get($record->execution_payload, 'failure_modes') : [],
                ],
            ];
        }

        foreach ($jobs as $job) {
            $runtime = data_get($job->payload, 'specialist_flow_runtime');
            if (! is_array($runtime) || $runtime === []) {
                continue;
            }

            return [
                'available' => true,
                'source' => 'ai_job_payload',
                'schema_version' => data_get($runtime, 'schema_version'),
                'flow_id' => data_get($runtime, 'flow_id'),
                'owner' => data_get($runtime, 'owner'),
                'execution_mode' => data_get($runtime, 'execution_mode'),
                'side_effect_policy' => data_get($runtime, 'side_effect_policy'),
                'workspace_present' => (bool) data_get($runtime, 'workspace_present', false),
                'delegation' => is_array(data_get($runtime, 'delegation')) ? data_get($runtime, 'delegation') : [],
                'required_evidence' => is_array(data_get($runtime, 'required_evidence')) ? data_get($runtime, 'required_evidence') : [],
                'output_contract' => is_array(data_get($runtime, 'output_contract')) ? data_get($runtime, 'output_contract') : [],
                'forbidden_actions' => is_array(data_get($runtime, 'forbidden_actions')) ? data_get($runtime, 'forbidden_actions') : [],
                'receipt' => is_array(data_get($runtime, 'receipt')) ? data_get($runtime, 'receipt') : [],
                'execution' => $this->specialistFlowExecutionForJob($job),
            ];
        }

        return [
            'available' => false,
        ];
    }

    private function specialistFlowExecutionRecordFor(AiTrace $trace): ?AiSpecialistFlowExecution
    {
        if (! Schema::hasTable('ai_specialist_flow_executions')) {
            return null;
        }

        return $trace->specialistFlowExecution;
    }

    /**
     * @return array<string,mixed>
     */
    private function specialistFlowExecutionForJob(AiJob $job): array
    {
        $execution = data_get($job->payload, 'specialist_flow_execution');
        if (! is_array($execution) || $execution === []) {
            return [
                'available' => false,
            ];
        }

        return [
            'available' => true,
            'schema_version' => data_get($execution, 'schema_version'),
            'status' => data_get($execution, 'status'),
            'handler_id' => data_get($execution, 'handler_id'),
            'handler_version' => data_get($execution, 'handler_version'),
            'runtime_receipt_id' => data_get($execution, 'runtime_receipt_id'),
            'runtime_contract_hash' => data_get($execution, 'runtime_contract_hash'),
            'audit_checks' => is_array(data_get($execution, 'audit_checks')) ? data_get($execution, 'audit_checks') : [],
            'response_shape' => is_array(data_get($execution, 'response_shape')) ? data_get($execution, 'response_shape') : [],
            'quality_rubric' => is_array(data_get($execution, 'quality_rubric')) ? data_get($execution, 'quality_rubric') : [],
            'completion_checks' => is_array(data_get($execution, 'completion_checks')) ? data_get($execution, 'completion_checks') : [],
            'failure_modes' => is_array(data_get($execution, 'failure_modes')) ? data_get($execution, 'failure_modes') : [],
        ];
    }

    /**
     * Count of telemetry events grouped by event_phase. Today event_phase is mostly
     * 'server' across the gateway, so the breakdown is shallow — but the aggregator
     * captures it now so a future split into 'pre_provider'/'provider'/'post_provider'
     * is immediately diagnosable from the report without re-instrumenting.
     *
     * @param  Collection<int,AiTelemetryEvent>  $events
     * @return array<string,int>
     */
    private function eventsByPhase(Collection $events): array
    {
        return $events
            ->groupBy(fn (AiTelemetryEvent $event): string => (string) ($event->event_phase ?? 'unspecified'))
            ->map(fn (Collection $group): int => $group->count())
            ->all();
    }

    /**
     * Surfaces telemetry events that carry a numeric_value + unit, keeping at most
     * 12 to stay within JSON budget. Today the CLI emits input_chars; future emitters
     * can publish anything (response_chars, queue_depth, retry_count) and they show
     * up in the report without aggregator changes.
     *
     * @param  Collection<int,AiTelemetryEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    private function numericSignals(Collection $events): array
    {
        return $events
            ->filter(fn (AiTelemetryEvent $event): bool => $event->numeric_value !== null)
            ->take(12)
            ->map(fn (AiTelemetryEvent $event): array => [
                'event_name' => $event->event_name,
                'phase' => $event->event_phase,
                'unit' => $event->unit,
                'value' => is_numeric($event->numeric_value) ? (float) $event->numeric_value : null,
            ])
            ->values()
            ->all();
    }
}
