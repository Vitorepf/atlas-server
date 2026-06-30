<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Promotion-grade evidence chain verifier for model-amplifier scaffold lift.
 * Proves scaffolded run produced measurable real-value lift over baseline on identical challenges.
 *
 * INPUT:
 *   pairs               — list of {challenge_id, baseline_scores{dim→float}, scaffolded_scores{dim→float}}
 *   required_dimensions — dims that must be present and lifted (use real dims, not gate_pass_rate)
 *   min_sample_size     — min valid pairs (default 5)
 *   min_lift_threshold  — per-dimension average lift floor (default 0.05)
 *   give_back_delta     — scaffolded_give_back_rate − baseline_give_back_rate (must be ≤ 0)
 *   poison_delta        — scaffolded_poison_rate − baseline_poison_rate (must be ≤ 0)
 *   cost_ceiling        — max allowed cost ratio scaffolded/baseline (e.g. 2.0)
 *   token_cost_baseline — baseline token cost (for cost ratio check)
 *   token_cost_scaffolded — scaffolded token cost
 *
 * REJECTION GATES (all evaluated; each match adds to rejected_claims):
 *   insufficient_sample        — valid pair count < min_sample_size
 *   missing_required_dimension — required dim absent from all pairs
 *   lift_below_threshold       — required dim average lift < threshold
 *   worse_give_back_delta      — give_back_delta > 0 (scaffolded regressions)
 *   worse_poison_delta         — poison_delta > 0
 *   cost_above_ceiling         — token_cost_scaffolded / token_cost_baseline > cost_ceiling
 *   proxy_only_lift            — all lifted required dims are proxy-only (gate_pass_rate);
 *                                none from REAL_LIFT_DIMENSIONS show lift
 *
 * REAL LIFT DIMENSIONS (non-proxy):
 *   commit_success_prediction, implementability, dedup_honesty,
 *   structural_leverage, compounding_impact
 *
 * evidence_type (optional, default 'before_after_benchmark') — the source of the lift claim.
 *   ACCEPTED: before_after_benchmark, heldout_case_delta, repeated_outcome_improvement
 *   REJECTED: self_declared, anecdotal, single_unverified_result, or any unrecognized value —
 *             a prompt asserting "this improved reasoning" is never sufficient by itself.
 *
 * OUTPUT:
 *   schema_version, verified, lift_by_dimension, rejected_claims, sample_size,
 *   promotion_readiness, next_measurement_recommendation,
 *   accepted, confidence, missing_evidence, evidence_type
 *
 * accepted = verified (benchmark proof) AND evidence_type is one of the ACCEPTED types.
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainScaffoldLiftReceiptVerifier
{
    public const SCHEMA = 'atlas.external_brain.scaffold_lift_receipt_verifier.v1';

    private const DEFAULT_MIN_SAMPLE = 5;
    private const DEFAULT_MIN_LIFT   = 0.05;

    private const REAL_LIFT_DIMENSIONS = [
        'commit_success_prediction',
        'implementability',
        'dedup_honesty',
        'structural_leverage',
        'compounding_impact',
    ];

    private const PROXY_DIMENSIONS = ['gate_pass_rate'];

    public const ACCEPTED_EVIDENCE_TYPES = [
        'before_after_benchmark',
        'heldout_case_delta',
        'repeated_outcome_improvement',
    ];

    public const REJECTED_EVIDENCE_TYPES = [
        'self_declared',
        'anecdotal',
        'single_unverified_result',
    ];

    private const DEFAULT_EVIDENCE_TYPE = 'before_after_benchmark';

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function verify(array $facts): array
    {
        $rawPairs     = is_array($facts['pairs'] ?? null) ? $facts['pairs'] : [];
        $requiredDims = is_array($facts['required_dimensions'] ?? null)
            ? array_map('strval', $facts['required_dimensions'])
            : [];
        $minSample = max(1, (int) ($facts['min_sample_size']   ?? self::DEFAULT_MIN_SAMPLE));
        $minLift   = (float) ($facts['min_lift_threshold'] ?? self::DEFAULT_MIN_LIFT);

        // New delta / cost inputs.
        $giveBackDelta       = isset($facts['give_back_delta'])       ? (float) $facts['give_back_delta']       : null;
        $poisonDelta         = isset($facts['poison_delta'])          ? (float) $facts['poison_delta']          : null;
        $costCeiling         = isset($facts['cost_ceiling'])          ? (float) $facts['cost_ceiling']          : null;
        $tokenCostBaseline   = isset($facts['token_cost_baseline'])   ? (float) $facts['token_cost_baseline']   : null;
        $tokenCostScaffolded = isset($facts['token_cost_scaffolded']) ? (float) $facts['token_cost_scaffolded'] : null;

        // Validate pairs — both sides must have scores.
        $validPairs = $this->filterValidPairs($rawPairs);
        $sampleSize = count($validPairs);

        $rejectedClaims = [];

        // Insufficient sample.
        if ($sampleSize < $minSample) {
            $rejectedClaims[] = [
                'reason'  => 'insufficient_sample',
                'details' => "valid pairs: $sampleSize, required: $minSample",
            ];
        }

        // Per-dimension lift.
        $liftByDimension = $this->computeLift($validPairs);

        // Required dimension missing.
        foreach ($requiredDims as $dim) {
            if (! array_key_exists($dim, $liftByDimension)) {
                $rejectedClaims[] = [
                    'reason'  => 'missing_required_dimension',
                    'details' => "dimension '$dim' absent from all pairs",
                ];
            }
        }

        // Required dimension lift below threshold.
        foreach ($requiredDims as $dim) {
            if (array_key_exists($dim, $liftByDimension) &&
                $liftByDimension[$dim]['average_lift'] < $minLift) {
                $rejectedClaims[] = [
                    'reason'  => 'lift_below_threshold',
                    'details' => sprintf(
                        "dimension '%s' average lift %.4f < threshold %.4f",
                        $dim,
                        $liftByDimension[$dim]['average_lift'],
                        $minLift,
                    ),
                ];
            }
        }

        // Worse give_back delta.
        if ($giveBackDelta !== null && $giveBackDelta > 0.0) {
            $rejectedClaims[] = [
                'reason'  => 'worse_give_back_delta',
                'details' => sprintf('give_back_delta %.4f > 0; scaffolded regresses give_back rate', $giveBackDelta),
            ];
        }

        // Worse poison delta.
        if ($poisonDelta !== null && $poisonDelta > 0.0) {
            $rejectedClaims[] = [
                'reason'  => 'worse_poison_delta',
                'details' => sprintf('poison_delta %.4f > 0; scaffolded regresses poison rate', $poisonDelta),
            ];
        }

        // Cost above ceiling.
        if ($costCeiling !== null && $tokenCostBaseline !== null && $tokenCostBaseline > 0.0
            && $tokenCostScaffolded !== null) {
            $ratio = $tokenCostScaffolded / $tokenCostBaseline;
            if ($ratio > $costCeiling) {
                $rejectedClaims[] = [
                    'reason'  => 'cost_above_ceiling',
                    'details' => sprintf(
                        'cost_ratio %.4f > ceiling %.4f (baseline %.4f, scaffolded %.4f)',
                        $ratio,
                        $costCeiling,
                        $tokenCostBaseline,
                        $tokenCostScaffolded,
                    ),
                ];
            }
        }

        // Proxy-only lift: all lifted required dims are proxy (e.g. gate_pass_rate) with
        // no real-value dimension showing lift.
        if ($requiredDims !== []) {
            $liftedRequired = array_values(array_filter(
                $requiredDims,
                fn(string $d): bool => array_key_exists($d, $liftByDimension)
                    && $liftByDimension[$d]['is_lifted'],
            ));
            if ($liftedRequired !== []) {
                $proxyLifted = array_values(array_filter(
                    $liftedRequired,
                    fn(string $d): bool => in_array($d, self::PROXY_DIMENSIONS, true),
                ));
                // Fire only when EVERY lifted required dim is a known proxy.
                if (count($proxyLifted) === count($liftedRequired)) {
                    $rejectedClaims[] = [
                        'reason'  => 'proxy_only_lift',
                        'details' => 'only proxy dimensions (e.g. gate_pass_rate) show lift; no real-value dimension lifted',
                    ];
                }
            }
        }

        $verified           = empty($rejectedClaims) && $sampleSize > 0;
        $promotionReadiness = $verified;

        // Evidence-type gate: lift is never accepted on a self-declared/anecdotal/single-result
        // claim, regardless of how the benchmark pairs scored.
        $evidenceType = (string) ($facts['evidence_type'] ?? self::DEFAULT_EVIDENCE_TYPE);
        $evidenceTypeAccepted = in_array($evidenceType, self::ACCEPTED_EVIDENCE_TYPES, true);

        $missingEvidence = [];
        if (! $evidenceTypeAccepted) {
            $missingEvidence[] = sprintf(
                "evidence_type '%s' is not an accepted lift evidence source; requires one of: %s",
                $evidenceType,
                implode(', ', self::ACCEPTED_EVIDENCE_TYPES),
            );
        }
        foreach ($rejectedClaims as $claim) {
            $missingEvidence[] = $claim['details'];
        }

        $accepted = $verified && $evidenceTypeAccepted;

        $confidence = 'low';
        if ($accepted) {
            $confidence = $sampleSize >= ($minSample * 2) ? 'high' : 'medium';
        } elseif (! $evidenceTypeAccepted) {
            $confidence = 'none';
        }

        return [
            'schema_version'                  => self::SCHEMA,
            'verified'                        => $verified,
            'promotion_readiness'             => $promotionReadiness,
            'lift_by_dimension'               => $liftByDimension,
            'rejected_claims'                 => $rejectedClaims,
            'sample_size'                     => $sampleSize,
            'next_measurement_recommendation' => $this->recommendation($verified, $sampleSize, $minSample, $rejectedClaims),
            'accepted'                        => $accepted,
            'confidence'                      => $confidence,
            'missing_evidence'                => $missingEvidence,
            'evidence_type'                   => $evidenceType,
        ];
    }

    private function filterValidPairs(array $rawPairs): array
    {
        return array_values(array_filter($rawPairs, static function (mixed $pair): bool {
            if (! is_array($pair)) {
                return false;
            }
            $b = $pair['baseline_scores']   ?? null;
            $s = $pair['scaffolded_scores'] ?? null;
            return is_array($b) && ! empty($b) && is_array($s) && ! empty($s);
        }));
    }

    private function computeLift(array $pairs): array
    {
        if (empty($pairs)) {
            return [];
        }

        $sums   = [];
        $counts = [];

        foreach ($pairs as $pair) {
            $baseline   = (array) ($pair['baseline_scores']   ?? []);
            $scaffolded = (array) ($pair['scaffolded_scores'] ?? []);

            $sharedDims = array_intersect(array_keys($baseline), array_keys($scaffolded));
            foreach ($sharedDims as $dim) {
                $lift = (float) $scaffolded[$dim] - (float) $baseline[$dim];
                $sums[$dim]   = ($sums[$dim]   ?? 0.0) + $lift;
                $counts[$dim] = ($counts[$dim] ?? 0) + 1;
            }
        }

        $result = [];
        foreach ($sums as $dim => $sum) {
            $n    = $counts[$dim];
            $avg  = round($sum / $n, 6);
            $result[$dim] = [
                'average_lift' => $avg,
                'is_lifted'    => $avg > 0.0,
                'sample_count' => $n,
            ];
        }

        ksort($result);

        return $result;
    }

    private function recommendation(bool $verified, int $sampleSize, int $minSample, array $rejected): string
    {
        if ($verified) {
            return 'lift proven; run a larger cohort to increase confidence';
        }

        foreach ($rejected as $claim) {
            if ($claim['reason'] === 'insufficient_sample') {
                $need = $minSample - $sampleSize;
                return "collect $need more paired challenge results to meet minimum sample of $minSample";
            }
            if ($claim['reason'] === 'missing_required_dimension') {
                return 'ensure all required dimensions are scored in both baseline and scaffolded outputs';
            }
            if ($claim['reason'] === 'lift_below_threshold') {
                return 'increase scaffold quality or widen challenge diversity before re-measuring lift';
            }
        }

        return 'add paired challenges and re-verify';
    }
}
