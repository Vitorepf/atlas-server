<?php

declare(strict_types=1);

namespace App\Services\Ai\Aemor\Judgment;

final class AemorContextRoiScorer
{
    private const SCHEMA_VERSION = 'atlas.aemor.context_roi_score.v1';

    private const BASE_SCORE = 75;

    private const HELPFUL_WEIGHT = 5;

    private const IRRELEVANT_WEIGHT = 4;

    private const STALE_WEIGHT = 8;

    private const MISSING_WEIGHT = 12;

    private const SCORE_FLOOR = 0;

    private const SCORE_CEILING = 100;

    private const GOOD_THRESHOLD = 70;

    private const WATCH_THRESHOLD = 45;

    /**
     * Mirrors AtlasAemorJudgmentService::contextRoi (line 278) with the four
     * outcome.context_utility source-array sizes passed in directly.
     *
     * @return array{
     *     schema_version: string,
     *     score: int,
     *     helpful_sources_count: int,
     *     irrelevant_sources_count: int,
     *     stale_sources_count: int,
     *     missing_sources_count: int,
     *     status: string
     * }
     */
    public function score(int $helpfulCount, int $irrelevantCount, int $staleCount, int $missingCount): array
    {
        // Negative source counts are never real signal (a source can't occur
        // a negative number of times) — clamp before they can corrupt the score.
        $helpfulCount   = max(0, $helpfulCount);
        $irrelevantCount = max(0, $irrelevantCount);
        $staleCount     = max(0, $staleCount);
        $missingCount   = max(0, $missingCount);

        $irrelevantPenalty = $irrelevantCount * self::IRRELEVANT_WEIGHT;
        $stalePenalty      = $staleCount * self::STALE_WEIGHT;
        $missingPenalty    = $missingCount * self::MISSING_WEIGHT;

        $raw = self::BASE_SCORE
            + ($helpfulCount * self::HELPFUL_WEIGHT)
            - $irrelevantPenalty
            - $stalePenalty
            - $missingPenalty;

        $score = max(self::SCORE_FLOOR, min(self::SCORE_CEILING, $raw));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'score' => $score,
            'helpful_sources_count' => $helpfulCount,
            'irrelevant_sources_count' => $irrelevantCount,
            'stale_sources_count' => $staleCount,
            'missing_sources_count' => $missingCount,
            'loss_breakdown' => [
                'irrelevant_penalty' => $irrelevantPenalty,
                'stale_penalty' => $stalePenalty,
                'missing_penalty' => $missingPenalty,
                'total_penalty' => $irrelevantPenalty + $stalePenalty + $missingPenalty,
            ],
            'status' => $score >= self::GOOD_THRESHOLD
                ? 'good'
                : ($score >= self::WATCH_THRESHOLD ? 'watch' : 'poor'),
        ];
    }
}
