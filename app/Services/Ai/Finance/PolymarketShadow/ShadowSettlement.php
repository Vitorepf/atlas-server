<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

use Illuminate\Support\Facades\DB;

/**
 * Settles shadow positions when their 5-minute window closes.
 *
 * Outcome rule mirrors the market description: Up if end price >= start price
 * (ties resolve Up). Settlement uses our recorded Binance boundary prices as a
 * proxy for the official Chainlink stream; reconcileMarketOutcomes() later pulls
 * the official resolution so the Binance↔Chainlink basis is MEASURED, not assumed.
 */
final class ShadowSettlement
{
    public function __construct(private readonly ShadowRunState $state = new ShadowRunState) {}

    /**
     * @return array{settled_windows: int, settled_trades: int, voided_trades: int, pnl: float}
     */
    public function settleDueWindows(int $nowUnix): array
    {
        $due = DB::table('atlas_poly_shadow_windows')
            ->whereNull('outcome')
            ->where('window_start', '<=', $nowUnix - PolymarketShadowFeed::WINDOW_SECONDS)
            ->orderBy('window_start')
            ->limit(50)
            ->get();

        $settledWindows = 0;
        $settledTrades = 0;
        $voidedTrades = 0;
        $totalPnl = 0.0;

        foreach ($due as $window) {
            $sStart = $window->s_start !== null ? (float) $window->s_start : null;
            $sEnd = $window->s_end !== null ? (float) $window->s_end : null;

            if ($sStart === null || $sEnd === null || $sStart <= 0.0 || $sEnd <= 0.0) {
                // Boundary price missing (runner started mid-window or feed gap):
                // void rather than guess — an honest null, never a fabricated fill.
                $voidedTrades += $this->voidTrades((int) $window->window_start);
                DB::table('atlas_poly_shadow_windows')
                    ->where('window_start', $window->window_start)
                    ->update(['outcome' => 'void', 'updated_at' => now()]);
                $settledWindows++;

                continue;
            }

            $outcome = $sEnd >= $sStart ? 'up' : 'down';
            DB::table('atlas_poly_shadow_windows')
                ->where('window_start', $window->window_start)
                ->update(['outcome' => $outcome, 'updated_at' => now()]);

            DB::table('atlas_poly_shadow_quotes')
                ->where('window_start', $window->window_start)
                ->whereNull('outcome')
                ->update(['outcome' => $outcome, 'updated_at' => now()]);

            $trades = DB::table('atlas_poly_shadow_trades')
                ->where('window_start', $window->window_start)
                ->where('status', 'open')
                ->get();

            foreach ($trades as $trade) {
                $won = $trade->side === $outcome;
                $shares = (float) $trade->shares;
                $costPerShare = (float) $trade->ask + (float) $trade->fee_per_share;
                $pnl = $won
                    ? $shares * (1.0 - $costPerShare)
                    : -$shares * $costPerShare;
                $pnl = round($pnl, 4);

                DB::table('atlas_poly_shadow_trades')->where('id', $trade->id)->update([
                    'status' => $won ? 'won' : 'lost',
                    'outcome' => $outcome,
                    'pnl' => $pnl,
                    'settled_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->state->applyPnl($pnl);
                $totalPnl += $pnl;
                $settledTrades++;
            }

            $settledWindows++;
        }

        return [
            'settled_windows' => $settledWindows,
            'settled_trades' => $settledTrades,
            'voided_trades' => $voidedTrades,
            'pnl' => round($totalPnl, 4),
        ];
    }

    /**
     * Pull official resolutions for settled windows and record basis agreement.
     *
     * @return array{checked: int, matched: int, mismatched: int}
     */
    public function reconcileMarketOutcomes(PolymarketShadowFeed $feed, int $limit = 30): array
    {
        $windows = DB::table('atlas_poly_shadow_windows')
            ->whereIn('outcome', ['up', 'down'])
            ->whereNull('market_outcome')
            ->orderBy('window_start')
            ->limit($limit)
            ->get();

        $checked = 0;
        $matched = 0;
        $mismatched = 0;

        foreach ($windows as $window) {
            $official = $feed->resolvedOutcome((int) $window->window_start);
            if ($official === null) {
                continue;
            }

            $match = $official === $window->outcome;
            DB::table('atlas_poly_shadow_windows')
                ->where('window_start', $window->window_start)
                ->update([
                    'market_outcome' => $official,
                    'basis_match' => $match,
                    'updated_at' => now(),
                ]);

            $checked++;
            $match ? $matched++ : $mismatched++;
        }

        return ['checked' => $checked, 'matched' => $matched, 'mismatched' => $mismatched];
    }

    private function voidTrades(int $windowStart): int
    {
        return DB::table('atlas_poly_shadow_trades')
            ->where('window_start', $windowStart)
            ->where('status', 'open')
            ->update([
                'status' => 'void',
                'outcome' => 'void',
                'pnl' => 0,
                'settled_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
