<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * RESULT-KIND HISTOGRAM — frequency distribution of result_kind across reflections (blocked / exhausted /
 * stagnated / note / clean_no_op / success). Distinct from the BRIEF histogram (action_hint distribution):
 * this measures OUTCOMES of cycles as the brain recorded them — a different axis of cascade health.
 *
 * Why it matters: high `blocked`+`exhausted`+`stagnated` share means the brain keeps hitting walls; high
 * `clean_no_op` means it keeps abstaining; both starve the queue. A future doctor finding can flag either.
 *
 * Pure + deterministic + read-only. Pétreo.
 */
final class AtlasBrainResultKindHistogram
{
    public const SCHEMA = 'atlas.brain.result_kind_histogram.v1';

    /** "starvation" kinds — counts of these matter for a future low-yield finding. */
    public const STARVATION_KINDS = ['blocked', 'exhausted', 'stagnated', 'clean_no_op'];

    /**
     * @param  list<array<string,mixed>>  $reflectionRows
     * @return array{schema:string, total:int, by_kind:list<array{kind:string, count:int, pct:int}>, starvation_pct:int}
     */
    public function histogram(array $reflectionRows): array
    {
        $counts = [];
        foreach ($reflectionRows as $row) {
            $kind = trim((string) ($row['result_kind'] ?? ''));
            if ($kind === '') {
                continue;
            }
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }

        $total = array_sum($counts);
        $rows = [];
        $starvation = 0;
        foreach ($counts as $kind => $count) {
            $pct = $total > 0 ? (int) round(($count * 100) / $total) : 0;
            $rows[] = ['kind' => (string) $kind, 'count' => (int) $count, 'pct' => $pct];
            if (in_array($kind, self::STARVATION_KINDS, true)) {
                $starvation += $count;
            }
        }
        usort($rows, static fn (array $a, array $b): int => [$b['count'], $a['kind']] <=> [$a['count'], $b['kind']]);

        return [
            'schema' => self::SCHEMA,
            'total' => $total,
            'by_kind' => $rows,
            'starvation_pct' => $total > 0 ? (int) round(($starvation * 100) / $total) : 0,
        ];
    }
}
