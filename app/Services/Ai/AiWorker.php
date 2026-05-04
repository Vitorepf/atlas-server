<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiQualityAction;
use App\Models\AiTrace;
use App\Services\Ai\Cli\AtlasCliQualityService;
use App\Services\Ai\Mobile\JobResultInboxEmitter;
use App\Services\Ai\Programming\AtlasProgrammingOrchestrator;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use App\Services\AuditLogService;
use App\Services\MacAgent\MacAgentService;
use App\Services\Semantic\CaptureSemanticClarifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiWorker
{
    private const MAC_BACKGROUND_RETRY_DELAY_SECONDS = 300;

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
        private readonly AiRuntimeBudgetService $budgets,
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
        private readonly FairClaudePolicy $fairClaude,
        private readonly AtlasProgrammingOrchestrator $programming,
        private readonly AtlasCliQualityService $cliQuality,
        private readonly MacAgentService $macAgent,
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

        $providerKey = $providerOverride ?: $job->provider ?: $this->runtimeSettings->defaultProvider();
        if ($violation = $this->fairModeRuntimeViolation($job, $providerKey, $job->model, requireModel: false)) {
            $attempt = $this->createAttempt($job, $workerId, $providerKey);

            return $this->completeAttempt($job, $attempt, $this->fairModeViolationResult($violation), $workerId);
        }
        $job = $this->ensureJobModelIdentity($job, $providerKey);
        if ($violation = $this->fairModeRuntimeViolation($job, $providerKey, $job->model)) {
            $attempt = $this->createAttempt($job, $workerId, $providerKey);

            return $this->completeAttempt($job, $attempt, $this->fairModeViolationResult($violation), $workerId);
        }
        $job = $this->applyExpiredAtlasScoutDependency($job);
        $job = $this->applyProgrammingProviderPolicyRuntime($job);
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
        } elseif ($policyViolation = $this->programmingProviderPolicyViolation($job)) {
            $this->emitStreamEvent($job, $attempt, 'policy', 'policy_contract_blocked', (string) ($policyViolation['message'] ?? 'Policy contract blocked provider execution.'), [
                'policy_contract_enforcement' => $policyViolation,
            ], null, $onStream);

            $result = new AiProviderResult(
                ok: false,
                output: '',
                command: [],
                exitCode: null,
                durationMs: 0,
                stdout: '',
                stderr: '',
                errorCode: 'policy_violation',
                errorMessage: (string) ($policyViolation['message'] ?? 'Policy contract blocked provider execution.'),
                metadata: [
                    'policy_contract_enforcement' => $policyViolation,
                ],
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
                $powerSession = $this->macAgent->startSession(
                    kind: 'ai_job',
                    reason: "Atlas AI job {$job->id}",
                    expiresAt: now()->addSeconds(max(60, (int) $job->timeout_seconds) + 300),
                    source: 'ai_worker',
                    job: $job,
                    metadata: [
                        'worker_id' => $workerId,
                        'provider' => $providerKey,
                        'attempt_id' => $attempt->id,
                    ],
                );

                try {
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
                    $result = $this->withPowerSessionMetadata($result, (string) $powerSession->id);
                } finally {
                    $this->macAgent->stopSession($powerSession, 'ai_job_finished');
                }
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
                ->limit(25)
                ->lockForUpdate();

            if ($providerOverride) {
                $query->where('provider', $providerOverride);
            }

            if ($traceId) {
                $query->where('trace_id', $traceId);
            }

            /** @var Collection<int, AiJob> $jobs */
            $jobs = $query->get();

            foreach ($jobs as $job) {
                if ($this->deferForMacBackgroundReadinessIfNeeded($job, $workerId)) {
                    continue;
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
            }

            return null;
        });
    }

    private function deferForMacBackgroundReadinessIfNeeded(AiJob $job, string $workerId): bool
    {
        if (! $defer = $this->macBackgroundReadinessDefer($job)) {
            return false;
        }

        $metadata = array_merge($job->metadata ?? [], [
            'mac_background_readiness' => $defer,
        ]);

        $job->update([
            'available_at' => now()->addSeconds(self::MAC_BACKGROUND_RETRY_DELAY_SECONDS),
            'metadata' => $metadata,
        ]);
        $job->trace?->update([
            'status' => 'queued',
            'metadata' => array_merge($job->trace->metadata ?? [], [
                'mac_background_readiness' => $defer,
            ]),
        ]);

        $this->logger->event(
            eventType: 'job_deferred',
            message: 'AI background job deferred until Mac Agent readiness is satisfied.',
            severity: 'warning',
            provider: $job->provider,
            job: $job,
            metadata: $defer,
            workerId: $workerId,
        );

        return true;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function macBackgroundReadinessDefer(AiJob $job): ?array
    {
        if (! $this->requiresMacBackgroundReadiness($job)) {
            return null;
        }

        $status = $this->macAgent->status(refresh: true);
        $readiness = (array) ($status['readiness'] ?? []);

        if (($readiness['ready_for_background_jobs'] ?? false) === true) {
            return null;
        }

        return [
            'schema_version' => 1,
            'status' => 'deferred',
            'reason' => 'mac_background_not_ready',
            'retry_after_seconds' => self::MAC_BACKGROUND_RETRY_DELAY_SECONDS,
            'checked_at' => now()->toJSON(),
            'readiness' => [
                'overall' => $readiness['overall'] ?? 'unknown',
                'ready_for_remote' => (bool) ($readiness['ready_for_remote'] ?? false),
                'ready_for_scheduled_wake' => (bool) ($readiness['ready_for_scheduled_wake'] ?? false),
                'ready_for_background_jobs' => (bool) ($readiness['ready_for_background_jobs'] ?? false),
                'power_ready_for_background_jobs' => (bool) ($readiness['power_ready_for_background_jobs'] ?? false),
                'blockers' => $readiness['blockers'] ?? [],
                'warnings' => $readiness['warnings'] ?? [],
            ],
        ];
    }

    private function requiresMacBackgroundReadiness(AiJob $job): bool
    {
        $traceSource = (string) ($job->trace?->source_type ?? '');
        $payload = $job->payload ?? [];

        if (in_array($traceSource, ['scheduled', 'system'], true)) {
            return true;
        }

        return in_array((string) data_get($payload, 'atlas_workflow_mode'), ['scheduled', 'background'], true)
            || in_array((string) data_get($payload, 'app_surface'), ['atlas_cli_schedule', 'scheduled', 'background'], true)
            || (bool) data_get($payload, 'scheduled_task.id');
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
        $lastAttemptNumber = (int) AiJobAttempt::query()
            ->where('ai_job_id', $job->id)
            ->max('attempt_number');
        $attemptNumber = max((int) $job->attempts, $lastAttemptNumber + 1);

        if ($attemptNumber !== (int) $job->attempts) {
            $job->forceFill(['attempts' => $attemptNumber])->save();
        }

        return AiJobAttempt::query()->create([
            'ai_job_id' => $job->id,
            'attempt_number' => $attemptNumber,
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

    /**
     * @return array<string,mixed>
     */
    private function programmingDispatchUpdate(
        AiJob $job,
        string $status,
        ?string $traceProvider = null,
        ?string $responseHash = null,
        ?string $errorCode = null,
    ): array {
        $dispatch = data_get($job->metadata, 'programming_dispatch');
        if (! is_array($dispatch)) {
            $dispatch = data_get($job->payload, 'programming_dispatch');
        }
        if (! is_array($dispatch)) {
            $dispatch = data_get($job->trace?->metadata, 'programming_dispatch');
        }
        if (! is_array($dispatch)) {
            return [];
        }

        $now = now()->toJSON();
        $terminal = in_array($status, ['executed', 'blocked'], true);
        $updatedDispatch = array_filter(array_merge($dispatch, [
            'status' => $status,
            'trace_provider' => $traceProvider ?: $job->provider,
            'completed_at' => $terminal ? $now : null,
            'updated_at' => $now,
        ]), fn (mixed $value): bool => $value !== null);

        return [
            'programming_dispatch' => $updatedDispatch,
            'programming_completion' => array_filter([
                'schema_version' => 1,
                'status' => match ($status) {
                    'executed' => 'passed',
                    'blocked' => 'blocked',
                    'retrying' => 'retrying',
                    default => $status,
                },
                'executor' => data_get($dispatch, 'executor'),
                'dispatch_path' => data_get($dispatch, 'dispatch_path'),
                'profile_context' => data_get($dispatch, 'profile_context'),
                'execution_policy' => data_get($dispatch, 'execution_policy'),
                'policy_contracts' => data_get($dispatch, 'policy_contracts'),
                'provider' => $traceProvider ?: $job->provider,
                'model' => $job->model,
                'response_hash' => $responseHash,
                'error_code' => $errorCode,
                'completed_at' => $terminal ? $now : null,
                'updated_at' => $now,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    private function shouldEvaluateNativeProgrammingRepair(AiJob $job): bool
    {
        if ($this->isCouncilJob($job) || $this->isAtlasScoutJob($job)) {
            return false;
        }

        return (bool) data_get($job->payload, 'programming_repair.enabled')
            && data_get($job->payload, 'programming_dispatch.dispatch_path') === 'ai_gateway_provider'
            && data_get($job->payload, 'programming_dispatch.executor') === 'dev_repair_executor';
    }

    private function isProgrammingProviderDispatch(AiJob $job): bool
    {
        return ! $this->isCouncilJob($job)
            && ! $this->isAtlasScoutJob($job)
            && data_get($job->payload, 'programming_dispatch.dispatch_path') === 'ai_gateway_provider';
    }

    private function applyProgrammingProviderPolicyRuntime(AiJob $job): AiJob
    {
        if (! $this->isProgrammingProviderDispatch($job)) {
            return $job;
        }

        $toolContract = $this->programmingProviderToolContract($job);
        if ($this->programmingRepairAllowsWorkspaceWrite($toolContract)) {
            return $job;
        }

        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $toolPermissions = is_array(data_get($payload, 'tool_permissions'))
            ? data_get($payload, 'tool_permissions')
            : [];
        $enforcement = array_filter([
            'schema_version' => 1,
            'source' => 'effective_policy_v2_policy_contracts',
            'scope' => 'provider_runtime',
            'tool_contract_enforced' => true,
            'tool_contract' => $toolContract,
            'effective_tool_permission_mode' => 'read',
            'reason' => 'tool_contract_forces_read_only_provider_runtime',
            'enforced_at' => now()->toJSON(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        $payload['tool_permissions'] = array_merge($toolPermissions, [
            'mode' => 'read',
            'policy_contract_forced_read_only' => true,
        ]);
        $payload['programming_policy_contract_enforcement'] = array_merge(
            (array) ($payload['programming_policy_contract_enforcement'] ?? []),
            ['provider_runtime' => $enforcement],
        );

        $job->forceFill([
            'payload' => $payload,
            'metadata' => array_merge($metadata, [
                'programming_policy_contract_enforcement' => array_merge(
                    (array) ($metadata['programming_policy_contract_enforcement'] ?? []),
                    ['provider_runtime' => $enforcement],
                ),
            ]),
        ])->save();

        $job->trace?->forceFill([
            'metadata' => array_merge($job->trace->metadata ?? [], [
                'programming_policy_contract_enforcement' => array_merge(
                    (array) data_get($job->trace->metadata, 'programming_policy_contract_enforcement', []),
                    ['provider_runtime' => $enforcement],
                ),
            ]),
        ])->save();

        return $job->refresh()->load('trace');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function programmingProviderPolicyViolation(AiJob $job): ?array
    {
        if (! $this->isProgrammingProviderDispatch($job)) {
            return null;
        }

        $gateContract = $this->programmingProviderGateContract($job);
        if (! $this->programmingRepairGateRequiresEvidence($gateContract)) {
            return null;
        }

        if ($this->shouldEvaluateNativeProgrammingRepair($job)) {
            return null;
        }

        return array_filter([
            'schema_version' => 1,
            'source' => 'effective_policy_v2_policy_contracts',
            'scope' => 'provider_execution',
            'gate_contract_enforced' => true,
            'gate_contract' => $gateContract,
            'blocked_reason' => 'gate_contract_requires_evidence_without_provider_evidence_path',
            'message' => 'Programming provider execution blocked: policy gate requires evidence, but this executor has no repair/test evidence path.',
            'enforced_at' => now()->toJSON(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array<string,mixed>
     */
    private function programmingProviderGateContract(AiJob $job): array
    {
        return $this->firstArray([
            data_get($job->payload, 'programming_policy_contracts.gates'),
            data_get($job->metadata, 'programming_policy_contracts.gates'),
            data_get($job->payload, 'programming_message_plan.policy_contracts.gates'),
            data_get($job->payload, 'programming_message_plan.policy_profile.policy_contracts.gates'),
            data_get($job->payload, 'programming_message_plan.policy_profile.effective_policy.operational_contracts.gates'),
            data_get($job->payload, 'programming_dispatch.policy_contracts.gates'),
            data_get($job->trace?->metadata, 'programming_policy_contracts.gates'),
            data_get($job->trace?->metadata, 'programming_dispatch.policy_contracts.gates'),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function programmingProviderToolContract(AiJob $job): array
    {
        return $this->firstArray([
            data_get($job->payload, 'programming_policy_contracts.tools'),
            data_get($job->metadata, 'programming_policy_contracts.tools'),
            data_get($job->payload, 'programming_message_plan.policy_contracts.tools'),
            data_get($job->payload, 'programming_message_plan.policy_profile.policy_contracts.tools'),
            data_get($job->payload, 'programming_message_plan.policy_profile.effective_policy.operational_contracts.tools'),
            data_get($job->payload, 'programming_dispatch.policy_contracts.tools'),
            data_get($job->trace?->metadata, 'programming_policy_contracts.tools'),
            data_get($job->trace?->metadata, 'programming_dispatch.policy_contracts.tools'),
        ]);
    }

    private function handleNativeProgrammingRepair(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, ?string $responseHash): ?AiJob
    {
        $workspace = $this->programmingRepairWorkspace($job);
        if ($workspace === null) {
            return null;
        }

        $repair = (array) data_get($job->payload, 'programming_repair', []);
        $messagePlan = (array) data_get($job->payload, 'programming_message_plan', []);
        $gateContract = $this->programmingRepairGateContract($job, $repair, $messagePlan);
        $toolContract = $this->programmingRepairToolContract($job, $repair, $messagePlan);
        $completeMode = (bool) ($repair['complete_mode'] ?? data_get($messagePlan, 'execution_profile.complete', false));
        $runTests = $completeMode
            || (bool) data_get($messagePlan, 'execution_profile.auto_test', false)
            || $this->programmingRepairGateRequiresEvidence($gateContract);
        $quality = $this->cliQuality->evaluate(
            workspace: $workspace,
            runTests: $runTests,
            testCommand: $this->programmingRepairTestCommand($job),
            approved: true,
            traceId: $job->trace_id,
        );
        $qualityStatus = (string) ($quality['status'] ?? 'unknown');
        $currentIteration = max(1, (int) ($repair['current_iteration'] ?? 1));
        $maxIterations = max(1, min(10, (int) ($repair['max_iterations'] ?? data_get($messagePlan, 'execution_profile.max_iterations', 1))));
        $qualityWorsened = (bool) ($repair['stop_when_quality_worsens'] ?? true)
            && $this->programmingRepairQualityWorsened($qualityStatus, $repair['previous_quality_status'] ?? null);
        $shouldRepair = in_array($qualityStatus, (array) ($repair['repair_when_status'] ?? ['failed', 'needs_review']), true)
            && ! $qualityWorsened
            && $currentIteration < $maxIterations;
        $finalPassed = in_array($qualityStatus, (array) ($repair['stop_when_status'] ?? ['passed']), true);
        $toolBlocksRepair = $shouldRepair && ! $this->programmingRepairAllowsWorkspaceWrite($toolContract);

        if ($shouldRepair && ! $toolBlocksRepair) {
            $this->enqueueNativeProgrammingRepairJob($job, $quality, $currentIteration + 1, $maxIterations);
            $metadata = $this->nativeProgrammingRepairMetadata($job, $repair, $quality, [
                'status' => 'repairing',
                'current_iteration' => $currentIteration,
                'next_iteration' => $currentIteration + 1,
            ], $attempt->provider, $responseHash);

            $job->trace?->update([
                'status' => 'queued',
                'provider' => $attempt->provider,
                'model' => $attempt->model,
                'response_hash' => $responseHash,
                'response_text' => $result->output,
                'latency_ms' => $result->durationMs,
                'completed_at' => null,
                'metadata' => array_merge($job->trace->metadata ?? [], $metadata),
            ]);

            return $job->refresh()->load(['trace', 'attemptHistory']);
        }

        if (! $finalPassed) {
            $metadata = $this->nativeProgrammingRepairMetadata($job, $repair, $quality, [
                'status' => $qualityWorsened || $toolBlocksRepair ? 'stopped' : 'exhausted',
                'current_iteration' => $currentIteration,
                'reason_if_stopped' => match (true) {
                    $toolBlocksRepair => 'tool_contract_blocks_workspace_write',
                    $qualityWorsened => 'quality_gate_worsened',
                    default => 'max_iterations_or_quality_failed',
                },
            ], $attempt->provider, $responseHash, blocked: true);

            $job->trace?->update([
                'status' => 'failed',
                'provider' => $attempt->provider,
                'model' => $attempt->model,
                'response_hash' => $responseHash,
                'response_text' => $result->output,
                'latency_ms' => $result->durationMs,
                'completed_at' => now(),
                'metadata' => array_merge($job->trace->metadata ?? [], $metadata),
            ]);

            return $job->refresh()->load(['trace', 'attemptHistory']);
        }

        $metadata = $this->nativeProgrammingRepairMetadata($job, $repair, $quality, [
            'status' => 'passed',
            'current_iteration' => $currentIteration,
        ], $attempt->provider, $responseHash);

        $job->forceFill([
            'metadata' => array_merge($job->metadata ?? [], $metadata),
        ])->save();
        $job->trace?->forceFill([
            'metadata' => array_merge($job->trace->metadata ?? [], $metadata),
        ])->save();

        return null;
    }

    /**
     * @param  array<string,mixed>  $repair
     * @param  array<string,mixed>  $messagePlan
     * @return array<string,mixed>
     */
    private function programmingRepairGateContract(AiJob $job, array $repair, array $messagePlan): array
    {
        return $this->firstArray([
            data_get($repair, 'gate_contract'),
            data_get($messagePlan, 'execution_profile.gate_contract'),
            data_get($messagePlan, 'policy_contracts.gates'),
            data_get($messagePlan, 'policy_profile.policy_contracts.gates'),
            data_get($messagePlan, 'policy_profile.effective_policy.operational_contracts.gates'),
            data_get($job->payload, 'programming_dispatch.policy_contracts.gates'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $repair
     * @param  array<string,mixed>  $messagePlan
     * @return array<string,mixed>
     */
    private function programmingRepairToolContract(AiJob $job, array $repair, array $messagePlan): array
    {
        return $this->firstArray([
            data_get($repair, 'tool_contract'),
            data_get($messagePlan, 'execution_profile.tool_contract'),
            data_get($messagePlan, 'policy_contracts.tools'),
            data_get($messagePlan, 'policy_profile.policy_contracts.tools'),
            data_get($messagePlan, 'policy_profile.effective_policy.operational_contracts.tools'),
            data_get($job->payload, 'programming_dispatch.policy_contracts.tools'),
        ]);
    }

    /**
     * @param  array<string,mixed>  $gateContract
     */
    private function programmingRepairGateRequiresEvidence(array $gateContract): bool
    {
        $minimum = strtolower(trim((string) ($gateContract['minimum_gate'] ?? '')));

        return (bool) ($gateContract['evidence_required'] ?? false)
            || in_array($minimum, ['strict', 'release'], true);
    }

    /**
     * @param  array<string,mixed>  $toolContract
     */
    private function programmingRepairAllowsWorkspaceWrite(array $toolContract): bool
    {
        if ($toolContract === []) {
            return true;
        }

        $mode = strtolower(trim((string) ($toolContract['mode'] ?? '')));
        if ($mode === 'read_only') {
            return false;
        }

        return (bool) ($toolContract['workspace_write'] ?? in_array($mode, ['workspace_write', 'harness'], true));
    }

    /**
     * @param  array<int,mixed>  $candidates
     * @return array<string,mixed>
     */
    private function firstArray(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    private function programmingRepairWorkspace(AiJob $job): ?string
    {
        $workspace = data_get($job->payload, 'workspace_context.repo_root')
            ?: data_get($job->payload, 'workspace_context.workspace')
            ?: data_get($job->payload, 'programming_message_plan.workspace');

        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        return realpath($workspace) ?: $workspace;
    }

    private function programmingRepairTestCommand(AiJob $job): ?string
    {
        $command = data_get($job->payload, 'dev_execution_plan.operator_options.harness_overrides.test_command');

        return is_string($command) && trim($command) !== '' ? trim($command) : null;
    }

    private function programmingRepairQualityWorsened(string $currentStatus, mixed $previousStatus): bool
    {
        if (! is_string($previousStatus) || trim($previousStatus) === '') {
            return false;
        }

        return $this->programmingRepairStatusRank($currentStatus) < $this->programmingRepairStatusRank($previousStatus);
    }

    private function programmingRepairStatusRank(string $status): int
    {
        return match ($status) {
            'passed' => 4,
            'needs_review' => 3,
            'failed' => 2,
            'blocked' => 1,
            default => 0,
        };
    }

    /**
     * @param  array<string,mixed>  $quality
     */
    private function enqueueNativeProgrammingRepairJob(AiJob $job, array $quality, int $iteration, int $maxIterations): AiJob
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $repair = (array) data_get($payload, 'programming_repair', []);
        $payload['programming_repair'] = array_merge($repair, [
            'status' => 'active',
            'current_iteration' => $iteration,
            'previous_quality_status' => $quality['status'] ?? null,
            'previous_quality_hash' => hash('sha256', json_encode($quality, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'),
            'updated_at' => now()->toJSON(),
        ]);
        $payload['programming_repair_history'] = array_values(array_merge(
            (array) ($payload['programming_repair_history'] ?? []),
            [[
                'iteration' => $iteration - 1,
                'status' => $quality['status'] ?? null,
                'diff_hash' => $quality['diff_hash'] ?? null,
                'recorded_at' => now()->toJSON(),
            ]],
        ));

        return AiJob::query()->create([
            'trace_id' => $job->trace_id,
            'client_id' => $job->client_id,
            'kind' => $job->kind,
            'status' => 'queued',
            'priority' => max(1, (int) $job->priority - 1),
            'agent_slug' => $job->agent_slug,
            'provider' => $job->provider,
            'model' => $job->model,
            'input_text' => $job->input_text,
            'prompt' => $this->programming->repairPrompt(
                (string) ($job->trace?->operator_input ?: $job->input_text),
                $quality,
                $iteration,
                $maxIterations,
            ),
            'context_refs' => $job->context_refs ?? [],
            'payload' => $payload,
            'available_at' => now(),
            'attempts' => 0,
            'max_attempts' => 1,
            'timeout_seconds' => $job->timeout_seconds,
            'metadata' => array_merge($job->metadata ?? [], [
                'programming_repair_job' => true,
                'programming_repair_iteration' => $iteration,
                'programming_repair_parent_job_id' => $job->id,
            ]),
        ]);
    }

    /**
     * @param  array<string,mixed>  $repair
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $repairUpdates
     * @return array<string,mixed>
     */
    private function nativeProgrammingRepairMetadata(
        AiJob $job,
        array $repair,
        array $quality,
        array $repairUpdates,
        ?string $provider,
        ?string $responseHash,
        bool $blocked = false,
    ): array {
        $dispatchStatus = $blocked ? 'blocked' : 'executed';
        $metadata = $this->programmingDispatchUpdate($job, $dispatchStatus, $provider, $responseHash, $blocked ? 'quality_gate_failed' : null);
        $completion = (array) ($metadata['programming_completion'] ?? []);
        $completion['status'] = $blocked ? 'blocked' : (string) ($quality['status'] ?? $completion['status'] ?? 'unknown');
        $completion['quality_status'] = $quality['status'] ?? null;
        $completion['quality_gate'] = [
            'status' => $quality['status'] ?? null,
            'diff_hash' => $quality['diff_hash'] ?? null,
            'dirty_count' => $quality['dirty_count'] ?? null,
            'tests' => data_get($quality, 'completion_packet.tests', []),
            'risks' => data_get($quality, 'completion_packet.risks', []),
        ];
        $completion['repair'] = array_filter([
            'status' => $repairUpdates['status'] ?? $repair['status'] ?? null,
            'current_iteration' => $repairUpdates['current_iteration'] ?? $repair['current_iteration'] ?? 1,
            'next_iteration' => $repairUpdates['next_iteration'] ?? null,
            'max_iterations' => $repair['max_iterations'] ?? null,
            'reason_if_stopped' => $repairUpdates['reason_if_stopped'] ?? null,
            'previous_quality_status' => $repair['previous_quality_status'] ?? null,
            'last_quality_status' => $quality['status'] ?? null,
            'history' => $this->programmingRepairHistory($job, $repairUpdates, $quality),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
        $metadata['programming_completion'] = array_filter($completion, fn (mixed $value): bool => $value !== null && $value !== []);
        $metadata['programming_repair'] = array_merge($repair, $repairUpdates, [
            'last_quality_status' => $quality['status'] ?? null,
            'last_quality_diff_hash' => $quality['diff_hash'] ?? null,
            'updated_at' => now()->toJSON(),
        ]);

        return $metadata;
    }

    /**
     * @param  array<string,mixed>  $repairUpdates
     * @param  array<string,mixed>  $quality
     * @return array<int,array<string,mixed>>
     */
    private function programmingRepairHistory(AiJob $job, array $repairUpdates, array $quality): array
    {
        $history = (array) data_get($job->payload, 'programming_repair_history', []);
        $currentIteration = (int) ($repairUpdates['current_iteration'] ?? data_get($job->payload, 'programming_repair.current_iteration', 1));
        $history[] = [
            'iteration' => max(1, $currentIteration),
            'status' => $quality['status'] ?? null,
            'diff_hash' => $quality['diff_hash'] ?? null,
            'recorded_at' => now()->toJSON(),
        ];

        return array_values(array_slice($history, -10));
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
                'model_label' => $resolution['model_label'] ?? $resolution['model'],
                'model_tier' => $resolution['model_tier'] ?? config('atlas.ai.default_tier', 'daily'),
                'model_allow_auto' => (bool) ($resolution['allow_auto'] ?? true),
                'model_allow_manual' => (bool) ($resolution['allow_manual'] ?? true),
            ]),
            'metadata' => array_merge($metadata, [
                'model_identity_source' => $resolution['source'],
                'model_label' => $resolution['model_label'] ?? $resolution['model'],
                'model_tier' => $resolution['model_tier'] ?? config('atlas.ai.default_tier', 'daily'),
                'model_allow_auto' => (bool) ($resolution['allow_auto'] ?? true),
                'model_allow_manual' => (bool) ($resolution['allow_manual'] ?? true),
            ]),
        ];

        if ($job->model !== $resolution['model']) {
            $job->forceFill($updates)->save();
        } elseif (
            data_get($metadata, 'model_identity_source') !== $resolution['source']
            || data_get($metadata, 'model_label') !== ($resolution['model_label'] ?? $resolution['model'])
            || data_get($metadata, 'model_tier') !== ($resolution['model_tier'] ?? config('atlas.ai.default_tier', 'daily'))
        ) {
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
                    'model_label' => $resolution['model_label'] ?? $resolution['model'],
                    'model_tier' => $resolution['model_tier'] ?? config('atlas.ai.default_tier', 'daily'),
                    'model_allow_auto' => (bool) ($resolution['allow_auto'] ?? true),
                    'model_allow_manual' => (bool) ($resolution['allow_manual'] ?? true),
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
        $this->persistInvocationFingerprint($job, $result);
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
                'metadata' => array_merge($job->metadata ?? [], $this->programmingDispatchUpdate($job, 'executed', $attempt->provider, $responseHash)),
            ]);

            try {
                $this->clarifier->completeAiClarification($job->refresh());
            } catch (\Throwable $exception) {
                report($exception);
            }

            if ($this->isAtlasScoutJob($job)) {
                return $this->completeAtlasScoutJob($job, $attempt, $result, $workerId);
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

            if ($this->shouldEvaluateNativeProgrammingRepair($job)) {
                $repairOutcome = $this->handleNativeProgrammingRepair($job, $attempt, $result, $responseHash);
                if ($repairOutcome !== null) {
                    return $repairOutcome;
                }
            }

            $job->trace?->update([
                'status' => 'succeeded',
                'provider' => $attempt->provider,
                'model' => $attempt->model,
                'response_hash' => $responseHash,
                'response_text' => $result->output,
                'latency_ms' => $result->durationMs,
                'completed_at' => now(),
                'metadata' => array_merge($job->trace->metadata ?? [], $this->programmingDispatchUpdate($job, 'executed', $attempt->provider, $responseHash)),
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

        if ($this->shouldFallbackGeminiToClaude($job, $attempt, $result)) {
            return $this->fallbackGeminiToClaude($job, $attempt, $result, $workerId);
        }

        if ($this->shouldDegradeAtlasScoutImmediately($job, $result)) {
            return $this->failAtlasScoutJobAndReleaseExecutor($job, $attempt, $result, $workerId);
        }

        if ($this->shouldPauseForChoice($job, $result)) {
            return $this->pauseForChoice($job, $attempt, $result, $workerId);
        }

        $nonRetryable = in_array($result->errorCode, ['permission_denied', 'policy_violation'], true);
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
            'metadata' => array_merge($job->metadata ?? [], $result->metadata, $this->programmingDispatchUpdate($job, $finalFailure ? 'blocked' : 'retrying', $attempt->provider, null, $result->errorCode)),
        ]);

        if ($finalFailure && $this->isAtlasScoutJob($job)) {
            return $this->releaseExecutorAfterAtlasScout($job->refresh(), $attempt, null, $result, $workerId);
        }

        if ($this->isCouncilJob($job)) {
            $synced = $this->council->sync($job->trace()->firstOrFail());
            if ($finalFailure && $synced->status === 'failed') {
                // evaluateQuality on terminal failure so that traces with non-empty response
                // (e.g. provider returned text before erroring out) get classified by the
                // heuristic evaluator. The evaluator's internal gate skips empty responses.
                $this->evaluateQuality($synced);
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
            ], $result->metadata, $this->programmingDispatchUpdate($job, $finalFailure ? 'blocked' : 'retrying', $attempt->provider, null, $result->errorCode)),
        ]);

        if ($finalFailure && $job->trace) {
            $failedTrace = $job->trace->refresh();
            // evaluateQuality on the single-job failure path mirrors the council branch
            // above. Without this, failed traces have no quality signal and downstream
            // analysis cannot distinguish "failed loudly with diagnostic output" from
            // "failed silently with nothing".
            $this->evaluateQuality($failedTrace);
            $this->completeRemediationActions($failedTrace);
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

    private function withPowerSessionMetadata(AiProviderResult $result, string $powerSessionId): AiProviderResult
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
            metadata: array_merge($result->metadata, ['power_session_id' => $powerSessionId]),
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

    private function isAtlasScoutJob(AiJob $job): bool
    {
        return data_get($job->metadata, 'atlas_decide_stage') === 'context_scout'
            || data_get($job->payload, 'atlas_decide_execution.atlas_decide_stage') === 'context_scout';
    }

    private function isAtlasPrimaryExecutorJob(AiJob $job): bool
    {
        return data_get($job->metadata, 'atlas_decide_stage') === 'primary_executor'
            && data_get($job->metadata, 'dependency_state') === 'pending'
            && is_string(data_get($job->metadata, 'dependency_job_id'));
    }

    private function applyExpiredAtlasScoutDependency(AiJob $job): AiJob
    {
        if (! $this->isAtlasPrimaryExecutorJob($job)) {
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

    private function completeAtlasScoutJob(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        $job = $this->releaseExecutorAfterAtlasScout($job->refresh(), $attempt, $result, null, $workerId);

        $this->logger->event('job_succeeded', 'Atlas Decide context scout completed and released executor.', 'info', $attempt->provider, $job, $attempt, workerId: $workerId);
        $this->recordTelemetry('job_succeeded', $job, $attempt, [
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
            'privacy' => $this->privacyFromJob($job),
            'refs' => [
                'trace_id' => $job->trace_id,
                'job_id' => $job->id,
                'attempt_id' => $attempt->id,
            ],
        ]);

        return $job->refresh()->load(['trace', 'attemptHistory']);
    }

    private function shouldDegradeAtlasScoutImmediately(AiJob $job, AiProviderResult $result): bool
    {
        return $this->isAtlasScoutJob($job)
            && in_array($result->errorCode, ['rate_limited', 'auth_expired', 'policy_violation'], true);
    }

    private function failAtlasScoutJobAndReleaseExecutor(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
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

    private function releaseExecutorAfterAtlasScout(
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

        $this->emitStreamEvent($scoutJob, $attempt, 'lifecycle', 'atlas_scout_released_executor', '', [
            'executor_job_id' => $executor->id,
            'dependency_state' => $dependencyState,
        ], 'system');
        $this->recordTelemetry('atlas_scout_released_executor', $scoutJob, $attempt, [
            'event_phase' => 'worker',
            'metadata' => [
                'worker_id' => $workerId,
                'executor_job_id' => $executor->id,
                'dependency_state' => $dependencyState,
            ],
        ]);

        return $scoutJob->refresh()->load(['trace', 'attemptHistory']);
    }

    /**
     * @param  array<string,mixed>  $extraMetadata
     */
    private function applyAtlasScoutBriefToExecutor(AiJob $executor, ?AiJob $scoutJob, string $brief, string $dependencyState, array $extraMetadata = []): AiJob
    {
        $metadata = array_merge($executor->metadata ?? [], [
            'dependency_state' => $dependencyState,
            'dependency_resolved_at' => now()->toJSON(),
            'dependency_job_id' => $scoutJob?->id ?: data_get($executor->metadata, 'dependency_job_id'),
            'dependency_provider' => $scoutJob?->provider ?: data_get($executor->metadata, 'dependency_provider'),
            'dependency_model' => $scoutJob?->model ?: data_get($executor->metadata, 'dependency_model'),
        ], $extraMetadata);
        $payload = is_array($executor->payload) ? $executor->payload : [];
        $payload['atlas_decide_execution'] = array_merge(
            is_array($payload['atlas_decide_execution'] ?? null) ? $payload['atlas_decide_execution'] : [],
            [
                'dependency_state' => $dependencyState,
                'dependency_resolved_at' => $metadata['dependency_resolved_at'],
                'dependency_job_id' => $metadata['dependency_job_id'],
            ],
        );

        $executor->forceFill([
            'prompt' => $this->promptWithAtlasScoutBrief($executor->prompt, $brief),
            'available_at' => now(),
            'reserved_at' => null,
            'started_at' => null,
            'worker_id' => null,
            'payload' => $payload,
            'metadata' => $metadata,
        ])->save();

        return $executor->refresh()->load('trace');
    }

    private function atlasScoutBrief(AiJob $scoutJob, string $output): string
    {
        return trim(<<<TEXT
Atlas Decide context scout concluido.
provider: {$scoutJob->provider}
model: {$scoutJob->model}
job_id: {$scoutJob->id}

{$output}
TEXT);
    }

    private function atlasScoutFailureBrief(?AiJob $scoutJob, ?string $errorCode, ?string $errorMessage): string
    {
        $provider = $scoutJob?->provider ?: 'unknown';
        $model = $scoutJob?->model ?: 'unknown';
        $jobId = $scoutJob?->id ?: 'unknown';
        $errorCode = $errorCode ?: 'scout_unavailable';
        $errorMessage = $errorMessage ?: 'Scout de contexto indisponivel; siga com o contexto original e marque incertezas.';

        return trim(<<<TEXT
Atlas Decide context scout degradado.
provider: {$provider}
model: {$model}
job_id: {$jobId}
error_code: {$errorCode}
error_message: {$errorMessage}

Siga com o contexto original. Se a tarefa depender de arquivos, logs ou decisões nao carregadas, explicite a lacuna antes de concluir.
TEXT);
    }

    private function promptWithAtlasScoutBrief(string $prompt, string $brief): string
    {
        $brief = Str::limit(trim($brief), 20000, '...');

        return rtrim($prompt)."\n\n# Atlas Decide Context Scout\n\n{$brief}\n";
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

    private function persistInvocationFingerprint(AiJob $job, AiProviderResult $result): void
    {
        $fingerprint = data_get($result->metadata, 'claude_invocation_fingerprint');
        if (! is_array($fingerprint)) {
            return;
        }

        $metadata = array_merge(is_array($job->metadata) ? $job->metadata : [], [
            'claude_invocation_fingerprint' => $fingerprint,
        ]);
        $job->forceFill(['metadata' => $metadata])->save();

        $trace = $job->trace ?: $job->trace()->first();
        if ($trace) {
            $trace->forceFill([
                'metadata' => array_merge($trace->metadata ?? [], [
                    'claude_invocation_fingerprint' => $fingerprint,
                ]),
            ])->save();
        }
    }

    private function fairModeRuntimeViolation(AiJob $job, string $providerKey, mixed $model, bool $requireModel = true): ?array
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        if (! $this->fairClaude->isFairPayload($payload) && ! $this->fairClaude->isFairPayload($metadata)) {
            return null;
        }

        if (! $requireModel && $providerKey !== FairClaudePolicy::PROVIDER_LOCK) {
            return $this->fairClaude->violation(
                message: 'Fair Claude mode requires provider claude_cli.',
                details: ['provider' => $providerKey],
            );
        }

        if (! $requireModel) {
            return null;
        }

        $mergedPayload = array_merge($payload, [
            'fair_mode' => data_get($payload, 'fair_mode') ?: data_get($metadata, 'fair_mode'),
            'dev_execution_plan' => data_get($payload, 'dev_execution_plan') ?: data_get($metadata, 'dev_execution_plan'),
        ]);
        $model = is_string($model) || is_numeric($model) ? trim((string) $model) : null;
        $violation = $this->fairClaude->validateInvocation($providerKey, $model !== '' ? $model : null, $mergedPayload);

        return (bool) ($violation['ok'] ?? false) ? null : $violation;
    }

    /**
     * @param  array<string,mixed>  $violation
     */
    private function fairModeViolationResult(array $violation): AiProviderResult
    {
        $message = (string) ($violation['message'] ?? 'Fair Claude mode violation.');

        return new AiProviderResult(
            ok: false,
            output: '',
            command: [],
            exitCode: null,
            durationMs: 0,
            stdout: '',
            stderr: $message,
            errorCode: FairClaudePolicy::ERROR_CODE,
            errorMessage: $message,
            metadata: [
                'fair_mode_violation' => $violation,
            ],
        );
    }

    private function shouldFallbackGeminiToClaude(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result): bool
    {
        if ($this->fairClaude->isFairPayload(is_array($job->payload) ? $job->payload : [])
            || $this->fairClaude->isFairPayload(is_array($job->metadata) ? $job->metadata : [])) {
            return false;
        }

        if ($this->isCouncilJob($job)) {
            return false;
        }

        if (($attempt->provider ?: $job->provider) !== 'gemini_cli') {
            return false;
        }

        if (! in_array($result->errorCode, ['rate_limited', 'auth_expired'], true)) {
            return false;
        }

        if (! $this->fallbackProviderWithinBudget($job, 'claude_cli')) {
            return false;
        }

        return data_get($job->metadata, 'gemini_fallback_attempted') !== true;
    }

    private function fallbackProviderWithinBudget(AiJob $job, string $provider): bool
    {
        $model = $this->models->resolve($provider, null);

        try {
            $this->budgets->assertAllows($provider, $model, [
                'payload' => is_array($job->payload) ? $job->payload : [],
            ]);

            return true;
        } catch (\RuntimeException $exception) {
            $metadata = array_merge($job->metadata ?? [], [
                'fallback_budget_blocked' => true,
                'fallback_budget_provider' => $provider,
                'fallback_budget_model' => $model,
                'fallback_budget_error' => $exception->getMessage(),
                'fallback_budget_checked_at' => now()->toIso8601String(),
            ]);
            $job->forceFill(['metadata' => $metadata])->save();
            $job->trace?->forceFill([
                'metadata' => array_merge($job->trace->metadata ?? [], [
                    'fallback_budget_blocked' => true,
                    'fallback_budget_provider' => $provider,
                    'fallback_budget_model' => $model,
                    'fallback_budget_error' => $exception->getMessage(),
                ]),
            ])->save();

            $this->logger->event(
                eventType: 'provider_fallback_budget_blocked',
                message: 'Gemini fallback to Claude blocked by runtime budget.',
                severity: 'warning',
                provider: $job->provider,
                job: $job,
                metadata: [
                    'fallback_provider' => $provider,
                    'fallback_model' => $model,
                    'budget_error' => $exception->getMessage(),
                ],
            );

            return false;
        }
    }

    private function fallbackGeminiToClaude(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        $fallbackProvider = 'claude_cli';
        $metadata = array_merge($job->metadata ?? [], [
            'gemini_fallback_attempted' => true,
            'fallback_state' => 'queued',
            'fallback_reason' => $result->errorCode,
            'fallback_error_message' => $result->errorMessage,
            'fallback_degraded' => true,
            'lost_capabilities' => ['long_context_full', 'native_multimodal'],
            'original_provider' => 'gemini_cli',
            'original_model' => $attempt->model ?: $job->model,
            'fallback_provider' => $fallbackProvider,
            'fallback_at' => now()->toIso8601String(),
        ]);
        $payload = is_array($job->payload) ? $job->payload : [];
        $payload['provider_fallback'] = [
            'original_provider' => 'gemini_cli',
            'original_model' => $attempt->model ?: $job->model,
            'fallback_provider' => $fallbackProvider,
            'fallback_reason' => $result->errorCode,
            'fallback_degraded' => true,
            'lost_capabilities' => ['long_context_full', 'native_multimodal'],
        ];

        $job->forceFill([
            'status' => 'queued',
            'provider' => $fallbackProvider,
            'model' => null,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'available_at' => now(),
            'reserved_at' => null,
            'started_at' => null,
            'worker_id' => null,
            'finished_at' => null,
            'max_attempts' => max((int) $job->max_attempts, (int) $job->attempts + 1),
            'payload' => $payload,
            'metadata' => $metadata,
        ])->save();

        $job->trace?->forceFill([
            'status' => 'queued',
            'provider' => $fallbackProvider,
            'model' => null,
            'metadata' => array_merge($job->trace->metadata ?? [], [
                'provider_fallback' => $payload['provider_fallback'],
                'last_error_code' => $result->errorCode,
                'last_error_message' => $result->errorMessage,
            ]),
        ])->save();

        $this->logger->event(
            eventType: 'provider_fallback_requeued',
            message: 'Gemini job requeued to Claude after quota/capacity or auth fallback.',
            severity: 'warning',
            provider: 'gemini_cli',
            job: $job,
            attempt: $attempt,
            metadata: [
                'fallback_provider' => $fallbackProvider,
                'fallback_reason' => $result->errorCode,
                'worker_id' => $workerId,
            ],
            workerId: $workerId,
        );
        $this->recordTelemetry('provider_fallback_requeued', $job, $attempt, [
            'event_phase' => 'worker',
            'duration_ms' => $result->durationMs,
            'metadata' => [
                'worker_id' => $workerId,
                'original_provider' => 'gemini_cli',
                'fallback_provider' => $fallbackProvider,
                'fallback_reason' => $result->errorCode,
            ],
        ]);
        $this->audit->record('ai_provider_fallback_requeued', [
            'subject_type' => 'ai_job',
            'subject_id' => $job->id,
            'severity' => 'warning',
            'summary' => 'Gemini indisponivel ou sem autenticacao; job reenfileirado para Claude.',
            'evidence' => [
                'original_provider' => 'gemini_cli',
                'original_model' => $attempt->model ?: $job->model,
                'fallback_provider' => $fallbackProvider,
                'fallback_reason' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ],
            'privacy' => $this->privacyFromJob($job),
            'refs' => [
                'trace_id' => $job->trace_id,
                'job_id' => $job->id,
                'attempt_id' => $attempt->id,
            ],
        ]);

        return $job->refresh()->load(['trace', 'attemptHistory']);
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
            fairMode: $this->fairClaude->isFairPayload(is_array($job->payload) ? $job->payload : [])
                || $this->fairClaude->isFairPayload(is_array($job->metadata) ? $job->metadata : []),
        );

        $lastAttemptedId = data_get($job->metadata, 'provider_choice_last_attempted_option_id');
        if (is_string($lastAttemptedId) && $lastAttemptedId !== '') {
            $options = array_values(array_filter(
                $options,
                fn (array $opt): bool => ($opt['id'] ?? null) !== $lastAttemptedId,
            ));
        }

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
