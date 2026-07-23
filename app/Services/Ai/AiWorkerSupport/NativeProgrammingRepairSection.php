<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AiWorker;
use App\Services\Ai\Cli\AtlasCliQualityService;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\HumanSurface\AiExecutionPresentationState;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Failure\FailureClassification;
use App\Services\Ai\Kernel\Repair\AtlasRepairOrchestrator;
use App\Services\Ai\Kernel\Repair\RepairDecision;
use App\Services\Ai\Kernel\Repair\RepairRequestFactory;
use App\Services\Ai\Kernel\Repair\RepairStrategy;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Programming\AtlasProgrammingOrchestrator;
use App\Services\Ai\Programming\ProgrammingIterationPolicy;

/**
 * Programming provider-policy + native programming-repair family extracted
 * VERBATIM from AiWorker (GOD-DEBULK entangled-family split). Bodies are byte
 * -identical modulo the parent-back-reference rewrites: cross-cutting facade
 * helpers are reached via $this->parent->x(); the thin ProgrammingRepairSupport
 * delegators via $this->programmingRepairSupport->x(). Facade AiWorker keeps
 * same-signature delegators for the methods still called on the hot path.
 */
class NativeProgrammingRepairSection
{
    public function __construct(
        private readonly AiWorker $parent,
        private readonly KernelSloProbe $slo,
        private readonly AtlasCliQualityService $cliQuality,
        private readonly AiExecutionPresentationState $presentationStates,
        private readonly AtlasProgrammingOrchestrator $programming,
        private readonly RepairRequestFactory $repairRequests,
        private readonly AtlasRepairOrchestrator $repairOrchestrator,
        private readonly ProgrammingRepairSupportSection $programmingRepairSupport,
    ) {}

    public function shouldEvaluateNativeProgrammingRepair(AiJob $job): bool
    {
        if ($this->parent->isCouncilJob($job) || $this->parent->isAtlasScoutJob($job)) {
            return false;
        }

        return (bool) data_get($job->payload, 'programming_repair.enabled')
            && data_get($job->payload, 'programming_dispatch.dispatch_path') === 'ai_gateway_provider'
            && data_get($job->payload, 'programming_dispatch.executor') === 'dev_repair_executor';
    }

    public function isProgrammingProviderDispatch(AiJob $job): bool
    {
        return ! $this->parent->isCouncilJob($job)
            && ! $this->parent->isAtlasScoutJob($job)
            && data_get($job->payload, 'programming_dispatch.dispatch_path') === 'ai_gateway_provider';
    }

    public function applyProgrammingProviderPolicyRuntime(AiJob $job): AiJob
    {
        if (! $this->isProgrammingProviderDispatch($job)) {
            return $job;
        }

        $toolContract = $this->programmingRepairSupport->programmingProviderToolContract($job);
        if ($this->programmingRepairSupport->programmingRepairAllowsWorkspaceWrite($toolContract)) {
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
     * Obra 5 / DEV-05 — AtlasContextRuntime certify on ai_gateway_provider dispatch.
     * Fail-open: stamps enforcement on the job; blocks only when certify fails closed.
     */
    public function certifyProgrammingGatewayContext(AiJob $job): AiJob
    {
        if (! $this->isProgrammingProviderDispatch($job)) {
            return $job;
        }

        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $task = trim((string) ($payload['prompt'] ?? $payload['message'] ?? $job->prompt ?? ''));
        if ($task === '') {
            return $job;
        }

        try {
            $runtime = app(AtlasContextRuntime::class);
            $workspace = (string) data_get($payload, 'workspace', data_get($payload, 'programming_dispatch.workspace', base_path()));
            $enforcement = $runtime->certifyEnforcement([
                'flow_id' => (string) data_get($payload, 'programming_dispatch.flow', 'programming.dev'),
                'domain' => 'programming',
                'task_type' => (string) data_get($payload, 'programming_dispatch.task_kind', 'dev'),
                'provider' => (string) ($job->provider ?? 'ai_gateway'),
                'provider_target' => 'external',
                'objective' => $task,
                'workspace' => $workspace,
                'strict_retrieval_gate' => (bool) config('atlas.programming.strict_retrieval_gate', true),
            ]);
            $payload['programming_context_runtime_enforcement'] = $enforcement;
            $metadata['programming_context_runtime_enforcement'] = $enforcement;

            if (($enforcement['status'] ?? 'passed') === 'blocked'
                && (bool) config('atlas.programming.context_runtime_fail_closed', true)) {
                $payload['programming_context_runtime_blocked'] = true;
            }

            $job->forceFill(['payload' => $payload, 'metadata' => $metadata])->save();
        } catch (\Throwable $e) {
            $payload['programming_context_runtime_enforcement'] = [
                'status' => 'degraded',
                'error' => $e->getMessage(),
            ];
            $job->forceFill(['payload' => $payload])->save();
        }

        return $job->refresh()->load('trace');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function programmingProviderPolicyViolation(AiJob $job): ?array
    {
        if (! $this->isProgrammingProviderDispatch($job)) {
            return null;
        }

        if ((bool) data_get($job->payload, 'programming_context_runtime_blocked', false)) {
            $enforcement = (array) data_get($job->payload, 'programming_context_runtime_enforcement', []);

            return array_filter([
                'schema_version' => 1,
                'source' => 'atlas_context_runtime',
                'scope' => 'provider_execution',
                'context_runtime_enforced' => true,
                'blocked_reason' => 'context_runtime_certify_blocked',
                'message' => 'Programming provider execution blocked: AtlasContextRuntime certify failed closed.',
                'enforcement' => $enforcement,
                'enforced_at' => now()->toJSON(),
            ], fn (mixed $value): bool => $value !== null && $value !== []);
        }

        $gateContract = $this->programmingRepairSupport->programmingProviderGateContract($job);
        if (! $this->programmingRepairSupport->programmingRepairGateRequiresEvidence($gateContract)) {
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

    public function handleNativeProgrammingRepair(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, ?string $responseHash, string $workerId): ?AiJob
    {
        $repair = (array) data_get($job->payload, 'programming_repair', []);

        return $this->slo->measure('repair.loop', function () use ($job, $attempt, $result, $responseHash, $workerId): ?AiJob {
            return $this->handleNativeProgrammingRepairUnmeasured($job, $attempt, $result, $responseHash, $workerId);
        }, array_merge($this->parent->sloContextForJob($job, $attempt, $workerId), [
            'current_iteration' => max(1, (int) ($repair['current_iteration'] ?? 1)),
            'max_iterations' => ProgrammingIterationPolicy::forRepairPolicy(
                $repair['max_iterations'] ?? data_get($job->payload, 'programming_message_plan.execution_profile.max_iterations', 1),
            ),
            'complete_mode' => (bool) ($repair['complete_mode'] ?? data_get($job->payload, 'programming_message_plan.execution_profile.complete', false)),
            'workspace_present' => $this->programmingRepairSupport->programmingRepairWorkspace($job) !== null,
        ]));
    }

    public function handleNativeProgrammingRepairUnmeasured(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, ?string $responseHash, string $workerId): ?AiJob
    {
        $workspace = $this->programmingRepairSupport->programmingRepairWorkspace($job);
        if ($workspace === null) {
            return null;
        }

        $repair = (array) data_get($job->payload, 'programming_repair', []);
        $messagePlan = (array) data_get($job->payload, 'programming_message_plan', []);
        $gateContract = $this->programmingRepairSupport->programmingRepairGateContract($job, $repair, $messagePlan);
        $toolContract = $this->programmingRepairSupport->programmingRepairToolContract($job, $repair, $messagePlan);
        $completeMode = (bool) ($repair['complete_mode'] ?? data_get($messagePlan, 'execution_profile.complete', false));
        $runTests = $completeMode
            || (bool) data_get($messagePlan, 'execution_profile.auto_test', false)
            || $this->programmingRepairSupport->programmingRepairGateRequiresEvidence($gateContract);
        $quality = $this->cliQuality->evaluate(
            workspace: $workspace,
            runTests: $runTests,
            testCommand: $this->programmingRepairSupport->programmingRepairTestCommand($job),
            approved: true,
            traceId: $job->trace_id,
        );
        $qualityStatus = (string) ($quality['status'] ?? 'unknown');
        $currentIteration = max(1, (int) ($repair['current_iteration'] ?? 1));
        $maxIterations = ProgrammingIterationPolicy::forRepairPolicy(
            $repair['max_iterations'] ?? data_get($messagePlan, 'execution_profile.max_iterations', 1),
        );
        $this->parent->recordLedgerEvent(LedgerEventType::GateEvaluated, $job, $attempt, $this->programmingRepairSupport->programmingRepairLedgerPayload($quality, [
            'run_tests' => $runTests,
            'complete_mode' => $completeMode,
            'current_iteration' => $currentIteration,
            'max_iterations' => $maxIterations,
            'gate_contract' => $gateContract,
        ]), $workerId);
        $qualityWorsened = (bool) ($repair['stop_when_quality_worsens'] ?? true)
            && $this->programmingRepairSupport->programmingRepairQualityWorsened($qualityStatus, $repair['previous_quality_status'] ?? null);
        $shouldRepair = in_array($qualityStatus, (array) ($repair['repair_when_status'] ?? ['failed', 'needs_review']), true)
            && ! $qualityWorsened
            && $currentIteration < $maxIterations;
        $finalPassed = in_array($qualityStatus, (array) ($repair['stop_when_status'] ?? ['passed']), true);
        $toolBlocksRepair = $shouldRepair && ! $this->programmingRepairSupport->programmingRepairAllowsWorkspaceWrite($toolContract);
        $kernelRepairDecision = ! $finalPassed
            ? $this->nativeProgrammingRepairKernelDecision(
                job: $job,
                attempt: $attempt,
                quality: $quality,
                repair: $repair,
                currentIteration: $currentIteration,
                maxIterations: $maxIterations,
                evidenceRefs: $this->programmingRepairSupport->nativeProgrammingRepairEvidenceRefs($job, $attempt, $quality),
            )
            : null;
        $kernelBlocksRepair = $shouldRepair
            && $kernelRepairDecision instanceof RepairDecision
            && ! $kernelRepairDecision->allowsRepair();

        if ($shouldRepair && ! $toolBlocksRepair && ! $kernelBlocksRepair) {
            $repairJob = $this->enqueueNativeProgrammingRepairJob($job, $quality, $currentIteration + 1, $maxIterations);
            $this->parent->recordLedgerEvent(LedgerEventType::RepairInitiated, $job, $attempt, $this->programmingRepairSupport->programmingRepairLedgerPayload($quality, [
                'repair_job_id' => $repairJob->id,
                'current_iteration' => $currentIteration,
                'next_iteration' => $currentIteration + 1,
                'max_iterations' => $maxIterations,
                'reason' => 'quality_gate_requested_repair',
                'kernel_repair' => $kernelRepairDecision?->toArray(),
            ]), $workerId);
            $metadata = $this->nativeProgrammingRepairMetadata($job, $repair, $quality, [
                'status' => 'repairing',
                'current_iteration' => $currentIteration,
                'next_iteration' => $currentIteration + 1,
                'kernel_decision' => $kernelRepairDecision?->toArray(),
            ], $attempt->provider, $responseHash);
            $replanningPresentationState = $this->presentationStates->replanning(
                currentIteration: $currentIteration,
                nextIteration: $currentIteration + 1,
                maxIterations: $maxIterations,
                trace: $job->trace,
            );

            $job->trace?->update([
                'status' => 'queued',
                'provider' => $attempt->provider,
                'model' => $attempt->model,
                'response_hash' => $responseHash,
                'response_text' => $result->output,
                'latency_ms' => $result->durationMs,
                'completed_at' => null,
                'metadata' => array_merge($job->trace->metadata ?? [], $metadata, [
                    'presentation_state' => $replanningPresentationState,
                    // C19: replanejamento HONESTO — a versão corrente do plano é
                    // arquivada em plan_revisions[] antes da nova iteração; nada
                    // é sobrescrito em silêncio. Mesmo padrão do fluxo dev
                    // (metadata_json.plan_revisions); a casca pode então mostrar
                    // "v1 arquivado · comparar versões" com dado real.
                    'plan_revisions' => AiWorker::planRevisionsAfterReplan(
                        $job->trace->metadata ?? [],
                        $currentIteration,
                        'quality_gate_requested_repair',
                        now()->toIso8601String(),
                    ),
                ]),
            ]);
            if ($job->trace) {
                $this->parent->emitStreamEvent($job, $attempt, 'lifecycle', 'execution_replanning', '', [
                    'presentation_state' => $replanningPresentationState,
                ], 'system');
            }

            return $job->refresh()->load(['trace', 'attemptHistory']);
        }

        if (! $finalPassed) {
            $reasonIfStopped = match (true) {
                $kernelBlocksRepair => 'kernel_repair_contract_blocks',
                $toolBlocksRepair => 'tool_contract_blocks_workspace_write',
                $qualityWorsened => 'quality_gate_worsened',
                default => 'max_iterations_or_quality_failed',
            };
            $this->parent->recordLedgerEvent(LedgerEventType::GateBlocked, $job, $attempt, $this->programmingRepairSupport->programmingRepairLedgerPayload($quality, [
                'current_iteration' => $currentIteration,
                'max_iterations' => $maxIterations,
                'reason' => $reasonIfStopped,
                'kernel_repair' => $kernelRepairDecision?->toArray(),
            ]), $workerId);
            $this->parent->recordLedgerEvent(LedgerEventType::RepairCompleted, $job, $attempt, $this->programmingRepairSupport->programmingRepairLedgerPayload($quality, [
                'repair_status' => $qualityWorsened || $toolBlocksRepair || $kernelBlocksRepair ? 'stopped' : 'exhausted',
                'current_iteration' => $currentIteration,
                'max_iterations' => $maxIterations,
                'reason' => $reasonIfStopped,
                'kernel_repair' => $kernelRepairDecision?->toArray(),
            ]), $workerId);
            $metadata = $this->nativeProgrammingRepairMetadata($job, $repair, $quality, [
                'status' => $qualityWorsened || $toolBlocksRepair || $kernelBlocksRepair ? 'stopped' : 'exhausted',
                'current_iteration' => $currentIteration,
                'reason_if_stopped' => $reasonIfStopped,
                'kernel_decision' => $kernelRepairDecision?->toArray(),
            ], $attempt->provider, $responseHash, blocked: true);
            $failedPresentationState = $this->presentationStates->failed(trace: $job->trace);

            $job->trace?->update([
                'status' => 'failed',
                'provider' => $attempt->provider,
                'model' => $attempt->model,
                'response_hash' => $responseHash,
                'response_text' => $result->output,
                'latency_ms' => $result->durationMs,
                'completed_at' => now(),
                'metadata' => array_merge($job->trace->metadata ?? [], $metadata, [
                    'presentation_state' => $failedPresentationState,
                ]),
            ]);
            if ($job->trace) {
                $this->parent->emitStreamEvent($job, $attempt, 'lifecycle', 'execution_failed', '', [
                    'presentation_state' => $failedPresentationState,
                ], 'system');
            }

            return $job->refresh()->load(['trace', 'attemptHistory']);
        }

        $this->parent->recordLedgerEvent(LedgerEventType::GatePassed, $job, $attempt, $this->programmingRepairSupport->programmingRepairLedgerPayload($quality, [
            'current_iteration' => $currentIteration,
            'max_iterations' => $maxIterations,
        ]), $workerId);
        $this->parent->recordLedgerEvent(LedgerEventType::RepairCompleted, $job, $attempt, $this->programmingRepairSupport->programmingRepairLedgerPayload($quality, [
            'repair_status' => 'passed',
            'current_iteration' => $currentIteration,
            'max_iterations' => $maxIterations,
        ]), $workerId);
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
     * @param  array<string,mixed>  $quality
     */
    public function enqueueNativeProgrammingRepairJob(AiJob $job, array $quality, int $iteration, int $maxIterations): AiJob
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

    public function nativeProgrammingRepairMetadata(
        AiJob $job,
        array $repair,
        array $quality,
        array $repairUpdates,
        ?string $provider,
        ?string $responseHash,
        bool $blocked = false,
    ): array {
        $dispatchStatus = $blocked ? 'blocked' : 'executed';
        $metadata = $this->parent->programmingDispatchUpdate($job, $dispatchStatus, $provider, $responseHash, $blocked ? 'quality_gate_failed' : null);
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
            'history' => $this->programmingRepairSupport->programmingRepairHistory($job, $repairUpdates, $quality),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
        $metadata['programming_completion'] = array_filter($completion, fn (mixed $value): bool => $value !== null && $value !== []);
        $metadata['programming_repair'] = array_merge($repair, $repairUpdates, [
            'last_quality_status' => $quality['status'] ?? null,
            'last_quality_diff_hash' => $quality['diff_hash'] ?? null,
            'kernel_decision' => $repairUpdates['kernel_decision'] ?? null,
            'updated_at' => now()->toJSON(),
        ]);

        return $metadata;
    }

    /**
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $repair
     * @param  array<int,string>  $evidenceRefs
     */
    public function nativeProgrammingRepairKernelDecision(
        AiJob $job,
        AiJobAttempt $attempt,
        array $quality,
        array $repair,
        int $currentIteration,
        int $maxIterations,
        array $evidenceRefs,
    ): RepairDecision {
        $context = $this->parent->kernelContextForJob($job);
        $qualityStatus = (string) ($quality['status'] ?? 'unknown');

        $request = $this->repairRequests->fromKernelContext(
            envelopeId: $context['envelope_id'],
            receiptId: $context['receipt_id'],
            failure: new FailureClassification(
                domain: $this->programmingRepairSupport->nativeProgrammingRepairFailureDomain($qualityStatus),
                source: 'ai_worker.native_programming_repair',
                signals: array_values(array_filter([
                    'quality_status:'.$qualityStatus,
                    is_string($quality['decision'] ?? null) ? 'quality_decision:'.$quality['decision'] : null,
                    is_string($quality['diff_hash'] ?? null) ? 'diff_hash_present' : null,
                ])),
                metadata: [
                    'job_id' => $job->id,
                    'attempt_id' => $attempt->id,
                    'current_iteration' => $currentIteration,
                    'max_iterations' => $maxIterations,
                    'quality_status' => $qualityStatus,
                ],
            ),
            policy: [
                'enabled' => (bool) ($repair['enabled'] ?? true),
                'max_attempts' => max(0, $maxIterations - 1),
                'allowed_strategies' => (array) ($repair['allowed_strategies'] ?? RepairStrategy::values()),
                'requires_evidence_for_heavy_repair' => (bool) ($repair['requires_evidence_for_heavy_repair'] ?? true),
                'metadata' => [
                    'source' => 'programming_repair',
                    'complete_mode' => (bool) ($repair['complete_mode'] ?? false),
                ],
            ],
            currentAttempt: max(0, $currentIteration - 1),
            evidenceRefs: $evidenceRefs,
            dryRun: false,
            metadata: [
                'surface' => 'ai_worker',
                'flow' => 'programming.repair',
                'contract_bridge' => 'native_programming_repair',
            ],
        );

        return $this->repairOrchestrator->plan($request);
    }

}
