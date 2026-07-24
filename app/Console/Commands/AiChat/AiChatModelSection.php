<?php

namespace App\Console\Commands\AiChat;

use App\Console\Commands\AiChatCommand;
use App\Services\Ai\Cli\AtlasCliDevWorkflowService;
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
    public function __construct(private readonly AiChatCommand $command)
    {
    }

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

    /**
     * @return list<array{alias:string,provider:string,model:string,label:string,tier:string,source:string,description:string,aliases:list<string>}>
     */
    private function modelCatalog(): array
    {
        $rows = [];

        $this->appendModelCatalogRow(
            $rows,
            'sonnet',
            'claude_cli',
            $this->providerConfiguredModel('claude_cli'),
            'default',
            'Claude diario',
            ['sonnet', 'sonnet-4.6', 'sonnet-4', 'claude-sonnet', 'claude-sonnet-4-6', 'claude'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'spark',
            'codex_cli',
            $this->providerConfiguredModel('codex_cli'),
            'default',
            'Codex diario',
            ['spark', 'codex-spark', 'gpt-5.3-codex-spark', 'gpt-5.3', 'codex'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'gemini_flash',
            'gemini_cli',
            [
                'model' => (string) config('atlas.ai.providers.gemini_cli.models.gemini_flash.model', 'gemini-3.5-flash'),
                'label' => (string) config('atlas.ai.providers.gemini_cli.models.gemini_flash.label', 'Gemini Flash'),
                'tier' => (string) config('atlas.ai.providers.gemini_cli.models.gemini_flash.tier', 'daily'),
            ],
            'default',
            'Gemini rapido',
            ['gemini', 'gemini-flash', 'gemini_flash', 'gemini-3.5-flash', 'gemini-3-5-flash'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'gemini_pro',
            'gemini_cli',
            [
                'model' => (string) config('atlas.ai.providers.gemini_cli.models.gemini_pro.model', 'gemini-3.1-pro-preview'),
                'label' => (string) config('atlas.ai.providers.gemini_cli.models.gemini_pro.label', 'Gemini Pro'),
                'tier' => (string) config('atlas.ai.providers.gemini_cli.models.gemini_pro.tier', 'premium'),
            ],
            'premium',
            'Gemini raciocinio profundo',
            ['gemini-pro', 'gemini_pro', 'gemini-3.1-pro-preview', 'gemini-3-1-pro'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'hermes',
            'hermes_cli',
            $this->providerConfiguredModel('hermes_cli'),
            'default',
            'Hermes executive runtime',
            ['hermes', 'hermes-cli', 'hermes-runtime'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'haiku',
            'claude_cli',
            $this->providerNamedModel('claude_cli', 'fallback_model', 'fallback_model_label'),
            'fallback',
            'Claude economico',
            ['haiku', 'claude-haiku', 'fallback-claude'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'mini',
            'codex_cli',
            $this->providerNamedModel('codex_cli', 'fallback_model', 'fallback_model_label'),
            'fallback',
            'Codex economico',
            ['mini', 'codex-mini', 'gpt-5.4-mini', 'fallback-codex'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'opus',
            'claude_cli',
            $this->providerNamedModel('claude_cli', 'premium_model', 'premium_model_label'),
            'premium',
            'Claude premium manual',
            ['opus', 'opus-4.7', 'claude-opus', 'claude-opus-4-7', 'claude-premium'],
        );
        $this->appendModelCatalogRow(
            $rows,
            'codex-premium',
            'codex_cli',
            $this->providerNamedModel('codex_cli', 'premium_model', 'premium_model_label'),
            'premium',
            'Codex premium manual',
            ['5.5', '55', 'codex-premium', 'codex-5.5', 'gpt-5.5', 'gpt-premium', 'premium-codex'],
        );

        return $rows;
    }

    /**
     * @param  list<array{alias:string,provider:string,model:string,label:string,tier:string,source:string,description:string,aliases:list<string>}>  $rows
     * @param  array{model:string,label:string,tier:string}|null  $model
     * @param  list<string>  $aliases
     */
    private function appendModelCatalogRow(array &$rows, string $alias, string $provider, ?array $model, string $source, string $description, array $aliases): void
    {
        if ($model === null || $model['model'] === '') {
            return;
        }

        foreach ($rows as $row) {
            if ($row['provider'] === $provider && $row['model'] === $model['model']) {
                return;
            }
        }

        $rows[] = [
            'alias' => $alias,
            'provider' => $provider,
            'model' => $model['model'],
            'label' => $model['label'],
            'tier' => $model['tier'],
            'source' => $source,
            'description' => $description,
            'aliases' => array_values(array_unique($aliases)),
        ];
    }

    /**
     * @return array{model:string,label:string,tier:string}|null
     */
    private function providerConfiguredModel(string $provider): ?array
    {
        $config = app(AtlasAiRuntimeSettings::class)->providerConfig($provider);
        $model = $this->cleanModelString($config['model'] ?? null) ?: $this->cleanModelString($config['model_identity'] ?? null);
        if ($model === null || str_ends_with($model, '_default')) {
            return null;
        }

        return [
            'model' => $model,
            'label' => $this->cleanModelString($config['model_label'] ?? null) ?: $model,
            'tier' => $this->cleanModelString($config['model_tier'] ?? null) ?: app(AtlasAiRuntimeSettings::class)->defaultTier(),
        ];
    }

    /**
     * @return array{model:string,label:string,tier:string}|null
     */
    private function providerNamedModel(string $provider, string $modelKey, string $labelKey): ?array
    {
        $config = app(AtlasAiRuntimeSettings::class)->providerConfig($provider);
        $model = $this->cleanModelString($config[$modelKey] ?? null);
        if ($model === null || str_ends_with($model, '_default')) {
            return null;
        }

        return [
            'model' => $model,
            'label' => $this->cleanModelString($config[$labelKey] ?? null) ?: $model,
            'tier' => $modelKey === 'premium_model'
                ? 'premium'
                : ($this->cleanModelString($config['model_tier'] ?? null) ?: app(AtlasAiRuntimeSettings::class)->defaultTier()),
        ];
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

    public function providerDisplayName(?string $provider): string
    {
        return match ($provider) {
            'claude_cli' => 'Claude',
            'codex_cli' => 'Codex',
            'gemini_cli' => 'Gemini',
            'hermes_cli' => 'Hermes',
            'claude_codex' => 'Conselho',
            default => 'padrao',
        };
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

    private function cleanModelString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? Str::limit($value, 120, '') : null;
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
}
