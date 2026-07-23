<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiQualityAction;
use App\Models\AiTrace;
use App\Services\Ai\Analysis\AiQualityActionService;
use App\Services\Ai\Analysis\AiQualityEvaluator;
use App\Services\Ai\ConversationOps\AiSessionStateService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryCanon;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryRecorder;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;

/**
 * Trace-completion side-effect grab-bag (session-state sync, quality
 * evaluation, remediation completion, native-repair telemetry mirror, trace
 * metric recompute) extracted VERBATIM from AiWorker (GOD-DEBULK entangled
 * -family split). Bodies are 100% byte-identical — these helpers use only their
 * own injected collaborators and app()/facades, so no parent-back-reference is
 * needed. Facade AiWorker keeps same-signature delegators for the hot path.
 */
class CompletionSideEffectsSection
{
    public function __construct(
        private readonly AiSessionStateService $states,
        private readonly AiQualityEvaluator $quality,
        private readonly AiQualityActionService $qualityActions,
    ) {}

    public function updateSessionStateForTrace(AiTrace $trace, string $response): void
    {
        $thread = $trace->thread()->first();
        $session = $trace->session()->first();
        if (! $thread || ! $session) {
            return;
        }

        $this->states->updateForAssistantResponse($thread, $session, $response, [
            'trace_id' => $trace->id,
            'provider' => $trace->provider,
        ]);
    }

    public function evaluateQuality(AiTrace $trace): void
    {
        try {
            $evaluation = $this->quality->evaluateTrace($trace);
            if ($evaluation) {
                $this->qualityActions->planFor($trace, $evaluation);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function completeRemediationActions(AiTrace $trace): void
    {
        if (! DatabaseTableAvailability::has('ai_quality_actions')) {
            return;
        }

        if (! in_array($trace->status, ['succeeded', 'failed', 'cancelled'], true)) {
            return;
        }

        AiQualityAction::query()
            ->where('remediation_trace_id', $trace->id)
            ->whereIn('status', ['queued', 'running'])
            ->get()
            ->each(function (AiQualityAction $action) use ($trace): void {
                $action->update([
                    'status' => $trace->status === 'succeeded' ? 'succeeded' : 'failed',
                    'result' => array_merge($action->result ?? [], [
                        'remediation_trace_status' => $trace->status,
                        'remediation_quality' => data_get($trace->metadata, 'quality'),
                    ]),
                    'error_message' => $trace->status === 'succeeded' ? null : 'Remediation trace finished without success.',
                    'completed_at' => now(),
                ]);
            });
    }

    /**
     * Mirror native programming-repair ledger events into the dedicated
     * Programming Runtime telemetry read-model. The native repair loop writes
     * lifecycle events to the Evidence Ledger, but without this the dedicated
     * aggregate reports zero repair runs — a read-model that lies while the
     * loop actually ran. Best-effort: never blocks the runtime, never carries
     * secrets, no-ops for non-repair event types.
     *
     * @param  array<string,mixed>  $payload
     */
    public function recordNativeRepairTelemetry(
        LedgerEventType $type,
        AiJob $job,
        ?AiJobAttempt $attempt,
        array $payload,
    ): void {
        $executionStatus = match ($type) {
            LedgerEventType::RepairInitiated => 'in_progress',
            LedgerEventType::GatePassed => 'passed',
            LedgerEventType::GateBlocked => 'blocked',
            LedgerEventType::RepairCompleted => match ((string) ($payload['repair_status'] ?? '')) {
                'passed' => 'passed',
                'stopped' => 'blocked',
                default => 'failed', // exhausted
            },
            default => null,
        };
        if ($executionStatus === null) {
            return; // not a native-repair lifecycle event → no telemetry mirror
        }

        try {
            app(ProgrammingRuntimeTelemetryRecorder::class)->record([
                'event_name' => 'repair_attempt_recorded',
                'event_phase' => 'native_programming_repair',
                'flow' => $job->agent_slug ?: $job->kind,
                'selected_core' => ProgrammingRuntimeTelemetryCanon::SELECTED_CORE_DEV,
                'run_id' => $job->trace_id ?: $job->id,
                'execution_status' => $executionStatus,
                'repair_attempt_count' => $payload['current_iteration'] ?? null,
                'metadata' => [
                    'source' => 'ai.worker.native_programming_repair',
                    'ledger_event_type' => $type->value,
                    'repair_status' => $payload['repair_status'] ?? null,
                    'reason' => $payload['reason'] ?? null,
                    'quality_status' => $payload['quality_status'] ?? null,
                    'max_iterations' => $payload['max_iterations'] ?? null,
                    'attempt_number' => $attempt?->attempt_number,
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function recomputeTraceMetrics(?AiTrace $trace): void
    {
        if (! $trace || ! DatabaseTableAvailability::has('ai_trace_metric_summaries')) {
            return;
        }

        try {
            app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

}
