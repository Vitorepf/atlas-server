<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;

/**
 * Long-only spot FUNDING-EXTREME strategy — the first consumer of the governed
 * `derivatives_funding_oi_v1` feature set (funding-only; OI deferred: Binance keeps
 * ~30d of OI history, useless for a frozen multi-year backtest).
 *
 * The information: perpetual-futures funding is the market's POSITIONING tape —
 * every 8h longs pay shorts (positive rate) or shorts pay longs (negative). A deeply
 * negative funding average = crowded, leveraged shorts paying to stay short = squeeze
 * fuel. Hypothesis: enter long on extreme negative funding (optionally regime-gated),
 * exit when funding normalizes, the ATR trail hits, or the time stop lapses.
 * WE TRADE SPOT ONLY — the derivatives tape is read, never traded.
 *
 * PUBLISH-TIME / NO LOOK-AHEAD: the funding tape is injected frozen (constructor —
 * candidate params can never alter data); at decision close[t] the strategy reads
 * ONLY events with funding_time <= closeTime[t], advanced by a forward-only pointer.
 * The unit test proves prefix-invariance BOTH ways: truncating future bars AND
 * truncating future funding events never changes a single past equity point.
 * Execution skeleton identical to the audited TrendBreakoutStrategy (decide close[t],
 * fill open[t+1], fees both sides, inert risk_pct=0 baseline).
 */
final class FundingExtremeStrategy implements StrategyRunner
{
    /**
     * @param  list<array{0:int,1:float}>  $fundingEvents  ascending [funding_time_ms, rate_per_8h]
     */
    public function __construct(private readonly array $fundingEvents = []) {}

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

        $open = $high = $low = $close = $closeTime = [];
        foreach ($bars as $b) {
            $open[] = $b->open;
            $high[] = $b->high;
            $low[] = $b->low;
            $close[] = $b->close;
            $closeTime[] = $b->closeTime;
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

        // Forward-only pointer over the funding tape: rates seen so far (publish-time join).
        $tape = $this->fundingEvents;
        $tapeLen = count($tape);
        $ptr = 0;
        $seenRates = [];

        for ($t = 0; $t < $n; $t++) {
            // 0) ADVANCE the funding pointer to events already PAID at this bar's close.
            while ($ptr < $tapeLen && $tape[$ptr][0] <= $closeTime[$t]) {
                $seenRates[] = $tape[$ptr][1];
                $ptr++;
            }

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

            // 3) DECIDE at close[t] -> a pending order for open[t+1]. Needs warmup + tape.
            if ($t < $p['warmup']) {
                continue;
            }
            // Fail-closed: without enough PAID funding events there is no signal.
            $k = $p['fund_window'];
            $seen = count($seenRates);
            if ($seen < $k) {
                continue;
            }
            $fundAvg = 0.0;
            for ($i = $seen - $k; $i < $seen; $i++) {
                $fundAvg += $seenRates[$i];
            }
            $fundAvg /= $k;

            if ($inPos) {
                if ($close[$t] > $trailHigh) {
                    $trailHigh = $close[$t];
                }
                $atr = $this->atr($high, $low, $close, $t, $p['atr_period']);
                $trailHit = ($trailHigh - $p['atr_mult'] * $atr) > $close[$t];
                $normalized = $fundAvg >= $p['exit_rate'];
                $timeUp = $p['max_hold_bars'] > 0 && ($t - $entryIdx) >= $p['max_hold_bars'];
                $canExit = ($t - $entryIdx) >= $p['min_hold_bars'];
                if ($canExit && ($trailHit || $normalized || $timeUp)) {
                    $pending = ['exit'];
                }
            } else {
                $crowdedShorts = $fundAvg <= -$p['entry_rate'];
                $regimeOk = $p['regime_period'] <= 0 || $close[$t] > $this->sma($close, $t, $p['regime_period']);
                if ($crowdedShorts && $regimeOk) {
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
            'fund_window' => max(1, $i('fund_window', 6)),          // nº de eventos de 8h na média
            'entry_bps' => max(0.0, $f('entry_bps', 3.0)),          // entra quando avg <= -entry (bps/8h)
            'exit_bps' => $f('exit_bps', 0.0),                      // sai quando avg >= exit (bps/8h)
            'regime_period' => $i('regime_period', 0),
            'atr_period' => max(1, $i('atr_period', 14)),
            'atr_mult' => max(0.1, $f('atr_mult', 3.0)),
            'max_hold_bars' => $i('max_hold_bars', 20),
            'risk_pct' => min(1.0, max(0.0, $f('risk_pct', 0.0))),  // inert default: 0 risk => 0 trades
            'fee_bps' => max(0.0, $f('fee_bps', 10.0)),             // 0.10% per side (Binance taker)
            'slippage_bps' => max(0.0, $f('slippage_bps', 5.0)),
            'min_hold_bars' => $i('min_hold_bars', 0),
        ];
        // Internamente em taxa decimal por período de 8h (1 bp = 0.0001).
        $out['entry_rate'] = $out['entry_bps'] / 10000.0;
        $out['exit_rate'] = $out['exit_bps'] / 10000.0;
        $out['warmup'] = max($out['regime_period'], $out['atr_period'] + 1, 2);

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
