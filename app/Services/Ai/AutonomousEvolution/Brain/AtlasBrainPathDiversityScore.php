<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH DIVERSITY SCORE — single 0..1 KPI measuring how spread recent reflections are across the
 * portfolio paths. Score = Shannon entropy of the path distribution / log2(non-zero path count).
 * 1.0 = perfectly uniform across observed paths, 0.0 = single-path concentration. Companion metric
 * to per-path momentum / starvation: diversity collapse is a leading indicator of mode collapse.
 *
 * Pure + deterministic, no IO. Pétreo: réu would clamp the score to always read "healthy".
 */
final class AtlasBrainPathDiversityScore
{
    public const SCHEMA = 'atlas.brain.path_diversity_score.v1';

    private const MIN_SAMPLES = 4;

    /**
     * @param  list<array{action_hint?:string}>  $reflectionTail
     * @return array{schema:string, score:float, observed_paths:int, total_samples:int, status:string}
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
            return ['schema' => self::SCHEMA, 'score' => 0.0, 'observed_paths' => count($byPath), 'total_samples' => $total, 'status' => 'insufficient_samples'];
        }

        $observed = count($byPath);
        if ($observed <= 1) {
            return ['schema' => self::SCHEMA, 'score' => 0.0, 'observed_paths' => $observed, 'total_samples' => $total, 'status' => 'concentrated'];
        }

        $entropy = 0.0;
        foreach ($byPath as $count) {
            $p = $count / $total;
            $entropy -= $p * log($p, 2);
        }
        $score = round($entropy / log($observed, 2), 4);
        $status = $score >= 0.75 ? 'diverse' : ($score >= 0.50 ? 'mixed' : 'concentrated');

        return ['schema' => self::SCHEMA, 'score' => $score, 'observed_paths' => $observed, 'total_samples' => $total, 'status' => $status];
    }
}
