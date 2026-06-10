<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

/**
 * Pure cross-market implication arbitrage math. No I/O, fully unit-testable.
 *
 * Given two DISTINCT markets where event A logically implies event B, fair
 * prices must satisfy P(A) <= P(B). When the books cross that band — the YES
 * bid on A exceeds the YES ask on B — there is a state-independent edge:
 *
 *   buy NO(A) at (1 - bid_A) and buy YES(B) at ask_B.
 *
 *   A happens  => B happens (A implies B): NO(A) pays 0, YES(B) pays 1 => $1
 *   B only     => NO(A) pays 1, YES(B) pays 1                          => $2
 *   neither    => NO(A) pays 1, YES(B) pays 0                          => $1
 *
 * Guaranteed payoff >= $1 per share for cost (1 - bid_A) + ask_B < $1 whenever
 * bid_A > ask_B. Locked edge per share = bid_A - ask_B - fee.
 *
 * EXECUTION HONESTY: unlike the sum-of-legs long basket, the short side here
 * is a SHORT on A. Capturing bid_A requires either the mirrored NO book or
 * minting a $1 set on A and selling the YES leg — a strictly more complex
 * execution than buying existing asks, and the two markets may resolve at
 * different times (capital locked until the later one). Signals therefore
 * carry execution_class 'buy_no_implicant_buy_yes_implied' and must never be
 * presented as equivalent to 'simple_buy_all_legs'.
 *
 * The implication itself is the CALLER's responsibility (cite-or-omit at the
 * relation parser); this class only prices a pair it is told is ordered.
 */
final class ImplicationMath
{
    public const EXECUTION_CLASS = 'buy_no_implicant_buy_yes_implied';

    /**
     * Top-of-book violation check for a pair (A implies B).
     *
     * @param  array{bid: float, bid_size: float}  $implicantTop  best YES bid of A
     * @param  array{ask: float, ask_size: float}  $impliedTop  best YES ask of B
     * @return array{kind: string, edge_per_share: float, shares: float, profit_usd: float, cost_usd: float, execution_class: string}|null
     */
    public static function violation(array $implicantTop, array $impliedTop, float $feePerShare = 0.0, float $minEdgePerShare = 0.005): ?array
    {
        $bid = (float) ($implicantTop['bid'] ?? 0.0);
        $bidSize = (float) ($implicantTop['bid_size'] ?? 0.0);
        $ask = (float) ($impliedTop['ask'] ?? 1.0);
        $askSize = (float) ($impliedTop['ask_size'] ?? 0.0);

        if (! self::validPrice($bid) || ! self::validPrice($ask) || $bidSize <= 0.0 || $askSize <= 0.0) {
            return null;
        }

        $edge = $bid - $ask - $feePerShare;
        if (! is_finite($edge) || $edge < $minEdgePerShare) {
            return null;
        }

        $shares = min($bidSize, $askSize);

        return [
            'kind' => 'implication_violation',
            'edge_per_share' => round($edge, 6),
            'shares' => round($shares, 4),
            'profit_usd' => round($edge * $shares, 4),
            'cost_usd' => round(((1.0 - $bid) + $ask) * $shares, 4),
            'execution_class' => self::EXECUTION_CLASS,
        ];
    }

    /**
     * Depth-aware violation: walks the implicant's YES bids (descending) against
     * the implied's YES asks (ascending), pairing shares while the marginal edge
     * clears the floor. Returns the TRUE executable size, not just top-of-book.
     *
     * @param  list<array{price: float, size: float}>  $implicantBids  descending by price
     * @param  list<array{price: float, size: float}>  $impliedAsks  ascending by price
     * @return array{shares: float, edge_start: float, profit_usd: float, cost_usd: float, execution_class: string}|null
     */
    public static function violationDepth(array $implicantBids, array $impliedAsks, float $feePerShare = 0.0, float $minEdgePerShare = 0.005): ?array
    {
        if ($implicantBids === [] || $impliedAsks === []) {
            return null;
        }

        $i = 0;
        $j = 0;
        $remainingBid = (float) ($implicantBids[0]['size'] ?? 0.0);
        $remainingAsk = (float) ($impliedAsks[0]['size'] ?? 0.0);

        $shares = 0.0;
        $profit = 0.0;
        $cost = 0.0;
        $edgeStart = null;

        while (true) {
            $bidLevel = $implicantBids[$i] ?? null;
            $askLevel = $impliedAsks[$j] ?? null;
            if ($bidLevel === null || $askLevel === null) {
                break;
            }

            $bid = (float) $bidLevel['price'];
            $ask = (float) $askLevel['price'];
            if (! self::validPrice($bid) || ! self::validPrice($ask)) {
                break;
            }

            $edge = $bid - $ask - $feePerShare;
            $step = min($remainingBid, $remainingAsk);
            if ($edge < $minEdgePerShare || ! is_finite($step) || $step <= 0.0) {
                break;
            }

            $edgeStart ??= $edge;
            $shares += $step;
            $profit += $edge * $step;
            $cost += ((1.0 - $bid) + $ask) * $step;

            $remainingBid -= $step;
            if ($remainingBid <= 1e-9) {
                $i++;
                $remainingBid = isset($implicantBids[$i]) ? (float) $implicantBids[$i]['size'] : 0.0;
                if ($remainingBid <= 0.0) {
                    break;
                }
            }
            $remainingAsk -= $step;
            if ($remainingAsk <= 1e-9) {
                $j++;
                $remainingAsk = isset($impliedAsks[$j]) ? (float) $impliedAsks[$j]['size'] : 0.0;
                if ($remainingAsk <= 0.0) {
                    break;
                }
            }
        }

        if ($shares <= 0.0) {
            return null;
        }

        return [
            'shares' => round($shares, 4),
            'edge_start' => round((float) $edgeStart, 6),
            'profit_usd' => round($profit, 4),
            'cost_usd' => round($cost, 4),
            'execution_class' => self::EXECUTION_CLASS,
        ];
    }

    private static function validPrice(float $price): bool
    {
        return is_finite($price) && $price > 0.0 && $price < 1.0;
    }
}
