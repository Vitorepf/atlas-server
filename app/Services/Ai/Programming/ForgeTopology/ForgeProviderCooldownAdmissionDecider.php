<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeTopology;

/**
 * Decides whether a previously-failed Forge provider may be re-admitted after
 * its cooldown window. Pure policy: the resolved cooldown window is keyed by
 * canonical failure type (mirrored from
 * AtlasForgeProviderFallbackPolicyService::resolveCooldownUntil so policy and
 * admission always agree), the elapsed time is clamped to a non-negative value,
 * and admission is granted only once the clamped elapsed time meets or exceeds
 * the window. Two guard rails are encoded: an unknown failure type fails closed
 * (never admitted), and the auth_failed sentinel (-1) is a never-readmit signal
 * that keeps the provider cooling down indefinitely.
 */
final class ForgeProviderCooldownAdmissionDecider
{
    private const SCHEMA_VERSION = 'atlas.forge.provider_cooldown_admission.v1';

    /**
     * Cooldown window in seconds keyed by canonical failure type. A value of -1
     * is the never-readmit sentinel (surfaced as-is). Aligned byte-for-byte with
     * AtlasForgeProviderFallbackPolicyService cooldown windows.
     *
     * @var array<string, int>
     */
    private const COOLDOWN_WINDOWS = [
        'rate_limit' => 60,
        'quota_exhausted' => 600,
        'timeout' => 30,
        'model_unavailable' => 120,
        'provider_error' => 30,
        'provider_capacity_exhausted' => 900,
        'auth_failed' => -1,
    ];

    /**
     * @return array{
     *     schema_version: 'atlas.forge.provider_cooldown_admission.v1',
     *     failure_type: string,
     *     cooldown_seconds: int,
     *     elapsed_seconds: int,
     *     admit: bool,
     *     remaining_seconds: int,
     *     reason: string
     * }
     */
    public function decide(string $failureType, int $elapsedSeconds): array
    {
        $clampedElapsed = max(0, $elapsedSeconds);

        // Rule (a): unknown failure type fails closed.
        if (! array_key_exists($failureType, self::COOLDOWN_WINDOWS)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'failure_type' => $failureType,
                'cooldown_seconds' => 0,
                'elapsed_seconds' => $clampedElapsed,
                'admit' => false,
                'remaining_seconds' => 0,
                'reason' => 'unknown_failure_blocks',
            ];
        }

        $window = self::COOLDOWN_WINDOWS[$failureType];

        // Rule (b): never-readmit sentinel (-1) stays cooling down forever.
        if ($window < 0) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'failure_type' => $failureType,
                'cooldown_seconds' => $window,
                'elapsed_seconds' => $clampedElapsed,
                'admit' => false,
                'remaining_seconds' => PHP_INT_MAX,
                'reason' => 'still_cooling_down',
            ];
        }

        // Rule (c): admit once the clamped elapsed time reaches the window.
        $admit = $clampedElapsed >= $window;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'failure_type' => $failureType,
            'cooldown_seconds' => $window,
            'elapsed_seconds' => $clampedElapsed,
            'admit' => $admit,
            'remaining_seconds' => max(0, $window - $clampedElapsed),
            'reason' => $admit ? 'cooldown_elapsed' : 'still_cooling_down',
        ];
    }
}
