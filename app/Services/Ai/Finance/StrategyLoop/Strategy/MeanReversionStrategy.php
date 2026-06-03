<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;

/**
 * Long-only spot mean-reversion family. It buys statistically stretched dips
 * inside an optional broad regime filter and exits when price mean-reverts,
 * hits a stop, or reaches a max holding time. Decisions use close[t], fills use
 * open[t+1], and costs apply on both sides.
 */
final class MeanReversionStrategy implements StrategyRunner
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
        $equity = $returns = $trades = [];
        $prevEquity = 1.0;
        $pending = null; // null | enter | exit
        $feeRate = $p['fee_bps'] / 10000.0;
        $slip = $p['slippage_bps'] / 10000.0;

        for ($t = 0; $t < $n; $t++) {
            if ($pending === 'enter' && $units <= 0.0) {
                $fill = $open[$t] * (1.0 + $slip);
                $notional = $cash * $p['risk_pct'];
                $maxNotional = $cash / (1.0 + $feeRate);
                $notional = min($notional, $maxNotional);
                $qty = $fill > 0.0 ? $notional / $fill : 0.0;
                $fee = $notional * $feeRate;
                if ($qty > 0.0 && $notional + $fee <= $cash + 1e-9) {
                    $cash -= $notional + $fee;
                    $units = $qty;
                    $entryPrice = $fill;
                    $entryIdx = $t;
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

            $z = $this->zScore($close, $t, $p['lookback']);
            if ($units > 0.0) {
                $stopHit = $entryPrice > 0.0 && $close[$t] <= $entryPrice * (1.0 - $p['stop_loss_pct']);
                $reverted = $z >= $p['exit_z'];
                $timedOut = ($t - $entryIdx) >= $p['max_hold_bars'];
                if ($stopHit || $reverted || $timedOut) {
                    $pending = 'exit';
                }
            } else {
                $regimeOk = $p['regime_period'] <= 0 || $close[$t] >= $this->sma($close, $t, $p['regime_period']);
                if ($regimeOk && $z <= -$p['entry_z']) {
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
            'lookback' => max(5, $i('lookback', 30)),
            'entry_z' => min(4.0, max(0.25, $f('entry_z', 1.5))),
            'exit_z' => min(2.0, max(-1.0, $f('exit_z', 0.0))),
            'risk_pct' => min(1.0, max(0.0, $f('risk_pct', 0.2))),
            'stop_loss_pct' => min(0.5, max(0.005, $f('stop_loss_pct', 0.08))),
            'max_hold_bars' => max(1, $i('max_hold_bars', 20)),
            'fee_bps' => max(0.0, $f('fee_bps', 10.0)),
            'slippage_bps' => max(0.0, $f('slippage_bps', 5.0)),
        ];
        $out['warmup'] = max($out['regime_period'], $out['lookback'], 2);

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
    private function zScore(array $values, int $idx, int $period): float
    {
        $lo = max(0, $idx - $period + 1);
        $slice = array_slice($values, $lo, $idx - $lo + 1);
        if (count($slice) < 2) {
            return 0.0;
        }
        $mean = array_sum($slice) / count($slice);
        $variance = 0.0;
        foreach ($slice as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $std = sqrt($variance / max(1, count($slice) - 1));

        return $std > 0.0 ? ($values[$idx] - $mean) / $std : 0.0;
    }
}
