<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Strategy;

use App\Services\Ai\Finance\StrategyLoop\Bar;
use App\Services\Ai\Finance\StrategyLoop\Strategy\FundingExtremeStrategy;
use PHPUnit\Framework\TestCase;

/**
 * A prova de publish-time exigida pelo contrato do feature set derivatives_funding_oi_v1
 * (funding-only): prefix-invariance DUPLA — truncar barras futuras E truncar eventos de
 * funding futuros nunca muda um ponto passado da curva. Mais o baseline inerte e a prova
 * diferencial da informação: o MESMO caminho de preço entra com funding negativo extremo
 * e fica de fora com funding neutro.
 */
final class FundingExtremeStrategyLookAheadTest extends TestCase
{
    public function test_no_lookahead_prefix_invariance_truncating_bars(): void
    {
        $tape = $this->tape(60, negativeWindow: true);
        $full = (new FundingExtremeStrategy($tape))->run($this->series(60), $this->params());
        $prefix = (new FundingExtremeStrategy($tape))->run($this->series(40), $this->params());

        $this->assertCount(40, $prefix->equityCurve);
        for ($i = 0; $i < 40; $i++) {
            $this->assertEqualsWithDelta($full->equityCurve[$i], $prefix->equityCurve[$i], 1e-9, "equity diverged at bar {$i} — bar look-ahead leak");
        }
        for ($i = 0; $i < 39; $i++) {
            $this->assertEqualsWithDelta($full->dailyReturns[$i], $prefix->dailyReturns[$i], 1e-9, "return diverged at bar {$i} — bar look-ahead leak");
        }
    }

    public function test_no_lookahead_prefix_invariance_truncating_funding_tape(): void
    {
        $bars = $this->series(60);
        $fullTape = $this->tape(60, negativeWindow: true);
        // Corta a fita nos eventos pagos até a barra 40 — o "futuro do funding" some.
        $cutMs = $bars[39]->closeTime;
        $truncatedTape = array_values(array_filter($fullTape, static fn (array $e): bool => $e[0] <= $cutMs));
        $this->assertLessThan(count($fullTape), count($truncatedTape), 'truncation must actually remove future events');

        $full = (new FundingExtremeStrategy($fullTape))->run($bars, $this->params());
        $truncated = (new FundingExtremeStrategy($truncatedTape))->run($bars, $this->params());

        for ($i = 0; $i < 40; $i++) {
            $this->assertEqualsWithDelta($full->equityCurve[$i], $truncated->equityCurve[$i], 1e-9, "equity diverged at bar {$i} — FUNDING publish-time leak");
        }
    }

    public function test_inert_baseline_makes_no_trades(): void
    {
        $r = (new FundingExtremeStrategy($this->tape(60, negativeWindow: true)))->run($this->series(60), $this->params(0.0));

        $this->assertSame(0, $r->nTrades);
        foreach ($r->equityCurve as $e) {
            $this->assertEqualsWithDelta(1.0, $e, 1e-12, 'inert baseline must hold flat equity');
        }
    }

    public function test_extreme_negative_funding_enters_and_neutral_funding_stays_flat(): void
    {
        $crowded = (new FundingExtremeStrategy($this->tape(60, negativeWindow: true)))->run($this->series(60), $this->params(0.5));
        $neutral = (new FundingExtremeStrategy($this->tape(60, negativeWindow: false)))->run($this->series(60), $this->params(0.5));

        $this->assertGreaterThanOrEqual(1, $crowded->nTrades, 'crowded shorts (deep negative funding) should trigger the squeeze long');
        $entry = (int) $crowded->trades[0]['entry_idx'];
        $this->assertGreaterThanOrEqual(20, $entry, 'entry must land inside the negative-funding window');
        $this->assertLessThanOrEqual(34, $entry, 'entry must land inside the negative-funding window');

        $this->assertSame(0, $neutral->nTrades, 'neutral funding must produce zero entries on the same price path');
    }

    public function test_empty_tape_is_fail_closed(): void
    {
        $r = (new FundingExtremeStrategy([]))->run($this->series(60), $this->params(0.5));

        $this->assertSame(0, $r->nTrades, 'no funding tape => no signal => no trades');
    }

    /** @return array<string,float|int> */
    private function params(float $risk = 0.5): array
    {
        return [
            'fund_window' => 3, 'entry_bps' => 3.0, 'exit_bps' => 0.0, 'regime_period' => 0,
            'atr_period' => 5, 'atr_mult' => 3.0, 'max_hold_bars' => 20,
            'risk_pct' => $risk, 'fee_bps' => 10.0, 'slippage_bps' => 5.0, 'min_hold_bars' => 0,
        ];
    }

    /**
     * Preço neutro de leve oscilação — o sinal desta família vem do FUNDING, não do preço.
     *
     * @return list<Bar>
     */
    private function series(int $len): array
    {
        $bars = [];
        $day = 86_400_000;
        $prev = 100.0;
        for ($i = 0; $i < $len; $i++) {
            $price = 100.0 + ($i % 4) * 0.6;
            $open = $i === 0 ? $price : $prev;
            $hi = max($open, $price) * 1.005;
            $lo = min($open, $price) * 0.995;
            $bars[] = new Bar($i * $day, $open, $hi, $lo, $price, 1000.0, ($i + 1) * $day - 1);
            $prev = $price;
        }

        return $bars;
    }

    /**
     * 3 eventos de funding por barra diária (a cada 8h). Neutro = +1bp; com
     * $negativeWindow, as barras 20-30 pagam -8bp (shorts apinhados).
     *
     * @return list<array{0:int,1:float}>
     */
    private function tape(int $lenBars, bool $negativeWindow): array
    {
        $events = [];
        $eightH = 28_800_000;
        for ($i = 0; $i < $lenBars * 3; $i++) {
            $barIdx = intdiv($i, 3);
            $rate = ($negativeWindow && $barIdx >= 20 && $barIdx <= 30) ? -0.0008 : 0.0001;
            $events[] = [$i * $eightH, $rate];
        }

        return $events;
    }
}
