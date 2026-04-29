<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiTrace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiWorker
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly AiWorkerLogger $logger,
        private readonly AiCouncilCoordinator $council,
    ) {}

    public function runNext(?string $providerOverride = null, ?string $workerId = null): ?AiJob
    {
        $workerId = $workerId ?: (string) config('atlas.ai.worker_id', 'atlas-worker');
        $this->recoverStaleProcessingJobs($workerId);

        $job = $this->claimJob($workerId, $providerOverride);

        if (! $job) {
            return null;
        }

        if ($this->isCouncilJob($job)) {
            $this->council->sync($job->trace()->firstOrFail());
        }

        $providerKey = $providerOverride ?: $job->provider ?: (string) config('atlas.ai.default_provider', 'claude_cli');
        $provider = $this->providers->get($providerKey);
        $attempt = $this->createAttempt($job, $workerId, $providerKey);

        try {
            $result = $provider->run($job, $job->prompt);
        } catch (\Throwable $exception) {
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

        return $this->completeAttempt($job, $attempt, $result, $workerId);
    }

    private function claimJob(string $workerId, ?string $providerOverride): ?AiJob
    {
        return DB::transaction(function () use ($workerId, $providerOverride): ?AiJob {
            $query = AiJob::query()
                ->where('status', 'queued')
                ->where('available_at', '<=', now())
                ->orderBy('priority')
                ->orderBy('created_at')
                ->lockForUpdate();

            if ($providerOverride) {
                $query->where('provider', $providerOverride);
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

    private function completeAttempt(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        $responseHash = $result->output !== '' ? hash('sha256', $result->output) : null;
        $attemptStatus = $result->ok ? 'succeeded' : ($result->errorCode === 'timeout' ? 'timeout' : 'failed');

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

        if ($result->ok) {
            $job->update([
                'status' => 'succeeded',
                'result_text' => $result->output,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => now(),
            ]);

            if ($this->isCouncilJob($job)) {
                $this->council->sync($job->trace()->firstOrFail());
                $this->logger->event('job_succeeded', 'AI council job completed successfully.', 'info', $attempt->provider, $job, $attempt, workerId: $workerId);

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

            $this->logger->event('job_succeeded', 'AI job completed successfully.', 'info', $attempt->provider, $job, $attempt, workerId: $workerId);

            return $job->refresh()->load(['trace', 'attemptHistory']);
        }

        $finalFailure = $job->attempts >= $job->max_attempts;
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
            $this->council->sync($job->trace()->firstOrFail());
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

        return $job->refresh()->load(['trace', 'attemptHistory']);
    }

    private function isCouncilJob(AiJob $job): bool
    {
        return $job->kind === 'council'
            || data_get($job->payload, 'execution_policy') === 'dual_review'
            || data_get($job->metadata, 'execution_policy') === 'dual_review';
    }
}
