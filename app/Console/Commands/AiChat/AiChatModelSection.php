<?php

namespace App\Console\Commands\AiChat;

use App\Console\Commands\AiChatCommand;
use App\Services\Ai\Cli\AtlasCliDevWorkflowService;
use App\Services\Ai\Cli\AtlasCliModelCatalogService;
use App\Services\Ai\Cli\AtlasTerminalTheme;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Kernel\Decision\ComputeEffortPolicy;
use App\Services\Ai\Policy\AtlasAiRuntimeSettings;
use App\Services\Ai\Provider\ProviderCatalog;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;

/**
 * Model catalog, provider selection, fair-mode and policy helpers split verbatim from AiChatCommand (GOD-DEBULK).
 */
class AiChatModelSection
{
    public function __construct(private readonly AiChatCommand $command) {}

    /**
     * @return array{model:string,label:string,tier:string,provider:?string,source:string,alias:string}|null
     */
    public function modelSelection(?string $value, ?string $currentProvider = null): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $raw = trim($value);
        $normalized = $this->normalizeModelAlias($raw);
        if (in_array($normalized, ['default', 'padrao', 'auto', 'atlas'], true)) {
            return null;
        }

        foreach ($this->modelCatalog() as $item) {
            $aliases = array_map(fn (string $alias): string => $this->normalizeModelAlias($alias), $item['aliases']);
            $aliases[] = $this->normalizeModelAlias($item['alias']);
            $aliases[] = $this->normalizeModelAlias($item['model']);
            $aliases[] = $this->normalizeModelAlias($item['label']);
            if (in_array($normalized, array_values(array_unique($aliases)), true)) {
                return [
                    'model' => $item['model'],
                    'label' => $item['label'],
                    'tier' => $item['tier'],
                    'provider' => $item['provider'],
                    'source' => $item['source'],
                    'alias' => $item['alias'],
                ];
            }
        }

        return [
            'model' => $raw,
            'label' => $raw,
            'tier' => 'manual',
            'provider' => $this->inferProviderFromModel($raw) ?: $currentProvider,
            'source' => 'explicit',
            'alias' => $raw,
        ];
    }

    public function handleModelCommand(string $argument, ?string &$provider, ?array &$modelSelection): void
    {
        $argument = trim($argument);
        if ($argument === '') {
            $this->printModelCatalog($provider, $modelSelection);

            if (! $this->command->inputIsInteractive()) {
                $this->command->line('Use /model sonnet, /model spark, /model default ou /model <model-id>.');

                return;
            }

            $choices = $this->modelChoiceLabels();
            $argument = $this->modelAliasFromChoice((string) $this->command->choice('Modelo', $choices, $choices[0] ?? null));
        }

        $selection = $this->modelSelection($argument, $provider);
        if ($selection === null) {
            $modelSelection = null;
            $this->command->line('Modelo ativo: padrao do provider.');

            return;
        }

        $modelSelection = $selection;
        $selectionProvider = is_string($selection['provider'] ?? null) ? $selection['provider'] : null;
        if ($selectionProvider !== null && $provider !== $selectionProvider) {
            $provider = $selectionProvider;
            $this->command->line('Provider ajustado: '.$this->providerDisplayName($provider));
        }

        $this->command->line('Modelo ativo: '.$this->modelSelectionLabel($modelSelection));
    }

    public function computeEffortSelection(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return app(ComputeEffortPolicy::class)->normalize((string) $value);
    }

    public function handleEffortCommand(string $argument, ?string $current): ?string
    {
        $argument = trim($argument);
        if ($argument === '') {
            $this->command->line('Esforco atual: '.($current ?: 'balanced'));
            $this->command->line('Use /effort fast, /effort balanced, /effort deep ou /effort max.');

            return $current;
        }

        $effort = $this->computeEffortSelection($argument);
        if ($effort === null) {
            $this->command->warn('Esforco invalido. Use fast, balanced, deep ou max.');

            return $current;
        }

        $this->command->line('Esforco ativo: '.$effort);

        return $effort;
    }

    public function printModelCatalog(?string $provider, ?array $modelSelection = null): void
    {
        $decorated = $this->command->getOutput()->isDecorated();
        $this->command->newLine();
        $this->command->line(AtlasTerminalTheme::bold('Modelos Atlas CLI', $decorated));
        $this->command->line('Atual: '.$this->modelPanelValue($provider, $modelSelection, $decorated));
        $this->command->newLine();
        $this->command->line(sprintf('  %-14s %-12s %-36s %-10s %s', 'alias', 'provider', 'modelo', 'tier', 'label'));
        $this->command->line('  '.str_repeat('-', 88));

        foreach ($this->modelCatalog() as $item) {
            $active = $modelSelection !== null && ($modelSelection['model'] ?? null) === $item['model'] ? '*' : ' ';
            $this->command->line(sprintf(
                '%s %-14s %-12s %-36s %-10s %s',
                $active,
                $item['alias'],
                $this->providerDisplayName($item['provider']),
                Str::limit($item['model'], 36, ''),
                $item['tier'],
                $item['label'],
            ));
        }

        $this->command->newLine();
        $this->command->line('Use /model <alias>, /model default, ou /model <model-id> para um id explicito.');
        $this->command->newLine();
    }

    /**
     * @return list<string>
     */
    private function modelChoiceLabels(): array
    {
        $choices = ['default - usar modelo padrao do provider'];
        foreach ($this->modelCatalog() as $item) {
            $choices[] = $item['alias'].' - '.$item['label'].' ['.$this->providerDisplayName($item['provider']).', '.$item['tier'].']';
        }

        return $choices;
    }

    private function modelAliasFromChoice(string $choice): string
    {
        return trim(Str::before($choice, ' - '));
    }

    public function modelOverrideFromSelection(?array $modelSelection): ?string
    {
        $model = $modelSelection['model'] ?? null;
        if (! is_string($model) && ! is_numeric($model)) {
            return null;
        }

        $model = trim((string) $model);

        return $model !== '' ? $model : null;
    }

    public function modelSelectionLabel(array $modelSelection): string
    {
        $model = (string) ($modelSelection['model'] ?? '');
        $label = (string) ($modelSelection['label'] ?? $model);
        $tier = (string) ($modelSelection['tier'] ?? 'manual');
        $provider = $this->providerDisplayName(is_string($modelSelection['provider'] ?? null) ? $modelSelection['provider'] : null);

        return "{$label} ({$model}, {$tier}, {$provider})";
    }

    public function modelSelectionMatchesProvider(array $modelSelection, ?string $provider): bool
    {
        $selectionProvider = is_string($modelSelection['provider'] ?? null) ? $modelSelection['provider'] : null;
        if ($selectionProvider === null) {
            return true;
        }

        return $provider === $selectionProvider;
    }

    public function manualProviderAllowed(?string $provider, AtlasAiRuntimeSettings $settings): bool
    {
        if (! is_string($provider) || ! ProviderCatalog::isInvocationProvider($provider)) {
            return true;
        }

        return (bool) ($settings->providerConfig($provider)['allow_manual'] ?? true);
    }

    public function manualProviderBlocked(string $provider): int
    {
        $message = "Provider {$provider} esta bloqueado para uso manual pelas configuracoes do Atlas app.";
        if ((bool) $this->command->option('json')) {
            $this->command->line(json_encode(AtlasSecurity::redactArray([
                'ok' => false,
                'phase' => 'preflight',
                'error' => 'atlas_manual_provider_blocked',
                'provider' => $provider,
                'message' => $message,
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return AiChatCommand::FAILURE;
        }

        $this->command->error($message);

        return AiChatCommand::FAILURE;
    }

    /**
     * @param  array<string,mixed>|null  $devPlan
     * @return array<string,bool>
     */
    public function fairClaudeFlags(?array $devPlan = null): array
    {
        $devPlanFair = (bool) data_get($devPlan, 'fair_mode.fair_mode')
            || (bool) data_get($devPlan, 'operator_options.fair_mode');

        return [
            'claude_only' => (bool) $this->command->option('claude-only') || (bool) data_get($devPlan, 'operator_options.claude_only'),
            'single_provider' => (bool) $this->command->option('single-provider') || $devPlanFair || (bool) data_get($devPlan, 'operator_options.single_provider'),
            'no_decide' => (bool) $this->command->option('no-decide') || $devPlanFair || (bool) data_get($devPlan, 'operator_options.no_decide'),
            'fallback_disabled' => (bool) $this->command->option('fallback-disabled') || $devPlanFair || (bool) data_get($devPlan, 'operator_options.fallback_disabled'),
        ];
    }

    /**
     * @param  array<string,mixed>  $violation
     */
    public function fairModeViolation(array $violation): int
    {
        $payload = AtlasSecurity::redactArray([
            'ok' => false,
            'phase' => 'preflight',
            'error' => FairClaudePolicy::ERROR_CODE,
            'message' => (string) ($violation['message'] ?? 'Fair Claude mode violation.'),
            'fair_mode' => $violation['fair_mode'] ?? [],
            'details' => $violation['details'] ?? [],
        ]);

        if ((bool) $this->command->option('json')) {
            $this->command->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return AiChatCommand::FAILURE;
        }

        $this->command->error((string) $payload['message']);

        return AiChatCommand::FAILURE;
    }

    public function fairClaudePromptContract(string $prompt): string
    {
        return app(AtlasCliDevWorkflowService::class)
            ->fairClaudePromptContract($prompt);
    }

    public function modelPanelValue(?string $provider, ?array $modelSelection, bool $decorated): string
    {
        if ($modelSelection !== null) {
            $label = (string) ($modelSelection['label'] ?? $modelSelection['model'] ?? 'modelo fixado');
            $model = (string) ($modelSelection['model'] ?? '');
            $tier = (string) ($modelSelection['tier'] ?? 'manual');

            return $label.' '.AtlasTerminalTheme::muted('· '.$model.' · '.$tier.' · fixado', $decorated);
        }

        $provider = $provider ?: $this->defaultProviderKey();
        if ($provider === 'claude_codex') {
            return 'Claude + Codex council '.AtlasTerminalTheme::muted('· modelos por provider', $decorated);
        }

        $configured = $this->providerConfiguredModel($provider);
        if ($configured !== null) {
            return $configured['label'].' '.AtlasTerminalTheme::muted('· '.$configured['model'].' · '.$configured['tier'].' · padrao', $decorated);
        }

        return AtlasTerminalTheme::muted('padrao do provider', $decorated);
    }

    /**
     * @param  array<string,mixed>|null  $modelSelection
     * @return array<string,mixed>
     */
    public function aiPolicyOverride(?string $provider, ?array $modelSelection = null, ?string $modelOverride = null, bool $fairMode = false): array
    {
        if (! is_string($provider) || ! ProviderCatalog::isInvocationProvider($provider)) {
            return [];
        }

        if ($fairMode) {
            return app(FairClaudePolicy::class)->runtimeOverride($modelSelection, $modelOverride);
        }

        $inventory = ProviderCatalog::invocationProviders();
        $override = [
            'default_provider' => $provider,
            'enabled_providers' => $inventory,
            'disabled_providers' => [],
            'fallback_order' => array_values(array_unique([$provider, ...$inventory])),
            'allow_council' => false,
            'allow_multistage_graph' => false,
        ];

        $model = $modelOverride ?: (is_string($modelSelection['model'] ?? null) ? (string) $modelSelection['model'] : null);
        if (is_string($model) && trim($model) !== '') {
            $model = trim($model);
            $override['providers'][$provider] = array_filter([
                'model' => $model,
                'model_label' => is_string($modelSelection['label'] ?? null) ? (string) $modelSelection['label'] : null,
                'model_tier' => is_string($modelSelection['tier'] ?? null) ? (string) $modelSelection['tier'] : null,
                'model_identity' => $model,
                'allow_auto' => true,
                'allow_manual' => true,
            ], fn (mixed $value): bool => $value !== null);
            $override['allowed_models'][$provider] = [$model];
        }

        return $override;
    }

    private function inferProviderFromModel(string $model): ?string
    {
        $normalized = $this->normalizeModelAlias($model);
        if (Str::contains($normalized, ['claude', 'sonnet', 'haiku', 'opus'])) {
            return 'claude_cli';
        }
        if (Str::contains($normalized, ['gpt', 'codex', 'spark'])) {
            return 'codex_cli';
        }
        if (Str::contains($normalized, ['gemini'])) {
            return 'gemini_cli';
        }
        if (Str::contains($normalized, ['hermes'])) {
            return 'hermes_cli';
        }
        if (Str::contains($normalized, ['minimax', 'm3', 'm27'])) {
            return 'minimax_m27_cli';
        }

        return null;
    }

    private function normalizeModelAlias(string $value): string
    {
        $normalized = strtolower(trim(Str::ascii($value)));
        $normalized = str_replace(['_', ' '], '-', $normalized);
        $normalized = preg_replace('/-+/', '-', $normalized) ?? $normalized;

        return trim($normalized, '-');
    }

    public function providerKey(?string $provider): ?string
    {
        if ($provider === null || trim($provider) === '') {
            return null;
        }

        return match ($this->normalizeModelAlias($provider)) {
            'padrao', 'default', 'auto', 'atlas' => null,
            'claude', 'claude-cli' => 'claude_cli',
            'codex', 'codex-cli' => 'codex_cli',
            'gemini', 'gemini-cli' => 'gemini_cli',
            'hermes', 'hermes-cli' => 'hermes_cli',
            'minimax', 'minimax-m3', 'minimax-m27', 'minimax-m27-cli' => 'minimax_m27_cli',
            'conselho', 'council', 'ambos', 'claude-codex' => 'claude_codex',
            default => throw new \InvalidArgumentException("Provider invalido: {$provider}"),
        };
    }

    public function defaultProviderKey(): string
    {
        try {
            $provider = app(AtlasAiRuntimeSettings::class)->defaultProvider();

            return ProviderCatalog::isInvocationProvider($provider) ? $provider : 'hermes_cli';
        } catch (\InvalidArgumentException) {
            return 'hermes_cli';
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function modelCatalog(): array
    {
        return app(AtlasCliModelCatalogService::class)->catalog();
    }

    private function providerDisplayName(?string $provider): string
    {
        return app(AtlasCliModelCatalogService::class)->providerDisplayName($provider);
    }
}
