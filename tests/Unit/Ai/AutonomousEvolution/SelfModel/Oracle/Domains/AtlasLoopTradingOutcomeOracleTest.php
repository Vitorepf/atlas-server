<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\SelfModel\Oracle\Domains;

use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\AtlasLoopModelOutcomeOracle;
use App\Services\Ai\AutonomousEvolution\SelfModel\Oracle\Domains\AtlasLoopTradingOutcomeOracle;
use PHPUnit\Framework\TestCase;

/**
 * Proves the trading outcome oracle: domain="trading"; the pétreo anti-overfit rule (in-sample never wins);
 * held-out P&L scoring; ungrounded when held_out_pnl is absent; and self_score ignored.
 */
final class AtlasLoopTradingOutcomeOracleTest extends TestCase
{
    private function oracle(): AtlasLoopTradingOutcomeOracle
    {
        return new AtlasLoopTradingOutcomeOracle;
    }

    public function test_implements_contract_and_domain(): void
    {
        $this->assertInstanceOf(AtlasLoopModelOutcomeOracle::class, $this->oracle());
        $this->assertSame('trading', $this->oracle()->domain());
    }

    public function test_in_sample_only_is_never_a_win(): void
    {
        $out = $this->oracle()->scoreOutcome(['held_out_pnl' => 999999, 'in_sample_only' => true]);

        $this->assertFalse($out['grounded']);
        $this->assertSame(0.0, $out['score']);
        $this->assertSame('in_sample_rejected', $out['basis'], 'in-sample is rejected regardless of P&L');
    }

    public function test_held_out_pnl_scores_when_out_of_sample(): void
    {
        $out = $this->oracle()->scoreOutcome(['held_out_pnl' => 1500, 'in_sample_only' => false]);

        $this->assertTrue($out['grounded']);
        $this->assertSame(1500.0, $out['score']);
        $this->assertSame('held_out_pnl', $out['basis']);
        $this->assertSame('atlas.loop.model_outcome.trading.v1', $out['schema']);
    }

    public function test_missing_held_out_pnl_is_ungrounded(): void
    {
        $out = $this->oracle()->scoreOutcome(['in_sample_only' => false]);

        $this->assertFalse($out['grounded']);
        $this->assertSame(0.0, $out['score']);
        $this->assertSame('ungrounded', $out['basis']);
    }

    public function test_self_score_is_ignored(): void
    {
        $out = $this->oracle()->scoreOutcome(['held_out_pnl' => 200, 'in_sample_only' => false, 'self_score' => 999]);

        $this->assertSame(200.0, $out['score'], 'computed from held_out_pnl, never self_score');
    }
}
