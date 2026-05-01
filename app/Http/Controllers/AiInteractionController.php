<?php

namespace App\Http\Controllers;

use App\Http\Requests\FeedbackAiTraceRequest;
use App\Http\Requests\StoreAiInteractionRequest;
use App\Http\Resources\AiTraceResource;
use App\Models\AiStreamEvent;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\Telemetry\AiOutcomeAttributionService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiInteractionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $traces = AiTrace::query()
            ->with($this->traceRelations())
            ->when($request->query('thread_id'), fn ($query, $threadId) => $query->where('thread_id', $threadId))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('agent'), fn ($query, $agent) => $query->where('agent_slug', $agent))
            ->orderByDesc('created_at')
            ->limit(min((int) $request->query('limit', 50), 200))
            ->get();

        return response()->json([
            'traces' => AiTraceResource::collection($traces)->resolve(),
        ]);
    }

    public function store(StoreAiInteractionRequest $request, AiGatewayService $gateway): JsonResponse
    {
        $data = $request->validated();
        $trace = $gateway->enqueueInteraction((string) $data['input_text'], $data);

        return response()->json([
            'trace' => (new AiTraceResource($trace))->resolve(),
        ], 202);
    }

    public function show(AiTrace $trace): JsonResponse
    {
        return response()->json([
            'trace' => (new AiTraceResource($trace->load($this->traceRelations(withAttempts: true))))->resolve(),
        ]);
    }

    public function stream(Request $request, AiTrace $trace): StreamedResponse
    {
        $after = max(0, (int) $request->query('after', 0));
        $timeoutSeconds = min(max((int) $request->query('timeout', 120), 5), 600);

        return response()->stream(function () use ($trace, $after, $timeoutSeconds): void {
            if (! Schema::hasTable('ai_stream_events')) {
                $this->sendSse('error', [
                    'error' => 'stream_events_unavailable',
                    'message' => 'ai_stream_events table is not available.',
                ]);

                return;
            }

            $lastSequence = $after;
            $deadline = microtime(true) + $timeoutSeconds;
            $lastHeartbeat = microtime(true);

            while (microtime(true) <= $deadline && ! connection_aborted()) {
                $events = AiStreamEvent::query()
                    ->where('trace_id', $trace->id)
                    ->where('sequence', '>', $lastSequence)
                    ->orderBy('sequence')
                    ->limit(100)
                    ->get();

                foreach ($events as $event) {
                    $lastSequence = max($lastSequence, (int) $event->sequence);
                    $this->sendSse($event->event_type, [
                        'id' => $event->id,
                        'trace_id' => $event->trace_id,
                        'job_id' => $event->ai_job_id,
                        'attempt_id' => $event->ai_job_attempt_id,
                        'sequence' => $event->sequence,
                        'type' => $event->event_type,
                        'channel' => $event->channel,
                        'content' => $event->content,
                        'metadata' => $event->metadata ?? [],
                        'occurred_at' => $event->occurred_at?->toJSON(),
                    ], (string) $event->sequence);
                }

                $freshTrace = $trace->fresh(['jobs']);
                if ($freshTrace && in_array($freshTrace->status, ['succeeded', 'failed', 'cancelled'], true) && $events->isEmpty()) {
                    $this->sendSse('done', [
                        'trace_id' => $freshTrace->id,
                        'status' => $freshTrace->status,
                        'last_sequence' => $lastSequence,
                    ], (string) ($lastSequence + 1));

                    return;
                }

                if (microtime(true) - $lastHeartbeat >= 10) {
                    $this->sendSse('heartbeat', [
                        'trace_id' => $trace->id,
                        'last_sequence' => $lastSequence,
                    ]);
                    $lastHeartbeat = microtime(true);
                }

                usleep(200_000);
            }

            $this->sendSse('timeout', [
                'trace_id' => $trace->id,
                'last_sequence' => $lastSequence,
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function feedback(
        FeedbackAiTraceRequest $request,
        AiTrace $trace,
        AiGatewayService $gateway,
        AiOutcomeAttributionService $outcomes,
        AiTraceMetricAggregator $aggregator,
    ): JsonResponse
    {
        $data = $request->validated();
        $updatedTrace = $gateway->recordFeedback($trace, $data);
        $this->recordFeedbackOutcome($updatedTrace, $data, $outcomes, $aggregator);

        return response()->json([
            'trace' => (new AiTraceResource($updatedTrace))->resolve(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function sendSse(string $event, array $payload, ?string $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }

        echo "event: {$event}\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    /**
     * @return array<int,string>
     */
    private function traceRelations(bool $withAttempts = false): array
    {
        $relations = $withAttempts
            ? ['thread', 'session', 'job.attemptHistory', 'jobs.attemptHistory']
            : ['thread', 'session', 'job', 'jobs'];

        if (Schema::hasTable('ai_quality_evaluations')) {
            $relations[] = 'qualityEvaluation';
        }

        if (Schema::hasTable('ai_quality_actions')) {
            $relations[] = 'qualityActions.remediationTrace';
        }

        if ($withAttempts && Schema::hasTable('ai_stream_events')) {
            $relations[] = 'streamEvents';
        }

        return $relations;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function recordFeedbackOutcome(
        AiTrace $trace,
        array $data,
        AiOutcomeAttributionService $outcomes,
        AiTraceMetricAggregator $aggregator,
    ): void {
        $action = is_string($data['feedback_action'] ?? null) ? $data['feedback_action'] : null;
        $score = is_numeric($data['feedback_score'] ?? null) ? (int) $data['feedback_score'] : null;
        $outcomeType = $this->feedbackOutcomeType($action, $score);
        if (! $outcomeType) {
            return;
        }

        try {
            $outcomes->record([
                'trace_id' => $trace->id,
                'thread_id' => $trace->thread_id,
                'session_id' => $trace->session_id,
                'outcome_type' => $outcomeType,
                'target_type' => 'ai_trace',
                'target_id' => $trace->id,
                'value_score' => $score !== null ? $score * 20 : $this->feedbackOutcomeDefaultScore($outcomeType),
                'confidence' => 1,
                'source' => 'human_feedback',
                'metadata' => [
                    'feedback_action' => $action,
                    'feedback_comment_present' => isset($data['feedback_comment']) && trim((string) $data['feedback_comment']) !== '',
                ],
            ]);

            if (Schema::hasTable('ai_trace_metric_summaries')) {
                $aggregator->recomputeTrace($trace->id);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function feedbackOutcomeType(?string $action, ?int $score): ?string
    {
        return match ($action) {
            'useful' => 'human_marked_useful',
            'wrong_context' => 'human_marked_wrong_context',
            'too_slow' => 'human_marked_too_slow',
            'too_expensive' => 'human_marked_too_expensive',
            'unsafe' => 'human_marked_unsafe',
            'not_useful', 'wrong_agent' => 'human_marked_not_useful',
            'dismissed' => 'human_dismissed',
            default => $score !== null ? ($score >= 4 ? 'human_marked_useful' : 'human_marked_not_useful') : null,
        };
    }

    private function feedbackOutcomeDefaultScore(string $outcomeType): int
    {
        return match ($outcomeType) {
            'human_marked_useful' => 90,
            'human_marked_too_slow', 'human_marked_too_expensive', 'human_dismissed' => 50,
            default => 20,
        };
    }
}
