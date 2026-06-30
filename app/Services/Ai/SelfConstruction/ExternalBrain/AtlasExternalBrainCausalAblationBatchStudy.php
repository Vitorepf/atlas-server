<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Causal ablation study for batch-design dimensions. Learns which dimensions
 * cause real Atlas improvement by correlating each against 6 outcome dimensions.
 *
 * Outcome dimensions accepted per batch (AC2):
 *   Good (higher = better): value_proof_rate, commit_success_rate, compounding_impact
 *   Bad  (lower  = better): give_back_rate, poison_rate, implementation_cost
 *
 * Composite score per design dimension:
 *   score = avg_corr(dim, good_outcomes) − avg_corr(dim, bad_outcomes)
 *
 * A dimension is a likely positive cause when score >= POSITIVE_THRESHOLD and
 * a likely negative cause when score <= NEGATIVE_THRESHOLD.
 *
 * Confounders (AC3): design dimensions whose inter-correlation >= CONFOUNDER_THRESHOLD
 * AND both show a meaningful composite score are labelled confounders and excluded
 * from causal lists.
 *
 * Confidence (AC3):
 *   weak   — sample_size < min_sample_size, OR confounders dominate causal signals
 *   medium — adequate sample with some confounders
 *   high   — adequate sample, no confounders
 *
 * Pure, deterministic, no I/O, no providers.
 */
final class AtlasExternalBrainCausalAblationBatchStudy
{
    public const SCHEMA = 'atlas.external_brain.causal_ablation_batch_study.v1';

    private const MIN_SAMPLE_DEFAULT    = 10;
    private const POSITIVE_THRESHOLD   =  0.30;
    private const NEGATIVE_THRESHOLD   = -0.30;
    private const CONFOUNDER_THRESHOLD =  0.80;

    private const GOOD_OUTCOMES = ['value_proof_rate', 'commit_success_rate', 'compounding_impact'];
    private const BAD_OUTCOMES  = ['give_back_rate', 'poison_rate', 'implementation_cost'];

    public function study(array $facts): array
    {
        $batches       = is_array($facts['batches'] ?? null) ? $facts['batches'] : [];
        $minSample     = max(1, (int)   ($facts['min_sample_size']                   ?? self::MIN_SAMPLE_DEFAULT));
        $confThreshold = max(0.0, min(1.0, (float) ($facts['confounder_correlation_threshold'] ?? self::CONFOUNDER_THRESHOLD)));

        $sampleSize = count($batches);

        $dimVectors     = []; // design_dim  → float[]
        $outcomeVectors = []; // outcome_dim → float[]

        foreach ($batches as $batch) {
            $outcomes = is_array($batch['outcome_dimensions'] ?? null) ? $batch['outcome_dimensions'] : [];
            foreach (array_merge(self::GOOD_OUTCOMES, self::BAD_OUTCOMES) as $od) {
                $outcomeVectors[$od][] = max(0.0, min(1.0, (float) ($outcomes[$od] ?? 0.0)));
            }
            $dims = is_array($batch['dimensions'] ?? null) ? $batch['dimensions'] : [];
            foreach ($dims as $dim => $val) {
                $dimVectors[$dim][] = is_bool($val) ? ($val ? 1.0 : 0.0) : (float) $val;
            }
        }

        $fullDimensions    = array_filter($dimVectors, fn ($v) => count($v) === $sampleSize);
        $studiedDimensions = array_keys($fullDimensions);

        if ($sampleSize === 0 || $fullDimensions === []) {
            return $this->emptyResult($sampleSize, $studiedDimensions, $minSample);
        }

        $compositeScores  = [];
        $outcomeCorrelMap = [];

        foreach ($fullDimensions as $dim => $vals) {
            $goodCorrs = [];
            $badCorrs  = [];
            $allCorrs  = [];

            foreach (self::GOOD_OUTCOMES as $od) {
                $c = $this->pearson($vals, $outcomeVectors[$od]);
                $goodCorrs[] = $c;
                $allCorrs[$od] = round($c, 4);
            }
            foreach (self::BAD_OUTCOMES as $od) {
                $c = $this->pearson($vals, $outcomeVectors[$od]);
                $badCorrs[]    = $c;
                $allCorrs[$od] = round($c, 4);
            }

            $composite = ($goodCorrs !== [] ? array_sum($goodCorrs) / count($goodCorrs) : 0.0)
                       - ($badCorrs  !== [] ? array_sum($badCorrs)  / count($badCorrs)  : 0.0);

            $compositeScores[$dim]  = $composite;
            $outcomeCorrelMap[$dim] = $allCorrs;
        }

        // Inter-dimension confounders.
        $confounderSet = [];
        $dimList = array_keys($fullDimensions);
        foreach ($dimList as $i => $dimA) {
            foreach ($dimList as $j => $dimB) {
                if ($j <= $i) {
                    continue;
                }
                $interCorr = $this->pearson($fullDimensions[$dimA], $fullDimensions[$dimB]);
                if ($interCorr >= $confThreshold
                    && abs($compositeScores[$dimA]) >= self::POSITIVE_THRESHOLD
                    && abs($compositeScores[$dimB]) >= self::POSITIVE_THRESHOLD) {
                    $confounderSet[$dimA] = "correlated_with_$dimB (r=" . round($interCorr, 3) . ')';
                    $confounderSet[$dimB] = "correlated_with_$dimA (r=" . round($interCorr, 3) . ')';
                }
            }
        }

        $positiveCauses = [];
        $negativeCauses = [];

        foreach ($compositeScores as $dim => $score) {
            if (isset($confounderSet[$dim])) {
                continue;
            }
            $entry = [
                'dimension'            => $dim,
                'composite_score'      => round($score, 4),
                'strength'             => $this->strength($score),
                'outcome_correlations' => $outcomeCorrelMap[$dim],
            ];
            if ($score >= self::POSITIVE_THRESHOLD) {
                $positiveCauses[] = $entry;
            } elseif ($score <= self::NEGATIVE_THRESHOLD) {
                $negativeCauses[] = $entry;
            }
        }

        usort($positiveCauses, static fn ($a, $b) => $b['composite_score'] <=> $a['composite_score']);
        usort($negativeCauses, static fn ($a, $b) => $a['composite_score'] <=> $b['composite_score']);

        $confounders = array_map(
            static fn (string $d, string $r): array => ['dimension' => $d, 'reason' => $r],
            array_keys($confounderSet), array_values($confounderSet),
        );

        $causalCount     = count($positiveCauses) + count($negativeCauses);
        $confounderCount = count($confounderSet);

        [$confidence, $confidenceReason] = $this->confidence($sampleSize, $minSample, $confounderCount, $causalCount);

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

    private function pearson(array $x, array $y): float
    {
        $n = count($x);
        if ($n < 2) {
            return 0.0;
        }
        $mx = array_sum($x) / $n;
        $my = array_sum($y) / $n;
        $num = $dx2 = $dy2 = 0.0;
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

    private function strength(float $score): string
    {
        $abs = abs($score);
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

    private function emptyResult(int $n, array $dims, int $min): array
    {
        [$conf, $reason] = $n < $min
            ? ['weak', "sample_size $n below minimum $min"]
            : ['weak', 'no_studied_dimensions'];

        return [
            'schema_version'         => self::SCHEMA,
            'sample_size'            => $n,
            'studied_dimensions'     => $dims,
            'likely_positive_causes' => [],
            'likely_negative_causes' => [],
            'confounders'            => [],
            'confidence'             => $conf,
            'confidence_reason'      => $reason,
        ];
    }
}
