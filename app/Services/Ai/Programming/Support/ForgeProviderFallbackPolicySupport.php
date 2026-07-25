<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Support;

use App\Services\Ai\Programming\AtlasForgeProviderFallbackPolicyService as Policy;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Pure fallback-policy helpers for Forge provider continuum.
 *
 * Extracted from AtlasForgeProviderFallbackPolicyService private methods.
 * No I/O, no provider calls, no DB, no Carbon/time side effects
 * (callers pass/format timestamps; cooldownSecondsFor is pure int typing).
 */
final class ForgeProviderFallbackPolicySupport
{
    /**
     * @param  array<int,array<string,mixed>>|mixed  $chain
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeFallbackChain(mixed $chain): array
    {
        if (! is_array($chain)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $entry): ?array {
            if (! is_array($entry)) {
                return null;
            }
            $role = AiValueNormalizer::trimmedStringOrNull($entry['role'] ?? null);
            $provider = AiValueNormalizer::trimmedStringOrNull($entry['provider'] ?? null);
            $model = AiValueNormalizer::trimmedStringOrNull($entry['model'] ?? null);
            $order = (int) ($entry['order'] ?? 0);
            $capable = ! array_key_exists('capable', $entry) || (bool) $entry['capable'];
            if ($role === null && $provider === null && $model === null) {
                return null;
            }

            return [
                'role' => $role,
                'provider' => $provider,
                'model' => $model,
                'order' => $order,
                'capable' => $capable,
            ];
        }, $chain), fn (?array $value): bool => $value !== null));
    }

    /**
     * @param  array<int,array<string,mixed>>|mixed  $roles
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeRoles(mixed $roles): array
    {
        if (! is_array($roles)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $entry): ?array {
            if (! is_array($entry)) {
                return null;
            }
            $role = AiValueNormalizer::trimmedStringOrNull($entry['role'] ?? null);
            if ($role === null) {
                return null;
            }
            $provider = AiValueNormalizer::trimmedStringOrNull($entry['provider'] ?? null);
            $model = AiValueNormalizer::trimmedStringOrNull($entry['model'] ?? null);
            $status = AiValueNormalizer::trimmedStringOrNull($entry['status'] ?? null) ?? 'available';

            return [
                'role' => $role,
                'provider' => $provider,
                'model' => $model,
                'status' => $status,
            ];
        }, $roles), fn (?array $value): bool => $value !== null));
    }

    /**
     * Pick the next capable fallback excluding the failed (role+provider+model)
     * tuple. Returns null when no capable fallback exists — caller must block.
     *
     * @param  array<int,array<string,mixed>>  $chain
     * @param  array<int,array<string,mixed>>  $roles
     * @return array<string,mixed>|null
     */
    public static function pickFallback(
        ?string $failedRole,
        ?string $failedProvider,
        ?string $failedModel,
        array $chain,
        array $roles,
    ): ?array {
        $sorted = $chain;
        usort($sorted, static fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        foreach ($sorted as $entry) {
            if (! ($entry['capable'] ?? true)) {
                continue;
            }
            if ($failedRole !== null && $entry['role'] === $failedRole
                && $entry['provider'] === $failedProvider
                && $entry['model'] === $failedModel) {
                continue;
            }
            if ($entry['provider'] === null && $entry['model'] === null) {
                continue;
            }

            return [
                'role' => $entry['role'] ?? $failedRole,
                'provider' => $entry['provider'],
                'model' => $entry['model'],
                'order' => $entry['order'] ?? 0,
                'source' => 'fallback_chain',
            ];
        }

        // No explicit fallback chain hit. Try other roles in the topology that
        // are available with a different provider/model from the failed tuple.
        foreach ($roles as $candidate) {
            if ($candidate['status'] !== 'available' && $candidate['status'] !== 'selected') {
                continue;
            }
            if ($candidate['provider'] === $failedProvider && $candidate['model'] === $failedModel) {
                continue;
            }
            if ($candidate['provider'] === null && $candidate['model'] === null) {
                continue;
            }
            // Only consider as fallback when the role differs OR provider/model differs.
            if ($candidate['role'] === $failedRole
                && $candidate['provider'] === $failedProvider
                && $candidate['model'] === $failedModel) {
                continue;
            }

            return [
                'role' => $candidate['role'],
                'provider' => $candidate['provider'],
                'model' => $candidate['model'],
                'order' => 0,
                'source' => 'topology_role',
            ];
        }

        return null;
    }

    /**
     * Cooldown seconds keyed by canonical failure type. Aligned with
     * AtlasForgeProviderFailureMemoryService::COOLDOWN_BY_FAILURE so policy
     * and memory always agree on the suggested cooldown window.
     *
     * Pure typing: no wall-clock; callers format timestamps.
     */
    public static function cooldownSecondsFor(string $failureType): int
    {
        return match ($failureType) {
            Policy::FAILURE_RATE_LIMIT => 60,
            Policy::FAILURE_QUOTA_EXHAUSTED => 600,
            Policy::FAILURE_TIMEOUT => 30,
            Policy::FAILURE_MODEL_UNAVAILABLE => 120,
            Policy::FAILURE_PROVIDER_ERROR => 30,
            Policy::FAILURE_CAPACITY_EXHAUSTED => 900,
            default => 0,
        };
    }
}
