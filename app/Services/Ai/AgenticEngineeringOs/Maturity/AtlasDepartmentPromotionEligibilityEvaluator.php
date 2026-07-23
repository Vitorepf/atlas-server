<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiValueNormalizer;

final class AtlasDepartmentPromotionEligibilityEvaluator
{
    public const SCHEMA_VERSION = 'atlas.aaeos.department_promotion_eligibility.v1';

    public const DEFAULT_MAX_EVIDENCE_AGE_DAYS = 30;

    public const DEFAULT_MAX_TIER = 5;

    public const VERDICT_ELIGIBLE = 'eligible';

    public const VERDICT_BLOCKED = 'blocked';

    public const FIELD_PASSED = 'passed';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_VERDICT = 'verdict';
    public const FIELD_CURRENT_TIER = 'current_tier';
    public const FIELD_TARGET_TIER = 'target_tier';
    public const FIELD_PRECONDITIONS = 'preconditions';
    public const FIELD_FAILED_PRECONDITIONS = 'failed_preconditions';
    public const FIELD_BLOCKING_REASONS = 'blocking_reasons';
    public const FIELD_AGE_DAYS = 'age_days';
    public const FIELD_AS_OF = 'as_of';
    public const FIELD_AUTO_PROMOTE_ALLOWED = 'auto_promote_allowed';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_BLOCKERS_TO_NEXT = 'blockers_to_next';
    public const FIELD_CURRENT_SCORE = 'current_score';
    public const FIELD_UNRESOLVED = 'unresolved';
    public const FIELD_QUALITY_BAR = 'quality_bar';
    public const FIELD_PROMOTION_ALLOWED = 'promotion_allowed';
    public const FIELD_TOTAL = 'total';
    public const FIELD_TIER_THRESHOLDS = 'tier_thresholds';
    public const FIELD_CANONICAL_WRITE_ALLOWED = 'canonical_write_allowed';
    public const FIELD_DEFICIT = 'deficit';
    public const FIELD_ELIGIBILITY_HASH = 'eligibility_hash';
    public const FIELD_FRESHNESS = 'freshness';
    public const FIELD_ID = 'id';
    public const FIELD_LAST_EVALUATION = 'last_evaluation';
    public const FIELD_MAX_AGE_DAYS = 'max_age_days';
    public const FIELD_MAX_EVIDENCE_AGE_DAYS = 'max_evidence_age_days';
    public const FIELD_MAX_TIER = 'max_tier';
    public const FIELD_REQUIRED_THRESHOLD = 'required_threshold';
    public const FIELD_RESOLVED = 'resolved';
    public const FIELD_TARGET_THRESHOLD = 'target_threshold';
    public const FIELD_SHA256 = 'sha256';
    public const FIELD_ALREADY_AT_MAX_TIER = 'already_at_max_tier';
    public const FIELD_EVIDENCE_STALE = 'evidence_stale';
    public const FIELD_QUALITY_BAR_NOT_MET = 'quality_bar_not_met';

    /**
     * @param array{current_tier?: int|float|string, blockers_to_next?: list<array{id?: mixed, resolved?: bool, severity?: string}>, last_evaluation?: string} $department
     * @param array{current_score?: int|float|string, tier_thresholds?: array<int|string, int|float|string>, target_threshold?: int|float|string} $metrics
     * @param array{as_of?: string, max_evidence_age_days?: int|float|string, max_tier?: int|float|string} $options
     *
     * @return array{
     *     schema_version: string,
     *     verdict: string,
     *     current_tier: int,
     *     target_tier: int,
     *     preconditions: array{
     *         blockers: array{passed: bool, total: int, unresolved: list<string>},
     *         quality_bar: array{passed: bool, current_score: float, required_threshold: float, deficit: float},
     *         freshness: array{passed: bool, age_days: int, max_age_days: int}
     *     },
     *     failed_preconditions: list<string>,
     *     blocking_reasons: list<string>,
     *     eligibility_hash: string,
     *     promotion_allowed: false,
     *     canonical_write_allowed: false,
     *     auto_promote_allowed: false
     * }
     */
    public function evaluate(array $department, array $metrics, array $options = []): array
    {
        $currentTier = $this->intValue($department[self::FIELD_CURRENT_TIER] ?? 0);
        $maxTier = $this->intValue($options[self::FIELD_MAX_TIER] ?? self::DEFAULT_MAX_TIER, self::DEFAULT_MAX_TIER);
        $atMaxTier = $currentTier >= $maxTier;
        $targetTier = $atMaxTier ? $maxTier : ($currentTier + 1);

        $blockerPrecondition = $this->evaluateBlockers($department[self::FIELD_BLOCKERS_TO_NEXT] ?? []);
        $qualityPrecondition = $this->evaluateQualityBar($metrics, $targetTier);
        $freshnessPrecondition = $this->evaluateFreshness($department, $options);

        $failedPreconditions = [];
        $blockingReasons = [];

        if (! $blockerPrecondition[self::FIELD_PASSED]) {
            $failedPreconditions[] = self::FIELD_BLOCKERS;
            foreach ($blockerPrecondition[self::FIELD_UNRESOLVED] as $unresolvedId) {
                $blockingReasons[] = 'blocked_by_unresolved_blockers:' . $unresolvedId;
            }
        }

        if (! $qualityPrecondition[self::FIELD_PASSED]) {
            $failedPreconditions[] = self::FIELD_QUALITY_BAR;
            $blockingReasons[] = self::FIELD_QUALITY_BAR_NOT_MET;
        }

        if (! $freshnessPrecondition[self::FIELD_PASSED]) {
            $failedPreconditions[] = self::FIELD_FRESHNESS;
            $blockingReasons[] = self::FIELD_EVIDENCE_STALE;
        }

        if ($atMaxTier) {
            $blockingReasons[] = self::FIELD_ALREADY_AT_MAX_TIER;
        }

        $eligible = $failedPreconditions === [] && ! $atMaxTier;

        $preconditions = [
            self::FIELD_BLOCKERS => $blockerPrecondition,
            self::FIELD_QUALITY_BAR => $qualityPrecondition,
            self::FIELD_FRESHNESS => $freshnessPrecondition,
        ];

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_VERDICT => $eligible ? self::VERDICT_ELIGIBLE : self::VERDICT_BLOCKED,
            self::FIELD_CURRENT_TIER => $currentTier,
            self::FIELD_TARGET_TIER => $targetTier,
            self::FIELD_PRECONDITIONS => $preconditions,
            self::FIELD_FAILED_PRECONDITIONS => $failedPreconditions,
            self::FIELD_BLOCKING_REASONS => $blockingReasons,
            self::FIELD_ELIGIBILITY_HASH => $this->eligibilityHash(
                $currentTier,
                $targetTier,
                $eligible,
                $preconditions,
                $failedPreconditions,
                $blockingReasons,
            ),
            self::FIELD_PROMOTION_ALLOWED => false,
            self::FIELD_CANONICAL_WRITE_ALLOWED => false,
            self::FIELD_AUTO_PROMOTE_ALLOWED => false,
        ];
    }

    /**
     * @return list<string>
     */
    public function preconditionKeys(): array
    {
        return [self::FIELD_BLOCKERS, self::FIELD_QUALITY_BAR, self::FIELD_FRESHNESS];
    }

    /**
     * @param list<array{id?: mixed, resolved?: bool, severity?: string}> $blockers
     *
     * @return array{passed: bool, total: int, unresolved: list<string>}
     */
    private function evaluateBlockers(array $blockers): array
    {
        $unresolved = [];

        foreach ($blockers as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }

            if (($blocker[self::FIELD_RESOLVED] ?? false) === true) {
                continue;
            }

            $unresolved[] = $this->stringValue($blocker[self::FIELD_ID] ?? '');
        }

        $unresolved = $this->uniqueSortedStrings($unresolved);

        return [
            self::FIELD_PASSED => $unresolved === [],
            self::FIELD_TOTAL => count($blockers),
            self::FIELD_UNRESOLVED => $unresolved,
        ];
    }

    /**
     * @param array{current_score?: int|float|string, tier_thresholds?: array<int|string, int|float|string>, target_threshold?: int|float|string} $metrics
     *
     * @return array{passed: bool, current_score: float, required_threshold: float, deficit: float}
     */
    private function evaluateQualityBar(array $metrics, int $targetTier): array
    {
        $currentScore = $this->floatValue($metrics[self::FIELD_CURRENT_SCORE] ?? 0.0);
        $requiredThreshold = $this->resolveRequiredThreshold($metrics, $targetTier);
        $deficit = max(0.0, round($requiredThreshold - $currentScore, 4));

        return [
            self::FIELD_PASSED => $currentScore >= $requiredThreshold,
            self::FIELD_CURRENT_SCORE => $currentScore,
            self::FIELD_REQUIRED_THRESHOLD => $requiredThreshold,
            self::FIELD_DEFICIT => $deficit,
        ];
    }

    /**
     * @param array{current_score?: int|float|string, tier_thresholds?: array<int|string, int|float|string>, target_threshold?: int|float|string} $metrics
     */
    private function resolveRequiredThreshold(array $metrics, int $targetTier): float
    {
        $thresholds = $metrics[self::FIELD_TIER_THRESHOLDS] ?? null;

        if (is_array($thresholds) && array_key_exists($targetTier, $thresholds)) {
            return $this->floatValue($thresholds[$targetTier]);
        }

        if (is_array($thresholds) && array_key_exists(AiValueNormalizer::trimmedScalarStringOrNull($targetTier) ?? '', $thresholds)) {
            return $this->floatValue($thresholds[AiValueNormalizer::trimmedScalarStringOrNull($targetTier) ?? '']);
        }

        return $this->floatValue($metrics[self::FIELD_TARGET_THRESHOLD] ?? 0.0);
    }

    /**
     * @param array{last_evaluation?: string} $department
     * @param array{as_of?: string, max_evidence_age_days?: int|float|string} $options
     *
     * @return array{passed: bool, age_days: int, max_age_days: int}
     */
    private function evaluateFreshness(array $department, array $options): array
    {
        $maxAgeDays = $this->intValue($options[self::FIELD_MAX_EVIDENCE_AGE_DAYS] ?? self::DEFAULT_MAX_EVIDENCE_AGE_DAYS, self::DEFAULT_MAX_EVIDENCE_AGE_DAYS);
        $lastEvaluation = AiValueNormalizer::trimmedStringOrNull($department[self::FIELD_LAST_EVALUATION] ?? null) ?? '';
        $asOf = AiValueNormalizer::trimmedStringOrNull($options[self::FIELD_AS_OF] ?? null) ?? date(DATE_ATOM);

        $lastEvaluationTimestamp = $this->timestampFromIso($lastEvaluation);
        $ageDays = $this->ageInDays($lastEvaluation, $asOf);

        return [
            self::FIELD_PASSED => $lastEvaluationTimestamp !== null && $ageDays <= $maxAgeDays,
            self::FIELD_AGE_DAYS => $ageDays,
            self::FIELD_MAX_AGE_DAYS => $maxAgeDays,
        ];
    }

    private function ageInDays(string $lastEvaluation, string $asOf): int
    {
        $lastTimestamp = $this->timestampFromIso($lastEvaluation);
        $asOfTimestamp = $this->timestampFromIso($asOf);

        if ($lastTimestamp === null || $asOfTimestamp === null) {
            return 0;
        }

        $elapsedSeconds = $asOfTimestamp - $lastTimestamp;

        if ($elapsedSeconds <= 0) {
            return 0;
        }

        return intdiv($elapsedSeconds, 86400);
    }

    private function timestampFromIso(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $parsed = strtotime($value);

        return $parsed === false ? null : $parsed;
    }

    /**
     * @param array{blockers: array{passed: bool, total: int, unresolved: list<string>}, quality_bar: array{passed: bool, current_score: float, required_threshold: float, deficit: float}, freshness: array{passed: bool, age_days: int, max_age_days: int}} $preconditions
     * @param list<string> $failedPreconditions
     * @param list<string> $blockingReasons
     */
    private function eligibilityHash(
        int $currentTier,
        int $targetTier,
        bool $eligible,
        array $preconditions,
        array $failedPreconditions,
        array $blockingReasons,
    ): string {
        $payload = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_CURRENT_TIER => $currentTier,
            self::FIELD_TARGET_TIER => $targetTier,
            self::FIELD_VERDICT => $eligible ? self::VERDICT_ELIGIBLE : self::VERDICT_BLOCKED,
            self::FIELD_PRECONDITIONS => $preconditions,
            self::FIELD_FAILED_PRECONDITIONS => $failedPreconditions,
            self::FIELD_BLOCKING_REASONS => $blockingReasons,
        ];

        return hash(self::FIELD_SHA256, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function intValue(mixed $value, int $default = 0): int
    {
        $float = AiValueNormalizer::finiteFloatOrNull($value);

        return $float === null ? $default : (int) $float;
    }

    private function floatValue(mixed $value, float $default = 0.0): float
    {
        return AiValueNormalizer::finiteFloatOrNull($value) ?? $default;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function uniqueSortedStrings(array $values): array
    {
        $normalized = array_values(array_unique($values));
        sort($normalized, SORT_STRING);

        return $normalized;
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value) || is_int($value) || is_float($value)) {
            return AiValueNormalizer::trimmedStringOrNull($value) ?? '';
        }

        return '';
    }
}
