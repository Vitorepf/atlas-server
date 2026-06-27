<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * HINT→PATH TRANSLATOR — maps action_hint strings to portfolio path ids. Lets the cascade outcome
 * analyzer + summary surfaces attribute per-hint metrics to paths (e.g. "frontier-harvest serves
 * 80%"). Pure const-table mapping; pétreo (réu would re-map to credit favored paths).
 */
final class AtlasBrainHintToPathTranslator
{
    public const SCHEMA = 'atlas.brain.hint_to_path_translator.v1';

    public const HINT_TO_PATH = [
        'rotate_path' => 'comprehension-deepening',
        'harvest_frontier' => 'frontier-harvest',
        'use_drafted_candidate' => 'pattern-design',
        'originate_fresh' => 'comprehension-deepening',
        'escalate_perseveration' => 'metrics-optimization',
        'compound' => 'compounding',
        'use_routed_path' => 'simulation-twin',
        'gate_regression' => 'adversarial-critique',
    ];

    public function pathFor(string $hint): ?string
    {
        $hint = trim($hint);

        return self::HINT_TO_PATH[$hint] ?? null;
    }

    /**
     * @param  list<array{hint:string, count?:int, served?:int, refused?:int, served_rate_pct?:int, total?:int}>  $byHint
     * @return list<array{path:string, hint:string, count?:int, served?:int, refused?:int, served_rate_pct?:int, total?:int}>
     */
    public function attribute(array $byHint): array
    {
        $out = [];
        foreach ($byHint as $row) {
            $path = self::HINT_TO_PATH[(string) ($row['hint'] ?? '')] ?? null;
            if ($path === null) {
                continue;
            }
            $out[] = ['path' => $path] + $row;
        }

        return $out;
    }
}
