<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCandidateSignature;
use PHPUnit\Framework\TestCase;

final class StrategyCandidateSignatureTest extends TestCase
{
    public function test_mean_reversion_signature_buckets_its_own_parameter_region(): void
    {
        $signature = (new StrategyCandidateSignature)->make('BTCUSDT', '1d', 'mean-reversion-v1', [
            'regime_period' => 112,
            'lookback' => 33,
            'entry_z' => 1.37,
            'exit_z' => 0.12,
            'risk_pct' => 0.23,
            'stop_loss_pct' => 0.073,
            'max_hold_bars' => 19,
        ]);

        $this->assertSame('mean-reversion-v1', $signature['scope']['family']);
        $this->assertSame([
            'regime_period' => 100,
            'lookback' => 35,
            'entry_z' => 1.25,
            'exit_z' => 0.0,
            'risk_pct' => 0.25,
            'stop_loss_pct' => 0.08,
            'max_hold_bars' => 20,
        ], $signature['scope']['bucketed_params']);
        $this->assertIsString($signature['signature']);
    }

    public function test_trend_and_mean_reversion_do_not_share_the_same_signature_scope(): void
    {
        $signer = new StrategyCandidateSignature;
        $trend = $signer->make('BTCUSDT', '1d', 'trend-breakout-v1', [
            'regime_period' => 100,
            'entry_lookback' => 30,
            'exit_lookback' => 15,
            'atr_period' => 14,
            'atr_mult' => 3.0,
            'risk_pct' => 0.2,
            'min_hold_bars' => 3,
        ]);
        $mean = $signer->make('BTCUSDT', '1d', 'mean-reversion-v1', [
            'regime_period' => 100,
            'lookback' => 30,
            'entry_z' => 1.5,
            'exit_z' => 0.0,
            'risk_pct' => 0.2,
            'stop_loss_pct' => 0.08,
            'max_hold_bars' => 20,
        ]);

        $this->assertNotSame($trend['signature'], $mean['signature']);
        $this->assertSame('trend-breakout-v1', $trend['scope']['family']);
        $this->assertSame('mean-reversion-v1', $mean['scope']['family']);
    }

    public function test_momentum_signature_buckets_its_own_parameter_region(): void
    {
        $signature = (new StrategyCandidateSignature)->make('BTCUSDT', '4h', 'momentum-v1', [
            'regime_period' => 112,
            'momentum_lookback' => 27,
            'entry_momentum' => 0.034,
            'exit_momentum' => -0.016,
            'risk_pct' => 0.23,
            'stop_loss_pct' => 0.073,
            'trailing_stop_pct' => 0.116,
            'max_hold_bars' => 31,
        ]);

        $this->assertSame('momentum-v1', $signature['scope']['family']);
        $this->assertSame([
            'regime_period' => 100,
            'momentum_lookback' => 25,
            'entry_momentum' => 0.03,
            'exit_momentum' => -0.02,
            'risk_pct' => 0.25,
            'stop_loss_pct' => 0.08,
            'trailing_stop_pct' => 0.12,
            'max_hold_bars' => 30,
        ], $signature['scope']['bucketed_params']);
    }
}
