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
 * autonomy_risk (per row): low when production_ready; medium when unproven but at least one
 * capability_axis is claimed; high when unproven AND zero capability axes are claimed — a pool
 * that offers nothing demonstrable is the riskiest kind of accelerator to lean on.
 *
 * Rejection (AC4): a pool is excluded from capability_matrix and reported in rejected_pools with
 * an actionable_risk_signal when its description is genuinely EMPTY (model_family and cost_tier
 * both blank — no identity at all) or explicitly PROXY-ONLY (quota_pool_policy contains "proxy").
 * A pool with real identity but zero supports_* flags is NOT rejected — it is common for a newly
 * discovered pool to have no capability proof yet, and it still needs to appear (as high-risk,
 * unproven) so it can be tracked toward proof, not silently dropped.
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
 *   { schema, capability_matrix: list<row>, provider_count, rejected_pools: list<{provider_id,
 *     rejection_reason, actionable_risk_signal}> }
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
        $rejectedPools = [];

        foreach ($pools as $pool) {
            if (! is_array($pool) || ! isset($pool['provider_id'])) {
                continue;
            }

            $providerId = (string) $pool['provider_id'];
            $modelFamily = (string) ($pool['model_family'] ?? '');
            $costTier = (string) ($pool['cost_tier'] ?? '');
            $quotaPoolPolicy = (string) ($pool['quota_pool_policy'] ?? '');

            $isEmptyDescription = $modelFamily === '' && $costTier === '';
            $isProxyOnly = str_contains(strtolower($quotaPoolPolicy), 'proxy');

            if ($isEmptyDescription || $isProxyOnly) {
                $reason = $isProxyOnly ? 'proxy_only_capability_description' : 'empty_capability_description';
                $rejectedPools[] = [
                    'provider_id' => $providerId,
                    'rejection_reason' => $reason,
                    'actionable_risk_signal' => $isProxyOnly
                        ? "provider_id={$providerId} declares quota_pool_policy={$quotaPoolPolicy}, a proxy-only pass-through — do not register it as a capability contract until it exposes a directly attributable model/cost identity"
                        : "provider_id={$providerId} has no model_family or cost_tier — nothing concrete to evaluate; supply both before registering this pool",
                ];

                continue;
            }

            $sdkAvailable = (bool) ($pool['sdk_available'] ?? false);
            $headlessAvailable = (bool) ($pool['headless_available'] ?? false);
            $smokeTestRefs = (array) ($pool['smoke_test_refs'] ?? []);
            $hasSmokeTestProof = $smokeTestRefs !== [];
            $hasHeadlessSdkProof = $sdkAvailable && $headlessAvailable && $hasSmokeTestProof;

            $supportsPatchGeneration = (bool) ($pool['supports_patch_generation'] ?? false);
            $supportsTaskOrigination = (bool) ($pool['supports_task_origination'] ?? false);
            $supportsLongRunningGoal = (bool) ($pool['supports_long_running_goal'] ?? false);

            // AC3: classify capability level — muscle_only (patch generation without long-running
            // goals) vs brain_ready (both patch generation and long-running goals available).
            $capabilityLevel = match (true) {
                $supportsPatchGeneration && $supportsLongRunningGoal => 'brain_ready',
                $supportsPatchGeneration => 'muscle_only',
                default => null,
            };

            $capabilityAxes = array_values(array_filter([
                $supportsPatchGeneration ? 'patch_generation' : null,
                $supportsTaskOrigination ? 'task_origination' : null,
                $supportsLongRunningGoal ? 'long_running_goal' : null,
            ]));

            $integrationStatus = $hasHeadlessSdkProof ? 'production_ready' : 'unproven';

            $autonomyRisk = match (true) {
                $hasHeadlessSdkProof => 'low',
                $capabilityAxes !== [] => 'medium',
                default => 'high',
            };

            $proofRequirement = $hasHeadlessSdkProof
                ? 'proven: sdk_available and headless_available and smoke_test_refs all verified'
                : match (true) {
                    ! $hasSmokeTestProof => 'requires smoke_test_refs (runnable smoke test evidence) to reach production_ready',
                    default => 'requires sdk_available=true AND headless_available=true to reach production_ready',
                };

            $matrix[] = [
                'provider_id' => $providerId,
                'model_family' => $modelFamily,
                'cost_tier' => $costTier,
                'sdk_available' => $sdkAvailable,
                'headless_available' => $headlessAvailable,
                'smoke_test_refs' => $smokeTestRefs,
                'supports_patch_generation' => $supportsPatchGeneration,
                'supports_task_origination' => $supportsTaskOrigination,
                'supports_long_running_goal' => $supportsLongRunningGoal,
                'quota_pool_policy' => $quotaPoolPolicy,
                'optional_accelerator' => true,
                'atlas_required' => false,
                'fallback_required' => true,
                'integration_status' => $integrationStatus,
                'capability_level' => $capabilityLevel,
                'capability_axes' => $capabilityAxes,
                'proof_requirement' => $proofRequirement,
                'fallback_strategy' => "native_atlas_fallback_required:{$providerId}",
                'autonomy_risk' => $autonomyRisk,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'capability_matrix' => $matrix,
            'provider_count' => count($matrix),
            'rejected_pools' => $rejectedPools,
        ];
    }
}
