<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * BRIEF HISTOGRAM — frequency distribution of action_hints across a prior-briefs window. Lets the brain
 * see its own recent behavior as a distribution ('I've used use_drafted_candidate 60% of the time, but
 * never harvest_frontier — is the frontier path dead?') rather than just the last hint or a streak count.
 *
 * Pure + deterministic: takes the prior_briefs list (L14 reflection time series, newest-first) + returns
 * `{schema, total, by_hint:[{hint, count, pct}], top}`. Sorted by count desc with deterministic id
 * tie-break. Pétreo: réu never edits the histogram (else it'd shape its own distribution to "look balanced").
 */
final class AtlasBrainBriefHistogram
{
    public const SCHEMA = 'atlas.brain.brief_histogram.v1';

    /**
     * @param  list<array{kind?:string, reflection?:string}>  $priorBriefs  newest-first
     * @return array{schema:string, total:int, by_hint:list<array{hint:string, count:int, pct:int}>, top:?string}
     */
    public function histogram(array $priorBriefs): array
    {
        $counts = [];
        foreach ($priorBriefs as $brief) {
            $text = (string) ($brief['reflection'] ?? '');
            if (! str_starts_with($text, 'leverage_brief: ')) {
                continue;
            }
            $rest = substr($text, strlen('leverage_brief: '));
            $cut = strpos($rest, ' — ');
            $hint = trim($cut === false ? $rest : substr($rest, 0, $cut));
            if ($hint === '') {
                continue;
            }
            $counts[$hint] = ($counts[$hint] ?? 0) + 1;
        }

        $total = array_sum($counts);
        $rows = [];
        foreach ($counts as $hint => $count) {
            $rows[] = [
                'hint' => (string) $hint,
                'count' => (int) $count,
                'pct' => $total > 0 ? (int) round(($count * 100) / $total) : 0,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['hint']] <=> [$a['count'], $b['hint']]);

        return [
            'schema' => self::SCHEMA,
            'total' => $total,
            'by_hint' => $rows,
            'top' => $rows[0]['hint'] ?? null,
        ];
    }
}
