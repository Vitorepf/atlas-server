<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

final class CompoundingFlywheelCertificationEvaluator
{
    private const SCHEMA_VERSION = 'atlas.loop.compounding_flywheel_certification.v1';

    private const DEFAULT_REQUIRED_CYCLES = 3;

    private const DEFAULT_REGRESSION_TOLERANCE = 0.0;

    /**
     * @param  list<array<string,mixed>>  $cycles
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function certify(array $cycles, array $options = []): array
    {
        $requiredCycles = $this->requiredCycles($options);
        $tolerance = $this->regressionTolerance($options);

        $cycleCount = count($cycles);
        $positiveLiftCount = 0;
        $unmeasuredAutoApplyCount = 0;
        $aggregateLift = 0.0;
        $hasRegression = false;
        $hasReplayMismatch = false;

        foreach ($cycles as $cycle) {
            $measured = $this->isMeasured($cycle);
            $autoApply = $this->isAutoApply($cycle);
            $lift = $this->liftValue($cycle);

            if ($autoApply && ! $measured) {
                $unmeasuredAutoApplyCount++;
            }

            if ($this->hasReplayMismatch($cycle)) {
                $hasReplayMismatch = true;
            }

            if ($measured) {
                $aggregateLift += $lift;

                if ($lift > 0.0) {
                    $positiveLiftCount++;
                }

                if ($lift < -$tolerance) {
                    $hasRegression = true;
                }
            }
        }

        $aggregateLift = round($aggregateLift, 4);

        $blockers = [];

        if ($cycleCount < $requiredCycles) {
            $blockers[] = 'insufficient_cycles';
        }

        if ($unmeasuredAutoApplyCount > 0) {
            $blockers[] = 'unmeasured_auto_apply';
        }

        if ($hasReplayMismatch) {
            $blockers[] = 'replay_hash_mismatch';
        }

        if ($hasRegression) {
            $blockers[] = 'regression_below_tolerance';
        }

        if ($aggregateLift <= 0.0) {
            $blockers[] = 'non_positive_aggregate_lift';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'certified' => $blockers === [],
            'cycle_count' => $cycleCount,
            'positive_lift_count' => $positiveLiftCount,
            'unmeasured_auto_apply_count' => $unmeasuredAutoApplyCount,
            'blockers' => $blockers,
            'aggregate_lift' => $aggregateLift,
        ];
    }

    /** @param array<string,mixed> $options */
    private function requiredCycles(array $options): int
    {
        $value = $options['required_cycles'] ?? $options['min_cycles'] ?? self::DEFAULT_REQUIRED_CYCLES;

        $required = is_int($value) ? $value : (int) $value;

        return max(1, $required);
    }

    /** @param array<string,mixed> $options */
    private function regressionTolerance(array $options): float
    {
        $value = $options['regression_tolerance'] ?? $options['tolerance'] ?? self::DEFAULT_REGRESSION_TOLERANCE;

        $tolerance = is_float($value) || is_int($value) ? (float) $value : self::DEFAULT_REGRESSION_TOLERANCE;

        return abs($tolerance);
    }

    /** @param array<string,mixed> $cycle */
    private function isMeasured(array $cycle): bool
    {
        return ($cycle['measured'] ?? $cycle['delta_measured'] ?? false) === true;
    }

    /** @param array<string,mixed> $cycle */
    private function isAutoApply(array $cycle): bool
    {
        return ($cycle['auto_apply'] ?? $cycle['auto_applied'] ?? false) === true;
    }

    /** @param array<string,mixed> $cycle */
    private function liftValue(array $cycle): float
    {
        $value = $cycle['lift'] ?? $cycle['delta'] ?? 0.0;

        return is_float($value) || is_int($value) ? (float) $value : 0.0;
    }

    /** @param array<string,mixed> $cycle */
    private function hasReplayMismatch(array $cycle): bool
    {
        if (! array_key_exists('expected_replay_hash', $cycle)) {
            return false;
        }

        $expected = $cycle['expected_replay_hash'];

        if ($expected === null) {
            return false;
        }

        return ($cycle['replay_hash'] ?? null) !== $expected;
    }
}
