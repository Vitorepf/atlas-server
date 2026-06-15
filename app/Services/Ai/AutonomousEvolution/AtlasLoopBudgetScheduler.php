<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ABSURD-LEAP 5 — the BUDGET-OPTIMAL multi-obra scheduler (engineering org, not one engineer).
 *
 * Scaling from one-obra-at-a-time to an autonomous engineering ORG means running many obras concurrently
 * AND spending the token budget where it buys the most certified value. This scheduler is the economics
 * brain: given pending obras (each with a value/priority and a per-provider estimated cost) + a budget + a
 * concurrency cap, it picks for each obra the CHEAPEST provider that can do it and greedily allocates by
 * value-per-cost until the budget or the concurrency cap is hit — maximizing expected certified value, not
 * raw throughput. The rest are deferred (never silently dropped).
 *
 * Pure + deterministic (a value/cost greedy knapsack). The live cost estimates (from impact receipts /
 * provider telemetry) and the actual dispatch wire on top.
 *
 * STATUS: keystone only — pure value/cost allocation with NO production caller yet; the live cost source +
 * concurrent dispatch are the explicit pending integration, not implied to be live.
 */
final class AtlasLoopBudgetScheduler
{
    /**
     * @param  list<array{id:string, value?:float, costs?:array<string,float>}>  $obras  per-provider est cost
     * @return array{scheduled:list<array{id:string, provider:string, cost:float, value:float, ratio:float}>, deferred:list<string>, spent:float, expected_value:float, budget:float}
     */
    public function schedule(array $obras, float $budget, int $maxConcurrent): array
    {
        $budget = max(0.0, $budget);
        $maxConcurrent = max(1, $maxConcurrent);

        // Per obra, pick the CHEAPEST configured provider and its value/cost ratio.
        $candidates = [];
        $unschedulable = []; // obras with no usable provider/cost — DEFERRED, never silently dropped.
        foreach ($obras as $obra) {
            if (! is_array($obra) || trim((string) ($obra['id'] ?? '')) === '') {
                continue;
            }
            $id = trim((string) $obra['id']);
            $rawValue = (float) ($obra['value'] ?? 1.0);
            $value = is_finite($rawValue) ? max(0.0, $rawValue) : 0.0; // NaN/INF value => 0 (never poisons the sort)
            $costs = is_array($obra['costs'] ?? null) ? $obra['costs'] : [];

            $bestProvider = null;
            $bestCost = null;
            foreach ($costs as $provider => $cost) {
                $cost = (float) $cost;
                // Reject non-finite (NaN/INF — realistic from live telemetry) and non-positive costs;
                // a NaN must never pass the comparison and poison the schedule.
                if (! is_string($provider) || trim($provider) === '' || ! is_finite($cost) || $cost <= 0.0) {
                    continue;
                }
                if ($bestCost === null || $cost < $bestCost) {
                    $bestCost = $cost;
                    $bestProvider = trim($provider);
                }
            }
            if ($bestProvider === null) {
                $unschedulable[] = $id; // no finite/positive provider cost => deferred (the honest invariant)

                continue;
            }
            $candidates[] = [
                'id' => $id,
                'provider' => $bestProvider,
                'cost' => $bestCost,
                'value' => $value,
                'ratio' => $bestCost > 0.0 ? round($value / $bestCost, 6) : 0.0,
            ];
        }

        // Greedy: highest value-per-cost first (ties: cheaper first, then higher value).
        usort($candidates, static function (array $a, array $b): int {
            return [$b['ratio'], $a['cost'], $b['value']] <=> [$a['ratio'], $b['cost'], $a['value']];
        });

        $scheduled = [];
        $deferred = $unschedulable; // unschedulable obras are deferred up-front (the "never silently dropped" invariant)
        $spent = 0.0;
        $expectedValue = 0.0;
        foreach ($candidates as $c) {
            if (count($scheduled) < $maxConcurrent && $spent + $c['cost'] <= $budget + 1e-9) {
                $scheduled[] = $c;
                $spent = round($spent + $c['cost'], 6);
                $expectedValue = round($expectedValue + $c['value'], 6);
            } else {
                $deferred[] = $c['id'];
            }
        }

        return [
            'scheduled' => $scheduled,
            'deferred' => $deferred,
            'spent' => $spent,
            'expected_value' => $expectedValue,
            'budget' => $budget,
        ];
    }
}
