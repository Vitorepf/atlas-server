<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

/**
 * Internal search selector. It improves exploration pressure, never certification.
 * The final judge still uses TradingHonestyGate + quarantine.
 */
final class StrategyParetoSelector
{
    /** @return list<string> */
    public static function defaultObjectives(): array
    {
        return ['ann_sharpe', 'max_dd', 'stability_score', 'robustness_score'];
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    public function selectElite(array $candidates, int $limit): array
    {
        $pool = [];
        foreach (array_values($candidates) as $i => $candidate) {
            $candidate['_pareto_id'] = 'p'.$i;
            $pool[] = $candidate;
        }
        $elite = [];
        while ($pool !== [] && count($elite) < $limit) {
            $front = $this->front($pool);
            usort($front, fn (array $a, array $b): int => $this->aggregateScore($b) <=> $this->aggregateScore($a));
            foreach ($front as $candidate) {
                $elite[] = $candidate;
                if (count($elite) >= $limit) {
                    break 2;
                }
            }
            $frontIds = array_flip(array_map(static fn (array $c): string => (string) ($c['_pareto_id'] ?? ''), $front));
            $pool = array_values(array_filter($pool, static fn (array $c): bool => ! isset($frontIds[(string) ($c['_pareto_id'] ?? '')])));
        }

        return $elite;
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    public function front(array $candidates): array
    {
        $withIds = [];
        foreach (array_values($candidates) as $i => $candidate) {
            $candidate['_pareto_id'] ??= 'p'.$i;
            $withIds[] = $candidate;
        }

        $front = [];
        foreach ($withIds as $candidate) {
            $dominated = false;
            foreach ($withIds as $other) {
                if (($other['_pareto_id'] ?? null) === ($candidate['_pareto_id'] ?? null)) {
                    continue;
                }
                if ($this->dominates($other, $candidate)) {
                    $dominated = true;
                    break;
                }
            }
            if (! $dominated) {
                $front[] = $candidate;
            }
        }

        return $front;
    }

    /** @param array<string,mixed> $candidate */
    public function aggregateScore(array $candidate): float
    {
        return (float) ($candidate['ann_sharpe'] ?? -INF)
            - (float) ($candidate['max_dd'] ?? INF)
            + 0.25 * (float) ($candidate['stability_score'] ?? 0.0)
            + 0.25 * (float) ($candidate['robustness_score'] ?? 0.0);
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function dominates(array $a, array $b): bool
    {
        $betterOrEqual = (float) ($a['ann_sharpe'] ?? -INF) >= (float) ($b['ann_sharpe'] ?? -INF)
            && (float) ($a['max_dd'] ?? INF) <= (float) ($b['max_dd'] ?? INF)
            && (float) ($a['stability_score'] ?? -INF) >= (float) ($b['stability_score'] ?? -INF)
            && (float) ($a['robustness_score'] ?? -INF) >= (float) ($b['robustness_score'] ?? -INF);

        $strictlyBetter = (float) ($a['ann_sharpe'] ?? -INF) > (float) ($b['ann_sharpe'] ?? -INF)
            || (float) ($a['max_dd'] ?? INF) < (float) ($b['max_dd'] ?? INF)
            || (float) ($a['stability_score'] ?? -INF) > (float) ($b['stability_score'] ?? -INF)
            || (float) ($a['robustness_score'] ?? -INF) > (float) ($b['robustness_score'] ?? -INF);

        return $betterOrEqual && $strictlyBetter;
    }
}
