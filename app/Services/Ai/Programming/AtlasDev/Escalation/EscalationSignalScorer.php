<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Escalation;

/**
 * Deterministic 0..10 escalation score (doc principal §20 "Forge Escalation").
 *
 * Each contributing signal adds a fixed weight; the total is clamped to
 * [0, 10]. Same input → same score, always. Thresholds are interpreted
 * downstream by {@see EscalationDecisionEngine}; the scorer itself is
 * purely additive.
 *
 * Weight table:
 *   - risk_level R4|R5                   +5
 *   - file_count > 5                     +2
 *   - file_count > 3 (and not > 5)       +1
 *   - layers_touched >= 3                +1
 *   - same_signature_twice               +2
 *   - diff_grew                          +1
 *   - test_coverage_gap                  +1
 *   - prior_failure_in_area              +1
 *   - risk_keywords                      +1 per keyword (capped at +2)
 *   - context_required_chars >= 40_000   +1
 *   - thread_messages >= 24              +1
 *   - prior_failure_count >= 2           +1
 */
final class EscalationSignalScorer
{
    public const MAX_SCORE = 10;

    public const MIN_SCORE = 0;

    public const RISK_R4_OR_R5_WEIGHT = 5;

    public const FILE_COUNT_HIGH_WEIGHT = 2;   // > 5 files

    public const FILE_COUNT_MED_WEIGHT = 1;    // > 3 files (and <= 5)

    public const LAYERS_WEIGHT = 1;            // layers_touched >= 3

    public const SAME_SIGNATURE_WEIGHT = 2;

    public const DIFF_GROWTH_WEIGHT = 1;

    public const TEST_COVERAGE_GAP_WEIGHT = 1;

    public const PRIOR_FAILURE_AREA_WEIGHT = 1;

    public const RISK_KEYWORDS_CAP = 2;

    public const CONTEXT_HIGH_WEIGHT = 1;      // >= 40k chars

    public const THREAD_LONG_WEIGHT = 1;       // >= 24 messages

    public const PRIOR_FAILURE_COUNT_WEIGHT = 1; // >= 2 in this run

    public const HIGH_FILE_THRESHOLD = 5;

    public const MED_FILE_THRESHOLD = 3;

    public const HIGH_LAYER_THRESHOLD = 3;

    public const HIGH_CONTEXT_CHARS_THRESHOLD = 40_000;

    public const LONG_THREAD_THRESHOLD = 24;

    public const PRIOR_FAILURE_COUNT_THRESHOLD = 2;

    /**
     * @return array{score:int, contributions:array<string,int>}
     */
    public function score(EscalationSignalsInput $input): array
    {
        $contributions = [];

        if (in_array($input->riskLevel, ['R4', 'R5'], true)) {
            $contributions['risk_r4_or_r5'] = self::RISK_R4_OR_R5_WEIGHT;
        }

        if ($input->fileCount > self::HIGH_FILE_THRESHOLD) {
            $contributions['file_count_gt_5'] = self::FILE_COUNT_HIGH_WEIGHT;
        } elseif ($input->fileCount > self::MED_FILE_THRESHOLD) {
            $contributions['file_count_gt_3'] = self::FILE_COUNT_MED_WEIGHT;
        }

        if ($input->layersTouched >= self::HIGH_LAYER_THRESHOLD) {
            $contributions['layers_gte_3'] = self::LAYERS_WEIGHT;
        }

        if ($input->sameSignatureTwice) {
            $contributions['same_signature_twice'] = self::SAME_SIGNATURE_WEIGHT;
        }
        if ($input->diffGrew) {
            $contributions['diff_growth'] = self::DIFF_GROWTH_WEIGHT;
        }
        if ($input->testCoverageGap) {
            $contributions['test_coverage_gap'] = self::TEST_COVERAGE_GAP_WEIGHT;
        }
        if ($input->priorFailureInArea) {
            $contributions['prior_failure_in_area'] = self::PRIOR_FAILURE_AREA_WEIGHT;
        }

        $keywordCount = count($input->riskKeywords);
        if ($keywordCount > 0) {
            $contributions['risk_keywords'] = min($keywordCount, self::RISK_KEYWORDS_CAP);
        }

        if ($input->contextRequiredChars !== null
            && $input->contextRequiredChars >= self::HIGH_CONTEXT_CHARS_THRESHOLD) {
            $contributions['context_gte_40k'] = self::CONTEXT_HIGH_WEIGHT;
        }
        if ($input->threadMessages !== null
            && $input->threadMessages >= self::LONG_THREAD_THRESHOLD) {
            $contributions['thread_gte_24_messages'] = self::THREAD_LONG_WEIGHT;
        }
        if ($input->priorFailureCount >= self::PRIOR_FAILURE_COUNT_THRESHOLD) {
            $contributions['prior_failure_count_gte_2'] = self::PRIOR_FAILURE_COUNT_WEIGHT;
        }

        ksort($contributions);

        $total = array_sum($contributions);
        $clamped = max(self::MIN_SCORE, min(self::MAX_SCORE, (int) $total));

        return [
            'score' => $clamped,
            'contributions' => $contributions,
        ];
    }
}
