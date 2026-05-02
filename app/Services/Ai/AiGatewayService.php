<?php

namespace App\Services\Ai;

use App\Models\AiCompaction;
use App\Models\AiDecision;
use App\Models\AiJob;
use App\Models\AiMessage;
use App\Models\AiRouterDecision;
use App\Models\AiSession;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Models\Capture;
use App\Services\Ai\Telemetry\AiTelemetryCollector;
use App\Services\Ai\ValueObjects\AiThreadResolution;
use App\Services\AuditLogService;
use App\Services\CapturePrivacyService;
use App\Support\AiAttachmentPayload;
use Illuminate\Support\Facades\DB;
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
        private readonly AuditLogService $audit,
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
        $candidateProvider = $this->decide->candidateProvider($options, $this->runtimeSettings->defaultProvider());
        $fallbackReason = $candidateProvider !== $provider
            ? $this->providerFallbackReason($candidateProvider, $provider, $options)
            : null;
        $payload['selected_provider'] = $provider;
        $payload['atlas_decide'] = array_merge(
            is_array($payload['atlas_decide'] ?? null) ? $payload['atlas_decide'] : [],
            [
                'candidate_provider' => $candidateProvider,
                'selected_provider' => $provider,
                'fallback_provider' => $fallbackReason ? $provider : null,
                'fallback_reason' => $fallbackReason,
            ],
        );
        $options['payload'] = $payload;
        $threadResolution = $this->threads->resolve($input, $options);
        $session = $this->sessions->ensureActive($threadResolution->thread, $provider, $input, $options);
        $resumeCompaction = $this->maybeCompactSessionResume($threadResolution->thread, $session);
        $autoCompaction = $resumeCompaction ?: $this->compactions->maybeAutoCompact($threadResolution->thread, $session);
        $providerHandoff = $this->handoffs->createIfSwitching($threadResolution->thread, $session, $provider, $autoCompaction, [
            'trigger' => 'enqueue_interaction',
        ]);
        $options = $this->optionsWithResolvedRuntime($options, $threadResolution->thread->id, $session->id, $autoCompaction?->id, $providerHandoff?->id);
        $prompt = $this->prompts->build($input, $options);
        if ($this->shouldRunCouncil($options)) {
            return $this->enqueueCouncilInteraction($input, $options, $prompt, $privacy, $threadResolution, $session, $autoCompaction, $providerHandoff);
        }

        $modelResolution = $this->models->resolveWithSource($provider, $prompt->model ?: ($options['model'] ?? null));
        $model = $modelResolution['model'];
        $this->budgets->assertAllows($provider, $model, $options);
        $now = now();

        return DB::transaction(function () use ($input, $options, $prompt, $provider, $model, $modelResolution, $now, $privacy, $threadResolution, $session, $autoCompaction, $providerHandoff): AiTrace {
            $lockedThread = $this->lockThreadForTrace($threadResolution);
            $lockedSession = $this->lockSessionForTrace($session);

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
                    'execution_plan' => $prompt->executionPlan,
                    'skills_activated' => $prompt->activatedSkills,
                    'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
                    'decision_receipt' => $this->decide->receiptForTrace($options, $provider, $model),
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
                    'privacy' => $privacy,
                    'thread' => $threadResolution->toArray(),
                    'session' => $this->sessionMetadata($lockedSession),
                    'auto_compaction_id' => $autoCompaction?->id,
                    'provider_handoff_id' => $providerHandoff?->id,
                    ...$this->modelRuntimeMetadata($modelResolution),
                    'task_request' => $prompt->taskRequest,
                    'context_pack' => $prompt->contextPack,
                    'execution_plan' => $prompt->executionPlan,
                    'skills_activated' => $prompt->activatedSkills,
                ],
                'available_at' => $options['available_at'] ?? $now,
                'max_attempts' => (int) ($options['max_attempts'] ?? config('atlas.ai.max_attempts', 1)),
                'timeout_seconds' => (int) ($options['timeout_seconds'] ?? config('atlas.ai.timeout_seconds', 600)),
                'metadata' => [
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
                    'execution_plan' => $prompt->executionPlan,
                    'skills_activated' => $prompt->activatedSkills,
                    'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
                    'decision_receipt' => $this->decide->receiptForTrace($options, $provider, $model),
                ],
            ]);

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

            return $trace->load($this->traceRelations());
        }, self::TRANSACTION_ATTEMPTS);
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
                    'execution_plan' => $prompt->executionPlan,
                    'skills_activated' => $prompt->activatedSkills,
                    'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
                    'decision_receipt' => $this->decide->receiptForTrace($options, 'claude_codex', $traceModelResolution['model']),
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
                        'execution_plan' => $prompt->executionPlan,
                        'skills_activated' => $prompt->activatedSkills,
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
                        'execution_plan' => $prompt->executionPlan,
                        'skills_activated' => $prompt->activatedSkills,
                        'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
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

        if (! Schema::hasTable('ai_decisions')) {
            return;
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $manualProvider = $this->decide->manualOverrideProvider($options);
        $receipt = $this->decide->receiptForTrace($options, $provider, $model);
        $signals = [
            ...$this->decide->signals($options),
            'decision_mode' => $this->decide->decisionMode($options),
            'operator_requested_provider' => data_get($payload, 'operator_requested_provider') ?: 'auto',
            'requested_provider' => $manualProvider,
            'model_identity_source' => $modelResolution['source'] ?? 'unresolved',
            'model_tier' => $modelResolution['model_tier'] ?? config('atlas.ai.default_tier', 'daily'),
            'model_allow_auto' => (bool) ($modelResolution['allow_auto'] ?? true),
            'model_allow_manual' => (bool) ($modelResolution['allow_manual'] ?? true),
            'task_request' => $prompt->taskRequest,
            'execution_plan' => $prompt->executionPlan,
        ];

        AiDecision::query()->updateOrCreate([
            'trace_id' => $trace->id,
        ], [
            'router_decision_id' => $routerDecision?->id,
            'policy_version' => (string) data_get($payload, 'atlas_decide.policy_version', 'atlas-decide-v1'),
            'decision_mode' => $receipt['decision_mode'] ?? 'atlas_decide',
            'route_mode' => (string) (data_get($payload, 'atlas_workflow_mode') ?: data_get($options, 'mode', 'direct')),
            'task_type' => $this->boundedString(data_get($prompt->taskRequest, 'task_type'), 80),
            'risk_level' => $this->boundedString(data_get($prompt->taskRequest, 'risk_level'), 40),
            'selected_provider' => $provider,
            'selected_model' => $model,
            'fallback_provider' => $this->boundedString(
                data_get($payload, 'provider_strategy.fallback_provider') ?: data_get($payload, 'atlas_decide.fallback_provider'),
                32,
            ),
            'operator_requested_provider' => (string) ($receipt['operator_requested_provider'] ?? 'auto'),
            'requested_provider' => $manualProvider,
            'was_overridden' => $manualProvider !== null,
            'confidence_score' => $this->confidenceScore($options, $provider),
            'signals' => $signals,
            'candidates' => $this->decisionCandidates($options, $provider),
            'constraints' => $this->decisionConstraints($options, $provider),
            'metrics_snapshot' => $this->decisionMetricsSnapshot($options, $modelResolution),
            'reason' => (string) ($receipt['reason'] ?? $this->decide->decisionReason($options, $provider)),
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

        return AiRouterDecision::query()->updateOrCreate([
            'trace_id' => $trace->id,
        ], [
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
                'atlas_decide' => $this->decide->signals($options),
                'strategy' => $signals,
            ],
            'reason' => (string) (data_get($strategy, 'reason') ?: $this->decide->decisionReason($options, $provider)),
            'was_overridden' => $manualProvider !== null,
        ]);
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
        $options['payload'] = $payload;

        return $options;
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
        $manualProvider = $this->decide->manualOverrideProvider($options);
        $requested = data_get($options, 'payload.execution_policy') === 'dual_review'
            || $manualProvider === 'claude_codex'
            || ($options['provider'] ?? null) === 'claude_codex';

        return $requested && $this->providerAllowedForInvocation('claude_codex', $options) === 'claude_codex';
    }

    private function providerFromOptions(array $options): string
    {
        $manualProvider = $this->decide->manualOverrideProvider($options);
        if ($manualProvider === 'claude_codex'
            || data_get($options, 'payload.execution_policy') === 'dual_review'
        ) {
            return $this->providerAllowedForInvocation('claude_codex', $options);
        }

        if (in_array($manualProvider, self::INVOCATION_PROVIDERS, true)) {
            return $this->providerAllowedForInvocation((string) $manualProvider, $options, explicitProvider: true);
        }

        $candidate = $this->decide->candidateProvider($options, $this->runtimeSettings->defaultProvider());

        return $this->providerAllowedForInvocation($candidate, $options, explicitProvider: false);
    }

    private function providerAllowedForInvocation(string $provider, array $options, bool $explicitProvider = false): string
    {
        if (! $explicitProvider && $provider === 'gemini_cli' && $this->geminiBlockedForInvocation($options)) {
            return $this->geminiFallbackProvider();
        }

        if (! $this->isAutomaticInvocation($options)) {
            return (bool) ($this->runtimeSettings->providerConfig($provider)['allow_manual'] ?? true)
                ? $provider
                : $this->manualFallbackProvider($provider, $options);
        }

        if ($provider === 'claude_codex') {
            return $this->runtimeSettings->councilAllowAuto()
                ? $provider
                : $this->automaticFallbackProvider($options);
        }

        if ((bool) ($this->runtimeSettings->providerConfig($provider)['allow_auto'] ?? true)) {
            return $provider;
        }

        return $this->automaticFallbackProvider($options);
    }

    private function providerFallbackReason(string $candidateProvider, string $selectedProvider, array $options): string
    {
        if ($candidateProvider === $selectedProvider) {
            return 'none';
        }

        if ($candidateProvider === 'gemini_cli' && $this->geminiBlockedForInvocation($options)) {
            return 'gemini_blocked_for_dev_like_task';
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

    private function manualFallbackProvider(string $provider, array $options): string
    {
        $default = $this->runtimeSettings->defaultProvider();
        if ($default !== $provider
            && $default !== 'claude_codex'
            && ! ($default === 'gemini_cli' && $this->geminiBlockedForInvocation($options))
            && (bool) ($this->runtimeSettings->providerConfig($default)['allow_manual'] ?? true)
        ) {
            return $default;
        }

        return $provider === 'claude_cli' ? 'codex_cli' : 'claude_cli';
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

    private function isAutomaticInvocation(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $sourceType = $options['source_type'] ?? null;

        return data_get($payload, 'decision_mode') === 'atlas_decide'
            || (bool) data_get($payload, 'automatic', false)
            || in_array($sourceType, ['capture', 'scheduled', 'system'], true);
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
