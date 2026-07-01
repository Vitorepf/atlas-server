<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Compounding;

/**
 * Pure tracker. Computes cycle-over-cycle FACTS over a chronologically-ordered list of cycle outcome
 * facts (the per-cycle aggregate produced from AtlasSelfConstructionCompoundingOutcomeProjection):
 *
 *   throughput_delta            : passed_count[i] - passed_count[i-1]
 *   blocker_decay               : blockers_count[i-1] - blockers_count[i]   (positive = decay = good)
 *   rework_decay                : rework_count[i-1] - rework_count[i]
 *   evidence_completeness_delta : completeness[i] - completeness[i-1]       (0.0–1.0 fractions)
 *
 * Emits a `trend_label` per dimension (improving / flat / regressing) — FACTS only; no single vanity score.
 */
final class AtlasSelfConstructionCompoundingVelocityTracker
{
    public const SCHEMA = 'atlas.self_construction.compounding_velocity.v1';

    public const TREND_IMPROVING = 'improving';

    public const TREND_FLAT = 'flat';

    public const TREND_REGRESSING = 'regressing';

    private const LEVERAGE_WEIGHT = 0.5;

    private const CHURN_WEIGHT = 2.0;

    private const PROOF_BOOST_WEIGHT = 0.5;

    private const SIMPLIFICATION_BOOST_WEIGHT = 1.0;

    /** give_back_drag / quality_adjusted_velocity at/above this ratio recommends slowing down. */
    private const HEAVY_DRAG_RATIO = 0.5;

    private const PROOF_STRENGTH_FLOOR = 0.3;

    public const RECOMMENDATION_SCALE_UP = 'scale_up';

    public const RECOMMENDATION_REDUCE_CHURN_FIRST = 'reduce_churn_before_scaling';

    public const RECOMMENDATION_RAISE_PROOF_FIRST = 'raise_proof_strength_before_scaling';

    public const RECOMMENDATION_STABILIZE = 'stabilize';

    /**
     * @param  list<array<string,mixed>>  $cycleFacts  list of per-cycle aggregates in chronological order
     * @return array<string,mixed>
     */
    public function track(array $cycleFacts): array
    {
        $rows = [];
        $previous = null;
        foreach (array_values($cycleFacts) as $idx => $current) {
            if (! is_array($current)) {
                continue;
            }
            $cycleId = (string) ($current['cycle_id'] ?? ('cycle-'.$idx));
            $passed = (int) ($current['passed_count'] ?? 0);
            $blockers = (int) ($current['blockers_count'] ?? 0);
            $rework = (int) ($current['rework_count'] ?? 0);
            $completeness = (float) ($current['evidence_completeness'] ?? 0.0);

            $leverageDelta = (float) ($current['leverage_delta'] ?? 0.0);
            $giveBackCount = (int) ($current['give_back_count'] ?? 0);
            $churnCount = $giveBackCount
                + (int) ($current['poison_count'] ?? 0)
                + (int) ($current['retry_churn'] ?? 0);
            $churnPenalty = round($churnCount * self::CHURN_WEIGHT, 6);
            $qualityVelocity = round(max(0.0, $passed + $leverageDelta * self::LEVERAGE_WEIGHT - $churnPenalty), 6);

            // Additive quality-adjustment facts: proof strength (defaults to evidence_completeness
            // when not separately supplied) and simplification/deletion impact both raise the
            // adjusted velocity; give_back drag isolates just the give_back contribution to churn.
            $proofStrength = max(0.0, min(1.0, (float) ($current['proof_strength'] ?? $completeness)));
            $simplificationImpact = max(0.0, min(1.0, (float) ($current['deletion_impact'] ?? $current['simplification_score'] ?? 0.0)));
            $giveBackDrag = round($giveBackCount * self::CHURN_WEIGHT, 6);
            $proofWeight = round($proofStrength, 6);
            $simplificationWeight = round($simplificationImpact, 6);
            $positiveContribution = $passed
                + $leverageDelta * self::LEVERAGE_WEIGHT
                + $simplificationImpact * self::SIMPLIFICATION_BOOST_WEIGHT
                + $proofStrength * self::PROOF_BOOST_WEIGHT;
            $qualityAdjustedVelocity = round(max(0.0, $positiveContribution - $churnPenalty), 6);
            $recommendation = $this->recommendation($positiveContribution, $giveBackDrag, $proofStrength);

            if ($previous === null) {
                $rows[] = [
                    'cycle_id' => $cycleId,
                    'throughput_delta' => 0,
                    'throughput_trend' => self::TREND_FLAT,
                    'blocker_decay' => 0,
                    'blocker_trend' => self::TREND_FLAT,
                    'rework_decay' => 0,
                    'rework_trend' => self::TREND_FLAT,
                    'evidence_completeness_delta' => 0.0,
                    'evidence_trend' => self::TREND_FLAT,
                    'churn_penalty' => $churnPenalty,
                    'quality_weighted_velocity' => $qualityVelocity,
                    'quality_velocity_trend' => self::TREND_FLAT,
                    'raw_velocity' => $passed,
                    'quality_adjusted_velocity' => $qualityAdjustedVelocity,
                    'give_back_drag' => $giveBackDrag,
                    'proof_weight' => $proofWeight,
                    'simplification_weight' => $simplificationWeight,
                    'recommendation' => $recommendation,
                ];
            } else {
                $tDelta = $passed - (int) $previous['passed_count'];
                $bDecay = (int) $previous['blockers_count'] - $blockers;
                $rDecay = (int) $previous['rework_count'] - $rework;
                $eDelta = $completeness - (float) $previous['evidence_completeness'];
                $qvDelta = $qualityVelocity - (float) $previous['quality_weighted_velocity'];

                $rows[] = [
                    'cycle_id' => $cycleId,
                    'throughput_delta' => $tDelta,
                    'throughput_trend' => $this->trendInt($tDelta),
                    'blocker_decay' => $bDecay,
                    'blocker_trend' => $this->trendInt($bDecay),
                    'rework_decay' => $rDecay,
                    'rework_trend' => $this->trendInt($rDecay),
                    'evidence_completeness_delta' => $eDelta,
                    'evidence_trend' => $this->trendFloat($eDelta),
                    'churn_penalty' => $churnPenalty,
                    'quality_weighted_velocity' => $qualityVelocity,
                    'quality_velocity_trend' => $this->trendFloat($qvDelta),
                    'raw_velocity' => $passed,
                    'quality_adjusted_velocity' => $qualityAdjustedVelocity,
                    'give_back_drag' => $giveBackDrag,
                    'proof_weight' => $proofWeight,
                    'simplification_weight' => $simplificationWeight,
                    'recommendation' => $recommendation,
                ];
            }
            $previous = [
                'passed_count' => $passed,
                'blockers_count' => $blockers,
                'rework_count' => $rework,
                'evidence_completeness' => $completeness,
                'quality_weighted_velocity' => $qualityVelocity,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'rows' => $rows,
        ];
    }

    private function recommendation(float $positiveContribution, float $giveBackDrag, float $proofStrength): string
    {
        if ($giveBackDrag > 0.0 && $giveBackDrag / max($positiveContribution, 0.0001) >= self::HEAVY_DRAG_RATIO) {
            return self::RECOMMENDATION_REDUCE_CHURN_FIRST;
        }
        if ($proofStrength < self::PROOF_STRENGTH_FLOOR) {
            return self::RECOMMENDATION_RAISE_PROOF_FIRST;
        }
        if ($positiveContribution - $giveBackDrag > 0.0) {
            return self::RECOMMENDATION_SCALE_UP;
        }

        return self::RECOMMENDATION_STABILIZE;
    }

    private function trendInt(int $delta): string
    {
        return $delta > 0 ? self::TREND_IMPROVING : ($delta < 0 ? self::TREND_REGRESSING : self::TREND_FLAT);
    }

    private function trendFloat(float $delta): string
    {
        if ($delta > 1e-9) {
            return self::TREND_IMPROVING;
        }
        if ($delta < -1e-9) {
            return self::TREND_REGRESSING;
        }

        return self::TREND_FLAT;
    }
}
