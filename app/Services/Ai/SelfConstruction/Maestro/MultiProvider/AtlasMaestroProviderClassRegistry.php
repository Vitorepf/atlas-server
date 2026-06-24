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

    /**
     * @return array<string,array{provider_id:string,axes:array<string,int>,cost_band:string,memory_anchor:string}>
     */
    public function providers(): array
    {
        $providers = [
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
            ],
        ];

        $this->assertWellFormed($providers);

        return $providers;
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
        }
    }
}
