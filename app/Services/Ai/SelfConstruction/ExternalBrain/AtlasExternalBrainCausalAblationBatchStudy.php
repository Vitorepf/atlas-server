<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure causal ablation study. Compares historical task batches and estimates
 * which controlled dimensions cause positive or negative outcomes.
 *
 * Method (AC2):
 *   For each numeric/boolean dimension present in all batches, compute the
 *   Pearson correlation coefficient against outcome_metric across all batches.
 *   Dimensions with corr >= POSITIVE_THRESHOLD are likely positive causes;
 *   corr <= NEGATIVE_THRESHOLD are likely negative causes; the rest are neutral.
 *
 * Confounders (AC2):
 *   Two dimensions are considered confounded when their inter-dimension
 *   correlation exceeds CONFOUNDER_THRESHOLD (default 0.80) AND both correlate
 *   with the outcome. Both are labelled confounders.
 *
 * Confidence (AC3):
 *   WEAK   — sample_size < min_sample_size, OR ≥ half of causal dimensions are confounders.
 *   MEDIUM — sample_size >= min_sample_size AND some confounders.
 *   HIGH   — sample_size >= min_sample_size AND no confounders.
 *
 * AC4 outputs: likely_positive_causes, likely_negative_causes, confounders,
 *   confidence, confidence_reason, sample_size, studied_dimensions.
 *
 * Pure, deterministic (input order preserved), no providers, no I/O.
 */
final class AtlasExternalBrainCausalAblationBatchStudy
{
    public const SCHEMA = 'atlas.external_brain.causal_ablation_batch_study.v1';

    private const MIN_SAMPLE_DEFAULT    = 10;
    private const POSITIVE_THRESHOLD   =  0.30;
    private const NEGATIVE_THRESHOLD   = -0.30;
    private const CONFOUNDER_THRESHOLD =  0.80;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function study(array $facts): array
    {
        $batches       = is_array($facts['batches'] ?? null) ? $facts['batches'] : [];
        $minSample     = max(1, (int) ($facts['min_sample_size'] ?? self::MIN_SAMPLE_DEFAULT));
        $confThreshold = max(0.0, min(1.0, (float) ($facts['confounder_correlation_threshold'] ?? self::CONFOUNDER_THRESHOLD)));

        $sampleSize = count($batches);

        // Extract outcomes and dimension vectors.
        $outcomes   = [];
        $dimVectors = []; // dim -> float[]

        foreach ($batches as $batch) {
            $outcomes[] = max(0.0, min(1.0, (float) ($batch['outcome_metric'] ?? 0.0)));
            $dims = is_array($batch['dimensions'] ?? null) ? $batch['dimensions'] : [];
            foreach ($dims as $dim => $val) {
                $dimVectors[$dim][] = is_bool($val) ? ($val ? 1.0 : 0.0) : (float) $val;
            }
        }

        // Keep only dimensions present in ALL batches.
        $fullDimensions = [];
        foreach ($dimVectors as $dim => $vals) {
            if (count($vals) === $sampleSize) {
                $fullDimensions[$dim] = $vals;
            }
        }

        $studiedDimensions = array_keys($fullDimensions);

        if ($sampleSize === 0 || empty($fullDimensions)) {
            return $this->emptyResult($sampleSize, $studiedDimensions, $minSample);
        }

        // Correlations of each dim with outcome.
        $dimCorrelations = [];
        foreach ($fullDimensions as $dim => $vals) {
            $dimCorrelations[$dim] = $this->pearson($vals, $outcomes);
        }

        // Inter-dimension correlations to find confounders.
        $confounderSet = [];
        $dimList = array_keys($fullDimensions);
        foreach ($dimList as $i => $dimA) {
            foreach ($dimList as $j => $dimB) {
                if ($j <= $i) {
                    continue;
                }
                $interCorr = abs($this->pearson($fullDimensions[$dimA], $fullDimensions[$dimB]));
                if ($interCorr >= $confThreshold) {
                    // Both must have a meaningful correlation with outcome to be confounders.
                    if (abs($dimCorrelations[$dimA]) >= abs(self::POSITIVE_THRESHOLD)
                        && abs($dimCorrelations[$dimB]) >= abs(self::POSITIVE_THRESHOLD)) {
                        $confounderSet[$dimA] = "correlated_with_$dimB (r=" . round($interCorr, 3) . ')';
                        $confounderSet[$dimB] = "correlated_with_$dimA (r=" . round($interCorr, 3) . ')';
                    }
                }
            }
        }

        $positiveCauses = [];
        $negativeCauses = [];

        foreach ($dimCorrelations as $dim => $corr) {
            if (isset($confounderSet[$dim])) {
                continue; // confounders are excluded from causal lists
            }
            $entry = ['dimension' => $dim, 'correlation' => round($corr, 4), 'strength' => $this->strength($corr)];
            if ($corr >= self::POSITIVE_THRESHOLD) {
                $positiveCauses[] = $entry;
            } elseif ($corr <= self::NEGATIVE_THRESHOLD) {
                $negativeCauses[] = $entry;
            }
        }

        // Sort by absolute correlation descending.
        usort($positiveCauses, static fn ($a, $b) => $b['correlation'] <=> $a['correlation']);
        usort($negativeCauses, static fn ($a, $b) => $a['correlation'] <=> $b['correlation']);

        $confounders = array_map(
            static fn (string $dim, string $reason): array => ['dimension' => $dim, 'reason' => $reason],
            array_keys($confounderSet), array_values($confounderSet)
        );

        // Confidence (AC3).
        $causalCount     = count($positiveCauses) + count($negativeCauses);
        $confounderCount = count($confounderSet);

        [$confidence, $confidenceReason] = $this->confidence(
            $sampleSize, $minSample, $confounderCount, $causalCount
        );

        return [
            'schema_version'         => self::SCHEMA,
            'sample_size'            => $sampleSize,
            'studied_dimensions'     => $studiedDimensions,
            'likely_positive_causes' => $positiveCauses,
            'likely_negative_causes' => $negativeCauses,
            'confounders'            => $confounders,
            'confidence'             => $confidence,
            'confidence_reason'      => $confidenceReason,
        ];
    }

    /** Pearson correlation coefficient; returns 0.0 when undefined. */
    private function pearson(array $x, array $y): float
    {
        $n = count($x);
        if ($n < 2) {
            return 0.0;
        }
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $num = 0.0;
        $dx2 = 0.0;
        $dy2 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx   = $x[$i] - $mx;
            $dy   = $y[$i] - $my;
            $num += $dx * $dy;
            $dx2 += $dx * $dx;
            $dy2 += $dy * $dy;
        }
        $denom = sqrt($dx2 * $dy2);

        return $denom < 1e-12 ? 0.0 : $num / $denom;
    }

    private function strength(float $corr): string
    {
        $abs = abs($corr);
        if ($abs >= 0.7) return 'strong';
        if ($abs >= 0.4) return 'moderate';

        return 'weak';
    }

    /** @return array{string, string} */
    private function confidence(int $n, int $min, int $confounders, int $causal): array
    {
        if ($n < $min) {
            return ['weak', "sample_size $n below minimum $min"];
        }
        if ($confounders > 0 && ($causal === 0 || $confounders >= $causal)) {
            return ['weak', "confounders ($confounders) dominate causal signals ($causal)"];
        }
        if ($confounders > 0) {
            return ['medium', "some confounders ($confounders) limit isolation"];
        }

        return ['high', 'adequate_sample_no_confounders'];
    }

    /** @return array<string,mixed> */
    private function emptyResult(int $n, array $dims, int $min): array
    {
        [$confidence, $reason] = $n < $min
            ? ['weak', "sample_size $n below minimum $min"]
            : ['weak', 'no_studied_dimensions'];

        return [
            'schema_version'         => self::SCHEMA,
            'sample_size'            => $n,
            'studied_dimensions'     => $dims,
            'likely_positive_causes' => [],
            'likely_negative_causes' => [],
            'confounders'            => [],
            'confidence'             => $confidence,
            'confidence_reason'      => $reason,
        ];
    }
}
