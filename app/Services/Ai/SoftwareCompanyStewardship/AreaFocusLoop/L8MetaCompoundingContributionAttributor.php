<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * L8-P2 meta-compounding: attributes an observed dm_dt (the derivative of the
 * wrapper multiplier M) to the candidate M factors that may have produced it.
 *
 * Each candidate carries its own per-window activity series aligned with the
 * observed dm_dt series. Contribution is the measured Pearson correlation
 * between the factor activity and the observed dm_dt over the overlapping
 * windows, so a factor only earns credit when its movement actually tracks the
 * measured multiplier movement — "weight changes require measured contribution".
 *
 * Pure: every returned field is computed from the method inputs through real
 * statistics. No I/O, clock, randomness or external state. Identical inputs
 * always yield identical output.
 */
final class L8MetaCompoundingContributionAttributor
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.meta_compounding.contribution_attribution.v1';

    /**
     * Minimum number of aligned windows before a correlation is trusted at all;
     * below this the factor is flagged noisy regardless of its raw correlation.
     */
    private const MIN_SAMPLE_COUNT = 3;

    /**
     * Number of aligned windows at which sample sufficiency reaches its ceiling
     * of 1.0; fewer windows scale confidence down proportionally.
     */
    private const FULL_CONFIDENCE_SAMPLE_COUNT = 6;

    /**
     * Confidence floor; below it the factor is marked insufficient_evidence and
     * can never be recommended for adoption.
     */
    private const CONFIDENCE_THRESHOLD = 0.5;

    /**
     * Minimum positive contribution before a factor may be recommended; a
     * negative or weak correlation never recommends adoption.
     */
    private const POSITIVE_CONTRIBUTION_THRESHOLD = 0.3;

    /**
     * Variance below this is treated as a flat / no-signal series, which makes
     * the correlation undefined and the factor noisy.
     */
    private const VARIANCE_EPSILON = 1.0e-9;

    /**
     * @param  list<float|int>  $series  observed dm_dt per window (oldest first)
     * @param  list<array{factor_id?: mixed, factor_series?: mixed, source_refs?: mixed}>  $candidates
     * @return array{
     *     schema_version: string,
     *     evaluated_factor_count: int,
     *     recommended_factor_ids: list<string>,
     *     insufficient_evidence: bool,
     *     attributions: list<array{factor_id: string, contribution_score: float, confidence: float, sample_count: int, noisy: bool, insufficient_evidence: bool, recommend_adoption: bool, source_refs: list<string>}>
     * }
     */
    public function attribute(array $series, array $candidates): array
    {
        $observed = $this->numericSeries($series);

        $attributions = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $attributions[] = $this->attributeFactor($observed, $candidate);
        }

        usort($attributions, static function (array $a, array $b): int {
            // Primary: contribution score DESC. Tie-break: factor_id ASC as a
            // STRING (strcmp) — never the array spaceship, whose string compare
            // coerces numeric-string ids into a numeric ordering and breaks the
            // deterministic list<string> ordering of factor_id.
            return ($b['contribution_score'] <=> $a['contribution_score'])
                ?: strcmp($a['factor_id'], $b['factor_id']);
        });

        $recommended = [];
        $anyWithEvidence = false;
        foreach ($attributions as $attribution) {
            if ($attribution['recommend_adoption']) {
                $recommended[] = $attribution['factor_id'];
            }

            if (! $attribution['insufficient_evidence']) {
                $anyWithEvidence = true;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'evaluated_factor_count' => count($attributions),
            'recommended_factor_ids' => $recommended,
            'insufficient_evidence' => $attributions === [] || ! $anyWithEvidence,
            'attributions' => array_values($attributions),
        ];
    }

    /**
     * @param  list<float>  $observed
     * @param  array{factor_id?: mixed, factor_series?: mixed, source_refs?: mixed}  $candidate
     * @return array{factor_id: string, contribution_score: float, confidence: float, sample_count: int, noisy: bool, insufficient_evidence: bool, recommend_adoption: bool, source_refs: list<string>}
     */
    private function attributeFactor(array $observed, array $candidate): array
    {
        $factorId = AreaFocusScalarNormalizer::trimmedStringOnly($candidate['factor_id'] ?? '');
        $sourceRefs = AreaFocusStringListNormalizer::preserveNonBlankStrings($candidate['source_refs'] ?? []);
        $factorSeries = $this->numericSeries($candidate['factor_series'] ?? []);

        $length = min(count($observed), count($factorSeries));
        $observedSlice = array_slice($observed, 0, $length);
        $factorSlice = array_slice($factorSeries, 0, $length);

        $observedVariance = $this->variance($observedSlice);
        $factorVariance = $this->variance($factorSlice);

        $noisy = $length < self::MIN_SAMPLE_COUNT
            || $observedVariance <= self::VARIANCE_EPSILON
            || $factorVariance <= self::VARIANCE_EPSILON;

        $contribution = $noisy
            ? 0.0
            : $this->correlation($factorSlice, $observedSlice, $factorVariance, $observedVariance);
        $contribution = round($this->clamp($contribution, -1.0, 1.0), 4);

        $sampleSufficiency = $this->clamp($length / self::FULL_CONFIDENCE_SAMPLE_COUNT, 0.0, 1.0);
        $confidence = $noisy
            ? 0.0
            : round($this->clamp($sampleSufficiency * abs($contribution), 0.0, 1.0), 4);

        $insufficientEvidence = $confidence < self::CONFIDENCE_THRESHOLD;
        $recommendAdoption = $factorId !== ''
            && ! $noisy
            && ! $insufficientEvidence
            && $contribution >= self::POSITIVE_CONTRIBUTION_THRESHOLD;

        return [
            'factor_id' => $factorId,
            'contribution_score' => $contribution,
            'confidence' => $confidence,
            'sample_count' => $length,
            'noisy' => $noisy,
            'insufficient_evidence' => $insufficientEvidence,
            'recommend_adoption' => $recommendAdoption,
            'source_refs' => $sourceRefs,
        ];
    }

    /**
     * Population Pearson correlation coefficient over two equal-length series.
     *
     * @param  list<float>  $x
     * @param  list<float>  $y
     */
    private function correlation(array $x, array $y, float $varX, float $varY): float
    {
        $count = count($x);
        $meanX = array_sum($x) / $count;
        $meanY = array_sum($y) / $count;

        $covariance = 0.0;
        for ($i = 0; $i < $count; $i++) {
            $covariance += ($x[$i] - $meanX) * ($y[$i] - $meanY);
        }
        $covariance /= $count;

        $denominator = sqrt($varX * $varY);
        if (! is_finite($denominator) || $denominator <= self::VARIANCE_EPSILON) {
            return 0.0;
        }

        $correlation = $covariance / $denominator;

        // Overflow guard: huge-magnitude series push the variance product to +INF,
        // so the ratio collapses to NAN, which the downstream [-1.0, 1.0] clamp
        // cannot tame (max/min propagate NAN). A non-finite correlation is not a
        // measured contribution — treat it as no signal.
        return is_finite($correlation) ? $correlation : 0.0;
    }

    /**
     * Population variance of a numeric series.
     *
     * @param  list<float>  $values
     */
    private function variance(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $sumSquares = 0.0;
        foreach ($values as $value) {
            $delta = $value - $mean;
            $sumSquares += $delta * $delta;
        }

        return $sumSquares / $count;
    }

    /**
     * @return list<float>
     */
    private function numericSeries(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $series = [];
        foreach ($raw as $value) {
            if (is_int($value) || is_float($value)) {
                $series[] = (float) $value;
            }
        }

        return $series;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }
}
