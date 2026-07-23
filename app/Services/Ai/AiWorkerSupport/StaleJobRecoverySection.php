<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use App\Models\AiTrace;
use App\Services\Ai\AiWorker;
use App\Services\Ai\Arena\AiCouncilCoordinator;
use App\Services\Ai\HumanSurface\AiExecutionPresentationState;
use App\Services\Ai\Instrumentation\AiWorkerLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stale processing-job recovery family extracted VERBATIM from AiWorker
 * (GOD-DEBULK entangled-family split). Body is byte-identical modulo the
 * parent-back-reference rewrites ($this->emitStreamEvent/$this->isCouncilJob
 * reached via $this->parent). Facade AiWorker keeps a same-signature delegator.
 */
class StaleJobRecoverySection
{
    public function __construct(
        private readonly AiWorker $parent,
        private readonly AiCouncilCoordinator $council,
        private readonly AiExecutionPresentationState $presentationStates,
        private readonly AiWorkerLogger $logger,
    ) {}

    public function recoverStaleProcessingJobs(string $workerId): void
    {
        $councilRecoveries = DB::transaction(function () use ($workerId): array {
            /** @var array<string, array{job_id:int,requeued:bool,final_failure:bool}> $recoveries */
            $recoveries = [];

            $query = AiJob::query()
                ->where('status', 'processing')
                ->orderBy('started_at')
                ->orderBy('reserved_at')
                ->limit(50)
                ->lockForUpdate();

            /** @var Collection<int, AiJob> $jobs */
            $jobs = $query->get();

            foreach ($jobs as $job) {
                $startedAt = $job->started_at ?? $job->reserved_at ?? $job->updated_at ?? $job->created_at;
                $timeoutSeconds = max(60, (int) $job->timeout_seconds) + 60;
                $expiresAt = $startedAt?->copy()->addSeconds($timeoutSeconds);

                if ($expiresAt?->isFuture()) {
                    continue;
                }

                $finalFailure = $job->attempts >= $job->max_attempts;
                $presentationState = $finalFailure
                    ? $this->presentationStates->failed(trace: $job->trace)
                    : $this->presentationStates->staleWorkerRecovery(trace: $job->trace);
                $metadata = array_merge($job->metadata ?? [], [
                    'last_recovered_at' => now()->toJSON(),
                    'last_recovery_worker_id' => $workerId,
                    'recovery_reason' => 'stale_processing_job',
                ]);

                $job->update([
                    'status' => $finalFailure ? 'failed' : 'queued',
                    'reserved_at' => null,
                    'started_at' => null,
                    'worker_id' => null,
                    'available_at' => now(),
                    'finished_at' => $finalFailure ? now() : null,
                    'error_code' => 'worker_timeout',
                    'error_message' => $finalFailure
                        ? 'Worker deixou este job em processamento até expirar todas as tentativas.'
                        : 'Worker deixou este job em processamento; Atlas reabriu a fila automaticamente.',
                    'metadata' => $metadata,
                ]);

                if ($this->parent->isCouncilJob($job)) {
                    if ($job->trace_id) {
                        $recovery = $recoveries[$job->trace_id] ?? [
                            'job_id' => $job->id,
                            'requeued' => false,
                            'final_failure' => false,
                        ];
                        $recovery['requeued'] = $recovery['requeued'] || ! $finalFailure;
                        $recovery['final_failure'] = $recovery['final_failure'] || $finalFailure;
                        $recoveries[$job->trace_id] = $recovery;
                    }
                } else {
                    $job->trace?->update([
                        'status' => $finalFailure ? 'failed' : 'queued',
                        'completed_at' => $finalFailure ? now() : null,
                        'metadata' => array_merge($job->trace->metadata ?? [], [
                            'last_error_code' => 'worker_timeout',
                            'last_error_message' => $job->error_message,
                            'presentation_state' => $presentationState,
                        ]),
                    ]);
                    $this->parent->emitStreamEvent(
                        $job,
                        null,
                        'lifecycle',
                        $finalFailure ? 'execution_failed' : 'execution_recovering',
                        '',
                        ['presentation_state' => $presentationState],
                        'system',
                    );
                }

                $this->logger->event(
                    eventType: $finalFailure ? 'job_failed' : 'job_requeued',
                    message: $finalFailure
                        ? 'AI job stale failed permanently.'
                        : 'AI job stale recovered and requeued.',
                    severity: $finalFailure ? 'error' : 'warning',
                    provider: $job->provider,
                    job: $job,
                    metadata: [
                        'error_code' => 'worker_timeout',
                        'stale_seconds' => $startedAt ? $startedAt->diffInSeconds(now(), true) : null,
                    ],
                    workerId: $workerId,
                );
            }

            return $recoveries;
        });

        foreach ($councilRecoveries as $traceId => $recovery) {
            $trace = AiTrace::query()->find($traceId);
            if (! $trace) {
                continue;
            }

            $synced = $this->council->sync($trace);
            // Council is an aggregate. A stale member may fail while another
            // member is still active, so only publish a terminal state after
            // the aggregate itself is terminal. A real requeue is safe to
            // show immediately because the trace remains processing.
            $presentationState = $synced->status === 'failed' && $recovery['final_failure']
                ? $this->presentationStates->failed(trace: $synced)
                : ($recovery['requeued'] ? $this->presentationStates->staleWorkerRecovery(trace: $synced) : null);
            if (! $presentationState) {
                continue;
            }

            $synced->update([
                'metadata' => array_merge($synced->metadata ?? [], [
                    'presentation_state' => $presentationState,
                ]),
            ]);
            $job = AiJob::query()->find($recovery['job_id']);
            if ($job) {
                $this->parent->emitStreamEvent(
                    $job,
                    null,
                    'lifecycle',
                    $presentationState['kind'] === 'failed' ? 'execution_failed' : 'execution_recovering',
                    '',
                    ['presentation_state' => $presentationState],
                    'system',
                );
            }
        }
    }
}
