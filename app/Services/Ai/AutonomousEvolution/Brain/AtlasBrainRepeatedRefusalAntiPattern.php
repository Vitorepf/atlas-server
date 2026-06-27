<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * REPEATED REFUSAL ANTI-PATTERN — pattern-design organ. Scans a reflection tail and surfaces
 * hints that have been refused N+ times with ZERO accepted occurrences. Signals the
 * "repeated-failure with no learning" shape: the brain keeps trying the same approach without
 * ever succeeding once. Distinct from frequency drift or starvation — those measure deltas; this
 * surfaces zero-success persistence.
 *
 * Pure aggregation. No IO. Pétreo: réu would lower N so its preferred hint never appears.
 */
final class AtlasBrainRepeatedRefusalAntiPattern
{
    public const SCHEMA = 'atlas.brain.repeated_refusal_anti_pattern.v1';

    public const DEFAULT_MIN_REFUSALS = 3;

    /**
     * @param  list<array{action_hint?:string, result_kind?:string}>  $reflectionTail
     * @return array{schema:string, anti_patterns:list<array{hint:string, refused:int}>}
     */
    public function detect(array $reflectionTail, int $minRefusals = self::DEFAULT_MIN_REFUSALS): array
    {
        $stats = [];  // hint => [refused, accepted]
        foreach ($reflectionTail as $r) {
            $hint = (string) ($r['action_hint'] ?? '');
            $kind = (string) ($r['result_kind'] ?? '');
            if ($hint === '' || $kind === '') {
                continue;
            }
            if (! isset($stats[$hint])) {
                $stats[$hint] = ['refused' => 0, 'accepted' => 0];
            }
            if ($kind === 'accepted') {
                $stats[$hint]['accepted']++;
            } else {
                $stats[$hint]['refused']++;
            }
        }

        $anti = [];
        foreach ($stats as $hint => $s) {
            if ($s['accepted'] === 0 && $s['refused'] >= $minRefusals) {
                $anti[] = ['hint' => (string) $hint, 'refused' => $s['refused']];
            }
        }
        usort($anti, static fn (array $a, array $b) => $b['refused'] <=> $a['refused']);

        return ['schema' => self::SCHEMA, 'anti_patterns' => $anti];
    }
}
