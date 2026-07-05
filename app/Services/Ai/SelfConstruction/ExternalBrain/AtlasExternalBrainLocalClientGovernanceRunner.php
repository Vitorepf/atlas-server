<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Composes the four optional-local-client organs into one governance verdict.
 * Local subscription clients (Cursor, Codex, Claude, Hermes) may accelerate
 * but must never be the sole path for steady-state autonomy.
 */
final class AtlasExternalBrainLocalClientGovernanceRunner
{
    public const SCHEMA = 'atlas.external_brain.local_client_governance_runner.v1';

    public const VERDICT_CONTINUE = 'continue';
    public const VERDICT_FALLBACK_PAUSE = 'fallback_pause';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function run(array $input): array
    {
        $fallbackPolicy = new AtlasExternalBrainLocalClientFallbackPolicy;
        $recoveryPlanner = new AtlasExternalBrainLocalClientRecoveryPlanner;
        $costGuard = new AtlasExternalBrainLocalClientSubscriptionCostGuard;
        $fragilityGate = new AtlasExternalBrainLocalClientUiFragilityRiskGate;

        // Run each organ.
        $fallback = $fallbackPolicy->decide($input);
        $recovery = $recoveryPlanner->plan($input);
        $cost = $costGuard->evaluate($input);
        $fragility = $fragilityGate->evaluate($input);

        // Determine overall verdict.
        $fallbackDecision = (string) ($fallback['decision'] ?? '');
        $isLocalOnly = $fallbackDecision === AtlasExternalBrainLocalClientFallbackPolicy::DECISION_PAUSE_PROVIDER_ROUTING
            && (bool) ($fallback['local_client_available'] ?? false);

        $verdict = $isLocalOnly
            ? self::VERDICT_FALLBACK_PAUSE
            : self::VERDICT_CONTINUE;

        $blockers = [];
        if ((bool) ($fallback['steady_state_provider_dependency'] ?? false)) {
            $blockers[] = 'steady_state_depends_on_local_client_no_independent_fallback';
        }
        foreach ((array) ($cost['blockers'] ?? []) as $cb) {
            $blockers[] = 'cost:'.$cb;
        }
        if ($fragility['risk_reason_count'] > 0) {
            $blockers[] = 'fragility:ui_fragility_risks_detected';
        }

        $missingFallbackCaps = (array) ($fallback['missing_atlas_native_fallback_capabilities'] ?? []);

        return [
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'fallback' => [
                'decision' => $fallbackDecision,
                'reason' => (string) ($fallback['reason'] ?? ''),
                'local_client_available' => (bool) ($fallback['local_client_available'] ?? false),
                'atlas_native_fallback_available' => (bool) ($fallback['atlas_native_fallback_capacity_available'] ?? false),
                'manual_muscle_available' => (bool) ($fallback['manual_muscle_available'] ?? false),
                'missing_atlas_native_fallback_capabilities' => $missingFallbackCaps,
                'acceleration_allowed' => (bool) ($fallback['acceleration_allowed'] ?? false),
                'safe_usage_mode' => (string) ($fallback['safe_usage_mode'] ?? ''),
                'steady_state_provider_dependency' => (bool) ($fallback['steady_state_provider_dependency'] ?? false),
            ],
            'recovery' => [
                'decision' => (string) ($recovery['decision'] ?? ''),
                'next_safe_action' => (string) ($recovery['next_safe_action'] ?? ''),
                'no_loss_recovery_plan' => (bool) ($recovery['no_loss_recovery_plan'] ?? false),
            ],
            'cost' => [
                'cost_guard_status' => (string) ($cost['cost_guard_status'] ?? ''),
                'safe_for_24_7' => (bool) ($cost['safe_for_24_7'] ?? false),
                'safe_for_manual_use' => (bool) ($cost['safe_for_manual_use'] ?? false),
                'client_class' => (string) ($cost['client_class'] ?? ''),
                'risk_level' => (string) ($cost['risk_level'] ?? ''),
                'fallback_recommendation' => (string) ($cost['fallback_recommendation'] ?? ''),
                'blockers' => (array) ($cost['blockers'] ?? []),
            ],
            'fragility' => [
                'safe_for_24_7' => (bool) ($fragility['safe_for_24_7'] ?? false),
                'recommendation' => (string) ($fragility['recommendation'] ?? ''),
                'risk_reason_count' => (int) ($fragility['risk_reason_count'] ?? 0),
            ],
            'blockers' => $blockers,
            'acceleration_allowed' => (bool) ($fallback['acceleration_allowed'] ?? false),
            'steady_state_dependency_allowed' => false, // Invariant: never allowed
            'mutates_queue' => false,
        ];
    }
}
