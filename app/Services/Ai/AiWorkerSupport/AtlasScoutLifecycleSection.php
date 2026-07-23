<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AiWorker;
use App\Services\Ai\Instrumentation\AiWorkerLogger;
use App\Services\AuditLogService;

/**
 * Atlas Decide context-scout lifecycle family (dependency release, scout
 * completion, immediate degrade, executor release) extracted VERBATIM from
 * AiWorker (GOD-DEBULK entangled-family split). Bodies are byte-identical
 * modulo parent-back-reference rewrites (cross-cutting helpers via
 * $this->parent; brief helpers via the injected AtlasScoutBriefSection). Facade
 * AiWorker keeps same-signature delegators for the hot-path entry points.
 */
class AtlasScoutLifecycleSection
{
    public function __construct(
        private readonly AiWorker $parent,
        private readonly AiWorkerLogger $logger,
        private readonly AuditLogService $audit,
        private readonly AtlasScoutBriefSection $scoutBrief,
    ) {}

    public function applyExpiredAtlasScoutDependency(AiJob $job): AiJob
    {
        if (! $this->parent->isAtlasPrimaryExecutorJob($job)) {
            return $job;
        }

        $dependencyId = (string) data_get($job->metadata, 'dependency_job_id');
        $dependency = AiJob::query()->find($dependencyId);
        $dependencySucceeded = $dependency?->status === 'succeeded' && is_string($dependency->result_text) && trim($dependency->result_text) !== '';
        $brief = $dependencySucceeded
            ? $this->atlasScoutBrief($dependency, $dependency->result_text)
            : $this->atlasScoutFailureBrief($dependency, 'dependency_timeout', 'Scout de contexto nao terminou antes do executor ficar disponivel.');

        return $this->applyAtlasScoutBriefToExecutor($job, $dependency, $brief, $dependencySucceeded ? 'satisfied' : 'degraded', [
            'dependency_expired' => ! $dependencySucceeded,
            'dependency_timeout_at' => now()->toJSON(),
        ]);
    }

    public function completeAtlasScoutJob(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        $job = $this->releaseExecutorAfterAtlasScout($job->refresh(), $attempt, $result, null, $workerId);

        $this->logger->event('job_succeeded', 'Atlas Decide context scout completed and released executor.', 'info', $attempt->provider, $job, $attempt, workerId: $workerId);
        $this->parent->recordTelemetry('job_succeeded', $job, $attempt, [
            'event_phase' => 'worker',
            'duration_ms' => $result->durationMs,
            'metadata' => [
                'worker_id' => $workerId,
                'atlas_decide_stage' => 'context_scout',
                'dependent_job_id' => data_get($job->metadata, 'dependent_job_id'),
            ],
        ]);
        $this->audit->record('ai_job_succeeded', [
            'subject_type' => 'ai_job',
            'subject_id' => $job->id,
            'summary' => 'Scout de contexto do Atlas Decide concluido.',
            'evidence' => [
                'agent_slug' => $job->agent_slug,
                'provider' => $attempt->provider,
                'model' => $attempt->model,
                'duration_ms' => $result->durationMs,
                'dependent_job_id' => data_get($job->metadata, 'dependent_job_id'),
                'response_hash' => $result->output !== '' ? hash('sha256', $result->output) : null,
            ],
            'privacy' => $this->parent->privacyFromJob($job),
            'refs' => [
                'trace_id' => $job->trace_id,
                'job_id' => $job->id,
                'attempt_id' => $attempt->id,
            ],
        ]);

        return $job->refresh()->load(['trace', 'attemptHistory']);
    }

    public function shouldDegradeAtlasScoutImmediately(AiJob $job, AiProviderResult $result): bool
    {
        return $this->parent->isAtlasScoutJob($job)
            && in_array($result->errorCode, ['rate_limited', 'auth_expired', 'policy_violation'], true);
    }

    public function failAtlasScoutJobAndReleaseExecutor(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        $job->update([
            'status' => 'failed',
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'available_at' => $job->available_at,
            'reserved_at' => $job->reserved_at,
            'started_at' => $job->started_at,
            'worker_id' => $job->worker_id,
            'finished_at' => now(),
        ]);

        return $this->releaseExecutorAfterAtlasScout($job->refresh(), $attempt, null, $result, $workerId);
    }

    public function releaseExecutorAfterAtlasScout(
        AiJob $scoutJob,
        AiJobAttempt $attempt,
        ?AiProviderResult $success,
        ?AiProviderResult $failure,
        string $workerId,
    ): AiJob {
        $dependentId = data_get($scoutJob->metadata, 'dependent_job_id')
            ?: data_get($scoutJob->payload, 'atlas_decide_execution.dependent_job_id');
        if (! is_string($dependentId) || $dependentId === '') {
            return $scoutJob->refresh()->load(['trace', 'attemptHistory']);
        }

        /** @var AiJob|null $executor */
        $executor = AiJob::query()->whereKey($dependentId)->first();
        if (! $executor || $executor->status !== 'queued') {
            return $scoutJob->refresh()->load(['trace', 'attemptHistory']);
        }

        $brief = $success
            ? $this->atlasScoutBrief($scoutJob, $success->output)
            : $this->atlasScoutFailureBrief($scoutJob, $failure?->errorCode, $failure?->errorMessage);
        $dependencyState = $success ? 'satisfied' : 'degraded';
        $executor = $this->applyAtlasScoutBriefToExecutor($executor, $scoutJob, $brief, $dependencyState, [
            'dependency_released_by_worker_id' => $workerId,
            'dependency_released_at' => now()->toJSON(),
            'dependency_error_code' => $failure?->errorCode,
            'dependency_error_message' => $failure?->errorMessage,
        ]);

        $scoutJob->trace?->forceFill([
            'status' => 'queued',
            'metadata' => array_merge($scoutJob->trace->metadata ?? [], [
                'atlas_decide_execution' => array_merge(
                    is_array(data_get($scoutJob->trace->metadata, 'atlas_decide_execution'))
                        ? data_get($scoutJob->trace->metadata, 'atlas_decide_execution')
                        : [],
                    [
                        'dependency_state' => $dependencyState,
                        'context_scout_job_id' => $scoutJob->id,
                        'executor_job_id' => $executor->id,
                    ],
                ),
            ]),
        ])->save();

        $this->parent->emitStreamEvent($scoutJob, $attempt, 'lifecycle', 'atlas_scout_released_executor', '', [
            'executor_job_id' => $executor->id,
            'dependency_state' => $dependencyState,
        ], 'system');
        $this->parent->recordTelemetry('atlas_scout_released_executor', $scoutJob, $attempt, [
            'event_phase' => 'worker',
            'metadata' => [
                'worker_id' => $workerId,
                'executor_job_id' => $executor->id,
                'dependency_state' => $dependencyState,
            ],
        ]);

        return $scoutJob->refresh()->load(['trace', 'attemptHistory']);
    }

    public function applyAtlasScoutBriefToExecutor(AiJob $executor, ?AiJob $scoutJob, string $brief, string $dependencyState, array $extraMetadata = []): AiJob
    {
        return $this->scoutBrief->applyAtlasScoutBriefToExecutor($executor, $scoutJob, $brief, $dependencyState, $extraMetadata);
    }

    public function atlasScoutBrief(AiJob $scoutJob, string $output): string
    {
        return $this->scoutBrief->atlasScoutBrief($scoutJob, $output);
    }

    public function atlasScoutFailureBrief(?AiJob $scoutJob, ?string $errorCode, ?string $errorMessage): string
    {
        return $this->scoutBrief->atlasScoutFailureBrief($scoutJob, $errorCode, $errorMessage);
    }

}
