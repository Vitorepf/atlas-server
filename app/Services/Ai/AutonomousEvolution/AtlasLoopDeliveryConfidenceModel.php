<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Lever 2 — CALIBRATED delivery confidence (the ">93% confidence everything is correct" enabler).
 *
 * Today the loop's confidence numbers are hardcoded theatre (0.7, 75, ...). This replaces them with a
 * PRINCIPLED P(correct) computed from the cert's measurable, ungameable signals — a logistic over weighted
 * evidence — and a gate that refuses a one-shot delivery below a threshold (default 0.93).
 *
 * HARD ZERO: behaviour not preserved => 0.0 (a broken delivery is never confident, whatever else holds).
 *
 * CALIBRATABLE BY CONSTRUCTION: the weights + bias + threshold are config, not magic constants, so the
 * self-calibration loop can FIT them against ground truth (certs whose real correctness was later proven —
 * e.g. the broader-regression-gate / post-merge outcome). Until enough outcomes accrue, the shipped weights
 * are conservative and the number is at least HONEST evidence-arithmetic instead of a fabricated constant.
 */
final class AtlasLoopDeliveryConfidenceModel
{
    public const DEFAULT_THRESHOLD = 0.93;

    /**
     * @param  array<string,mixed>  $signals  measurable cert signals:
     *                                        behavior_preserved:bool (HARD), diff_earned:bool, sealed_holdout_passed:bool,
     *                                        complexity_reduced:bool, cross_file_consumers_ok:bool, mutation_kill_ratio:float (0..1),
     *                                        quality_score:float (0..10), adversarial_refuted_count:int
     * @return array{confidence:float, passes:bool, threshold:float, contributions:array<string,float>, reasons:list<string>}
     */
    public function estimate(array $signals, ?float $threshold = null): array
    {
        $threshold = $threshold ?? (float) config('atlas.loop.confidence_model.threshold', self::DEFAULT_THRESHOLD);
        $threshold = max(0.0, min(1.0, $threshold));
        $w = $this->weights();

        // HARD GATE: a behaviour-broken delivery is never confident.
        if (! (bool) ($signals['behavior_preserved'] ?? false)) {
            return [
                'confidence' => 0.0,
                'passes' => false,
                'threshold' => $threshold,
                'contributions' => ['behavior_preserved' => 0.0],
                'reasons' => ['behavior_not_preserved'],
            ];
        }

        $refuted = max(0, (int) ($signals['adversarial_refuted_count'] ?? 0));
        $killRatio = max(0.0, min(1.0, (float) ($signals['mutation_kill_ratio'] ?? 0.0)));
        $quality = max(0.0, min(10.0, (float) ($signals['quality_score'] ?? 0.0)));

        $terms = [
            'bias' => (float) $w['bias'],
            'diff_earned' => (bool) ($signals['diff_earned'] ?? false) ? (float) $w['diff_earned'] : 0.0,
            'sealed_holdout' => (bool) ($signals['sealed_holdout_passed'] ?? false) ? (float) $w['sealed_holdout'] : 0.0,
            'complexity_reduced' => (bool) ($signals['complexity_reduced'] ?? false) ? (float) $w['complexity_reduced'] : 0.0,
            'cross_file_consumers' => (bool) ($signals['cross_file_consumers_ok'] ?? false) ? (float) $w['cross_file_consumers'] : 0.0,
            'mutation_kill_ratio' => $killRatio * (float) $w['mutation_kill_ratio'],
            'quality_score' => ($quality / 10.0) * (float) $w['quality_score'],
            'adversarial_refuted' => $refuted * (float) $w['adversarial_refuted_penalty'],
        ];

        $z = array_sum($terms);
        $confidence = round($this->sigmoid($z), 4);
        $reasons = [];
        if ($refuted > 0) {
            $reasons[] = 'adversarial_refuted:'.$refuted;
        }
        if ($confidence + 1e-9 < $threshold) {
            $reasons[] = 'confidence_below_threshold:'.$confidence.'<'.$threshold;
        }

        return [
            'confidence' => $confidence,
            'passes' => $confidence + 1e-9 >= $threshold,
            'threshold' => $threshold,
            'contributions' => $terms,
            'reasons' => $reasons,
        ];
    }

    /**
     * @return array<string,float>
     */
    private function weights(): array
    {
        $cfg = (array) config('atlas.loop.confidence_model.weights', []);

        // Conservative, monotonic defaults: every positive gate raises confidence, a refutation sharply
        // lowers it. Tuned so a fully-green cert (all gates + kill_ratio 1 + quality 10, 0 refutations)
        // clears 0.93 and a half-evidenced one does not. The self-calibration loop refines these.
        return [
            'bias' => (float) ($cfg['bias'] ?? -2.2),
            'diff_earned' => (float) ($cfg['diff_earned'] ?? 1.3),
            'sealed_holdout' => (float) ($cfg['sealed_holdout'] ?? 1.0),
            'complexity_reduced' => (float) ($cfg['complexity_reduced'] ?? 0.6),
            'cross_file_consumers' => (float) ($cfg['cross_file_consumers'] ?? 1.2),
            'mutation_kill_ratio' => (float) ($cfg['mutation_kill_ratio'] ?? 2.4),
            'quality_score' => (float) ($cfg['quality_score'] ?? 1.6),
            'adversarial_refuted_penalty' => (float) ($cfg['adversarial_refuted_penalty'] ?? -3.0),
        ];
    }

    private function sigmoid(float $z): float
    {
        if ($z >= 0.0) {
            return 1.0 / (1.0 + exp(-$z));
        }
        $e = exp($z);

        return $e / (1.0 + $e);
    }
}
