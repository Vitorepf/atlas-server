<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * GATE FALSE-POSITIVE ESTIMATOR — frontier-harvest organ. Given a sample of recently refused
 * gate verdicts each tagged with a downstream hindsight verdict (was the rejection actually right?),
 * estimates the gate's false-refusal rate (refused-but-real / total-refused). High FP rate means
 * the gate is over-rejecting, starving the queue.
 *
 * Pure decision over already-built sample. No IO. Pétreo: réu would clamp the estimate so its
 * own gate's FP rate never crossed a threshold.
 */
final class AtlasBrainGateFalsePositiveEstimator
{
    public const SCHEMA = 'atlas.brain.gate_false_positive_estimator.v1';

    private const MIN_SAMPLE = 5;

    /**
     * @param  list<array{refused_by_gate:bool, was_real_in_hindsight:bool}>  $sample
     * @return array{schema:string, total_refused:int, false_refusals:int, fp_rate:float, status:string}
     */
    public function estimate(array $sample): array
    {
        $totalRefused = 0;
        $falseRefusals = 0;
        foreach ($sample as $row) {
            if (! (bool) ($row['refused_by_gate'] ?? false)) {
                continue;
            }
            $totalRefused++;
            if ((bool) ($row['was_real_in_hindsight'] ?? false)) {
                $falseRefusals++;
            }
        }

        if ($totalRefused < self::MIN_SAMPLE) {
            return ['schema' => self::SCHEMA, 'total_refused' => $totalRefused, 'false_refusals' => $falseRefusals, 'fp_rate' => 0.0, 'status' => 'insufficient_sample'];
        }

        $rate = round($falseRefusals / $totalRefused, 4);
        $status = $rate >= 0.20 ? 'overrefusing' : ($rate >= 0.05 ? 'borderline' : 'healthy');

        return ['schema' => self::SCHEMA, 'total_refused' => $totalRefused, 'false_refusals' => $falseRefusals, 'fp_rate' => $rate, 'status' => $status];
    }
}
