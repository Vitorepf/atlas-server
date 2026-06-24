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
 * here is an LLM score and nothing is self-set: `credited`, `mean_delta` and `wilson_lower_bound` are COMPUTED
 * from the deliveries' measured net_behavior_delta — a caller cannot hand itself a grade. NEW class only — the
 * operator later feeds the prior into the pétreo AtlasLoopOriginationProducer / AtlasLoopAmbitionDecider.
 */
final class AtlasLoopCapabilityDeltaAttributionService
{
    public const SCHEMA = 'atlas.loop.capability_delta_attribution.v1';

    /** 95% Wilson z-score. */
    private const Z = 1.959963984540054;

    public function __construct(private readonly int $minSamples = 5) {}

    /**
     * @param  list<array{shape_token?:string, originator_id?:string, net_behavior_delta?:int|float, objective_class?:string}>  $deliveries
     * @return array{schema:string, by_shape:list<array{shape_token:string, samples:int, mean_delta:float, wilson_lower_bound:float, credited:bool}>}
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

        $rows = [];
        foreach ($byShape as $shape => $deltas) {
            $samples = count($deltas);
            $positives = count(array_filter($deltas, static fn (float $d): bool => $d > 0.0));
            $mean = round(array_sum($deltas) / max(1, $samples), 3);
            $wilson = $this->wilsonLowerBound($positives, $samples);

            $rows[] = [
                'shape_token' => $shape,
                'samples' => $samples,
                'mean_delta' => $mean,
                'wilson_lower_bound' => $wilson,
                'credited' => $samples >= $this->minSamples && $wilson > 0.0,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['shape_token'], $b['shape_token']));

        return ['schema' => self::SCHEMA, 'by_shape' => $rows];
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
