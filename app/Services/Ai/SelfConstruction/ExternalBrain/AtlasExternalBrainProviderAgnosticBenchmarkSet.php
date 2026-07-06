<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Loads a provider-agnostic benchmark set for the given provider pool.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainProviderAgnosticBenchmarkSet
{
    public const SCHEMA = 'atlas.external_brain.provider_agnostic_benchmark_set.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function load(array $input): array
    {
        $providers = (array) ($input['providers'] ?? []);
        $benchmarks = [];

        foreach ($providers as $provider) {
            $name = is_string($provider) ? $provider : (string) ($provider['provider'] ?? '');
            if ($name === '') {
                continue;
            }
            // A provider-agnostic benchmark requires the same test suite to pass
            // regardless of which provider runs it.
            $benchmarks[] = [
                'provider' => $name,
                'benchmark_id' => 'benchmark:'.$name,
                'description' => sprintf('Provider-agnostic execution benchmark for %s', $name),
                'independent' => true,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'benchmarks' => $benchmarks,
            'total_benchmarks' => count($benchmarks),
        ];
    }
}
