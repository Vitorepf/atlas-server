<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Post-L7 regression watchdog.
 *
 * Monitors a rolling window of post-promotion cycles for five regression
 * signals: post-L7 regression, Trust Ledger drop, sacred invariant breach,
 * quality utilization below 96 (useful_cycle_rate < 0.96) and provider
 * honesty failure. Per the autonomy ladder runbook a demote is automatic and
 * never requires a signature: two consecutive cycles tripping any rule demote
 * the level by exactly one degree (L7 -> L6). The watchdog only reports; it
 * never mutates state or applies the demote.
 */
final class PostL7RegressionDemoteWatchdog
{
    private const SCHEMA_VERSION = 'atlas.loop.post_l7_regression_demote_watchdog.v1';

    /**
     * Trust Ledger gate: a score below this is a regression signal.
     */
    private const TRUST_LEDGER_FLOOR = 0.95;

    /**
     * Quality utilization gate: useful_cycle_rate below this is a regression
     * signal ("quality utilization <96").
     */
    private const USEFUL_CYCLE_RATE_FLOOR = 0.96;

    /**
     * Consecutive bad cycles required to force an automatic demote.
     */
    private const CONSECUTIVE_BAD_CYCLES_FOR_DEMOTE = 2;

    private const STATUS_PASS = 'pass';
    private const STATUS_DEMOTE_REQUIRED = 'demote_required';
    private const STATUS_UNKNOWN_BLOCKED = 'unknown_blocked';

    private const RULE_REGRESSION = 'post_l7_regression';
    private const RULE_TRUST_DROP = 'trust_drop';
    private const RULE_INVARIANT_BREACH = 'invariant_breach';
    private const RULE_QUALITY_UTILIZATION = 'quality_utilization_below_96';
    private const RULE_PROVIDER_HONESTY = 'provider_honesty_failure';

    private const DEFAULT_CURRENT_LEVEL = 7;
    private const FLOOR_LEVEL = 0;

    /**
     * @param array<string, mixed> $window
     *
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     regression_window: array{observed_cycles: int, bad_cycle_count: int, max_consecutive_bad: int, evidence_sufficient: bool},
     *     triggered_rules: list<string>,
     *     demote_required: bool,
     *     current_level: int,
     *     target_level: int,
     *     demote_receipt_ref: ?string
     * }
     */
    public function evaluate(array $window): array
    {
        $currentLevel = $this->levelValue($window, 'current_level', self::DEFAULT_CURRENT_LEVEL);
        $cycles = $this->cycles($window);

        $badFlags = [];
        $triggeredRules = [];
        foreach ($cycles as $cycle) {
            $rules = $this->rulesForCycle($cycle);
            $badFlags[] = $rules !== [];
            foreach ($rules as $rule) {
                $triggeredRules[$rule] = true;
            }
        }

        $observedCycles = count($cycles);
        $badCycleCount = count(array_filter($badFlags));
        $maxConsecutiveBad = $this->maxConsecutive($badFlags);
        $evidenceSufficient = $observedCycles >= self::CONSECUTIVE_BAD_CYCLES_FOR_DEMOTE;

        if (! $evidenceSufficient) {
            $status = self::STATUS_UNKNOWN_BLOCKED;
            $demoteRequired = false;
        } elseif ($maxConsecutiveBad >= self::CONSECUTIVE_BAD_CYCLES_FOR_DEMOTE) {
            $status = self::STATUS_DEMOTE_REQUIRED;
            $demoteRequired = true;
        } else {
            $status = self::STATUS_PASS;
            $demoteRequired = false;
        }

        $targetLevel = $demoteRequired
            ? max(self::FLOOR_LEVEL, $currentLevel - 1)
            : $currentLevel;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'regression_window' => [
                'observed_cycles' => $observedCycles,
                'bad_cycle_count' => $badCycleCount,
                'max_consecutive_bad' => $maxConsecutiveBad,
                'evidence_sufficient' => $evidenceSufficient,
            ],
            'triggered_rules' => array_values(array_keys($triggeredRules)),
            'demote_required' => $demoteRequired,
            'current_level' => $currentLevel,
            'target_level' => $targetLevel,
            'demote_receipt_ref' => $demoteRequired
                ? $this->demoteReceiptRef($currentLevel, $targetLevel)
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $cycle
     *
     * @return list<string> Ordered list of rule identifiers tripped by this cycle.
     */
    private function rulesForCycle(array $cycle): array
    {
        $rules = [];

        if ($this->isRegression($cycle)) {
            $rules[] = self::RULE_REGRESSION;
        }

        if ($this->floatValue($cycle, 'trust_ledger_score', self::TRUST_LEDGER_FLOOR) < self::TRUST_LEDGER_FLOOR) {
            $rules[] = self::RULE_TRUST_DROP;
        }

        if ($this->intValue($cycle, 'invariant_breach_count', 0) > 0) {
            $rules[] = self::RULE_INVARIANT_BREACH;
        }

        if ($this->usefulCycleRate($cycle) < self::USEFUL_CYCLE_RATE_FLOOR) {
            $rules[] = self::RULE_QUALITY_UTILIZATION;
        }

        if (($cycle['provider_honesty_ok'] ?? true) === false) {
            $rules[] = self::RULE_PROVIDER_HONESTY;
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $cycle
     */
    private function isRegression(array $cycle): bool
    {
        if (($cycle['regression'] ?? false) === true) {
            return true;
        }

        return $this->intValue($cycle, 'regression_count', 0) > 0;
    }

    /**
     * Quality utilization can be supplied either as a 0..1 useful_cycle_rate
     * or as a 0..100 percentage ("<96"). Normalise to the 0..1 rate.
     *
     * @param array<string, mixed> $cycle
     */
    private function usefulCycleRate(array $cycle): float
    {
        if (array_key_exists('useful_cycle_rate', $cycle)) {
            return $this->floatValue($cycle, 'useful_cycle_rate', self::USEFUL_CYCLE_RATE_FLOOR);
        }

        if (array_key_exists('quality_utilization', $cycle)) {
            return $this->floatValue($cycle, 'quality_utilization', self::USEFUL_CYCLE_RATE_FLOOR * 100.0) / 100.0;
        }

        return self::USEFUL_CYCLE_RATE_FLOOR;
    }

    /**
     * @param array<string, mixed> $window
     *
     * @return list<array<string, mixed>>
     */
    private function cycles(array $window): array
    {
        $raw = $window['cycles'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $cycles = [];
        foreach ($raw as $cycle) {
            if (is_array($cycle)) {
                $cycles[] = $cycle;
            }
        }

        return $cycles;
    }

    /**
     * Longest run of consecutive true flags.
     *
     * @param list<bool> $flags
     */
    private function maxConsecutive(array $flags): int
    {
        $best = 0;
        $run = 0;
        foreach ($flags as $flag) {
            if ($flag) {
                $run++;
                if ($run > $best) {
                    $best = $run;
                }
            } else {
                $run = 0;
            }
        }

        return $best;
    }

    private function demoteReceiptRef(int $fromLevel, int $toLevel): string
    {
        return self::SCHEMA_VERSION . ':demote:L' . $fromLevel . '->L' . $toLevel;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function levelValue(array $payload, string $key, int $default): int
    {
        $value = $this->intValue($payload, $key, $default);

        return max(self::FLOOR_LEVEL, $value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function intValue(array $payload, string $key, int $default): int
    {
        $value = $payload[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function floatValue(array $payload, string $key, float $default): float
    {
        $value = $payload[$key] ?? $default;

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }
}
