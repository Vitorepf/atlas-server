<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

/**
 * Pure sum-of-legs arbitrage math for mutually-exclusive-and-exhaustive
 * (negRisk) multi-outcome events. No I/O, fully unit-testable.
 *
 * Within a SINGLE binary market this arb is impossible by construction (the
 * CLOB mirrors NO orders as 1 - YES, so ask_yes + ask_no = 1 + spread >= 1).
 * Across the separate per-candidate markets of a negRisk event there is no
 * such mirror, so books can drift into genuine inconsistency:
 *
 *  - LONG basket:  sum(best asks) < 1  => buy every YES leg, exactly one pays
 *    $1 per set; profit is locked regardless of outcome. Requires EVERY leg
 *    to be purchasable (exhaustiveness is the caller's responsibility).
 *  - SHORT basket: sum(best bids) > 1  => mint a full set for $1 and sell the
 *    sellable legs; unsold legs are a freeroll (they can only add value).
 *    Execution requires minting, so it is tagged with its own class.
 */
final class ArbMath
{
    /**
     * @param  list<array{token: string, ask: float, ask_size: float}>  $legs
     * @return array{kind: string, n_legs: int, sum: float, profit_per_set: float, sets: float, profit_usd: float, cost_usd: float, execution_class: string}|null
     */
    public static function longBasket(array $legs, float $feePerSet = 0.0, float $minProfitPerSet = 0.005): ?array
    {
        if (count($legs) < 2) {
            return null;
        }

        $sum = 0.0;
        $sets = INF;
        foreach ($legs as $leg) {
            $ask = (float) ($leg['ask'] ?? 1.0);
            $size = (float) ($leg['ask_size'] ?? 0.0);
            // ask >= 1 means "no real offer" — the basket cannot be completed.
            if (! is_finite($ask) || $ask <= 0.0 || $ask >= 1.0 || $size <= 0.0) {
                return null;
            }
            $sum += $ask;
            $sets = min($sets, $size);
        }

        $profitPerSet = 1.0 - $sum - $feePerSet;
        if (! is_finite($profitPerSet) || $profitPerSet < $minProfitPerSet || ! is_finite($sets) || $sets <= 0.0) {
            return null;
        }

        return [
            'kind' => 'long_sum_under',
            'n_legs' => count($legs),
            'sum' => round($sum, 6),
            'profit_per_set' => round($profitPerSet, 6),
            'sets' => round($sets, 4),
            'profit_usd' => round($profitPerSet * $sets, 4),
            'cost_usd' => round($sum * $sets, 4),
            'execution_class' => 'simple_buy_all_legs',
        ];
    }

    /**
     * Depth-aware long basket: walks every ask level of every leg and keeps
     * buying sets while the marginal sum stays profitable. Returns the TRUE
     * executable size, not just top-of-book.
     *
     * @param  list<list<array{price: float, size: float}>>  $legsAskLevels  ascending by price per leg
     * @return array{sets: float, profit_usd: float, cost_usd: float, marginal_sum_start: float}|null
     */
    public static function longBasketDepth(array $legsAskLevels, float $feePerSet = 0.0, float $minProfitPerSet = 0.005): ?array
    {
        if (count($legsAskLevels) < 2) {
            return null;
        }

        $pointers = array_fill(0, count($legsAskLevels), 0);
        $remaining = [];
        foreach ($legsAskLevels as $i => $levels) {
            if ($levels === [] || (float) $levels[0]['size'] <= 0.0) {
                return null;
            }
            $remaining[$i] = (float) $levels[0]['size'];
        }

        $sets = 0.0;
        $profit = 0.0;
        $cost = 0.0;
        $startSum = null;

        while (true) {
            $sum = 0.0;
            $step = INF;
            foreach ($legsAskLevels as $i => $levels) {
                $level = $levels[$pointers[$i]] ?? null;
                if ($level === null) {
                    break 2;
                }
                $price = (float) $level['price'];
                if (! is_finite($price) || $price <= 0.0 || $price >= 1.0) {
                    break 2;
                }
                $sum += $price;
                $step = min($step, $remaining[$i]);
            }

            $marginalProfit = 1.0 - $sum - $feePerSet;
            if ($marginalProfit < $minProfitPerSet || ! is_finite($step) || $step <= 0.0) {
                break;
            }

            $startSum ??= $sum;
            $sets += $step;
            $profit += $marginalProfit * $step;
            $cost += $sum * $step;

            foreach ($legsAskLevels as $i => $levels) {
                $remaining[$i] -= $step;
                if ($remaining[$i] <= 1e-9) {
                    $pointers[$i]++;
                    $next = $levels[$pointers[$i]] ?? null;
                    $remaining[$i] = $next !== null ? (float) $next['size'] : 0.0;
                    if ($remaining[$i] <= 0.0) {
                        break 2;
                    }
                }
            }
        }

        if ($sets <= 0.0) {
            return null;
        }

        return [
            'sets' => round($sets, 4),
            'profit_usd' => round($profit, 4),
            'cost_usd' => round($cost, 4),
            'marginal_sum_start' => round((float) $startSum, 6),
        ];
    }

    /**
     * Depth-aware short basket: walks bid levels (descending) of the sellable
     * legs while the marginal sum stays above 1 + fee. Unsold legs freeroll.
     *
     * @param  list<list<array{price: float, size: float}>>  $legsBidLevels  descending by price per leg
     * @return array{sets: float, profit_usd: float, cost_usd: float, marginal_sum_start: float, sellable_legs: int}|null
     */
    public static function shortBasketDepth(array $legsBidLevels, float $feePerSet = 0.0, float $minProfitPerSet = 0.005): ?array
    {
        $sellable = array_values(array_filter(
            $legsBidLevels,
            fn (array $levels) => $levels !== []
                && (float) $levels[0]['price'] > 0.0
                && (float) $levels[0]['price'] < 1.0
                && (float) $levels[0]['size'] > 0.0,
        ));
        if (count($sellable) < 2) {
            return null;
        }

        $pointers = array_fill(0, count($sellable), 0);
        $remaining = [];
        foreach ($sellable as $i => $levels) {
            $remaining[$i] = (float) $levels[0]['size'];
        }

        $sets = 0.0;
        $profit = 0.0;
        $startSum = null;

        while (true) {
            $sum = 0.0;
            $step = INF;
            foreach ($sellable as $i => $levels) {
                $level = $levels[$pointers[$i]] ?? null;
                if ($level === null) {
                    break 2;
                }
                $price = (float) $level['price'];
                if (! is_finite($price) || $price <= 0.0 || $price >= 1.0) {
                    break 2;
                }
                $sum += $price;
                $step = min($step, $remaining[$i]);
            }

            $marginalProfit = $sum - 1.0 - $feePerSet;
            if ($marginalProfit < $minProfitPerSet || ! is_finite($step) || $step <= 0.0) {
                break;
            }

            $startSum ??= $sum;
            $sets += $step;
            $profit += $marginalProfit * $step;

            foreach ($sellable as $i => $levels) {
                $remaining[$i] -= $step;
                if ($remaining[$i] <= 1e-9) {
                    $pointers[$i]++;
                    $next = $levels[$pointers[$i]] ?? null;
                    $remaining[$i] = $next !== null ? (float) $next['size'] : 0.0;
                    if ($remaining[$i] <= 0.0) {
                        break 2;
                    }
                }
            }
        }

        if ($sets <= 0.0) {
            return null;
        }

        return [
            'sets' => round($sets, 4),
            'profit_usd' => round($profit, 4),
            'cost_usd' => round($sets, 4),
            'marginal_sum_start' => round((float) $startSum, 6),
            'sellable_legs' => count($sellable),
        ];
    }

    /**
     * @param  list<array{token: string, bid: float, bid_size: float}>  $legs
     * @return array{kind: string, n_legs: int, sellable_legs: int, sum: float, profit_per_set: float, sets: float, profit_usd: float, cost_usd: float, execution_class: string}|null
     */
    public static function shortBasket(array $legs, float $feePerSet = 0.0, float $minProfitPerSet = 0.005): ?array
    {
        if (count($legs) < 2) {
            return null;
        }

        $sum = 0.0;
        $sets = INF;
        $sellable = 0;
        foreach ($legs as $leg) {
            $bid = (float) ($leg['bid'] ?? 0.0);
            $size = (float) ($leg['bid_size'] ?? 0.0);
            if (! is_finite($bid) || $bid <= 0.0 || $bid >= 1.0 || $size <= 0.0) {
                continue; // unsold leg = freeroll, never a loss against the $1 mint
            }
            $sum += $bid;
            $sets = min($sets, $size);
            $sellable++;
        }

        if ($sellable < 2) {
            return null;
        }

        $profitPerSet = $sum - 1.0 - $feePerSet;
        if (! is_finite($profitPerSet) || $profitPerSet < $minProfitPerSet || ! is_finite($sets) || $sets <= 0.0) {
            return null;
        }

        return [
            'kind' => 'short_sum_over',
            'n_legs' => count($legs),
            'sellable_legs' => $sellable,
            'sum' => round($sum, 6),
            'profit_per_set' => round($profitPerSet, 6),
            'sets' => round($sets, 4),
            'profit_usd' => round($profitPerSet * $sets, 4),
            'cost_usd' => round($sets, 4),
            'execution_class' => 'requires_minting_full_set',
        ];
    }
}
