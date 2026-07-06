<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Proves that no single provider is load-bearing by checking that every
 * provider in the pool has a fallback or replacement path.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainProviderIndependenceProof
{
    public const SCHEMA = 'atlas.external_brain.provider_independence_proof.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function prove(array $input): array
    {
        $providers = (array) ($input['providers'] ?? []);
        $loadBearing = [];

        foreach ($providers as $provider) {
            if (! is_array($provider)) {
                continue;
            }
            $name = (string) ($provider['provider'] ?? '');
            $hasFallback = (bool) ($provider['has_local_fallback'] ?? false);
            $isNative = (bool) ($provider['is_atlas_native'] ?? false);
            $required = (bool) ($provider['required_for_steady_state'] ?? false);
            $replacementPath = trim((string) ($provider['replacement_path'] ?? ''));

            // A provider is load-bearing if it's non-native, required for steady state,
            // and has no fallback or replacement path.
            if (! $isNative && $required && ! $hasFallback && $replacementPath === '') {
                $loadBearing[] = $name;
            }
        }

        $independent = $loadBearing === [];

        return [
            'schema_version' => self::SCHEMA,
            'independent' => $independent,
            'load_bearing_providers' => $loadBearing,
            'total_providers' => count($providers),
        ];
    }
}
