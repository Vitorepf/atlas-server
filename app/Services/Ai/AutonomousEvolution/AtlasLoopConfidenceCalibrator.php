<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ABSURD-LEAP 3 — the CALIBRATION flywheel (arms the honest ">93% confidence").
 *
 * AtlasLoopDeliveryConfidenceModel emits a P(correct); but a number is only trustworthy once it is
 * CALIBRATED against reality — i.e. among deliveries the model scored 0.93, ~93% were actually correct. This
 * calibrator consumes historical (predicted_confidence, actual_correct) samples — sourced from the impact
 * receipts / post-merge outcomes — and measures calibration (Brier score, reliability buckets, over/under-
 * confidence) AND derives the RECOMMENDED gate threshold: the lowest confidence at which observed precision
 * clears the target (e.g. 0.93) with enough samples. THAT is the honest value to arm the gate at — derived
 * from outcomes, not declared. If no threshold reaches the target yet, it returns null (not enough evidence
 * to arm — the honest answer).
 *
 * Pure statistics over injected samples (the outcome-data source is wired on top), deterministically tested.
 */
final class AtlasLoopConfidenceCalibrator
{
    /**
     * @param  list<array{predicted:float, correct:bool}>  $samples
     * @return array{n:int, brier:float, mean_predicted:float, observed_accuracy:float, overconfidence:float, buckets:list<array{lo:float, hi:float, count:int, mean_predicted:float, observed_accuracy:float}>, recommended_threshold:?float, target_precision:float, well_calibrated:bool, reason:string}
     */
    public function calibrate(array $samples, float $targetPrecision = 0.93, int $minSamplesAtThreshold = 20): array
    {
        $clean = [];
        foreach ($samples as $s) {
            if (! is_array($s) || ! isset($s['predicted'])) {
                continue;
            }
            $clean[] = ['predicted' => max(0.0, min(1.0, (float) $s['predicted'])), 'correct' => (bool) ($s['correct'] ?? false)];
        }
        $n = count($clean);
        $targetPrecision = max(0.0, min(1.0, $targetPrecision));

        if ($n === 0) {
            return [
                'n' => 0, 'brier' => 0.0, 'mean_predicted' => 0.0, 'observed_accuracy' => 0.0, 'overconfidence' => 0.0,
                'buckets' => [], 'recommended_threshold' => null, 'target_precision' => $targetPrecision,
                'well_calibrated' => false, 'reason' => 'no_samples',
            ];
        }

        $brier = 0.0;
        $sumPred = 0.0;
        $correct = 0;
        foreach ($clean as $s) {
            $actual = $s['correct'] ? 1.0 : 0.0;
            $brier += ($s['predicted'] - $actual) ** 2;
            $sumPred += $s['predicted'];
            $correct += $s['correct'] ? 1 : 0;
        }
        $brier = round($brier / $n, 4);
        $meanPredicted = round($sumPred / $n, 4);
        $observedAccuracy = round($correct / $n, 4);
        $overconfidence = round($meanPredicted - $observedAccuracy, 4);

        // RECOMMENDED THRESHOLD: lowest predicted value T s.t. {predicted >= T} has precision >= target with
        // >= minSamples. Sweep the distinct predicted values descending; the deepest T that still clears the
        // bar is the honest arm-point (maximizes recall at the required precision).
        $thresholds = array_values(array_unique(array_map(static fn (array $s): float => $s['predicted'], $clean)));
        rsort($thresholds);
        $recommended = null;
        foreach ($thresholds as $t) {
            $subset = array_values(array_filter($clean, static fn (array $s): bool => $s['predicted'] >= $t - 1e-9));
            $cnt = count($subset);
            if ($cnt < $minSamplesAtThreshold) {
                continue;
            }
            $prec = array_sum(array_map(static fn (array $s): int => $s['correct'] ? 1 : 0, $subset)) / $cnt;
            if ($prec + 1e-9 >= $targetPrecision) {
                $recommended = $t; // keep walking down — deeper T that still clears target is better (more recall)
            }
        }

        return [
            'n' => $n,
            'brier' => $brier,
            'mean_predicted' => $meanPredicted,
            'observed_accuracy' => $observedAccuracy,
            'overconfidence' => $overconfidence,
            'buckets' => $this->reliabilityBuckets($clean),
            'recommended_threshold' => $recommended === null ? null : round($recommended, 4),
            'target_precision' => $targetPrecision,
            'well_calibrated' => abs($overconfidence) <= 0.05 && $brier <= 0.1,
            'reason' => $recommended === null
                ? 'no_threshold_reaches_target_precision (insufficient evidence to arm)'
                : 'arm_at:'.round($recommended, 4),
        ];
    }

    /**
     * @param  list<array{predicted:float, correct:bool}>  $clean
     * @return list<array{lo:float, hi:float, count:int, mean_predicted:float, observed_accuracy:float}>
     */
    private function reliabilityBuckets(array $clean, int $bins = 10): array
    {
        $buckets = [];
        for ($b = 0; $b < $bins; $b++) {
            $lo = $b / $bins;
            $hi = ($b + 1) / $bins;
            $in = array_values(array_filter($clean, static function (array $s) use ($lo, $hi, $b, $bins): bool {
                return $s['predicted'] >= $lo && ($s['predicted'] < $hi || ($b === $bins - 1 && $s['predicted'] <= $hi));
            }));
            if ($in === []) {
                continue;
            }
            $cnt = count($in);
            $buckets[] = [
                'lo' => round($lo, 2),
                'hi' => round($hi, 2),
                'count' => $cnt,
                'mean_predicted' => round(array_sum(array_map(static fn (array $s): float => $s['predicted'], $in)) / $cnt, 4),
                'observed_accuracy' => round(array_sum(array_map(static fn (array $s): int => $s['correct'] ? 1 : 0, $in)) / $cnt, 4),
            ];
        }

        return $buckets;
    }
}
