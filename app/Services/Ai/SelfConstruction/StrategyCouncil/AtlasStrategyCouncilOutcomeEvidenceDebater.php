<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\StrategyCouncil;

/**
 * Pure debater that compares competing candidate task batches using outcome
 * evidence and explicit uncertainty rather than static priority labels.
 *
 * Rules:
 *   - Stronger evidence wins (higher evidence score)
 *   - Missing evidence lowers confidence
 *   - Ties return requested probe facts
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasStrategyCouncilOutcomeEvidenceDebater
{
    public const SCHEMA = 'atlas.strategy_council.outcome_evidence_debater.v1';

    public const VERDICT_WINNER = 'winner';
    public const VERDICT_TIE = 'tie';
    public const VERDICT_PROBE_REQUESTED = 'probe_requested';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function debate(array $input): array
    {
        $candidateA = is_array($input['candidate_a'] ?? null) ? $input['candidate_a'] : [];
        $candidateB = is_array($input['candidate_b'] ?? null) ? $input['candidate_b'] : [];

        $evidenceA = (float) ($candidateA['evidence_score'] ?? 0.0);
        $evidenceB = (float) ($candidateB['evidence_score'] ?? 0.0);
        $hasEvidenceA = (bool) ($candidateA['has_evidence'] ?? false);
        $hasEvidenceB = (bool) ($candidateB['has_evidence'] ?? false);
        $idA = (string) ($candidateA['id'] ?? 'a');
        $idB = (string) ($candidateB['id'] ?? 'b');

        // Missing evidence lowers confidence.
        $confidenceA = $hasEvidenceA ? 1.0 : 0.3;
        $confidenceB = $hasEvidenceB ? 1.0 : 0.3;

        $adjustedScoreA = $evidenceA * $confidenceA;
        $adjustedScoreB = $evidenceB * $confidenceB;

        $verdict = match (true) {
            abs($adjustedScoreA - $adjustedScoreB) < 0.01 => self::VERDICT_TIE,
            $adjustedScoreA > $adjustedScoreB => self::VERDICT_WINNER,
            default => self::VERDICT_WINNER,
        };

        $winner = match ($verdict) {
            self::VERDICT_WINNER => $adjustedScoreA > $adjustedScoreB ? $idA : $idB,
            default => null,
        };

        $reasons = [];
        if ($verdict === self::VERDICT_WINNER) {
            $reasons[] = 'stronger_evidence:'.$winner;
            if (! $hasEvidenceA || ! $hasEvidenceB) {
                $reasons[] = 'missing_evidence_lowered_confidence';
            }
        }
        if ($verdict === self::VERDICT_TIE) {
            $reasons[] = 'evidence_tie';
            $reasons[] = 'probe_facts_requested';
            $verdict = self::VERDICT_PROBE_REQUESTED;
        }

        return [
            'schema_version' => self::SCHEMA,
            'verdict' => $verdict,
            'winner' => $winner,
            'reasons' => $reasons,
            'candidate_a' => ['id' => $idA, 'evidence_score' => $evidenceA, 'confidence' => $confidenceA, 'adjusted_score' => round($adjustedScoreA, 3)],
            'candidate_b' => ['id' => $idB, 'evidence_score' => $evidenceB, 'confidence' => $confidenceB, 'adjusted_score' => round($adjustedScoreB, 3)],
        ];
    }
}
