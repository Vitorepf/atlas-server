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
 * autonomy_preserved for a (provider, scenario) pair = is_atlas_native OR (has_exit_path AND NOT
 * required_for_steady_state). has_exit_path = has_local_fallback OR replacement_path OR
 * sunset_criteria (AC3: any one of fallback/replacement/sunset satisfies the exit requirement).
 * A provider is required_for_steady_state when the input claims it, regardless of fallback presence.
 *
 * pool_classification (AC2) per provider:
 *   native                — is_atlas_native=true (no external dependency at all)
 *   prohibited_dependency — non-native AND required_for_steady_state=true (blocks production
 *                           regardless of exit path — AC4)
 *   risky_dependency      — non-native, not required, but has_exit_path=false (no fallback,
 *                           replacement path or sunset criteria declared)
 *   acceleration_only     — non-native, not required, has_exit_path=true (safe accelerator)
 *
 * production_promotion_blocked = true when ANY NON-native provider is required_for_steady_state=true.
 * A native provider being required for steady state is the intended architecture, not a violation.
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
 *     is_atlas_native?:               bool  (default false)
 *     replacement_path?:              string (default '')
 *     sunset_criteria?:               string (default '')
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
        $poolClassification = [];

        foreach ($providers as $claim) {
            if (! is_array($claim) || ! isset($claim['provider'])) {
                continue;
            }

            $provider = (string) $claim['provider'];
            $hasFallback = (bool) ($claim['has_local_fallback'] ?? false);
            $required = (bool) ($claim['required_for_steady_state'] ?? false);
            $missingCaps = is_array($claim['missing_fallback_capabilities'] ?? null) ? $claim['missing_fallback_capabilities'] : [];
            $isNative = (bool) ($claim['is_atlas_native'] ?? false);
            $replacementPath = trim((string) ($claim['replacement_path'] ?? ''));
            $sunsetCriteria = trim((string) ($claim['sunset_criteria'] ?? ''));
            $hasExitPath = $hasFallback || $replacementPath !== '' || $sunsetCriteria !== '';

            $autonomyPreserved = $isNative || ($hasExitPath && ! $required);

            $classification = match (true) {
                $isNative => 'native',
                $required => 'prohibited_dependency',
                ! $hasExitPath => 'risky_dependency',
                default => 'acceleration_only',
            };
            $poolClassification[] = [
                'provider' => $provider,
                'classification' => $classification,
                'has_exit_path' => $hasExitPath,
            ];

            foreach (self::SCENARIOS as $scenario) {
                $scenarioResults[] = [
                    'provider' => $provider,
                    'scenario' => $scenario,
                    'autonomy_preserved' => $autonomyPreserved,
                ];
            }

            if ($required && ! $isNative) {
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
            'pool_classification' => $poolClassification,
        ];
    }
}
