<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * HYPOTHETICAL TAIL APPENDER — simulation-twin lens. Pure builder that takes a real reflection
 * tail and appends N synthetic reflections matching a hint+result_kind specification, returning
 * the mutated tail. Enables what-if probes: feed the mutated tail to momentum, diversity, or
 * oscillation organs and read what those signals WOULD say if the hypothetical pattern held.
 *
 * Pure + deterministic, no IO, no state. Pétreo: réu would mutate the SOURCE tail in place to
 * corrupt downstream readings.
 */
final class AtlasBrainHypotheticalTailAppender
{
    public const SCHEMA = 'atlas.brain.hypothetical_tail_appender.v1';

    /**
     * @param  list<array{action_hint?:string, result_kind?:string}>  $realTail
     * @param  list<array{hint:string, kind:string, count:int}>  $appends
     * @return list<array{action_hint:string, result_kind:string, synthetic?:bool}>
     */
    public function build(array $realTail, array $appends): array
    {
        $out = $realTail;
        foreach ($appends as $spec) {
            $hint = (string) ($spec['hint'] ?? '');
            $kind = (string) ($spec['kind'] ?? '');
            $count = max(0, (int) ($spec['count'] ?? 0));
            if ($hint === '' || $kind === '') {
                continue;
            }
            for ($i = 0; $i < $count; $i++) {
                $out[] = ['action_hint' => $hint, 'result_kind' => $kind, 'synthetic' => true];
            }
        }

        return $out;
    }
}
