<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;

/**
 * Long-only spot time-series momentum family. It buys sustained strength,
 * optionally filtered by a broad regime SMA, and exits on momentum decay,
 * stop loss, trailing stop, or max holding time. Decisions use close[t],
 * fills use open[t+1], and costs apply on both sides.
 */
final class MomentumStrategy implements StrategyRunner
{
    /**
     * @param  list<Bar>  $bars
     * @param  array<string,mixed>  $params
     */
    public function run(array $bars, array $params): StrategyResult
    {
        $p = $this->normalize($params);
        $n = count($bars);
        if ($n === 0) {
            return new StrategyResult([], [], [], 0);
        }

        $open = $close = [];
        foreach ($bars as $bar) {
            $open[] = $bar->open;
            $close[] = $bar->close;
        }

        $cash = 1.0;
        $units = 0.0;
        $entryPrice = 0.0;
        $entryIdx = -1;
        $trailHigh = 0.0;
        $equity = $returns = $trades = [];
        $prevEquity = 1.0;
        $pending = null; // null | enter | exit
        $feeRate = $p['fee_bps'] / 10000.0;
        $slip = $p['slippage_bps'] / 10000.0;

        for ($t = 0; $t < $n; $t++) {
            if ($pending === 'enter' && $units <= 0.0) {
                $fill = $open[$t] * (1.0 + $slip);
                $notional = min($cash * $p['risk_pct'], $cash / (1.0 + $feeRate));
                $qty = $fill > 0.0 ? $notional / $fill : 0.0;
                $fee = $notional * $feeRate;
                if ($qty > 0.0 && $notional + $fee <= $cash + 1e-9) {
                    $cash -= $notional + $fee;
                    $units = $qty;
                    $entryPrice = $fill;
                    $entryIdx = $t;
                    $trailHigh = $close[$t];
                }
            } elseif ($pending === 'exit' && $units > 0.0) {
                $fill = $open[$t] * (1.0 - $slip);
                $notional = $units * $fill;
                $fee = $notional * $feeRate;
                $cash += $notional - $fee;
                $trades[] = [
                    'entry_idx' => $entryIdx,
                    'entry_price' => $entryPrice,
                    'exit_idx' => $t,
                    'exit_price' => $fill,
                    'return' => $entryPrice > 0.0 ? $fill / $entryPrice - 1.0 : 0.0,
                    'bars_held' => $t - $entryIdx,
                ];
                $units = 0.0;
                $entryPrice = 0.0;
                $entryIdx = -1;
                $trailHigh = 0.0;
            }
            $pending = null;

            $eq = $cash + $units * $close[$t];
            $equity[] = $eq;
            if ($t > 0) {
                $returns[] = $prevEquity > 0.0 ? $eq / $prevEquity - 1.0 : 0.0;
            }
            $prevEquity = $eq;

            if ($t < $p['warmup']) {
                continue;
            }

            $momentum = $this->momentum($close, $t, $p['momentum_lookback']);
            if ($units > 0.0) {
                if ($close[$t] > $trailHigh) {
                    $trailHigh = $close[$t];
                }
                $stopHit = $entryPrice > 0.0 && $close[$t] <= $entryPrice * (1.0 - $p['stop_loss_pct']);
                $trailHit = $trailHigh > 0.0 && $close[$t] <= $trailHigh * (1.0 - $p['trailing_stop_pct']);
                $momentumLost = $momentum <= $p['exit_momentum'];
                $timedOut = ($t - $entryIdx) >= $p['max_hold_bars'];
                if ($stopHit || $trailHit || $momentumLost || $timedOut) {
                    $pending = 'exit';
                }
            } else {
                $regimeOk = $p['regime_period'] <= 0 || $close[$t] >= $this->sma($close, $t, $p['regime_period']);
                if ($regimeOk && $momentum >= $p['entry_momentum']) {
                    $pending = 'enter';
                }
            }
        }

        return new StrategyResult($equity, $returns, $trades, count($trades));
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,float|int>
     */
    private function normalize(array $params): array
    {
        $f = static fn (string $key, float $default): float => is_numeric($params[$key] ?? null) ? (float) $params[$key] : $default;
        $i = static fn (string $key, int $default): int => is_numeric($params[$key] ?? null) ? max(0, (int) $params[$key]) : $default;

        $out = [
            'regime_period' => $i('regime_period', 100),
            'momentum_lookback' => max(2, $i('momentum_lookback', 20)),
            'entry_momentum' => min(0.50, max(0.0, $f('entry_momentum', 0.03))),
            'exit_momentum' => min(0.25, max(-0.25, $f('exit_momentum', 0.0))),
            'risk_pct' => min(1.0, max(0.0, $f('risk_pct', 0.2))),
            'stop_loss_pct' => min(0.5, max(0.005, $f('stop_loss_pct', 0.08))),
            'trailing_stop_pct' => min(0.5, max(0.005, $f('trailing_stop_pct', 0.10))),
            'max_hold_bars' => max(1, $i('max_hold_bars', 30)),
            'fee_bps' => max(0.0, $f('fee_bps', 10.0)),
            'slippage_bps' => max(0.0, $f('slippage_bps', 5.0)),
        ];
        $out['warmup'] = max($out['regime_period'], $out['momentum_lookback'], 2);

        return $out;
    }

    /** @param list<float> $values */
    private function sma(array $values, int $idx, int $period): float
    {
        $lo = max(0, $idx - $period + 1);
        $slice = array_slice($values, $lo, $idx - $lo + 1);

        return $slice !== [] ? array_sum($slice) / count($slice) : 0.0;
    }

    /** @param list<float> $values */
    private function momentum(array $values, int $idx, int $lookback): float
    {
        $baseIdx = $idx - $lookback;
        if ($baseIdx < 0 || ($values[$baseIdx] ?? 0.0) <= 0.0) {
            return 0.0;
        }

        return $values[$idx] / $values[$baseIdx] - 1.0;
    }
}
