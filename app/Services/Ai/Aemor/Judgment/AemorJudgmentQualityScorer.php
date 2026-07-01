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
        // Hostile/malformed inputs are clamped before they ever touch the arithmetic — a negative
        // ref count or an out-of-range ROI score must never silently distort the quality score.
        $evidenceRefsCount = max(0, $evidenceRefsCount);
        $contextRoiScore = max(0, min(100, $contextRoiScore));

        $positiveDrivers = [];
        $negativeDrivers = [];

        $score = 50;

        if ($evidenceRefsCount > 0) {
            $score += 15;
            $positiveDrivers[] = 'evidence_refs_present';
        } else {
            $score -= 30;
            $negativeDrivers[] = 'no_evidence_refs';
        }

        if ($falseLearningStatus === 'pass') {
            $score += 20;
            $positiveDrivers[] = 'false_learning_gate_passed';
        } else {
            $score -= 20;
            $negativeDrivers[] = 'false_learning_gate_not_passed';
        }

        if ($repeatedFailureStatus === 'clear') {
            $score += 10;
            $positiveDrivers[] = 'repeated_failure_clear';
        } else {
            $score -= 15;
            $negativeDrivers[] = 'repeated_failure_not_clear';
        }

        if ($contextRoiScore >= 70) {
            $score += 10;
            $positiveDrivers[] = 'high_context_roi';
        } else {
            $score -= 10;
            $negativeDrivers[] = 'low_context_roi';
        }

        $score = max(0, min(100, $score));

        // A false-learning failure must never be masked by strong evidence/ROI — the gate caps
        // status below 'strong' regardless of how high the raw arithmetic score is.
        $status = match (true) {
            $falseLearningStatus !== 'pass' && $score >= 80 => 'watch',
            $score >= 80 => 'strong',
            $score >= 55 => 'watch',
            default => 'weak',
        };
        if ($falseLearningStatus !== 'pass' && ! in_array('false_learning_status_caps_below_strong', $negativeDrivers, true)) {
            $negativeDrivers[] = 'false_learning_status_caps_below_strong';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'score' => $score,
            'status' => $status,
            'breakdown' => [
                'evidence_coverage' => $evidenceRefsCount,
                'false_learning_gate' => $falseLearningStatus,
                'repeated_failure' => $repeatedFailureStatus,
                'context_roi' => $contextRoiScore,
            ],
            'positive_drivers' => $positiveDrivers,
            'negative_drivers' => $negativeDrivers,
        ];
    }
}
