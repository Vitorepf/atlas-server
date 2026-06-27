<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * COHORT SCOPE COMPARATOR — ranks multiple scopes by composite health (gates assumed shared / global,
 * so the cohort axes are PER-SCOPE: served_ratio_pct + starvation_pct + reflection_count). Lets the
 * operator (or a future router) answer "which scope is healthiest RIGHT NOW?" in one read instead of
 * polling brain:state N times and eyeballing.
 *
 * Composite is intentionally simple + transparent: health = served_ratio_pct - starvation_pct, clamped
 * to [-100, 100]. Higher = healthier. Each row preserves the raw axes so the operator can disagree
 * with the composite without losing the underlying numbers.
 *
 * Pure + deterministic + read-only. Pétreo: réu never edits the cohort comparator (else it'd reorder
 * to keep its current scope on top regardless of evidence).
 */
final class AtlasBrainCohortScopeComparator
{
    public const SCHEMA = 'atlas.brain.cohort_scope_comparator.v1';

    /**
     * @param  list<string>  $scopeSlugs
     * @return array{schema:string, rows:list<array{scope:string, served_ratio_pct:int, starvation_pct:int, reflection_count:int, health:int}>, top:?string}
     */
    public function compare(array $scopeSlugs, AtlasBrainReflectionStream $stream, string $doneSetRoot): array
    {
        $rows = [];
        foreach ($scopeSlugs as $slug) {
            $slug = trim($slug);
            if ($slug === '') {
                continue;
            }
            $ledger = new AtlasBrainDoneSetLedger($slug, $doneSetRoot);
            $cycles = $ledger->recentCycles(50);
            $served = 0;
            $refused = 0;
            foreach ($cycles as $c) {
                $status = trim((string) ($c['status'] ?? ''));
                if ($status === 'served' || $status === 'seeded') {
                    $served++;
                } elseif (in_array($status, ['refused', 'abstain', 'already_done', 'prepare_blocked', 'forbidden_target'], true)) {
                    $refused++;
                }
            }
            $decisive = $served + $refused;
            $ratio = $decisive > 0 ? (int) round(($served * 100) / $decisive) : 0;

            $reflectionTail = array_slice($stream->forScope($slug), -50);
            $reflectionCount = count($stream->forScope($slug));
            $starvation = (new AtlasBrainResultKindHistogram)->histogram($reflectionTail)['starvation_pct'];

            $health = max(-100, min(100, $ratio - $starvation));
            $rows[] = [
                'scope' => $slug,
                'served_ratio_pct' => $ratio,
                'starvation_pct' => $starvation,
                'reflection_count' => $reflectionCount,
                'health' => $health,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['health'], $a['scope']] <=> [$a['health'], $b['scope']]);

        return [
            'schema' => self::SCHEMA,
            'rows' => $rows,
            'top' => $rows[0]['scope'] ?? null,
        ];
    }
}
