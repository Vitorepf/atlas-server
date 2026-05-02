<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

class AtlasDecideService
{
    private const PROVIDERS = ['claude_cli', 'codex_cli', 'gemini_cli'];
    private const COUNCIL_PROVIDER = 'claude_codex';

    /**
     * Normalizes legacy app/CLI payloads into the new Atlas Decide contract.
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function normalizeOptions(array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $decisionMode = $this->cleanDecisionMode(data_get($payload, 'decision_mode'));
        $operatorRequested = $this->providerOrAuto(data_get($payload, 'operator_requested_provider'));
        $requestedProvider = $this->providerOrCouncil(data_get($payload, 'requested_provider'));
        $topLevelProvider = $this->providerOrCouncil($options['provider'] ?? null);

        if ($operatorRequested === null) {
            $operatorRequested = $this->providerOrAuto(data_get($payload, 'requested_provider'));
        }

        $manualProvider = $this->manualOverrideProviderFromParts(
            $decisionMode,
            $operatorRequested,
            $requestedProvider,
            $topLevelProvider,
        );

        if ($manualProvider !== null) {
            $payload['decision_mode'] = 'manual_override';
            $payload['requested_provider'] = $manualProvider;
            $payload['operator_requested_provider'] = $operatorRequested && $operatorRequested !== 'auto'
                ? $operatorRequested
                : $manualProvider;
            $options['provider'] = $manualProvider;
        } else {
            $payload['decision_mode'] = 'atlas_decide';
            $payload['operator_requested_provider'] = $operatorRequested ?: 'auto';
            unset($payload['requested_provider']);
            unset($options['provider']);
        }

        $payload['atlas_decide'] = array_merge(
            is_array($payload['atlas_decide'] ?? null) ? $payload['atlas_decide'] : [],
            [
                'schema_version' => 1,
                'decision_mode' => $payload['decision_mode'],
                'operator_requested_provider' => $payload['operator_requested_provider'],
            ],
        );

        $options['payload'] = $payload;

        return $options;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function decisionMode(array $options): string
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return $this->cleanDecisionMode(data_get($payload, 'decision_mode')) ?? 'atlas_decide';
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function manualOverrideProvider(array $options): ?string
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return $this->manualOverrideProviderFromParts(
            $this->cleanDecisionMode(data_get($payload, 'decision_mode')),
            $this->providerOrAuto(data_get($payload, 'operator_requested_provider')),
            $this->providerOrCouncil(data_get($payload, 'requested_provider')),
            $this->providerOrCouncil($options['provider'] ?? null),
        );
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function candidateProvider(array $options, string $defaultProvider): string
    {
        if ($manual = $this->manualOverrideProvider($options)) {
            return $manual;
        }

        if ($this->isProgrammingTask($options)) {
            return 'codex_cli';
        }

        if ($this->needsLongContextProvider($options)) {
            return 'gemini_cli';
        }

        return in_array($defaultProvider, self::PROVIDERS, true) ? $defaultProvider : 'claude_cli';
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function isProgrammingLikeTask(array $options): bool
    {
        return $this->isProgrammingTask($options);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function needsLongContextOrMultimodalProvider(array $options): bool
    {
        return $this->needsLongContextProvider($options);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public function decisionReason(array $options, string $selectedProvider): string
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $candidateProvider = data_get($payload, 'atlas_decide.candidate_provider');
        if (is_string($candidateProvider) && $candidateProvider !== '' && $candidateProvider !== $selectedProvider) {
            $fallbackReason = data_get($payload, 'atlas_decide.fallback_reason');
            $suffix = is_string($fallbackReason) && $fallbackReason !== ''
                ? " ({$fallbackReason})"
                : '';

            return "Atlas Decide avaliou {$candidateProvider}, mas selecionou {$selectedProvider} por fallback{$suffix}.";
        }

        if ($this->manualOverrideProvider($options)) {
            return "Provider {$selectedProvider} selecionado por override manual do operador.";
        }

        if ($this->isProgrammingTask($options)) {
            return "Atlas Decide selecionou {$selectedProvider} para tarefa de programacao.";
        }

        if ($this->needsLongContextProvider($options)) {
            return "Atlas Decide selecionou {$selectedProvider} por contexto longo, anexos, pesquisa ou entrada multimodal.";
        }

        return "Atlas Decide selecionou {$selectedProvider} pela politica padrao efetiva.";
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function receiptForTrace(array $options, string $selectedProvider, ?string $model = null): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $manualProvider = $this->manualOverrideProvider($options);

        return [
            'schema_version' => 1,
            'decision_mode' => $this->decisionMode($options),
            'candidate_provider' => data_get($payload, 'atlas_decide.candidate_provider'),
            'selected_provider' => $selectedProvider,
            'selected_model' => $model,
            'fallback_reason' => data_get($payload, 'atlas_decide.fallback_reason'),
            'operator_requested_provider' => data_get($payload, 'operator_requested_provider') ?: 'auto',
            'requested_provider' => $manualProvider,
            'was_overridden' => $manualProvider !== null,
            'reason' => $this->decisionReason($options, $selectedProvider),
            'signals' => $this->signals($options),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function signals(array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];

        return [
            'atlas_workflow_mode' => data_get($payload, 'atlas_workflow_mode'),
            'routing_task' => data_get($payload, 'routing_task'),
            'routing_domain' => data_get($payload, 'routing_domain'),
            'task_type' => data_get($payload, 'task_type'),
            'has_visual_input' => data_get($payload, 'visual_input.image_count') !== null,
            'has_file_input' => data_get($payload, 'file_input.file_count') !== null,
            'attachment_count' => is_array(data_get($payload, 'attachments')) ? count(data_get($payload, 'attachments')) : 0,
            'context_strategy_hint' => data_get($payload, 'context_strategy_hint'),
            'source_type' => $options['source_type'] ?? null,
        ];
    }

    private function manualOverrideProviderFromParts(
        ?string $decisionMode,
        ?string $operatorRequested,
        ?string $requestedProvider,
        ?string $topLevelProvider,
    ): ?string {
        if ($operatorRequested !== null && $operatorRequested !== 'auto') {
            return $operatorRequested;
        }

        if ($decisionMode === 'manual_override') {
            return $requestedProvider ?: $topLevelProvider;
        }

        if ($operatorRequested === 'auto') {
            return null;
        }

        return $requestedProvider ?: $topLevelProvider;
    }

    private function cleanDecisionMode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return in_array($value, ['atlas_decide', 'manual_override'], true) ? $value : null;
    }

    private function providerOrCouncil(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === self::COUNCIL_PROVIDER) {
            return $value;
        }

        return in_array($value, self::PROVIDERS, true) ? $value : null;
    }

    private function providerOrAuto(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === 'auto') {
            return 'auto';
        }

        return $this->providerOrCouncil($value);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function isProgrammingTask(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $workflowMode = Str::lower(trim((string) data_get($payload, 'atlas_workflow_mode', '')));
        $routingTask = Str::lower(trim((string) data_get($payload, 'routing_task', '')));
        $taskType = Str::lower(trim((string) data_get($payload, 'task_type', '')));
        $agent = Str::lower(trim((string) ($options['agent_slug'] ?? data_get($payload, 'requested_agent', ''))));

        return in_array($workflowMode, ['dev', 'debug', 'execute', 'quality_repair'], true)
            || in_array($routingTask, ['dev', 'debug'], true)
            || in_array($taskType, ['dev', 'debug', 'code', 'coding', 'programming', 'quality_repair'], true)
            || in_array($agent, ['desenvolvedor', 'developer', 'debugger'], true);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function needsLongContextProvider(array $options): bool
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $workflowMode = Str::lower(trim((string) data_get($payload, 'atlas_workflow_mode', '')));
        $routingTask = Str::lower(trim((string) data_get($payload, 'routing_task', '')));
        $taskType = Str::lower(trim((string) data_get($payload, 'task_type', '')));
        $hint = Str::lower(trim((string) data_get($payload, 'context_strategy_hint', '')));
        $input = Str::lower(trim((string) ($options['input_text'] ?? '')));

        return data_get($payload, 'visual_input.image_count') !== null
            || data_get($payload, 'file_input.file_count') !== null
            || is_array(data_get($payload, 'attachments'))
            || in_array($workflowMode, ['research', 'analysis'], true)
            || in_array($routingTask, ['research'], true)
            || in_array($taskType, ['research', 'analysis', 'long_context', 'multimodal'], true)
            || in_array($hint, ['long_context', 'multimodal', 'long_context_or_multimodal'], true)
            || str_contains($input, 'pesquisa')
            || str_contains($input, 'pesquise')
            || str_contains($input, 'research');
    }
}
