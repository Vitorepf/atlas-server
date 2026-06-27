<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * NEXT-PATH SUGGESTER — picks one portfolio path for the next cycle by combining starvation +
 * outcome rollup. Priority (explicit + auditable):
 *   1. STARVED path (any canonical path that hasn't fired recently) → break the silence.
 *   2. WINNING path (any path with served_rate ≥70% and total ≥3) → reinforce.
 *   3. FALLBACK: first canonical path alphabetically (deterministic).
 *
 * The cascade router stays in charge (this is an OPERATOR-facing suggestion, not auto-routed). Pétreo:
 * réu cannot reorder the priority to favor its current path.
 */
final class AtlasBrainNextPathSuggester
{
    public const SCHEMA = 'atlas.brain.next_path_suggester.v1';

    /**
     * @param  list<string>  $starvedPaths
     * @param  list<array{path:string, served_rate_pct:int, total:int}>  $pathRollup
     * @return array{schema:string, suggested:string, reason:string}
     */
    public function suggest(array $starvedPaths, array $pathRollup): array
    {
        if ($starvedPaths !== []) {
            // alphabetical pick to keep deterministic when multiple are starved.
            sort($starvedPaths);

            return ['schema' => self::SCHEMA, 'suggested' => $starvedPaths[0], 'reason' => 'starved_path_unblock'];
        }
        foreach ($pathRollup as $row) {
            if ((int) ($row['total'] ?? 0) >= 3 && (int) ($row['served_rate_pct'] ?? 0) >= 70) {
                return ['schema' => self::SCHEMA, 'suggested' => (string) $row['path'], 'reason' => 'winning_path_reinforce'];
            }
        }

        return ['schema' => self::SCHEMA, 'suggested' => 'adversarial-critique', 'reason' => 'fallback_canonical'];
    }
}
