<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

/**
 * Atlas Forge Rivals · Provider Model Registry v1.
 *
 * Single source of truth for provider model aliases and concrete CLI model
 * ids. Changing the real Codex/Claude/Gemini model id should happen here
 * through config/env, not in runners, reports or adjudicators.
 */
final class AtlasForgeRivalsProviderModelRegistryService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.provider_model_registry.v1';

    /**
     * @return array<string,array<string,mixed>>
     */
    public function providers(): array
    {
        return [
            'claude' => [
                'provider' => 'claude',
                'binary_config_key' => 'atlas.ai.providers.claude_cli.binary',
                'binary_default' => 'claude',
                'default_model' => 'claude_sonnet',
                'models' => [
                    'claude_sonnet' => [
                        'canonical_model' => 'claude_sonnet',
                        'model_id' => (string) (config('atlas.ai.providers.claude_cli.model') ?: 'sonnet'),
                        'label' => (string) (config('atlas.ai.providers.claude_cli.model_label') ?: 'Claude Sonnet'),
                        'aliases' => ['sonnet', 'claude_sonnet', 'claude-sonnet'],
                        'legacy_model_id' => AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET,
                    ],
                    'claude_opus' => [
                        'canonical_model' => 'claude_opus',
                        'model_id' => (string) (config('atlas.ai.providers.claude_cli.premium_model') ?: 'opus'),
                        'label' => (string) (config('atlas.ai.providers.claude_cli.premium_model_label') ?: 'Claude Opus'),
                        'aliases' => ['opus', 'claude_opus', 'claude-opus'],
                        'legacy_model_id' => AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_OPUS,
                    ],
                ],
            ],
            'codex' => [
                'provider' => 'codex',
                'binary_config_key' => 'atlas.ai.providers.codex_cli.binary',
                'binary_default' => 'codex',
                'default_model' => 'codex',
                'models' => [
                    'codex' => [
                        'canonical_model' => 'codex',
                        'model_id' => (string) (config('atlas.ai.providers.codex_cli.model') ?: 'codex'),
                        'label' => (string) (config('atlas.ai.providers.codex_cli.model_label') ?: 'Codex CLI default'),
                        'aliases' => ['codex', 'gpt-codex', 'codex-default', 'default'],
                        'legacy_model_id' => AtlasForgeRivalsModelMatrix::MODEL_CODEX,
                    ],
                    'gpt-5.5' => [
                        'canonical_model' => 'gpt-5.5',
                        'model_id' => (string) (config('atlas.ai.providers.codex_cli.premium_model') ?: 'gpt-5.5'),
                        'label' => (string) (config('atlas.ai.providers.codex_cli.premium_model_label') ?: 'GPT-5.5'),
                        'aliases' => ['gpt-5.5', 'gpt_5_5', 'gpt5.5', 'codex-premium', 'premium'],
                        'legacy_model_id' => AtlasForgeRivalsModelMatrix::MODEL_CODEX,
                    ],
                ],
            ],
            'gemini' => [
                'provider' => 'gemini',
                'binary_config_key' => 'atlas.ai.providers.gemini_cli.binary',
                'binary_default' => 'gemini',
                'default_model' => 'gemini-pro',
                'models' => [
                    'gemini-pro' => [
                        'canonical_model' => 'gemini-pro',
                        'model_id' => (string) (config('atlas.ai.providers.gemini_cli.model') ?: 'gemini-pro'),
                        'label' => (string) (config('atlas.ai.providers.gemini_cli.model_label') ?: 'Gemini Pro'),
                        'aliases' => ['gemini-pro', 'gemini_pro', 'pro'],
                        'legacy_model_id' => 'gemini',
                    ],
                    'gemini-flash' => [
                        'canonical_model' => 'gemini-flash',
                        'model_id' => (string) (config('atlas.ai.providers.gemini_cli.fallback_model') ?: 'gemini-flash'),
                        'label' => (string) (config('atlas.ai.providers.gemini_cli.fallback_model_label') ?: 'Gemini Flash'),
                        'aliases' => ['gemini-flash', 'gemini_flash', 'flash'],
                        'legacy_model_id' => 'gemini',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ok',
            'providers' => $this->providers(),
            'provider_count' => count($this->providers()),
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
            'note' => 'Rivals emits measured evidence; Atlas Decide decides model routing.',
        ];
    }

    /**
     * @return list<string>
     */
    public function aliasesForProvider(string $provider): array
    {
        $providerDef = $this->providers()[strtolower(trim($provider))] ?? null;
        if (! is_array($providerDef)) {
            return [];
        }

        $aliases = [];
        foreach ((array) ($providerDef['models'] ?? []) as $canonical => $model) {
            $aliases[] = (string) $canonical;
            foreach ((array) ($model['aliases'] ?? []) as $alias) {
                $aliases[] = (string) $alias;
            }
        }

        return array_values(array_unique($aliases));
    }

    /**
     * @return list<string>
     */
    public function canonicalModels(?string $provider = null): array
    {
        $providers = $this->providers();
        if ($provider !== null && trim($provider) !== '') {
            $providerDef = $providers[strtolower(trim($provider))] ?? null;
            if (! is_array($providerDef)) {
                return [];
            }

            return array_values(array_map(
                static fn (mixed $model): string => (string) $model,
                array_keys((array) ($providerDef['models'] ?? [])),
            ));
        }

        $models = [];
        foreach ($providers as $providerDef) {
            foreach (array_keys((array) ($providerDef['models'] ?? [])) as $model) {
                $models[] = (string) $model;
            }
        }

        return array_values(array_unique($models));
    }

    /**
     * @return array{ok:bool,provider:string,binary:?string,binary_config_key:?string,blockers:list<string>}
     */
    public function binaryForProvider(string $provider): array
    {
        $provider = strtolower(trim($provider));
        $providerDef = $this->providers()[$provider] ?? null;
        if (! is_array($providerDef)) {
            return [
                'ok' => false,
                'provider' => $provider,
                'binary' => null,
                'binary_config_key' => null,
                'blockers' => ['provider_unknown:'.$provider],
            ];
        }

        $configKey = (string) ($providerDef['binary_config_key'] ?? '');
        $fallback = (string) ($providerDef['binary_default'] ?? $provider);
        $binary = $configKey !== ''
            ? (string) (config($configKey) ?: $fallback)
            : $fallback;

        if (trim($binary) === '') {
            return [
                'ok' => false,
                'provider' => $provider,
                'binary' => null,
                'binary_config_key' => $configKey !== '' ? $configKey : null,
                'blockers' => ['provider_binary_missing:'.$provider],
            ];
        }

        return [
            'ok' => true,
            'provider' => $provider,
            'binary' => $binary,
            'binary_config_key' => $configKey !== '' ? $configKey : null,
            'blockers' => [],
        ];
    }

    /**
     * @return array{
     *   ok:bool,
     *   provider:string,
     *   requested_model:string,
     *   canonical_model:?string,
     *   model_id:?string,
     *   model_label:?string,
     *   legacy_model_id:?string,
     *   blockers:list<string>
     * }
     */
    public function resolve(string $provider, string $requestedModel): array
    {
        $provider = strtolower(trim($provider));
        $requested = strtolower(trim($requestedModel));
        $providers = $this->providers();
        $providerDef = $providers[$provider] ?? null;
        if (! is_array($providerDef)) {
            return $this->blocked($provider, $requested, ['provider_unknown:'.$provider]);
        }

        if ($requested === '') {
            $requested = (string) ($providerDef['default_model'] ?? '');
        }

        foreach ((array) ($providerDef['models'] ?? []) as $canonical => $model) {
            $aliases = array_map(
                static fn ($alias): string => strtolower((string) $alias),
                array_merge([(string) $canonical], (array) ($model['aliases'] ?? [])),
            );
            if (in_array($requested, $aliases, true)) {
                return [
                    'ok' => true,
                    'provider' => $provider,
                    'requested_model' => $requested,
                    'canonical_model' => (string) $canonical,
                    'model_id' => (string) ($model['model_id'] ?? $canonical),
                    'model_label' => (string) ($model['label'] ?? $canonical),
                    'legacy_model_id' => (string) ($model['legacy_model_id'] ?? $canonical),
                    'blockers' => [],
                ];
            }
        }

        return $this->blocked($provider, $requested, ['provider_model_unknown:'.$provider.':'.$requested]);
    }

    /**
     * @param  list<string>  $blockers
     * @return array{ok:bool,provider:string,requested_model:string,canonical_model:null,model_id:null,model_label:null,legacy_model_id:null,blockers:list<string>}
     */
    private function blocked(string $provider, string $requested, array $blockers): array
    {
        return [
            'ok' => false,
            'provider' => $provider,
            'requested_model' => $requested,
            'canonical_model' => null,
            'model_id' => null,
            'model_label' => null,
            'legacy_model_id' => null,
            'blockers' => $blockers,
        ];
    }
}
