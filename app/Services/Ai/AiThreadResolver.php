<?php

namespace App\Services\Ai;

use App\Models\AiSession;
use App\Models\AiThread;
use App\Services\Ai\ValueObjects\AiThreadResolution;
use Illuminate\Support\Str;
use RuntimeException;

class AiThreadResolver
{
    public function resolve(string $input, array $options): AiThreadResolution
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $explicitThreadId = $this->firstString(
            $options['thread_id'] ?? null,
            data_get($payload, 'thread_id'),
            data_get($payload, 'conversation_context.thread_id'),
        );

        if (($options['new_thread'] ?? false) === true) {
            return new AiThreadResolution($this->createThread($input, $options, 'explicit_new_thread'), 'explicit_new_thread', true);
        }

        if ($explicitThreadId) {
            $thread = AiThread::query()->find($explicitThreadId);
            if (! $thread) {
                throw new RuntimeException('AI thread not found.');
            }

            if ($thread->status !== 'active') {
                $thread->update(['status' => 'active']);
            }

            return new AiThreadResolution($this->updateThreadModeFromPayload($thread->refresh(), $payload, $options), 'explicit_thread_id', false);
        }

        $explicitSessionId = $this->firstString(
            $options['session_id'] ?? null,
            data_get($payload, 'session_id'),
            data_get($payload, 'conversation_context.session_id'),
        );
        if ($explicitSessionId) {
            $session = AiSession::query()->with('thread')->find($explicitSessionId);
            if (! $session || ! $session->thread) {
                throw new RuntimeException('AI session not found.');
            }

            if ($session->thread->status !== 'active') {
                $session->thread->update(['status' => 'active']);
            }

            return new AiThreadResolution($this->updateThreadModeFromPayload($session->thread->refresh(), $payload, $options), 'explicit_session_id', false);
        }

        if ($this->allowsImplicitContinuation($options, $payload)) {
            $candidate = $this->latestContinuationCandidate($input, $options);
            if ($candidate) {
                return new AiThreadResolution($candidate, 'latest_continuation_candidate', false);
            }
        }

        return new AiThreadResolution($this->createThread($input, $options, 'implicit_new_thread'), 'implicit_new_thread', true);
    }

    private function allowsImplicitContinuation(array $options, array $payload): bool
    {
        return ($options['allow_implicit_thread_continuation'] ?? false) === true
            || data_get($payload, 'allow_implicit_thread_continuation') === true;
    }

    private function latestContinuationCandidate(string $input, array $options): ?AiThread
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $hasRecentPayloadTurns = is_array(data_get($payload, 'conversation_context.turns'))
            && count(data_get($payload, 'conversation_context.turns')) > 0;

        if (! $hasRecentPayloadTurns && ! $this->isShortReference($input)) {
            return null;
        }

        $surface = $this->surface($options, $payload);
        $workspace = $this->workspace($payload);

        return AiThread::query()
            ->where('status', 'active')
            ->where('surface', $surface)
            ->when($workspace, fn ($query) => $query->where('workspace', $workspace))
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->first();
    }

    private function createThread(string $input, array $options, string $reason): AiThread
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $agent = $this->requestedAgent($payload, $options);
        $workflowMode = $this->workflowMode($payload);
        $mode = $this->threadMode($payload, $options);
        $focus = $this->threadFocus($payload, $mode);
        $routingTask = $this->routingTask($payload, $workflowMode);
        $routingDomain = $this->routingDomain($payload, $mode);
        $providerGovernance = $this->providerGovernance($payload, $options);

        return AiThread::query()->create([
            'title' => $this->titleFromInput($input),
            'status' => 'active',
            'surface' => $this->surface($options, $payload),
            'workspace' => $this->workspace($payload),
            'source_type' => $options['source_type'] ?? 'app',
            'source_id' => $options['source_id'] ?? null,
            'metadata' => [
                'created_by' => 'ai_thread_resolver',
                'creation_reason' => $reason,
                'requested_agent' => $agent,
                'last_agent_slug' => $agent,
                'requested_provider' => data_get($payload, 'requested_provider')
                    ?: (data_get($payload, 'decision_mode') === 'manual_override' ? ($options['provider'] ?? null) : null),
                'selected_provider' => $options['provider'] ?? null,
                'execution_provider' => data_get($providerGovernance, 'execution_provider') ?: ($options['provider'] ?? null),
                'operator_requested_provider' => data_get($payload, 'operator_requested_provider'),
                'decision_mode' => data_get($providerGovernance, 'decision_mode') ?: data_get($payload, 'decision_mode'),
                'decision_authority' => data_get($providerGovernance, 'decision_authority'),
                'provider_governance' => $providerGovernance,
                'app_surface' => data_get($payload, 'app_surface'),
                'atlas_workflow_mode' => $workflowMode,
                'workflow_mode' => $workflowMode,
                'atlas_focus' => $focus,
                'initial_focus' => $focus,
                'current_focus' => $focus,
                'atlas_mode' => $mode,
                'initial_mode' => $mode,
                'current_mode' => $mode,
                'routing_task' => $routingTask,
                'routing_domain' => $routingDomain,
                'created_from' => data_get($payload, 'created_from'),
                'origin_type' => data_get($payload, 'origin_type'),
                'origin_label' => data_get($payload, 'origin_label'),
                'source_operational_thread_id' => data_get($payload, 'source_operational_thread_id'),
                'source_operational_title' => data_get($payload, 'source_operational_title'),
                'source_inbox_item_id' => data_get($payload, 'source_inbox_item_id'),
                'source_context_bundle_id' => data_get($payload, 'source_context_bundle_id'),
                'source_thread_mode' => data_get($payload, 'source_thread_mode'),
                'source_thread_focus' => data_get($payload, 'source_thread_focus'),
            ],
        ]);
    }

    private function updateThreadModeFromPayload(AiThread $thread, array $payload, array $options = []): AiThread
    {
        $workflowMode = $this->workflowMode($payload);
        $agent = $this->requestedAgent($payload, $options);
        $mode = $this->threadMode($payload, $options);
        $focus = $this->threadFocus($payload, $mode);
        $routingTask = $this->routingTask($payload, $workflowMode);
        $routingDomain = $this->routingDomain($payload, $mode);
        $requestedProvider = $this->firstString(data_get($payload, 'requested_provider'));
        $selectedProvider = $this->firstString(data_get($payload, 'selected_provider'));
        $providerGovernance = $this->providerGovernance($payload, $options);

        if (! $focus && ! $mode && ! $routingTask && ! $routingDomain && ! $requestedProvider && ! $selectedProvider && ! $workflowMode && ! $agent && ! $providerGovernance) {
            return $thread;
        }

        $metadata = $thread->metadata ?? [];
        $previousFocus = $this->firstString($metadata['atlas_focus'] ?? null);
        $previousMode = $this->firstString($metadata['atlas_mode'] ?? null);
        $changed = false;

        if ($focus && $focus !== $previousFocus) {
            $history = is_array($metadata['focus_history'] ?? null) ? $metadata['focus_history'] : [];
            $history[] = [
                'from' => $previousFocus,
                'to' => $focus,
                'at' => now()->toJSON(),
                'source' => 'ai_thread_resolver',
            ];

            $metadata['initial_focus'] = $metadata['initial_focus'] ?? ($previousFocus ?: $focus);
            $metadata['atlas_focus'] = $focus;
            $metadata['current_focus'] = $focus;
            $metadata['focus_history'] = array_slice($history, -20);
            $changed = true;
        }

        if ($mode && $mode !== $previousMode) {
            $history = is_array($metadata['mode_history'] ?? null) ? $metadata['mode_history'] : [];
            $history[] = [
                'from' => $previousMode,
                'to' => $mode,
                'at' => now()->toJSON(),
                'source' => 'ai_thread_resolver',
            ];

            $metadata['initial_mode'] = $metadata['initial_mode'] ?? ($previousMode ?: $mode);
            $metadata['atlas_mode'] = $mode;
            $metadata['current_mode'] = $mode;
            $metadata['mode_history'] = array_slice($history, -20);
            $changed = true;
        }

        foreach ([
            'atlas_workflow_mode' => $workflowMode,
            'workflow_mode' => $workflowMode,
            'requested_agent' => $agent,
            'last_agent_slug' => $agent,
            'routing_task' => $routingTask,
            'routing_domain' => $routingDomain,
            'requested_provider' => $requestedProvider,
            'selected_provider' => $selectedProvider,
            'execution_provider' => data_get($providerGovernance, 'execution_provider'),
            'operator_requested_provider' => $this->firstString(data_get($payload, 'operator_requested_provider')),
            'decision_mode' => $this->firstString(data_get($providerGovernance, 'decision_mode'), data_get($payload, 'decision_mode')),
            'decision_authority' => $this->firstString(data_get($providerGovernance, 'decision_authority')),
        ] as $key => $value) {
            if ($value && ($metadata[$key] ?? null) !== $value) {
                $metadata[$key] = $value;
                $changed = true;
            }
        }

        if ($providerGovernance && ($metadata['provider_governance'] ?? null) !== $providerGovernance) {
            $metadata['provider_governance'] = $providerGovernance;
            $changed = true;
        }

        if (! $changed) {
            return $thread;
        }

        $thread->update(['metadata' => $metadata]);

        return $thread->refresh();
    }

    private function titleFromInput(string $input): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($input)) ?: 'Nova conversa Atlas';

        return Str::limit($normalized, 90, '');
    }

    private function surface(array $options, array $payload): string
    {
        $surface = $this->firstString(data_get($payload, 'app_surface'), data_get($payload, 'surface'));
        if ($surface) {
            return Str::limit($surface, 80, '');
        }

        return match ($options['source_type'] ?? 'app') {
            'manual' => 'api',
            'scheduled' => 'automation',
            'system' => 'system',
            default => 'atlas_ai_sheet',
        };
    }

    private function requestedAgent(array $payload, array $options): ?string
    {
        return $this->firstString(
            data_get($payload, 'requested_agent'),
            data_get($payload, 'last_agent_slug'),
            $options['agent_slug'] ?? null,
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>|null
     */
    private function providerGovernance(array $payload, array $options): ?array
    {
        $governance = data_get($payload, 'provider_governance');
        if (is_array($governance) && ($governance['schema_version'] ?? null) === 'atlas.provider_governance.v1') {
            return $governance;
        }

        $decisionMode = $this->firstString(data_get($payload, 'decision_mode'));
        $selectedProvider = $this->firstString(data_get($payload, 'selected_provider'), $options['provider'] ?? null);
        $requestedProvider = $this->firstString(data_get($payload, 'requested_provider'));
        $operatorRequestedProvider = $this->firstString(data_get($payload, 'operator_requested_provider'));

        if (! $decisionMode && ! $selectedProvider && ! $requestedProvider && ! $operatorRequestedProvider) {
            return null;
        }

        $decisionMode ??= $requestedProvider ? 'manual_override' : 'atlas_decide';
        $manualOverride = $decisionMode !== 'atlas_decide';

        return [
            'schema_version' => 'atlas.provider_governance.v1',
            'surface' => data_get($payload, 'app_surface') ?: data_get($payload, 'surface') ?: 'unknown',
            'workflow_mode' => $this->workflowMode($payload),
            'decision_mode' => $decisionMode,
            'decision_authority' => $manualOverride ? 'operator_override' : 'atlas_decide',
            'model_selection_authority' => data_get($payload, 'model_selection_contract.authority') ?: 'atlas_decide',
            'operator_requested_provider' => $operatorRequestedProvider ?: ($manualOverride ? $requestedProvider : 'auto'),
            'requested_provider' => $requestedProvider,
            'candidate_provider' => $this->firstString(data_get($payload, 'candidate_provider')),
            'execution_provider' => $selectedProvider,
            'selected_provider' => $selectedProvider,
            'fallback_provider' => $this->firstString(data_get($payload, 'fallback_provider')),
            'fallback_reason' => $this->firstString(data_get($payload, 'fallback_reason')),
            'manual_override' => $manualOverride,
            'fair_mode' => (bool) data_get($payload, 'fair_mode.fair_mode'),
            'separation_contract' => [
                'atlas_decide_is_decision_layer' => ! $manualOverride,
                'provider_is_executor_only' => true,
                'provider_may_not_be_treated_as_atlas_identity' => true,
                'manual_override_must_remain_visible' => $manualOverride,
            ],
        ];
    }

    private function workflowMode(array $payload): ?string
    {
        return $this->normalizeKey($this->firstString(
            data_get($payload, 'atlas_workflow_mode'),
            data_get($payload, 'workflow_mode'),
            data_get($payload, 'open_brain.workflow_mode'),
        ));
    }

    private function threadMode(array $payload, array $options): ?string
    {
        return $this->normalizeMode($this->firstString(
            data_get($payload, 'atlas_mode'),
            data_get($payload, 'current_mode'),
        ))
            ?? $this->modeFromWorkflow($this->workflowMode($payload))
            ?? $this->modeFromRoutingTask($this->firstString(data_get($payload, 'routing_task')))
            ?? $this->modeFromAgent($this->requestedAgent($payload, $options))
            ?? $this->modeFromProgrammingContract($payload);
    }

    private function threadFocus(array $payload, ?string $mode): ?string
    {
        return $this->normalizeKey($this->firstString(
            data_get($payload, 'atlas_focus'),
            data_get($payload, 'current_focus'),
        )) ?? match ($mode) {
            'programming' => 'programming',
            'operational' => 'operational',
            default => null,
        };
    }

    private function routingTask(array $payload, ?string $workflowMode): ?string
    {
        return $this->normalizeKey($this->firstString(data_get($payload, 'routing_task')))
            ?? ($this->modeFromWorkflow($workflowMode) === 'programming' ? $workflowMode : null);
    }

    private function routingDomain(array $payload, ?string $mode): ?string
    {
        return $this->normalizeKey($this->firstString(data_get($payload, 'routing_domain')))
            ?? ($mode === 'programming' ? 'programming' : null);
    }

    private function normalizeMode(?string $mode): ?string
    {
        $mode = $this->normalizeKey($mode);

        return match ($mode) {
            'dev', 'debug', 'execute', 'quality_repair', 'programacao', 'programming' => 'programming',
            'operacional', 'operational', 'research', 'pesquisa' => 'operational',
            default => $mode,
        };
    }

    private function modeFromWorkflow(?string $workflowMode): ?string
    {
        return match ($workflowMode) {
            'dev', 'debug', 'execute', 'quality_repair' => 'programming',
            default => null,
        };
    }

    private function modeFromRoutingTask(?string $routingTask): ?string
    {
        return match ($this->normalizeKey($routingTask)) {
            'dev', 'debug', 'execute', 'quality_repair' => 'programming',
            default => null,
        };
    }

    private function modeFromAgent(?string $agent): ?string
    {
        $agent = $this->normalizeKey($agent);
        if (! $agent) {
            return null;
        }

        return match ($agent) {
            'desenvolvedor', 'engenheiro', 'programador', 'developer', 'programmer', 'engineer', 'coder' => 'programming',
            'pesquisador', 'analista', 'consultor', 'researcher', 'analyst', 'advisor' => 'operational',
            default => str_contains($agent, 'dev') || str_contains($agent, 'code') || str_contains($agent, 'program')
                ? 'programming'
                : null,
        };
    }

    private function modeFromProgrammingContract(array $payload): ?string
    {
        $flow = $this->normalizeKey($this->firstString(
            data_get($payload, 'programming_chat_contract.programming_flow'),
            data_get($payload, 'programming_dispatch.flow'),
            data_get($payload, 'kernel_pipeline.input.safe_hints.flow'),
        ));

        return $flow && str_starts_with($flow, 'programming') ? 'programming' : null;
    }

    private function normalizeKey(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $normalized = Str::of($value)
            ->ascii()
            ->lower()
            ->replace([' ', '-', '.'], '_')
            ->trim('_')
            ->value();

        return $normalized !== '' ? $normalized : null;
    }

    private function workspace(array $payload): ?string
    {
        $workspace = $this->firstString(data_get($payload, 'workspace'), config('atlas.ai.workdir'));

        return $workspace ? Str::limit($workspace, 500, '') : null;
    }

    private function isShortReference(string $input): bool
    {
        $text = Str::of($input)->lower()->trim()->value();
        $normalized = preg_replace('/\s+/', ' ', $text) ?: '';
        if ($normalized === '' || mb_strlen($normalized) > 80) {
            return false;
        }

        $exact = [
            'a',
            'b',
            'c',
            'ambos',
            'as duas',
            'os dois',
            'isso',
            'esse',
            'essa',
            'continua',
            'continue',
            'sim',
            'nao',
            'não',
        ];

        return in_array($normalized, $exact, true)
            || str_starts_with($normalized, 'faz isso')
            || str_starts_with($normalized, 'segue')
            || str_starts_with($normalized, 'continua');
    }

    private function firstString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
