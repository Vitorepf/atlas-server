<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Scoring;

use App\Services\Ai\Support\AiValueNormalizer;

final class MemoryFeedbackDecayScorer
{
    public const SCHEMA_VERSION = 'atlas.aaeos.memory_feedback_decay.v1';

    public const HARD_STALE_AGE_DAYS = 180;

    public const SOFT_STALE_AGE_DAYS = 45;

    public const DEFAULT_BASE_PRIORITY = 50;

    public const ARCHIVE_STALE_FEEDBACK_THRESHOLD = 2;

    public const INACTIVATE_NEGATIVE_THRESHOLD = 3;

    public const INACTIVATE_HEALTH_CEILING = 40;

    public const DEGRADE_HEALTH_CEILING = 60;

    public const DECISION_ARCHIVE = 'archive';

    public const DECISION_INACTIVATE = 'inactivate';

    public const DECISION_DEGRADE = 'degrade';

    public const DECISION_KEEP = 'keep';

    public const DECISION_STALE_INACTIVE_CANDIDATE = 'stale_inactive_candidate';

    public const DECISION_STALE_REVIEW_RECOMMENDED = 'stale_review_recommended';

    public const DECISION_FRESH = 'fresh';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_HEALTH_SCORE = 'health_score';
    public const FIELD_EFFECTIVE_PRIORITY = 'effective_priority';
    public const FIELD_LIFECYCLE_ACTION = 'lifecycle_action';
    public const FIELD_STALENESS = 'staleness';
    public const FIELD_AGE_DAYS = 'age_days';
    public const FIELD_THRESHOLD_REASONS = 'threshold_reasons';
    public const FIELD_INPUTS_ECHO = 'inputs_echo';
    public const FIELD_RECALL_EVAL_HIT_RATE = 'recall_eval_hit_rate';
    public const FIELD_BASE_PRIORITY = 'base_priority';
    public const FIELD_LAST_USED_AT_AGE_DAYS = 'last_used_at_age_days';
    public const FIELD_NEGATIVE_COUNT = 'negative_count';
    public const FIELD_POSITIVE_COUNT = 'positive_count';
    public const FIELD_RECORDED_AT_AGE_DAYS = 'recorded_at_age_days';
    public const FIELD_STALE_COUNT = 'stale_count';
    public const FIELD_WRONG_CONTEXT_COUNT = 'wrong_context_count';
    public const FIELD_ARCHIVED_BY_STALE_FEEDBACK = 'archived_by_stale_feedback';
    public const FIELD_DEGRADED_BY_FEEDBACK_PRESSURE = 'degraded_by_feedback_pressure';
    public const FIELD_DEGRADED_BY_LOW_RECALL_HIT_RATE = 'degraded_by_low_recall_hit_rate';
    public const FIELD_DEGRADED_BY_STALE_AGE = 'degraded_by_stale_age';
    public const FIELD_INACTIVATED_BY_NEGATIVE_FEEDBACK = 'inactivated_by_negative_feedback';
    public const FIELD_INACTIVATED_BY_STALE_AGE = 'inactivated_by_stale_age';
    public const FIELD_SOFT_STALE_AGE_EXCEEDS_45D = 'soft_stale_age_exceeds_45d';
    public const FIELD_STALE_AGE_EXCEEDS_180D = 'stale_age_exceeds_180d';
    public const FLOAT_0_0 = 0.0;

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
        $positive = $this->nonNegativeInt($signals, self::FIELD_POSITIVE_COUNT);
        $negative = $this->nonNegativeInt($signals, self::FIELD_NEGATIVE_COUNT);
        $wrongContext = $this->nonNegativeInt($signals, self::FIELD_WRONG_CONTEXT_COUNT);
        $stale = $this->nonNegativeInt($signals, self::FIELD_STALE_COUNT);
        $basePriority = $this->clamp(0, 100, $this->intOrDefault($signals, self::FIELD_BASE_PRIORITY, self::DEFAULT_BASE_PRIORITY));

        $recordedAge = $this->ageOrNull($signals, self::FIELD_RECORDED_AT_AGE_DAYS);
        $lastUsedAge = $this->ageOrNull($signals, self::FIELD_LAST_USED_AT_AGE_DAYS);
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

        $lastUsedRecently = $lastUsedAge !== null && $lastUsedAge <= self::SOFT_STALE_AGE_DAYS;
        $recordedAgeForDecay = $lastUsedRecently ? null : $recordedAge;

        $recordedHardStale = $recordedAgeForDecay !== null && $recordedAgeForDecay > self::HARD_STALE_AGE_DAYS;
        $lastUsedHardStale = $lastUsedAge !== null && $lastUsedAge > self::HARD_STALE_AGE_DAYS;
        $decayActive = $recordedHardStale || $lastUsedHardStale;

        if ($decayActive) {
            $effectivePriority = (int) intdiv($effectivePriority, 2);
            $reasons[] = self::FIELD_STALE_AGE_EXCEEDS_180D;
        }

        $staleness = $this->resolveStaleness($recordedAgeForDecay, $lastUsedAge, $recordedHardStale, $lastUsedHardStale);

        if ($this->softStale($recordedAgeForDecay) || $this->softStale($lastUsedAge)) {
            $reasons[] = self::FIELD_SOFT_STALE_AGE_EXCEEDS_45D;
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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_HEALTH_SCORE => $healthScore,
            self::FIELD_EFFECTIVE_PRIORITY => $effectivePriority,
            self::FIELD_LIFECYCLE_ACTION => $lifecycleAction,
            self::FIELD_STALENESS => $staleness,
            self::FIELD_AGE_DAYS => $recordedAge,
            self::FIELD_THRESHOLD_REASONS => array_values($reasons),
            self::FIELD_INPUTS_ECHO => [
                self::FIELD_POSITIVE_COUNT => $positive,
                self::FIELD_NEGATIVE_COUNT => $negative,
                self::FIELD_WRONG_CONTEXT_COUNT => $wrongContext,
                self::FIELD_STALE_COUNT => $stale,
                self::FIELD_BASE_PRIORITY => $basePriority,
                self::FIELD_RECORDED_AT_AGE_DAYS => $recordedAge,
                self::FIELD_LAST_USED_AT_AGE_DAYS => $lastUsedAge,
                self::FIELD_RECALL_EVAL_HIT_RATE => $hitRate,
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
            $reasons[] = self::FIELD_ARCHIVED_BY_STALE_FEEDBACK;

            return self::DECISION_ARCHIVE;
        }

        if ($negative >= self::INACTIVATE_NEGATIVE_THRESHOLD && $healthScore <= self::INACTIVATE_HEALTH_CEILING) {
            $reasons[] = self::FIELD_INACTIVATED_BY_NEGATIVE_FEEDBACK;

            return self::DECISION_INACTIVATE;
        }

        if ($staleness === self::DECISION_STALE_INACTIVE_CANDIDATE) {
            $reasons[] = self::FIELD_INACTIVATED_BY_STALE_AGE;

            return self::DECISION_INACTIVATE;
        }

        // CONTRATO CONGELADO (AND-not-OR, teste de 01/06): wrong_context
        // sozinho NUNCA inativa — inativação exige o gate conjuntivo
        // (negativos>=limiar E health<=teto); wrong_context apenas degrada.
        // Um auto-merge do Loop em 13/06 (6df5fa50c6, pré-O-3/reprove)
        // enxertou aqui uma regra que inativava memórias agressivamente e
        // quebrou o teste congelado por 3 semanas — removido em 03/07.
        if ($negative >= self::INACTIVATE_NEGATIVE_THRESHOLD || $wrongContext > 0 || $healthScore <= self::DEGRADE_HEALTH_CEILING) {
            $reasons[] = self::FIELD_DEGRADED_BY_FEEDBACK_PRESSURE;

            return self::DECISION_DEGRADE;
        }

        if ($staleness === self::DECISION_STALE_REVIEW_RECOMMENDED) {
            $reasons[] = self::FIELD_DEGRADED_BY_STALE_AGE;

            return self::DECISION_DEGRADE;
        }

        if ($staleness === self::DECISION_FRESH && $hitRate === self::FLOAT_0_0) {
            $reasons[] = self::FIELD_DEGRADED_BY_LOW_RECALL_HIT_RATE;

            return self::DECISION_DEGRADE;
        }

        return self::DECISION_KEEP;
    }

    private function resolveStaleness(
        ?int $recordedAge,
        ?int $lastUsedAge,
        bool $recordedHardStale,
        bool $lastUsedHardStale,
    ): string {
        if ($recordedHardStale && $lastUsedHardStale) {
            return self::DECISION_STALE_INACTIVE_CANDIDATE;
        }

        if ($recordedHardStale || $lastUsedHardStale) {
            return self::DECISION_STALE_REVIEW_RECOMMENDED;
        }

        // CONTRATO CONGELADO (teste de 01/06): banda SOFT-stale é AVISO
        // (threshold_reasons carrega soft_stale_age_exceeds_45d) — o ESTADO
        // continua 'fresh'; só hard-stale muda staleness. Auto-merge do Loop
        // de 12/06 (9f8d214599, pré-O-3) promovia soft→review e quebrou o
        // teste congelado por 3 semanas — removido em 03/07.
        return self::DECISION_FRESH;
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
        $value = AiValueNormalizer::finiteFloatOrNull($signals[$key] ?? 0);
        if ($value === null) {
            return 0;
        }

        return max(0, (int) $value);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function intOrDefault(array $signals, string $key, int $default): int
    {
        $value = AiValueNormalizer::finiteFloatOrNull($signals[$key] ?? null);

        return $value === null ? $default : (int) $value;
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
            return max(0, $value);
        }

        $float = AiValueNormalizer::finiteFloatOrNull($value);
        if ($float === null) {
            return null;
        }

        return max(0, (int) ceil($float));
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function hitRateOrNull(array $signals): ?float
    {
        $value = AiValueNormalizer::finiteFloatOrNull($signals[self::FIELD_RECALL_EVAL_HIT_RATE] ?? null);

        return $value === null ? null : AiValueNormalizer::clampUnit($value);
    }

    private function clamp(int $min, int $max, int $value): int
    {
        return min($max, max($min, $value));
    }
}
