<?php

namespace App\Console\Commands;

use App\Services\Ai\AiProviderModelResolver;
use App\Services\Ai\AtlasAiRuntimeSettings;
use App\Services\Ai\AtlasDecideService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AtlasAiDecideCommand extends Command
{
    private const INVOCATION_PROVIDERS = ['claude_cli', 'codex_cli', 'gemini_cli'];

    protected $signature = 'atlas:ai:decide
        {input : Prompt or task description to route}
        {--provider= : auto, claude, codex, gemini, conselho, claude_cli, codex_cli, gemini_cli or claude_codex}
        {--model= : Optional explicit model id}
        {--mode=direct : direct, plan, review, dev, debug or research}
        {--agent= : Optional Atlas agent slug}
        {--source=manual : Source type for the decision preview}
        {--workspace= : Workspace path passed by the Atlas CLI wrapper}
        {--json : Print machine-readable JSON}';

    protected $description = 'Preview the Atlas Decide routing decision without running a provider.';

    public function handle(
        AtlasDecideService $decide,
        AtlasAiRuntimeSettings $settings,
        AiProviderModelResolver $models,
    ): int {
        $input = trim((string) $this->argument('input'));
        if ($input === '') {
            $this->error('Input vazio.');

            return self::FAILURE;
        }

        $provider = $this->providerKey($this->option('provider') ?: null);
        $mode = $this->workflowMode((string) $this->option('mode'));
        $payload = [
            'atlas_workflow_mode' => $mode,
            'decision_mode' => $provider ? 'manual_override' : 'atlas_decide',
            'operator_requested_provider' => $provider ?: 'auto',
            'requested_provider' => $provider,
            'requested_agent' => $this->option('agent') ?: null,
            'workspace' => $this->option('workspace') ?: null,
        ];

        if (in_array($mode, ['research', 'analysis'], true)) {
            $payload['context_strategy_hint'] = 'long_context';
        }

        $options = $decide->normalizeOptions([
            'input_text' => $input,
            'provider' => $provider,
            'model' => $this->option('model') ?: null,
            'source_type' => $this->option('source') ?: 'manual',
            'agent_slug' => $this->option('agent') ?: null,
            'mode' => $mode,
            'payload' => $payload,
        ]);

        $candidate = $decide->candidateProvider($options, $settings->defaultProvider());
        $selectedProvider = $this->providerAllowedForPreview($candidate, $options, $decide, $settings);
        $modelResolution = $models->resolveWithSource($selectedProvider, $this->option('model') ?: null);
        $fallbackReason = $candidate !== $selectedProvider
            ? $this->fallbackReason($candidate, $selectedProvider, $options, $decide, $settings)
            : null;
        $options = $this->optionsWithSelectionMetadata($options, $candidate, $selectedProvider, $fallbackReason);
        $receipt = $decide->receiptForTrace($options, $selectedProvider, $modelResolution['model']);
        $plan = $decide->decisionPlan($options, $selectedProvider, $modelResolution['model']);
        $decision = [
            ...$receipt,
            ...$plan,
            'policy_version' => (string) data_get($options, 'payload.atlas_decide.policy_version', 'atlas-decide-v1'),
            'route_mode' => $mode,
            'candidate_provider' => $candidate,
            'fallback_provider' => $candidate !== $selectedProvider ? $selectedProvider : null,
            'fallback_reason' => $fallbackReason,
            'selected_model_label' => $modelResolution['model_label'] ?? $modelResolution['model'],
            'model_identity_source' => $modelResolution['source'] ?? 'unresolved',
            'confidence_score' => $this->confidenceScore($decide, $options, $selectedProvider),
            'candidates' => $this->candidates($settings, $models, $selectedProvider, $options, $decide),
            'constraints' => $this->constraints($settings, $options, $selectedProvider, $decide),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['decision' => $decision], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Atlas Decide');
        $this->table(['campo', 'valor'], [
            ['modo', $decision['decision_mode']],
            ['provider selecionado', $decision['selected_provider']],
            ['modelo', $decision['selected_model_label']],
            ['pedido do operador', $decision['operator_requested_provider']],
            ['override manual', $decision['was_overridden'] ? 'sim' : 'nao'],
            ['candidato inicial', $decision['candidate_provider']],
            ['fallback aplicado', $decision['fallback_provider'] ?: '-'],
            ['estrategia contexto', $decision['context_strategy']],
            ['estrategia execucao', $decision['execution_strategy']],
            ['confianca', (string) $decision['confidence_score']],
            ['motivo', $decision['reason']],
        ]);

        return self::SUCCESS;
    }

    private function optionsWithSelectionMetadata(array $options, string $candidateProvider, string $selectedProvider, ?string $fallbackReason): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $payload['atlas_decide'] = array_merge(
            is_array($payload['atlas_decide'] ?? null) ? $payload['atlas_decide'] : [],
            [
                'candidate_provider' => $candidateProvider,
                'selected_provider' => $selectedProvider,
                'fallback_provider' => $fallbackReason ? $selectedProvider : null,
                'fallback_reason' => $fallbackReason,
            ],
        );
        $options['payload'] = $payload;

        return $options;
    }

    private function fallbackReason(
        string $candidateProvider,
        string $selectedProvider,
        array $options,
        AtlasDecideService $decide,
        AtlasAiRuntimeSettings $settings,
    ): string {
        if ($candidateProvider === $selectedProvider) {
            return 'none';
        }

        if ($candidateProvider === 'gemini_cli' && $decide->isProgrammingLikeTask($options)) {
            return 'gemini_blocked_for_dev_like_task';
        }

        if ($decide->manualOverrideProvider($options) === null
            && ! (bool) ($settings->providerConfig($candidateProvider)['allow_auto'] ?? true)
        ) {
            return 'candidate_auto_disabled';
        }

        if ($decide->manualOverrideProvider($options) !== null
            && ! (bool) ($settings->providerConfig($candidateProvider)['allow_manual'] ?? true)
        ) {
            return 'candidate_manual_disabled';
        }

        return 'provider_gate_fallback';
    }

    private function providerKey(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return match (Str::of($value)->lower()->replace('-', '_')->trim()->value()) {
            'auto', 'atlas', 'atlas_decide' => null,
            'claude', 'claude_cli' => 'claude_cli',
            'codex', 'codex_cli' => 'codex_cli',
            'gemini', 'gemini_cli' => 'gemini_cli',
            'conselho', 'council', 'claude_codex' => 'claude_codex',
            default => null,
        };
    }

    private function workflowMode(string $value): string
    {
        $value = Str::of($value)->lower()->trim()->value();

        return in_array($value, ['direct', 'plan', 'review', 'dev', 'debug', 'research', 'analysis'], true)
            ? $value
            : 'direct';
    }

    private function providerAllowedForPreview(
        string $candidate,
        array $options,
        AtlasDecideService $decide,
        AtlasAiRuntimeSettings $settings,
    ): string {
        $manualProvider = $decide->manualOverrideProvider($options);

        if ($manualProvider !== null) {
            return (bool) ($settings->providerConfig($candidate)['allow_manual'] ?? true)
                ? $candidate
                : $this->manualFallbackProvider($candidate, $settings);
        }

        if ($candidate === 'gemini_cli' && $decide->isProgrammingLikeTask($options)) {
            return (string) ($settings->providerConfig('gemini_cli')['fallback_provider'] ?? 'claude_cli');
        }

        if ((bool) ($settings->providerConfig($candidate)['allow_auto'] ?? true)) {
            return $candidate;
        }

        return $this->automaticFallbackProvider($settings, $options, $decide);
    }

    private function manualFallbackProvider(string $provider, AtlasAiRuntimeSettings $settings): string
    {
        $default = $settings->defaultProvider();
        if ($default !== $provider && $default !== 'claude_codex' && (bool) ($settings->providerConfig($default)['allow_manual'] ?? true)) {
            return $default;
        }

        return $provider === 'claude_cli' ? 'codex_cli' : 'claude_cli';
    }

    private function automaticFallbackProvider(AtlasAiRuntimeSettings $settings, array $options, AtlasDecideService $decide): string
    {
        $default = $settings->defaultProvider();
        if ($default !== 'claude_codex'
            && ! ($default === 'gemini_cli' && $decide->isProgrammingLikeTask($options))
            && (bool) ($settings->providerConfig($default)['allow_auto'] ?? true)
        ) {
            return $default;
        }

        return 'claude_cli';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function candidates(
        AtlasAiRuntimeSettings $settings,
        AiProviderModelResolver $models,
        string $selectedProvider,
        array $options,
        AtlasDecideService $decide,
    ): array {
        $manualProvider = $decide->manualOverrideProvider($options);

        return collect(self::INVOCATION_PROVIDERS)
            ->map(fn (string $provider): array => [
                'provider' => $provider,
                'selected' => $provider === $selectedProvider,
                'manual_override' => $manualProvider === $provider,
                'allow_auto' => (bool) ($settings->providerConfig($provider)['allow_auto'] ?? true),
                'allow_manual' => (bool) ($settings->providerConfig($provider)['allow_manual'] ?? true),
                'model' => $models->resolveWithSource($provider)['model'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function constraints(
        AtlasAiRuntimeSettings $settings,
        array $options,
        string $selectedProvider,
        AtlasDecideService $decide,
    ): array {
        return [
            'selected_provider_allow_auto' => (bool) ($settings->providerConfig($selectedProvider)['allow_auto'] ?? true),
            'selected_provider_allow_manual' => (bool) ($settings->providerConfig($selectedProvider)['allow_manual'] ?? true),
            'gemini_blocked_for_dev_like_task' => $decide->isProgrammingLikeTask($options),
            'needs_long_context_or_multimodal_provider' => $decide->needsLongContextOrMultimodalProvider($options),
            'budget_enabled' => (bool) data_get($settings->effective(), 'budget.enabled', false),
        ];
    }

    private function confidenceScore(AtlasDecideService $decide, array $options, string $provider): int
    {
        if ($decide->manualOverrideProvider($options)) {
            return 100;
        }

        if ($provider === 'gemini_cli' && $decide->needsLongContextOrMultimodalProvider($options)) {
            return 88;
        }

        if ($provider === 'codex_cli' && $decide->isProgrammingLikeTask($options)) {
            return 86;
        }

        return 74;
    }
}
