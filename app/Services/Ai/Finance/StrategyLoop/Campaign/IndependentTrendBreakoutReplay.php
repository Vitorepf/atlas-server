<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;

/**
 * Clean-room replay for the first strategy family. This is not the primary
 * backtester and never proposes trades; it independently replays the same
 * candidate to catch implementation divergence before review certification.
 */
final class IndependentTrendBreakoutReplay
{
    /**
     * @param  list<Bar>  $bars
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    public function evaluate(array $bars, array $params, float $periodsPerYear): array
    {
        $p = $this->normalize($params);
        $n = count($bars);
        $cash = 1.0;
        $qty = 0.0;
        $entryPrice = 0.0;
        $entryIndex = -1;
        $trailHigh = 0.0;
        $pending = null;
        $equity = [];
        $returns = [];
        $trades = [];
        $previousEquity = 1.0;

        for ($i = 0; $i < $n; $i++) {
            $bar = $bars[$i];
            if ($pending === 'enter' && $qty <= 0.0) {
                $fill = $bar->open * (1.0 + $p['slippage_bps'] / 10000.0);
                $atr = $this->atr($bars, max(0, $i - 1), $p['atr_period']);
                $stopDistance = max(1e-9, $p['atr_mult'] * $atr);
                $riskCash = $cash * $p['risk_pct'];
                $qty = $fill > 0 ? $riskCash / $stopDistance : 0.0;
                $notional = $qty * $fill;
                $feeRate = $p['fee_bps'] / 10000.0;
                $maxNotional = $cash / (1.0 + $feeRate);
                if ($notional > $maxNotional) {
                    $notional = $maxNotional;
                    $qty = $fill > 0 ? $notional / $fill : 0.0;
                }
                $cash -= $notional + $notional * $feeRate;
                $entryPrice = $fill;
                $entryIndex = $i;
                $trailHigh = $bar->close;
            } elseif ($pending === 'exit' && $qty > 0.0) {
                $fill = $bar->open * (1.0 - $p['slippage_bps'] / 10000.0);
                $notional = $qty * $fill;
                $cash += $notional - $notional * ($p['fee_bps'] / 10000.0);
                $trades[] = [
                    'entry_idx' => $entryIndex,
                    'exit_idx' => $i,
                    'return' => $entryPrice > 0 ? $fill / $entryPrice - 1.0 : 0.0,
                ];
                $qty = 0.0;
                $entryPrice = 0.0;
                $entryIndex = -1;
                $trailHigh = 0.0;
            }
            $pending = null;

            $mark = $cash + $qty * $bar->close;
            $equity[] = $mark;
            if ($i > 0) {
                $returns[] = $previousEquity > 0 ? $mark / $previousEquity - 1.0 : 0.0;
            }
            $previousEquity = $mark;

            if ($i < $p['warmup'] || $i >= $n - 1) {
                continue;
            }
            if ($qty > 0.0) {
                $trailHigh = max($trailHigh, $bar->close);
                $atr = $this->atr($bars, $i, $p['atr_period']);
                $stop = ($trailHigh - $p['atr_mult'] * $atr) > $bar->close;
                $exit = $p['exit_lookback'] > 0 && $bar->close < $this->lowestLow($bars, $i - 1, $p['exit_lookback']);
                if (($i - $entryIndex) >= $p['min_hold_bars'] && ($stop || $exit)) {
                    $pending = 'exit';
                }
            } else {
                $regime = $p['regime_period'] <= 0 || $bar->close > $this->smaClose($bars, $i, $p['regime_period']);
                $breakout = $bar->close > $this->highestHigh($bars, $i - 1, $p['entry_lookback']);
                if ($regime && $breakout) {
                    $pending = 'enter';
                }
            }
        }

        $metrics = new HonestMetrics;

        return [
            'engine' => 'atlas_independent_replay',
            'status' => 'ready',
            'trade_count' => count($trades),
            'ann_sharpe' => $metrics->sharpe($returns, $periodsPerYear),
            'max_dd' => $metrics->maxDrawdown($equity),
            'trades' => $trades,
        ];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,float|int>
     */
    private function normalize(array $params): array
    {
        $int = static fn (string $key, int $default): int => is_numeric($params[$key] ?? null) ? max(0, (int) $params[$key]) : $default;
        $float = static fn (string $key, float $default): float => is_numeric($params[$key] ?? null) ? (float) $params[$key] : $default;
        $out = [
            'regime_period' => $int('regime_period', 100),
            'entry_lookback' => max(1, $int('entry_lookback', 20)),
            'exit_lookback' => $int('exit_lookback', 10),
            'atr_period' => max(1, $int('atr_period', 14)),
            'atr_mult' => max(0.0, $float('atr_mult', 3.0)),
            'risk_pct' => min(1.0, max(0.0, $float('risk_pct', 0.0))),
            'fee_bps' => max(0.0, $float('fee_bps', 10.0)),
            'slippage_bps' => max(0.0, $float('slippage_bps', 5.0)),
            'min_hold_bars' => $int('min_hold_bars', 0),
        ];
        $out['warmup'] = max($out['regime_period'], $out['entry_lookback'] + 1, $out['exit_lookback'] + 1, $out['atr_period'] + 1, 2);

        return $out;
    }

    /** @param list<Bar> $bars */
    private function smaClose(array $bars, int $idx, int $period): float
    {
        $lo = max(0, $idx - $period + 1);
        $sum = 0.0;
        for ($i = $lo; $i <= $idx; $i++) {
            $sum += $bars[$i]->close;
        }

        return $sum / max(1, $idx - $lo + 1);
    }

    /** @param list<Bar> $bars */
    private function highestHigh(array $bars, int $idx, int $period): float
    {
        $hi = -INF;
        for ($i = max(0, $idx - $period + 1); $i <= $idx; $i++) {
            $hi = max($hi, $bars[$i]->high);
        }

        return $hi === -INF ? PHP_FLOAT_MAX : $hi;
    }

    /** @param list<Bar> $bars */
    private function lowestLow(array $bars, int $idx, int $period): float
    {
        $lo = INF;
        for ($i = max(0, $idx - $period + 1); $i <= $idx; $i++) {
            $lo = min($lo, $bars[$i]->low);
        }

        return $lo === INF ? -PHP_FLOAT_MAX : $lo;
    }

    /** @param list<Bar> $bars */
    private function atr(array $bars, int $idx, int $period): float
    {
        $lo = max(1, $idx - $period + 1);
        $sum = 0.0;
        $count = 0;
        for ($i = $lo; $i <= $idx; $i++) {
            $sum += max(
                $bars[$i]->high - $bars[$i]->low,
                abs($bars[$i]->high - $bars[$i - 1]->close),
                abs($bars[$i]->low - $bars[$i - 1]->close),
            );
            $count++;
        }

        return $count > 0 ? $sum / $count : 0.0;
    }
}
