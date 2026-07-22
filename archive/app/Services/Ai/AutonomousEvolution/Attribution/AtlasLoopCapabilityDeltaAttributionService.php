<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Attribution;

/**
 * CAPABILITY-Δ ATTRIBUTION — the recursive spark: attribute MEASURED behavior-Δ back to the origination SHAPE
 * that caused it, so the loop can learn which decision shapes actually move capability (not which ones the
 * model THINKS are good).
 *
 * FACT-only + deterministic. A shape is CREDITED only when it has earned it from REAL samples: samples >=
 * minSamples (default 5) AND a Wilson lower-bound (95%) on its positive-Δ rate strictly above 0. A new/thin
 * shape NEVER gets spurious credit OR blame — the Wilson floor is exactly the anti-small-sample guard. Nothing
 * here is an LLM score and nothing is self-set: `credited`, `capability_delta` and `wilson_lower_bound` are
 * COMPUTED from the deliveries' measured net_behavior_delta — a caller cannot hand itself a grade.
 *
 * CAUSAL HONESTY: `credited` alone answers "did this shape clear the anti-noise floor", not "did this shape
 * cause more capability than everything else". `counterfactual_baseline` is the positive-Δ rate of every
 * OTHER shape's deliveries; when a credited shape's Wilson lower bound does not clear that baseline,
 * `false_causality_warning` is set — the shape is statistically indistinguishable from the background rate,
 * so treating it as the cause of the gain would be a correlation-as-causation error. `confidence` is a
 * deterministic sample-size band (low/medium/high), never an LLM score.
 */
final class AtlasLoopCapabilityDeltaAttributionService
{
    public const SCHEMA = 'atlas.loop.capability_delta_attribution.v1';

    /** 95% Wilson z-score. */
    private const Z = 1.959963984540054;

    public function __construct(private readonly int $minSamples = 5) {}

    /**
     * @param  list<array{shape_token?:string, originator_id?:string, net_behavior_delta?:int|float, objective_class?:string}>  $deliveries
     * @return array{schema:string, by_shape:list<array{shape_token:string, samples:int, sample_count:int, mean_delta:float, capability_delta:float, wilson_lower_bound:float, credited:bool, confidence:string, counterfactual_baseline:float, false_causality_warning:bool, rationale:string}>}
     */
    public function attribute(array $deliveries): array
    {
        /** @var array<string,list<float>> $byShape */
        $byShape = [];
        foreach ($deliveries as $delivery) {
            if (! is_array($delivery)) {
                continue;
            }
            $shape = trim((string) ($delivery['shape_token'] ?? ''));
            if ($shape === '') {
                continue;
            }
            // ONLY the measured delta is read — never a caller-supplied credited/grade field.
            $byShape[$shape][] = (float) ($delivery['net_behavior_delta'] ?? 0);
        }

        // Population totals, used below as each shape's COUNTERFACTUAL baseline (the positive-Δ rate of
        // every OTHER shape) — comparing a shape against itself would bias the baseline toward its own result.
        $totalSamples = 0;
        $totalPositives = 0;
        foreach ($byShape as $deltas) {
            $totalSamples += count($deltas);
            $totalPositives += count(array_filter($deltas, static fn (float $d): bool => $d > 0.0));
        }

        $rows = [];
        foreach ($byShape as $shape => $deltas) {
            $samples = count($deltas);
            $positives = count(array_filter($deltas, static fn (float $d): bool => $d > 0.0));
            $mean = round(array_sum($deltas) / max(1, $samples), 3);
            $wilson = $this->wilsonLowerBound($positives, $samples);
            $credited = $samples >= $this->minSamples && $wilson > 0.0;

            $otherSamples = $totalSamples - $samples;
            $otherPositives = $totalPositives - $positives;
            $counterfactualBaseline = $otherSamples > 0 ? round($otherPositives / $otherSamples, 4) : 0.0;

            // A shape that clears the "credited" bar but does not beat what every OTHER shape achieves is
            // not causally distinguishable from the background rate — flag it instead of asserting causation.
            $falseCausalityWarning = $credited && $wilson <= $counterfactualBaseline;

            $rows[] = [
                'shape_token' => $shape,
                'samples' => $samples,
                'sample_count' => $samples,
                'mean_delta' => $mean,
                'capability_delta' => $mean,
                'wilson_lower_bound' => $wilson,
                'credited' => $credited,
                'confidence' => $this->confidenceBand($samples),
                'counterfactual_baseline' => $counterfactualBaseline,
                'false_causality_warning' => $falseCausalityWarning,
                'rationale' => $this->rationale($credited, $falseCausalityWarning, $samples, $wilson, $counterfactualBaseline),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['shape_token'], $b['shape_token']));

        return ['schema' => self::SCHEMA, 'by_shape' => $rows];
    }

    /** Deterministic sample-size confidence band — never an LLM/opaque score. */
    private function confidenceBand(int $samples): string
    {
        if ($samples < $this->minSamples) {
            return 'low';
        }

        return $samples >= $this->minSamples * 2 ? 'high' : 'medium';
    }

    /** Deterministic, computed-only explanation of the credited/confidence/false-causality verdict. */
    private function rationale(bool $credited, bool $falseCausalityWarning, int $samples, float $wilson, float $counterfactualBaseline): string
    {
        if (! $credited) {
            return $samples < $this->minSamples
                ? sprintf('insufficient samples (n=%d < min %d)', $samples, $this->minSamples)
                : sprintf('no positive-delta evidence above zero (wilson lower bound %.4f)', $wilson);
        }

        if ($falseCausalityWarning) {
            return sprintf('credited but wilson lower bound %.4f does not clear counterfactual baseline %.4f — not distinguishable from the background rate', $wilson, $counterfactualBaseline);
        }

        return sprintf('credited: wilson lower bound %.4f exceeds counterfactual baseline %.4f over n=%d samples', $wilson, $counterfactualBaseline, $samples);
    }

    /**
     * Wilson score interval lower bound for a binomial proportion (positive-Δ rate). 0 when there are no
     * positive samples; clamped to [0,1]. Deterministic.
     */
    private function wilsonLowerBound(int $positives, int $samples): float
    {
        if ($samples <= 0 || $positives <= 0) {
            return 0.0;
        }

        $n = (float) $samples;
        $phat = $positives / $n;
        $z = self::Z;
        $z2 = $z * $z;

        $denominator = 1.0 + $z2 / $n;
        $centre = $phat + $z2 / (2.0 * $n);
        $margin = $z * sqrt(($phat * (1.0 - $phat) + $z2 / (4.0 * $n)) / $n);
        $lower = ($centre - $margin) / $denominator;

        return round(max(0.0, min(1.0, $lower)), 4);
    }
}
