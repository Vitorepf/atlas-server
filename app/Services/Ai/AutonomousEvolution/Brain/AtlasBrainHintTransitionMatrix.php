<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * HINT TRANSITION MATRIX — markov-style read over the action_hint time series. For every adjacent pair
 * (prev_hint, next_hint) in the reflection stream (oldest-first), increment a counter. The result is a
 * compact view of the cascade's actual DYNAMICS: "after rotate_path, what does the brain hint next?".
 *
 * Why this is a real perception leap (not proxy): the histogram (L69) and analyzer (L75) are static
 * summaries. The transition matrix surfaces COUPLING — if `originate_fresh` always lands on `rotate_path`
 * next, the cascade is oscillating; if `use_drafted_candidate` lands on itself, perseveration. Today the
 * brain has no measurement of how its own decisions chain.
 *
 * Pure + deterministic + read-only. Pétreo: réu never edits the matrix (else it'd flatten transitions
 * to look "balanced" while still oscillating in practice — Goodhart).
 */
final class AtlasBrainHintTransitionMatrix
{
    public const SCHEMA = 'atlas.brain.hint_transition_matrix.v1';

    /**
     * Build the matrix from a list of reflection rows (oldest-first as written by AppendOnlyJsonlStore).
     * Each row is either a leverage_brief reflection (signals.action_hint OR parsed from `reflection`)
     * or another note kind (skipped).
     *
     * @param  list<array<string,mixed>>  $reflectionRows  oldest-first
     * @return array{schema:string, transitions:int, by_pair:list<array{from:string, to:string, count:int}>, self_loop_count:int}
     */
    public function build(array $reflectionRows): array
    {
        $hints = [];
        foreach ($reflectionRows as $row) {
            $hint = trim((string) ($row['signals']['action_hint'] ?? ''));
            if ($hint === '') {
                $text = (string) ($row['reflection'] ?? '');
                if (! str_starts_with($text, 'leverage_brief: ')) {
                    continue;
                }
                $rest = substr($text, strlen('leverage_brief: '));
                $cut = strpos($rest, ' — ');
                $hint = trim($cut === false ? $rest : substr($rest, 0, $cut));
            }
            if ($hint !== '') {
                $hints[] = $hint;
            }
        }

        $counts = [];
        $selfLoops = 0;
        $transitions = 0;
        $n = count($hints);
        for ($i = 0; $i < $n - 1; $i++) {
            $from = $hints[$i];
            $to = $hints[$i + 1];
            $key = $from.'>'.$to;
            $counts[$key] ??= ['from' => $from, 'to' => $to, 'count' => 0];
            $counts[$key]['count']++;
            $transitions++;
            if ($from === $to) {
                $selfLoops++;
            }
        }

        $pairs = array_values($counts);
        usort($pairs, static fn (array $a, array $b): int => [$b['count'], $a['from'], $a['to']] <=> [$a['count'], $b['from'], $b['to']]);

        return [
            'schema' => self::SCHEMA,
            'transitions' => $transitions,
            'by_pair' => $pairs,
            'self_loop_count' => $selfLoops,
        ];
    }
}
