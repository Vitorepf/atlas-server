<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Cores;

final class SummaryFidelityCoverageScorer
{
    private const SCHEMA_VERSION = 'atlas.aaeos.summary_fidelity_coverage.v1';

    private const DECISION_KIND = 'decision';

    private const SCORE_PRECISION = 4;

    private const RETENTION_FAIL_FLOOR = 0.6;

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
            $idToken = trim($id);
            $kind = $this->stringValue($item, 'kind');
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
            'context_retention_score' => $contextRetentionScore,
            'missed_decision_rate' => $missedDecisionRate,
            'required_total' => $requiredTotal,
            'present_total' => $presentTotal,
            'missing_total' => $missingTotal,
            'decision_total' => $decisionTotal,
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
            return 'failed';
        }

        if ($contextRetentionScore >= 1.0 && $missedDecisionRate <= 0.0) {
            return 'passed';
        }

        return 'degraded';
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

        if (! is_string($digest)) {
            return null;
        }

        return trim($digest) === '' ? null : $digest;
    }

    /**
     * @param  array{id?: mixed, kind?: mixed, digest?: mixed}  $item
     */
    private function stringValue(array $item, string $key): string
    {
        $value = $item[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function ratio(int $numerator, int $denominator): float
    {
        return round($numerator / $denominator, self::SCORE_PRECISION);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
