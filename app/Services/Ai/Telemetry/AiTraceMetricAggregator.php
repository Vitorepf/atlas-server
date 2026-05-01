<?php

namespace App\Services\Ai\Telemetry;

use App\Models\AiContextSnapshot;
use App\Models\AiJob;
use App\Models\AiOutcomeLink;
use App\Models\AiTelemetryEvent;
use App\Models\AiTrace;
use App\Models\AiTraceMetricSummary;
use App\Services\Ai\AiProviderModelResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AiTraceMetricAggregator
{
    public function __construct(
        private readonly AiCostEstimator $costEstimator,
        private readonly AiProviderModelResolver $models,
    ) {}

    public function recomputeTrace(string $traceId): AiTraceMetricSummary
    {
        if (! Schema::hasTable('ai_trace_metric_summaries')) {
            throw new \RuntimeException('ai_trace_metric_summaries table is not available.');
        }

        $trace = AiTrace::query()
            ->with(['jobs.attemptHistory', 'qualityEvaluation', 'qualityActions'])
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
            [$autoQualityScore, 0.45],
            [$continuityScore, 0.20],
            [$humanFeedbackScore, 0.20],
            [$outcomeScore, 0.15],
            [$remediationScore, 0.10],
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
        ];

        return AiTraceMetricSummary::query()->updateOrCreate(
            ['trace_id' => $trace->id],
            [
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
                    'aggregator_version' => 'ai_trace_metric_aggregator_v1',
                    'events_count' => $events->count(),
                    'jobs_count' => $jobs->count(),
                    'outcomes_count' => $outcomes->count(),
                ],
                'computed_at' => now(),
            ],
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
}
