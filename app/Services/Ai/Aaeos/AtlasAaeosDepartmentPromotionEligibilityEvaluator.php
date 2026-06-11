<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

final class AtlasAaeosDepartmentPromotionEligibilityEvaluator
{
    private const SCHEMA_VERSION = 'atlas.aaeos.department_promotion_eligibility.v1';

    private const DEFAULT_MAX_EVIDENCE_AGE_DAYS = 30;

    private const DEFAULT_MAX_TIER = 5;

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
        $currentTier = $this->intValue($department['current_tier'] ?? 0);
        $maxTier = $this->intValue($options['max_tier'] ?? self::DEFAULT_MAX_TIER, self::DEFAULT_MAX_TIER);
        $atMaxTier = $currentTier >= $maxTier;
        $targetTier = $atMaxTier ? $maxTier : ($currentTier + 1);

        $blockerPrecondition = $this->evaluateBlockers($department['blockers_to_next'] ?? []);
        $qualityPrecondition = $this->evaluateQualityBar($metrics, $targetTier);
        $freshnessPrecondition = $this->evaluateFreshness($department, $options);

        $failedPreconditions = [];
        $blockingReasons = [];

        if (! $blockerPrecondition['passed']) {
            $failedPreconditions[] = 'blockers';
            foreach ($blockerPrecondition['unresolved'] as $unresolvedId) {
                $blockingReasons[] = 'blocked_by_unresolved_blockers:' . $unresolvedId;
            }
        }

        if (! $qualityPrecondition['passed']) {
            $failedPreconditions[] = 'quality_bar';
            $blockingReasons[] = 'quality_bar_not_met';
        }

        if (! $freshnessPrecondition['passed']) {
            $failedPreconditions[] = 'freshness';
            $blockingReasons[] = 'evidence_stale';
        }

        if ($atMaxTier) {
            $blockingReasons[] = 'already_at_max_tier';
        }

        $eligible = $failedPreconditions === [] && ! $atMaxTier;

        $preconditions = [
            'blockers' => $blockerPrecondition,
            'quality_bar' => $qualityPrecondition,
            'freshness' => $freshnessPrecondition,
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $eligible ? 'eligible' : 'blocked',
            'current_tier' => $currentTier,
            'target_tier' => $targetTier,
            'preconditions' => $preconditions,
            'failed_preconditions' => $failedPreconditions,
            'blocking_reasons' => $blockingReasons,
            'eligibility_hash' => $this->eligibilityHash(
                $currentTier,
                $targetTier,
                $eligible,
                $preconditions,
                $failedPreconditions,
                $blockingReasons,
            ),
            'promotion_allowed' => false,
            'canonical_write_allowed' => false,
            'auto_promote_allowed' => false,
        ];
    }

    /**
     * @return list<string>
     */
    public function preconditionKeys(): array
    {
        return ['blockers', 'quality_bar', 'freshness'];
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

            if (($blocker['resolved'] ?? false) === true) {
                continue;
            }

            $unresolved[] = $this->stringValue($blocker['id'] ?? '');
        }

        $unresolved = AtlasAaeosStringListNormalizer::uniqueSortedStrings($unresolved);

        return [
            'passed' => $unresolved === [],
            'total' => count($blockers),
            'unresolved' => $unresolved,
        ];
    }

    /**
     * @param array{current_score?: int|float|string, tier_thresholds?: array<int|string, int|float|string>, target_threshold?: int|float|string} $metrics
     *
     * @return array{passed: bool, current_score: float, required_threshold: float, deficit: float}
     */
    private function evaluateQualityBar(array $metrics, int $targetTier): array
    {
        $currentScore = $this->floatValue($metrics['current_score'] ?? 0.0);
        $requiredThreshold = $this->resolveRequiredThreshold($metrics, $targetTier);
        $deficit = round($requiredThreshold - $currentScore, 4);

        return [
            'passed' => $currentScore >= $requiredThreshold,
            'current_score' => $currentScore,
            'required_threshold' => $requiredThreshold,
            'deficit' => $deficit,
        ];
    }

    /**
     * @param array{current_score?: int|float|string, tier_thresholds?: array<int|string, int|float|string>, target_threshold?: int|float|string} $metrics
     */
    private function resolveRequiredThreshold(array $metrics, int $targetTier): float
    {
        $thresholds = $metrics['tier_thresholds'] ?? null;

        if (is_array($thresholds) && array_key_exists($targetTier, $thresholds)) {
            return $this->floatValue($thresholds[$targetTier]);
        }

        if (is_array($thresholds) && array_key_exists((string) $targetTier, $thresholds)) {
            return $this->floatValue($thresholds[(string) $targetTier]);
        }

        return $this->floatValue($metrics['target_threshold'] ?? 0.0);
    }

    /**
     * @param array{last_evaluation?: string} $department
     * @param array{as_of?: string, max_evidence_age_days?: int|float|string} $options
     *
     * @return array{passed: bool, age_days: int, max_age_days: int}
     */
    private function evaluateFreshness(array $department, array $options): array
    {
        $maxAgeDays = $this->intValue($options['max_evidence_age_days'] ?? self::DEFAULT_MAX_EVIDENCE_AGE_DAYS, self::DEFAULT_MAX_EVIDENCE_AGE_DAYS);
        $lastEvaluation = is_string($department['last_evaluation'] ?? null) ? $department['last_evaluation'] : '';
        $asOf = is_string($options['as_of'] ?? null) ? $options['as_of'] : $lastEvaluation;

        $ageDays = $this->ageInDays($lastEvaluation, $asOf);

        return [
            'passed' => $ageDays <= $maxAgeDays,
            'age_days' => $ageDays,
            'max_age_days' => $maxAgeDays,
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
            'schema_version' => self::SCHEMA_VERSION,
            'current_tier' => $currentTier,
            'target_tier' => $targetTier,
            'verdict' => $eligible ? 'eligible' : 'blocked',
            'preconditions' => $preconditions,
            'failed_preconditions' => $failedPreconditions,
            'blocking_reasons' => $blockingReasons,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function intValue(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function floatValue(mixed $value, float $default = 0.0): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    private function stringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
