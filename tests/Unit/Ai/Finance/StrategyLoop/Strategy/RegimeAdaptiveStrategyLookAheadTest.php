<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Strategy\RegimeAdaptiveStrategy;
use PHPUnit\Framework\TestCase;

/**
 * A prova de não-vazamento exigida pelo contrato de ativação do feature set
 * ohlcv_regime_index_v1 (prefix-invariance: barras futuras nunca mudam um ponto
 * passado da curva — cobre ER, percentil de vol e z-score de uma vez), mais o
 * baseline inerte e a prova DIFERENCIAL do regime: o mesmo caminho de preço em
 * chop só gera entrada revert quando chop_mode permite; em tendência limpa entra
 * como trend.
 */
final class RegimeAdaptiveStrategyLookAheadTest extends TestCase
{
    public function test_no_lookahead_prefix_invariance(): void
    {
        $full = (new RegimeAdaptiveStrategy)->run($this->trendSeries(60), $this->trendParams());
        $prefix = (new RegimeAdaptiveStrategy)->run($this->trendSeries(40), $this->trendParams());

        $this->assertCount(40, $prefix->equityCurve);
        $this->assertGreaterThanOrEqual(40, count($full->equityCurve));

        for ($i = 0; $i < 40; $i++) {
            $this->assertEqualsWithDelta($full->equityCurve[$i], $prefix->equityCurve[$i], 1e-9, "equity diverged at bar {$i} — look-ahead leak");
        }
        for ($i = 0; $i < 39; $i++) {
            $this->assertEqualsWithDelta($full->dailyReturns[$i], $prefix->dailyReturns[$i], 1e-9, "return diverged at bar {$i} — look-ahead leak");
        }
    }

    public function test_inert_baseline_makes_no_trades(): void
    {
        $r = (new RegimeAdaptiveStrategy)->run($this->trendSeries(60), $this->trendParams(0.0));

        $this->assertSame(0, $r->nTrades);
        foreach ($r->equityCurve as $e) {
            $this->assertEqualsWithDelta(1.0, $e, 1e-12, 'inert baseline must hold flat equity');
        }
    }

    public function test_clean_uptrend_enters_as_trend_regime(): void
    {
        $r = (new RegimeAdaptiveStrategy)->run($this->trendSeries(60), $this->trendParams(0.5));

        $this->assertGreaterThanOrEqual(1, $r->nTrades, 'a clean uptrend (high ER) should trigger a trend entry');
        $this->assertSame('trend', (string) $r->trades[0]['entry_kind']);
        $this->assertGreaterThan(19, (int) $r->trades[0]['entry_idx'], 'entry should land in the trend, not the chop');
    }

    public function test_chop_dip_enters_revert_only_when_chop_mode_allows(): void
    {
        $withRevert = (new RegimeAdaptiveStrategy)->run($this->chopDipSeries(40), $this->chopParams(chopMode: 1));
        $flatInChop = (new RegimeAdaptiveStrategy)->run($this->chopDipSeries(40), $this->chopParams(chopMode: 0));

        $this->assertGreaterThanOrEqual(1, $withRevert->nTrades, 'chop_mode=1 should buy the z-score dip in chop');
        $this->assertSame('revert', (string) $withRevert->trades[0]['entry_kind']);
        $entry = (int) $withRevert->trades[0]['entry_idx'];
        $this->assertGreaterThanOrEqual(20, $entry, 'revert entry must land in the dip');
        $this->assertLessThanOrEqual(28, $entry, 'revert entry must land in the dip');

        $this->assertSame(0, $flatInChop->nTrades, 'chop_mode=0 must stay flat on the same path (er_entry=0.99 blocks trend entries)');
    }

    /** @return array<string,float|int> */
    private function trendParams(float $risk = 0.5): array
    {
        return [
            'er_period' => 8, 'er_entry' => 0.6, 'er_exit' => 0.2, 'trend_period' => 10,
            'vol_period' => 5, 'vol_rank_window' => 10, 'vol_cap' => 1.0, 'chop_mode' => 0,
            'z_lookback' => 10, 'entry_z' => 1.5, 'exit_z' => 0.0,
            'atr_period' => 5, 'atr_mult' => 3.0, 'max_hold_bars' => 30,
            'risk_pct' => $risk, 'fee_bps' => 10.0, 'slippage_bps' => 5.0, 'min_hold_bars' => 0,
        ];
    }

    /** @return array<string,float|int> */
    private function chopParams(int $chopMode): array
    {
        return [
            // er_entry=0.99: NADA conta como tendência — isola o comportamento de chop.
            'er_period' => 12, 'er_entry' => 0.99, 'er_exit' => 0.2, 'trend_period' => 10,
            'vol_period' => 5, 'vol_rank_window' => 10, 'vol_cap' => 1.0, 'chop_mode' => $chopMode,
            'z_lookback' => 10, 'entry_z' => 1.0, 'exit_z' => 0.0,
            'atr_period' => 5, 'atr_mult' => 3.0, 'max_hold_bars' => 30,
            'risk_pct' => 0.5, 'fee_bps' => 10.0, 'slippage_bps' => 5.0, 'min_hold_bars' => 0,
        ];
    }

    /**
     * Chop ~100, depois uptrend limpo até 140 (ER alto), depois declínio.
     *
     * @return list<Bar>
     */
    private function trendSeries(int $len): array
    {
        $prices = [];
        for ($i = 0; $i < $len; $i++) {
            if ($i < 20) {
                $prices[] = 100.0 + ($i % 2) * 0.5;
            } elseif ($i < 40) {
                $prices[] = 100.0 + ($i - 19) * 2.0;
            } else {
                $prices[] = 140.0 - ($i - 39) * 1.5;
            }
        }

        return $this->toBars($prices);
    }

    /**
     * Oscilação ±1 em torno de 100 (ER baixo: caminho longo, rede curta), um mergulho
     * de 4 barras até ~92 (z-score fundo) e recuperação suave — o habitat exato da
     * entrada revert.
     *
     * @return list<Bar>
     */
    private function chopDipSeries(int $len): array
    {
        $prices = [];
        for ($i = 0; $i < $len; $i++) {
            if ($i < 20) {
                $prices[] = 100.0 + ($i % 2 === 0 ? 1.0 : -1.0);   // chop ±1
            } elseif ($i < 24) {
                $prices[] = 100.0 - ($i - 19) * 2.0;               // mergulho 98 -> 92
            } else {
                $prices[] = 92.0 + ($i - 23) * 1.0;                // recuperação
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
