<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH CADENCE DETECTOR — pattern-design organ. For each path, computes the average gap (in
 * cycles) between consecutive picks of that path. Reveals rhythmic cadences ("compounding every
 * ~3 cycles"). Distinct from starvation (which only tracks the latest gap) and oscillation
 * (alternation). Returns per-path avg_gap (float) + pick_count.
 *
 * Pure scan over chronological tail. No IO. Pétreo: réu would shrink reported gaps so its path
 * looked overdue.
 */
final class AtlasBrainPathCadenceDetector
{
    public const SCHEMA = 'atlas.brain.path_cadence_detector.v1';

    /**
     * @param  list<array{action_hint?:string}>  $reflectionTail
     * @return array{schema:string, by_path:array<string, array{avg_gap:float, pick_count:int}>}
     */
    public function detect(array $reflectionTail, AtlasBrainHintToPathTranslator $translator): array
    {
        $picks = [];  // path => list<int index>
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
            if ($n < 2) {
                $out[$path] = ['avg_gap' => 0.0, 'pick_count' => $n];

                continue;
            }
            $sum = 0;
            for ($j = 1; $j < $n; $j++) {
                $sum += $indices[$j] - $indices[$j - 1];
            }
            $out[$path] = ['avg_gap' => round($sum / ($n - 1), 4), 'pick_count' => $n];
        }

        return ['schema' => self::SCHEMA, 'by_path' => $out];
    }
}
