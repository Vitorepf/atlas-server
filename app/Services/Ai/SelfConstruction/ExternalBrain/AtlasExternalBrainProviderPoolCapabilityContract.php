<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Describes optional external model pools (e.g. Cursor Composer) without
 * making any provider mandatory for autonomous operation.
 *
 * Every entry is treated as optional_accelerator=true and atlas_required=false.
 * fallback_required is always true for an external provider — Atlas must
 * keep a local/native fallback path regardless of pool capability.
 *
 * integration_status:
 *   production_ready — sdk_available=true AND headless_available=true
 *   unproven         — otherwise (no headless SDK proof, including Cursor by default)
 *
 * INPUT:
 *   provider_pools: list<{
 *     provider_id:                  string
 *     model_family:                 string
 *     cost_tier:                    string
 *     sdk_available?:               bool (default false)
 *     headless_available?:          bool (default false)
 *     supports_patch_generation?:   bool (default false)
 *     supports_task_origination?:   bool (default false)
 *     supports_long_running_goal?:  bool (default false)
 *     quota_pool_policy?:           string (default '')
 *   }>
 *
 * OUTPUT:
 *   { schema, capability_matrix: list<row>, provider_count }
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainProviderPoolCapabilityContract
{
    public const SCHEMA = 'atlas.external_brain.provider_pool_capability_contract.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function describe(array $input): array
    {
        $pools = is_array($input['provider_pools'] ?? null) ? $input['provider_pools'] : [];

        $matrix = [];
        foreach ($pools as $pool) {
            if (! is_array($pool) || ! isset($pool['provider_id'])) {
                continue;
            }

            $sdkAvailable = (bool) ($pool['sdk_available'] ?? false);
            $headlessAvailable = (bool) ($pool['headless_available'] ?? false);
            $hasHeadlessSdkProof = $sdkAvailable && $headlessAvailable;

            $matrix[] = [
                'provider_id' => (string) $pool['provider_id'],
                'model_family' => (string) ($pool['model_family'] ?? ''),
                'cost_tier' => (string) ($pool['cost_tier'] ?? ''),
                'sdk_available' => $sdkAvailable,
                'headless_available' => $headlessAvailable,
                'supports_patch_generation' => (bool) ($pool['supports_patch_generation'] ?? false),
                'supports_task_origination' => (bool) ($pool['supports_task_origination'] ?? false),
                'supports_long_running_goal' => (bool) ($pool['supports_long_running_goal'] ?? false),
                'quota_pool_policy' => (string) ($pool['quota_pool_policy'] ?? ''),
                'optional_accelerator' => true,
                'atlas_required' => false,
                'fallback_required' => true,
                'integration_status' => $hasHeadlessSdkProof ? 'production_ready' : 'unproven',
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'capability_matrix' => $matrix,
            'provider_count' => count($matrix),
        ];
    }
}
