<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

final class RepairRetryHintPolicyEvaluator
{
    private const SCHEMA_VERSION = 'atlas.repair.retry_hint_policy.v1';

    private const AUTO_REPAIRABLE_HINTS = [
        'retry_with_fresh_context',
        'narrow_scope',
        'add_tests_first',
    ];

    private const ESCALATING_HINTS = [
        'escalate_to_human',
        'request_operator_input',
    ];

    /**
     * @param  array<string, mixed>  $failedCycle
     * @param  array<string, mixed>  $state
     * @return array{
     *     schema_version: string,
     *     decision: string,
     *     next_attempt_allowed: bool,
     *     attempt_count_next: int,
     *     stop_reason: ?string,
     *     evidence_required: bool
     * }
     */
    public function evaluate(array $failedCycle, array $state, int $maxAttempts = 3): array
    {
        $maxAttempts = max(1, $maxAttempts);
        $attemptCount = $this->currentAttemptCount($state, $failedCycle);
        $hint = $this->repairHint($failedCycle);

        if ($this->gatePassed($failedCycle)) {
            return $this->result('resolved', false, $attemptCount, 'gate_passed');
        }

        if (in_array($hint, self::ESCALATING_HINTS, true)) {
            return $this->result('escalate', false, $attemptCount, $hint);
        }

        if (in_array($hint, self::AUTO_REPAIRABLE_HINTS, true)) {
            if ($attemptCount >= $maxAttempts) {
                return $this->result('exhausted', false, $attemptCount, 'max_attempts_reached');
            }

            return $this->result('retry', true, $attemptCount + 1, null);
        }

        return $this->result('escalate', false, $attemptCount, 'unrepairable_hint');
    }

    /**
     * @return array{
     *     schema_version: string,
     *     decision: string,
     *     next_attempt_allowed: bool,
     *     attempt_count_next: int,
     *     stop_reason: ?string,
     *     evidence_required: bool
     * }
     */
    private function result(string $decision, bool $nextAttemptAllowed, int $attemptCountNext, ?string $stopReason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $decision,
            'next_attempt_allowed' => $nextAttemptAllowed,
            'attempt_count_next' => $attemptCountNext,
            'stop_reason' => $stopReason,
            'evidence_required' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $failedCycle
     */
    private function currentAttemptCount(array $state, array $failedCycle): int
    {
        foreach (['repair_loop_attempt_count', 'attempt_count', 'attempts'] as $key) {
            if (array_key_exists($key, $state)) {
                if (! is_numeric($state[$key])) {
                    continue;
                }

                return max(0, $this->intValue($state[$key]));
            }
        }

        if (array_key_exists('cycle_position', $failedCycle)) {
            return max(0, $this->intValue($failedCycle['cycle_position']));
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $failedCycle
     */
    private function repairHint(array $failedCycle): ?string
    {
        $hook = $failedCycle['repair_hook'] ?? null;

        if (is_array($hook) && isset($hook['hint']) && is_string($hook['hint'])) {
            $hint = trim($hook['hint']);

            return $hint === '' ? null : $hint;
        }

        if (isset($failedCycle['repair_hint']) && is_string($failedCycle['repair_hint'])) {
            $hint = trim($failedCycle['repair_hint']);

            return $hint === '' ? null : $hint;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $failedCycle
     */
    private function gatePassed(array $failedCycle): bool
    {
        $gate = $failedCycle['gate'] ?? null;

        if (is_array($gate)) {
            return ($gate['passed'] ?? false) === true;
        }

        return ($failedCycle['gate_passed'] ?? false) === true;
    }

    private function intValue(mixed $value): int
    {
        return is_int($value) ? $value : (int) $value;
    }
}
