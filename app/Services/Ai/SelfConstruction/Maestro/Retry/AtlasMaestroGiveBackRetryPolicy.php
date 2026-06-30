<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Retry;

/**
 * Fail-closed give-back retry policy. Returns a deterministic {@see RetryDecision} keyed by
 * (task_packet_id, give_back_reason_class, give_back_count, prior_success_count, retries_used, cooldown_until).
 *
 * Three decision classes (first match wins):
 *   1. packet_defect (give_back_reason_class)  → REASON_RESPEC_NEEDED  — stop blind retries; caller must respec.
 *   2. give_back_count >= threshold             → REASON_QUARANTINE     — no prior success (never worked).
 *                                               → REASON_RESPEC_NEEDED  — has prior success (regression, respec).
 *   3. worker_mismatch (give_back_reason_class) → allow                 — packet fine, wrong worker; retry.
 *   4. loop_detected                            → deny                  — same reshape fingerprint.
 *   5. budget_exhausted                         → deny.
 *   6. cooldown_active                          → deny.
 *   7. allow.
 */
final class AtlasMaestroGiveBackRetryPolicy
{
    public const DEFAULT_MAX_RETRIES = 2;

    public const DEFAULT_REPEATED_GIVE_BACK_THRESHOLD = 3;

    /** @deprecated Use REASON_QUARANTINE or REASON_RESPEC_NEEDED instead. */
    public const REASON_RESPEC_OR_RETIRE = 'respec_or_retire';

    public const DECISION_CLASS_RETRY      = 'retry';
    public const DECISION_CLASS_RESPEC     = 'respec';
    public const DECISION_CLASS_QUARANTINE = 'quarantine';

    public const REASON_WORKER_MISMATCH = 'worker_mismatch';
    public const REASON_RESPEC_NEEDED   = 'respec_needed';
    public const REASON_QUARANTINE      = 'quarantine';

    public function __construct(
        private readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
        private readonly int $repeatedGiveBackThreshold = self::DEFAULT_REPEATED_GIVE_BACK_THRESHOLD,
    ) {}

    /**
     * @param  array{
     *   task_packet_id:string,
     *   retries_used:int,
     *   give_back_count?:int,
     *   give_back_reason_class?:string,
     *   prior_success_count?:int,
     *   packet_defect_fields?:list<string>,
     *   previous_fingerprint?:?string,
     *   new_fingerprint:string,
     *   cooldown_until_unix?:int,
     *   now_unix?:int,
     * } $facts
     */
    public function evaluate(array $facts): RetryDecision
    {
        $retriesUsed         = (int) ($facts['retries_used']           ?? 0);
        $nextAttempt         = $retriesUsed + 1;
        $giveBackCount       = (int) ($facts['give_back_count']        ?? 0);
        $giveBackReasonClass = (string) ($facts['give_back_reason_class'] ?? '');
        $priorSuccessCount   = (int) ($facts['prior_success_count']    ?? 0);

        // Rule 1: packet_defect — stop blind retries; packet structure must be fixed.
        if ($giveBackReasonClass === 'packet_defect') {
            return RetryDecision::deny(self::REASON_RESPEC_NEEDED, $nextAttempt);
        }

        // Rule 2: repeated give-backs at threshold → quarantine (never worked) or respec (regression).
        if ($giveBackCount >= $this->repeatedGiveBackThreshold) {
            if ($priorSuccessCount > 0) {
                return RetryDecision::deny(self::REASON_RESPEC_NEEDED, $nextAttempt);
            }

            return RetryDecision::deny(self::REASON_QUARANTINE, $nextAttempt);
        }

        // Rule 3: worker_mismatch — packet is sound; retry with a different worker.
        if ($giveBackReasonClass === 'worker_mismatch') {
            return RetryDecision::allow($nextAttempt);
        }

        $newFingerprint      = (string) ($facts['new_fingerprint']      ?? '');
        $previousFingerprint = isset($facts['previous_fingerprint']) ? (string) $facts['previous_fingerprint'] : '';

        // Rule 4: loop detected (same reshape fingerprint).
        if ($newFingerprint !== '' && $previousFingerprint !== '' && $newFingerprint === $previousFingerprint) {
            return RetryDecision::deny(RetryDecision::REASON_LOOP_DETECTED, $nextAttempt);
        }

        // Rule 5: budget exhausted.
        if ($retriesUsed >= $this->maxRetries) {
            return RetryDecision::deny(RetryDecision::REASON_BUDGET_EXHAUSTED, $nextAttempt);
        }

        // Rule 6: cooldown active.
        $cooldownUntil = (int) ($facts['cooldown_until_unix'] ?? 0);
        $now           = (int) ($facts['now_unix']           ?? time());
        if ($cooldownUntil > 0 && $now < $cooldownUntil) {
            return RetryDecision::deny(RetryDecision::REASON_COOLDOWN_ACTIVE, $nextAttempt);
        }

        return RetryDecision::allow($nextAttempt);
    }

    /**
     * Returns the minimum fields a caller must supply in a respec to produce a valid packet.
     * Only meaningful when evaluate() returns REASON_RESPEC_NEEDED on a packet_defect give-back.
     *
     * @param  array<string,mixed>  $facts
     * @return array{decision_class:string, required_fields:list<string>}
     */
    public function respecFieldsFor(array $facts): array
    {
        $giveBackReasonClass = (string) ($facts['give_back_reason_class'] ?? '');

        if ($giveBackReasonClass !== 'packet_defect') {
            return ['decision_class' => self::DECISION_CLASS_QUARANTINE, 'required_fields' => []];
        }

        $defectFields = array_values(array_filter(
            array_map('strval', (array) ($facts['packet_defect_fields'] ?? [])),
            fn (string $f) => $f !== ''
        ));

        return [
            'decision_class'  => self::DECISION_CLASS_RESPEC,
            'required_fields' => $defectFields === [] ? ['objective', 'allowed_files', 'acceptance_criteria'] : $defectFields,
        ];
    }
}
