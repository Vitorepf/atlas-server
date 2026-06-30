<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure verifier. Proves that a scaffolded model run produced measurable lift
 * over a baseline on the same challenge set.
 *
 * Input facts:
 *   pairs              — list of {challenge_id, baseline_scores{dim→float}, scaffolded_scores{dim→float}}.
 *   required_dimensions — list of dimension names that must be present and lifted.
 *   min_sample_size    — minimum valid pairs required (default 5).
 *   min_lift_threshold — minimum per-dimension average lift to count as lifted (default 0.05).
 *
 * AC2: Each pair must supply both baseline_scores and scaffolded_scores for the same
 *   challenge. Unpaired or asymmetric pairs are excluded before any calculation.
 *
 * AC3: Lift claim is rejected when any of:
 *   - valid pair count < min_sample_size           → insufficient_sample
 *   - a required dimension absent from all pairs   → missing_required_dimension
 *   - a required dimension average lift < threshold → lift_below_threshold
 *
 * AC4 outputs: verified, lift_by_dimension, rejected_claims, sample_size,
 *   next_measurement_recommendation.
 *
 * Pure, deterministic, no providers, no I/O.
 */
final class AtlasExternalBrainScaffoldLiftReceiptVerifier
{
    public const SCHEMA = 'atlas.external_brain.scaffold_lift_receipt_verifier.v1';

    private const DEFAULT_MIN_SAMPLE      = 5;
    private const DEFAULT_MIN_LIFT        = 0.05;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function verify(array $facts): array
    {
        $rawPairs          = is_array($facts['pairs'] ?? null) ? $facts['pairs'] : [];
        $requiredDims      = is_array($facts['required_dimensions'] ?? null) ? $facts['required_dimensions'] : [];
        $minSample         = max(1, (int) ($facts['min_sample_size']    ?? self::DEFAULT_MIN_SAMPLE));
        $minLift           = (float) ($facts['min_lift_threshold'] ?? self::DEFAULT_MIN_LIFT);

        // AC2: Validate pairs — both sides must have scores.
        $validPairs = $this->filterValidPairs($rawPairs);
        $sampleSize = count($validPairs);

        $rejectedClaims = [];

        // AC3a: insufficient sample.
        if ($sampleSize < $minSample) {
            $rejectedClaims[] = [
                'reason'  => 'insufficient_sample',
                'details' => "valid pairs: $sampleSize, required: $minSample",
            ];
        }

        // Compute per-dimension lift across valid pairs.
        $liftByDimension = $this->computeLift($validPairs);

        // AC3b: required dimension missing from all pairs.
        foreach ($requiredDims as $dim) {
            $dim = (string) $dim;
            if (! array_key_exists($dim, $liftByDimension)) {
                $rejectedClaims[] = [
                    'reason'  => 'missing_required_dimension',
                    'details' => "dimension '$dim' absent from all pairs",
                ];
            }
        }

        // AC3c: required dimension lift below threshold.
        foreach ($requiredDims as $dim) {
            $dim = (string) $dim;
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

        $verified = empty($rejectedClaims) && $sampleSize > 0;

        return [
            'schema_version'               => self::SCHEMA,
            'verified'                     => $verified,
            'lift_by_dimension'            => $liftByDimension,
            'rejected_claims'              => $rejectedClaims,
            'sample_size'                  => $sampleSize,
            'next_measurement_recommendation' => $this->recommendation($verified, $sampleSize, $minSample, $rejectedClaims),
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
