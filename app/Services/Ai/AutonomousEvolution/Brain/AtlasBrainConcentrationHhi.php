<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * CONCENTRATION HHI — metrics-optimization organ. Herfindahl-Hirschman index over the path
 * distribution of recent reflections. HHI = Σ p_i^2 across observed paths (p_i = share of path i).
 * Range: 1/N (perfectly uniform across N paths) → 1.0 (single-path monopoly). Complements the
 * entropy-based diversity score — same goal (mode-collapse alarm), different math, useful for
 * cross-checking.
 *
 * Pure + deterministic. Pétreo: réu would skip the square step so monopoly never registered.
 */
final class AtlasBrainConcentrationHhi
{
    public const SCHEMA = 'atlas.brain.concentration_hhi.v1';

    private const MIN_SAMPLES = 4;

    /**
     * @param  list<array{action_hint?:string}>  $reflectionTail
     * @return array{schema:string, hhi:float, observed_paths:int, total_samples:int, status:string}
     */
    public function compute(array $reflectionTail, AtlasBrainHintToPathTranslator $translator): array
    {
        $byPath = [];
        $total = 0;
        foreach ($reflectionTail as $r) {
            $hint = (string) ($r['action_hint'] ?? '');
            if ($hint === '') {
                continue;
            }
            $path = $translator->pathFor($hint);
            if ($path === null) {
                continue;
            }
            $byPath[$path] = ($byPath[$path] ?? 0) + 1;
            $total++;
        }

        if ($total < self::MIN_SAMPLES) {
            return ['schema' => self::SCHEMA, 'hhi' => 0.0, 'observed_paths' => count($byPath), 'total_samples' => $total, 'status' => 'insufficient_samples'];
        }

        $hhi = 0.0;
        foreach ($byPath as $count) {
            $share = $count / $total;
            $hhi += $share * $share;
        }
        $hhi = round($hhi, 4);
        $status = $hhi >= 0.50 ? 'concentrated' : ($hhi >= 0.25 ? 'moderate' : 'distributed');

        return ['schema' => self::SCHEMA, 'hhi' => $hhi, 'observed_paths' => count($byPath), 'total_samples' => $total, 'status' => $status];
    }
}
