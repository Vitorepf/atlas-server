<?php

declare(strict_types=1);

namespace App\Services\Ai\Aemor\Judgment;

/**
 * Pure projection of AtlasAemorJudgmentService::qualityScore.
 *
 * Mirrors the canonical AEMOR judgment-quality arithmetic byte-for-byte while
 * accepting already-resolved scalar inputs, so the score can be recomputed
 * without touching the runtime outcome/false-learning/repeated-failure payloads.
 */
final class AemorJudgmentQualityScorer
{
    private const SCHEMA_VERSION = 'atlas.aemor.quality_score.v1';

    /**
     * @return array{
     *     schema_version: string,
     *     score: int,
     *     status: string,
     *     breakdown: array{
     *         evidence_coverage: int,
     *         false_learning_gate: string,
     *         repeated_failure: string,
     *         context_roi: int
     *     }
     * }
     */
    public function score(
        int $evidenceRefsCount,
        string $falseLearningStatus,
        string $repeatedFailureStatus,
        int $contextRoiScore
    ): array {
        $score = 50;
        $score += $evidenceRefsCount > 0 ? 15 : -30;
        $score += $falseLearningStatus === 'pass' ? 20 : -20;
        $score += $repeatedFailureStatus === 'clear' ? 10 : -15;
        $score += $contextRoiScore >= 70 ? 10 : -10;
        $score = max(0, min(100, $score));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'score' => $score,
            'status' => $score >= 80 ? 'strong' : ($score >= 55 ? 'watch' : 'weak'),
            'breakdown' => [
                'evidence_coverage' => $evidenceRefsCount,
                'false_learning_gate' => $falseLearningStatus,
                'repeated_failure' => $repeatedFailureStatus,
                'context_roi' => $contextRoiScore,
            ],
        ];
    }
}
