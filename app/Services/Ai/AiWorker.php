<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Models\AiJobAttempt;
use App\Models\AiMission;
use App\Models\AiQualityAction;
use App\Models\AiTrace;
use App\Services\Ai\Analysis\AiQualityActionService;
use App\Services\Ai\Analysis\AiQualityEvaluator;
use App\Services\Ai\Arena\AiCouncilCoordinator;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasSwarmAutoFailoverService;
use App\Services\Ai\Cli\AtlasCliQualityService;
use App\Services\Ai\ConversationOps\AiSessionStateService;
use App\Services\Ai\Context\AiConversationRecorder;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\Evidence\CertificationRuntimeService;
use App\Services\Ai\Evidence\MissionEvidenceAdapter;
use App\Services\Ai\Gateway\ChatWeakResponseProbe;
use App\Services\Ai\Governance\AiPermissionDecision;
use App\Services\Ai\Governance\AiPermissionEngine;
use App\Services\Ai\Hermes\Mesh\HermesMeshJobRunner;
use App\Services\Ai\HumanSurface\AiExecutionPresentationState;
use App\Services\Ai\Instrumentation\AiWorkerLogger;
use App\Services\Ai\Knowledge\YouTubeKnowledgeIngestionService;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\ProviderUsagePayload;
use App\Services\Ai\Kernel\Failure\FailureClassification;
use App\Services\Ai\Kernel\Failure\FailureDomain;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineAuditService;
use App\Services\Ai\Kernel\Pipeline\KernelPipelineRuntimeGuard;
use App\Services\Ai\Kernel\Repair\AtlasRepairOrchestrator;
use App\Services\Ai\Kernel\Repair\RepairDecision;
use App\Services\Ai\Kernel\Repair\RepairRequestFactory;
use App\Services\Ai\Kernel\Repair\RepairStrategy;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;
use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionEvidenceService;
use App\Services\Ai\Mission\MissionLifecycleService;
use App\Services\Ai\Mobile\JobResultInboxEmitter;
use App\Services\Ai\Policy\AiRuntimeBudgetService;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Services\Ai\Policy\PermissionGateService;
use App\Services\Ai\Programming\AtlasProgrammingOrchestrator;
use App\Services\Ai\Programming\ProgrammingIterationPolicy;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryCanon;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryRecorder;
use App\Services\Ai\Router\AtlasSemanticFlowArbiterService;
use App\Services\Ai\Streaming\AiStreamRecorder;
use App\Services\Ai\Surface\AtlasFinalResponseSanitizer;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use App\Services\AuditLogService;
use App\Services\MacAgent\MacAgentService;
use App\Services\Semantic\CaptureSemanticClarifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Ai\AiWorkerSupport\AtlasScoutBriefSection;
use App\Services\Ai\AiWorkerSupport\AtlasScoutLifecycleSection;
use App\Services\Ai\AiWorkerSupport\CompletionSideEffectsSection;
use App\Services\Ai\AiWorkerSupport\KernelGovernanceSection;
use App\Services\Ai\AiWorkerSupport\MacBackgroundReadinessSection;
use App\Services\Ai\AiWorkerSupport\NativeProgrammingRepairSection;
use App\Services\Ai\AiWorkerSupport\PermissionSteerSection;
use App\Services\Ai\AiWorkerSupport\ProgrammingRepairSupportSection;
use App\Services\Ai\AiWorkerSupport\ProviderFairnessBudgetSection;
use App\Services\Ai\AiWorkerSupport\ProviderPauseFallbackSection;
use App\Services\Ai\AiWorkerSupport\AiWorkerGitShortstatSupport;
use App\Services\Ai\AiWorkerSupport\AiWorkerJobPredicatesSupport;
use App\Services\Ai\AiWorkerSupport\AiWorkerPlanRevisionsSupport;
use App\Services\Ai\AiWorkerSupport\AiWorkerTimeDiffSupport;
use App\Services\Ai\AiWorkerSupport\ReadyPromptPrepSection;
use App\Services\Ai\AiWorkerSupport\StaleJobRecoverySection;

class AiWorker
{

    /**
     * Opt-in seam (Patamar 4 · ADML closed feedback loop). Wired by
     * AppServiceProvider so unit tests can construct AiWorker without
     * pulling the live outcome ledger.
     */
    private ?AtlasDecideLiveOutcomeFeedbackService $liveOutcomeFeedback = null;

    /**
     * Opt-in seam (Patamar 4 · A4 · Swarm Auto-Failover). When wired AND
     * both flags are ON, a primary provider failure triggers a K=2 swarm
     * dispatch via the production resolver, and the winning arm's result
     * replaces the failure. Default: null → original behaviour preserved.
     */
    private ?AtlasSwarmAutoFailoverService $swarmAutoFailover = null;

    private ?EliteExecutorKernel $eliteKernel = null;

    public function setEliteExecutorKernel(?EliteExecutorKernel $kernel): void
    {
        $this->eliteKernel = $kernel;
    }

    public function setSwarmAutoFailover(?AtlasSwarmAutoFailoverService $svc): void
    {
        $this->swarmAutoFailover = $svc;
    }

    public function setLiveOutcomeFeedback(?AtlasDecideLiveOutcomeFeedbackService $svc): void
    {
        $this->liveOutcomeFeedback = $svc;
    }

    private readonly MacBackgroundReadinessSection $macBackgroundReadiness;

    private readonly PermissionSteerSection $permissionSteer;

    private readonly ProgrammingRepairSupportSection $programmingRepairSupport;

    private readonly KernelGovernanceSection $kernelGovernance;

    private readonly ReadyPromptPrepSection $readyPromptPrep;

    private readonly ProviderFairnessBudgetSection $providerFairnessBudget;

    private readonly AtlasScoutBriefSection $scoutBrief;

    private readonly NativeProgrammingRepairSection $nativeProgrammingRepair;

    private readonly StaleJobRecoverySection $staleJobRecovery;

    private readonly AtlasScoutLifecycleSection $atlasScout;

    private readonly CompletionSideEffectsSection $completionSideEffects;

    private readonly ProviderPauseFallbackSection $providerPauseFallback;

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
        private readonly AiExecutionPresentationState $presentationStates,
        private readonly AiRuntimeBudgetService $budgets,
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
        private readonly FairClaudePolicy $fairClaude,
        private readonly AtlasProgrammingOrchestrator $programming,
        private readonly AtlasCliQualityService $cliQuality,
        private readonly MacAgentService $macAgent,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly DecisionReceiptRuntimeGuard $decisionReceipts,
        private readonly KernelPipelineRuntimeGuard $kernelPipelines,
        private readonly KernelPipelineAuditService $kernelPipelineAudit,
        private readonly KernelSloProbe $slo,
        private readonly AtlasRepairOrchestrator $repairOrchestrator,
        private readonly RepairRequestFactory $repairRequests,
        private readonly ProviderUsagePayload $providerUsage,
        private readonly AtlasFinalResponseSanitizer $finalResponses,
        private readonly YouTubeKnowledgeIngestionService $youtubeKnowledge,
        private readonly AiPromptBuilder $prompts,
        private readonly PermissionGateService $permissionGates,
        private readonly MissionLifecycleService $missionLifecycle,
        private readonly MissionEvidenceService $missionEvidence,
        private readonly MissionCertificationService $missionCertification,
        private readonly MissionEvidenceAdapter $missionEvidenceAdapter,
        private readonly HermesMeshJobRunner $meshJobRunner,
    ) {
        $this->macBackgroundReadiness = new MacBackgroundReadinessSection($this->logger, $this->macAgent);
        $this->permissionSteer = new PermissionSteerSection($this->finalResponses, $this->states);
        $this->programmingRepairSupport = new ProgrammingRepairSupportSection;
        $this->kernelGovernance = new KernelGovernanceSection(
            $this->kernelPipelines,
            $this->kernelPipelineAudit,
            $this->missionLifecycle,
            $this->missionEvidence,
            $this->missionCertification,
            $this->missionEvidenceAdapter,
            $this->permissionGates,
            $this->logger,
        );
        $this->readyPromptPrep = new ReadyPromptPrepSection($this->youtubeKnowledge, $this->prompts);
        $this->providerFairnessBudget = new ProviderFairnessBudgetSection(
            $this->fairClaude,
            $this->models,
            $this->budgets,
            $this->logger,
        );
        $this->scoutBrief = new AtlasScoutBriefSection;
        $this->nativeProgrammingRepair = new NativeProgrammingRepairSection(
            $this,
            $this->slo,
            $this->cliQuality,
            $this->presentationStates,
            $this->programming,
            $this->repairRequests,
            $this->repairOrchestrator,
            $this->programmingRepairSupport,
        );
        $this->staleJobRecovery = new StaleJobRecoverySection(
            $this,
            $this->council,
            $this->presentationStates,
            $this->logger,
        );
        $this->atlasScout = new AtlasScoutLifecycleSection(
            $this,
            $this->logger,
            $this->audit,
            $this->scoutBrief,
        );
        $this->completionSideEffects = new CompletionSideEffectsSection(
            $this->states,
            $this->quality,
            $this->qualityActions,
        );
        $this->providerPauseFallback = new ProviderPauseFallbackSection(
            $this,
            $this->fairClaude,
            $this->presentationStates,
            $this->choices,
            $this->logger,
            $this->audit,
            $this->providerUsage,
            $this->providerFairnessBudget,
        );
    }

    public function runNext(?string $providerOverride = null, ?string $workerId = null, ?callable $onStream = null): ?AiJob
    {
        return $this->runNextMatching(null, $providerOverride, $workerId, $onStream);
    }

    public function runNextForTrace(string $traceId, ?string $providerOverride = null, ?string $workerId = null, ?callable $onStream = null): ?AiJob
    {
        return $this->runNextMatching($traceId, $providerOverride, $workerId, $onStream);
    }

    private function refreshReadyYouTubePrompt(AiJob $job): AiJob
    {
        return $this->readyPromptPrep->refreshReadyYouTubePrompt($job);
    }

    private function applySemanticFlowArbiter(AiJob $job): AiJob
    {
        return $this->readyPromptPrep->applySemanticFlowArbiter($job);
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
        $job = $this->applySemanticFlowArbiter($job);
        $job = $this->applyProgrammingProviderPolicyRuntime($job);
        $job = $this->certifyProgrammingGatewayContext($job);
        $job = $this->refreshReadyYouTubePrompt($job);
        if ($violation = $this->decisionReceipts->violationForJob($job, $providerKey, $job->model)) {
            $violationPayload = $violation->toArray();
            $attempt = $this->createAttempt($job, $workerId, $providerKey);
            $this->emitStreamEvent($job, $attempt, 'policy', 'decision_receipt_blocked', $violation->message, [
                'decision_receipt_enforcement' => $violationPayload,
            ], null, $onStream);

            return $this->completeAttempt($job, $attempt, new AiProviderResult(
                ok: false,
                output: '',
                command: [],
                exitCode: null,
                durationMs: 0,
                stdout: '',
                stderr: '',
                errorCode: $violation->errorCode,
                errorMessage: $violation->message,
                metadata: [
                    'decision_receipt_enforcement' => $violationPayload,
                ],
            ), $workerId);
        }
        if ($kernelPipelineViolation = $this->kernelPipelines->violationForJob($job)) {
            $attempt = $this->createAttempt($job, $workerId, $providerKey);
            $this->kernelPipelineAudit->recordRejectedPlan(
                $this->kernelPipelines->auditablePlanForJob($job, $kernelPipelineViolation),
                (array) data_get($kernelPipelineViolation, 'violations', []),
                $this->kernelPipelines->auditContextForJob($job),
            );
            $this->emitStreamEvent($job, $attempt, 'policy', 'kernel_pipeline_contract_blocked', (string) $kernelPipelineViolation['message'], [
                'kernel_pipeline_contract_enforcement' => $kernelPipelineViolation,
            ], null, $onStream);

            return $this->completeAttempt($job, $attempt, new AiProviderResult(
                ok: false,
                output: '',
                command: [],
                exitCode: null,
                durationMs: 0,
                stdout: '',
                stderr: '',
                errorCode: 'kernel_pipeline_contract_violation',
                errorMessage: (string) $kernelPipelineViolation['message'],
                metadata: [
                    'kernel_pipeline_contract_enforcement' => $kernelPipelineViolation,
                ],
            ), $workerId);
        }
        $this->recordAcceptedKernelPipelineRuntimeContract($job);
        $this->recordKernelPermissionGate($job, $providerKey, $workerId);
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

            // Live Cockpit · verify falhou no contrato de policy.
            $this->emitStreamEvent($job, $attempt, 'lifecycle', 'pipeline_verify_failed', '', [
                'checkpoint' => 'verify',
                'outcome' => 'failed',
                'gate_name' => 'policy_contract',
                'reason' => (string) ($policyViolation['message'] ?? 'policy_contract_blocked'),
            ], 'system', $onStream);

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
        } elseif ($violation = $this->decisionReceipts->violationForJob($job, $providerKey, $job->model)) {
            // Recheck at the effect boundary: permission, steering and policy
            // work can consume enough time for an otherwise valid authority to
            // expire. No provider lookup, ProviderCalled event or run may occur
            // after this point without a fresh guard result.
            $violationPayload = $violation->toArray();
            $this->emitStreamEvent($job, $attempt, 'policy', 'decision_receipt_blocked', $violation->message, [
                'decision_receipt_enforcement' => $violationPayload,
            ], null, $onStream);

            $result = new AiProviderResult(
                ok: false,
                output: '',
                command: [],
                exitCode: null,
                durationMs: 0,
                stdout: '',
                stderr: '',
                errorCode: $violation->errorCode,
                errorMessage: $violation->message,
                metadata: [
                    'decision_receipt_enforcement' => $violationPayload,
                ],
            );
        } else {
            $provider = $this->providers->get($providerKey);

            // Provider resolution itself is not the effect, but may consume
            // time (or block). Revalidate immediately before telemetry,
            // ProviderCalled and the provider run so an expired receipt cannot
            // cross that boundary.
            if ($violation = $this->decisionReceipts->violationForJob($job, $providerKey, $job->model)) {
                $violationPayload = $violation->toArray();
                $this->emitStreamEvent($job, $attempt, 'policy', 'decision_receipt_blocked', $violation->message, [
                    'decision_receipt_enforcement' => $violationPayload,
                ], null, $onStream);

                $result = new AiProviderResult(
                    ok: false,
                    output: '',
                    command: [],
                    exitCode: null,
                    durationMs: 0,
                    stdout: '',
                    stderr: '',
                    errorCode: $violation->errorCode,
                    errorMessage: $violation->message,
                    metadata: [
                        'decision_receipt_enforcement' => $violationPayload,
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
                    $this->recordLedgerEvent(LedgerEventType::ProviderCalled, $job, $attempt, array_merge(
                        $this->providerUsage->called($job, $attempt, $this->kernelContextForJob($job)),
                        [
                            'prompt_hash' => $attempt->prompt_hash,
                            'timeout_seconds' => $job->timeout_seconds,
                            'permission_mode' => $permission->mode,
                            'permission_allowed' => $permission->allowed,
                        ],
                    ), $workerId);
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
                        $result = $this->slo->measure('runtime.execute', function () use ($provider, $job, $attempt, $onStream, &$firstTokenRecorded, $powerSession): AiProviderResult {
                            // AtlasDecide-routed mesh fan-out (kind='mesh' + operator opted in).
                            // Returns null when not a mesh route / not opted-in / nothing
                            // dispatched, so we transparently fall back to the single provider.
                            $result = $this->meshJobRunner->run($job)
                                ?? $provider->runStreaming($job, $job->prompt, function (array $event) use ($job, $attempt, $onStream, &$firstTokenRecorded): void {
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

                            return $this->withPowerSessionMetadata($result, (string) $powerSession->id);
                        }, $this->sloContextForJob($job, $attempt, $workerId));
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
        }

        $result = $this->withPermissionMetadata($result, $permission);

        // Patamar 4 · A4 · Swarm Auto-Failover. When wired AND flags ON AND
        // primary result failed, fire a K=2 swarm dispatch and use the
        // winning arm outcome as the new result. Defensive: never throw.
        if ($this->swarmAutoFailover !== null && ! $result->ok) {
            try {
                $failoverResult = $this->swarmAutoFailover->observeProviderFailure($job, $result);
                if ($failoverResult !== null) {
                    $result = $failoverResult;
                }
            } catch (\Throwable $failoverErr) {
                // Defensive: keep original result on failover error.
            }
        }

        // Patamar 4 · ADML closed feedback loop. Record outcome of this provider
        // call so the Live Outcome Feedback ledger sees real online signal —
        // defensive: never throw from telemetry; never block the response.
        if ($this->liveOutcomeFeedback !== null) {
            try {
                $taskCategory = (string) (data_get($job->payload, 'task_category')
                    ?? data_get($job->payload, 'atlas_decide.task_category')
                    ?? 'unspecified');
                $role = (string) (data_get($job->payload, 'council_role')
                    ?? data_get($job->payload, 'role')
                    ?? 'primary');
                $framework = data_get($job->payload, 'framework');
                if (! is_string($framework) || $framework === '') {
                    $framework = null;
                }
                $resultKind = match (true) {
                    ($result->errorCode ?? null) === 'provider_timeout' => AtlasDecideLiveOutcomeFeedbackService::RESULT_TIMEOUT,
                    $result->ok === true => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                    default => AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE,
                };
                $inputTokens = data_get($result->metadata, 'input_tokens');
                $outputTokens = data_get($result->metadata, 'output_tokens');
                $tokensUsed = data_get($result->metadata, 'tokens_used');
                if ($tokensUsed === null && (is_numeric($inputTokens) || is_numeric($outputTokens))) {
                    $tokensUsed = (int) ($inputTokens ?? 0) + (int) ($outputTokens ?? 0);
                }
                $costUsd = data_get($result->metadata, 'cost_usd')
                    ?? data_get($result->metadata, 'cost_estimate_usd')
                    ?? data_get($result->metadata, 'estimated_cost_usd');
                $qualityScore = data_get($result->metadata, 'quality_score')
                    ?? data_get($result->metadata, 'atlas_decide.quality_score');
                // Chat weak-response probe → ADML: a structurally weak
                // response on a nominally successful call floors the quality
                // signal to 0.0 (our mechanical evidence beats any provider
                // self-declared score — O-5 anti-self-declared contract), so
                // learned routes that produce weak answers degrade instead of
                // looking permanently green.
                if ($result->ok) {
                    $probe = (new ChatWeakResponseProbe)->inspect(
                        $result->output,
                        is_array(data_get($job->payload, 'specialist_flow_execution'))
                            ? (array) data_get($job->payload, 'specialist_flow_execution')
                            : [],
                    );
                    if ($probe['weak']) {
                        $qualityScore = 0.0;
                    }
                }
                $this->liveOutcomeFeedback->record([
                    'task_category' => $taskCategory,
                    'role' => $role,
                    'framework' => $framework,
                    'provider' => $providerKey,
                    'model' => $attempt->model ?? null,
                    'result' => $resultKind,
                    'latency_ms' => is_int($result->durationMs) ? $result->durationMs : null,
                    'quality_score' => AiValueNormalizer::finiteFloatOrNull($qualityScore),
                    'cost_usd' => AiValueNormalizer::finiteFloatOrNull($costUsd),
                    'tokens_used' => is_numeric($tokensUsed) ? (int) $tokensUsed : null,
                    'input_tokens' => is_numeric($inputTokens) ? (int) $inputTokens : null,
                    'output_tokens' => is_numeric($outputTokens) ? (int) $outputTokens : null,
                    'language' => data_get($job->payload, 'programming_language')
                        ?? data_get($job->payload, 'language')
                        ?? data_get($job->payload, 'payload.language'),
                    'risk_level' => data_get($job->payload, 'risk_level')
                        ?? data_get($job->payload, 'atlas_decide.risk_level'),
                    'context_mode' => data_get($job->payload, 'context_delivery_policy.delivery_mode')
                        ?? data_get($job->payload, 'open_brain.mode'),
                    'tool_profile' => data_get($job->payload, 'execution_policy.tool_profile')
                        ?? data_get($job->payload, 'tool_profile'),
                    'repair_count' => data_get($result->metadata, 'repair_count')
                        ?? data_get($result->metadata, 'repair.attempt_count'),
                    'context_tokens' => is_numeric($inputTokens) ? (int) $inputTokens : null,
                    'proven_real' => data_get($result->metadata, 'proven_real') === true
                        || data_get($result->metadata, 'outcome_proof.proven_real') === true,
                    'actor' => 'ai_worker',
                ]);
            } catch (\Throwable $e) {
                // Defensive: ledger failure must never break the worker.
            }
        }

        return $this->completeAttempt($job, $attempt, $result, $workerId);
    }

    private function recordAcceptedKernelPipelineRuntimeContract(AiJob $job): void
    {
        $this->kernelGovernance->recordAcceptedKernelPipelineRuntimeContract($job);
    }

    private function assertEliteKernelHonestOutcome(AiJob $job, AiJobAttempt $attempt): void
    {
        if ($this->eliteKernel === null) {
            return;
        }

        $payload = is_array($job->payload) ? $job->payload : [];
        if (($payload['elite_kernel_honesty'] ?? 'auto') === 'skip') {
            return;
        }

        $execution = (array) data_get($payload, 'execution', data_get($payload, 'programming_completion.execution', []));
        // Trivial / thin success claims without execution evidence are unproven, not fake-green.
        if ($execution === [] && ($payload['elite_kernel_honesty'] ?? '') !== 'require') {
            return;
        }

        try {
            $this->eliteKernel->assertHonestOutcome([
                'status' => 'success',
                'execution' => $execution,
            ], 'dev');
        } catch (\Throwable $e) {
            // Obra 5 / DEV-09: fail-closed by default; emergency escape hatch local-only.
            if (app()->environment('local')
                && ((bool) env('ATLAS_ELITE_KERNEL_HONESTY_FAIL_OPEN', false)
                    || (bool) config('atlas.elite_kernel.honesty_fail_open', false))) {
                $this->logger->event('elite_kernel_fake_green_blocked', $e->getMessage(), 'warning', $attempt->provider, $job, $attempt);

                return;
            }

            $this->logger->event('elite_kernel_fake_green_fail_closed', $e->getMessage(), 'error', $attempt->provider, $job, $attempt);
            throw $e;
        }
    }

    private function completeKernelMissionFromSuccessfulJob(
        AiJob $job,
        AiJobAttempt $attempt,
        ?string $responseHash,
        string $workerId,
    ): void {
        $this->kernelGovernance->completeKernelMissionFromSuccessfulJob($job, $attempt, $responseHash, $workerId);
    }

    private function recordKernelPermissionGate(AiJob $job, string $providerKey, string $workerId): void
    {
        $this->kernelGovernance->recordKernelPermissionGate($job, $providerKey, $workerId);
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
                $this->recordLedgerEvent(LedgerEventType::ExecutionStarted, $job->refresh(), null, [
                    'attempt_number' => $job->attempts,
                    'provider_override' => $providerOverride,
                    'available_at' => $job->available_at?->toJSON(),
                ], $workerId);

                return $job->refresh()->load('trace');
            }

            return null;
        });
    }

    private function deferForMacBackgroundReadinessIfNeeded(AiJob $job, string $workerId): bool
    {
        return $this->macBackgroundReadiness->deferForMacBackgroundReadinessIfNeeded($job, $workerId);
    }

    private function recoverStaleProcessingJobs(string $workerId): void
    {
        $this->staleJobRecovery->recoverStaleProcessingJobs($workerId);
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
    public function programmingDispatchUpdate(
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
        return $this->nativeProgrammingRepair->shouldEvaluateNativeProgrammingRepair($job);
    }

    private function applyProgrammingProviderPolicyRuntime(AiJob $job): AiJob
    {
        return $this->nativeProgrammingRepair->applyProgrammingProviderPolicyRuntime($job);
    }

    private function certifyProgrammingGatewayContext(AiJob $job): AiJob
    {
        return $this->nativeProgrammingRepair->certifyProgrammingGatewayContext($job);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function programmingProviderPolicyViolation(AiJob $job): ?array
    {
        return $this->nativeProgrammingRepair->programmingProviderPolicyViolation($job);
    }

    private function handleNativeProgrammingRepair(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, ?string $responseHash, string $workerId): ?AiJob
    {
        return $this->nativeProgrammingRepair->handleNativeProgrammingRepair($job, $attempt, $result, $responseHash, $workerId);
    }

    /**
     * C18 — mede o diff corrente do workspace da execução (git shortstat vs
     * HEAD). Só quando existe workspace git de verdade; nunca fabrica número.
     *
     * @return array{files_touched:int,lines_added:int,lines_removed:int}|null
     */
    private function workspaceDiffStats(AiJob $job): ?array
    {
        $workspace = $this->programmingRepairWorkspace($job)
            ?: data_get($job->payload, 'workspace')
            ?: data_get($job->payload, 'programming_dispatch.workspace');
        if (! is_string($workspace) || trim($workspace) === '' || ! is_dir($workspace.'/.git')) {
            return null;
        }

        $out = @shell_exec('cd '.escapeshellarg($workspace).' && timeout 3 git diff HEAD --shortstat 2>/dev/null');

        return self::parseGitShortstat((string) $out);
    }

    /**
     * Parser PURO do shortstat ("3 files changed, 48 insertions(+), 12 deletions(-)").
     * Singular/plural e partes ausentes tratados; saída vazia = null (sem mudança
     * não é "0 inventado" — é ausência de diff, e a UI decide não mostrar).
     *
     * @return array{files_touched:int,lines_added:int,lines_removed:int}|null
     */
    public static function parseGitShortstat(string $out): ?array
    {
        return AiWorkerGitShortstatSupport::parse($out);
    }

    private function programmingRepairWorkspace(AiJob $job): ?string
    {
        return $this->programmingRepairSupport->programmingRepairWorkspace($job);
    }

    /**
     * @param  array<string,mixed>  $repair
     * @param  array<string,mixed>  $quality
     * @param  array<string,mixed>  $repairUpdates
     * @return array<string,mixed>
     */
    /**
     * C19 — histórico de plano no replanejamento. Função PURA sobre o metadata:
     * se existe um execution_plan corrente, ele entra como a próxima revisão em
     * plan_revisions[] (revision, iteration, reason, archived_at, execution_plan).
     * Sem plano corrente, o histórico anterior passa intocado — nunca uma
     * revisão vazia fabricada. Cap de 10 revisões (as mais recentes vencem).
     *
     * @param  array<string,mixed>  $metadata
     * @return array<int,array<string,mixed>>
     */
    public static function planRevisionsAfterReplan(
        array $metadata,
        int $iteration,
        string $reason,
        string $archivedAt,
    ): array {
        return AiWorkerPlanRevisionsSupport::afterReplan($metadata, $iteration, $reason, $archivedAt);
    }

    private function ensureJobModelIdentity(AiJob $job, string $providerKey): AiJob
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $resolution = $this->models->resolveWithSource($providerKey, $job->model, array_merge($metadata, $payload));
        if (! $resolution['model']) {
            return $job;
        }

        $updates = [
            'model' => $resolution['model'],
            'payload' => array_merge($payload, [
                'model_identity_source' => $resolution['source'],
                'model_label' => $resolution['model_label'] ?? $resolution['model'],
                'model_tier' => $resolution['model_tier'] ?? config('atlas.ai.default_tier', 'daily'),
                'selected_model' => $resolution['selected_model'] ?? $resolution['model'],
                'selected_model_alias' => $resolution['selected_model_alias'] ?? $resolution['model_alias'] ?? null,
                'operator_requested_model_alias' => $resolution['operator_requested_model_alias'] ?? null,
                'model_family' => $resolution['model_family'] ?? null,
                'model_selection_source' => $resolution['selection_source'] ?? $resolution['source'],
                'model_allow_auto' => (bool) ($resolution['allow_auto'] ?? true),
                'model_allow_manual' => (bool) ($resolution['allow_manual'] ?? true),
            ]),
            'metadata' => array_merge($metadata, [
                'model_identity_source' => $resolution['source'],
                'model_label' => $resolution['model_label'] ?? $resolution['model'],
                'model_tier' => $resolution['model_tier'] ?? config('atlas.ai.default_tier', 'daily'),
                'selected_model' => $resolution['selected_model'] ?? $resolution['model'],
                'selected_model_alias' => $resolution['selected_model_alias'] ?? $resolution['model_alias'] ?? null,
                'operator_requested_model_alias' => $resolution['operator_requested_model_alias'] ?? null,
                'model_family' => $resolution['model_family'] ?? null,
                'model_selection_source' => $resolution['selection_source'] ?? $resolution['source'],
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
        $result = $this->sanitizeProviderResultForOperator($result);
        $responseHash = $result->output !== '' ? hash('sha256', $result->output) : null;
        $attemptStatus = $result->ok ? 'succeeded' : ($result->errorCode === 'timeout' ? 'timeout' : 'failed');
        $job->refresh();

        if ($job->status === 'cancelled') {
            return $this->completeAttemptWhenCancelled($job, $attempt, $result, $workerId, $responseHash);
        }

        $this->persistAttemptProviderOutcome($job, $attempt, $result, $attemptStatus, $responseHash, $workerId);

        if ($result->ok) {
            return $this->completeAttemptWhenSucceeded($job, $attempt, $result, $workerId, $responseHash);
        }

        return $this->completeAttemptWhenFailed($job, $attempt, $result, $workerId);
    }

    private function completeAttemptWhenCancelled(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId, ?string $responseHash): AiJob
    {
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
        $this->recordLedgerEvent(LedgerEventType::OperationBlocked, $job, $attempt, [
            'reason' => 'cancelled_by_operator',
            'provider_result_ok' => $result->ok,
            'duration_ms' => $result->durationMs,
        ], $workerId);

        return $job->load(['trace', 'attemptHistory']);
    }

    private function persistAttemptProviderOutcome(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $attemptStatus, ?string $responseHash, string $workerId): void
    {
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
        if ($this->providerWasCalled($result)) {
            $this->recordLedgerEvent(LedgerEventType::ProviderReturned, $job, $attempt, $this->providerResultLedgerPayload($job, $attempt, $result, $responseHash), $workerId);
        } else {
            $this->recordLedgerEvent(LedgerEventType::OperationBlocked, $job, $attempt, [
                'reason' => $result->errorCode,
                'error_message_hash' => $result->errorMessage ? hash('sha256', $result->errorMessage) : null,
                'duration_ms' => $result->durationMs,
            ], $workerId);
        }

    }

    private function completeAttemptWhenSucceeded(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId, ?string $responseHash): AiJob
    {
        // Live Cockpit · verify (provider produziu resposta válida) +
        // evidence (output persistido no AiJob). São os 2 checkpoints
        // finais do pipeline antes do `response` terminal.
        // C18: estatística de diff REAL no checkpoint — medida no workspace
        // da execução via git shortstat. Sem workspace (chat read-mode) o
        // campo é ausente: a UI não inventa número, mostra só o passo N/M.
        $diffStats = $this->workspaceDiffStats($job);
        $this->emitStreamEvent($job, $attempt, 'lifecycle', 'pipeline_verify_passed', '', array_filter([
            'checkpoint' => 'verify',
            'outcome' => 'done',
            'gate_name' => 'provider_output',
            'duration_ms' => $result->durationMs,
            'diff_stats' => $diffStats,
        ], fn ($v) => $v !== null), 'system');
        $this->emitStreamEvent($job, $attempt, 'lifecycle', 'pipeline_evidence_appended', '', [
            'checkpoint' => 'evidence',
            'outcome' => 'done',
            'kind' => 'response',
            'response_hash' => $responseHash,
            'artifact_count' => 1,
        ], 'system');

        // Chat weak-response probe (advisory, chat-side sibling of Dev W1):
        // a structurally weak/contract-violating response is FLAGGED on the
        // job metadata for surfaces/telemetry — never blocked. Absent key
        // when clean keeps the metadata byte-identical to the pre-probe
        // baseline.
        $weakProbe = (new ChatWeakResponseProbe)->inspect(
            $result->output,
            is_array(data_get($job->payload, 'specialist_flow_execution'))
                ? (array) data_get($job->payload, 'specialist_flow_execution')
                : [],
        );

        $job->update([
            'status' => 'succeeded',
            'result_text' => $result->output,
            'error_code' => null,
            'error_message' => null,
            'finished_at' => now(),
            'metadata' => array_merge(
                $job->metadata ?? [],
                $this->programmingDispatchUpdate($job, 'executed', $attempt->provider, $responseHash),
                $weakProbe['weak']
                    ? ['weak_response' => ['detected' => true, 'reasons' => $weakProbe['reasons']]]
                    : [],
            ),
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
                $completedPresentationState = $this->presentationStates->completed(trace: $synced);
                $synced->update([
                    'metadata' => array_merge($synced->metadata ?? [], [
                        'presentation_state' => $completedPresentationState,
                    ]),
                ]);
                $this->emitStreamEvent($job, $attempt, 'lifecycle', 'execution_completed', '', [
                    'presentation_state' => $completedPresentationState,
                ], 'system');
                $synced->refresh();
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
                $this->recordLedgerEvent(LedgerEventType::OperationCompleted, $job, $attempt, [
                    'trace_status' => $synced->status,
                    'execution_policy' => 'dual_review',
                    'duration_ms' => $synced->latency_ms,
                    'response_hash' => $synced->response_hash,
                ], $workerId);
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
            $repairOutcome = $this->handleNativeProgrammingRepair($job, $attempt, $result, $responseHash, $workerId);
            if ($repairOutcome !== null) {
                return $repairOutcome;
            }
        }

        $completedPresentationState = $this->presentationStates->completed(trace: $job->trace);
        $job->trace?->update([
            'status' => 'succeeded',
            'provider' => $attempt->provider,
            'model' => $attempt->model,
            'response_hash' => $responseHash,
            'response_text' => $result->output,
            'latency_ms' => $result->durationMs,
            'completed_at' => now(),
            'metadata' => array_merge(
                $job->trace->metadata ?? [],
                $this->programmingDispatchUpdate($job, 'executed', $attempt->provider, $responseHash),
                ['presentation_state' => $completedPresentationState],
            ),
        ]);
        if ($job->trace) {
            $this->emitStreamEvent($job, $attempt, 'lifecycle', 'execution_completed', '', [
                'presentation_state' => $completedPresentationState,
            ], 'system');
        }

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
            $this->recordLedgerEvent(LedgerEventType::OperationCompleted, $job, $attempt, [
                'trace_status' => $trace->status,
                'duration_ms' => $trace->latency_ms,
                'response_hash' => $trace->response_hash,
            ], $workerId);
            $this->recomputeTraceMetrics($trace);
        }
        $this->completeKernelMissionFromSuccessfulJob($job->refresh(), $attempt, $responseHash, $workerId);
        $this->assertEliteKernelHonestOutcome($job, $attempt);
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

    private function completeAttemptWhenFailed(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
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
                $failedPresentationState = $this->presentationStates->failed(trace: $synced);
                $synced->update([
                    'metadata' => array_merge($synced->metadata ?? [], [
                        'presentation_state' => $failedPresentationState,
                    ]),
                ]);
                $this->emitStreamEvent($job, $attempt, 'lifecycle', 'execution_failed', '', [
                    'presentation_state' => $failedPresentationState,
                ], 'system');
                $synced->refresh();
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
                $this->recordLedgerEvent(LedgerEventType::OperationFailed, $job, $attempt, [
                    'trace_status' => $synced->status,
                    'execution_policy' => 'dual_review',
                    'duration_ms' => $synced->latency_ms,
                    'error_code' => $result->errorCode,
                    'error_message_hash' => $result->errorMessage ? hash('sha256', $result->errorMessage) : null,
                ], $workerId);
                $this->recomputeTraceMetrics($synced);
                $this->emitImportantJobResult($job->refresh(), 'failed');
            }

            return $job->refresh()->load(['trace', 'attemptHistory']);
        }

        $failedPresentationState = $finalFailure ? $this->presentationStates->failed(trace: $job->trace) : null;
        $job->trace?->update([
            'status' => $finalFailure ? 'failed' : 'queued',
            'provider' => $attempt->provider,
            'model' => $attempt->model,
            'latency_ms' => $result->durationMs,
            'completed_at' => $finalFailure ? now() : null,
            'metadata' => array_merge($job->trace->metadata ?? [], [
                'last_error_code' => $result->errorCode,
                'last_error_message' => $result->errorMessage,
            ], $result->metadata, $this->programmingDispatchUpdate($job, $finalFailure ? 'blocked' : 'retrying', $attempt->provider, null, $result->errorCode), $failedPresentationState ? [
                'presentation_state' => $failedPresentationState,
            ] : []),
        ]);
        if ($finalFailure && $job->trace && $failedPresentationState) {
            $this->emitStreamEvent($job, $attempt, 'lifecycle', 'execution_failed', '', [
                'presentation_state' => $failedPresentationState,
            ], 'system');
        }

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
                $this->recordLedgerEvent(LedgerEventType::OperationFailed, $job, $attempt, [
                    'trace_status' => $failedTrace->status,
                    'duration_ms' => $failedTrace->latency_ms,
                    'error_code' => $result->errorCode,
                    'error_event_type' => $eventType,
                    'error_message_hash' => $result->errorMessage ? hash('sha256', $result->errorMessage) : null,
                ], $workerId);
                $this->recomputeTraceMetrics($failedTrace);
            }
            $this->emitImportantJobResult($job->refresh(), 'failed');
        }

        return $job->refresh()->load(['trace', 'attemptHistory']);
    }

    private function sanitizeProviderResultForOperator(AiProviderResult $result): AiProviderResult
    {
        return $this->permissionSteer->sanitizeProviderResultForOperator($result);
    }

    private function applyPermissionRuntime(AiJob $job, AiPermissionDecision $permission): AiJob
    {
        return $this->permissionSteer->applyPermissionRuntime($job, $permission);
    }

    private function applyPendingSteer(AiJob $job): AiJob
    {
        return $this->permissionSteer->applyPendingSteer($job);
    }

    private function withPermissionMetadata(AiProviderResult $result, AiPermissionDecision $permission): AiProviderResult
    {
        return $this->permissionSteer->withPermissionMetadata($result, $permission);
    }

    private function withPowerSessionMetadata(AiProviderResult $result, string $powerSessionId): AiProviderResult
    {
        return $this->permissionSteer->withPowerSessionMetadata($result, $powerSessionId);
    }

    public function emitStreamEvent(
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
     * @return array{envelope_id:string,receipt_id:?string,tenant_id:string,operator_id:string}
     */
    public function kernelContextForJob(AiJob $job): array
    {
        $receipt = $this->decisionReceipts->receiptForJob($job);
        $receiptV2 = is_array(data_get($receipt, 'receipt_v2')) ? data_get($receipt, 'receipt_v2') : [];
        $envelopeId = (string) (
            data_get($receiptV2, 'envelope_id')
            ?: data_get($receipt, 'envelope_id')
            ?: data_get($job->metadata, 'decision_receipt.envelope_id')
            ?: data_get($job->payload, 'decision_receipt.envelope_id')
            ?: $job->trace_id
            ?: $job->id
        );
        $receiptId = data_get($receiptV2, 'receipt_id')
            ?: data_get($receipt, 'receipt_id')
            ?: data_get($job->metadata, 'decision_receipt.receipt_id')
            ?: data_get($job->payload, 'decision_receipt.receipt_id');

        return [
            'envelope_id' => $envelopeId,
            'receipt_id' => $receiptId ? (string) $receiptId : null,
            'tenant_id' => (string) (data_get($receiptV2, 'metadata.tenant_id') ?: data_get($job->payload, 'tenant_id') ?: data_get($job->metadata, 'tenant_id') ?: 'default'),
            'operator_id' => (string) (data_get($receiptV2, 'metadata.operator_id') ?: data_get($job->payload, 'operator_id') ?: data_get($job->metadata, 'operator_id') ?: 'system'),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function recordLedgerEvent(
        LedgerEventType $type,
        AiJob $job,
        ?AiJobAttempt $attempt = null,
        array $payload = [],
        ?string $workerId = null,
    ): void {
        try {
            $receipt = $this->decisionReceipts->receiptForJob($job);
            $receiptV2 = is_array(data_get($receipt, 'receipt_v2')) ? data_get($receipt, 'receipt_v2') : [];
            $envelopeId = (string) (
                data_get($receiptV2, 'envelope_id')
                ?: data_get($receipt, 'envelope_id')
                ?: data_get($job->metadata, 'decision_receipt.envelope_id')
                ?: data_get($job->payload, 'decision_receipt.envelope_id')
                ?: $job->trace_id
                ?: $job->id
            );
            $receiptId = data_get($receiptV2, 'receipt_id')
                ?: data_get($receipt, 'receipt_id')
                ?: data_get($job->metadata, 'decision_receipt.receipt_id')
                ?: data_get($job->payload, 'decision_receipt.receipt_id');
            $tenantId = data_get($receiptV2, 'metadata.tenant_id')
                ?: data_get($job->payload, 'tenant_id')
                ?: data_get($job->metadata, 'tenant_id')
                ?: 'default';
            $operatorId = data_get($receiptV2, 'metadata.operator_id')
                ?: data_get($job->payload, 'operator_id')
                ?: data_get($job->metadata, 'operator_id')
                ?: 'system';

            $this->ledger->record($type, array_merge([
                'envelope_id' => $envelopeId,
                'receipt_id' => $receiptId,
                'trace_id' => $job->trace_id,
                'job_id' => $job->id,
                'attempt_id' => $attempt?->id,
                'attempt_number' => $attempt?->attempt_number,
                'worker_id' => $workerId,
                'job_status' => $job->status,
                'attempt_status' => $attempt?->status,
                'agent_slug' => $job->agent_slug,
                'provider' => $attempt?->provider ?? $job->provider,
                'model' => $attempt?->model ?? $job->model,
            ], $payload), [
                'tenant_id' => (string) $tenantId,
                'operator_id' => (string) $operatorId,
                'envelope_id' => $envelopeId,
                'receipt_id' => $receiptId ? (string) $receiptId : null,
                'trace_id' => $job->trace_id,
                'correlation_id' => $job->trace_id ?: $envelopeId,
                'emitter_stage' => 'ai.worker',
                'emitter_version' => 'ai-worker-v1',
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        $this->recordNativeRepairTelemetry($type, $job, $attempt, $payload);
    }

    private function recordNativeRepairTelemetry(LedgerEventType $type, AiJob $job, ?AiJobAttempt $attempt, array $payload): void
    {
        $this->completionSideEffects->recordNativeRepairTelemetry($type, $job, $attempt, $payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function sloContextForJob(AiJob $job, AiJobAttempt $attempt, ?string $workerId): array
    {
        $receipt = $this->decisionReceipts->receiptForJob($job);
        $receiptV2 = is_array(data_get($receipt, 'receipt_v2')) ? data_get($receipt, 'receipt_v2') : [];
        $envelopeId = (string) (
            data_get($receiptV2, 'envelope_id')
            ?: data_get($receipt, 'envelope_id')
            ?: data_get($job->metadata, 'decision_receipt.envelope_id')
            ?: data_get($job->payload, 'decision_receipt.envelope_id')
            ?: $job->trace_id
            ?: $job->id
        );
        $receiptId = data_get($receiptV2, 'receipt_id')
            ?: data_get($receipt, 'receipt_id')
            ?: data_get($job->metadata, 'decision_receipt.receipt_id')
            ?: data_get($job->payload, 'decision_receipt.receipt_id');
        $tenantId = data_get($receiptV2, 'metadata.tenant_id')
            ?: data_get($job->payload, 'tenant_id')
            ?: data_get($job->metadata, 'tenant_id')
            ?: 'default';
        $operatorId = data_get($receiptV2, 'metadata.operator_id')
            ?: data_get($job->payload, 'operator_id')
            ?: data_get($job->metadata, 'operator_id')
            ?: 'system';

        return [
            'tenant_id' => (string) $tenantId,
            'operator_id' => (string) $operatorId,
            'envelope_id' => $envelopeId,
            'receipt_id' => $receiptId ? (string) $receiptId : null,
            'trace_id' => $job->trace_id,
            'correlation_id' => $job->trace_id ?: $envelopeId,
            'domain' => data_get($receiptV2, 'domain')
                ?: data_get($job->payload, 'domain')
                ?: data_get($job->payload, 'programming_message_plan.domain')
                ?: data_get($job->metadata, 'domain'),
            'flow' => data_get($receiptV2, 'flow')
                ?: data_get($job->payload, 'flow')
                ?: data_get($job->payload, 'programming_message_plan.flow')
                ?: data_get($job->metadata, 'flow'),
            'surface_id' => data_get($job->payload, 'surface_id')
                ?: data_get($job->payload, 'app_surface')
                ?: data_get($job->metadata, 'surface_id')
                ?: data_get($job->trace?->metadata, 'surface_id'),
            'job_id' => $job->id,
            'attempt_id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'worker_id' => $workerId,
            'provider' => $attempt->provider ?? $job->provider,
            'model' => $attempt->model ?? $job->model,
        ];
    }

    private function providerWasCalled(AiProviderResult $result): bool
    {
        return AiWorkerJobPredicatesSupport::providerWasCalled($result->errorCode);
    }

    /**
     * @return array<string,mixed>
     */
    private function providerResultLedgerPayload(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, ?string $responseHash): array
    {
        return $this->providerUsage->returned($job, $attempt, $result, $responseHash, $this->kernelContextForJob($job));
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    public function recordTelemetry(string $eventName, AiJob $job, ?AiJobAttempt $attempt = null, array $overrides = []): void
    {
        if (! DatabaseTableAvailability::has('ai_telemetry_events')) {
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
        $this->completionSideEffects->recomputeTraceMetrics($trace);
    }

    private function diffMs(mixed $start, mixed $end): ?int
    {
        return AiWorkerTimeDiffSupport::diffMs($start, $end);
    }

    private function epochMs(\DateTimeInterface $value): int
    {
        return AiWorkerTimeDiffSupport::epochMs($value);
    }

    public function isCouncilJob(AiJob $job): bool
    {
        return AiWorkerJobPredicatesSupport::isCouncilJob(
            (string) $job->kind,
            is_array($job->payload) ? $job->payload : [],
            is_array($job->metadata) ? $job->metadata : [],
        );
    }

    public function isAtlasScoutJob(AiJob $job): bool
    {
        return AiWorkerJobPredicatesSupport::isAtlasScoutJob(
            is_array($job->metadata) ? $job->metadata : [],
            is_array($job->payload) ? $job->payload : [],
        );
    }

    public function isAtlasPrimaryExecutorJob(AiJob $job): bool
    {
        return AiWorkerJobPredicatesSupport::isAtlasPrimaryExecutorJob(
            is_array($job->metadata) ? $job->metadata : [],
        );
    }

    private function applyExpiredAtlasScoutDependency(AiJob $job): AiJob
    {
        return $this->atlasScout->applyExpiredAtlasScoutDependency($job);
    }

    private function completeAtlasScoutJob(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        return $this->atlasScout->completeAtlasScoutJob($job, $attempt, $result, $workerId);
    }

    private function shouldDegradeAtlasScoutImmediately(AiJob $job, AiProviderResult $result): bool
    {
        return $this->atlasScout->shouldDegradeAtlasScoutImmediately($job, $result);
    }

    private function failAtlasScoutJobAndReleaseExecutor(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        return $this->atlasScout->failAtlasScoutJobAndReleaseExecutor($job, $attempt, $result, $workerId);
    }

    private function releaseExecutorAfterAtlasScout(AiJob $scoutJob, AiJobAttempt $attempt, ?AiProviderResult $success, ?AiProviderResult $failure, string $workerId): AiJob
    {
        return $this->atlasScout->releaseExecutorAfterAtlasScout($scoutJob, $attempt, $success, $failure, $workerId);
    }

    private function promptWithAtlasScoutBrief(string $prompt, string $brief): string
    {
        return $this->scoutBrief->promptWithAtlasScoutBrief($prompt, $brief);
    }

    public function privacyFromJob(AiJob $job): array
    {
        return AiWorkerJobPredicatesSupport::privacyFromJob(
            is_array($job->payload) ? $job->payload : [],
            is_array($job->metadata) ? $job->metadata : [],
        );
    }

    private function updateSessionStateForTrace(AiTrace $trace, string $response): void
    {
        $this->completionSideEffects->updateSessionStateForTrace($trace, $response);
    }

    private function evaluateQuality(AiTrace $trace): void
    {
        $this->completionSideEffects->evaluateQuality($trace);
    }

    private function completeRemediationActions(AiTrace $trace): void
    {
        $this->completionSideEffects->completeRemediationActions($trace);
    }

    private function shouldPauseForChoice(AiJob $job, AiProviderResult $result): bool
    {
        return $this->providerPauseFallback->shouldPauseForChoice($job, $result);
    }

    private function persistInvocationFingerprint(AiJob $job, AiProviderResult $result): void
    {
        $this->providerPauseFallback->persistInvocationFingerprint($job, $result);
    }

    private function fairModeRuntimeViolation(AiJob $job, string $providerKey, mixed $model, bool $requireModel = true): ?array
    {
        return $this->providerFairnessBudget->fairModeRuntimeViolation($job, $providerKey, $model, $requireModel);
    }

    private function fairModeViolationResult(array $violation): AiProviderResult
    {
        return $this->providerFairnessBudget->fairModeViolationResult($violation);
    }

    private function shouldFallbackGeminiToClaude(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result): bool
    {
        return $this->providerPauseFallback->shouldFallbackGeminiToClaude($job, $attempt, $result);
    }

    private function fallbackGeminiToClaude(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        return $this->providerPauseFallback->fallbackGeminiToClaude($job, $attempt, $result, $workerId);
    }

    private function pauseForChoice(AiJob $job, AiJobAttempt $attempt, AiProviderResult $result, string $workerId): AiJob
    {
        return $this->providerPauseFallback->pauseForChoice($job, $attempt, $result, $workerId);
    }

}
