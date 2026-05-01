<?php

namespace App\Services\Ai;

use App\Models\AiCompaction;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class AiGatewayService
{
    private const COUNCIL_PROVIDERS = ['claude_cli', 'codex_cli'];
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
        private readonly AuditLogService $audit,
    ) {}

    public function enqueueInteraction(string $input, array $options = []): AiTrace
    {
        if (! config('atlas.ai.enabled')) {
            throw new RuntimeException('Atlas AI is disabled.');
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
        $provider = $this->providerFromOptions($options);
        $options['provider'] = $provider;
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
                    'model_identity_source' => $modelResolution['source'],
                    'task_request' => $prompt->taskRequest,
                    'context_pack' => $prompt->contextPack,
                    'execution_plan' => $prompt->executionPlan,
                    'skills_activated' => $prompt->activatedSkills,
                    'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
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
                    'model_identity_source' => $modelResolution['source'],
                    'task_request' => $prompt->taskRequest,
                    'context_pack' => $prompt->contextPack,
                    'execution_plan' => $prompt->executionPlan,
                    'skills_activated' => $prompt->activatedSkills,
                ],
                'available_at' => $options['available_at'] ?? $now,
                'max_attempts' => (int) ($options['max_attempts'] ?? config('atlas.ai.max_attempts', 1)),
                'timeout_seconds' => (int) ($options['timeout_seconds'] ?? config('atlas.ai.timeout_seconds', 7200)),
                'metadata' => [
                    'intent' => $prompt->intent,
                    'skill_versions' => $prompt->skillVersions,
                    'privacy' => $privacy,
                    'thread' => $threadResolution->toArray(),
                    'session' => $this->sessionMetadata($lockedSession),
                    'auto_compaction_id' => $autoCompaction?->id,
                    'provider_handoff_id' => $providerHandoff?->id,
                    'model_identity_source' => $modelResolution['source'],
                    'task_request' => $prompt->taskRequest,
                    'context_pack' => $prompt->contextPack,
                    'execution_plan' => $prompt->executionPlan,
                    'skills_activated' => $prompt->activatedSkills,
                    'dev_execution_plan' => data_get($options, 'payload.dev_execution_plan'),
                ],
            ]);

            $this->conversation->recordUserMessage($lockedThread, $trace, $input, [
                'source' => 'ai_gateway',
                'thread_resolution' => $threadResolution->toArray(),
                'session_id' => $lockedSession->id,
            ]);

            $this->states->updateForUserInput($lockedThread, $lockedSession, $input, $this->optionsWithPromptContracts($options, $prompt));
            $this->snapshots->record($trace, $lockedSession, $prompt, $autoCompaction, $providerHandoff);
            $this->recordTelemetry('trace_created', $trace, $job, [
                'surface' => 'server',
                'runtime' => 'laravel',
                'metadata' => [
                    'source_type' => $trace->source_type,
                    'kind' => $job->kind,
                    'model_identity_source' => $modelResolution['source'],
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
                    'model_identity_source' => $modelResolution['source'],
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

            $this->recordRouterDecision($trace, $options, $provider);

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
                    'model_identity_source' => $traceModelResolution['source'],
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
                        'model_identity_source' => $jobModelResolution['source'],
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
                    'timeout_seconds' => (int) ($options['timeout_seconds'] ?? config('atlas.ai.timeout_seconds', 7200)),
                    'metadata' => [
                        'intent' => $prompt->intent,
                        'skill_versions' => $prompt->skillVersions,
                        'privacy' => $privacy,
                        'execution_policy' => 'dual_review',
                        'thread' => $threadResolution->toArray(),
                        'session' => $this->sessionMetadata($lockedSession),
                        'auto_compaction_id' => $autoCompaction?->id,
                        'provider_handoff_id' => $providerHandoff?->id,
                        'model_identity_source' => $jobModelResolution['source'],
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
                        'model_identity_source' => $jobModelResolution['source'],
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
                        'model_identity_source' => $jobModelResolution['source'],
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
            ]);

            $this->states->updateForUserInput($lockedThread, $lockedSession, $input, $this->optionsWithPromptContracts($options, $prompt));
            $this->snapshots->record($trace, $lockedSession, $prompt, $autoCompaction, $providerHandoff);

            $this->recordRouterDecision($trace, $options, 'claude_codex');
            $firstJob = $trace->jobs()->oldest('created_at')->first();
            $this->recordTelemetry('trace_created', $trace, $firstJob, [
                'surface' => 'server',
                'runtime' => 'laravel',
                'metadata' => [
                    'source_type' => $trace->source_type,
                    'execution_policy' => 'dual_review',
                    'council_providers' => $providers,
                    'model_identity_source' => $traceModelResolution['source'],
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

    private function recordRouterDecision(AiTrace $trace, array $options, string $provider): void
    {
        if (! Schema::hasTable('ai_router_decisions')) {
            return;
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $mode = (string) (data_get($payload, 'atlas_workflow_mode') ?: data_get($options, 'mode', 'direct'));
        $strategy = data_get($payload, 'provider_strategy');
        $signals = is_array($strategy) ? $strategy : [];

        AiRouterDecision::query()->updateOrCreate([
            'trace_id' => $trace->id,
        ], [
            'mode' => $mode,
            'selected_provider' => $provider,
            'fallback_provider' => is_string(data_get($strategy, 'fallback_provider')) ? data_get($strategy, 'fallback_provider') : null,
            'signals' => [
                'provider_online' => data_get($strategy, 'has_online_provider'),
                'critical' => data_get($strategy, 'critical', false),
                'requested_provider' => data_get($payload, 'requested_provider'),
                'execution_policy' => data_get($payload, 'execution_policy'),
                'strategy' => $signals,
            ],
            'reason' => (string) (data_get($strategy, 'reason') ?: "Provider {$provider} selecionado para modo {$mode}."),
            'was_overridden' => (bool) data_get($payload, 'requested_provider'),
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
        return data_get($options, 'payload.execution_policy') === 'dual_review'
            || data_get($options, 'payload.requested_provider') === 'claude_codex'
            || ($options['provider'] ?? null) === 'claude_codex';
    }

    private function providerFromOptions(array $options): string
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        if (($options['provider'] ?? null) === 'claude_codex'
            || data_get($payload, 'requested_provider') === 'claude_codex'
            || data_get($payload, 'execution_policy') === 'dual_review'
        ) {
            return 'claude_codex';
        }

        $provider = $options['provider'] ?? data_get($payload, 'requested_provider');
        if (in_array($provider, self::COUNCIL_PROVIDERS, true)) {
            return (string) $provider;
        }

        return (string) config('atlas.ai.default_provider', 'claude_cli');
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
