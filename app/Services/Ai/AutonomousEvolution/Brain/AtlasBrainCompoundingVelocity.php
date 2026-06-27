<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * COMPOUNDING VELOCITY — second-order signal per path. Splits the recent reflection tail into
 * three equal thirds (early / mid / late) and per path computes:
 *   delta_early_mid = mid_yield - early_yield
 *   delta_mid_late  = late_yield - mid_yield
 *   acceleration    = delta_mid_late - delta_early_mid
 *
 * Positive acceleration = path is COMPOUNDING (rate of improvement increasing). Negative = path
 * is decelerating (improvement slowing). Distinct from momentum (first derivative) and starvation
 * (level). No IO, pure decision. Pétreo.
 */
final class AtlasBrainCompoundingVelocity
{
    public const SCHEMA = 'atlas.brain.compounding_velocity.v1';

    private const MIN_SAMPLES_PER_THIRD = 3;

    private const STEADY_DELTA = 0.05;

    /**
     * @param  list<array{action_hint?:string, result_kind?:string}>  $reflectionTail
     * @return array{schema:string, by_path:array<string, array{early_yield:float, mid_yield:float, late_yield:float, acceleration:float, label:string}>}
     */
    public function compute(array $reflectionTail, AtlasBrainHintToPathTranslator $translator): array
    {
        $n = count($reflectionTail);
        if ($n < self::MIN_SAMPLES_PER_THIRD * 3) {
            return ['schema' => self::SCHEMA, 'by_path' => []];
        }

        $third = intdiv($n, 3);
        $early = array_slice($reflectionTail, 0, $third);
        $mid = array_slice($reflectionTail, $third, $third);
        $late = array_slice($reflectionTail, $third * 2);

        $eByPath = $this->yieldByPath($early, $translator);
        $mByPath = $this->yieldByPath($mid, $translator);
        $lByPath = $this->yieldByPath($late, $translator);

        $paths = array_unique(array_merge(array_keys($eByPath), array_keys($mByPath), array_keys($lByPath)));
        $out = [];
        foreach ($paths as $path) {
            $e = $eByPath[$path] ?? null;
            $m = $mByPath[$path] ?? null;
            $l = $lByPath[$path] ?? null;
            if ($e === null || $m === null || $l === null) {
                continue;
            }
            if ($e['total'] < 1 || $m['total'] < 1 || $l['total'] < 1) {
                continue;
            }
            $ey = $e['accepted'] / $e['total'];
            $my = $m['accepted'] / $m['total'];
            $ly = $l['accepted'] / $l['total'];
            $accel = ($ly - $my) - ($my - $ey);
            $label = abs($accel) < self::STEADY_DELTA ? 'steady' : ($accel > 0 ? 'accelerating' : 'decelerating');
            $out[$path] = [
                'early_yield' => round($ey, 4),
                'mid_yield' => round($my, 4),
                'late_yield' => round($ly, 4),
                'acceleration' => round($accel, 4),
                'label' => $label,
            ];
        }

        return ['schema' => self::SCHEMA, 'by_path' => $out];
    }

    /**
     * @param  list<array{action_hint?:string, result_kind?:string}>  $reflections
     * @return array<string, array{accepted:int, total:int}>
     */
    private function yieldByPath(array $reflections, AtlasBrainHintToPathTranslator $translator): array
    {
        $by = [];
        foreach ($reflections as $r) {
            $hint = (string) ($r['action_hint'] ?? '');
            $kind = (string) ($r['result_kind'] ?? '');
            if ($hint === '' || $kind === '') {
                continue;
            }
            $path = $translator->pathFor($hint);
            if ($path === null) {
                continue;
            }
            if (! isset($by[$path])) {
                $by[$path] = ['accepted' => 0, 'total' => 0];
            }
            $by[$path]['total']++;
            if ($kind === 'accepted') {
                $by[$path]['accepted']++;
            }
        }

        return $by;
    }
}
