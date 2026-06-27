<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH STARVATION DETECTOR — for each canonical portfolio path, returns whether any hint mapped to it
 * has fired in the recent reflection tail. Different from concentration (which is about dominance):
 * starvation is "this path hasn't been picked AT ALL recently" — the rotation is leaving paths on
 * the bench.
 *
 * Pure + deterministic + read-only over the L121 translator + brief histogram. Pétreo.
 */
final class AtlasBrainPathStarvationDetector
{
    public const SCHEMA = 'atlas.brain.path_starvation_detector.v1';

    public const CANONICAL_PATHS = [
        'frontier-harvest', 'metrics-optimization', 'pattern-design', 'simulation-twin',
        'comprehension-deepening', 'adversarial-critique', 'compounding',
    ];

    /**
     * @param  array{by_hint?:list<array{hint:string, count:int}>}  $briefHistogram
     * @return array{schema:string, hit:list<string>, starved:list<string>, ambiguous_hints:int}
     */
    public function detect(array $briefHistogram, AtlasBrainHintToPathTranslator $translator): array
    {
        $rows = (array) ($briefHistogram['by_hint'] ?? []);
        $hit = [];
        $ambiguous = 0;
        foreach ($rows as $r) {
            $h = (string) ($r['hint'] ?? '');
            $count = (int) ($r['count'] ?? 0);
            if ($h === '' || $count <= 0) {
                continue;
            }
            $p = $translator->pathFor($h);
            if ($p === null) {
                $ambiguous++;

                continue;
            }
            $hit[$p] = true;
        }

        $hitList = array_values(array_filter(self::CANONICAL_PATHS, static fn (string $p): bool => isset($hit[$p])));
        $starved = array_values(array_diff(self::CANONICAL_PATHS, $hitList));

        return [
            'schema' => self::SCHEMA,
            'hit' => $hitList,
            'starved' => $starved,
            'ambiguous_hints' => $ambiguous,
        ];
    }
}
