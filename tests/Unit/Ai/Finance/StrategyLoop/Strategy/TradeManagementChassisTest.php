<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Strategy\TrendBreakoutStrategy;
use App\Services\Ai\Finance\StrategyLoop\Strategy\TrendPullbackStrategy;
use PHPUnit\Framework\TestCase;

/**
 * O chassi de gestão de trade (famílias v2) tem três contratos:
 *   1. GOLDEN: params de gestão ausentes == zeros explícitos == comportamento v1
 *      bit-idêntico (curva de equity inteira comparada).
 *   2. BREAKEVEN morde: num rally que arma o breakeven e depois colapsa, a v2 sai
 *      perto da entrada enquanto a v1 (trail largo) afunda junto — equity v2 > v1.
 *   3. PREFIX-INVARIANCE com gestão LIGADA: as camadas novas continuam causais.
 */
final class TradeManagementChassisTest extends TestCase
{
    public function test_golden_equivalence_management_off_is_bit_identical(): void
    {
        $series = $this->rallyCollapseSeries(70);

        foreach ([
            [new TrendBreakoutStrategy, $this->tbParams()],
            [new TrendPullbackStrategy, $this->tpParams()],
        ] as [$engine, $params]) {
            $without = $engine->run($series, $params);
            $withZeros = $engine->run($series, $params + ['breakeven_at_r' => 0.0, 'tighten_at_r' => 0.0, 'tighten_atr_mult' => 0.0]);

            $this->assertSame(count($without->equityCurve), count($withZeros->equityCurve));
            foreach ($without->equityCurve as $i => $e) {
                $this->assertSame($e, $withZeros->equityCurve[$i], "equity divergiu no bar {$i} — default não é inerte");
            }
            $this->assertSame($without->nTrades, $withZeros->nTrades);
        }
    }

    public function test_breakeven_protects_the_rally_that_collapses_on_trend_breakout(): void
    {
        $series = $this->rallyCollapseSeries(70);
        $base = $this->tbParams();

        $v1 = (new TrendBreakoutStrategy)->run($series, $base);
        $v2 = (new TrendBreakoutStrategy)->run($series, $base + ['breakeven_at_r' => 1.0]);

        $this->assertGreaterThanOrEqual(1, $v2->nTrades, 'v2 deve ter saído via breakeven');
        $v1Final = $v1->equityCurve[count($v1->equityCurve) - 1];
        $v2Final = $v2->equityCurve[count($v2->equityCurve) - 1];
        $this->assertGreaterThan($v1Final, $v2Final, 'breakeven deve preservar mais equity num colapso pós-rally');
        // a saída v2 fica perto da entrada (não no fundo do colapso)
        $exit = (float) $v2->trades[0]['exit_price'];
        $entry = (float) $v2->trades[0]['entry_price'];
        $this->assertGreaterThan(0.90, $exit / $entry, 'saída breakeven deve ficar perto da entrada');
    }

    public function test_breakeven_raises_the_fixed_stop_on_pullback(): void
    {
        $series = $this->dipRallyCollapseSeries(80);
        $base = $this->tpParams();

        $v1 = (new TrendPullbackStrategy)->run($series, $base);
        $v2 = (new TrendPullbackStrategy)->run($series, $base + ['breakeven_at_r' => 0.8]);

        $this->assertGreaterThanOrEqual(1, $v2->nTrades, 'v2 deve ter saído via stop elevado');
        $v1Final = $v1->equityCurve[count($v1->equityCurve) - 1];
        $v2Final = $v2->equityCurve[count($v2->equityCurve) - 1];
        $this->assertGreaterThanOrEqual($v1Final, $v2Final, 'breakeven não pode piorar o colapso');
    }

    public function test_no_lookahead_prefix_invariance_with_management_on(): void
    {
        $params = $this->tbParams() + ['breakeven_at_r' => 1.0, 'tighten_at_r' => 1.5, 'tighten_atr_mult' => 1.5];
        $full = (new TrendBreakoutStrategy)->run($this->rallyCollapseSeries(70), $params);
        $prefix = (new TrendBreakoutStrategy)->run($this->rallyCollapseSeries(45), $params);

        for ($i = 0; $i < 45; $i++) {
            $this->assertEqualsWithDelta($full->equityCurve[$i], $prefix->equityCurve[$i], 1e-9, "equity diverged at bar {$i} — look-ahead leak na gestão");
        }
    }

    /** @return array<string,float|int> */
    private function tbParams(): array
    {
        return [
            'regime_period' => 10, 'entry_lookback' => 10, 'exit_lookback' => 0,
            'atr_period' => 5, 'atr_mult' => 8.0, 'risk_pct' => 0.5,
            'fee_bps' => 10.0, 'slippage_bps' => 5.0, 'min_hold_bars' => 0,
        ];
    }

    /** @return array<string,float|int> */
    private function tpParams(): array
    {
        return [
            'trend_period' => 10, 'pullback_period' => 8, 'pullback_atr' => 0.8,
            'atr_period' => 5, 'atr_mult' => 8.0, 'max_hold_bars' => 60,
            'risk_pct' => 0.5, 'fee_bps' => 10.0, 'slippage_bps' => 5.0, 'min_hold_bars' => 0,
        ];
    }

    /**
     * Chop ~100 → rally limpo até ~136 (arma o breakeven) → colapso rápido até ~70
     * (barras largas: ATR explode, o trail de 8×ATR NUNCA alcança — só o breakeven
     * separa v1 de v2).
     *
     * @return list<Bar>
     */
    private function rallyCollapseSeries(int $len): array
    {
        $prices = [];
        for ($i = 0; $i < $len; $i++) {
            if ($i < 20) {
                $prices[] = 100.0 + ($i % 2) * 0.5;
            } elseif ($i < 32) {
                $prices[] = 100.0 + ($i - 19) * 3.0;   // rally 103 -> 136
            } else {
                $prices[] = max(70.0, 136.0 - ($i - 31) * 6.0); // colapso -6/bar
            }
        }

        return $this->toBars($prices);
    }

    /**
     * Habitat do pullback: chop → uptrend → dip (entra) → retomada (arma breakeven)
     * → colapso (o stop elevado morde antes do stop original lá embaixo).
     *
     * @return list<Bar>
     */
    private function dipRallyCollapseSeries(int $len): array
    {
        $prices = [];
        for ($i = 0; $i < $len; $i++) {
            if ($i < 15) {
                $prices[] = 100.0 + ($i % 2) * 0.5;
            } elseif ($i < 35) {
                $prices[] = 100.0 + ($i - 14) * 2.0;          // uptrend -> 140
            } elseif ($i < 40) {
                $prices[] = 140.0 - ($i - 34) * 1.5;          // dip -> 132.5 (entra)
            } elseif ($i < 48) {
                $prices[] = 132.5 + ($i - 39) * 1.5;          // retomada -> 144.5 (arma)
            } else {
                $prices[] = max(80.0, 144.5 - ($i - 47) * 5.0); // colapso
            }
        }

        return $this->toBars($prices);
    }

    /**
     * @param  list<float>  $prices
     * @return list<Bar>
     */
    private function toBars(array $prices): array
    {
        $bars = [];
        $day = 86_400_000;
        $prev = $prices[0];
        foreach ($prices as $i => $price) {
            $open = $i === 0 ? $price : $prev;
            $hi = max($open, $price) * 1.005;
            $lo = min($open, $price) * 0.995;
            $bars[] = new Bar($i * $day, $open, $hi, $lo, $price, 1000.0, ($i + 1) * $day - 1);
            $prev = $price;
        }

        return $bars;
    }
}
