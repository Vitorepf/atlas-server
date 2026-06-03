<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\CandidateRediscoveryLedger;
use App\Services\Ai\Finance\StrategyLoop\Campaign\CrossCampaignRediscoveryGate;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCandidateSignature;
use PHPUnit\Framework\TestCase;

final class CrossCampaignRediscoveryGateTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/atlas-rediscovery-'.bin2hex(random_bytes(4)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_requires_independent_campaign_rediscovery(): void
    {
        $ledger = new CandidateRediscoveryLedger($this->path);
        $signature = (new StrategyCandidateSignature)->make('BTCUSDT', '1d', 'trend-breakout-v1', $this->params());
        $gate = new CrossCampaignRediscoveryGate;

        $missing = $gate->evaluate($ledger, $signature, 'campaign-b', 1);
        $this->assertFalse($missing['passed']);

        $ledger->record([
            'campaign_id' => 'campaign-a',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'signature' => $signature,
            'status' => 'promoted_to_quarantine',
        ]);

        $passed = $gate->evaluate($ledger, $signature, 'campaign-b', 1);
        $this->assertTrue($passed['passed']);
        $this->assertSame(1, $passed['confirmed_independent_campaigns']);
    }

    public function test_same_campaign_does_not_count_as_independent_rediscovery(): void
    {
        $ledger = new CandidateRediscoveryLedger($this->path);
        $signature = (new StrategyCandidateSignature)->make('BTCUSDT', '1d', 'trend-breakout-v1', $this->params());
        $ledger->record([
            'campaign_id' => 'campaign-a',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'signature' => $signature,
            'status' => 'promoted_to_quarantine',
        ]);

        $result = (new CrossCampaignRediscoveryGate)->evaluate($ledger, $signature, 'campaign-a', 1);

        $this->assertFalse($result['passed']);
    }

    /** @return array<string,mixed> */
    private function params(): array
    {
        return [
            'regime_period' => 100,
            'entry_lookback' => 20,
            'exit_lookback' => 10,
            'atr_period' => 14,
            'atr_mult' => 3.0,
            'risk_pct' => 0.2,
            'min_hold_bars' => 4,
        ];
    }
}
