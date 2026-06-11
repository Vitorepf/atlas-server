<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Support\AiStringListNormalizer;

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

    /** @var array<string,string> */
    private const CONFIG_KEYS = [
        'cursor.default' => 'atlas.ai.providers.cursor_cli.model',
        'composer.composer-2.5' => 'atlas.ai.providers.cursor_cli.composer_2_5_model',
    ];

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
                        'model_id' => (string) (config('atlas.ai.providers.gemini_cli.models.gemini_pro.model') ?: config('atlas.ai.providers.gemini_cli.model') ?: 'gemini-pro'),
                        'label' => (string) (config('atlas.ai.providers.gemini_cli.models.gemini_pro.label') ?: config('atlas.ai.providers.gemini_cli.model_label') ?: 'Gemini Pro'),
                        'aliases' => ['gemini-pro', 'gemini_pro', 'pro'],
                        'legacy_model_id' => 'gemini',
                    ],
                    'gemini-flash' => [
                        'canonical_model' => 'gemini-flash',
                        'model_id' => (string) (config('atlas.ai.providers.gemini_cli.models.gemini_flash.model') ?: config('atlas.ai.providers.gemini_cli.fallback_model') ?: 'gemini-flash'),
                        'label' => (string) (config('atlas.ai.providers.gemini_cli.models.gemini_flash.label') ?: config('atlas.ai.providers.gemini_cli.fallback_model_label') ?: 'Gemini Flash'),
                        'aliases' => ['gemini-flash', 'gemini_flash', 'flash'],
                        'legacy_model_id' => 'gemini',
                    ],
                ],
            ],
            'cursor' => [
                'provider' => 'cursor',
                'provider_kind' => 'meta_provider',
                'meta_provider' => true,
                'model_routing_owner' => 'cursor',
                'atlas_routing_effect' => 'none',
                'billing_mode_config_key' => 'atlas.ai.providers.cursor_cli.billing_mode',
                'quota_bucket_config_key' => 'atlas.ai.providers.cursor_cli.quota_bucket',
                'model_identity_config_key' => 'atlas.ai.providers.cursor_cli.model_identity',
                'tool_event_stream' => 'stream-json',
                'binary_config_key' => 'atlas.ai.providers.cursor_cli.binary',
                'binary_default' => 'cursor-agent',
                'default_model' => 'default',
                'models' => [
                    'default' => [
                        'canonical_model' => 'default',
                        'model_id' => $this->configuredModelId(self::CONFIG_KEYS['cursor.default']),
                        'model_id_config_key' => self::CONFIG_KEYS['cursor.default'],
                        'label' => (string) (config('atlas.ai.providers.cursor_cli.model_label') ?: 'Cursor CLI configured default'),
                        'aliases' => $this->aliases(
                            ['default', 'configured', 'cursor_default', 'auto'],
                            'atlas.ai.providers.cursor_cli.aliases',
                        ),
                        'legacy_model_id' => 'cursor_cli',
                    ],
                    'auto' => [
                        'canonical_model' => 'auto',
                        'model_id' => 'auto',
                        'model_id_config_key' => null,
                        'label' => 'Cursor Auto',
                        'aliases' => $this->aliases(['auto', 'cursor_auto'], 'atlas.ai.providers.cursor_cli.auto_aliases'),
                        'legacy_model_id' => 'cursor_cli',
                    ],
                    'composer-2.5' => [
                        'canonical_model' => 'composer-2.5',
                        'model_id' => $this->configuredModelId(self::CONFIG_KEYS['composer.composer-2.5']),
                        'model_id_config_key' => self::CONFIG_KEYS['composer.composer-2.5'],
                        'label' => (string) (config('atlas.ai.providers.cursor_cli.composer_2_5_model_label') ?: 'Composer 2.5'),
                        'aliases' => $this->aliases(
                            ['composer', 'composer_2', 'composer_2_5', 'composer-2.5'],
                            'atlas.ai.providers.cursor_cli.composer_2_5_aliases',
                        ),
                        'legacy_model_id' => 'composer_2_5',
                    ],
                ],
            ],
            'composer' => [
                'provider' => 'composer',
                'provider_kind' => 'meta_provider_surface',
                'meta_provider' => true,
                'meta_provider_parent' => 'cursor',
                'model_routing_owner' => 'cursor',
                'atlas_routing_effect' => 'none',
                'billing_mode_config_key' => 'atlas.ai.providers.cursor_cli.billing_mode',
                'quota_bucket_config_key' => 'atlas.ai.providers.cursor_cli.quota_bucket',
                'model_identity_config_key' => 'atlas.ai.providers.cursor_cli.model_identity',
                'tool_event_stream' => 'stream-json',
                'binary_config_key' => 'atlas.ai.providers.cursor_cli.binary',
                'binary_default' => 'cursor-agent',
                'default_model' => 'composer-2.5',
                'models' => [
                    'composer-2.5' => [
                        'canonical_model' => 'composer-2.5',
                        'model_id' => $this->configuredModelId(self::CONFIG_KEYS['composer.composer-2.5']),
                        'model_id_config_key' => self::CONFIG_KEYS['composer.composer-2.5'],
                        'label' => (string) (config('atlas.ai.providers.cursor_cli.composer_2_5_model_label') ?: 'Composer 2.5'),
                        'aliases' => $this->aliases(
                            ['default', 'configured', 'composer', 'composer_2', 'composer_2_5', 'composer-2.5', 'auto'],
                            'atlas.ai.providers.cursor_cli.composer_2_5_aliases',
                        ),
                        'legacy_model_id' => 'composer_2_5',
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
            $modelIdToken = strtolower(trim((string) ($model['model_id'] ?? '')));
            if (
                in_array($requested, $aliases, true)
                || ($modelIdToken !== '' && $requested === $modelIdToken)
            ) {
                $modelId = (string) ($model['model_id'] ?? '');
                if (trim($modelId) === '') {
                    $configKey = (string) ($model['model_id_config_key'] ?? '');

                    return $this->blocked($provider, $requested, [
                        'provider_model_id_missing:'.$provider.':'.$canonical.($configKey !== '' ? ':'.$configKey : ''),
                    ]);
                }

                return [
                    'ok' => true,
                    'provider' => $provider,
                    'provider_kind' => (string) ($providerDef['provider_kind'] ?? 'direct_provider'),
                    'meta_provider' => (bool) ($providerDef['meta_provider'] ?? false),
                    'meta_provider_parent' => $providerDef['meta_provider_parent'] ?? null,
                    'provider_metadata' => $this->providerMetadata($providerDef),
                    'requested_model' => $requested,
                    'canonical_model' => (string) $canonical,
                    'model_id' => $modelId,
                    'model_id_config_key' => $model['model_id_config_key'] ?? null,
                    'model_label' => (string) ($model['label'] ?? $canonical),
                    'legacy_model_id' => (string) ($model['legacy_model_id'] ?? $canonical),
                    'blockers' => [],
                ];
            }
        }

        return $this->blocked($provider, $requested, ['provider_model_unknown:'.$provider.':'.$requested]);
    }

    /**
     * @param  array<string,mixed>  $providerDef
     * @return array<string,mixed>
     */
    private function providerMetadata(array $providerDef): array
    {
        return [
            'provider_kind' => (string) ($providerDef['provider_kind'] ?? 'direct_provider'),
            'meta_provider' => (bool) ($providerDef['meta_provider'] ?? false),
            'meta_provider_parent' => $providerDef['meta_provider_parent'] ?? null,
            'model_routing_owner' => (string) ($providerDef['model_routing_owner'] ?? 'atlas_decide'),
            'atlas_routing_effect' => (string) ($providerDef['atlas_routing_effect'] ?? 'none'),
            'billing_mode_config_key' => $providerDef['billing_mode_config_key'] ?? null,
            'quota_bucket_config_key' => $providerDef['quota_bucket_config_key'] ?? null,
            'model_identity_config_key' => $providerDef['model_identity_config_key'] ?? null,
            'tool_event_stream' => $providerDef['tool_event_stream'] ?? null,
        ];
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
            'provider_kind' => 'unknown',
            'meta_provider' => false,
            'meta_provider_parent' => null,
            'provider_metadata' => [],
            'requested_model' => $requested,
            'canonical_model' => null,
            'model_id' => null,
            'model_id_config_key' => null,
            'model_label' => null,
            'legacy_model_id' => null,
            'blockers' => $blockers,
        ];
    }

    private function configuredModelId(string $configKey): ?string
    {
        $value = config($configKey);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private function aliases(array $defaults, string $configKey): array
    {
        return AiStringListNormalizer::uniqueMergedStrings(
            $defaults,
            AiStringListNormalizer::uniqueCommaSeparatedStrings(config($configKey)),
        );
    }
}
