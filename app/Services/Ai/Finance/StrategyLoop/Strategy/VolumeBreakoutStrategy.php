<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;

/**
 * Long-only spot breakout CONFIRMED BY ABNORMAL VOLUME — the first family that
 * reads the volume column (price-only families are the most arbitraged space on
 * earth; participation is orthogonal information). Hypothesis: a Donchian breakout
 * accompanied by abnormal participation (volume >= mult × its own recent average)
 * carries follow-through that a low-volume poke does not.
 *
 * NO LOOK-AHEAD BY CONSTRUCTION (same audited skeleton as TrendBreakoutStrategy):
 *   - decisions at bar t use only data from bars <= t (known at close[t]);
 *   - the volume baseline EXCLUDES the current bar (window ends at t-1), so
 *     "abnormal" is measured against history only;
 *   - orders fill at the NEXT bar's open; fee + slippage charged on entry and exit.
 * The unit test proves prefix-invariance: appending future bars never changes a
 * single past equity point.
 *
 * The candidate the search tunes is the params array; this engine and the data are
 * frozen — the loop tunes WHAT to trade, never HOW it is scored.
 */
final class VolumeBreakoutStrategy implements StrategyRunner
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

        $open = $high = $low = $close = $volume = [];
        foreach ($bars as $b) {
            $open[] = $b->open;
            $high[] = $b->high;
            $low[] = $b->low;
            $close[] = $b->close;
            $volume[] = $b->volume;
        }

        $cash = 1.0;
        $units = 0.0;
        $inPos = false;
        $entryPrice = 0.0;
        $entryIdx = -1;
        $trailHigh = 0.0;
        $equity = $returns = $trades = [];
        $prevEquity = 1.0;
        $pending = null; // null | ['enter', stopDist] | ['exit']

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
                    ];
                    $units = 0.0;
                    $inPos = false;
                    $entryIdx = -1;
                    $entryPrice = 0.0;
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
            if ($inPos) {
                if ($close[$t] > $trailHigh) {
                    $trailHigh = $close[$t];
                }
                $atr = $this->atr($high, $low, $close, $t, $p['atr_period']);
                $stopHit = ($trailHigh - $p['atr_mult'] * $atr) > $close[$t];
                $donExit = $p['exit_lookback'] > 0 && $close[$t] < $this->lowest($low, $t - 1, $p['exit_lookback']);
                $canExit = ($t - $entryIdx) >= $p['min_hold_bars'];
                if ($canExit && ($stopHit || $donExit)) {
                    $pending = ['exit'];
                }
            } else {
                $regimeOk = $p['regime_period'] <= 0 || $close[$t] > $this->sma($close, $t, $p['regime_period']);
                $breakout = $close[$t] > $this->highest($high, $t - 1, $p['entry_lookback']);
                // Volume gate: today's participation vs its own history (window ends t-1,
                // so the breakout bar never inflates its own baseline). A flat/zero
                // baseline cannot confirm — fail-closed, no entry.
                $volBase = $this->sma($volume, $t - 1, $p['vol_sma_period']);
                $volOk = $volBase > 0.0 && $volume[$t] >= $p['vol_mult'] * $volBase;
                if ($regimeOk && $breakout && $volOk) {
                    $atr = $this->atr($high, $low, $close, $t, $p['atr_period']);
                    $pending = ['enter', $p['atr_mult'] * $atr];
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
            'regime_period' => $i('regime_period', 100),
            'entry_lookback' => max(1, $i('entry_lookback', 20)),
            'exit_lookback' => $i('exit_lookback', 10),
            'atr_period' => max(1, $i('atr_period', 14)),
            'atr_mult' => max(0.0, $f('atr_mult', 3.0)),
            'vol_sma_period' => max(1, $i('vol_sma_period', 20)),
            'vol_mult' => max(0.0, $f('vol_mult', 1.5)),
            'risk_pct' => min(1.0, max(0.0, $f('risk_pct', 0.0))), // inert default: 0 risk => 0 trades (RED baseline)
            'fee_bps' => max(0.0, $f('fee_bps', 10.0)),            // 0.10% per side (Binance taker)
            'slippage_bps' => max(0.0, $f('slippage_bps', 5.0)),
            'min_hold_bars' => $i('min_hold_bars', 0),
        ];
        $out['warmup'] = max(
            $out['regime_period'],
            $out['entry_lookback'] + 1,
            $out['exit_lookback'] + 1,
            $out['atr_period'] + 1,
            $out['vol_sma_period'] + 1,
            2,
        );

        return $out;
    }

    /** @param  list<float>  $a */
    private function sma(array $a, int $idx, int $period): float
    {
        if ($idx < 0) {
            return 0.0;
        }
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

        return $m === -INF ? PHP_FLOAT_MAX : $m; // empty window => unreachable breakout
    }

    /** @param  list<float>  $a */
    private function lowest(array $a, int $idx, int $period): float
    {
        $lo = max(0, $idx - $period + 1);
        $m = INF;
        for ($i = $lo; $i <= $idx; $i++) {
            if ($a[$i] < $m) {
                $m = $a[$i];
            }
        }

        return $m === INF ? -PHP_FLOAT_MAX : $m; // empty window => unreachable exit
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
