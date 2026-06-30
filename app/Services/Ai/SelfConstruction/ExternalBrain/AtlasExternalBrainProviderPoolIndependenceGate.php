<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure gate. Proves Atlas remains autonomous if any external provider in the
 * pool (Cursor, Claude, Codex, Hermes, or any other) becomes unavailable,
 * slow, over quota, lower quality, or policy-disabled.
 *
 * Evaluates each provider claim against 6 fixed failure scenarios:
 *   provider_outage, quota_exhausted, sdk_missing, ui_only, low_quality, policy_disabled.
 *
 * autonomy_preserved for a (provider, scenario) pair = has_local_fallback AND NOT required_for_steady_state.
 * A provider is required_for_steady_state when the input claims it, regardless of fallback presence.
 *
 * production_promotion_blocked = true when ANY provider is required_for_steady_state=true.
 *
 * Pure: no I/O, no side effects, never creates tasks. It only reports
 * minimal_next_tasks_needed_to_restore_independence as plain strings for a
 * caller to act on.
 *
 * INPUT:
 *   providers: list<{
 *     provider:                       string
 *     has_local_fallback?:            bool  (default false)
 *     required_for_steady_state?:     bool  (default false)
 *     missing_fallback_capabilities?: list<string>  (default [])
 *   }>
 */
final class AtlasExternalBrainProviderPoolIndependenceGate
{
    public const SCHEMA = 'atlas.external_brain.provider_pool_independence_gate.v1';

    public const SCENARIOS = [
        'provider_outage',
        'quota_exhausted',
        'sdk_missing',
        'ui_only',
        'low_quality',
        'policy_disabled',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $providers = is_array($input['providers'] ?? null) ? $input['providers'] : [];

        $scenarioResults = [];
        $requiredProviders = [];
        $missingFallbackCapabilities = [];
        $tasks = [];

        foreach ($providers as $claim) {
            if (! is_array($claim) || ! isset($claim['provider'])) {
                continue;
            }

            $provider = (string) $claim['provider'];
            $hasFallback = (bool) ($claim['has_local_fallback'] ?? false);
            $required = (bool) ($claim['required_for_steady_state'] ?? false);
            $missingCaps = is_array($claim['missing_fallback_capabilities'] ?? null) ? $claim['missing_fallback_capabilities'] : [];

            $autonomyPreserved = $hasFallback && ! $required;

            foreach (self::SCENARIOS as $scenario) {
                $scenarioResults[] = [
                    'provider' => $provider,
                    'scenario' => $scenario,
                    'autonomy_preserved' => $autonomyPreserved,
                ];
            }

            if ($required) {
                $requiredProviders[] = $provider;
            }

            if (! $autonomyPreserved) {
                if ($missingCaps === []) {
                    $missingFallbackCapabilities[] = ['provider' => $provider, 'capability' => 'atlas_native_fallback_for_'.$provider];
                } else {
                    foreach ($missingCaps as $cap) {
                        $missingFallbackCapabilities[] = ['provider' => $provider, 'capability' => (string) $cap];
                    }
                }
                $tasks[] = 'build_atlas_native_fallback_for_'.$provider;
            }
        }

        $productionPromotionBlocked = $requiredProviders !== [];
        $autonomyPreservedOverall = $requiredProviders === []
            && ! in_array(false, array_column($scenarioResults, 'autonomy_preserved'), true);

        return [
            'schema' => self::SCHEMA,
            'autonomy_preserved' => $autonomyPreservedOverall,
            'production_promotion_blocked' => $productionPromotionBlocked,
            'required_for_steady_state_providers' => $requiredProviders,
            'scenario_results' => $scenarioResults,
            'missing_fallback_capabilities' => $missingFallbackCapabilities,
            'minimal_next_tasks_needed_to_restore_independence' => array_values(array_unique($tasks)),
        ];
    }
}
