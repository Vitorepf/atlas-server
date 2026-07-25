<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Support;

use App\Services\Ai\Programming\AtlasForgeProviderCapacityService;
use DateTimeInterface;

/**
 * Pure signal→status helpers for Forge provider capacity read-model.
 *
 * Extracted from AtlasForgeProviderCapacityService private classification methods.
 * No I/O, no provider calls, no DB, no filesystem.
 */
final class ForgeProviderCapacityClassifier
{
    /**
     * @param  array<string,mixed>|null  $latestFailure
     */
    public static function deriveRateLimitState(
        string $providerKey,
        ?array $latestFailure,
        ?DateTimeInterface $cooldownUntil,
        DateTimeInterface $now,
    ): string {
        if ($providerKey === AtlasForgeProviderCapacityService::PROVIDER_ATLAS_LOCAL) {
            return 'not_applicable';
        }

        if ($cooldownUntil !== null && $cooldownUntil > $now) {
            return 'cooldown';
        }

        $type = is_array($latestFailure) ? ($latestFailure['failure_type'] ?? null) : null;
        if ($type === 'rate_limit') {
            return 'limited';
        }

        return 'unknown';
    }

    /**
     * @param  array<string,mixed>|null  $latestFailure
     */
    public static function deriveQuotaState(string $providerKey, ?array $latestFailure): string
    {
        if ($providerKey === AtlasForgeProviderCapacityService::PROVIDER_ATLAS_LOCAL) {
            return 'not_applicable';
        }

        $type = is_array($latestFailure) ? ($latestFailure['failure_type'] ?? null) : null;

        return match ($type) {
            'quota_exhausted' => 'exhausted',
            'rate_limit' => 'limited',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string,mixed>|null  $latestFailure
     */
    public static function deriveCapacityState(
        string $providerKey,
        bool $configPresent,
        bool $runtimePresent,
        string $authState,
        ?array $latestFailure,
        string $rateLimitState,
        string $quotaState,
    ): string {
        if (! $runtimePresent || ! $configPresent) {
            return 'unknown';
        }

        if ($authState === 'invalid' || $authState === 'missing') {
            return 'unknown';
        }

        $type = is_array($latestFailure) ? ($latestFailure['failure_type'] ?? null) : null;
        if ($type === 'provider_capacity_exhausted') {
            return 'exhausted';
        }

        if ($quotaState === 'exhausted') {
            return 'exhausted';
        }

        if ($rateLimitState === 'cooldown' || $rateLimitState === 'limited' || $quotaState === 'limited') {
            return 'limited';
        }

        if ($providerKey === AtlasForgeProviderCapacityService::PROVIDER_ATLAS_LOCAL) {
            return 'available';
        }

        return 'available';
    }

    /**
     * @param  array<string,mixed>|null  $healthSnapshot
     */
    public static function deriveStatus(
        string $providerKey,
        bool $configPresent,
        bool $runtimePresent,
        string $authState,
        string $capacityState,
        string $rateLimitState,
        string $quotaState,
        ?array $healthSnapshot,
    ): string {
        if ($providerKey === AtlasForgeProviderCapacityService::PROVIDER_ATLAS_LOCAL) {
            return AtlasForgeProviderCapacityService::STATUS_AVAILABLE;
        }

        if (! $configPresent || ! $runtimePresent) {
            return AtlasForgeProviderCapacityService::STATUS_UNAVAILABLE;
        }

        if ($authState === 'invalid' || $authState === 'missing') {
            return AtlasForgeProviderCapacityService::STATUS_UNAVAILABLE;
        }

        if ($capacityState === 'exhausted' || $quotaState === 'exhausted') {
            return AtlasForgeProviderCapacityService::STATUS_UNAVAILABLE;
        }

        if ($capacityState === 'limited' || $rateLimitState === 'cooldown' || $rateLimitState === 'limited') {
            return AtlasForgeProviderCapacityService::STATUS_DEGRADED;
        }

        if (is_array($healthSnapshot)) {
            $hsStatus = (string) ($healthSnapshot['status'] ?? '');
            if (in_array($hsStatus, ['failed', 'down', 'offline'], true)) {
                return AtlasForgeProviderCapacityService::STATUS_UNAVAILABLE;
            }
            if (in_array($hsStatus, ['degraded', 'stale'], true)) {
                return AtlasForgeProviderCapacityService::STATUS_DEGRADED;
            }
            if (in_array($hsStatus, ['online', 'healthy', 'available'], true)) {
                return AtlasForgeProviderCapacityService::STATUS_AVAILABLE;
            }
        }

        // No definitive signal beyond config/runtime presence. Honest unknown.
        return AtlasForgeProviderCapacityService::STATUS_UNKNOWN;
    }

    /**
     * @param  array<string,mixed>|null  $healthSnapshot
     * @param  list<array<string,mixed>>  $failureMemory
     */
    public static function resolveConfidence(
        string $providerKey,
        bool $configPresent,
        bool $runtimePresent,
        ?array $healthSnapshot,
        bool $hasWorkerEvent,
        array $failureMemory,
    ): string {
        if ($providerKey === AtlasForgeProviderCapacityService::PROVIDER_ATLAS_LOCAL) {
            return 'high';
        }

        $signals = 0;
        if ($configPresent) {
            $signals++;
        }
        if ($runtimePresent) {
            $signals++;
        }
        if (is_array($healthSnapshot) && ($healthSnapshot['checked_at'] ?? null) !== null) {
            $signals++;
        }
        if ($hasWorkerEvent) {
            $signals++;
        }
        if (! empty($failureMemory)) {
            $signals++;
        }

        return match (true) {
            $signals >= 3 => 'high',
            $signals >= 2 => 'medium',
            default => 'low',
        };
    }

    /**
     * @param  list<string>  $blockers
     */
    public static function resolveProviderNextAction(
        string $status,
        string $capacityState,
        string $rateLimitState,
        string $quotaState,
        string $authState,
        array $blockers,
    ): string {
        if (in_array('provider_capacity_exhausted', $blockers, true)) {
            return 'block';
        }
        if (in_array('auth_invalid', $blockers, true) || in_array('auth_missing', $blockers, true)) {
            return 'reauth';
        }
        if ($rateLimitState === 'cooldown') {
            return 'retry_later';
        }
        if ($capacityState === 'limited' || $quotaState === 'limited') {
            return 'observe';
        }
        if ($status === AtlasForgeProviderCapacityService::STATUS_AVAILABLE) {
            return 'use';
        }

        return 'observe';
    }

    /**
     * @param  list<array<string,mixed>>  $providers
     */
    public static function pickBestAvailable(array $providers): ?string
    {
        $priority = [
            AtlasForgeProviderCapacityService::PROVIDER_CLAUDE_CLI,
            AtlasForgeProviderCapacityService::PROVIDER_CLAUDE_CODEX,
            AtlasForgeProviderCapacityService::PROVIDER_CODEX_CLI,
            AtlasForgeProviderCapacityService::PROVIDER_GEMINI_CLI,
            AtlasForgeProviderCapacityService::PROVIDER_ATLAS_LOCAL,
        ];

        $byProvider = [];
        foreach ($providers as $entry) {
            $byProvider[(string) $entry['provider']] = $entry;
        }

        foreach ($priority as $providerKey) {
            $entry = $byProvider[$providerKey] ?? null;
            if ($entry === null) {
                continue;
            }
            if ((string) $entry['status'] === AtlasForgeProviderCapacityService::STATUS_AVAILABLE) {
                return $providerKey;
            }
        }

        // Degraded fallback ordering.
        foreach ($priority as $providerKey) {
            $entry = $byProvider[$providerKey] ?? null;
            if ($entry === null) {
                continue;
            }
            if ((string) $entry['status'] === AtlasForgeProviderCapacityService::STATUS_DEGRADED) {
                return $providerKey;
            }
        }

        return null;
    }

    public static function resolveTopStatus(int $available, int $degraded, int $unavailable, int $total): string
    {
        if ($available === 0 && $degraded === 0) {
            return AtlasForgeProviderCapacityService::TOP_STATUS_BLOCKED;
        }
        if ($unavailable > 0 || $degraded > 0) {
            return AtlasForgeProviderCapacityService::TOP_STATUS_DEGRADED;
        }

        return AtlasForgeProviderCapacityService::TOP_STATUS_AVAILABLE;
    }

    /**
     * @param  list<string>  $blockers
     */
    public static function resolveNextAction(
        string $topStatus,
        int $availableCount,
        int $degradedCount,
        ?string $bestAvailable,
        array $blockers,
    ): string {
        if (in_array('provider_capacity_exhausted', $blockers, true)) {
            return 'block_runtime_dispatch_until_provider_capacity_recovers';
        }
        if (in_array('obra_not_found', $blockers, true)) {
            return 'provide_existing_obra_id';
        }
        if ($availableCount === 0 && $degradedCount > 0) {
            return 'use_degraded_provider_with_governed_caution';
        }
        if ($bestAvailable !== null) {
            return 'dispatch_to:'.$bestAvailable;
        }

        return 'observe_capacity_signals';
    }

    public static function labelFor(string $providerKey): string
    {
        return match ($providerKey) {
            AtlasForgeProviderCapacityService::PROVIDER_CLAUDE_CLI => 'Claude CLI',
            AtlasForgeProviderCapacityService::PROVIDER_CODEX_CLI => 'Codex CLI',
            AtlasForgeProviderCapacityService::PROVIDER_GEMINI_CLI => 'Gemini CLI',
            AtlasForgeProviderCapacityService::PROVIDER_CLAUDE_CODEX => 'Claude orchestrating Codex',
            AtlasForgeProviderCapacityService::PROVIDER_ATLAS_LOCAL => 'Atlas local runtime',
            default => $providerKey,
        };
    }
}
