<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\MultiProvider;

use LogicException;

final class AtlasMaestroProviderClassRegistry
{
    public const AXES = [
        'architecture-depth',
        'multi-file-coherence',
        'grind-throughput',
        'doc-fidelity',
    ];

    public const PROVIDER_CLASS_LOCAL_NATIVE = 'local_native';

    public const PROVIDER_CLASS_SUBSCRIPTION_EXTERNAL = 'subscription_external';

    public const PROVIDER_CLASS_API_METERED = 'api_metered';

    public const PROVIDER_CLASS_UNKNOWN = 'unknown';

    /**
     * @return array<string,array{provider_id:string,axes:array<string,int>,cost_band:string,memory_anchor:string,autonomy_level:string,steady_state_allowed:bool,supports_task_classes:list<string>,provider_class:string,proof_strength:int,quota_style:string,fallback_class:string,autonomy_dependency_risk:string}>
     */
    public function providers(): array
    {
        $providers = [
            'atlas_native' => [
                'provider_id' => 'atlas_native',
                'axes' => [
                    'architecture-depth' => 0,
                    'multi-file-coherence' => 0,
                    'grind-throughput' => 100,
                    'doc-fidelity' => 0,
                ],
                'cost_band' => 'zero',
                'memory_anchor' => 'loop-provider-minimax-impl-codex-hard',
                'autonomy_level' => 'native',
                'steady_state_allowed' => true,
                'supports_task_classes' => ['*'],
                'provider_class' => self::PROVIDER_CLASS_LOCAL_NATIVE,
                'proof_strength' => 100,
                'quota_style' => 'unmetered_native',
                'fallback_class' => 'none',
                'autonomy_dependency_risk' => 'none',
            ],
            'minimax-m3' => [
                'provider_id' => 'minimax-m3',
                'axes' => [
                    'architecture-depth' => 58,
                    'multi-file-coherence' => 55,
                    'grind-throughput' => 92,
                    'doc-fidelity' => 64,
                ],
                'cost_band' => 'cheap',
                'memory_anchor' => 'loop-provider-minimax-impl-codex-hard',
                'autonomy_level' => 'ai_api',
                'steady_state_allowed' => true,
                'supports_task_classes' => ['refactor', 'wiring', 'docs', 'test', 'grind'],
                'provider_class' => self::PROVIDER_CLASS_SUBSCRIPTION_EXTERNAL,
                'proof_strength' => 70,
                'quota_style' => 'subscription_pool',
                'fallback_class' => 'atlas_native',
                'autonomy_dependency_risk' => 'low',
            ],
            'codex-gpt-5-5' => [
                'provider_id' => 'codex-gpt-5-5',
                'axes' => [
                    'architecture-depth' => 91,
                    'multi-file-coherence' => 88,
                    'grind-throughput' => 57,
                    'doc-fidelity' => 86,
                ],
                'cost_band' => 'expensive',
                'memory_anchor' => 'loop-provider-minimax-impl-codex-hard',
                'autonomy_level' => 'ai_api',
                'steady_state_allowed' => false,
                'supports_task_classes' => ['architecture', 'planning', 'hard-refactor'],
                'provider_class' => self::PROVIDER_CLASS_API_METERED,
                'proof_strength' => 85,
                'quota_style' => 'pay_per_token',
                'fallback_class' => 'claude-opus',
                'autonomy_dependency_risk' => 'high',
            ],
            'claude-opus' => [
                'provider_id' => 'claude-opus',
                'axes' => [
                    'architecture-depth' => 87,
                    'multi-file-coherence' => 84,
                    'grind-throughput' => 61,
                    'doc-fidelity' => 91,
                ],
                'cost_band' => 'expensive',
                'memory_anchor' => 'loop-provider-minimax-impl-codex-hard',
                'autonomy_level' => 'ai_api',
                'steady_state_allowed' => false,
                'supports_task_classes' => ['architecture', 'planning', 'docs', 'hard-refactor'],
                'provider_class' => self::PROVIDER_CLASS_API_METERED,
                'proof_strength' => 88,
                'quota_style' => 'pay_per_token',
                'fallback_class' => 'codex-gpt-5-5',
                'autonomy_dependency_risk' => 'high',
            ],
            'glm-5-2' => [
                'provider_id' => 'glm-5-2',
                'axes' => [
                    'architecture-depth' => 70,
                    'multi-file-coherence' => 69,
                    'grind-throughput' => 78,
                    'doc-fidelity' => 68,
                ],
                'cost_band' => 'standard',
                'memory_anchor' => 'loop-provider-minimax-impl-codex-hard',
                'autonomy_level' => 'ai_api',
                'steady_state_allowed' => true,
                'supports_task_classes' => ['refactor', 'wiring', 'docs', 'test', 'grind'],
                'provider_class' => self::PROVIDER_CLASS_SUBSCRIPTION_EXTERNAL,
                'proof_strength' => 75,
                'quota_style' => 'subscription_pool',
                'fallback_class' => 'minimax-m3',
                'autonomy_dependency_risk' => 'low',
            ],
        ];

        $this->assertWellFormed($providers);

        return $providers;
    }

    /**
     * Safe degraded contract for any provider id, known or unknown, so callers
     * (e.g. assignment policy) never have to special-case a missing entry.
     * Known providers return their full registry row; unknown ids return a
     * deterministic, non-secret placeholder that degrades to the native fallback.
     *
     * @return array<string,mixed>
     */
    public function contractFor(string $providerId): array
    {
        $providers = $this->providers();

        if (isset($providers[$providerId])) {
            return $providers[$providerId];
        }

        return [
            'provider_id' => $providerId,
            'axes' => array_fill_keys(self::AXES, 0),
            'cost_band' => 'unknown',
            'memory_anchor' => '',
            'autonomy_level' => 'unknown',
            'steady_state_allowed' => false,
            'supports_task_classes' => [],
            'provider_class' => self::PROVIDER_CLASS_UNKNOWN,
            'proof_strength' => 0,
            'quota_style' => 'unknown',
            'fallback_class' => 'atlas_native',
            'autonomy_dependency_risk' => 'unknown',
            'degraded' => true,
        ];
    }

    /**
     * @return array<string,int>
     */
    public function axesFor(string $providerId): array
    {
        return $this->providers()[$providerId]['axes'] ?? [];
    }

    public function costBandFor(string $providerId): string
    {
        return (string) ($this->providers()[$providerId]['cost_band'] ?? '');
    }

    /**
     * @param  array<string,array<string,mixed>>  $providers
     */
    private function assertWellFormed(array $providers): void
    {
        if (! array_key_exists('atlas_native', $providers)) {
            throw new LogicException('registry must declare atlas_native as the zero-cost native provider');
        }

        foreach ($providers as $providerId => $provider) {
            $axes = (array) ($provider['axes'] ?? []);
            foreach (self::AXES as $axis) {
                if (! array_key_exists($axis, $axes) || ! is_int($axes[$axis]) || $axes[$axis] < 0 || $axes[$axis] > 100) {
                    throw new LogicException(sprintf('provider %s axis %s must be an int in [0,100]', $providerId, $axis));
                }
            }
            if ((string) ($provider['cost_band'] ?? '') === '') {
                throw new LogicException(sprintf('provider %s must declare a cost band', $providerId));
            }
            if ((string) ($provider['autonomy_level'] ?? '') === '') {
                throw new LogicException(sprintf('provider %s must declare autonomy_level', $providerId));
            }
            if (! is_bool($provider['steady_state_allowed'] ?? null)) {
                throw new LogicException(sprintf('provider %s must declare steady_state_allowed as bool', $providerId));
            }
            if (! is_array($provider['supports_task_classes'] ?? null) || $provider['supports_task_classes'] === []) {
                throw new LogicException(sprintf('provider %s must declare non-empty supports_task_classes', $providerId));
            }
            if ((string) ($provider['provider_class'] ?? '') === '') {
                throw new LogicException(sprintf('provider %s must declare provider_class', $providerId));
            }
            if (! is_int($provider['proof_strength'] ?? null) || $provider['proof_strength'] < 0 || $provider['proof_strength'] > 100) {
                throw new LogicException(sprintf('provider %s must declare proof_strength as an int in [0,100]', $providerId));
            }
            if ((string) ($provider['quota_style'] ?? '') === '') {
                throw new LogicException(sprintf('provider %s must declare quota_style', $providerId));
            }
            if ((string) ($provider['fallback_class'] ?? '') === '') {
                throw new LogicException(sprintf('provider %s must declare fallback_class', $providerId));
            }
            if ((string) ($provider['autonomy_dependency_risk'] ?? '') === '') {
                throw new LogicException(sprintf('provider %s must declare autonomy_dependency_risk', $providerId));
            }
        }
    }
}
