<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\PolymarketShadow;

use App\Services\Ai\Finance\PolymarketShadow\ShadowDecisionEngine;
use PHPUnit\Framework\TestCase;

final class ShadowDecisionEngineTest extends TestCase
{
    private const WINDOW = 1781097600;

    private function baseInput(array $overrides = []): array
    {
        return array_merge([
            'window_start' => self::WINDOW,
            'now' => self::WINDOW + 60.0,
            'fv_up' => 0.65,
            'book_up' => ['best_ask' => 0.55, 'ask_size' => 500.0],
            'book_down' => ['best_ask' => 0.50, 'ask_size' => 500.0],
            'bankroll' => 200.0,
            'day_start_bankroll' => 200.0,
            'day_pnl' => 0.0,
            'has_position_for_window' => false,
            'previous_signal' => null,
        ], $overrides);
    }

    private function persistedSignal(string $side = 'up', float $secondsAgo = 2.0): array
    {
        return ['window_start' => self::WINDOW, 'side' => $side, 'at' => self::WINDOW + 60.0 - $secondsAgo];
    }

    public function test_first_qualifying_tick_emits_signal_not_trade(): void
    {
        $decision = (new ShadowDecisionEngine)->evaluate($this->baseInput());

        $this->assertSame('signal', $decision['action']);
        $this->assertSame('up', $decision['side']);
    }

    public function test_persistent_edge_on_second_tick_trades(): void
    {
        $decision = (new ShadowDecisionEngine)->evaluate($this->baseInput([
            'previous_signal' => $this->persistedSignal(),
        ]));

        $this->assertSame('trade', $decision['action']);
        $this->assertSame('up', $decision['side']);
        $this->assertSame('latency', $decision['leg']);
        // 5% of $200 = $10 stake at ask 0.55.
        $this->assertEqualsWithDelta(10.0, $decision['stake'], 0.01);
        $this->assertEqualsWithDelta(18.1818, $decision['shares'], 0.01);
        $this->assertEqualsWithDelta(0.65 - 0.55, $decision['edge'], 1e-9);
    }

    public function test_persistence_does_not_carry_across_sides_or_windows(): void
    {
        $engine = new ShadowDecisionEngine;

        $otherSide = $engine->evaluate($this->baseInput([
            'previous_signal' => $this->persistedSignal('down'),
        ]));
        $this->assertSame('signal', $otherSide['action']);

        $staleWindow = $engine->evaluate($this->baseInput([
            'previous_signal' => ['window_start' => self::WINDOW - 300, 'side' => 'up', 'at' => self::WINDOW + 58.0],
        ]));
        $this->assertSame('signal', $staleWindow['action']);

        $tooOld = $engine->evaluate($this->baseInput([
            'previous_signal' => $this->persistedSignal('up', 30.0),
        ]));
        $this->assertSame('signal', $tooOld['action']);
    }

    public function test_entry_window_gate(): void
    {
        $engine = new ShadowDecisionEngine;

        $early = $engine->evaluate($this->baseInput(['now' => self::WINDOW + 10.0]));
        $this->assertSame('none', $early['action']);
        $this->assertSame('outside_entry_window', $early['reason']);

        $late = $engine->evaluate($this->baseInput(['now' => self::WINDOW + 200.0]));
        $this->assertSame('none', $late['action']);
    }

    public function test_min_edge_gate_includes_fee(): void
    {
        // fv 0.58 vs ask 0.55: gross edge 0.03 < 0.04 => no signal.
        $noEdge = (new ShadowDecisionEngine)->evaluate($this->baseInput(['fv_up' => 0.58]));
        $this->assertSame('none', $noEdge['action']);
        $this->assertSame('no_edge', $noEdge['reason']);

        // With a fee rate, a gross 0.045 edge shrinks below min_edge and is rejected.
        $withFee = new ShadowDecisionEngine(takerFeeRate: 0.05);
        $rejected = $withFee->evaluate($this->baseInput(['fv_up' => 0.595]));
        $this->assertSame('none', $rejected['action']);
    }

    public function test_down_side_edge_is_found(): void
    {
        $decision = (new ShadowDecisionEngine)->evaluate($this->baseInput([
            'fv_up' => 0.40, // fv_down = 0.60 vs ask_down 0.50 => edge 0.10
            'previous_signal' => $this->persistedSignal('down'),
        ]));

        $this->assertSame('trade', $decision['action']);
        $this->assertSame('down', $decision['side']);
    }

    public function test_longshot_leg_classification(): void
    {
        $decision = (new ShadowDecisionEngine)->evaluate($this->baseInput([
            'fv_up' => 0.30,
            'book_up' => ['best_ask' => 0.20, 'ask_size' => 500.0],
            'book_down' => ['best_ask' => 0.85, 'ask_size' => 500.0],
            'previous_signal' => $this->persistedSignal('up'),
        ]));

        $this->assertSame('trade', $decision['action']);
        $this->assertSame('longshot_fade', $decision['leg']);
    }

    public function test_daily_halt_blocks_everything(): void
    {
        $decision = (new ShadowDecisionEngine)->evaluate($this->baseInput([
            'day_pnl' => -20.0, // -10% of 200
        ]));

        $this->assertSame('halted', $decision['action']);
    }

    public function test_one_position_per_window(): void
    {
        $decision = (new ShadowDecisionEngine)->evaluate($this->baseInput([
            'has_position_for_window' => true,
            'previous_signal' => $this->persistedSignal(),
        ]));

        $this->assertSame('none', $decision['action']);
        $this->assertSame('position_exists', $decision['reason']);
    }

    public function test_stake_capped_by_top_of_book_liquidity(): void
    {
        $decision = (new ShadowDecisionEngine)->evaluate($this->baseInput([
            'book_up' => ['best_ask' => 0.55, 'ask_size' => 5.0], // only $2.75 available
            'previous_signal' => $this->persistedSignal(),
        ]));

        $this->assertSame('trade', $decision['action']);
        $this->assertEqualsWithDelta(2.75, $decision['stake'], 0.01);
    }

    public function test_dust_liquidity_yields_no_trade(): void
    {
        $decision = (new ShadowDecisionEngine)->evaluate($this->baseInput([
            'book_up' => ['best_ask' => 0.55, 'ask_size' => 1.0], // $0.55 < min stake $1
            'previous_signal' => $this->persistedSignal(),
        ]));

        $this->assertSame('none', $decision['action']);
        $this->assertSame('stake_below_minimum', $decision['reason']);
    }

    public function test_missing_books_and_invalid_fv_are_safe(): void
    {
        $engine = new ShadowDecisionEngine;

        $noBooks = $engine->evaluate($this->baseInput(['book_up' => null, 'book_down' => null]));
        $this->assertSame('none', $noBooks['action']);

        $badFv = $engine->evaluate($this->baseInput(['fv_up' => NAN]));
        $this->assertSame('none', $badFv['action']);
        $this->assertSame('invalid_fv', $badFv['reason']);
    }

    public function test_fee_per_share_formula(): void
    {
        $engine = new ShadowDecisionEngine(takerFeeRate: 0.10);

        $this->assertEqualsWithDelta(0.02, $engine->feePerShare(0.20), 1e-9);
        $this->assertEqualsWithDelta(0.02, $engine->feePerShare(0.80), 1e-9);
        $this->assertEqualsWithDelta(0.05, $engine->feePerShare(0.50), 1e-9);
    }
}
