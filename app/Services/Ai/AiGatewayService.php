<?php

namespace App\Services\Ai;

use App\Models\AiCompaction;
use App\Models\AiDecision;
use App\Models\AiJob;
use App\Models\AiMessage;
use App\Models\AiRouterDecision;
use App\Models\AiSession;
use App\Models\AiSpecialistFlowExecution;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Models\Capture;
use App\Services\Ai\Cli\AtlasFileAttachmentService;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Mission\AiGatewayMissionBridge;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use App\Services\Ai\ValueObjects\AiThreadResolution;
use App\Services\AuditLogService;
use App\Services\CapturePrivacyService;
use App\Support\AiAttachmentPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class AiGatewayService
{
    private const COUNCIL_PROVIDERS = ['claude_cli', 'codex_cli'];

    private const INVOCATION_PROVIDERS = ['claude_cli', 'codex_cli', 'gemini_cli'];

    private const TRANSACTION_ATTEMPTS = 5;

    public function __construct(
        private readonly AiPromptBuilder $prompts,
        private readonly AiThreadResolver $threads,
        private readonly AiSessionManager $sessions,
        private readonly AiSessionStateService $states,
        private readonly AiCompactionService $compactions,
        private readonly AiProviderHandoffService $handoffs,
        private readonly AiContextSnapshotRecorder $snapshots,
        private readonly AiConversationRecorder $conversation,
        private readonly CapturePrivacyService $privacy,
        private readonly AiProviderModelResolver $models,
        private readonly AtlasAiRuntimeSettings $runtimeSettings,
        private readonly AiRuntimeBudgetService $budgets,
        private readonly AtlasDecideService $decide,
        private readonly FairClaudePolicy $fairClaude,
        private readonly AuditLogService $audit,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AtlasFileAttachmentService $fileAttachments,
        private readonly YouTubeKnowledgeIngestionService $youtubeKnowledge,
        private readonly AiStreamRecorder $stream,
        private readonly AiGatewayMissionBridge $missionBridge,
    ) {}

    public function enqueueInteraction(string $input, array $options = []): AiTrace
    {
        if (! config('atlas.ai.enabled')) {
            throw new RuntimeException('Atlas is disabled.');
        }

        $input = trim($input);
        if ($input === '') {
            throw new RuntimeException('AI input cannot be empty.');
        }

        if ($existingTrace = $this->existingTraceForClient($options['client_id'] ?? null)) {
            return $existingTrace;
        }

        $privacy = $this->privacyFromOptions($options);
        $this->guardPrivacyAllowsAi($options, $privacy, $input);
        $options['input_text'] = $input;
        $options = $this->decide->normalizeOptions($options);
        $provider = $this->providerFromOptions($options);
        $options['provider'] = $provider;
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $payload = $this->enforceFairModeProvider($payload, $provider);
        $options['payload'] = $payload;
        if ($this->fairClaude->isFairPayload($payload) && ! is_string($options['model'] ?? null)) {
            $expectedModel = $this->fairClaude->expectedResolvedModel($payload);
            if ($expectedModel !== null) {
                $options['model'] = $expectedModel;
            }
        }
        $fairMode = $this->fairClaude->isFairPayload($payload);
        if ($fairMode) {
            $decisionPayload = $this->fairModeDecisionPayload($payload, $provider);
            $candidateProvider = FairClaudePolicy::PROVIDER_LOCK;
            $fallbackReason = null;
        } else {
            $decision = $this->decide->operationalDecision($options, $provider);
            $decisionPayload = $decision->toArray();
            $candidateProvider = $decision->candidateProvider();
            $fallbackReason = $decision->fallbackReason();
        }
        $payload['selected_provider'] = $provider;
        $payload['atlas_decide'] = array_merge(
            is_array($payload['atlas_decide'] ?? null) ? $payload['atlas_decide'] : [],
            [
                'decision_id' => $decisionPayload['decision_id'] ?? null,
                'policy_profile_id' => $decisionPayload['policy_profile_id'] ?? null,
                'policy_version' => $decisionPayload['policy_version'] ?? null,
                'decision_policy_version' => $decisionPayload['decision_policy_version'] ?? null,
                'candidate_provider' => $candidateProvider,
                'selected_provider' => $provider,
                'fallback_provider' => $fallbackReason ? $provider : null,
                'fallback_reason' => $fallbackReason,
                'planned_graph' => $decisionPayload['planned_graph'] ?? null,
                'runtime_graph' => $decisionPayload['runtime_graph'] ?? null,
            ],
        );
        $payload['provider_governance'] = $this->providerGovernanceContract($payload, $provider, $decisionPayload, $candidateProvider, $fallbackReason);
        $options['payload'] = $payload;
        $threadResolution = $this->threads->resolve($input, $options);
        $session = $this->sessions->ensureActive($threadResolution->thread, $provider, $input, $options);
        $resumeCompaction = $this->maybeCompactSessionResume($threadResolution->thread, $session);
        $autoCompaction = $resumeCompaction ?: $this->compactions->maybeAutoCompact($threadResolution->thread, $session);
        $providerHandoff = $fairMode
            ? null
            : $this->handoffs->createIfSwitching($threadResolution->thread, $session, $provider, $autoCompaction, [
                'trigger' => 'enqueue_interaction',
            ]);
        $options = $this->optionsWithResolvedRuntime($options, $threadResolution->thread->id, $session->id, $autoCompaction?->id, $providerHandoff?->id);
        if ($fairMode) {
            $options['payload']['provider_handoff_disabled_by_fair_mode'] = true;
        }
        $options = $this->optionsWithYouTubeKnowledge($input, $options);
        $options = $this->optionsWithPdfQuestionVisuals($input, $options);
        $prompt = $this->prompts->build($input, $options);
        $options = $this->optionsWithPromptContracts($options, $prompt);
        $this->assertProgrammingContextContractsAllowRuntime($options);
        $this->assertProgrammingMemoryContractsAllowRuntime($options);
        $this->assertProgrammingSkillContractsAllowRuntime($options);
        $this->assertProgrammingToolContractsAllowRuntime($options);
        if ($this->shouldRunCouncil($options)) {
            return $this->enqueueCouncilInteraction($input, $options, $prompt, $privacy, $threadResolution, $session, $autoCompaction, $providerHandoff);
        }

        $modelResolution = $this->models->resolveWithSource($provider, $prompt->model ?: ($options['model'] ?? null));
        $model = $modelResolution['model'];
        $this->enforceFairModeModel((array) ($options['payload'] ?? []), $provider, $model);
        $this->budgets->assertAllows($provider, $model, $options);
        $options = $this->optionsWithProgrammingModelGraphReceipt($options, $provider, $model);
        $this->assertProgrammingModelGraphAllowsRuntime($options);
        $scoutGate = $this->atlasScoutGate($this->optionsWithPromptContracts($options, $prompt), $provider, $model);
        $options = $this->optionsWithAtlasExecutionActivation($options, $scoutGate);
        $now = now();

        // ── Atlas AiWorker → Kernel · Phase 1 bridge ─────────────────────────
        // Behind `atlas_ai.kernel_http_integration.enabled` (default false).
        // Records a canonical `atlas.ai.aiworker.kernel_envelope.v1` so the
        // trace + job carry `mission_id` / `objective_id` / `work_order_id`
        // for Phase 2-6 wires. The bridge is contractually non-throwing —
        // failures convert into a stub envelope so the legacy worker path
        // proceeds unchanged. Canon: atlas-aiworker-kernel-integration-adr.md.
        $kernelEnvelope = $this->missionBridge->buildEnvelope($input, [
            'autonomy_level' => $options['autonomy_level'] ?? null,
            'risk_level' => $options['risk_level'] ?? null,
            'primary_domain' => $options['primary_domain'] ?? null,
            'actor_type' => 'ai_gateway',
        ]);

        return DB::transaction(function () use ($input, $options, $prompt, $provider, $model, $modelResolution, $scoutGate, $now, $privacy, $threadResolution, $session, $autoCompaction, $providerHandoff, $kernelEnvelope): AiTrace {
            $lockedThread = $this->lockThreadForTrace($threadResolution);
            $lockedSession = $this->lockSessionForTrace($session);
            $decisionReceipt = $this->decisionReceiptForTrace($this->optionsWithPromptContracts($options, $prompt), $provider, $model);
            $executorAvailableAt = $scoutGate['enabled']
                ? $this->atlasScoutDependencyDeadline($options)
                : ($options['available_at'] ?? $now);
            $atlasExecution = $this->atlasExecutionPayload($scoutGate);

            $trace = AiTrace::query()->create([
                'trace_key' => 'trace_'.Str::orderedUuid()->toString(),
                'thread_id' => $lockedThread->id,
                'session_id' => $lockedSession->id,
                'source_type' => $options['source_type'] ?? 'app',
                'source_id' => $options['source_id'] ?? null,
                'status' => 'queued',
                'operator_input' => $input,
                'intent' => $prompt->intent,
                'agent_slug' => $prompt->agentSlug,
                'provider' => $provider,
                'model' => $model,
                'skill_versions' => $prompt->skillVersions,
                'context_refs' => $prompt->contextRefs,
                'prompt_hash' => hash('sha256', $prompt->prompt),
                'metadata' => [
                    'mode' => $options['mode'] ?? 'async',
                    'client_id' => $options['client_id'] ?? null,
                    'privacy' => $privacy,
                    'thread' => $threadResolution->toArray(),
                    'session' => $this->sessionMetadata($lockedSession),
                    'auto_compaction_id' => $autoCompaction?->id,
                    'provider_handoff_id' => $providerHandoff?->id,
                    ...$this->modelRuntimeMetadata($modelResolution),
                    'task_request' => $prompt->taskRequest,
                    'context_pack' => $prompt->contextPack,
                    'open_brain_injection' => $prompt->openBrainInjection,
                    'execution_plan' => $prompt->executionPlan,
                    'skills_activated' => $prompt->activatedSkills,
                    'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
                    ...$this->programmingMetadata($options),
                    'decision_receipt' => $decisionReceipt,
                    'atlas_decide_execution' => $atlasExecution,
                    'kernel' => $kernelEnvelope,
                ],
            ]);

            $job = AiJob::query()->create([
                'trace_id' => $trace->id,
                'client_id' => $options['client_id'] ?? null,
                'kind' => $options['kind'] ?? 'interaction',
                'status' => 'queued',
                'priority' => (int) ($options['priority'] ?? 50),
                'agent_slug' => $prompt->agentSlug,
                'provider' => $provider,
                'model' => $model,
                'input_text' => $input,
                'prompt' => $prompt->prompt,
                'context_refs' => $prompt->contextRefs,
                'payload' => [
                    ...($options['payload'] ?? []),
                    'atlas_decide_execution' => $atlasExecution,
                    'privacy' => $privacy,
                    'thread' => $threadResolution->toArray(),
                    'session' => $this->sessionMetadata($lockedSession),
                    'auto_compaction_id' => $autoCompaction?->id,
                    'provider_handoff_id' => $providerHandoff?->id,
                    ...$this->modelRuntimeMetadata($modelResolution),
                    'task_request' => $prompt->taskRequest,
                    'context_pack' => $prompt->contextPack,
                    'open_brain_injection' => $prompt->openBrainInjection,
                    'execution_plan' => $prompt->executionPlan,
                    'skills_activated' => $prompt->activatedSkills,
                    'decision_receipt' => $decisionReceipt,
                    'kernel' => $kernelEnvelope,
                ],
                'available_at' => $executorAvailableAt,
                'max_attempts' => (int) ($options['max_attempts'] ?? config('atlas.ai.max_attempts', 1)),
                'timeout_seconds' => (int) ($options['timeout_seconds'] ?? config('atlas.ai.timeout_seconds', 600)),
                'metadata' => [
                    ...$atlasExecution,
                    'intent' => $prompt->intent,
                    'skill_versions' => $prompt->skillVersions,
                    'privacy' => $privacy,
                    'thread' => $threadResolution->toArray(),
                    'session' => $this->sessionMetadata($lockedSession),
                    'auto_compaction_id' => $autoCompaction?->id,
                    'provider_handoff_id' => $providerHandoff?->id,
                    ...$this->modelRuntimeMetadata($modelResolution),
                    'task_request' => $prompt->taskRequest,
                    'context_pack' => $prompt->contextPack,
                    'open_brain_injection' => $prompt->openBrainInjection,
                    'execution_plan' => $prompt->executionPlan,
                    'skills_activated' => $prompt->activatedSkills,
                    'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
                    ...$this->programmingMetadata($options),
                    'decision_receipt' => $decisionReceipt,
                    'kernel' => $kernelEnvelope,
                ],
            ]);
            if ($scoutGate['enabled']) {
                $this->enqueueAtlasScoutJob(
                    trace: $trace,
                    executorJob: $job,
                    input: $input,
                    prompt: $prompt,
                    options: $options,
                    privacy: $privacy,
                    threadResolution: $threadResolution,
                    sessionMetadata: $this->sessionMetadata($lockedSession),
                    autoCompactionId: $autoCompaction?->id,
                    providerHandoffId: $providerHandoff?->id,
                    availableAt: $options['available_at'] ?? $now,
                    priority: max(0, ((int) ($options['priority'] ?? 50)) - 1),
                    decisionReceipt: $decisionReceipt,
                );
            }

            $this->conversation->recordUserMessage($lockedThread, $trace, $input, [
                'source' => 'ai_gateway',
                'thread_resolution' => $threadResolution->toArray(),
                'session_id' => $lockedSession->id,
                ...$this->attachmentMetadata($options),
            ]);

            $this->states->updateForUserInput($lockedThread, $lockedSession, $input, $this->optionsWithPromptContracts($options, $prompt));
            $this->snapshots->record($trace, $lockedSession, $prompt, $autoCompaction, $providerHandoff);
            $this->recordTelemetry('trace_created', $trace, $job, [
                'surface' => 'server',
                'runtime' => 'laravel',
                'metadata' => [
                    'source_type' => $trace->source_type,
                    'kind' => $job->kind,
                    ...$this->modelRuntimeMetadata($modelResolution),
                    'task_type' => data_get($prompt->taskRequest, 'task_type'),
                    'workflow' => data_get($prompt->executionPlan, 'workflow'),
                ],
            ]);
            $this->recordTelemetry('job_enqueued', $trace, $job, [
                'surface' => 'server',
                'runtime' => 'laravel',
                'metadata' => [
                    'priority' => $job->priority,
                    'available_at' => $job->available_at?->toJSON(),
                    'max_attempts' => $job->max_attempts,
                    ...$this->modelRuntimeMetadata($modelResolution),
                ],
            ]);

            $this->audit->record('ai_trace_queued', [
                'subject_type' => 'ai_trace',
                'subject_id' => $trace->id,
                'summary' => "Interacao de IA enfileirada para {$prompt->agentSlug}.",
                'evidence' => [
                    'agent_slug' => $prompt->agentSlug,
                    'provider' => $provider,
                    'model' => $model,
                    ...$this->modelRuntimeMetadata($modelResolution),
                    'source_type' => $trace->source_type,
                    'source_id' => $trace->source_id,
                    'input_text' => $input,
                    'prompt_hash' => $trace->prompt_hash,
                    'task_type' => data_get($prompt->taskRequest, 'task_type'),
                    'risk_level' => data_get($prompt->taskRequest, 'risk_level'),
                    'workflow' => data_get($prompt->executionPlan, 'workflow'),
                    'skills_activated' => $prompt->activatedSkills,
                ],
                'privacy' => $privacy,
                'refs' => [
                    'trace_id' => $trace->id,
                    'thread_id' => $lockedThread->id,
                    'session_id' => $lockedSession->id,
                    'job_id' => $job->id,
                    'source_id' => $trace->source_id,
                ],
            ]);

            $this->recordAtlasDecision($trace, $options, $provider, $model, $prompt, $modelResolution);

            $this->emitPipelineCheckpoints($job, $input, $prompt, $provider, $model, $options);

            return $trace->load($this->traceRelations());
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Atlas Code Live Cockpit · emite 4 checkpoints iniciais do pipeline.
     *
     * Cada checkpoint usa event_type='lifecycle' (compatível com o CHECK
     * constraint de ai_stream_events.event_type) + metadata.checkpoint +
     * metadata.outcome. A surface Desktop discrimina por metadata.checkpoint.
     *
     * Os checkpoints 'verify' e 'evidence' são emitidos pelo AiWorker quando
     * o job realmente roda — aqui só os preparatórios (Intent → Context →
     * Plan → Provider) que já são conhecidos no momento do enqueue.
     */
    private function emitPipelineCheckpoints(AiJob $job, string $input, AiPrompt $prompt, string $provider, ?string $model, array $options): void
    {
        try {
            $intent = $this->classifyIntent($input, $prompt);
            $this->stream->record($job, null, 'lifecycle', '', [
                'checkpoint' => 'intent',
                'outcome' => 'done',
                'intent_type' => $intent['type'],
                'confidence' => $intent['confidence'],
                'classifier' => $intent['classifier'],
            ], 'system');

            $contextPack = is_array($prompt->contextPack ?? null) ? $prompt->contextPack : [];
            $docsCount = is_array($contextPack['docs'] ?? null) ? count($contextPack['docs']) : 0;
            $symbolsCount = is_array($contextPack['symbols'] ?? null) ? count($contextPack['symbols']) : 0;
            $tokensEstimate = (int) (data_get($contextPack, 'tokens_estimate')
                ?? data_get($prompt->taskRequest, 'tokens_estimate')
                ?? mb_strlen($prompt->prompt) / 4);
            $this->stream->record($job, null, 'lifecycle', '', [
                'checkpoint' => 'context',
                'outcome' => 'done',
                'docs_count' => $docsCount,
                'symbols_count' => $symbolsCount,
                'tokens_estimate' => $tokensEstimate,
            ], 'system');

            $planSteps = is_array($prompt->executionPlan['steps'] ?? null)
                ? count($prompt->executionPlan['steps'])
                : (is_array($prompt->executionPlan ?? null) ? 1 : 0);
            $this->stream->record($job, null, 'lifecycle', '', [
                'checkpoint' => 'plan',
                'outcome' => 'done',
                'steps' => $planSteps,
                'source' => $planSteps > 0 ? 'execution_plan' : 'inline_answer',
            ], 'system');

            $fallback = is_array($options['payload']['atlas_decide']['fallback_provider'] ?? null)
                ? $options['payload']['atlas_decide']['fallback_provider']
                : null;
            $this->stream->record($job, null, 'lifecycle', '', [
                'checkpoint' => 'provider',
                'outcome' => 'started',
                'provider' => $provider,
                'model' => $model,
                'fallback' => $fallback,
            ], 'system');
        } catch (\Throwable $e) {
            // Cockpit é additive — não pode bloquear o enqueue se a emissão
            // falhar. Logar e seguir.
            Log::warning('emitPipelineCheckpoints failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Classificador heurístico inicial. Sem LLM. Substituível por classifier
     * dedicado em fatia futura. Confidence fixa enquanto for heurístico.
     */
    private function classifyIntent(string $input, AiPrompt $prompt): array
    {
        $trim = mb_strtolower(trim($input));
        $type = 'task';
        if (str_contains($trim, '?')) {
            $type = 'question';
        }
        if (preg_match('/\b(intake|spec|plan|receipt|verify)\b/u', $trim) === 1) {
            $type = 'sdd';
        }
        if (is_string($prompt->intent ?? null) && $prompt->intent !== '') {
            $type = $prompt->intent;
        }

        return [
            'type' => $type,
            'confidence' => 0.85,
            'classifier' => 'heuristic_v1',
        ];
    }

    public function recordFeedback(AiTrace $trace, array $data): AiTrace
    {
        $trace->update([
            'feedback_score' => $data['feedback_score'] ?? $trace->feedback_score,
            'feedback_action' => $data['feedback_action'] ?? $trace->feedback_action,
            'feedback_comment' => $data['feedback_comment'] ?? $trace->feedback_comment,
        ]);

        return $trace->refresh()->load($this->traceRelations());
    }

    private function enqueueCouncilInteraction(string $input, array $options, AiPrompt $prompt, array $privacy, AiThreadResolution $threadResolution, $session, $autoCompaction = null, $providerHandoff = null): AiTrace
    {
        $providers = $this->councilProviders($options);
        $now = now();

        return DB::transaction(function () use ($input, $options, $prompt, $providers, $now, $privacy, $threadResolution, $session, $autoCompaction, $providerHandoff): AiTrace {
            $lockedThread = $this->lockThreadForTrace($threadResolution);
            $lockedSession = $this->lockSessionForTrace($session);
            $traceModelResolution = $this->models->resolveWithSource('claude_codex', $options['model'] ?? null);
            $options = $this->optionsWithProgrammingModelGraphReceipt($options, 'claude_codex', $traceModelResolution['model'], $providers);
            $this->assertProgrammingModelGraphAllowsRuntime($options);
            $decisionReceipt = $this->decide->receiptForTrace($this->optionsWithPromptContracts($options, $prompt), 'claude_codex', $traceModelResolution['model']);

            foreach ($providers as $provider) {
                $providerModelResolution = $this->models->resolveWithSource($provider, $options['model'] ?? null);
                $this->budgets->assertAllows($provider, $providerModelResolution['model'], $options);
            }

            $trace = AiTrace::query()->create([
                'trace_key' => 'trace_'.Str::orderedUuid()->toString(),
                'thread_id' => $lockedThread->id,
                'session_id' => $lockedSession->id,
                'source_type' => $options['source_type'] ?? 'app',
                'source_id' => $options['source_id'] ?? null,
                'status' => 'queued',
                'operator_input' => $input,
                'intent' => $prompt->intent,
                'agent_slug' => $prompt->agentSlug,
                'provider' => 'claude_codex',
                'model' => $traceModelResolution['model'],
                'skill_versions' => $prompt->skillVersions,
                'context_refs' => $prompt->contextRefs,
                'prompt_hash' => hash('sha256', $prompt->prompt),
                'metadata' => [
                    'mode' => $options['mode'] ?? 'async',
                    'client_id' => $options['client_id'] ?? null,
                    'privacy' => $privacy,
                    'execution_policy' => 'dual_review',
                    'thread' => $threadResolution->toArray(),
                    'session' => $this->sessionMetadata($lockedSession),
                    'auto_compaction_id' => $autoCompaction?->id,
                    'provider_handoff_id' => $providerHandoff?->id,
                    ...$this->modelRuntimeMetadata($traceModelResolution),
                    'council_providers' => $providers,
                    'council_status' => 'queued',
                    'council_progress' => [
                        'queued' => count($providers),
                        'processing' => 0,
                        'succeeded' => 0,
                        'failed' => 0,
                    ],
                    'task_request' => $prompt->taskRequest,
                    'context_pack' => $prompt->contextPack,
                    'open_brain_injection' => $prompt->openBrainInjection,
                    'execution_plan' => $prompt->executionPlan,
                    'skills_activated' => $prompt->activatedSkills,
                    'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
                    ...$this->programmingMetadata($options),
                    'decision_receipt' => $decisionReceipt,
                ],
            ]);

            foreach ($providers as $index => $provider) {
                $role = $provider === 'codex_cli' ? 'critical_reviewer' : 'primary_planner';
                $jobModelResolution = $this->models->resolveWithSource($provider, $options['model'] ?? null);
                $job = AiJob::query()->create([
                    'trace_id' => $trace->id,
                    'client_id' => $index === 0 ? ($options['client_id'] ?? null) : null,
                    'kind' => 'council',
                    'status' => 'queued',
                    'priority' => (int) ($options['priority'] ?? 50) + $index,
                    'agent_slug' => $prompt->agentSlug,
                    'provider' => $provider,
                    'model' => $jobModelResolution['model'],
                    'input_text' => $input,
                    'prompt' => $this->councilPrompt($prompt->prompt, $provider, $role),
                    'context_refs' => $prompt->contextRefs,
                    'payload' => array_merge($options['payload'] ?? [], [
                        'privacy' => $privacy,
                        'execution_policy' => 'dual_review',
                        'thread' => $threadResolution->toArray(),
                        'session' => $this->sessionMetadata($lockedSession),
                        'auto_compaction_id' => $autoCompaction?->id,
                        'provider_handoff_id' => $providerHandoff?->id,
                        ...$this->modelRuntimeMetadata($jobModelResolution),
                        'council_role' => $role,
                        'council_provider' => $provider,
                        'council_providers' => $providers,
                        'task_request' => $prompt->taskRequest,
                        'context_pack' => $prompt->contextPack,
                        'open_brain_injection' => $prompt->openBrainInjection,
                        'execution_plan' => $prompt->executionPlan,
                        'skills_activated' => $prompt->activatedSkills,
                        'decision_receipt' => $decisionReceipt,
                    ]),
                    'available_at' => $options['available_at'] ?? $now,
                    'max_attempts' => (int) ($options['max_attempts'] ?? config('atlas.ai.max_attempts', 1)),
                    'timeout_seconds' => (int) ($options['timeout_seconds'] ?? config('atlas.ai.timeout_seconds', 600)),
                    'metadata' => [
                        'intent' => $prompt->intent,
                        'skill_versions' => $prompt->skillVersions,
                        'privacy' => $privacy,
                        'execution_policy' => 'dual_review',
                        'thread' => $threadResolution->toArray(),
                        'session' => $this->sessionMetadata($lockedSession),
                        'auto_compaction_id' => $autoCompaction?->id,
                        'provider_handoff_id' => $providerHandoff?->id,
                        ...$this->modelRuntimeMetadata($jobModelResolution),
                        'council_role' => $role,
                        'task_request' => $prompt->taskRequest,
                        'context_pack' => $prompt->contextPack,
                        'open_brain_injection' => $prompt->openBrainInjection,
                        'execution_plan' => $prompt->executionPlan,
                        'skills_activated' => $prompt->activatedSkills,
                        'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
                        ...$this->programmingMetadata($options),
                        'decision_receipt' => $decisionReceipt,
                    ],
                ]);

                $this->recordTelemetry('job_enqueued', $trace, $job, [
                    'surface' => 'server',
                    'runtime' => 'laravel',
                    'metadata' => [
                        'priority' => $job->priority,
                        'available_at' => $job->available_at?->toJSON(),
                        'max_attempts' => $job->max_attempts,
                        'execution_policy' => 'dual_review',
                        'council_role' => $role,
                        ...$this->modelRuntimeMetadata($jobModelResolution),
                    ],
                ]);

                $this->audit->record('ai_trace_queued', [
                    'subject_type' => 'ai_trace',
                    'subject_id' => $trace->id,
                    'summary' => "Interacao de IA em conselho enfileirada para {$prompt->agentSlug}.",
                    'evidence' => [
                        'agent_slug' => $prompt->agentSlug,
                        'provider' => $provider,
                        'model' => $jobModelResolution['model'],
                        ...$this->modelRuntimeMetadata($jobModelResolution),
                        'source_type' => $trace->source_type,
                        'source_id' => $trace->source_id,
                        'input_text' => $input,
                        'prompt_hash' => $trace->prompt_hash,
                        'council_role' => $role,
                        'task_type' => data_get($prompt->taskRequest, 'task_type'),
                        'risk_level' => data_get($prompt->taskRequest, 'risk_level'),
                        'workflow' => data_get($prompt->executionPlan, 'workflow'),
                        'skills_activated' => $prompt->activatedSkills,
                    ],
                    'privacy' => $privacy,
                    'refs' => [
                        'trace_id' => $trace->id,
                        'thread_id' => $lockedThread->id,
                        'session_id' => $lockedSession->id,
                        'job_id' => $job->id,
                        'source_id' => $trace->source_id,
                    ],
                ]);
            }

            $this->conversation->recordUserMessage($lockedThread, $trace, $input, [
                'source' => 'ai_gateway',
                'thread_resolution' => $threadResolution->toArray(),
                'execution_policy' => 'dual_review',
                'session_id' => $lockedSession->id,
                ...$this->attachmentMetadata($options),
            ]);

            $this->states->updateForUserInput($lockedThread, $lockedSession, $input, $this->optionsWithPromptContracts($options, $prompt));
            $this->snapshots->record($trace, $lockedSession, $prompt, $autoCompaction, $providerHandoff);

            $this->recordAtlasDecision($trace, $options, 'claude_codex', $traceModelResolution['model'], $prompt, $traceModelResolution);
            $firstJob = $trace->jobs()->oldest('created_at')->first();
            $this->recordTelemetry('trace_created', $trace, $firstJob, [
                'surface' => 'server',
                'runtime' => 'laravel',
                'metadata' => [
                    'source_type' => $trace->source_type,
                    'execution_policy' => 'dual_review',
                    'council_providers' => $providers,
                    ...$this->modelRuntimeMetadata($traceModelResolution),
                    'task_type' => data_get($prompt->taskRequest, 'task_type'),
                    'workflow' => data_get($prompt->executionPlan, 'workflow'),
                ],
            ]);

            return $trace->load($this->traceRelations());
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function recordTelemetry(string $eventName, AiTrace $trace, ?AiJob $job = null, array $overrides = []): void
    {
        if (! Schema::hasTable('ai_telemetry_events')) {
            return;
        }

        try {
            $metadata = is_array($overrides['metadata'] ?? null) ? $overrides['metadata'] : [];
            $eventOverrides = $overrides;
            unset($eventOverrides['metadata']);
            app(AiTelemetryCollector::class)->record(array_merge([
                'event_key' => 'server:'.$eventName.':'.($job?->id ?? $trace->id),
                'trace_id' => $trace->id,
                'thread_id' => $trace->thread_id,
                'session_id' => $trace->session_id,
                'ai_job_id' => $job?->id,
                'client_id' => $job?->client_id ?? data_get($trace->metadata, 'client_id'),
                'surface' => 'server',
                'runtime' => 'laravel',
                'provider' => $job?->provider ?? $trace->provider,
                'model' => $job?->model ?? $trace->model,
                'agent_slug' => $job?->agent_slug ?? $trace->agent_slug,
                'event_name' => $eventName,
                'event_phase' => 'server',
                'metadata' => $metadata,
            ], $eventOverrides));
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array<int,string>
     */
    private function traceRelations(): array
    {
        $relations = ['thread', 'session', 'job', 'jobs'];

        if (Schema::hasTable('ai_quality_evaluations')) {
            $relations[] = 'qualityEvaluation';
        }

        if (Schema::hasTable('ai_quality_actions')) {
            $relations[] = 'qualityActions';
        }

        if (Schema::hasTable('ai_router_decisions')) {
            $relations[] = 'routerDecision';
        }

        if (Schema::hasTable('ai_decisions')) {
            $relations[] = 'atlasDecision';
        }

        return $relations;
    }

    private function lockThreadForTrace(AiThreadResolution $threadResolution): AiThread
    {
        /** @var AiThread $thread */
        $thread = AiThread::query()
            ->whereKey($threadResolution->thread->id)
            ->lockForUpdate()
            ->firstOrFail();

        return $thread;
    }

    private function lockSessionForTrace(AiSession $session): AiSession
    {
        /** @var AiSession $lockedSession */
        $lockedSession = AiSession::query()
            ->whereKey($session->id)
            ->lockForUpdate()
            ->firstOrFail();

        return $lockedSession;
    }

    /**
     * @param  array<string,mixed>  $modelResolution
     */
    private function recordAtlasDecision(AiTrace $trace, array $options, string $provider, ?string $model, AiPrompt $prompt, array $modelResolution): void
    {
        $routerDecision = $this->recordRouterDecision($trace, $options, $provider);
        $this->recordSpecialistFlowExecution($trace, $routerDecision, $options);

        if (! Schema::hasTable('ai_decisions')) {
            return;
        }

        $decisionOptions = $this->optionsWithPromptContracts($options, $prompt);
        $payload = is_array($decisionOptions['payload'] ?? null) ? $decisionOptions['payload'] : [];
        $manualProvider = $this->decide->manualOverrideProvider($decisionOptions);
        $fairMode = $this->isFairModeOptions($decisionOptions);
        $receipt = $this->decisionReceiptForTrace($decisionOptions, $provider, $model);
        $plan = $this->decisionPlanForTrace($decisionOptions, $provider, $model);
        $taskProfile = is_array($plan['task_profile'] ?? null) ? $plan['task_profile'] : [];
        $kernelContracts = data_get($receipt, 'kernel_contracts') ?: data_get($receipt, 'receipt_v2.metadata.kernel_contracts');
        $signals = [
            ...($fairMode ? $this->fairModeSignals($decisionOptions, $provider, $model) : $this->decide->signals($decisionOptions)),
            'decision_mode' => $this->decide->decisionMode($decisionOptions),
            'operator_requested_provider' => data_get($payload, 'operator_requested_provider') ?: 'auto',
            'requested_provider' => $manualProvider,
            'model_identity_source' => $modelResolution['source'] ?? 'unresolved',
            'model_tier' => $modelResolution['model_tier'] ?? config('atlas.ai.default_tier', 'daily'),
            'model_allow_auto' => (bool) ($modelResolution['allow_auto'] ?? true),
            'model_allow_manual' => (bool) ($modelResolution['allow_manual'] ?? true),
            'task_request' => $prompt->taskRequest,
            'execution_plan' => $prompt->executionPlan,
            'kernel_contracts' => is_array($kernelContracts) ? $kernelContracts : null,
            'selection_explanation' => is_array($receipt['selection_explanation'] ?? null) ? $receipt['selection_explanation'] : null,
        ];
        $confidenceScore = data_get($receipt, 'confidence_score');

        AiDecision::query()->updateOrCreate([
            'trace_id' => $trace->id,
        ], [
            'router_decision_id' => $routerDecision?->id,
            'policy_version' => (string) data_get($payload, 'atlas_decide.policy_version', 'atlas-decide-v1'),
            'decision_mode' => $receipt['decision_mode'] ?? 'atlas_decide',
            'route_mode' => (string) (data_get($payload, 'atlas_workflow_mode') ?: data_get($options, 'mode', 'direct')),
            'task_type' => $this->boundedString(data_get($taskProfile, 'task_type') ?: data_get($prompt->taskRequest, 'task_type'), 80),
            'risk_level' => $this->boundedString(data_get($taskProfile, 'risk_level') ?: data_get($prompt->taskRequest, 'risk_level'), 40),
            'context_strategy' => $plan['context_strategy'],
            'execution_strategy' => $plan['execution_strategy'],
            'selected_provider' => $provider,
            'selected_model' => $model,
            'fallback_provider' => $this->boundedString(
                data_get($payload, 'provider_strategy.fallback_provider') ?: data_get($payload, 'atlas_decide.fallback_provider'),
                32,
            ),
            'operator_requested_provider' => (string) ($receipt['operator_requested_provider'] ?? 'auto'),
            'requested_provider' => $manualProvider,
            'was_overridden' => $manualProvider !== null,
            'confidence_score' => is_numeric($confidenceScore) ? (int) $confidenceScore : $this->confidenceScore($options, $provider),
            'signals' => $signals,
            'candidates' => $this->decisionCandidates($options, $provider),
            'constraints' => $this->decisionConstraints($options, $provider),
            'metrics_snapshot' => $this->decisionMetricsSnapshot($options, $modelResolution),
            'task_profile' => $taskProfile,
            'execution_graph' => $plan['execution_graph'],
            'reason' => (string) ($receipt['reason'] ?? ($fairMode ? 'Fair Claude mode locks claude_cli and disables Atlas Decide.' : $this->decide->decisionReason($options, $provider))),
        ]);
    }

    private function recordRouterDecision(AiTrace $trace, array $options, string $provider): ?AiRouterDecision
    {
        if (! Schema::hasTable('ai_router_decisions')) {
            return null;
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $mode = (string) (data_get($payload, 'atlas_workflow_mode') ?: data_get($options, 'mode', 'direct'));
        $strategy = data_get($payload, 'provider_strategy');
        $signals = is_array($strategy) ? $strategy : [];
        $manualProvider = $this->decide->manualOverrideProvider($options);
        $fairMode = $this->isFairModeOptions($options);

        return AiRouterDecision::query()->updateOrCreate([
            'trace_id' => $trace->id,
        ], $this->routerDecisionPayload([
            'mode' => $mode,
            'selected_provider' => $provider,
            'fallback_provider' => is_string(data_get($strategy, 'fallback_provider')) ? data_get($strategy, 'fallback_provider') : null,
            'signals' => [
                'decision_mode' => $this->decide->decisionMode($options),
                'provider_online' => data_get($strategy, 'has_online_provider'),
                'critical' => data_get($strategy, 'critical', false),
                'requested_provider' => $manualProvider,
                'operator_requested_provider' => data_get($payload, 'operator_requested_provider') ?: 'auto',
                'execution_policy' => data_get($payload, 'execution_policy'),
                'atlas_decide' => $fairMode
                    ? $this->fairModeSignals($options, $provider, null)
                    : $this->decide->signals($options),
                'strategy' => $signals,
            ],
            'reason' => (string) (data_get($strategy, 'reason') ?: ($fairMode ? 'Fair Claude mode locks claude_cli and disables Atlas Decide.' : $this->decide->decisionReason($options, $provider))),
            'was_overridden' => $manualProvider !== null,
        ], $payload));
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function routerDecisionPayload(array $base, array $payload): array
    {
        $router = is_array(data_get($payload, 'atlas_ai_router')) ? (array) data_get($payload, 'atlas_ai_router') : [];
        if ($router === []) {
            return $base;
        }

        $columns = [
            'schema_version' => data_get($router, 'schema_version'),
            'surface_id' => data_get($router, 'handoff_payload.surface_id'),
            'flow_id' => data_get($router, 'flow_id'),
            'flow_origin' => data_get($router, 'flow_origin'),
            'command_intent' => data_get($router, 'command_intent'),
            'routing_reason' => data_get($router, 'routing_reason'),
            'routing_confidence' => data_get($router, 'routing_confidence'),
            'workspace_present' => (bool) data_get($router, 'handoff_payload.workspace_present', false),
            'handoff_payload' => is_array(data_get($router, 'handoff_payload')) ? data_get($router, 'handoff_payload') : [],
            'alternative_flow_ids' => is_array(data_get($router, 'alternative_flow_ids')) ? data_get($router, 'alternative_flow_ids') : [],
        ];

        foreach ($columns as $column => $value) {
            if (Schema::hasColumn('ai_router_decisions', $column)) {
                $base[$column] = $value;
            }
        }

        return $base;
    }

    private function recordSpecialistFlowExecution(AiTrace $trace, ?AiRouterDecision $routerDecision, array $options): ?AiSpecialistFlowExecution
    {
        if (! Schema::hasTable('ai_specialist_flow_executions')) {
            return null;
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $runtime = is_array(data_get($payload, 'specialist_flow_runtime')) ? (array) data_get($payload, 'specialist_flow_runtime') : [];
        $execution = is_array(data_get($payload, 'specialist_flow_execution')) ? (array) data_get($payload, 'specialist_flow_execution') : [];

        if ($runtime === [] || $execution === []) {
            return null;
        }

        $values = [
            'router_decision_id' => $routerDecision?->id,
            'runtime_schema_version' => data_get($runtime, 'schema_version'),
            'execution_schema_version' => data_get($execution, 'schema_version'),
            'flow_id' => data_get($execution, 'flow_id') ?: data_get($runtime, 'flow_id'),
            'handler_id' => data_get($execution, 'handler_id'),
            'handler_version' => data_get($execution, 'handler_version'),
            'status' => data_get($execution, 'status', 'ready_for_provider'),
            'runtime_receipt_id' => data_get($execution, 'runtime_receipt_id') ?: data_get($runtime, 'receipt.receipt_id'),
            'runtime_contract_hash' => data_get($execution, 'runtime_contract_hash') ?: data_get($runtime, 'receipt.contract_hash'),
            'delegation_status' => data_get($execution, 'delegation.status') ?: data_get($runtime, 'delegation.status'),
            'delegation_target_flow_id' => data_get($execution, 'delegation.target_flow_id') ?: data_get($runtime, 'delegation.target_flow_id'),
            'runtime_payload' => $runtime,
            'execution_payload' => $execution,
            'receipt' => is_array(data_get($runtime, 'receipt')) ? data_get($runtime, 'receipt') : [],
            'delegation' => is_array(data_get($execution, 'delegation')) ? data_get($execution, 'delegation') : (is_array(data_get($runtime, 'delegation')) ? data_get($runtime, 'delegation') : []),
            'audit_checks' => is_array(data_get($execution, 'audit_checks')) ? data_get($execution, 'audit_checks') : [],
            'response_shape' => is_array(data_get($execution, 'response_shape')) ? data_get($execution, 'response_shape') : [],
        ];

        $filtered = [];
        foreach ($values as $column => $value) {
            if (Schema::hasColumn('ai_specialist_flow_executions', $column)) {
                $filtered[$column] = $value;
            }
        }

        $record = AiSpecialistFlowExecution::query()->updateOrCreate([
            'trace_id' => $trace->id,
        ], $filtered);

        $this->recordCompoundingFlowSignal($trace, $record, $execution);

        return $record;
    }

    /**
     * @param  array<string,mixed>  $execution
     */
    private function recordCompoundingFlowSignal(AiTrace $trace, AiSpecialistFlowExecution $record, array $execution): void
    {
        if (! $this->compoundingTablesReady()) {
            return;
        }

        $flowId = (string) ($record->flow_id ?: data_get($execution, 'flow_id', 'atlas_conversation'));
        $runtimeReceiptId = (string) ($record->runtime_receipt_id ?: 'trace:'.$trace->id);
        $evidenceRefs = array_values(array_filter([
            'trace:'.$trace->id,
            $runtimeReceiptId !== '' ? 'runtime_receipt:'.$runtimeReceiptId : null,
            $record->runtime_contract_hash ? 'runtime_contract:'.$record->runtime_contract_hash : null,
        ]));

        try {
            app(AtlasCompoundingRuntimeService::class)->recordExecution([
                'run_id' => 'trace:'.$trace->id.':'.$flowId,
                'trace_id' => $trace->id,
                'flow_id' => $flowId,
                'outcome_status' => $record->status === 'delegated' ? 'delegated' : 'ready_for_provider',
                'source_type' => 'specialist_flow_execution',
                'flow_quality' => 80,
                'retrieval_quality' => 60,
                'execution_quality' => $record->status === 'delegated' ? 75 : 70,
                'evidence_quality' => 82,
                'learning_required' => true,
                'evidence_refs' => $evidenceRefs,
                'learning_signal' => [
                    'claim' => 'Specialist flow '.$flowId.' emitted a learning signal contract for future routing, retrieval and execution evaluation.',
                    'memory_type' => (string) data_get($execution, 'learning_signal_contract.candidate_memory_type', 'routing_memory'),
                    'scope' => 'atlas-server',
                    'confidence' => 78,
                    'flow_id' => $flowId,
                    'evidence_refs' => $evidenceRefs,
                ],
                'rag_feedback' => [
                    'retrieval_receipt_id' => $runtimeReceiptId,
                    'included_sources' => count((array) $record->audit_checks),
                    'used_sources' => count((array) $record->completion_checks),
                    'noise_sources' => 0,
                    'missed_required_sources' => [],
                    'context_sufficiency' => 70,
                    'post_execution_utility' => 70,
                    'source_utility' => [
                        'specialist_flow_execution' => 'learning_signal_contract',
                    ],
                ],
            ]);
        } catch (\Throwable) {
            // Compounding must never block the primary AI interaction path.
        }
    }

    private function compoundingTablesReady(): bool
    {
        return Schema::hasTable('ai_run_outcomes')
            && Schema::hasTable('ai_learning_candidates')
            && Schema::hasTable('ai_compounding_memories')
            && Schema::hasTable('ai_rag_feedback_events')
            && Schema::hasTable('ai_temporal_certifications');
    }

    private function boundedString(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }

    private function confidenceScore(array $options, string $provider): int
    {
        if ($this->decide->manualOverrideProvider($options)) {
            return 100;
        }

        $signals = $this->decide->signals($options);
        if ($provider === 'gemini_cli' && (
            (bool) ($signals['has_visual_input'] ?? false)
            || (bool) ($signals['has_file_input'] ?? false)
            || ((int) ($signals['attachment_count'] ?? 0)) > 0
            || in_array($signals['context_strategy_hint'] ?? null, ['long_context', 'multimodal', 'long_context_or_multimodal'], true)
        )) {
            return 88;
        }

        if ($provider === 'codex_cli' && in_array(data_get($signals, 'atlas_workflow_mode'), ['dev', 'debug', 'execute', 'quality_repair'], true)) {
            return 86;
        }

        return 74;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function decisionCandidates(array $options, string $selectedProvider): array
    {
        $manualProvider = $this->decide->manualOverrideProvider($options);
        if ($this->isFairModeOptions($options)) {
            return [[
                'provider' => FairClaudePolicy::PROVIDER_LOCK,
                'selected' => $selectedProvider === FairClaudePolicy::PROVIDER_LOCK,
                'manual_override' => true,
                'allow_auto' => true,
                'allow_manual' => true,
                'model' => $this->fairClaude->expectedResolvedModel((array) data_get($options, 'payload', []))
                    ?: $this->models->resolveWithSource(FairClaudePolicy::PROVIDER_LOCK)['model'],
                'fair_mode_locked' => true,
            ]];
        }

        return collect(self::INVOCATION_PROVIDERS)
            ->map(fn (string $provider): array => [
                'provider' => $provider,
                'selected' => $provider === $selectedProvider,
                'manual_override' => $manualProvider === $provider,
                'allow_auto' => (bool) ($this->runtimeSettings->providerConfig($provider)['allow_auto'] ?? true),
                'allow_manual' => (bool) ($this->runtimeSettings->providerConfig($provider)['allow_manual'] ?? true),
                'model' => $this->models->resolveWithSource($provider)['model'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionConstraints(array $options, string $selectedProvider): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return [
            'is_automatic_invocation' => $this->isAutomaticInvocation($options),
            'selected_provider_allow_auto' => (bool) ($this->runtimeSettings->providerConfig($selectedProvider)['allow_auto'] ?? true),
            'selected_provider_allow_manual' => (bool) ($this->runtimeSettings->providerConfig($selectedProvider)['allow_manual'] ?? true),
            'gemini_blocked_for_dev_like_task' => $this->geminiBlockedForInvocation($options),
            'execution_policy' => data_get($payload, 'execution_policy'),
            'budget_enabled' => (bool) data_get($this->runtimeSettings->effective(), 'budget.enabled', false),
        ];
    }

    /**
     * @param  array<string,mixed>  $modelResolution
     * @return array<string,mixed>
     */
    private function decisionMetricsSnapshot(array $options, array $modelResolution): array
    {
        return [
            'default_provider' => $this->runtimeSettings->defaultProvider(),
            'default_tier' => $this->runtimeSettings->defaultTier(),
            'model_identity_source' => $modelResolution['source'] ?? 'unresolved',
            'model_tier' => $modelResolution['model_tier'] ?? null,
            'visible_tokens_budget_enabled' => (bool) data_get($this->runtimeSettings->effective(), 'budget.enabled', false),
            'source_type' => $options['source_type'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array{enabled:bool,activation_status:string,blocked_reason:?string,plan:array<string,mixed>,scout_provider:string,scout_model:?string,dependency_timeout_seconds:int}
     */
    private function atlasScoutGate(array $options, string $selectedProvider, ?string $selectedModel): array
    {
        $timeoutSeconds = max(60, (int) config('atlas.ai.atlas_decide.scout_timeout_seconds', 600));
        $fairMode = $this->isFairModeOptions($options);
        $plan = $fairMode
            ? $this->fairModeDecisionPlan($options, $selectedProvider, $selectedModel)
            : $this->decide->decisionPlan($options, $selectedProvider, $selectedModel);
        $scoutProvider = $fairMode ? FairClaudePolicy::PROVIDER_LOCK : 'gemini_cli';
        $scoutModel = $fairMode ? $selectedModel : $this->models->resolve('gemini_cli');
        $base = [
            'enabled' => false,
            'activation_status' => $fairMode
                ? 'fair_mode_single_provider'
                : (string) data_get($plan, 'execution_graph.activation_status', 'active_single_provider'),
            'blocked_reason' => null,
            'plan' => $plan,
            'scout_provider' => $scoutProvider,
            'scout_model' => $scoutModel,
            'dependency_timeout_seconds' => $timeoutSeconds,
        ];

        if ($fairMode) {
            return [
                ...$base,
                'blocked_reason' => 'fair_mode_atlas_decide_disabled',
            ];
        }

        if (($plan['execution_strategy'] ?? null) !== 'scout_then_execute_planned') {
            return $base;
        }

        if (! (bool) ($this->runtimeSettings->providerConfig('gemini_cli')['allow_auto'] ?? true)) {
            return [
                ...$base,
                'activation_status' => 'blocked_by_runtime_settings',
                'blocked_reason' => 'gemini_auto_disabled',
            ];
        }

        try {
            $this->budgets->assertAllows('gemini_cli', $scoutModel, $options);
        } catch (RuntimeException $exception) {
            return [
                ...$base,
                'activation_status' => 'blocked_by_runtime_budget',
                'blocked_reason' => 'gemini_budget_blocked',
            ];
        }

        return [
            ...$base,
            'enabled' => true,
            'activation_status' => 'active_multi_stage',
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array{enabled:bool,activation_status:string,blocked_reason:?string}  $scoutGate
     * @return array<string,mixed>
     */
    private function optionsWithAtlasExecutionActivation(array $options, array $scoutGate): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $payload['atlas_decide'] = array_merge(
            is_array($payload['atlas_decide'] ?? null) ? $payload['atlas_decide'] : [],
            [
                'execution_graph_activation_status' => $scoutGate['activation_status'],
                'execution_graph_blocked_reason' => $scoutGate['blocked_reason'],
                'scout_enabled' => (bool) $scoutGate['enabled'],
                'disabled_by_fair_mode' => $this->isFairModeOptions(['payload' => $payload]),
            ],
        );
        $options['payload'] = $payload;

        return $options;
    }

    /**
     * @param  array{enabled:bool,activation_status:string,blocked_reason:?string,scout_provider:string,scout_model:?string,dependency_timeout_seconds:int}  $scoutGate
     * @return array<string,mixed>
     */
    private function atlasExecutionPayload(array $scoutGate): array
    {
        return [
            'strategy' => $scoutGate['enabled'] ? 'scout_then_execute' : 'single_stage',
            'activation_status' => $scoutGate['activation_status'],
            'blocked_reason' => $scoutGate['blocked_reason'],
            'atlas_decide_stage' => 'primary_executor',
            'dependency_state' => $scoutGate['enabled'] ? 'pending' : 'none',
            'dependency_provider' => $scoutGate['enabled'] ? $scoutGate['scout_provider'] : null,
            'dependency_model' => $scoutGate['enabled'] ? $scoutGate['scout_model'] : null,
            'dependency_timeout_seconds' => $scoutGate['dependency_timeout_seconds'],
        ];
    }

    private function atlasScoutDependencyDeadline(array $options): \DateTimeInterface
    {
        $base = $options['available_at'] ?? now();
        if (! $base instanceof \DateTimeInterface) {
            $base = now();
        }

        return now()
            ->setTimestamp($base->getTimestamp())
            ->addSeconds(max(60, (int) config('atlas.ai.atlas_decide.scout_timeout_seconds', 600)));
    }

    /**
     * @param  array<string,mixed>  $privacy
     * @param  array<string,mixed>  $sessionMetadata
     */
    private function enqueueAtlasScoutJob(
        AiTrace $trace,
        AiJob $executorJob,
        string $input,
        AiPrompt $prompt,
        array $options,
        array $privacy,
        AiThreadResolution $threadResolution,
        array $sessionMetadata,
        ?string $autoCompactionId,
        ?string $providerHandoffId,
        \DateTimeInterface $availableAt,
        int $priority,
        array $decisionReceipt,
    ): AiJob {
        $modelResolution = $this->models->resolveWithSource('gemini_cli');
        $execution = array_merge(
            is_array(data_get($executorJob->metadata, 'atlas_decide_execution'))
                ? data_get($executorJob->metadata, 'atlas_decide_execution')
                : [],
            [
                'strategy' => 'scout_then_execute',
                'activation_status' => 'active_multi_stage',
                'atlas_decide_stage' => 'context_scout',
                'dependency_state' => 'source',
                'dependent_job_id' => $executorJob->id,
                'dependency_timeout_seconds' => max(60, (int) config('atlas.ai.atlas_decide.scout_timeout_seconds', 600)),
            ],
        );

        $payload = [
            ...($options['payload'] ?? []),
            'atlas_decide_execution' => $execution,
            'privacy' => $privacy,
            'thread' => $threadResolution->toArray(),
            'session' => $sessionMetadata,
            'auto_compaction_id' => $autoCompactionId,
            'provider_handoff_id' => $providerHandoffId,
            ...$this->modelRuntimeMetadata($modelResolution),
            'task_request' => $prompt->taskRequest,
            'context_pack' => $prompt->contextPack,
            'execution_plan' => $prompt->executionPlan,
            'skills_activated' => $prompt->activatedSkills,
            'decision_receipt' => $decisionReceipt,
        ];

        $job = AiJob::query()->create([
            'trace_id' => $trace->id,
            'client_id' => null,
            'kind' => 'analysis',
            'status' => 'queued',
            'priority' => $priority,
            'agent_slug' => $prompt->agentSlug,
            'provider' => 'gemini_cli',
            'model' => $modelResolution['model'],
            'input_text' => $input,
            'prompt' => $this->atlasScoutPrompt($input, $prompt, $executorJob->provider, $executorJob->model),
            'context_refs' => $prompt->contextRefs,
            'payload' => $payload,
            'available_at' => $availableAt,
            'max_attempts' => 2,
            'timeout_seconds' => (int) ($options['timeout_seconds'] ?? config('atlas.ai.timeout_seconds', 600)),
            'metadata' => [
                ...$execution,
                'intent' => $prompt->intent,
                'skill_versions' => $prompt->skillVersions,
                'privacy' => $privacy,
                'thread' => $threadResolution->toArray(),
                'session' => $sessionMetadata,
                'auto_compaction_id' => $autoCompactionId,
                'provider_handoff_id' => $providerHandoffId,
                ...$this->modelRuntimeMetadata($modelResolution),
                'task_request' => $prompt->taskRequest,
                'context_pack' => $prompt->contextPack,
                'execution_plan' => $prompt->executionPlan,
                'skills_activated' => $prompt->activatedSkills,
                'decision_receipt' => $decisionReceipt,
            ],
        ]);

        $executorMetadata = array_merge($executorJob->metadata ?? [], [
            'dependency_job_id' => $job->id,
            'dependency_state' => 'pending',
            'dependency_deadline_at' => $this->atlasScoutDependencyDeadline($options)->format(DATE_ATOM),
        ]);
        $executorPayload = is_array($executorJob->payload) ? $executorJob->payload : [];
        $executorPayload['atlas_decide_execution'] = array_merge(
            is_array($executorPayload['atlas_decide_execution'] ?? null) ? $executorPayload['atlas_decide_execution'] : [],
            [
                'dependency_job_id' => $job->id,
                'dependency_state' => 'pending',
            ],
        );
        $executorJob->forceFill([
            'payload' => $executorPayload,
            'metadata' => $executorMetadata,
        ])->save();

        return $job;
    }

    private function atlasScoutPrompt(string $input, AiPrompt $prompt, ?string $executorProvider, ?string $executorModel): string
    {
        $executor = trim((string) $executorProvider.($executorModel ? " ({$executorModel})" : ''));

        return <<<PROMPT
Voce e o scout de contexto do Atlas Decide.

Objetivo: gastar contexto barato/longo antes do executor principal. Nao implemente, nao edite arquivos, nao rode comandos e nao tente finalizar a tarefa. Produza apenas um briefing compacto para o executor {$executor}.

Pedido do operador:
{$input}

Contrato de saida:
1. Context digest: fatos, arquivos, decisoes e dependencias que o executor precisa.
2. Source map: referencias citadas no contexto e por que importam.
3. Riscos e ambiguidades: pontos que podem quebrar a implementacao.
4. Plano minimo para o executor: ordem recomendada, verificacoes e criterios de pronto.
5. O que nao fazer: armadilhas ou caminhos caros/desnecessarios.

Prompt completo que o executor receberia:
{$prompt->prompt}
PROMPT;
    }

    /**
     * @param  array<string,mixed>  $resolution
     * @return array<string,mixed>
     */
    private function modelRuntimeMetadata(array $resolution): array
    {
        return [
            'model_identity_source' => $resolution['source'] ?? 'unresolved',
            'model_label' => $resolution['model_label'] ?? $resolution['model'] ?? null,
            'model_tier' => $resolution['model_tier'] ?? config('atlas.ai.default_tier', 'daily'),
            'model_allow_auto' => (bool) ($resolution['allow_auto'] ?? true),
            'model_allow_manual' => (bool) ($resolution['allow_manual'] ?? true),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function programmingMetadata(array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $messagePlan = is_array($payload['programming_message_plan'] ?? null)
            ? $payload['programming_message_plan']
            : [];
        $dispatch = is_array($payload['programming_dispatch'] ?? null)
            ? $payload['programming_dispatch']
            : [];

        return array_filter([
            'programming_profile' => data_get($payload, 'programming_profile'),
            'programming_session_plan' => data_get($payload, 'programming_session_plan'),
            'programming_message_plan' => data_get($payload, 'programming_message_plan'),
            'programming_dispatch' => data_get($payload, 'programming_dispatch'),
            'programming_repair' => data_get($payload, 'programming_repair'),
            'programming_profile_context' => data_get($payload, 'programming_profile_context')
                ?: data_get($messagePlan, 'policy_profile.profile_context')
                ?: data_get($dispatch, 'profile_context'),
            'programming_execution_policy' => data_get($payload, 'programming_execution_policy')
                ?: data_get($messagePlan, 'policy_profile.execution_policy')
                ?: data_get($dispatch, 'execution_policy'),
            'programming_policy_contracts' => $this->programmingPolicyContracts($options),
            'programming_policy_contract_receipt' => data_get($payload, 'programming_policy_contract_receipt'),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function programmingPolicyContracts(array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $messagePlan = is_array($payload['programming_message_plan'] ?? null)
            ? $payload['programming_message_plan']
            : [];
        $dispatch = is_array($payload['programming_dispatch'] ?? null)
            ? $payload['programming_dispatch']
            : [];
        $contracts = data_get($payload, 'programming_policy_contracts')
            ?: data_get($messagePlan, 'policy_contracts')
            ?: data_get($messagePlan, 'policy_profile.policy_contracts')
            ?: data_get($messagePlan, 'policy_profile.effective_policy.operational_contracts')
            ?: data_get($dispatch, 'policy_contracts');

        return is_array($contracts) ? $contracts : [];
    }

    private function optionsWithResolvedRuntime(array $options, string $threadId, string $sessionId, ?string $compactionId = null, ?string $handoffId = null): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $conversationContext = is_array(data_get($payload, 'conversation_context'))
            ? data_get($payload, 'conversation_context')
            : [];

        $conversationContext['thread_id'] = $threadId;
        $conversationContext['session_id'] = $sessionId;
        $payload['thread_id'] = $threadId;
        $payload['session_id'] = $sessionId;
        $payload['auto_compaction_id'] = $compactionId;
        $payload['provider_handoff_id'] = $handoffId;
        $payload['conversation_context'] = $conversationContext;
        $options['thread_id'] = $threadId;
        $options['session_id'] = $sessionId;
        $options['payload'] = $payload;

        return $options;
    }

    private function optionsWithPromptContracts(array $options, AiPrompt $prompt): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $payload['task_request'] = $prompt->taskRequest;
        $payload['context_pack'] = $prompt->contextPack;
        $payload['execution_plan'] = $prompt->executionPlan;
        $payload['skills_activated'] = $prompt->activatedSkills;
        $payload['open_brain_injection'] = $prompt->openBrainInjection;
        if ($receipt = $this->programmingPolicyContractReceipt($options, $prompt)) {
            $payload['programming_policy_contract_receipt'] = array_merge(
                (array) ($payload['programming_policy_contract_receipt'] ?? []),
                $receipt,
            );
        }
        $options['payload'] = $payload;

        return $options;
    }

    private function optionsWithPdfQuestionVisuals(string $input, array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];
        $files = is_array($attachments['files'] ?? null) ? $attachments['files'] : [];
        if ($files === []) {
            return $options;
        }

        $stats = [
            'status' => 'skipped',
            'files_checked' => 0,
            'files_enriched' => 0,
            'pages_added' => 0,
        ];

        $attachments['files'] = collect($files)
            ->map(function (mixed $file) use ($input, &$stats): mixed {
                if (! is_array($file)) {
                    return $file;
                }

                $mime = strtolower((string) ($file['mime_type'] ?? ''));
                $name = strtolower((string) ($file['original_name'] ?? ''));
                if (! str_contains($mime, 'pdf') && ! str_ends_with($name, '.pdf')) {
                    return $file;
                }

                $stats['files_checked']++;
                try {
                    $enhanced = $this->fileAttachments->enhancePdfForQuery($file, $input, 8);
                } catch (\Throwable) {
                    return $file;
                }

                $added = count((array) data_get($enhanced, 'pdf_query_visualization.added_pages', []));
                if ($added > 0) {
                    $stats['files_enriched']++;
                    $stats['pages_added'] += $added;
                }

                return $enhanced;
            })
            ->values()
            ->all();

        $payload['attachments'] = $attachments;
        if ($stats['files_checked'] > 0) {
            $stats['status'] = $stats['pages_added'] > 0 ? 'enriched' : 'cache_hit_or_no_match';
            $payload['pdf_question_visualization'] = $stats;
        }
        $options['payload'] = $payload;

        return $options;
    }

    private function optionsWithYouTubeKnowledge(string $input, array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        if (is_array(data_get($payload, 'youtube_ingestion'))) {
            return $options;
        }

        $urls = $this->youtubeKnowledge->extractUrls($input);
        if ($urls === []) {
            return $this->optionsWithRecentThreadYouTubeKnowledge($input, $options);
        }

        try {
            $ingestion = $this->youtubeKnowledge->ingestFromInput($input, [
                'defer_audio_fallback' => (bool) config('atlas.youtube.defer_audio_fallback', true),
            ]);
        } catch (\Throwable $e) {
            $ingestion = [
                'schema_version' => 1,
                'status' => 'failed',
                'reason' => Str::limit($e->getMessage(), 220, ''),
                'videos' => [],
            ];
        }

        $payload['youtube_ingestion'] = $ingestion;
        $options['payload'] = $payload;

        return $options;
    }

    private function optionsWithRecentThreadYouTubeKnowledge(string $input, array $options): array
    {
        if (! $this->isLikelyYouTubeContinuation($input)) {
            return $options;
        }

        $threadId = data_get($options, 'payload.thread_id', data_get($options, 'thread_id'));
        if (! is_string($threadId) || trim($threadId) === '') {
            return $options;
        }

        $recent = $this->recentThreadYouTubeVideo($threadId);
        $url = is_array($recent) ? (string) ($recent['url'] ?? '') : '';
        if ($url === '') {
            return $options;
        }

        try {
            $ingestion = $this->youtubeKnowledge->ingestFromInput($url, [
                'defer_audio_fallback' => (bool) config('atlas.youtube.defer_audio_fallback', true),
            ]);
        } catch (\Throwable $e) {
            $ingestion = [
                'schema_version' => 1,
                'status' => 'failed',
                'reason' => Str::limit($e->getMessage(), 220, ''),
                'videos' => [],
            ];
        }

        if (! is_array($ingestion['videos'] ?? null) || $ingestion['videos'] === []) {
            return $options;
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $payload['youtube_ingestion'] = $ingestion;
        $payload['youtube_continuation'] = [
            'source' => 'recent_thread_youtube',
            'matched_by' => 'short_follow_up_without_url',
            'previous_job_id' => $recent['job_id'] ?? null,
            'previous_trace_id' => $recent['trace_id'] ?? null,
            'url' => $url,
        ];
        $options['payload'] = $payload;

        return $options;
    }

    private function isLikelyYouTubeContinuation(string $input): bool
    {
        $normalized = Str::of($input)->lower()->ascii()->squish()->toString();
        if ($normalized === '' || mb_strlen($normalized) > 180) {
            return false;
        }

        foreach ([
            'conseguiu',
            'ficou pronto',
            'ja ficou pronto',
            'ja terminou',
            'terminou',
            'e agora',
            'agora vai',
            'deu certo',
            'pode analisar',
            'analise completa',
            'manda a analise',
            'me manda',
            'continua',
            'pronto',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return str_contains($normalized, 'video')
            && (str_contains($normalized, 'transcricao') || str_contains($normalized, 'youtube'));
    }

    /**
     * @return array{url:string,job_id:string|null,trace_id:string|null}|null
     */
    private function recentThreadYouTubeVideo(string $threadId): ?array
    {
        $jobs = AiJob::query()
            ->whereHas('trace', fn ($query) => $query->where('thread_id', $threadId))
            ->latest('created_at')
            ->limit(12)
            ->get(['id', 'trace_id', 'payload', 'created_at']);

        foreach ($jobs as $job) {
            if ($job->created_at && $job->created_at->lt(now()->subHours(6))) {
                continue;
            }

            $videos = data_get($job->payload, 'youtube_ingestion.videos', []);
            if (! is_array($videos)) {
                continue;
            }

            foreach ($videos as $video) {
                if (! is_array($video)) {
                    continue;
                }

                $url = (string) ($video['url'] ?? data_get($video, 'metadata.webpage_url', ''));
                if ($url === '') {
                    continue;
                }

                return [
                    'url' => $url,
                    'job_id' => $job->id,
                    'trace_id' => $job->trace_id,
                ];
            }
        }

        return null;
    }

    private function optionsWithProgrammingModelGraphReceipt(array $options, string $provider, ?string $model, array $runtimeProviders = []): array
    {
        $contracts = $this->programmingPolicyContracts($options);
        $graph = (array) data_get($contracts, 'model_graph', []);
        if ($graph === []) {
            return $options;
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $receipt = (array) ($payload['programming_policy_contract_receipt'] ?? []);
        $receipt['model_graph'] = $this->modelGraphContractReceipt($graph, $provider, $model, $runtimeProviders);
        $payload['programming_policy_contract_receipt'] = $receipt;
        $options['payload'] = $payload;

        return $options;
    }

    private function assertProgrammingModelGraphAllowsRuntime(array $options): void
    {
        $receipt = data_get($options, 'payload.programming_policy_contract_receipt.model_graph');
        if (! is_array($receipt)) {
            return;
        }

        $provider = (string) ($receipt['selected_provider'] ?? 'unknown');
        $model = (string) ($receipt['selected_model'] ?? 'unknown');
        $allowed = implode(', ', array_map(fn (mixed $item): string => (string) $item, (array) ($receipt['allowed_providers'] ?? [])));

        if (($receipt['status'] ?? null) === 'out_of_contract') {
            throw new RuntimeException("atlas_model_graph_policy_violation: provider/model {$provider}/{$model} fora do model_graph permitido".($allowed !== '' ? " ({$allowed})" : '').'.');
        }

        if (($receipt['status'] ?? null) === 'provider_matched_model_drift' && (bool) ($receipt['strict_model_match'] ?? false)) {
            throw new RuntimeException("atlas_model_graph_policy_violation: modelo {$model} diverge do model_graph fixo para {$provider}.");
        }
    }

    private function assertProgrammingSkillContractsAllowRuntime(array $options): void
    {
        $receipt = data_get($options, 'payload.programming_policy_contract_receipt.skills');
        if (! is_array($receipt) || ! (bool) ($receipt['required'] ?? false) || ($receipt['status'] ?? null) === 'satisfied') {
            return;
        }

        $missing = implode(', ', array_map(fn (mixed $item): string => (string) $item, (array) ($receipt['missing'] ?? [])));

        throw new RuntimeException('atlas_skill_contract_policy_violation: skill trace obrigatorio nao satisfeito'.($missing !== '' ? " ({$missing})" : '').'.');
    }

    private function assertProgrammingContextContractsAllowRuntime(array $options): void
    {
        $receipt = data_get($options, 'payload.programming_policy_contract_receipt.context');
        if (! is_array($receipt) || ! (bool) ($receipt['required'] ?? false) || ($receipt['status'] ?? null) === 'satisfied') {
            return;
        }

        throw new RuntimeException('atlas_context_contract_policy_violation: context pack obrigatorio nao foi projetado no prompt.');
    }

    private function assertProgrammingMemoryContractsAllowRuntime(array $options): void
    {
        $receipt = data_get($options, 'payload.programming_policy_contract_receipt.memory');
        if (! is_array($receipt) || ! (bool) ($receipt['required'] ?? false)) {
            return;
        }

        $status = (string) ($receipt['status'] ?? 'unknown');
        if (in_array($status, ['satisfied', 'degraded'], true)) {
            return;
        }

        throw new RuntimeException("atlas_memory_contract_policy_violation: Open Brain/memoria obrigatoria nao foi entregue ({$status}).");
    }

    private function assertProgrammingToolContractsAllowRuntime(array $options): void
    {
        $receipt = data_get($options, 'payload.programming_policy_contract_receipt.tools');
        if (! is_array($receipt) || ($receipt['status'] ?? null) === 'satisfied') {
            return;
        }

        $status = (string) ($receipt['status'] ?? 'unknown');
        $this->ledger->recordPolicyContractBlocked('programming.tools', $receipt, $options);

        throw new RuntimeException("atlas_tool_contract_policy_violation: permissao de ferramenta incompatível com contrato ({$status}).");
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function programmingPolicyContractReceipt(array $options, AiPrompt $prompt): array
    {
        $contracts = $this->programmingPolicyContracts($options);
        if ($contracts === []) {
            return [];
        }

        $context = (array) data_get($contracts, 'context', []);
        $memory = (array) data_get($contracts, 'memory', []);
        $skills = (array) data_get($contracts, 'skills', []);
        $tools = (array) data_get($contracts, 'tools', []);
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $contextPackPresent = $prompt->contextPack !== [];
        $openBrainPresent = $prompt->openBrainInjection !== [];
        $activatedSkills = collect($prompt->activatedSkills)
            ->map(fn (mixed $skill): ?string => is_array($skill) && is_string($skill['name'] ?? null) ? strtolower(trim((string) $skill['name'])) : null)
            ->filter()
            ->values()
            ->all();
        $requiredSkills = collect((array) data_get($skills, 'required_bundles', []))
            ->map(fn (mixed $skill): string => strtolower(trim((string) $skill)))
            ->filter()
            ->values()
            ->all();
        $missingSkills = array_values(array_diff($requiredSkills, $activatedSkills));
        $requiresSkillTrace = (bool) data_get($skills, 'require_skill_trace', false);
        $requiresContextPack = (bool) data_get($context, 'require_context_pack', false);
        $includeMemory = (bool) data_get($context, 'include_memory', data_get($memory, 'scope') !== null);
        $openBrainStatus = is_scalar(data_get($prompt->openBrainInjection, 'status'))
            ? (string) data_get($prompt->openBrainInjection, 'status')
            : null;
        $memoryStatus = match (true) {
            ! $includeMemory => 'not_required',
            ! $openBrainPresent => 'missing',
            in_array($openBrainStatus, ['injected'], true) => 'satisfied',
            in_array($openBrainStatus, ['degraded'], true) => 'degraded',
            in_array($openBrainStatus, ['failed_closed', 'failed_open'], true) => 'failed',
            in_array($openBrainStatus, ['skipped'], true) => 'missing',
            default => 'pending',
        };

        return [
            'schema_version' => 1,
            'source' => 'ai_gateway_prompt_contract_projection',
            'context' => [
                'required' => $requiresContextPack,
                'status' => ! $requiresContextPack || $contextPackPresent ? 'satisfied' : 'missing',
                'context_pack_present' => $contextPackPresent,
                'context_pack_id' => data_get($prompt->contextPack, 'context_pack_id') ?: data_get($prompt->contextPack, 'id'),
                'context_pack_hash' => data_get($prompt->contextPack, 'hash'),
                'depth' => data_get($context, 'depth'),
            ],
            'memory' => [
                'required' => $includeMemory,
                'status' => $memoryStatus,
                'open_brain_present' => $openBrainPresent,
                'open_brain_status' => $openBrainStatus,
                'open_brain_reason' => data_get($prompt->openBrainInjection, 'reason'),
                'scope' => data_get($memory, 'scope'),
                'privacy_gate' => data_get($memory, 'privacy_gate'),
                'recall' => data_get($memory, 'recall', []),
            ],
            'skills' => [
                'required' => $requiresSkillTrace,
                'status' => ! $requiresSkillTrace || $missingSkills === [] ? 'satisfied' : 'partial',
                'required_bundles' => $requiredSkills,
                'activated' => $activatedSkills,
                'missing' => $missingSkills,
                'mode' => data_get($skills, 'mode'),
            ],
            'tools' => $this->toolContractReceipt($tools, $payload),
        ];
    }

    /**
     * @param  array<string,mixed>  $tools
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function toolContractReceipt(array $tools, array $payload): array
    {
        $requestedMode = $this->normalizedToolPermissionMode(
            data_get($payload, 'tool_permissions.mode') ?: data_get($payload, 'permission_mode'),
        );
        $confirmed = (bool) data_get($payload, 'tool_permissions.confirmed', false);
        $requestedExecutionTier = $this->normalizedExecutionTier(
            data_get($payload, 'tool_permissions.max_execution_tier') ?: data_get($payload, 'max_execution_tier'),
        );
        $contractMaxExecutionTier = $this->normalizedExecutionTier(data_get($tools, 'max_execution_tier')) ?? 'T1';
        $hotPath = filter_var(data_get($payload, 'tool_permissions.hot_path', true), FILTER_VALIDATE_BOOL);

        if ($tools === []) {
            return [
                'required' => false,
                'status' => 'satisfied',
                'mode' => null,
                'workspace_write_allowed' => null,
                'destructive_requires_approval' => null,
                'requested_permission_mode' => $requestedMode,
                'confirmed' => $confirmed,
                'requested_execution_tier' => $requestedExecutionTier,
                'max_execution_tier' => $contractMaxExecutionTier,
                'hot_path' => $hotPath,
                'require_evidence_packet' => false,
            ];
        }

        $contractMode = is_scalar($tools['mode'] ?? null)
            ? strtolower(trim((string) $tools['mode']))
            : null;
        $workspaceWriteAllowed = (bool) data_get($tools, 'workspace_write', in_array($contractMode, ['workspace_write', 'harness'], true));
        $destructiveRequiresApproval = (bool) data_get($tools, 'destructive_requires_approval', true);
        $status = match (true) {
            $hotPath && $requestedExecutionTier !== null && $this->executionTierWeight($requestedExecutionTier) > $this->executionTierWeight('T1') => 'execution_tier_hot_path_blocked',
            $requestedExecutionTier !== null && $this->executionTierWeight($requestedExecutionTier) > $this->executionTierWeight($contractMaxExecutionTier) => 'execution_tier_above_contract',
            $requestedMode === null => 'satisfied',
            ! $workspaceWriteAllowed && in_array($requestedMode, ['write', 'danger'], true) => 'workspace_write_blocked',
            $destructiveRequiresApproval && $requestedMode === 'danger' && ! $confirmed => 'destructive_approval_missing',
            default => 'satisfied',
        };

        return [
            'required' => true,
            'status' => $status,
            'mode' => $contractMode,
            'workspace_write_allowed' => $workspaceWriteAllowed,
            'destructive_requires_approval' => $destructiveRequiresApproval,
            'requested_permission_mode' => $requestedMode,
            'confirmed' => $confirmed,
            'requested_execution_tier' => $requestedExecutionTier,
            'max_execution_tier' => $contractMaxExecutionTier,
            'hot_path' => $hotPath,
            'require_evidence_packet' => (bool) data_get($tools, 'require_evidence_packet', false),
        ];
    }

    private function normalizedToolPermissionMode(mixed $mode): ?string
    {
        if (! is_scalar($mode) || trim((string) $mode) === '') {
            return null;
        }

        $mode = strtolower(trim((string) $mode));

        return match ($mode) {
            'readonly', 'read-only', 'ro' => 'read',
            'workspace-write', 'edit', 'write-scoped' => 'write',
            'danger-full-access', 'full', 'all' => 'danger',
            default => in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'read',
        };
    }

    private function normalizedExecutionTier(mixed $tier): ?string
    {
        if (! is_scalar($tier) || trim((string) $tier) === '') {
            return null;
        }

        $tier = strtoupper(trim((string) $tier));

        return in_array($tier, ['T0', 'T1', 'T2', 'T3'], true) ? $tier : 'T1';
    }

    private function executionTierWeight(string $tier): int
    {
        return match ($this->normalizedExecutionTier($tier) ?? 'T1') {
            'T0' => 0,
            'T1' => 1,
            'T2' => 2,
            'T3' => 3,
            default => 1,
        };
    }

    /**
     * @param  array<string,mixed>  $graph
     * @return array<string,mixed>
     */
    private function modelGraphContractReceipt(array $graph, string $provider, ?string $model, array $runtimeProviders = []): array
    {
        $nodes = collect((array) data_get($graph, 'nodes', []))
            ->filter(fn (mixed $node): bool => is_array($node) && is_string($node['provider'] ?? null))
            ->values();
        $matched = $nodes->first(function (mixed $node) use ($provider, $model): bool {
            if (! is_array($node) || ($node['provider'] ?? null) !== $provider) {
                return false;
            }

            $nodeModel = is_string($node['model'] ?? null) ? trim((string) $node['model']) : '';

            return $nodeModel === '' || $model === null || $nodeModel === $model;
        });
        $providerNode = $nodes->first(fn (mixed $node): bool => is_array($node) && ($node['provider'] ?? null) === $provider);
        $fallbackProviders = $nodes
            ->flatMap(fn (mixed $node): array => is_array($node) ? (array) ($node['fallback_order'] ?? []) : [])
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->unique()
            ->values()
            ->all();
        $providerAllowedAsFallback = in_array($provider, $fallbackProviders, true);
        $runtimeProviders = collect($runtimeProviders)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $allowedProviders = $nodes->pluck('provider')->filter()->unique()->values()->all();
        $runtimeProvidersAllowed = $runtimeProviders !== []
            && count(array_diff($runtimeProviders, array_values(array_unique([...$allowedProviders, ...$fallbackProviders])))) === 0;
        $status = match (true) {
            is_array($matched) => 'satisfied',
            $provider === 'claude_codex' && $runtimeProvidersAllowed => 'satisfied',
            is_array($providerNode) => 'provider_matched_model_drift',
            $providerAllowedAsFallback => 'fallback_provider',
            default => 'out_of_contract',
        };

        return [
            'schema_version' => 1,
            'source' => 'ai_gateway_model_graph_projection',
            'status' => $status,
            'graph' => data_get($graph, 'graph'),
            'preset' => data_get($graph, 'preset'),
            'strict_model_match' => (bool) data_get($graph, 'strict_model_match', false),
            'selected_provider' => $provider,
            'selected_model' => $model,
            'runtime_providers' => $runtimeProviders,
            'matched_node_id' => is_array($matched) ? ($matched['id'] ?? null) : ($runtimeProvidersAllowed ? 'council_runtime' : null),
            'matched_node_role' => is_array($matched) ? ($matched['role'] ?? null) : ($runtimeProvidersAllowed ? 'council' : null),
            'allowed_providers' => $allowedProviders,
            'fallback_providers' => $fallbackProviders,
        ];
    }

    /**
     * @return array{attachments?:array<int,array<string,mixed>>}
     */
    private function attachmentMetadata(array $options): array
    {
        $attachments = AiAttachmentPayload::publicAttachmentsFromPayload($options['payload'] ?? []);

        return $attachments === [] ? [] : ['attachments' => $attachments];
    }

    private function sessionMetadata($session): array
    {
        return [
            'session_id' => $session->id,
            'status' => $session->status,
            'purpose' => $session->purpose,
            'provider_primary' => $session->provider_primary,
            'provider_last' => $session->provider_last,
        ];
    }

    private function maybeCompactSessionResume(AiThread $thread, AiSession $session): ?AiCompaction
    {
        $metadata = $session->metadata ?? [];
        if (data_get($metadata, 'creation_reason') !== 'session_idle_resume') {
            return null;
        }

        if (data_get($metadata, 'resume_compaction_id')) {
            return null;
        }

        if (AiMessage::query()->where('thread_id', $thread->id)->count() === 0) {
            return null;
        }

        $compaction = $this->compactions->compact($thread, $session, 'session_resume', [
            'trigger' => 'session_idle_resume',
            'resumed_from_session_id' => data_get($metadata, 'resumed_from_session_id'),
            'idle_minutes' => data_get($metadata, 'idle_minutes'),
        ]);

        $session->update([
            'metadata' => array_merge($session->metadata ?? [], [
                'resume_compaction_id' => $compaction->id,
                'resume_compaction_at' => now()->toJSON(),
            ]),
        ]);

        return $compaction;
    }

    private function shouldRunCouncil(array $options): bool
    {
        if ($this->isFairModeOptions($options)) {
            return false;
        }

        $manualProvider = $this->decide->manualOverrideProvider($options);
        $requested = data_get($options, 'payload.execution_policy') === 'dual_review'
            || $manualProvider === 'claude_codex'
            || ($options['provider'] ?? null) === 'claude_codex';

        return $requested && $this->providerAllowedForInvocation('claude_codex', $options) === 'claude_codex';
    }

    private function providerFromOptions(array $options): string
    {
        if ($this->isFairModeOptions($options)) {
            return FairClaudePolicy::PROVIDER_LOCK;
        }

        $manualProvider = $this->decide->manualOverrideProvider($options);
        if ($manualProvider === 'claude_codex'
            || data_get($options, 'payload.execution_policy') === 'dual_review'
        ) {
            return $this->providerAllowedForInvocation('claude_codex', $options);
        }

        if (in_array($manualProvider, self::INVOCATION_PROVIDERS, true)) {
            return $this->providerAllowedForInvocation((string) $manualProvider, $options, explicitProvider: true);
        }

        $candidate = $this->decide->operationalDecision($options)->selectedProvider();

        return $this->providerAllowedForInvocation($candidate, $options, explicitProvider: false);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $decisionPayload
     * @return array<string,mixed>
     */
    private function providerGovernanceContract(array $payload, string $provider, array $decisionPayload, ?string $candidateProvider, ?string $fallbackReason): array
    {
        $decisionMode = (string) (data_get($payload, 'decision_mode') ?: data_get($decisionPayload, 'decision_mode') ?: 'atlas_decide');
        $manualOverride = $decisionMode !== 'atlas_decide';

        return [
            'schema_version' => 'atlas.provider_governance.v1',
            'surface' => data_get($payload, 'app_surface') ?: data_get($payload, 'surface') ?: 'unknown',
            'workflow_mode' => data_get($payload, 'atlas_workflow_mode') ?: data_get($payload, 'workflow_mode'),
            'decision_mode' => $decisionMode,
            'decision_authority' => $manualOverride ? 'operator_override' : 'atlas_decide',
            'model_selection_authority' => data_get($payload, 'model_selection_contract.authority') ?: 'atlas_decide',
            'operator_requested_provider' => data_get($payload, 'operator_requested_provider') ?: ($manualOverride ? data_get($payload, 'requested_provider') : 'auto'),
            'requested_provider' => data_get($payload, 'requested_provider'),
            'candidate_provider' => $candidateProvider,
            'execution_provider' => $provider,
            'selected_provider' => $provider,
            'fallback_provider' => $fallbackReason ? $provider : null,
            'fallback_reason' => $fallbackReason,
            'manual_override' => $manualOverride,
            'fair_mode' => $this->isFairModeOptions(['payload' => $payload]),
            'separation_contract' => [
                'atlas_decide_is_decision_layer' => ! $manualOverride,
                'provider_is_executor_only' => true,
                'provider_may_not_be_treated_as_atlas_identity' => true,
                'manual_override_must_remain_visible' => $manualOverride,
            ],
        ];
    }

    private function providerAllowedForInvocation(string $provider, array $options, bool $explicitProvider = false): string
    {
        $hasImageAttachments = $this->hasImageAttachments($options);

        if ($hasImageAttachments && $explicitProvider && ! $this->providerSupportsImageAttachments($provider)) {
            return $this->imageAttachmentFallbackProvider($options);
        }

        if ($hasImageAttachments && ! $explicitProvider && ! $this->providerSupportsImageAttachments($provider)) {
            return $this->imageAttachmentFallbackProvider($options);
        }

        if (! $explicitProvider && $provider === 'gemini_cli' && $this->geminiBlockedForInvocation($options)) {
            return $hasImageAttachments
                ? $this->imageAttachmentFallbackProvider($options)
                : $this->geminiFallbackProvider();
        }

        if (! $this->isAutomaticInvocation($options)) {
            if ((bool) ($this->runtimeSettings->providerConfig($provider)['allow_manual'] ?? true)) {
                return $provider;
            }

            throw new RuntimeException("Provider {$provider} esta bloqueado para uso manual nas Configuracoes do Atlas.");
        }

        if ($provider === 'claude_codex') {
            return $this->runtimeSettings->councilAllowAuto()
                ? $provider
                : $this->automaticFallbackProvider($options);
        }

        if ((bool) ($this->runtimeSettings->providerConfig($provider)['allow_auto'] ?? true)) {
            return $provider;
        }

        $fallback = $this->automaticFallbackProvider($options);

        return $hasImageAttachments && ! $this->providerSupportsImageAttachments($fallback)
            ? $this->imageAttachmentFallbackProvider($options)
            : $fallback;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function enforceFairModeProvider(array $payload, string $provider): array
    {
        if (! $this->fairClaude->isFairPayload($payload)) {
            return $payload;
        }

        if ($provider !== FairClaudePolicy::PROVIDER_LOCK) {
            $violation = $this->fairClaude->violation(
                message: 'Fair Claude mode requires provider claude_cli.',
                details: ['provider' => $provider],
            );

            throw new RuntimeException(FairClaudePolicy::ERROR_CODE.': '.$violation['message']);
        }

        return array_merge($payload, [
            'fair_mode' => $this->fairClaude->metadataFromPayload($payload),
            'decision_mode' => 'manual_override',
            'operator_requested_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'requested_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'execution_policy' => null,
            'council_providers' => null,
            'council_disabled_by_fair_mode' => true,
            'atlas_decide' => array_merge(
                is_array($payload['atlas_decide'] ?? null) ? $payload['atlas_decide'] : [],
                [
                    'disabled_by_fair_mode' => true,
                    'decision_mode' => 'manual_override',
                    'operator_requested_provider' => FairClaudePolicy::PROVIDER_LOCK,
                    'requested_provider' => FairClaudePolicy::PROVIDER_LOCK,
                    'candidate_provider' => FairClaudePolicy::PROVIDER_LOCK,
                    'selected_provider' => FairClaudePolicy::PROVIDER_LOCK,
                    'fallback_provider' => null,
                    'fallback_reason' => null,
                ],
            ),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function fairModeDecisionPayload(array $payload, string $provider): array
    {
        return [
            'decision_id' => null,
            'policy_profile_id' => null,
            'policy_version' => 'fair-claude-v1',
            'decision_policy_version' => 'fair-claude-v1',
            'candidate_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'selected_provider' => $provider,
            'fallback_provider' => null,
            'fallback_reason' => null,
            'planned_graph' => $this->fairModeDecisionPlan(['payload' => $payload], $provider, null),
            'runtime_graph' => [
                'activation_status' => 'fair_mode_single_provider',
                'atlas_decide_disabled' => true,
                'fallback_disabled' => true,
                'provider_lock' => FairClaudePolicy::PROVIDER_LOCK,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function decisionReceiptForTrace(array $options, string $provider, ?string $model): array
    {
        if (! $this->isFairModeOptions($options)) {
            return $this->decide->receiptForTrace($options, $provider, $model);
        }

        return [
            'decision_mode' => 'fair_mode_disabled',
            'operator_requested_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'requested_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'selected_provider' => FairClaudePolicy::PROVIDER_LOCK,
            'selected_model' => $model,
            'fallback_provider' => null,
            'fallback_reason' => null,
            'atlas_decide_disabled_by_fair_mode' => true,
            'reason' => 'Fair Claude mode locks claude_cli and disables Atlas Decide.',
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function decisionPlanForTrace(array $options, string $provider, ?string $model): array
    {
        return $this->isFairModeOptions($options)
            ? $this->fairModeDecisionPlan($options, $provider, $model)
            : $this->decide->decisionPlan($options, $provider, $model);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function fairModeDecisionPlan(array $options, string $provider, ?string $model): array
    {
        return [
            'decision_mode' => 'fair_mode_disabled',
            'task_profile' => [
                'task_type' => data_get($options, 'payload.task_type'),
                'risk_level' => data_get($options, 'payload.risk_level'),
            ],
            'context_strategy' => 'fair_claude_locked_context',
            'execution_strategy' => 'single_provider_locked',
            'execution_graph' => [
                'activation_status' => 'fair_mode_single_provider',
                'selected_provider' => $provider,
                'selected_model' => $model,
                'atlas_decide_disabled' => true,
                'council_disabled' => true,
                'fallback_disabled' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function fairModeSignals(array $options, string $provider, ?string $model): array
    {
        return [
            'fair_mode' => true,
            'provider_lock' => FairClaudePolicy::PROVIDER_LOCK,
            'model_lock' => FairClaudePolicy::MODEL_LOCK,
            'selected_provider' => $provider,
            'selected_model' => $model,
            'atlas_decide_disabled_by_fair_mode' => true,
            'fallback_disabled' => true,
            'single_provider' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function enforceFairModeModel(array $payload, string $provider, ?string $model): void
    {
        if (! $this->fairClaude->isFairPayload($payload)) {
            return;
        }

        $violation = $this->fairClaude->validateInvocation($provider, $model, $payload);
        if (! (bool) ($violation['ok'] ?? false)) {
            throw new RuntimeException(FairClaudePolicy::ERROR_CODE.': '.(string) ($violation['message'] ?? 'Fair Claude mode violation.'));
        }
    }

    private function providerFallbackReason(string $candidateProvider, string $selectedProvider, array $options): string
    {
        if ($candidateProvider === $selectedProvider) {
            return 'none';
        }

        if ($candidateProvider === 'gemini_cli' && $this->geminiBlockedForInvocation($options)) {
            return 'gemini_blocked_for_dev_like_task';
        }

        if ($this->hasImageAttachments($options)
            && ! $this->providerSupportsImageAttachments($candidateProvider)
            && $this->providerSupportsImageAttachments($selectedProvider)
        ) {
            return 'image_attachment_provider_fallback';
        }

        if ($this->isAutomaticInvocation($options)
            && ! (bool) ($this->runtimeSettings->providerConfig($candidateProvider)['allow_auto'] ?? true)
        ) {
            return 'candidate_auto_disabled';
        }

        if (! $this->isAutomaticInvocation($options)
            && ! (bool) ($this->runtimeSettings->providerConfig($candidateProvider)['allow_manual'] ?? true)
        ) {
            return 'candidate_manual_disabled';
        }

        return 'provider_gate_fallback';
    }

    private function automaticFallbackProvider(array $options = []): string
    {
        $default = $this->runtimeSettings->defaultProvider();
        if ($default !== 'claude_codex'
            && ! ($default === 'gemini_cli' && $this->geminiBlockedForInvocation($options))
            && (bool) ($this->runtimeSettings->providerConfig($default)['allow_auto'] ?? true)
        ) {
            return $default;
        }

        return 'claude_cli';
    }

    private function geminiFallbackProvider(): string
    {
        return 'claude_cli';
    }

    private function hasImageAttachments(array $options): bool
    {
        $images = data_get($options, 'payload.attachments.images', []);
        if (is_array($images) && count($images) > 0) {
            return true;
        }

        $count = data_get($options, 'payload.visual_input.image_count', 0);

        return is_numeric($count) && (int) $count > 0;
    }

    private function providerSupportsImageAttachments(string $provider): bool
    {
        // Claude CLI only receives attachment paths/instructions in this runtime;
        // it does not get pixels as native visual input. Treating it as
        // image-capable made mobile uploads look attached in Atlas while Claude
        // could only see metadata. Keep image jobs on providers that pass actual
        // visual input to the model.
        return in_array($provider, ['codex_cli', 'gemini_cli'], true);
    }

    private function imageAttachmentFallbackProvider(array $options): string
    {
        foreach (['codex_cli', 'gemini_cli'] as $provider) {
            if ($provider === 'gemini_cli' && $this->geminiBlockedForInvocation($options)) {
                continue;
            }

            if ((bool) ($this->runtimeSettings->providerConfig($provider)['allow_auto'] ?? true)) {
                return $provider;
            }
        }

        foreach (['codex_cli', 'gemini_cli'] as $provider) {
            if ($provider === 'gemini_cli' && $this->geminiBlockedForInvocation($options)) {
                continue;
            }

            if ((bool) ($this->runtimeSettings->providerConfig($provider)['allow_manual'] ?? true)) {
                return $provider;
            }
        }

        throw new RuntimeException('Nenhum provider com suporte a imagem esta habilitado nas Configuracoes do Atlas.');
    }

    private function isAutomaticInvocation(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        if ($this->fairClaude->isFairPayload($payload)) {
            return false;
        }
        $sourceType = $options['source_type'] ?? null;

        return data_get($payload, 'decision_mode') === 'atlas_decide'
            || (bool) data_get($payload, 'automatic', false)
            || in_array($sourceType, ['capture', 'scheduled', 'system'], true);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function isFairModeOptions(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return $this->fairClaude->isFairPayload($payload);
    }

    private function geminiBlockedForInvocation(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $workflowMode = strtolower(trim((string) data_get($payload, 'atlas_workflow_mode', '')));
        $taskType = strtolower(trim((string) data_get($payload, 'task_type', '')));
        $agent = strtolower(trim((string) ($options['agent_slug'] ?? data_get($payload, 'requested_agent', ''))));

        if (in_array($workflowMode, ['dev', 'debug', 'execute', 'quality_repair'], true)) {
            return true;
        }

        if (in_array($taskType, ['dev', 'debug', 'code', 'coding', 'programming', 'quality_repair'], true)) {
            return true;
        }

        return in_array($agent, ['desenvolvedor', 'developer', 'debugger'], true);
    }

    private function existingTraceForClient(mixed $clientId): ?AiTrace
    {
        if (! is_string($clientId) || $clientId === '') {
            return null;
        }

        $job = AiJob::query()
            ->with('trace')
            ->where('client_id', $clientId)
            ->first();

        return $job?->trace;
    }

    private function privacyFromOptions(array $options): array
    {
        if (($options['source_type'] ?? null) === 'capture' && ! empty($options['source_id'])) {
            $capture = Capture::query()->find($options['source_id']);
            if ($capture) {
                $privacy = data_get($capture->metadata, 'privacy');

                return is_array($privacy)
                    ? $privacy
                    : [
                        'domain' => $capture->domain,
                        'sensitivity' => data_get($capture->metadata, 'sensitivity', 'normal'),
                    ];
            }
        }

        $privacy = data_get($options, 'payload.privacy');

        return is_array($privacy) ? $privacy : ['sensitivity' => 'normal'];
    }

    private function guardPrivacyAllowsAi(array $options, array $privacy, string $input): void
    {
        if (($privacy['external_ai_allowed'] ?? true) !== false && $this->privacy->externalAiAllowedForMetadata(['privacy' => $privacy])) {
            return;
        }

        $this->audit->record('ai_interaction_blocked_by_privacy', [
            'subject_type' => $options['source_type'] ?? 'ai_interaction',
            'subject_id' => $options['source_id'] ?? null,
            'severity' => 'warning',
            'summary' => 'Interacao de IA bloqueada pela politica de privacidade.',
            'evidence' => [
                'source_type' => $options['source_type'] ?? null,
                'source_id' => $options['source_id'] ?? null,
                'agent_slug' => $options['agent_slug'] ?? config('atlas.ai.default_agent', 'orquestrador'),
                'input_text' => $input,
            ],
            'privacy' => $privacy,
            'refs' => [
                'source_id' => $options['source_id'] ?? null,
            ],
        ]);

        throw new RuntimeException('AI interaction blocked by Atlas privacy policy.');
    }

    /**
     * @return array<int, string>
     */
    private function councilProviders(array $options): array
    {
        $requested = data_get($options, 'payload.council_providers');
        if (! is_array($requested)) {
            return self::COUNCIL_PROVIDERS;
        }

        $providers = array_values(array_intersect($requested, self::COUNCIL_PROVIDERS));

        return count($providers) >= 2 ? $providers : self::COUNCIL_PROVIDERS;
    }

    private function councilPrompt(string $basePrompt, string $provider, string $role): string
    {
        $roleInstruction = $role === 'critical_reviewer'
            ? 'Seu papel nesta rodada e revisar criticamente: encontre falhas, riscos, lacunas, premissas fracas, inconsistencias e pontos que o outro avaliador provavelmente deixaria passar.'
            : 'Seu papel nesta rodada e propor a leitura principal: estruture o caminho recomendado, explicite tradeoffs, ordem de execucao, criterios de verificacao e decisoes praticas.';

        $providerName = $provider === 'codex_cli' ? 'Codex' : 'Claude';

        return <<<PROMPT
{$basePrompt}

# Conselho Atlas: {$providerName}

Voce esta participando de uma rodada dupla Claude + Codex.
{$roleInstruction}

Regras desta rodada:
- Nao execute alteracoes externas.
- Nao trate sua resposta como decisao final isolada.
- Escreva para que o Atlas consiga comparar sua leitura com a do outro provedor.
- Seja especifico sobre riscos, verificacao e proximo passo.
- Se a tarefa pedir implementacao, descreva quem deveria executar e quais revisoes devem acontecer depois.

Formato recomendado:
1. Diagnostico
2. Recomendacao
3. Riscos e lacunas
4. Criterios de verificacao
5. Proximo passo
PROMPT;
    }
}
