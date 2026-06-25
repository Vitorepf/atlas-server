<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Retry;

/**
 * Fail-closed give-back retry policy. Returns a deterministic {@see RetryDecision} keyed by
 * (task_packet_id, reshape_fingerprint, retries_used, cooldown_until).
 *
 * Rules (evaluated in this order; first match wins):
 *   1. loop_detected     — new reshape_fingerprint == previous attempt's fingerprint.
 *   2. budget_exhausted  — retries_used >= max_retries.
 *   3. cooldown_active   — now < cooldown_until.
 *   4. allow             — otherwise.
 */
final class AtlasMaestroGiveBackRetryPolicy
{
    public const DEFAULT_MAX_RETRIES = 2;

    public function __construct(
        private readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
    ) {}

    /**
     * @param  array{
     *   task_packet_id:string,
     *   retries_used:int,
     *   previous_fingerprint?:?string,
     *   new_fingerprint:string,
     *   cooldown_until_unix?:int,
     *   now_unix?:int,
     * } $facts
     */
    public function evaluate(array $facts): RetryDecision
    {
        $retriesUsed = (int) ($facts['retries_used'] ?? 0);
        $nextAttempt = $retriesUsed + 1;

        $newFingerprint = (string) ($facts['new_fingerprint'] ?? '');
        $previousFingerprint = isset($facts['previous_fingerprint']) ? (string) $facts['previous_fingerprint'] : '';

        if ($newFingerprint !== '' && $previousFingerprint !== '' && $newFingerprint === $previousFingerprint) {
            return RetryDecision::deny(RetryDecision::REASON_LOOP_DETECTED, $nextAttempt);
        }

        if ($retriesUsed >= $this->maxRetries) {
            return RetryDecision::deny(RetryDecision::REASON_BUDGET_EXHAUSTED, $nextAttempt);
        }

        $cooldownUntil = (int) ($facts['cooldown_until_unix'] ?? 0);
        $now = (int) ($facts['now_unix'] ?? time());
        if ($cooldownUntil > 0 && $now < $cooldownUntil) {
            return RetryDecision::deny(RetryDecision::REASON_COOLDOWN_ACTIVE, $nextAttempt);
        }

        return RetryDecision::allow($nextAttempt);
    }
}
