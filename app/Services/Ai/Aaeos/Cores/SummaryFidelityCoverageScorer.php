<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Cores;

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

            $id = $this->stringValue($item, 'id');
            $idToken = AiValueNormalizer::trimmedStringOrNull($id) ?? '';
            $kind = $this->normalize($this->stringValue($item, 'kind'));
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
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            self::FIELD_CONTEXT_RETENTION_SCORE => $contextRetentionScore,
            'missed_decision_rate' => $missedDecisionRate,
            'required_total' => $requiredTotal,
            'present_total' => $presentTotal,
            'missing_total' => $missingTotal,
            self::FIELD_DECISION_TOTAL => $decisionTotal,
            'missing_item_ids' => $missingItemIds,
            'present_item_ids' => $presentItemIds,
            'missing_decision_ids' => $missingDecisionIds,
            'unverifiable_item_ids' => $unverifiableItemIds,
        ];
    }

    public function verdictFor(float $contextRetentionScore, float $missedDecisionRate): string
    {
        return $this->resolveVerdict(
            $contextRetentionScore,
            $missedDecisionRate,
            $missedDecisionRate > 0.0,
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

        if ($contextRetentionScore >= 1.0 && $missedDecisionRate <= 0.0) {
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
        if (! array_key_exists('digest', $item)) {
            return null;
        }

        $digest = $item['digest'];

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
