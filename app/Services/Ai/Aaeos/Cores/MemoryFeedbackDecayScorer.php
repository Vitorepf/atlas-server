<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Cores;

final class MemoryFeedbackDecayScorer
{
    private const SCHEMA_VERSION = 'atlas.aaeos.memory_feedback_decay.v1';

    private const HARD_STALE_AGE_DAYS = 180;

    private const SOFT_STALE_AGE_DAYS = 45;

    private const DEFAULT_BASE_PRIORITY = 50;

    private const ARCHIVE_STALE_FEEDBACK_THRESHOLD = 2;

    private const INACTIVATE_NEGATIVE_THRESHOLD = 3;

    private const INACTIVATE_HEALTH_CEILING = 40;

    private const DEGRADE_HEALTH_CEILING = 60;

    /**
     * @param  array<string, mixed>  $signals
     * @return array{
     *     schema_version: string,
     *     health_score: int,
     *     effective_priority: int,
     *     lifecycle_action: string,
     *     staleness: string,
     *     age_days: int|null,
     *     threshold_reasons: list<string>,
     *     inputs_echo: array<string, mixed>
     * }
     */
    public function score(array $signals): array
    {
        $positive = $this->nonNegativeInt($signals, 'positive_count');
        $negative = $this->nonNegativeInt($signals, 'negative_count');
        $wrongContext = $this->nonNegativeInt($signals, 'wrong_context_count');
        $stale = $this->nonNegativeInt($signals, 'stale_count');
        $basePriority = $this->clamp(0, 100, $this->intOrDefault($signals, 'base_priority', self::DEFAULT_BASE_PRIORITY));

        $recordedAge = $this->ageOrNull($signals, 'recorded_at_age_days');
        $lastUsedAge = $this->ageOrNull($signals, 'last_used_at_age_days');
        $hitRate = $this->hitRateOrNull($signals);

        $healthScore = $this->clamp(
            0,
            100,
            100 + ($positive * 8) - (($negative * 18) + ($wrongContext * 10) + ($stale * 12)),
        );

        $effectivePriority = $this->clamp(
            0,
            100,
            $basePriority + ($positive * 4) - (($negative * 10) + ($wrongContext * 8) + ($stale * 12)),
        );

        $reasons = [];

        $recordedHardStale = $recordedAge !== null && $recordedAge > self::HARD_STALE_AGE_DAYS;
        $lastUsedHardStale = $lastUsedAge !== null && $lastUsedAge > self::HARD_STALE_AGE_DAYS;
        $decayActive = $recordedHardStale || $lastUsedHardStale;

        if ($decayActive) {
            $effectivePriority = (int) intdiv($effectivePriority, 2);
            $reasons[] = 'stale_age_exceeds_180d';
        }

        $staleness = $this->resolveStaleness($recordedAge, $lastUsedAge, $recordedHardStale, $lastUsedHardStale);

        if ($this->softStale($recordedAge) || $this->softStale($lastUsedAge)) {
            $reasons[] = 'soft_stale_age_exceeds_45d';
        }

        $lifecycleAction = $this->resolveLifecycleAction(
            $stale,
            $negative,
            $wrongContext,
            $healthScore,
            $staleness,
            $hitRate,
            $reasons,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'health_score' => $healthScore,
            'effective_priority' => $effectivePriority,
            'lifecycle_action' => $lifecycleAction,
            'staleness' => $staleness,
            'age_days' => $recordedAge,
            'threshold_reasons' => array_values($reasons),
            'inputs_echo' => [
                'positive_count' => $positive,
                'negative_count' => $negative,
                'wrong_context_count' => $wrongContext,
                'stale_count' => $stale,
                'base_priority' => $basePriority,
                'recorded_at_age_days' => $recordedAge,
                'last_used_at_age_days' => $lastUsedAge,
                'recall_eval_hit_rate' => $hitRate,
            ],
        ];
    }

    /**
     * @param  list<string>  $reasons
     */
    private function resolveLifecycleAction(
        int $stale,
        int $negative,
        int $wrongContext,
        int $healthScore,
        string $staleness,
        ?float $hitRate,
        array &$reasons,
    ): string {
        if ($stale >= self::ARCHIVE_STALE_FEEDBACK_THRESHOLD) {
            $reasons[] = 'archived_by_stale_feedback';

            return 'archive';
        }

        if ($negative >= self::INACTIVATE_NEGATIVE_THRESHOLD && $healthScore <= self::INACTIVATE_HEALTH_CEILING) {
            $reasons[] = 'inactivated_by_negative_feedback';

            return 'inactivate';
        }

        if ($staleness === 'stale_inactive_candidate') {
            $reasons[] = 'inactivated_by_stale_age';

            return 'inactivate';
        }

        if ($negative >= self::INACTIVATE_NEGATIVE_THRESHOLD || $wrongContext > 0 || $healthScore <= self::DEGRADE_HEALTH_CEILING) {
            $reasons[] = 'degraded_by_feedback_pressure';

            return 'degrade';
        }

        if ($staleness === 'stale_review_recommended') {
            $reasons[] = 'degraded_by_stale_age';

            return 'degrade';
        }

        if ($staleness === 'fresh' && $hitRate === 0.0) {
            $reasons[] = 'degraded_by_low_recall_hit_rate';

            return 'degrade';
        }

        return 'keep';
    }

    private function resolveStaleness(
        ?int $recordedAge,
        ?int $lastUsedAge,
        bool $recordedHardStale,
        bool $lastUsedHardStale,
    ): string {
        if ($recordedHardStale && $lastUsedHardStale) {
            return 'stale_inactive_candidate';
        }

        if ($recordedHardStale || $lastUsedHardStale) {
            return 'stale_review_recommended';
        }

        if ($this->softStale($recordedAge) || $this->softStale($lastUsedAge)) {
            return 'stale_review_recommended';
        }

        return 'fresh';
    }

    private function softStale(?int $age): bool
    {
        return $age !== null
            && $age > self::SOFT_STALE_AGE_DAYS
            && $age <= self::HARD_STALE_AGE_DAYS;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function nonNegativeInt(array $signals, string $key): int
    {
        $value = $signals[$key] ?? 0;

        if (! is_int($value) && ! (is_float($value) && is_finite($value)) && ! (is_string($value) && is_numeric($value))) {
            return 0;
        }

        $int = (int) $value;

        return $int < 0 ? 0 : $int;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function intOrDefault(array $signals, string $key, int $default): int
    {
        $value = $signals[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function ageOrNull(array $signals, string $key): ?int
    {
        $value = $signals[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value < 0 ? 0 : $value;
        }

        if (is_float($value) && is_finite($value)) {
            $int = (int) $value;

            return $int < 0 ? 0 : $int;
        }

        if (is_string($value) && is_numeric($value)) {
            $int = (int) $value;

            return $int < 0 ? 0 : $int;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function hitRateOrNull(array $signals): ?float
    {
        $value = $signals['recall_eval_hit_rate'] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value) || (is_float($value) && is_finite($value)) || (is_string($value) && is_numeric($value))) {
            $float = (float) $value;

            if ($float < 0.0) {
                return 0.0;
            }

            if ($float > 1.0) {
                return 1.0;
            }

            return $float;
        }

        return null;
    }

    private function clamp(int $min, int $max, int $value): int
    {
        return min($max, max($min, $value));
    }
}
