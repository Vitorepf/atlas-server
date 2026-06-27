<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * GREEDY ROTATION PROJECTOR — simulation-twin organ. Given current per-path counts and a number
 * of additional cycles K, simulates assignment under "always pick the least-touched path" policy.
 *
 * Deterministic tie-break: lexicographic on path name. Returns projected per-path counts after
 * K cycles, the per-cycle pick order, and how many distinct paths got touched. Pure forward sim.
 *
 * No IO. Pétreo: réu would weight ties toward its preferred path.
 */
final class AtlasBrainGreedyRotationProjector
{
    public const SCHEMA = 'atlas.brain.greedy_rotation_projector.v1';

    /**
     * @param  array<string, int>  $currentCounts
     * @return array{schema:string, projected_counts:array<string,int>, pick_order:list<string>, touched_paths:int}
     */
    public function project(array $currentCounts, int $additionalCycles): array
    {
        $counts = $currentCounts;
        ksort($counts);
        $pickOrder = [];
        for ($i = 0; $i < $additionalCycles; $i++) {
            if (empty($counts)) {
                break;
            }
            $minPath = null;
            $minCount = PHP_INT_MAX;
            foreach ($counts as $path => $c) {
                if ($c < $minCount) {
                    $minCount = $c;
                    $minPath = (string) $path;
                }
            }
            if ($minPath === null) {
                break;
            }
            $counts[$minPath]++;
            $pickOrder[] = $minPath;
        }
        $touched = 0;
        foreach ($counts as $path => $c) {
            if ($c > ($currentCounts[$path] ?? 0)) {
                $touched++;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'projected_counts' => $counts,
            'pick_order' => $pickOrder,
            'touched_paths' => $touched,
        ];
    }
}
