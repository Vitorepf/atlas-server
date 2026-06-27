<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PORTFOLIO BUDGET ALLOCATOR — metrics-optimization organ. Given per-path priority scores
 * (typically from AtlasBrainPathPriorityRank), normalizes to a percentage rotation budget that
 * sums to 100 (rounded to whole percentages, residual added to top-ranked path so total is exactly
 * 100). Lets the operator translate score signals into actionable rotation shares.
 *
 * Pure normalization. No IO. Pétreo: réu would inflate its preferred path's share.
 */
final class AtlasBrainPortfolioBudgetAllocator
{
    public const SCHEMA = 'atlas.brain.portfolio_budget_allocator.v1';

    /**
     * @param  array<string, int>  $scoresByPath
     * @return array{schema:string, allocations:array<string,int>, total:int}
     */
    public function allocate(array $scoresByPath): array
    {
        $scores = array_map(static fn ($s) => max(0, (int) $s), $scoresByPath);
        $sum = array_sum($scores);
        if ($sum <= 0 || count($scores) === 0) {
            return ['schema' => self::SCHEMA, 'allocations' => [], 'total' => 0];
        }

        $allocations = [];
        $running = 0;
        $topPath = null;
        $topScore = -1;
        foreach ($scores as $path => $s) {
            $share = (int) floor(100.0 * $s / $sum);
            $allocations[(string) $path] = $share;
            $running += $share;
            if ($s > $topScore) {
                $topScore = $s;
                $topPath = (string) $path;
            }
        }
        if ($topPath !== null && $running < 100) {
            $allocations[$topPath] += (100 - $running);
        }

        return ['schema' => self::SCHEMA, 'allocations' => $allocations, 'total' => array_sum($allocations)];
    }
}
