<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PATH PRIORITY RANK — metrics-optimization organ. Combines per-path agreement label (from the
 * signal aggregator) and per-path starvation count (cycles since last touch) into a single
 * priority score; returns paths ranked desc. Higher score = more urgent to rotate to.
 *
 *   base from agreement:
 *     signals_agree_improving  → 10   (compound the win)
 *     signals_agree_declining  → 80   (urgent — needs intervention)
 *     signals_disagree         → 50   (noisy — needs probe)
 *     oscillation_pin          → 90   (top urgency — pair is stuck)
 *     signals_agree_steady     → 20
 *     insufficient_signal      → 30
 *   + min(40, starvation_cycles * 5)  // up to +40 for long starvation
 *
 * Pure decision over already-built inputs. Pétreo: réu would clamp scores so its preferred path
 * always ranks top.
 */
final class AtlasBrainPathPriorityRank
{
    public const SCHEMA = 'atlas.brain.path_priority_rank.v1';

    private const BASE_SCORES = [
        'signals_agree_improving' => 10,
        'signals_agree_declining' => 80,
        'signals_disagree' => 50,
        'oscillation_pin' => 90,
        'signals_agree_steady' => 20,
        'insufficient_signal' => 30,
    ];

    /**
     * @param  array<string, array{agreement:string}>  $aggregatorByPath
     * @param  array<string, int>  $starvationCyclesByPath
     * @return array{schema:string, ranked:list<array{path:string, score:int, agreement:string, starvation:int}>}
     */
    public function rank(array $aggregatorByPath, array $starvationCyclesByPath): array
    {
        $paths = array_unique(array_merge(array_keys($aggregatorByPath), array_keys($starvationCyclesByPath)));
        $rows = [];
        foreach ($paths as $path) {
            $agreement = $aggregatorByPath[$path]['agreement'] ?? 'insufficient_signal';
            $base = self::BASE_SCORES[$agreement] ?? 30;
            $starv = max(0, (int) ($starvationCyclesByPath[$path] ?? 0));
            $score = $base + min(40, $starv * 5);
            $rows[] = ['path' => $path, 'score' => $score, 'agreement' => $agreement, 'starvation' => $starv];
        }
        usort($rows, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return ['schema' => self::SCHEMA, 'ranked' => $rows];
    }
}
