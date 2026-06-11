<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;

/**
 * Long-only spot PULLBACK-IN-TREND — buy weakness INSIDE strength. Hypothesis class
 * genuinely distinct from the existing three: trend-breakout buys strength, mean-
 * reversion buys weakness anywhere, momentum follows time-series sign; this family
 * only buys a dip when the long regime is up, betting the trend resumes.
 *
 * Entry (decided at close[t]): close > SMA(trend_period) [regime up] AND close has
 * pulled back >= pullback_atr × ATR below the rolling high of the last
 * pullback_period bars (window ends at t-1).
 * Exit (decided at close[t]): close >= that entry-time rolling high (trend resumed —
 * target hit), OR close < entry-time fixed ATR stop, OR time stop (max_hold_bars).
 *
 * NO LOOK-AHEAD BY CONSTRUCTION (same audited skeleton as TrendBreakoutStrategy):
 * decisions at close[t] use bars <= t only; fills at open[t+1]; fee + slippage on
 * both sides; stop/target are FROZEN at decision time (no future recomputation).
 * The unit test proves prefix-invariance: appending future bars never changes a
 * single past equity point.
 */
final class TrendPullbackStrategy implements StrategyRunner
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

        $cash = 1.0;
        $units = 0.0;
        $inPos = false;
        $entryPrice = 0.0;
        $entryIdx = -1;
        $stopLevel = 0.0;   // frozen at decision time
        $targetLevel = 0.0; // frozen at decision time
        $maxClose = 0.0;    // maior close desde a entrada (arma o breakeven — v2)
        $entryR = 0.0;      // distância de stop na entrada (1R)
        $equity = $returns = $trades = [];
        $prevEquity = 1.0;
        $pending = null; // null | ['enter', stopDist, stopLevel, targetLevel] | ['exit']

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
                        $stopLevel = (float) $pending[2];
                        $targetLevel = (float) $pending[3];
                        $maxClose = $close[$t];
                        $entryR = $stopDist;
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
                    ];
                    $units = 0.0;
                    $inPos = false;
                    $entryIdx = -1;
                    $entryPrice = 0.0;
                    $stopLevel = 0.0;
                    $targetLevel = 0.0;
                    $maxClose = 0.0;
                    $entryR = 0.0;
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
            if ($inPos) {
                if ($close[$t] > $maxClose) {
                    $maxClose = $close[$t];
                }
                // GESTÃO DE TRADE (default 0 = desligado = v1 bit-idêntico; v2 explora):
                // breakeven — após +breakeven_at_r·R, o stop fixo sobe para a entrada.
                if ($p['breakeven_at_r'] > 0.0 && $entryR > 0.0
                    && $maxClose >= $entryPrice + $p['breakeven_at_r'] * $entryR) {
                    $stopLevel = max($stopLevel, $entryPrice);
                }
                $canExit = ($t - $entryIdx) >= $p['min_hold_bars'];
                $targetHit = $targetLevel > 0.0 && $close[$t] >= $targetLevel;
                $stopHit = $stopLevel > 0.0 && $close[$t] < $stopLevel;
                $timeUp = $p['max_hold_bars'] > 0 && ($t - $entryIdx) >= $p['max_hold_bars'];
                if ($canExit && ($targetHit || $stopHit || $timeUp)) {
                    $pending = ['exit'];
                }
            } else {
                $regimeOk = $close[$t] > $this->sma($close, $t, $p['trend_period']);
                $rollingHigh = $this->highest($high, $t - 1, $p['pullback_period']);
                $atr = $this->atr($high, $low, $close, $t, $p['atr_period']);
                $pulledBack = $rollingHigh < PHP_FLOAT_MAX
                    && $atr > 0.0
                    && $close[$t] <= $rollingHigh - $p['pullback_atr'] * $atr;
                if ($regimeOk && $pulledBack) {
                    $stop = $close[$t] - $p['atr_mult'] * $atr;
                    $pending = ['enter', $p['atr_mult'] * $atr, $stop, $rollingHigh];
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
            'trend_period' => max(2, $i('trend_period', 100)),     // regime is the family's thesis: always on
            'pullback_period' => max(2, $i('pullback_period', 20)),
            'pullback_atr' => max(0.0, $f('pullback_atr', 1.5)),
            'atr_period' => max(1, $i('atr_period', 14)),
            'atr_mult' => max(0.1, $f('atr_mult', 3.0)),
            'max_hold_bars' => $i('max_hold_bars', 20),
            'risk_pct' => min(1.0, max(0.0, $f('risk_pct', 0.0))), // inert default: 0 risk => 0 trades (RED baseline)
            'fee_bps' => max(0.0, $f('fee_bps', 10.0)),            // 0.10% per side (Binance taker)
            'slippage_bps' => max(0.0, $f('slippage_bps', 5.0)),
            'min_hold_bars' => $i('min_hold_bars', 0),
            // Gestão de trade (0 = desligado = v1 bit-idêntico). Família v2 explora.
            'breakeven_at_r' => max(0.0, $f('breakeven_at_r', 0.0)),
        ];
        $out['warmup'] = max(
            $out['trend_period'],
            $out['pullback_period'] + 1,
            $out['atr_period'] + 1,
            2,
        );

        return $out;
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

    /** @param  list<float>  $a  highest value over the window ending at $idx (inclusive) */
    private function highest(array $a, int $idx, int $period): float
    {
        $lo = max(0, $idx - $period + 1);
        $m = -INF;
        for ($i = $lo; $i <= $idx; $i++) {
            if ($a[$i] > $m) {
                $m = $a[$i];
            }
        }

        return $m === -INF ? PHP_FLOAT_MAX : $m; // empty window => no pullback reference

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
