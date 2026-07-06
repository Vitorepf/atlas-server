<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Plans a dry-run of a patch across provider pools to prove the patch
 * would survive losing any one provider.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainProviderPoolPatchDryRunPlan
{
    public const SCHEMA = 'atlas.external_brain.provider_pool_patch_dry_run_plan.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $providers = (array) ($input['providers'] ?? []);
        $patchHash = (string) ($input['patch_hash'] ?? '');

        $dryRunResults = [];
        foreach ($providers as $provider) {
            $name = is_string($provider) ? $provider : (string) ($provider['provider'] ?? '');
            if ($name === '') {
                continue;
            }
            // Simulate losing this provider — the patch survives if there's a fallback.
            $hasFallback = is_array($provider) && (bool) ($provider['has_local_fallback'] ?? false);
            $isNative = is_array($provider) && (bool) ($provider['is_atlas_native'] ?? false);
            $survives = $isNative || $hasFallback;

            $dryRunResults[] = [
                'provider_lost' => $name,
                'patch_survives' => $survives,
            ];
        }

        $allSurvive = array_filter($dryRunResults, static fn (array $r): bool => ! $r['patch_survives']) === [];

        return [
            'schema_version' => self::SCHEMA,
            'patch_hash' => $patchHash,
            'dry_run_results' => $dryRunResults,
            'all_pools_survive' => $allSurvive,
            'total_pools_tested' => count($dryRunResults),
        ];
    }
}
