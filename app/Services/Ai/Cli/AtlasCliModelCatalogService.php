<?php

namespace App\Services\Ai\Cli;

use App\Services\Ai\AtlasAiRuntimeSettings;

class AtlasCliModelCatalogService
{
    public function __construct(private readonly AtlasAiRuntimeSettings $settings) {}

    /**
     * @return array{model:string,label:string,tier:string,provider:?string,source:string,alias:string}|null
     */
    public function select(?string $value, ?string $currentProvider = null): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $raw = trim($value);
        $normalized = $this->normalizeAlias($raw);
        if (in_array($normalized, ['default', 'padrao', 'auto', 'atlas'], true)) {
            return null;
        }

        foreach ($this->catalog() as $item) {
            $aliases = array_map(fn (string $alias): string => $this->normalizeAlias($alias), $item['aliases']);
            $aliases[] = $this->normalizeAlias($item['alias']);
            $aliases[] = $this->normalizeAlias($item['model']);
            $aliases[] = $this->normalizeAlias($item['label']);

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

    /**
     * @param  array<string,mixed>  $selection
     */
    public function matchesProvider(array $selection, ?string $provider): bool
    {
        $selectionProvider = is_string($selection['provider'] ?? null) ? $selection['provider'] : null;
        if ($selectionProvider === null) {
            return true;
        }

        return $provider === $selectionProvider;
    }

    /**
     * @param  array<string,mixed>  $selection
     */
    public function label(array $selection): string
    {
        $model = (string) ($selection['model'] ?? '');
        $label = (string) ($selection['label'] ?? $model);
        $tier = (string) ($selection['tier'] ?? 'manual');
        $provider = $this->providerDisplayName(is_string($selection['provider'] ?? null) ? $selection['provider'] : null);

        return "{$label} ({$model}, {$tier}, {$provider})";
    }

    /**
     * @return list<array{alias:string,provider:string,model:string,label:string,tier:string,source:string,description:string,aliases:list<string>}>
     */
    public function catalog(): array
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

    public function providerDisplayName(?string $provider): string
    {
        return match ($provider) {
            'claude_cli' => 'Claude',
            'codex_cli' => 'Codex',
            'gemini_cli' => 'Gemini',
            'claude_codex' => 'Conselho',
            default => 'padrao',
        };
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
        $config = $this->settings->providerConfig($provider);
        $model = $this->cleanModelString($config['model'] ?? null) ?: $this->cleanModelString($config['model_identity'] ?? null);
        if ($model === null || str_ends_with($model, '_default')) {
            return null;
        }

        return [
            'model' => $model,
            'label' => $this->cleanModelString($config['model_label'] ?? null) ?: $model,
            'tier' => $this->cleanModelString($config['model_tier'] ?? null) ?: $this->settings->defaultTier(),
        ];
    }

    /**
     * @return array{model:string,label:string,tier:string}|null
     */
    private function providerNamedModel(string $provider, string $modelKey, string $labelKey): ?array
    {
        $config = $this->settings->providerConfig($provider);
        $model = $this->cleanModelString($config[$modelKey] ?? null);
        if ($model === null || str_ends_with($model, '_default')) {
            return null;
        }

        return [
            'model' => $model,
            'label' => $this->cleanModelString($config[$labelKey] ?? null) ?: $model,
            'tier' => $modelKey === 'premium_model'
                ? 'premium'
                : ($this->cleanModelString($config['model_tier'] ?? null) ?: $this->settings->defaultTier()),
        ];
    }

    private function inferProviderFromModel(string $model): ?string
    {
        $normalized = $this->normalizeAlias($model);

        return match (true) {
            str_contains($normalized, 'claude') || str_contains($normalized, 'opus') || str_contains($normalized, 'sonnet') || str_contains($normalized, 'haiku') => 'claude_cli',
            str_contains($normalized, 'gpt') || str_contains($normalized, 'codex') => 'codex_cli',
            str_contains($normalized, 'gemini') => 'gemini_cli',
            default => null,
        };
    }

    private function cleanModelString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeAlias(string $value): string
    {
        return str_replace(['_', '.', ' '], '-', strtolower(trim($value)));
    }
}
