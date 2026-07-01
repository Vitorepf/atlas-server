<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Simplification;

/**
 * Pure candidate scorecard. Ranks simplification candidates by real
 * structural leverage — duplication collapse, consumer risk, proof
 * readiness, autonomy gain, rollback readiness — never by raw line count.
 *
 * A candidate that only renames or wraps code (rename_or_wrap_only=true)
 * without reducing duplication or consumer risk is disqualified outright:
 * cosmetic churn never outranks real leverage, regardless of line_reduction.
 *
 * INPUT:
 *   candidates: list<{
 *     candidate_id:                 string
 *     line_reduction?:              int   (default 0, capped at 500 for scoring)
 *     duplication_collapse_score?:  float [0,1] (default 0.0)
 *     consumer_risk?:               float [0,1] (default 0.0; higher = riskier)
 *     proof_readiness?:             float [0,1] (default 0.0)
 *     autonomy_gain?:               float [0,1] (default 0.0)
 *     rollback_readiness?:          float [0,1] (default 0.0)
 *     rename_or_wrap_only?:         bool  (default false)
 *   }>
 *
 * OUTPUT:
 *   {
 *     schema,
 *     scores: list<{candidate_id, total_score, component_scores, disqualifiers, rationale}>,
 *     recommended_order: list<string>  (candidate_id, disqualified pushed last)
 *   }
 *
 * WEIGHTS: duplication_collapse=0.30, autonomy_gain=0.25, proof_readiness=0.15,
 * rollback_readiness=0.15, line_reduction(normalized/500)=0.15, minus consumer_risk*0.20.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasSelfConstructionSimplificationCandidateScorecard
{
    public const SCHEMA = 'atlas.self_construction.simplification_candidate_scorecard.v1';

    private const LINE_REDUCTION_CAP = 500;

    private const WEIGHT_DUPLICATION_COLLAPSE = 0.30;

    private const WEIGHT_AUTONOMY_GAIN = 0.25;

    private const WEIGHT_PROOF_READINESS = 0.15;

    private const WEIGHT_ROLLBACK_READINESS = 0.15;

    private const WEIGHT_LINE_REDUCTION = 0.15;

    private const WEIGHT_CONSUMER_RISK_PENALTY = 0.20;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function score(array $input): array
    {
        $rawCandidates = is_array($input['candidates'] ?? null) ? $input['candidates'] : [];

        $rows = [];
        foreach ($rawCandidates as $raw) {
            $candidateId = (string) ($raw['candidate_id'] ?? '');
            if ($candidateId === '') {
                continue;
            }

            $lineReduction = max(0, (int) ($raw['line_reduction'] ?? 0));
            $duplicationCollapse = $this->clamp01((float) ($raw['duplication_collapse_score'] ?? 0.0));
            $consumerRisk = $this->clamp01((float) ($raw['consumer_risk'] ?? 0.0));
            $proofReadiness = $this->clamp01((float) ($raw['proof_readiness'] ?? 0.0));
            $autonomyGain = $this->clamp01((float) ($raw['autonomy_gain'] ?? 0.0));
            $rollbackReadiness = $this->clamp01((float) ($raw['rollback_readiness'] ?? 0.0));
            $renameOrWrapOnly = (bool) ($raw['rename_or_wrap_only'] ?? false);

            $disqualifiers = [];
            if ($renameOrWrapOnly && $duplicationCollapse <= 0.0 && $consumerRisk <= 0.0) {
                $disqualifiers[] = 'cosmetic_rename_or_wrap_no_real_reduction';
            }

            $normalizedLineReduction = min(1.0, $lineReduction / self::LINE_REDUCTION_CAP);

            $componentScores = [
                'duplication_collapse' => round($duplicationCollapse * self::WEIGHT_DUPLICATION_COLLAPSE, 4),
                'autonomy_gain' => round($autonomyGain * self::WEIGHT_AUTONOMY_GAIN, 4),
                'proof_readiness' => round($proofReadiness * self::WEIGHT_PROOF_READINESS, 4),
                'rollback_readiness' => round($rollbackReadiness * self::WEIGHT_ROLLBACK_READINESS, 4),
                'line_reduction' => round($normalizedLineReduction * self::WEIGHT_LINE_REDUCTION, 4),
                'consumer_risk_penalty' => round(-1 * $consumerRisk * self::WEIGHT_CONSUMER_RISK_PENALTY, 4),
            ];

            $totalScore = $disqualifiers !== []
                ? 0.0
                : round(array_sum($componentScores), 4);

            $rationale = $disqualifiers !== []
                ? 'disqualified: '.implode(', ', $disqualifiers)
                : sprintf(
                    'duplication_collapse=%.2f autonomy_gain=%.2f proof_readiness=%.2f rollback_readiness=%.2f consumer_risk=%.2f',
                    $duplicationCollapse,
                    $autonomyGain,
                    $proofReadiness,
                    $rollbackReadiness,
                    $consumerRisk,
                );

            $rows[] = [
                'candidate_id' => $candidateId,
                'total_score' => $totalScore,
                'component_scores' => $componentScores,
                'disqualifiers' => $disqualifiers,
                'rationale' => $rationale,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $aDisqualified = $a['disqualifiers'] !== [];
            $bDisqualified = $b['disqualifiers'] !== [];
            if ($aDisqualified !== $bDisqualified) {
                return $aDisqualified ? 1 : -1;
            }
            $cmp = $b['total_score'] <=> $a['total_score'];

            return $cmp !== 0 ? $cmp : strcmp($a['candidate_id'], $b['candidate_id']);
        });

        return [
            'schema' => self::SCHEMA,
            'scores' => $rows,
            'recommended_order' => array_column($rows, 'candidate_id'),
        ];
    }

    private function clamp01(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
