<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

/**
 * The deterministic decision kernel — every rule the video bot carried as prompt
 * text lives here as a structural gate instead. Pure (no I/O), fully unit-testable.
 *
 * Gates, in evaluation order:
 *   1. daily halt        — day P&L <= -halt_pct of day-start bankroll => no trades
 *   2. entry window      — only between entry_min_sec and entry_max_sec into the window
 *   3. one per window    — a single shadow position per 5-minute market
 *   4. net edge          — FV - ask - fee(ask) >= min_edge on the chosen side
 *   5. persistence       — the SAME side must clear the edge on 2 consecutive ticks
 *   6. sizing            — risk_pct of bankroll, capped by top-of-book ask liquidity
 *
 * Win rate is not computed anywhere in this runtime; the scorecard is calibration
 * (Brier/reliability) + predicted-vs-realized EV, per the finance canon.
 */
final class ShadowDecisionEngine
{
    public function __construct(
        private readonly float $minEdge = 0.04,
        private readonly int $entryMinSec = 15,
        private readonly int $entryMaxSec = 180,
        private readonly float $riskPct = 0.05,
        private readonly float $dailyHaltPct = 0.10,
        private readonly float $takerFeeRate = 0.0,
        private readonly float $longshotAskThreshold = 0.35,
        private readonly float $minStake = 1.0,
        private readonly int $persistenceMaxGapSec = 6,
    ) {}

    /**
     * Polymarket-style taker fee per share: rate * min(p, 1-p).
     */
    public function feePerShare(float $price): float
    {
        $p = max(0.0, min(1.0, $price));

        return $this->takerFeeRate * min($p, 1.0 - $p);
    }

    /**
     * @param array{
     *     window_start: int,
     *     now: float,
     *     fv_up: float,
     *     book_up: array{best_ask: float, ask_size: float}|null,
     *     book_down: array{best_ask: float, ask_size: float}|null,
     *     bankroll: float,
     *     day_start_bankroll: float,
     *     day_pnl: float,
     *     has_position_for_window: bool,
     *     previous_signal: array{window_start: int, side: string, at: float}|null,
     * } $input
     * @return array{action: 'trade'|'signal'|'none'|'halted', side?: string, leg?: string, ask?: float, fee_per_share?: float, edge?: float, stake?: float, shares?: float, reason?: string}
     */
    public function evaluate(array $input): array
    {
        $dayStart = max(0.01, (float) $input['day_start_bankroll']);
        if ((float) $input['day_pnl'] <= -$this->dailyHaltPct * $dayStart) {
            return ['action' => 'halted', 'reason' => 'daily_loss_halt'];
        }

        $elapsed = (float) $input['now'] - (float) $input['window_start'];
        if ($elapsed < $this->entryMinSec || $elapsed > $this->entryMaxSec) {
            return ['action' => 'none', 'reason' => 'outside_entry_window'];
        }

        if ($input['has_position_for_window']) {
            return ['action' => 'none', 'reason' => 'position_exists'];
        }

        $fvUp = (float) $input['fv_up'];
        if (! is_finite($fvUp) || $fvUp <= 0.0 || $fvUp >= 1.0) {
            return ['action' => 'none', 'reason' => 'invalid_fv'];
        }

        $best = null;
        foreach (['up' => $fvUp, 'down' => 1.0 - $fvUp] as $side => $fv) {
            $book = $input['book_'.$side] ?? null;
            if (! is_array($book)) {
                continue;
            }
            $ask = (float) ($book['best_ask'] ?? 1.0);
            $askSize = (float) ($book['ask_size'] ?? 0.0);
            if ($ask <= 0.0 || $ask >= 1.0 || $askSize <= 0.0) {
                continue;
            }
            $fee = $this->feePerShare($ask);
            $edge = $fv - $ask - $fee;
            if ($edge < $this->minEdge) {
                continue;
            }
            if ($best === null || $edge > $best['edge']) {
                $best = ['side' => $side, 'fv' => $fv, 'ask' => $ask, 'ask_size' => $askSize, 'fee' => $fee, 'edge' => $edge];
            }
        }

        if ($best === null) {
            return ['action' => 'none', 'reason' => 'no_edge'];
        }

        $previous = $input['previous_signal'];
        $persistent = is_array($previous)
            && $previous['window_start'] === $input['window_start']
            && $previous['side'] === $best['side']
            && ((float) $input['now'] - (float) $previous['at']) <= $this->persistenceMaxGapSec
            && (float) $input['now'] > (float) $previous['at'];

        if (! $persistent) {
            return [
                'action' => 'signal',
                'side' => $best['side'],
                'edge' => $best['edge'],
                'reason' => 'awaiting_persistence',
            ];
        }

        $stake = $this->riskPct * max(0.0, (float) $input['bankroll']);
        $liquidityCap = $best['ask_size'] * $best['ask'];
        $stake = min($stake, $liquidityCap);
        if ($stake < $this->minStake) {
            return ['action' => 'none', 'reason' => 'stake_below_minimum'];
        }

        $shares = $stake / $best['ask'];

        return [
            'action' => 'trade',
            'side' => $best['side'],
            'leg' => $best['ask'] < $this->longshotAskThreshold ? 'longshot_fade' : 'latency',
            'ask' => $best['ask'],
            'fee_per_share' => $best['fee'],
            'edge' => $best['edge'],
            'fv' => $best['fv'],
            'stake' => round($stake, 2),
            'shares' => round($shares, 4),
        ];
    }
}
