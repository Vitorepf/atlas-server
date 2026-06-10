<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketShadow;

use Illuminate\Support\Facades\DB;

/**
 * The honest scorecard. Win rate is deliberately absent (finance canon: it is a
 * forbidden metric here — it flatters exactly the strategies whose tails kill).
 *
 * Two evidence layers:
 *  - QUOTES (unbiased): one FV snapshot per window regardless of trading, scored
 *    with Brier + reliability buckets. This answers "is the fair-value model
 *    calibrated?" without selection bias from the edge filter.
 *  - TRADES (selected): predicted edge vs realized P&L, per leg. This answers
 *    "does the claimed edge survive contact?" — EV ratio ~1 means honest pricing.
 */
final class ShadowCalibrationReport
{
    public function build(): array
    {
        return [
            'schema_version' => 'atlas.finance.poly_shadow.report.v1',
            'generated_at' => now()->toIso8601String(),
            'calibration' => $this->calibration(),
            'trades' => $this->trades(),
            'basis' => $this->basis(),
            'bankroll' => (new ShadowRunState)->snapshot(),
        ];
    }

    private function calibration(): array
    {
        $quotes = DB::table('atlas_poly_shadow_quotes')
            ->whereIn('outcome', ['up', 'down'])
            ->get(['fv_up', 'outcome', 'regime']);

        $n = $quotes->count();
        if ($n === 0) {
            return ['quotes_scored' => 0, 'brier' => null, 'reliability' => []];
        }

        $brierSum = 0.0;
        $buckets = [];
        foreach ($quotes as $quote) {
            $fv = (float) $quote->fv_up;
            $won = $quote->outcome === 'up' ? 1.0 : 0.0;
            $brierSum += ($fv - $won) ** 2;

            $bucket = sprintf('%.1f', floor($fv * 10) / 10);
            $buckets[$bucket] ??= ['n' => 0, 'fv_sum' => 0.0, 'up_sum' => 0.0];
            $buckets[$bucket]['n']++;
            $buckets[$bucket]['fv_sum'] += $fv;
            $buckets[$bucket]['up_sum'] += $won;
        }

        ksort($buckets);
        $reliability = [];
        foreach ($buckets as $bucket => $b) {
            $reliability[] = [
                'bucket' => $bucket,
                'n' => $b['n'],
                'mean_fv' => round($b['fv_sum'] / $b['n'], 4),
                'realized_up_rate' => round($b['up_sum'] / $b['n'], 4),
            ];
        }

        return [
            'quotes_scored' => $n,
            // Reference points: always-0.5 scores 0.25; market-priced ~0.247 historically.
            'brier' => round($brierSum / $n, 4),
            'reliability' => $reliability,
        ];
    }

    private function trades(): array
    {
        $settled = DB::table('atlas_poly_shadow_trades')
            ->whereIn('status', ['won', 'lost'])
            ->get(['leg', 'regime', 'edge', 'shares', 'stake', 'pnl', 'fee_per_share']);

        $summary = [
            'settled' => $settled->count(),
            'open' => DB::table('atlas_poly_shadow_trades')->where('status', 'open')->count(),
            'void' => DB::table('atlas_poly_shadow_trades')->where('status', 'void')->count(),
            'predicted_ev' => 0.0,
            'realized_pnl' => 0.0,
            'fees_paid' => 0.0,
            'ev_capture_ratio' => null,
            'by_leg' => [],
        ];

        foreach ($settled as $trade) {
            $predicted = (float) $trade->edge * (float) $trade->shares;
            $realized = (float) $trade->pnl;
            $fees = (float) $trade->fee_per_share * (float) $trade->shares;

            $summary['predicted_ev'] += $predicted;
            $summary['realized_pnl'] += $realized;
            $summary['fees_paid'] += $fees;

            $leg = (string) $trade->leg;
            $summary['by_leg'][$leg] ??= ['n' => 0, 'predicted_ev' => 0.0, 'realized_pnl' => 0.0];
            $summary['by_leg'][$leg]['n']++;
            $summary['by_leg'][$leg]['predicted_ev'] += $predicted;
            $summary['by_leg'][$leg]['realized_pnl'] += $realized;
        }

        $summary['predicted_ev'] = round($summary['predicted_ev'], 4);
        $summary['realized_pnl'] = round($summary['realized_pnl'], 4);
        $summary['fees_paid'] = round($summary['fees_paid'], 4);
        if ($summary['predicted_ev'] > 0.0) {
            $summary['ev_capture_ratio'] = round($summary['realized_pnl'] / $summary['predicted_ev'], 4);
        }
        foreach ($summary['by_leg'] as &$leg) {
            $leg['predicted_ev'] = round($leg['predicted_ev'], 4);
            $leg['realized_pnl'] = round($leg['realized_pnl'], 4);
        }

        return $summary;
    }

    private function basis(): array
    {
        $reconciled = DB::table('atlas_poly_shadow_windows')->whereNotNull('basis_match');

        $total = (clone $reconciled)->count();
        $mismatched = (clone $reconciled)->where('basis_match', false)->count();

        return [
            'windows_reconciled' => $total,
            'binance_chainlink_mismatches' => $mismatched,
            'mismatch_rate' => $total > 0 ? round($mismatched / $total, 4) : null,
        ];
    }
}
