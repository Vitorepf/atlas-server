<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;

/**
 * Long-only spot REGIME-ADAPTIVE strategy — the first consumer of the governed
 * `ohlcv_regime_index_v1` feature set (derived price indices; no external data).
 *
 * The regime index, computed strictly from bars <= t:
 *   - Kaufman Efficiency Ratio (ER): |net move| / sum(|bar moves|) over er_period —
 *     near 1 in a clean trend, near 0 in chop;
 *   - realized-volatility percentile: stdev of simple returns over vol_period,
 *     ranked against its own trailing history (vol_rank_window) — each historical
 *     vol value itself only uses ITS OWN past, so the rank is fully causal.
 *
 * Behavior switches on the regime instead of forcing one shape everywhere:
 *   - TRENDING (ER >= er_entry, close > SMA(trend_period)): enter long with the
 *     trend; exit when the trend's quality dies (ER < er_exit), the ATR trail is
 *     hit, or the time stop lapses.
 *   - CHOP (ER < er_entry): either stay FLAT (chop_mode=0) or buy z-score dips
 *     (chop_mode=1) gated by the vol percentile (no dip-buying into a high-vol
 *     crash); revert entries exit at the mean (z >= exit_z), stop, or time stop.
 *
 * NO LOOK-AHEAD BY CONSTRUCTION (same audited skeleton as TrendBreakoutStrategy):
 * decisions at close[t] use bars <= t only; fills at open[t+1]; fee + slippage on
 * both sides. The unit test proves prefix-invariance: appending future bars never
 * changes a single past equity point — the feature set's required leakage proof.
 */
final class RegimeAdaptiveStrategy implements StrategyRunner
{
    /**
     * @param  list<Bar>  $bars  ascending by time
     * @param  array<string,mixed>  $params
     */
    public function run(array $bars, array $params): StrategyResult
    {
        $p = $this->normalize($params);
        $n = count($bars);
        if ($n === 0) {
            return new StrategyResult([], [], [], 0);
        }

        $open = $high = $low = $close = [];
        foreach ($bars as $b) {
            $open[] = $b->open;
            $high[] = $b->high;
            $low[] = $b->low;
            $close[] = $b->close;
        }

        // Causal vol series: vols[t] depends on returns up to t only.
        $vols = $this->realizedVols($close, $p['vol_period']);

        $cash = 1.0;
        $units = 0.0;
        $inPos = false;
        $entryPrice = 0.0;
        $entryIdx = -1;
        $entryKind = ''; // 'trend' | 'revert'
        $trailHigh = 0.0;
        $stopLevel = 0.0;
        $equity = $returns = $trades = [];
        $prevEquity = 1.0;
        $pending = null; // null | ['enter', stopDist, kind, stopLevel] | ['exit']

        $feeRate = $p['fee_bps'] / 10000.0;
        $slip = $p['slippage_bps'] / 10000.0;

        for ($t = 0; $t < $n; $t++) {
            // 1) EXECUTE a pending order at THIS bar's open (decided at the prior close).
            if ($pending !== null) {
                if ($pending[0] === 'enter' && ! $inPos) {
                    $fill = $open[$t] * (1.0 + $slip);
                    $stopDist = max((float) $pending[1], 1e-9);
                    $riskCap = $p['risk_pct'] * $cash;           // flat => equity == cash
                    $qty = $fill > 0 ? $riskCap / $stopDist : 0.0;
                    $notional = $qty * $fill;
                    $maxNotional = $cash / (1.0 + $feeRate);     // no leverage on spot
                    if ($notional > $maxNotional) {
                        $notional = $maxNotional;
                        $qty = $fill > 0 ? $notional / $fill : 0.0;
                    }
                    $fee = $notional * $feeRate;
                    if ($qty > 0 && $notional + $fee <= $cash + 1e-9) {
                        $cash -= $notional + $fee;
                        $units = $qty;
                        $inPos = true;
                        $entryPrice = $fill;
                        $entryIdx = $t;
                        $entryKind = (string) $pending[2];
                        $stopLevel = (float) $pending[3];
                        $trailHigh = $close[$t];
                    }
                } elseif ($pending[0] === 'exit' && $inPos) {
                    $fill = $open[$t] * (1.0 - $slip);
                    $notional = $units * $fill;
                    $fee = $notional * $feeRate;
                    $cash += $notional - $fee;
                    $trades[] = [
                        'entry_idx' => $entryIdx,
                        'entry_price' => $entryPrice,
                        'exit_idx' => $t,
                        'exit_price' => $fill,
                        'return' => $entryPrice > 0 ? $fill / $entryPrice - 1.0 : 0.0,
                        'bars_held' => $t - $entryIdx,
                        'entry_kind' => $entryKind,
                    ];
                    $units = 0.0;
                    $inPos = false;
                    $entryIdx = -1;
                    $entryPrice = 0.0;
                    $entryKind = '';
                    $stopLevel = 0.0;
                    $trailHigh = 0.0;
                }
                $pending = null;
            }

            // 2) MARK-TO-MARKET at close[t].
            $eq = $cash + $units * $close[$t];
            $equity[] = $eq;
            if ($t > 0) {
                $returns[] = $prevEquity > 0 ? $eq / $prevEquity - 1.0 : 0.0;
            }
            $prevEquity = $eq;

            // 3) DECIDE at close[t] -> a pending order for open[t+1]. Needs warmup history.
            if ($t < $p['warmup']) {
                continue;
            }
            $er = $this->efficiencyRatio($close, $t, $p['er_period']);

            if ($inPos) {
                if ($close[$t] > $trailHigh) {
                    $trailHigh = $close[$t];
                }
                $atr = $this->atr($high, $low, $close, $t, $p['atr_period']);
                $trailHit = ($trailHigh - $p['atr_mult'] * $atr) > $close[$t];
                $stopHit = $stopLevel > 0.0 && $close[$t] < $stopLevel;
                $timeUp = $p['max_hold_bars'] > 0 && ($t - $entryIdx) >= $p['max_hold_bars'];
                $regimeDead = $entryKind === 'trend' && $er < $p['er_exit'];
                $meanTouched = $entryKind === 'revert'
                    && $this->zScore($close, $t, $p['z_lookback']) >= $p['exit_z'];
                $canExit = ($t - $entryIdx) >= $p['min_hold_bars'];
                if ($canExit && ($trailHit || $stopHit || $timeUp || $regimeDead || $meanTouched)) {
                    $pending = ['exit'];
                }
            } else {
                $atr = $this->atr($high, $low, $close, $t, $p['atr_period']);
                $trendingUp = $er >= $p['er_entry'] && $close[$t] > $this->sma($close, $t, $p['trend_period']);
                if ($trendingUp) {
                    $pending = ['enter', $p['atr_mult'] * $atr, 'trend', 0.0];
                } elseif ($p['chop_mode'] === 1 && $er < $p['er_entry']) {
                    $volPct = $this->percentileRank($vols, $t, $p['vol_rank_window']);
                    $z = $this->zScore($close, $t, $p['z_lookback']);
                    if ($volPct <= $p['vol_cap'] && $z <= -$p['entry_z']) {
                        $stop = $close[$t] - $p['atr_mult'] * $atr;
                        $pending = ['enter', $p['atr_mult'] * $atr, 'revert', $stop];
                    }
                }
            }
        }

        return new StrategyResult(
            $equity,
            $returns,
            $trades,
            count($trades),
            $inPos ? ['entry_idx' => $entryIdx, 'entry_price' => $entryPrice] : null,
            is_array($pending) ? (string) $pending[0] : $pending,
        );
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,int|float>
     */
    private function normalize(array $params): array
    {
        $f = static fn (string $k, float $d): float => is_numeric($params[$k] ?? null) ? (float) $params[$k] : $d;
        $i = static fn (string $k, int $d): int => is_numeric($params[$k] ?? null) ? max(0, (int) $params[$k]) : $d;

        $out = [
            'er_period' => max(2, $i('er_period', 10)),
            'er_entry' => min(1.0, max(0.0, $f('er_entry', 0.45))),
            'er_exit' => min(1.0, max(0.0, $f('er_exit', 0.20))),
            'trend_period' => max(2, $i('trend_period', 100)),
            'vol_period' => max(2, $i('vol_period', 20)),
            'vol_rank_window' => max(5, $i('vol_rank_window', 100)),
            'vol_cap' => min(1.0, max(0.0, $f('vol_cap', 0.8))),
            'chop_mode' => $i('chop_mode', 0) === 1 ? 1 : 0,       // 0 = flat in chop, 1 = revert in chop
            'z_lookback' => max(2, $i('z_lookback', 20)),
            'entry_z' => max(0.0, $f('entry_z', 1.5)),
            'exit_z' => $f('exit_z', 0.0),
            'atr_period' => max(1, $i('atr_period', 14)),
            'atr_mult' => max(0.1, $f('atr_mult', 3.0)),
            'max_hold_bars' => $i('max_hold_bars', 30),
            'risk_pct' => min(1.0, max(0.0, $f('risk_pct', 0.0))), // inert default: 0 risk => 0 trades (RED baseline)
            'fee_bps' => max(0.0, $f('fee_bps', 10.0)),            // 0.10% per side (Binance taker)
            'slippage_bps' => max(0.0, $f('slippage_bps', 5.0)),
            'min_hold_bars' => $i('min_hold_bars', 0),
        ];
        $out['warmup'] = max(
            $out['trend_period'],
            $out['er_period'] + 1,
            $out['vol_period'] + 1,
            $out['z_lookback'] + 1,
            $out['atr_period'] + 1,
            2,
        );

        return $out;
    }

    /**
     * Kaufman Efficiency Ratio over the window ending at $idx: |net| / sum(|steps|).
     *
     * @param  list<float>  $close
     */
    private function efficiencyRatio(array $close, int $idx, int $period): float
    {
        $from = $idx - $period;
        if ($from < 0) {
            return 0.0;
        }
        $net = abs($close[$idx] - $close[$from]);
        $path = 0.0;
        for ($i = $from + 1; $i <= $idx; $i++) {
            $path += abs($close[$i] - $close[$i - 1]);
        }

        return $path > 0.0 ? $net / $path : 0.0;
    }

    /**
     * Causal realized-vol series: vols[t] = stdev of simple returns over the window
     * ending at t. Each value depends on bars <= t only, so ranking against the
     * trailing slice stays causal.
     *
     * @param  list<float>  $close
     * @return list<float>
     */
    private function realizedVols(array $close, int $period): array
    {
        $n = count($close);
        $vols = [];
        for ($t = 0; $t < $n; $t++) {
            $lo = max(1, $t - $period + 1);
            $rets = [];
            for ($i = $lo; $i <= $t; $i++) {
                $rets[] = $close[$i - 1] > 0.0 ? $close[$i] / $close[$i - 1] - 1.0 : 0.0;
            }
            $vols[] = $this->stdev($rets);
        }

        return $vols;
    }

    /**
     * Percentile rank (0..1) of $series[$idx] within the trailing window ending at $idx.
     *
     * @param  list<float>  $series
     */
    private function percentileRank(array $series, int $idx, int $window): float
    {
        $lo = max(0, $idx - $window + 1);
        $count = 0;
        $below = 0;
        for ($i = $lo; $i <= $idx; $i++) {
            $count++;
            if ($series[$i] <= $series[$idx]) {
                $below++;
            }
        }

        return $count > 0 ? $below / $count : 0.0;
    }

    /** @param  list<float>  $close */
    private function zScore(array $close, int $idx, int $period): float
    {
        $lo = max(0, $idx - $period + 1);
        $window = array_slice($close, $lo, $idx - $lo + 1);
        $mean = array_sum($window) / max(1, count($window));
        $sd = $this->stdev($window);

        return $sd > 0.0 ? ($close[$idx] - $mean) / $sd : 0.0;
    }

    /** @param  list<float>  $values */
    private function stdev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $var = 0.0;
        foreach ($values as $v) {
            $var += ($v - $mean) ** 2;
        }

        return sqrt($var / ($n - 1));
    }

    /** @param  list<float>  $a */
    private function sma(array $a, int $idx, int $period): float
    {
        $lo = max(0, $idx - $period + 1);
        $sum = 0.0;
        $count = 0;
        for ($i = $lo; $i <= $idx; $i++) {
            $sum += $a[$i];
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }

    /**
     * Average True Range over the window ending at $idx. TR uses the previous close,
     * so the window starts at index >= 1 (warmup guarantees enough history).
     *
     * @param  list<float>  $high
     * @param  list<float>  $low
     * @param  list<float>  $close
     */
    private function atr(array $high, array $low, array $close, int $idx, int $period): float
    {
        $lo = max(1, $idx - $period + 1);
        $sum = 0.0;
        $count = 0;
        for ($i = $lo; $i <= $idx; $i++) {
            $tr = max(
                $high[$i] - $low[$i],
                abs($high[$i] - $close[$i - 1]),
                abs($low[$i] - $close[$i - 1]),
            );
            $sum += $tr;
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }
}
