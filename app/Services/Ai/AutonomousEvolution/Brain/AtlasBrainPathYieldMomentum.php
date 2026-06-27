<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH YIELD MOMENTUM — for each portfolio path, compares accepted/total yield in the EARLIER half
 * of recent reflections vs the LATER half. Produces a trend label (improving/declining/flat) and
 * delta per path. Compounding signal: paths whose yield is improving deserve more rotation share;
 * paths in decay deserve a probe or rotation pause. Pure decision over reflection stream — no IO.
 *
 * Distinct from cascade analyzer (which gives static rollup) and trend analyzer (which tracks the
 * starvation_pct global signal). This is per-path yield-over-time, the compounding lens.
 */
final class AtlasBrainPathYieldMomentum
{
    public const SCHEMA = 'atlas.brain.path_yield_momentum.v1';

    private const MIN_SAMPLES_PER_HALF = 3;

    private const FLAT_DELTA = 0.05;

    /**
     * @param  list<array{action_hint?:string, result_kind?:string}>  $reflectionTail
     * @return array{schema:string, by_path:array<string, array{earlier_yield:float, later_yield:float, delta:float, trend:string, earlier_samples:int, later_samples:int}>}
     */
    public function compute(array $reflectionTail, AtlasBrainHintToPathTranslator $translator): array
    {
        $count = count($reflectionTail);
        if ($count < (self::MIN_SAMPLES_PER_HALF * 2)) {
            return ['schema' => self::SCHEMA, 'by_path' => []];
        }

        $midpoint = intdiv($count, 2);
        $earlier = array_slice($reflectionTail, 0, $midpoint);
        $later = array_slice($reflectionTail, $midpoint);

        $earlierByPath = $this->yieldByPath($earlier, $translator);
        $laterByPath = $this->yieldByPath($later, $translator);

        $paths = array_unique(array_merge(array_keys($earlierByPath), array_keys($laterByPath)));
        $out = [];
        foreach ($paths as $path) {
            $e = $earlierByPath[$path] ?? ['accepted' => 0, 'total' => 0];
            $l = $laterByPath[$path] ?? ['accepted' => 0, 'total' => 0];
            if ($e['total'] < self::MIN_SAMPLES_PER_HALF || $l['total'] < self::MIN_SAMPLES_PER_HALF) {
                continue;
            }
            $ey = $e['accepted'] / $e['total'];
            $ly = $l['accepted'] / $l['total'];
            $delta = $ly - $ey;
            $trend = abs($delta) < self::FLAT_DELTA ? 'flat' : ($delta > 0 ? 'improving' : 'declining');
            $out[$path] = [
                'earlier_yield' => round($ey, 4),
                'later_yield' => round($ly, 4),
                'delta' => round($delta, 4),
                'trend' => $trend,
                'earlier_samples' => $e['total'],
                'later_samples' => $l['total'],
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
