<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Scoring;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Summary fidelity / context-retention coverage over required summary items.
 *
 * Live consumers: {@see \App\Services\Ai\AiCompactionService} and
 * {@see \App\Services\Ai\Compaction\VerifiedL2HierarchicalSummaryService}.
 */
final class SummaryFidelityCoverageScorer
{
    public const SCHEMA_VERSION = 'atlas.aaeos.summary_fidelity_coverage.v1';

    public const DECISION_KIND = 'decision';

    public const SCORE_PRECISION = 4;

    public const RETENTION_FAIL_FLOOR = 0.6;

    public const VERDICT_PASSED = 'passed';

    public const VERDICT_DEGRADED = 'degraded';

    public const VERDICT_FAILED = 'failed';
    public const FIELD_CONTEXT_RETENTION_SCORE = 'context_retention_score';
    public const FIELD_DECISION_TOTAL = 'decision_total';
    public const FIELD_DIGEST = 'digest';
    public const FIELD_MISSED_DECISION_RATE = 'missed_decision_rate';
    public const FIELD_MISSING_DECISION_IDS = 'missing_decision_ids';
    public const FIELD_MISSING_ITEM_IDS = 'missing_item_ids';
    public const FIELD_REQUIRED_TOTAL = 'required_total';
    public const FIELD_PRESENT_TOTAL = 'present_total';
    public const FIELD_VERDICT = 'verdict';
    public const FIELD_UNVERIFIABLE_ITEM_IDS = 'unverifiable_item_ids';
    public const FIELD_MISSING_TOTAL = 'missing_total';
    public const FIELD_PRESENT_ITEM_IDS = 'present_item_ids';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_KIND = 'kind';
    public const FIELD_ID = 'id';
    public const FLOAT_0_0 = 0.0;
    public const FLOAT_1_0 = 1.0;


    /**
     * @param  list<array{id?: mixed, kind?: mixed, digest?: mixed}>  $requiredItems
     * @return array{
     *     schema_version: string,
     *     verdict: string,
     *     context_retention_score: float,
     *     missed_decision_rate: float,
     *     required_total: int,
     *     present_total: int,
     *     missing_total: int,
     *     decision_total: int,
     *     missing_item_ids: list<string>,
     *     present_item_ids: list<string>,
     *     missing_decision_ids: list<string>,
     *     unverifiable_item_ids: list<string>
     * }
     */
    public function score(array $requiredItems, string $summaryText): array
    {
        $normalizedSummary = $this->normalize($summaryText);

        $requiredTotal = 0;
        $presentTotal = 0;
        $decisionTotal = 0;
        $missingDecisionCount = 0;

        $presentItemIds = [];
        $missingItemIds = [];
        $missingDecisionIds = [];
        $unverifiableItemIds = [];

        $seenMissing = [];
        $seenPresent = [];
        $seenMissingDecision = [];
        $seenUnverifiable = [];

        foreach ($requiredItems as $item) {
            $requiredTotal++;

            $id = $this->stringValue($item, self::FIELD_ID);
            $idToken = AiValueNormalizer::trimmedStringOrNull($id) ?? '';
            $kind = $this->normalize($this->stringValue($item, self::FIELD_KIND));
            $isDecision = $kind === self::DECISION_KIND;

            if ($isDecision) {
                $decisionTotal++;
            }

            $digest = $this->usableDigest($item);
            $hasUsableSignal = $idToken !== '' || $digest !== null;
            $present = $hasUsableSignal && $this->signalPresent($idToken, $digest, $normalizedSummary);

            if (! $hasUsableSignal && ! in_array($id, $seenUnverifiable, true)) {
                $unverifiableItemIds[] = $id;
                $seenUnverifiable[] = $id;
            }

            if ($present) {
                $presentTotal++;

                if (! in_array($id, $seenPresent, true)) {
                    $presentItemIds[] = $id;
                    $seenPresent[] = $id;
                }

                continue;
            }

            if (! in_array($id, $seenMissing, true)) {
                $missingItemIds[] = $id;
                $seenMissing[] = $id;
            }

            if ($isDecision) {
                $missingDecisionCount++;

                if (! in_array($id, $seenMissingDecision, true)) {
                    $missingDecisionIds[] = $id;
                    $seenMissingDecision[] = $id;
                }
            }
        }

        $missingTotal = $requiredTotal - $presentTotal;

        $contextRetentionScore = $requiredTotal === 0
            ? 1.0
            : $this->ratio($presentTotal, $requiredTotal);

        $missedDecisionRate = $decisionTotal === 0
            ? 0.0
            : $this->ratio($missingDecisionCount, $decisionTotal);

        $verdict = $this->resolveVerdict(
            $contextRetentionScore,
            $missedDecisionRate,
            $missingDecisionCount > 0,
        );

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_VERDICT => $verdict,
            self::FIELD_CONTEXT_RETENTION_SCORE => $contextRetentionScore,
            self::FIELD_MISSED_DECISION_RATE => $missedDecisionRate,
            self::FIELD_REQUIRED_TOTAL => $requiredTotal,
            self::FIELD_PRESENT_TOTAL => $presentTotal,
            self::FIELD_MISSING_TOTAL => $missingTotal,
            self::FIELD_DECISION_TOTAL => $decisionTotal,
            self::FIELD_MISSING_ITEM_IDS => $missingItemIds,
            self::FIELD_PRESENT_ITEM_IDS => $presentItemIds,
            self::FIELD_MISSING_DECISION_IDS => $missingDecisionIds,
            self::FIELD_UNVERIFIABLE_ITEM_IDS => $unverifiableItemIds,
        ];
    }

    public function verdictFor(float $contextRetentionScore, float $missedDecisionRate): string
    {
        return $this->resolveVerdict(
            $contextRetentionScore,
            $missedDecisionRate,
            $missedDecisionRate > self::FLOAT_0_0,
        );
    }

    private function resolveVerdict(
        float $contextRetentionScore,
        float $missedDecisionRate,
        bool $hasMissedDecision,
    ): string {
        if ($hasMissedDecision || $contextRetentionScore < self::RETENTION_FAIL_FLOOR) {
            return self::VERDICT_FAILED;
        }

        if ($contextRetentionScore >= self::FLOAT_1_0 && $missedDecisionRate <= self::FLOAT_0_0) {
            return self::VERDICT_PASSED;
        }

        return self::VERDICT_DEGRADED;
    }

    private function signalPresent(string $idToken, ?string $digest, string $normalizedSummary): bool
    {
        if ($idToken !== '' && str_contains($normalizedSummary, $this->normalize($idToken))) {
            return true;
        }

        if ($digest !== null && str_contains($normalizedSummary, $this->normalize($digest))) {
            return true;
        }

        return false;
    }

    /**
     * @param  array{id?: mixed, kind?: mixed, digest?: mixed}  $item
     */
    private function usableDigest(array $item): ?string
    {
        if (! array_key_exists(self::FIELD_DIGEST, $item)) {
            return null;
        }

        $digest = $item[self::FIELD_DIGEST];

        return AiValueNormalizer::trimmedStringOrNull($digest);
    }

    /**
     * @param  array{id?: mixed, kind?: mixed, digest?: mixed}  $item
     */
    private function stringValue(array $item, string $key): string
    {
        $value = $item[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    private function ratio(int $numerator, int $denominator): float
    {
        return round($numerator / $denominator, self::SCORE_PRECISION);
    }

    private function normalize(string $value): string
    {
        return AiValueNormalizer::lowerTrimmedString($value);
    }
}
