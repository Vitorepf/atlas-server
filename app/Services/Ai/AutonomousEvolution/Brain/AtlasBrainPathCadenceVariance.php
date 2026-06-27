<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH CADENCE VARIANCE — pattern-design organ. Per path, computes the variance of inter-pick
 * gaps. Low variance = regular cadence (path is consistently rotated); high variance = erratic
 * (bursts then long silences). Distinct from PathCadenceDetector (mean) — same data, second
 * moment.
 *
 * Pure scan. No IO. Pétreo: réu would zero-out variance on its preferred path so erratic
 * behavior went unnoticed.
 */
final class AtlasBrainPathCadenceVariance
{
    public const SCHEMA = 'atlas.brain.path_cadence_variance.v1';

    /**
     * @param  list<array{action_hint?:string}>  $reflectionTail
     * @return array{schema:string, by_path:array<string, array{variance:float, gaps:int}>}
     */
    public function compute(array $reflectionTail, AtlasBrainHintToPathTranslator $translator): array
    {
        $picks = [];
        foreach ($reflectionTail as $i => $r) {
            $hint = (string) ($r['action_hint'] ?? '');
            if ($hint === '') {
                continue;
            }
            $path = $translator->pathFor($hint);
            if ($path === null) {
                continue;
            }
            if (! isset($picks[$path])) {
                $picks[$path] = [];
            }
            $picks[$path][] = $i;
        }

        $out = [];
        foreach ($picks as $path => $indices) {
            $n = count($indices);
            if ($n < 3) {
                $out[$path] = ['variance' => 0.0, 'gaps' => max(0, $n - 1)];

                continue;
            }
            $gaps = [];
            for ($j = 1; $j < $n; $j++) {
                $gaps[] = $indices[$j] - $indices[$j - 1];
            }
            $g = count($gaps);
            $mean = array_sum($gaps) / $g;
            $sumSq = 0.0;
            foreach ($gaps as $gap) {
                $sumSq += ($gap - $mean) ** 2;
            }
            $out[$path] = ['variance' => round($sumSq / $g, 4), 'gaps' => $g];
        }

        return ['schema' => self::SCHEMA, 'by_path' => $out];
    }
}
