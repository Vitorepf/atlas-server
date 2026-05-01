<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiQualityAction;
use App\Models\AiTrace;
use App\Services\AuditLogService;
use App\Services\Ai\Mobile\JobResultInboxEmitter;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use App\Services\Semantic\CaptureSemanticClarifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiWorker
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly AiWorkerLogger $logger,
        private readonly AiStreamRecorder $stream,
        private readonly AiPermissionEngine $permissions,
        private readonly AiCouncilCoordinator $council,
        private readonly AiConversationRecorder $conversation,
        private readonly AiSessionStateService $states,
        private readonly AiQualityEvaluator $quality,
        private readonly AiQualityActionService $qualityActions,
        private readonly CaptureSemanticClarifier $clarifier,
        private readonly AiProviderModelResolver $models,
        private readonly AuditLogService $audit,
        private readonly JobResultInboxEmitter $jobResults,
        private readonly AiProviderChoiceBuilder $choices,
    ) {}

    public function runNext(?string $providerOverride = null, ?string $workerId = null, ?callable $onStream = null): ?AiJob
    {
        return $this->runNextMatching(null, $providerOverride, $workerId, $onStream);
    }

    public function runNextForTrace(string $traceId, ?string $providerOverride = null, ?string $workerId = null, ?callable $onStream = null): ?AiJob
    {
        return $this->runNextMatching($traceId, $providerOverride, $workerId, $onStream);
    }

    private function runNextMatching(?string $traceId = null, ?string $providerOverride = null, ?string $workerId = null, ?callable $onStream = null): ?AiJob
    {
        $workerId = $workerId ?: (string) config('atlas.ai.worker_id', 'atlas-worker');
        $this->recoverStaleProcessingJobs($workerId);

        $job = $this->claimJob($workerId, $providerOverride, $traceId);

        if (! $job) {
            return null;
        }

        if ($this->isCouncilJob($job)) {
            $this->council->sync($job->trace()->firstOrFail());
        }

        $providerKey = $providerOverride ?: $job->provider ?: (string) config('atlas.ai.default_provider', 'claude_cli');
        $job = $this->ensureJobModelIdentity($job, $providerKey);
        $provider = $this->providers->get($providerKey);
        $permission = $this->permissions->authorizeJob($job, $providerKey);

        if ($permission->allowed) {
            $job = $this->applyPermissionRuntime($job, $permission);
            $job = $this->applyPendingSteer($job);
        }

        $attempt = $this->createAttempt($job, $workerId, $providerKey);
        $this->emitStreamEvent($job, $attempt, 'permission', $permission->allowed ? 'permission_allowed' : 'permission_denied', $permission->denialMessage(), [
            'permission' => $permission->toArray(),
        ], null, $onStream);

        if (! $permission->allowed) {
            $result = new AiProviderResult(
                ok: false,
                output: '',
                command: [],
                exitCode: null,
                durationMs: 0,
                stdout: '',
                stderr: '',
                errorCode: 'permission_denied',
                errorMessage: $permission->denialMessage(),
                metadata: ['permission' => $permission->toArray()],
            );
        } else {
            try {
                $this->recordTelemetry('provider_call_started', $job, $attempt, [
                    'event_phase' => 'provider',
                    'metadata' => [
                        'worker_id' => $workerId,
                        'attempt_number' => $attempt->attempt_number,
                    ],
                ]);
                $firstTokenRecorded = false;
                $result = $provider->runStreaming($job, $job->prompt, function (array $event) use ($job, $attempt, $onStream, &$firstTokenRecorded): void {
                    $recorded = $this->stream->recordProviderEvent($job, $attempt, $event);
                    if (! $firstTokenRecorded && in_array(($event['type'] ?? null), ['token', 'response'], true)) {
                        $firstTokenRecorded = true;
                        $this->recordTelemetry('provider_first_token', $job, $attempt, [
                            'event_phase' => 'provider',
                            'duration_ms' => $this->diffMs($attempt->started_at, now()),
                            'metadata' => [
                                'stream_event_type' => $event['type'] ?? null,
                                'stream_event_name' => $event['name'] ?? null,
                                'sequence' => $recorded?->sequence,
                            ],
                        ]);
                    }
                    $event['sequence'] = $recorded?->sequence;
                    $event['job_id'] = $job->id;
                    $event['trace_id'] = $job->trace_id;
                    $event['attempt_id'] = $attempt->id;
                    $onStream?->__invoke($event);
                });
            } catch (\Throwable $exception) {
                $this->emitStreamEvent($job, $attempt, 'error', 'provider_exception', $exception->getMessage(), [
                    'error_code' => 'provider_exception',
                ], null, $onStream);

                $result = new AiProviderResult(
                    ok: false,
                    output: '',
                    command: [],
                    exitCode: null,
                    durationMs: 0,
                    stdout: '',
                    stderr: '',
                    errorCode: 'provider_exception',
                    errorMessage: $exception->getMessage(),
                );
            }
        }

        $result = $this->withPermissionMetadata($result, $permission);

        return $this->completeAttempt($job, $attempt, $result, $workerId);
    }

    private function claimJob(string $workerId, ?string $providerOverride, ?string $traceId = null): ?AiJob
    {
        return DB::transaction(function () use ($workerId, $providerOverride, $traceId): ?AiJob {
            $query = AiJob::query()
                ->where('status', 'queued')
                ->where('available_at', '<=', now())
                ->orderBy('priority')
                ->orderBy('created_at')
                ->lockForUpdate();

            if ($providerOverride) {
                $query->where('provider', $providerOverride);
            }

            if ($traceId) {
                $query->where('trace_id', $traceId);
            }

            /** @var AiJob|null $job */
            $job = $query->first();
            if (! $job) {
                return null;
            }

            $job->update([
                'status' => 'processing',
                'reserved_at' => now(),
                'started_at' => now(),
                'worker_id' => $workerId,
                'attempts' => $job->attempts + 1,
            ]);

            $job->trace?->update(['status' => 'processing']);
            $this->logger->event('job_claimed', 'AI job claimed by worker.', 'info', $job->provider, $job, workerId: $workerId);
            $this->recordTelemetry('job_claimed', $job->refresh(), null, [
                'event_phase' => 'worker',
                'metadata' => [
                    'worker_id' => $workerId,
                    'attempt_number' => $job->attempts,
                    'provider_override' => $providerOverride,
                ],
            ]);

            return $job->refresh()->load('trace');
        });
    }

    private function recoverStaleProcessingJobs(string $workerId): void
    {
        $councilTraceIds = DB::transaction(function () use ($workerId): array {
            $traceIds = [];

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

                if ($this->isCouncilJob($job)) {
                    if ($job->trace_id) {
                        $traceIds[] = $job->trace_id;
                    }
                } else {
                    $job->trace?->update([
                        'status' => $finalFailure ? 'failed' : 'queued',
                        'completed_at' => $finalFailure ? now() : null,
                        'metadata' => array_merge($job->trace->metadata ?? [], [
                            'last_error_code' => 'worker_timeout',
                            'last_error_message' => $job->error_message,
                        ]),
                    ]);
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

            return array_values(array_unique($traceIds));
        });

        foreach ($councilTraceIds as $traceId) {
            $trace = AiTrace::query()->find($traceId);
            if ($trace) {
                $this->council->sync($trace);
            }
        }
    }

    private function createAttempt(AiJob $job, string $workerId, string $providerKey): AiJobAttempt
    {
        return AiJobAttempt::query()->create([
            'ai_job_id' => $job->id,
            'attempt_number' => $job->attempts,
            'worker_id' => $workerId,
            'provider' => $providerKey,
            'model' => $job->model,
            'command' => [],
            'prompt_hash' => hash('sha256', $job->prompt),
            'status' => 'processing',
            'started_at' => now(),
            'metadata' => [],
        ]);
    }

    private function ensureJobModelIdentity(AiJob $job, string $providerKey): AiJob
    {
        $resolution = $this->models->resolveWithSource($providerKey, $job->model);
        if (! $resolution['model']) {
            return $job;
        }

        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $updates = [
            'model' => $resolution['model'],
            'payload' => array_merge($payload, [
                'model_identity_source' => $resolution['source'],
            ]),
            'metadata' => array_merge($metadata, [
                'model_identity_source' => $resolution['source'],
            ]),
        ];

        if ($job->model !== $resolution['model']) {
            $job->forceFill($updates)->save();
        } elseif (data_get($metadata, 'model_identity_source') !== $resolution['source']) {
            $job->forceFill([
                'payload' => $updates['payload'],
                'metadata' => $updates['metadata'],
            ])->save();
        }

        $trace = $job->trace ?: $job->trace()->first();
        if ($trace && ! $this->isCouncilJob($job) && ! $trace->model) {
            $trace->forceFill([
                'model' => $resolution['model'],
                'metadata' => array_merge($trace->metadata ?? [], [
                    'model_identity_source' => $resolution['source'],
                ]),
            ])->save();
        }

        return $job->refresh()->load('trace');
    }

    private function completeAttempt(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        $responseHash = $result->output !== '' ? hash('sha256', $result->output) : null;
        $attemptStatus = $result->ok ? 'succeeded' : ($result->errorCode === 'timeout' ? 'timeout' : 'failed');
        $job->refresh();

        if ($job->status === 'cancelled') {
            $attempt->update([
                'command' => $result->command,
                'command_hash' => $result->command ? hash('sha256', json_encode($result->command, JSON_THROW_ON_ERROR)) : null,
                'response_hash' => $responseHash,
                'status' => 'cancelled',
                'exit_code' => $result->exitCode,
                'duration_ms' => $result->durationMs,
                'output_text' => Str::limit($result->output, 20000, ''),
                'stdout_excerpt' => Str::limit($result->stdout, 4000, '...'),
                'stderr_excerpt' => Str::limit($result->stderr, 4000, '...'),
                'error_code' => 'cancelled_by_operator',
                'error_message' => 'Resultado ignorado porque o operador cancelou o job durante a execução.',
                'finished_at' => now(),
                'metadata' => array_merge($result->metadata, [
                    'ignored_provider_result' => true,
                    'provider_result_ok' => $result->ok,
                    'provider_error_code' => $result->errorCode,
                ]),
            ]);

            $this->logger->event(
                eventType: 'job_cancelled',
                message: 'AI job provider result ignored because the job was cancelled.',
                severity: 'warning',
                provider: $attempt->provider,
                job: $job,
                attempt: $attempt,
                workerId: $workerId,
            );
            $this->recordTelemetry('job_cancelled', $job, $attempt, [
                'event_phase' => 'worker',
                'duration_ms' => $result->durationMs,
                'metadata' => [
                    'worker_id' => $workerId,
                    'provider_result_ok' => $result->ok,
                ],
            ]);

            return $job->load(['trace', 'attemptHistory']);
        }

        $attempt->update([
            'command' => $result->command,
            'command_hash' => $result->command ? hash('sha256', json_encode($result->command, JSON_THROW_ON_ERROR)) : null,
            'response_hash' => $responseHash,
            'status' => $attemptStatus,
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMs,
            'output_text' => Str::limit($result->output, 20000, ''),
            'stdout_excerpt' => Str::limit($result->stdout, 4000, '...'),
            'stderr_excerpt' => Str::limit($result->stderr, 4000, '...'),
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage ? Str::limit($result->errorMessage, 2000, '...') : null,
            'finished_at' => now(),
            'metadata' => $result->metadata,
        ]);
        $this->recordTelemetry($result->ok ? 'provider_call_succeeded' : 'provider_call_failed', $job, $attempt, [
            'event_phase' => 'provider',
            'duration_ms' => $result->durationMs,
            'metadata' => [
                'worker_id' => $workerId,
                'exit_code' => $result->exitCode,
                'error_code' => $result->errorCode,
            ],
        ]);

        if ($result->ok) {
            $job->update([
                'status' => 'succeeded',
                'result_text' => $result->output,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            try {
                $this->clarifier->completeAiClarification($job->refresh());
            } catch (\Throwable $exception) {
                report($exception);
            }

            if ($this->isCouncilJob($job)) {
                $synced = $this->council->sync($job->trace()->firstOrFail());
                if ($synced->status === 'succeeded' && $synced->response_text) {
                    $this->conversation->recordAssistantMessage($synced, $synced->response_text, [
                        'source' => 'ai_council_coordinator',
                        'execution_policy' => 'dual_review',
                    ]);
                    $this->updateSessionStateForTrace($synced, $synced->response_text);
                    $this->evaluateQuality($synced);
                    $this->completeRemediationActions($synced);
                    $this->recordTelemetry('trace_completed', $job, $attempt, [
                        'event_key' => 'worker:trace_completed:'.$synced->id.':'.$synced->status,
                        'event_phase' => 'worker',
                        'duration_ms' => $synced->latency_ms,
                        'metadata' => [
                            'worker_id' => $workerId,
                            'trace_status' => $synced->status,
                            'execution_policy' => 'dual_review',
                        ],
                    ]);
                    $this->recomputeTraceMetrics($synced);
                }
                $this->logger->event('job_succeeded', 'AI council job completed successfully.', 'info', $attempt->provider, $job, $attempt, workerId: $workerId);
                $this->recordTelemetry('job_succeeded', $job, $attempt, [
                    'event_phase' => 'worker',
                    'duration_ms' => $result->durationMs,
                    'metadata' => [
                        'worker_id' => $workerId,
                        'execution_policy' => 'dual_review',
                    ],
                ]);
                $this->audit->record('ai_job_succeeded', [
                    'subject_type' => 'ai_job',
                    'subject_id' => $job->id,
                    'summary' => "Job de conselho IA concluido por {$attempt->provider}.",
                    'evidence' => [
                        'agent_slug' => $job->agent_slug,
                        'provider' => $attempt->provider,
                        'model' => $attempt->model,
                        'duration_ms' => $result->durationMs,
                        'response_hash' => $responseHash,
                        'result_text' => $result->output,
                        'council_role' => data_get($job->payload, 'council_role'),
                    ],
                    'privacy' => $this->privacyFromJob($job),
                    'refs' => [
                        'trace_id' => $job->trace_id,
                        'job_id' => $job->id,
                        'attempt_id' => $attempt->id,
                    ],
                ]);

                $this->emitImportantJobResult($job->refresh(), 'succeeded');

                return $job->refresh()->load(['trace', 'attemptHistory']);
            }

            $job->trace?->update([
                'status' => 'succeeded',
                'provider' => $attempt->provider,
                'model' => $attempt->model,
                'response_hash' => $responseHash,
                'response_text' => $result->output,
                'latency_ms' => $result->durationMs,
                'completed_at' => now(),
            ]);

            $trace = $job->trace?->refresh();
            if ($trace?->response_text) {
                $this->conversation->recordAssistantMessage($trace, $trace->response_text, [
                    'source' => 'ai_worker',
                    'attempt_id' => $attempt->id,
                    'job_id' => $job->id,
                ]);
                $this->updateSessionStateForTrace($trace, $trace->response_text);
                $this->evaluateQuality($trace);
                $this->completeRemediationActions($trace);
            }

            $this->logger->event('job_succeeded', 'AI job completed successfully.', 'info', $attempt->provider, $job, $attempt, workerId: $workerId);
            $this->recordTelemetry('job_succeeded', $job, $attempt, [
                'event_phase' => 'worker',
                'duration_ms' => $result->durationMs,
                'metadata' => [
                    'worker_id' => $workerId,
                ],
            ]);
            if ($trace) {
                $this->recordTelemetry('trace_completed', $job, $attempt, [
                    'event_key' => 'worker:trace_completed:'.$trace->id.':'.$trace->status,
                    'event_phase' => 'worker',
                    'duration_ms' => $trace->latency_ms,
                    'metadata' => [
                        'worker_id' => $workerId,
                        'trace_status' => $trace->status,
                    ],
                ]);
                $this->recomputeTraceMetrics($trace);
            }
            $this->audit->record('ai_job_succeeded', [
                'subject_type' => 'ai_job',
                'subject_id' => $job->id,
                'summary' => "Job de IA concluido por {$attempt->provider}.",
                'evidence' => [
                    'agent_slug' => $job->agent_slug,
                    'provider' => $attempt->provider,
                    'model' => $attempt->model,
                    'duration_ms' => $result->durationMs,
                    'response_hash' => $responseHash,
                    'result_text' => $result->output,
                ],
                'privacy' => $this->privacyFromJob($job),
                'refs' => [
                    'trace_id' => $job->trace_id,
                    'job_id' => $job->id,
                    'attempt_id' => $attempt->id,
                ],
            ]);

            $this->emitImportantJobResult($job->refresh(), 'succeeded');

            return $job->refresh()->load(['trace', 'attemptHistory']);
        }

        if ($this->shouldPauseForChoice($job, $result)) {
            return $this->pauseForChoice($job, $attempt, $result, $workerId);
        }

        $nonRetryable = in_array($result->errorCode, ['permission_denied'], true);
        $finalFailure = $nonRetryable || $job->attempts >= $job->max_attempts;
        $job->update([
            'status' => $finalFailure ? 'failed' : 'queued',
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'available_at' => $finalFailure ? $job->available_at : now()->addSeconds((int) config('atlas.ai.retry_delay_seconds', 300)),
            'reserved_at' => $finalFailure ? $job->reserved_at : null,
            'started_at' => $finalFailure ? $job->started_at : null,
            'worker_id' => $finalFailure ? $job->worker_id : null,
            'finished_at' => $finalFailure ? now() : null,
        ]);

        if ($this->isCouncilJob($job)) {
            $synced = $this->council->sync($job->trace()->firstOrFail());
            if ($finalFailure && $synced->status === 'failed') {
                $this->completeRemediationActions($synced);
            }
            $this->logger->event(
                eventType: $finalFailure ? 'job_failed' : 'job_requeued',
                message: $finalFailure ? 'AI council job failed permanently.' : 'AI council job failed and was requeued.',
                severity: $finalFailure ? 'error' : 'warning',
                provider: $attempt->provider,
                job: $job,
                attempt: $attempt,
                metadata: ['error_code' => $result->errorCode],
                workerId: $workerId,
            );
            $this->recordTelemetry($finalFailure ? 'job_failed' : 'job_requeued', $job, $attempt, [
                'event_phase' => 'worker',
                'duration_ms' => $result->durationMs,
                'metadata' => [
                    'worker_id' => $workerId,
                    'error_code' => $result->errorCode,
                    'execution_policy' => 'dual_review',
                ],
            ]);
            $this->audit->record($finalFailure ? 'ai_job_failed' : 'ai_job_requeued', [
                'subject_type' => 'ai_job',
                'subject_id' => $job->id,
                'severity' => $finalFailure ? 'error' : 'warning',
                'summary' => $finalFailure ? 'Job de conselho IA falhou permanentemente.' : 'Job de conselho IA falhou e foi reenfileirado.',
                'evidence' => [
                    'agent_slug' => $job->agent_slug,
                    'provider' => $attempt->provider,
                    'model' => $attempt->model,
                    'error_code' => $result->errorCode,
                    'error_message' => $result->errorMessage,
                    'stderr_excerpt' => $result->stderr,
                    'council_role' => data_get($job->payload, 'council_role'),
                ],
                'privacy' => $this->privacyFromJob($job),
                'refs' => [
                    'trace_id' => $job->trace_id,
                    'job_id' => $job->id,
                    'attempt_id' => $attempt->id,
                ],
            ]);

            if ($finalFailure) {
                $this->recordTelemetry('trace_completed', $job, $attempt, [
                    'event_key' => 'worker:trace_completed:'.$synced->id.':'.$synced->status,
                    'event_phase' => 'worker',
                    'duration_ms' => $synced->latency_ms,
                    'metadata' => [
                        'worker_id' => $workerId,
                        'trace_status' => $synced->status,
                        'execution_policy' => 'dual_review',
                    ],
                ]);
                $this->recomputeTraceMetrics($synced);
                $this->emitImportantJobResult($job->refresh(), 'failed');
            }

            return $job->refresh()->load(['trace', 'attemptHistory']);
        }

        $job->trace?->update([
            'status' => $finalFailure ? 'failed' : 'queued',
            'provider' => $attempt->provider,
            'model' => $attempt->model,
            'latency_ms' => $result->durationMs,
            'completed_at' => $finalFailure ? now() : null,
            'metadata' => array_merge($job->trace->metadata ?? [], [
                'last_error_code' => $result->errorCode,
                'last_error_message' => $result->errorMessage,
            ]),
        ]);

        if ($finalFailure && $job->trace) {
            $this->completeRemediationActions($job->trace->refresh());
        }

        $eventType = match ($result->errorCode) {
            'timeout' => 'timeout',
            'rate_limited' => 'rate_limited',
            'auth_expired' => 'auth_expired',
            default => 'job_failed',
        };

        $this->logger->event(
            eventType: $finalFailure ? 'job_failed' : 'job_requeued',
            message: $finalFailure ? 'AI job failed permanently.' : 'AI job failed and was requeued.',
            severity: $finalFailure ? 'error' : 'warning',
            provider: $attempt->provider,
            job: $job,
            attempt: $attempt,
            metadata: ['error_event_type' => $eventType, 'error_code' => $result->errorCode],
            workerId: $workerId,
        );
        $this->recordTelemetry($finalFailure ? 'job_failed' : 'job_requeued', $job, $attempt, [
            'event_phase' => 'worker',
            'duration_ms' => $result->durationMs,
            'metadata' => [
                'worker_id' => $workerId,
                'error_event_type' => $eventType,
                'error_code' => $result->errorCode,
            ],
        ]);

        $this->audit->record($finalFailure ? 'ai_job_failed' : 'ai_job_requeued', [
            'subject_type' => 'ai_job',
            'subject_id' => $job->id,
            'severity' => $finalFailure ? 'error' : 'warning',
            'summary' => $finalFailure ? 'Job de IA falhou permanentemente.' : 'Job de IA falhou e foi reenfileirado.',
            'evidence' => [
                'agent_slug' => $job->agent_slug,
                'provider' => $attempt->provider,
                'model' => $attempt->model,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
                'stderr_excerpt' => $result->stderr,
            ],
            'privacy' => $this->privacyFromJob($job),
            'refs' => [
                'trace_id' => $job->trace_id,
                'job_id' => $job->id,
                'attempt_id' => $attempt->id,
            ],
        ]);

        if ($finalFailure) {
            $failedTrace = $job->trace?->refresh();
            if ($failedTrace) {
                $this->recordTelemetry('trace_completed', $job, $attempt, [
                    'event_key' => 'worker:trace_completed:'.$failedTrace->id.':'.$failedTrace->status,
                    'event_phase' => 'worker',
                    'duration_ms' => $failedTrace->latency_ms,
                    'metadata' => [
                        'worker_id' => $workerId,
                        'trace_status' => $failedTrace->status,
                    ],
                ]);
                $this->recomputeTraceMetrics($failedTrace);
            }
            $this->emitImportantJobResult($job->refresh(), 'failed');
        }

        return $job->refresh()->load(['trace', 'attemptHistory']);
    }

    private function applyPermissionRuntime(AiJob $job, AiPermissionDecision $permission): AiJob
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $toolPermissions = is_array(data_get($payload, 'tool_permissions'))
            ? data_get($payload, 'tool_permissions')
            : [];
        $payload['tool_permissions'] = array_merge($toolPermissions, $permission->runtimePayload());

        $job->forceFill(['payload' => $payload])->save();

        return $job->refresh()->load('trace');
    }

    private function applyPendingSteer(AiJob $job): AiJob
    {
        $trace = $job->trace ?: $job->trace()->first();
        $thread = $trace?->thread()->first();
        $session = $trace?->session()->first();

        if (! $trace || ! $thread) {
            return $job;
        }

        $steer = $this->states->consumePendingSteer($thread, $session);
        if (! is_string($steer) || trim($steer) === '') {
            return $job;
        }

        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $steerPayload = [
            'content' => Str::limit(trim($steer), 4000, '...'),
            'injected_at' => now()->toJSON(),
            'source' => 'ai_session_state.pending_steer',
        ];

        $job->update([
            'prompt' => $this->promptWithPendingSteer($job->prompt, $steerPayload['content']),
            'payload' => array_merge($payload, [
                'pending_steer' => $steerPayload,
            ]),
            'metadata' => array_merge($metadata, [
                'pending_steer_injected' => true,
                'pending_steer_injected_at' => $steerPayload['injected_at'],
            ]),
        ]);

        $trace->update([
            'metadata' => array_merge($trace->metadata ?? [], [
                'pending_steer' => [
                    'injected' => true,
                    'injected_at' => $steerPayload['injected_at'],
                ],
            ]),
        ]);

        return $job->refresh()->load('trace');
    }

    private function promptWithPendingSteer(string $prompt, string $steer): string
    {
        return rtrim($prompt)."\n\n# Pedido adicional do operador\n\n[STEER] {$steer}\n";
    }

    private function withPermissionMetadata(AiProviderResult $result, AiPermissionDecision $permission): AiProviderResult
    {
        return new AiProviderResult(
            ok: $result->ok,
            output: $result->output,
            command: $result->command,
            exitCode: $result->exitCode,
            durationMs: $result->durationMs,
            stdout: $result->stdout,
            stderr: $result->stderr,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            metadata: array_merge($result->metadata, ['permission' => $permission->toArray()]),
        );
    }

    private function emitStreamEvent(
        AiJob $job,
        ?AiJobAttempt $attempt,
        string $eventType,
        string $name,
        string $content = '',
        array $metadata = [],
        ?string $channel = null,
        ?callable $onStream = null,
    ): void {
        $metadata = array_merge(['name' => $name], $metadata);
        $recorded = $this->stream->record($job, $attempt, $eventType, $content, $metadata, $channel);
        $onStream?->__invoke([
            'type' => $eventType,
            'name' => $name,
            'content' => $content,
            'channel' => $channel,
            'metadata' => $metadata,
            'sequence' => $recorded?->sequence,
            'job_id' => $job->id,
            'trace_id' => $job->trace_id,
            'attempt_id' => $attempt?->id,
            'occurred_at' => now()->toJSON(),
        ]);
    }

    private function emitImportantJobResult(AiJob $job, string $status): void
    {
        try {
            $this->jobResults->emitIfImportant($job, $status);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function recordTelemetry(string $eventName, AiJob $job, ?AiJobAttempt $attempt = null, array $overrides = []): void
    {
        if (! Schema::hasTable('ai_telemetry_events')) {
            return;
        }

        try {
            $trace = $job->trace ?: $job->trace()->first();
            $metadata = is_array($overrides['metadata'] ?? null) ? $overrides['metadata'] : [];
            $eventOverrides = $overrides;
            unset($eventOverrides['metadata']);
            app(AiTelemetryCollector::class)->record(array_merge([
                'event_key' => 'worker:'.$eventName.':'.$job->id.':'.($attempt?->id ?? 'job'),
                'trace_id' => $job->trace_id,
                'thread_id' => $trace?->thread_id,
                'session_id' => $trace?->session_id,
                'ai_job_id' => $job->id,
                'ai_job_attempt_id' => $attempt?->id,
                'client_id' => $job->client_id,
                'surface' => 'worker',
                'runtime' => 'worker',
                'provider' => $attempt?->provider ?? $job->provider,
                'model' => $attempt?->model ?? $job->model,
                'agent_slug' => $job->agent_slug,
                'event_name' => $eventName,
                'event_phase' => 'worker',
                'metadata' => array_merge($metadata, [
                    'job_status' => $job->status,
                    'attempt_status' => $attempt?->status,
                    'attempt_number' => $attempt?->attempt_number,
                ]),
            ], $eventOverrides));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function recomputeTraceMetrics(?AiTrace $trace): void
    {
        if (! $trace || ! Schema::hasTable('ai_trace_metric_summaries')) {
            return;
        }

        try {
            app(AiTraceMetricAggregator::class)->recomputeTrace($trace->id);
        } catch (\Throwable $exception) {
            report($exception);
        }
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

    private function isCouncilJob(AiJob $job): bool
    {
        return $job->kind === 'council'
            || data_get($job->payload, 'execution_policy') === 'dual_review'
            || data_get($job->metadata, 'execution_policy') === 'dual_review';
    }

    private function privacyFromJob(AiJob $job): array
    {
        $privacy = data_get($job->payload, 'privacy', data_get($job->metadata, 'privacy'));

        return is_array($privacy) ? $privacy : [];
    }

    private function updateSessionStateForTrace(AiTrace $trace, string $response): void
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

    private function evaluateQuality(AiTrace $trace): void
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

    private function completeRemediationActions(AiTrace $trace): void
    {
        if (! Schema::hasTable('ai_quality_actions')) {
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

    private function shouldPauseForChoice(AiJob $job, AiProviderResult $result): bool
    {
        if (! in_array($result->errorCode, ['rate_limited', 'auth_expired'], true)) {
            return false;
        }

        if ($this->isCouncilJob($job)) {
            return false;
        }

        return data_get($job->metadata, 'provider_choice_state') !== 'resolved';
    }

    private function pauseForChoice(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        $resetAtIso = data_get($result->metadata, 'provider_reset_at');
        $resetAt = is_string($resetAtIso) ? CarbonImmutable::parse($resetAtIso) : null;

        $options = $this->choices->build(
            errorCode: (string) $result->errorCode,
            currentProvider: (string) ($attempt->provider ?: $job->provider),
            currentModel: $job->model,
            resetAt: $resetAt,
        );

        $metadata = array_merge($job->metadata ?? [], [
            'provider_choice_state' => 'pending',
            'provider_choice_error_code' => $result->errorCode,
            'provider_choice_offered_at' => now()->toIso8601String(),
            'provider_reset_at' => $resetAtIso,
            'reset_hint' => data_get($result->metadata, 'reset_hint'),
            'choice_options' => $options,
        ]);

        $attempt->update([
            'status' => 'failed',
            'exit_code' => $result->exitCode,
            'duration_ms' => $result->durationMs,
            'stdout_excerpt' => Str::limit($result->stdout, 4000, '...'),
            'stderr_excerpt' => Str::limit($result->stderr, 4000, '...'),
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'finished_at' => now(),
            'metadata' => $result->metadata,
        ]);

        $job->update([
            'status' => 'awaiting_user_choice',
            'available_at' => now()->addYear(),
            'reserved_at' => null,
            'started_at' => null,
            'worker_id' => null,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'metadata' => $metadata,
        ]);

        $this->emitStreamEvent(
            $job,
            $attempt,
            'provider_choice',
            'provider_choice_required',
            $result->errorMessage ?: $result->errorCode,
            [
                'error_code' => $result->errorCode,
                'options' => $options,
                'provider_reset_at' => $resetAtIso,
                'reset_hint' => data_get($result->metadata, 'reset_hint'),
            ],
            'system',
            null,
        );

        $this->logger->event(
            eventType: 'provider_choice_required',
            message: 'AI job paused awaiting operator choice on provider failure.',
            severity: 'warning',
            provider: $attempt->provider,
            job: $job,
            attempt: $attempt,
            metadata: [
                'error_code' => $result->errorCode,
                'option_ids' => array_column($options, 'id'),
            ],
            workerId: $workerId,
        );

        return $job->refresh()->load(['trace', 'attemptHistory']);
    }
}
