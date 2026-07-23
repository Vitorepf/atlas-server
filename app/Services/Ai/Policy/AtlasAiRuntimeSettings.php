<?php

namespace App\Services\Ai\Policy;

use App\Models\AtlasAiRuntimeSetting;
use App\Services\Ai\Support\DatabaseTableAvailability;

class AtlasAiRuntimeSettings
{
    public const KEY = 'default';

    public const DEFAULT_BUDGET_WINDOW_HOURS = 24;

    public const MAX_BUDGET_WINDOW_HOURS = 168;

    /**
     * @return array<string,mixed>
     */
    public function effective(): array
    {
        $row = $this->row();
        $stored = is_array($row?->value_json) ? $row->value_json : [];
        $providerKeys = $this->providerKeys();
        $providers = [];

        foreach ($providerKeys as $provider) {
            $providers[$provider] = $this->providerConfig($provider, $stored);
        }

        return [
            'source' => $row ? 'database' : 'config',
            'updated_at' => $row?->updated_at?->toJSON(),
            'default_provider_selection' => $this->normalizeDefaultProviderSelection($stored['default_provider_selection'] ?? null),
            'default_provider' => $this->normalizeProvider($stored['default_provider'] ?? config('atlas.ai.default_provider', 'hermes_cli')) ?: 'hermes_cli',
            'default_tier' => $this->cleanString($stored['default_tier'] ?? config('atlas.ai.default_tier', 'daily')) ?: 'daily',
            'council_allow_auto' => $this->boolValue($stored['council_allow_auto'] ?? config('atlas.ai.council_allow_auto', false)),
            'providers' => $providers,
            'budget' => $this->budgetConfig($stored),
        ];
    }

    public function defaultProvider(): string
    {
        $provider = $this->effective()['default_provider'] ?? 'hermes_cli';

        return in_array($provider, $this->providerKeys(), true) ? $provider : 'hermes_cli';
    }

    public function defaultProviderSelection(): string
    {
        return $this->normalizeDefaultProviderSelection($this->effective()['default_provider_selection'] ?? null);
    }

    public function defaultTier(): string
    {
        return (string) ($this->effective()['default_tier'] ?? 'daily');
    }

    public function councilAllowAuto(): bool
    {
        return (bool) ($this->effective()['council_allow_auto'] ?? false);
    }

    /**
     * @return array<string,mixed>
     */
    public function providerConfig(string $provider, ?array $stored = null): array
    {
        if ($stored === null) {
            $row = $this->row();
            $stored = is_array($row?->value_json) ? $row->value_json : [];
        }
        $config = (array) config("atlas.ai.providers.{$provider}", []);
        $override = is_array(data_get($stored, "providers.{$provider}"))
            ? data_get($stored, "providers.{$provider}")
            : [];

        foreach (['model', 'model_label', 'model_tier', 'model_identity', 'default_model_alias'] as $key) {
            if (array_key_exists($key, $override)) {
                $config[$key] = $this->cleanNullableString($override[$key]);
            }
        }

        foreach (['allow_auto', 'allow_manual'] as $key) {
            if (array_key_exists($key, $override)) {
                $config[$key] = $this->boolValue($override[$key]);
            }
        }

        return $config;
    }

    /**
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    public function update(array $patch, ?string $updatedBy = null): array
    {
        if (! DatabaseTableAvailability::has('atlas_ai_runtime_settings')) {
            throw new \RuntimeException('Tabela atlas_ai_runtime_settings ainda nao existe. Rode as migrations.');
        }

        $row = AtlasAiRuntimeSetting::query()->firstOrNew(['key' => self::KEY]);
        $current = is_array($row->value_json) ? $row->value_json : [];
        $merged = array_replace_recursive($current, $this->normalizePatch($patch));
        $row->forceFill([
            'value_json' => $merged,
            'updated_by' => $updatedBy,
        ])->save();

        return $this->effective();
    }

    /**
     * @return list<string>
     */
    private function providerKeys(): array
    {
        return collect(['claude_cli', 'codex_cli'])
            ->merge(array_keys((array) config('atlas.ai.providers', [])))
            ->unique()
            ->values()
            ->all();
    }

    private function row(): ?AtlasAiRuntimeSetting
    {
        if (! DatabaseTableAvailability::has('atlas_ai_runtime_settings')) {
            return null;
        }

        return AtlasAiRuntimeSetting::query()->find(self::KEY);
    }

    /**
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    private function normalizePatch(array $patch): array
    {
        $normalized = [];

        if (array_key_exists('default_provider', $patch)) {
            if ($this->normalizeDefaultProviderSelection($patch['default_provider']) === 'auto') {
                $normalized['default_provider_selection'] = 'auto';
            } else {
                $provider = $this->normalizeProvider($patch['default_provider']);
                if ($provider !== null && in_array($provider, $this->providerKeys(), true)) {
                    $normalized['default_provider_selection'] = 'fixed';
                    $normalized['default_provider'] = $provider;
                    if ($provider !== 'claude_codex') {
                        $normalized['providers'][$provider]['allow_auto'] = true;
                    }
                }
            }
        }

        if (array_key_exists('default_provider_selection', $patch)) {
            $normalized['default_provider_selection'] = $this->normalizeDefaultProviderSelection($patch['default_provider_selection']);
        }

        if (array_key_exists('default_tier', $patch)) {
            $normalized['default_tier'] = $this->cleanString($patch['default_tier']) ?: 'daily';
        }

        if (array_key_exists('council_allow_auto', $patch)) {
            $normalized['council_allow_auto'] = $this->boolValue($patch['council_allow_auto']);
        }

        if (is_array($patch['providers'] ?? null)) {
            foreach ($patch['providers'] as $provider => $providerPatch) {
                $provider = $this->normalizeProvider($provider);
                if (! in_array((string) $provider, $this->providerKeys(), true) || ! is_array($providerPatch)) {
                    continue;
                }

                foreach (['model', 'model_label', 'model_tier', 'model_identity', 'default_model_alias'] as $key) {
                    if (array_key_exists($key, $providerPatch)) {
                        $normalized['providers'][$provider][$key] = $this->cleanNullableString($providerPatch[$key]);
                    }
                }

                foreach (['allow_auto', 'allow_manual'] as $key) {
                    if (array_key_exists($key, $providerPatch)) {
                        $normalized['providers'][$provider][$key] = $this->boolValue($providerPatch[$key]);
                    }
                }
            }
        }

        if (is_array($patch['budget'] ?? null)) {
            $normalized['budget'] = $this->normalizeBudgetPatch($patch['budget']);
        }

        $defaultProvider = $normalized['default_provider'] ?? $this->defaultProvider();
        if ($defaultProvider !== 'claude_codex' && in_array($defaultProvider, $this->providerKeys(), true)) {
            $normalized['providers'][$defaultProvider]['allow_auto'] = true;
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    private function normalizeBudgetPatch(array $patch): array
    {
        $budget = [];

        if (array_key_exists('enabled', $patch)) {
            $budget['enabled'] = $this->boolValue($patch['enabled']);
        }

        if (array_key_exists('mode', $patch)) {
            $mode = $this->cleanString($patch['mode']);
            $budget['mode'] = in_array($mode, ['monitor', 'block'], true) ? $mode : 'block';
        }

        if (array_key_exists('window_hours', $patch)) {
            $budget['window_hours'] = $this->normalizeBudgetWindowHours($patch['window_hours']);
        }

        foreach (['max_visible_tokens', 'warn_visible_tokens'] as $key) {
            if (array_key_exists($key, $patch)) {
                $budget[$key] = $this->positiveIntOrNull($patch[$key]);
            }
        }

        if (is_array($patch['providers'] ?? null)) {
            foreach ($patch['providers'] as $provider => $providerPatch) {
                $provider = $this->normalizeProvider($provider);
                if (! in_array((string) $provider, $this->providerKeys(), true) || ! is_array($providerPatch)) {
                    continue;
                }

                foreach (['max_visible_tokens', 'warn_visible_tokens'] as $key) {
                    if (array_key_exists($key, $providerPatch)) {
                        $budget['providers'][$provider][$key] = $this->positiveIntOrNull($providerPatch[$key]);
                    }
                }
            }
        }

        return $budget;
    }

    /**
     * @param  array<string,mixed>  $stored
     * @return array<string,mixed>
     */
    private function budgetConfig(array $stored): array
    {
        $config = (array) config('atlas.ai.budget', []);
        $override = is_array($stored['budget'] ?? null) ? $stored['budget'] : [];
        $merged = array_replace_recursive($config, $override);

        return [
            'enabled' => $this->boolValue($merged['enabled'] ?? false),
            'mode' => in_array($merged['mode'] ?? 'block', ['monitor', 'block'], true) ? $merged['mode'] : 'block',
            'window_hours' => $this->normalizeBudgetWindowHours($merged['window_hours'] ?? self::DEFAULT_BUDGET_WINDOW_HOURS),
            'max_visible_tokens' => $this->positiveIntOrNull($merged['max_visible_tokens'] ?? null),
            'warn_visible_tokens' => $this->positiveIntOrNull($merged['warn_visible_tokens'] ?? null),
            'providers' => collect($this->providerKeys())
                ->mapWithKeys(fn (string $provider): array => [$provider => $this->providerBudgetConfig($merged, $provider)])
                ->all(),
        ];
    }

    public function normalizeBudgetWindowHours(mixed $value): int
    {
        if (! is_numeric($value)) {
            $value = self::DEFAULT_BUDGET_WINDOW_HOURS;
        }

        return max(1, min(self::MAX_BUDGET_WINDOW_HOURS, (int) $value));
    }

    /**
     * @param  array<string,mixed>  $budget
     * @return array<string,int|null>
     */
    private function providerBudgetConfig(array $budget, string $provider): array
    {
        $providerBudget = is_array(data_get($budget, "providers.{$provider}"))
            ? data_get($budget, "providers.{$provider}")
            : [];

        return [
            'max_visible_tokens' => $this->positiveIntOrNull($providerBudget['max_visible_tokens'] ?? null),
            'warn_visible_tokens' => $this->positiveIntOrNull($providerBudget['warn_visible_tokens'] ?? null),
        ];
    }

    private function normalizeProvider(mixed $provider): ?string
    {
        if (! is_string($provider) && ! is_numeric($provider)) {
            return null;
        }

        $normalized = strtolower(str_replace('-', '_', trim((string) $provider)));

        $provider = match ($normalized) {
            'claude', 'claude_cli' => 'claude_cli',
            'codex', 'codex_cli' => 'codex_cli',
            'gemini', 'gemini_cli' => 'gemini_cli',
            'hermes', 'hermes_cli' => 'hermes_cli',
            'minimax', 'minimax_m3', 'minimax_m27', 'minimax_m27_cli' => 'minimax_m27_cli',
            'conselho', 'council', 'claude_codex' => 'claude_codex',
            default => $normalized,
        };

        return in_array($provider, $this->providerKeys(), true) ? $provider : null;
    }

    private function normalizeDefaultProviderSelection(mixed $selection): string
    {
        if (! is_string($selection) && ! is_numeric($selection)) {
            return 'fixed';
        }

        return strtolower(str_replace('-', '_', trim((string) $selection))) === 'auto' ? 'auto' : 'fixed';
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function cleanNullableString(mixed $value): ?string
    {
        return $this->cleanString($value);
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'sim'], true);
        }

        return (bool) $value;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
