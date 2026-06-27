<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * TAIL ALTERNATE HISTORY — simulation-twin organ. Returns a copy of the tail with the K-th
 * reflection's result_kind swapped to a counterfactual value (e.g., "what if that one had been
 * accepted instead of refused?"). Input not mutated. Lets downstream organs reread metrics over
 * the alternate history without touching the real stream.
 *
 * Pure mutation. No IO. Pétreo: réu would silently mutate the source tail.
 */
final class AtlasBrainTailAlternateHistory
{
    public const SCHEMA = 'atlas.brain.tail_alternate_history.v1';

    /**
     * @param  list<array<string,mixed>>  $tail
     * @return list<array<string,mixed>>
     */
    public function rewrite(array $tail, int $index, string $newResultKind): array
    {
        if ($index < 0 || $index >= count($tail) || $newResultKind === '') {
            return $tail;
        }
        $out = $tail;
        $row = $out[$index];
        $row['result_kind'] = $newResultKind;
        $row['counterfactual'] = true;
        $out[$index] = $row;

        return $out;
    }
}
