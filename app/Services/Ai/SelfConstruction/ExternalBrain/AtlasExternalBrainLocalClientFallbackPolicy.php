<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure fallback policy for optional local subscription clients (Cursor,
 * Codex, Claude, Hermes). A local client may ACCELERATE Atlas, but steady-
 * state autonomy must never depend on it alone: if it would be the only
 * path forward, the policy refuses to continue on it and instead pauses
 * routing, naming exactly which Atlas-native fallback capability is
 * missing.
 *
 * Decisions:
 *   continue_with_local_client — local client is up, its subscription is
 *     reliable, AND at least one independent fallback (Atlas-native or
 *     manual muscle) also exists, so leaning on it does not create a
 *     hidden single point of failure.
 *   fallback_to_atlas_native    — local client unavailable/unreliable, but
 *     Atlas-native fallback capacity exists.
 *   fallback_to_manual_muscle   — local client unavailable/unreliable,
 *     Atlas-native fallback missing, but a human/manual muscle can step in.
 *   pause_provider_routing      — no safe path: either no fallback exists at
 *     all, or the local client would be the SOLE path for steady-state
 *     autonomy.
 *
 * Pure: no I/O, never mutates the task queue.
 */
final class AtlasExternalBrainLocalClientFallbackPolicy
{
    public const SCHEMA = 'atlas.external_brain.local_client_fallback_policy.v1';

    public const DECISION_CONTINUE_WITH_LOCAL_CLIENT = 'continue_with_local_client';
    public const DECISION_FALLBACK_TO_ATLAS_NATIVE = 'fallback_to_atlas_native';
    public const DECISION_FALLBACK_TO_MANUAL_MUSCLE = 'fallback_to_manual_muscle';
    public const DECISION_PAUSE_PROVIDER_ROUTING = 'pause_provider_routing';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function decide(array $facts): array
    {
        $localClientAvailable = (bool) ($facts['local_client_available'] ?? false);
        $taskCriticality = strtolower(trim((string) ($facts['task_criticality'] ?? 'low')));
        $atlasNativeFallbackCapacityAvailable = (bool) ($facts['atlas_native_fallback_capacity_available'] ?? false);
        $manualMuscleAvailable = (bool) ($facts['manual_muscle_available'] ?? false);
        $subscriptionReliable = (bool) ($facts['subscription_reliable'] ?? false);

        $hasIndependentFallback = $atlasNativeFallbackCapacityAvailable || $manualMuscleAvailable;
        $missingAtlasNativeFallbackCapabilities = array_values(array_filter([
            $atlasNativeFallbackCapacityAvailable ? null : 'atlas_native_fallback_capacity',
            $manualMuscleAvailable ? null : 'manual_muscle_availability',
        ]));

        [$decision, $reason] = match (true) {
            $localClientAvailable && $subscriptionReliable && $hasIndependentFallback => [
                self::DECISION_CONTINUE_WITH_LOCAL_CLIENT,
                'local_client_reliable_and_independent_fallback_exists',
            ],
            $localClientAvailable && $subscriptionReliable && ! $hasIndependentFallback => [
                self::DECISION_PAUSE_PROVIDER_ROUTING,
                'local_client_would_be_sole_path_for_steady_state_autonomy',
            ],
            $atlasNativeFallbackCapacityAvailable => [
                self::DECISION_FALLBACK_TO_ATLAS_NATIVE,
                'local_client_unavailable_or_unreliable_atlas_native_fallback_used',
            ],
            $manualMuscleAvailable => [
                self::DECISION_FALLBACK_TO_MANUAL_MUSCLE,
                'local_client_and_atlas_native_fallback_unavailable_manual_muscle_used',
            ],
            default => [
                self::DECISION_PAUSE_PROVIDER_ROUTING,
                'no_safe_path_available',
            ],
        };

        return [
            'schema_version' => self::SCHEMA,
            'local_client_available' => $localClientAvailable,
            'task_criticality' => $taskCriticality,
            'atlas_native_fallback_capacity_available' => $atlasNativeFallbackCapacityAvailable,
            'manual_muscle_available' => $manualMuscleAvailable,
            'subscription_reliable' => $subscriptionReliable,
            'decision' => $decision,
            'reason' => $reason,
            'missing_atlas_native_fallback_capabilities' => $missingAtlasNativeFallbackCapabilities,
            'mutates_queue' => false,
        ];
    }
}
