<?php

namespace App\Services\Ai;

use App\Services\Ai\Provider\ProviderCatalog;
use App\Models\AiCompaction;
use App\Models\AiJob;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Models\Capture;
use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use App\Services\Ai\Cli\AtlasFileAttachmentService;
use App\Services\Ai\ConversationOps\AiSessionManager;
use App\Services\Ai\ConversationOps\AiSessionStateService;
use App\Services\Ai\ConversationOps\AiThreadResolver;
use App\Services\Ai\Context\AiContextSnapshotRecorder;
use App\Services\Ai\Context\AiConversationRecorder;
use App\Services\Ai\Knowledge\YouTubeKnowledgeIngestionService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Mission\AiGatewayMissionBridge;
use App\Services\Ai\PersistentContext\AtlasPersistentContextRuntimeService;
use App\Services\Ai\Policy\AiRuntimeBudgetService;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Services\Ai\Streaming\AiStreamRecorder;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use App\Services\Ai\ValueObjects\AiThreadResolution;
use App\Services\Ai\ValueObjects\AiPrompt;
use App\Services\AuditLogService;
use App\Services\CapturePrivacyService;
use App\Support\AiAttachmentPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class AiGatewayService
{
    use \App\Services\Ai\AiGateway\RunsGatewayCouncil;
    use \App\Services\Ai\AiGateway\EnrichesGatewayRichInput;
    use \App\Services\Ai\AiGateway\RoutesGatewayProvider;
    use \App\Services\Ai\AiGateway\PlansGatewayDecision;
    use \App\Services\Ai\AiGateway\ProjectsGatewayProgrammingContracts;

    /**
     * Opt-in seam (Patamar 4 · TEOS-I4 pre-flight). Wired by AppServiceProvider
     * resolving callback so unit tests can construct the gateway without
     * pulling the counterfactual stack.
     */
    private ?\App\Services\Ai\Gateway\AtlasGatewayPreflightService $preflight = null;

    /**
     * Opt-in seam (Patamar 4 · A2 · Cognitive Function Decomposer).
     * When wired, every enqueueInteraction() decomposes the input into the
     * canonical 6-axis cognitive tuple BEFORE the trace is persisted, and
     * the vector is attached to trace.metadata.cognitive_function.
     */
    private ?\App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService $decomposer = null;

    public function setPreflight(?\App\Services\Ai\Gateway\AtlasGatewayPreflightService $svc): void
    {
        $this->preflight = $svc;
    }

    public function setCognitiveFunctionDecomposer(?\App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService $svc): void
    {
        $this->decomposer = $svc;
    }

    /**
     * Public accessor for tests/CLIs that want to inspect the last preflight
     * envelope without re-reading the JSONL.
     */
    public function lastPreflightEnvelope(): ?array
    {
        return $this->preflight?->lastEnvelope();
    }

    // Provider inventory lives in ProviderCatalog (full-pass single source).
    // AUTO_LIVE_WORKER docs: desktop kernel drains hermes_cli+codex_cli only;
    // auto mode must not land on workers that never drain (stranded chats).

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
        private readonly AtlasDecideGatewayConsultationService $gatewayConsultation,
        private readonly ?AtlasPersistentContextRuntimeService $persistentContext = null,
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

        // ROTA NO FUNIL ÚNICO (03/07): a decisão do router viajava só quando
        // a superfície era o AiInteractionController HTTP — CLI chat e
        // enqueues programáticos chegavam SEM atlas_ai_router e perdiam a
        // escada inteira (inversão S52 + árbitro semântico S53). O gateway é
        // o funil por onde TODA superfície passa: decide aqui quando ausente;
        // superfícies que já decidiram (controller) são respeitadas. Fail-open.
        if (! is_array($payload['atlas_ai_router'] ?? null)) {
            try {
                $routerDecision = app(\App\Services\Ai\Router\AtlasAiRouterService::class)->decide([
                    'input_text' => $input,
                    'source_type' => (string) ($options['source_type'] ?? 'app'),
                    'payload' => $payload,
                ])->toArray();
                $payload['atlas_ai_router'] = $routerDecision;
                $payload['flow_origin'] = $payload['flow_origin'] ?? $routerDecision['flow_origin'];
                $payload['command_intent'] = $payload['command_intent'] ?? $routerDecision['command_intent'];
            } catch (\Throwable) {
                // roteamento nunca bloqueia o enqueue
            }
        }

        $payload = $this->enforceFairModeProvider($payload, $provider);
        $options['payload'] = $payload;
        if ($this->fairClaude->isFairPayload($payload) && ! is_string($options['model'] ?? null)) {
            $expectedModel = $this->fairClaude->expectedResolvedModel($payload);
            if ($expectedModel !== null) {
                $options['model'] = $expectedModel;
            }
        }
        $gatewayConsultation = null;
        $learnedRouteApplied = false;
        $consultationEnabled = (bool) config('atlas.atlas_decide.gateway_consultation_enabled', true);
        $rawConsultationMode = strtolower(trim((string) config('atlas.atlas_decide.gateway_consultation_mode', 'shadow')));
        // active is the legacy alias for default (apply learned route).
        $consultationMode = match ($rawConsultationMode) {
            'offline' => 'offline',
            'active', 'default', 'canary' => 'active',
            default => 'shadow',
        };
        if (! $consultationEnabled) {
            $consultationMode = 'offline';
        }
        if ($consultationEnabled && $consultationMode !== 'offline') {
            try {
                $gatewayConsultation = $this->gatewayConsultation->consult([
                    'task_category' => (string) (
                        data_get($payload, 'atlas_ai_router.routing_task')
                        ?? data_get($payload, 'task_category')
                        ?? data_get($payload, 'task_type')
                        ?? data_get($payload, 'programming_flow')
                        ?? 'interaction'
                    ),
                    'role' => (string) (
                        data_get($payload, 'council_role')
                        ?? data_get($payload, 'role')
                        ?? $options['role']
                        ?? 'primary'
                    ),
                    'framework' => data_get($payload, 'framework'),
                    'privacy_class' => (string) ($privacy['sensitivity'] ?? 'normal'),
                    'actor' => 'ai_gateway',
                ]);
                $learnedProvider = data_get($gatewayConsultation, 'active_route.provider');
                $manualOverride = data_get($options, 'payload.decision_mode') === 'manual_override';
                if ($consultationMode === 'active'
                    && ! $manualOverride
                    && ! $this->fairClaude->isFairPayload($payload)
                    && ($gatewayConsultation['verdict'] ?? null) === AtlasDecideGatewayConsultationService::VERDICT_FOLLOW_LEARNED
                    && is_string($learnedProvider)
                    && in_array($learnedProvider, ProviderCatalog::autoLiveWorkerProviders(), true)
                    && $this->providerAllowedForInvocation($learnedProvider, $options)) {
                    $provider = $learnedProvider;
                    $options['provider'] = $provider;
                    $learnedRouteApplied = true;
                }
            } catch (\Throwable $exception) {
                $gatewayConsultation = [
                    'status' => 'degraded',
                    'reason' => 'gateway_consultation_error',
                    'error_hash' => hash('sha256', $exception->getMessage()),
                ];
            }
        }
        $options['payload'] = $payload;
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
                'gateway_consultation' => $gatewayConsultation,
                'gateway_consultation_mode' => $consultationMode,
                'route_applied' => $learnedRouteApplied,
            ],
        );
        if ($provider === 'hermes_cli') {
            $payload['hermes'] = array_merge(
                is_array($payload['hermes'] ?? null) ? $payload['hermes'] : [],
                [
                    'runtime_router_reason' => data_get($decisionPayload, 'provider_selection.selection_explanation.hermes_runtime_router.reason'),
                    'runtime_router_role' => 'executive_runtime',
                ],
            );
        }
        // AtlasDecide mesh auto-route: when the sealed advisor routes this mission to
        // a governed many-agent fleet (execution_route='mesh' — requires mesh.policy
        // =atlas_adapter + a per-request decomposition signal + privacy ok + the
        // dedicated mesh.auto_route switch), mark the job kind='mesh' so the worker
        // fans it out via the Executive Mesh. The worker re-gates and falls back to a
        // single provider if it cannot dispatch, so this never traps a request. Never
        // overrides an explicit non-interaction kind (e.g. council/scout).
        if (data_get($decisionPayload, 'provider_selection.selection_explanation.hermes_mesh_routing.execution_route') === 'mesh'
            && ($options['kind'] ?? 'interaction') === 'interaction') {
            $options['kind'] = 'mesh';
            $payload['hermes']['mesh'] = array_merge(
                is_array(data_get($payload, 'hermes.mesh')) ? data_get($payload, 'hermes.mesh') : [],
                ['routed_by' => 'atlas_decide'],
            );
        }
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
        if ($fairMode) {
            $this->handoffs->recordFairModeLossReceipt($threadResolution->thread, $session, $provider, [
                'trigger' => 'enqueue_interaction',
            ]);
        }
        $options = $this->optionsWithResolvedRuntime($options, $threadResolution->thread->id, $session->id, $autoCompaction?->id, $providerHandoff?->id);
        if ($fairMode) {
            $options['payload']['provider_handoff_disabled_by_fair_mode'] = true;
        }
        $options = $this->optionsWithPersistentContext($input, $options, $provider);
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

        $modelResolution = $this->models->resolveWithSource($provider, $prompt->model ?: ($options['model'] ?? null), $this->modelResolutionContext($options, $prompt, $input));
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

            // Patamar 4 · TEOS-I4 pre-flight (opt-in). Major decisions get
            // a counterfactual tree projected BEFORE the job is enqueued.
            // Advisory only — never blocks; envelope is attached to metadata.
            $preflightEnvelope = null;
            if ($this->preflight !== null) {
                try {
                    $preflightEnvelope = $this->preflight->preflight($input, $provider, $options);
                } catch (\Throwable $preflightErr) {
                    $preflightEnvelope = ['verdict' => 'not_projected', 'error' => substr($preflightErr->getMessage(), 0, 160)];
                }
            }

            // A2 · Cognitive Function Decomposer — wire 6-axis vector for EVERY
            // turn before persisting trace. Advisory only; never blocks. The
            // vector lands in trace.metadata.cognitive_function so downstream
            // routers (Mission/Hyperflow/SDD/BDD) consume it without recomputing.
            $cognitiveFunctionVector = null;
            if ($this->decomposer !== null) {
                try {
                    $cognitiveFunctionVector = $this->decomposer->decompose($input, [
                        'role' => $options['role'] ?? ($prompt->agentSlug ?? null),
                        'framework' => $options['framework'] ?? data_get($options, 'payload.framework'),
                        'privacy_class' => $privacy['privacy_class'] ?? null,
                    ]);
                } catch (\Throwable $decErr) {
                    $cognitiveFunctionVector = ['error' => substr($decErr->getMessage(), 0, 160)];
                }
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
                    'compute_effort' => data_get($options, 'payload.compute_effort'),
                    'compute_effort_contract' => data_get($options, 'payload.compute_effort_contract')
                        ?? data_get($options, 'payload.model_selection_contract.compute_effort'),
                    'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
                    ...$this->programmingMetadata($options),
                    'decision_receipt' => $decisionReceipt,
                    'atlas_decide_execution' => $atlasExecution,
                    'kernel' => $kernelEnvelope,
                    'preflight' => $preflightEnvelope,
                    'cognitive_function' => $cognitiveFunctionVector,
                    // Canonical Atlas AI Hyperflow / RouterRuntime envelope
                    // built by AtlasHyperflowEntryService BEFORE the legacy
                    // router. Surface (Atlas AI Desktop / Mobile) consumes it
                    // via the `hyperflow_runtime` field on AiTraceResource.
                    'hyperflow_runtime' => data_get($options, 'payload.hyperflow_runtime'),
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
                    'compute_effort' => data_get($options, 'payload.compute_effort'),
                    'compute_effort_contract' => data_get($options, 'payload.compute_effort_contract')
                        ?? data_get($options, 'payload.model_selection_contract.compute_effort'),
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
            $this->captureOperatorLearningFromTrace($trace, $input, $options);

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


    /**
     * @param  array<string,mixed>  $options
     */
    private function captureOperatorLearningFromTrace(AiTrace $trace, string $input, array $options): void
    {
        try {
            app(\App\Services\Ai\OperatorIntelligence\OperatorLearningRuntimeCaptureService::class)
                ->captureFromTrace($trace, $input, $options);
        } catch (\Throwable $exception) {
            Log::warning('operator_learning_gateway_capture_failed', [
                'trace_id' => $trace->id,
                'source_type' => $trace->source_type,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function recordTelemetry(string $eventName, AiTrace $trace, ?AiJob $job = null, array $overrides = []): void
    {
        if (! DatabaseTableAvailability::has('ai_telemetry_events')) {
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

        if (DatabaseTableAvailability::has('ai_quality_evaluations')) {
            $relations[] = 'qualityEvaluation';
        }

        if (DatabaseTableAvailability::has('ai_quality_actions')) {
            $relations[] = 'qualityActions';
        }

        if (DatabaseTableAvailability::has('ai_router_decisions')) {
            $relations[] = 'routerDecision';
        }

        if (DatabaseTableAvailability::has('ai_decisions')) {
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
        $modelResolution = $this->models->resolveWithSource('gemini_cli', 'auto', $this->modelResolutionContext($options, $prompt, $input));
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
            'selected_model' => $resolution['selected_model'] ?? $resolution['model'] ?? null,
            'selected_model_alias' => $resolution['selected_model_alias'] ?? $resolution['model_alias'] ?? null,
            'operator_requested_model_alias' => $resolution['operator_requested_model_alias'] ?? null,
            'model_family' => $resolution['model_family'] ?? null,
            'model_selection_source' => $resolution['selection_source'] ?? $resolution['source'] ?? 'unresolved',
            'allowed_models' => $resolution['allowed_models'] ?? null,
            'model_allow_auto' => (bool) ($resolution['allow_auto'] ?? true),
            'model_allow_manual' => (bool) ($resolution['allow_manual'] ?? true),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function modelResolutionContext(array $options, ?AiPrompt $prompt = null, ?string $input = null): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return array_merge($payload, [
            'domain' => data_get($payload, 'domain') ?? data_get($payload, 'programming_message_plan.domain') ?? data_get($prompt?->taskRequest, 'domain'),
            'flow' => data_get($payload, 'flow') ?? data_get($payload, 'programming_message_plan.flow') ?? data_get($payload, 'atlas_workflow_mode'),
            'task' => $input ?? data_get($payload, 'task') ?? data_get($prompt?->taskRequest, 'description'),
            'task_type' => data_get($payload, 'task_type') ?? data_get($payload, 'programming_message_plan.task_type') ?? data_get($prompt?->taskRequest, 'task_type'),
            'task_request' => $prompt?->taskRequest,
            'compute_effort' => data_get($payload, 'compute_effort_contract') ?? data_get($payload, 'model_selection_contract.compute_effort') ?? data_get($payload, 'compute_effort'),
        ]);
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

    private function optionsWithPersistentContext(string $input, array $options, string $provider): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        if (($payload['persistent_context']['schema_version'] ?? null) === AtlasPersistentContextRuntimeService::SCHEMA_VERSION) {
            return $options;
        }

        // Persistent context is best-effort ENRICHMENT (injects bootstrap/memory
        // context into the prompt). It must NEVER block the interaction create:
        // its heavy session-bootstrap can take >30s under load and would otherwise
        // fatal the request at max_execution_time. Bound it with a wall-clock
        // budget; on timeout (or any throw) we degrade gracefully — the worker
        // still runs the mission with the base prompt.
        // Persistent context runs the heavy session bootstrap, which fans out into
        // hundreds of LIKE seq-scans over atlas_engineering_code_symbols — far too
        // slow to run synchronously on every interaction create (it exceeds PHP's
        // max_execution_time under load and fatals the request; the queries are each
        // fast but collectively long, so neither a per-query statement_timeout nor a
        // pcntl SIGALRM inside the cli-server can bound it). It is best-effort
        // ENRICHMENT, so it is OFF by default on the synchronous create path — the
        // worker still runs the mission. Re-enable via env once the bootstrap
        // code-symbol search is batched/cached (see the canonical doc next_actions).
        if (! (bool) config('atlas.ai.persistent_context.gateway_enabled', false)) {
            return $options;
        }

        try {
            $payload['persistent_context'] = ($this->persistentContext ?? app(AtlasPersistentContextRuntimeService::class))->build([
                'prompt' => $input,
                'workspace' => data_get($payload, 'workspace', data_get($options, 'workspace', base_path())),
                'surface_id' => data_get($payload, 'surface_id', data_get($payload, 'app_surface')),
                'domain' => data_get($payload, 'routing_domain', data_get($payload, 'atlas_mode', data_get($payload, 'programming_profile', 'atlas'))),
                'flow_id' => data_get($payload, 'hyperflow_runtime.flow_id', data_get($payload, 'programming_dispatch.execution_path', data_get($payload, 'atlas_workflow_mode'))),
                'provider' => $provider,
                'payload' => $payload,
                'evidence_refs' => (array) data_get($payload, 'evidence_refs', []),
                'scope_type' => is_string(data_get($payload, 'thread_id')) ? 'thread' : 'workspace',
                'scope_id' => data_get($payload, 'thread_id'),
                'source_type' => $options['source_type'] ?? 'gateway',
            ]);
        } catch (\Throwable $exception) {
            $payload['persistent_context'] = [
                'schema_version' => AtlasPersistentContextRuntimeService::SCHEMA_VERSION,
                'status' => AtlasPersistentContextRuntimeService::STATUS_DEGRADED,
                'error' => 'persistent_context_gateway_threw',
                'exception_class' => $exception::class,
                'claim_policy' => [
                    'benchmark_not_run' => true,
                    'rivals_compared' => false,
                    'provider_calls_made' => false,
                    'provider_is_context_consumer_only' => true,
                ],
            ];
        }

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


}
