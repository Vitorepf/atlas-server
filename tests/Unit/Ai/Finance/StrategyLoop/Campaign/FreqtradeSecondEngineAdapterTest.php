<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\FreqtradeSecondEngineAdapter;
use PHPUnit\Framework\TestCase;

final class FreqtradeSecondEngineAdapterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/atlas-freqtrade-report-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_fails_closed_without_configured_report(): void
    {
        $result = (new FreqtradeSecondEngineAdapter)->evaluate('');

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('freqtrade_report_not_configured', $result['reason']);
        $this->assertSame('forbidden', $result['live_trading']);
    }

    public function test_reads_pinned_freqtrade_metrics_report(): void
    {
        file_put_contents($this->path, json_encode([
            'engine' => 'freqtrade',
            'trading_mode' => 'spot',
            'propose_only' => true,
            'live_trading' => 'forbidden',
            'scenario' => [
                'symbol' => 'BTCUSDT',
                'interval' => '1d',
                'strategy_family' => 'trend-breakout-v1',
            ],
            'data_manifest' => ['sha256' => 'data-sha'],
            'holdout' => ['holdout_id' => 'holdout-1'],
            'cost_profile' => ['cost_profile_hash' => 'cost-hash'],
            'metrics' => [
                'trade_count' => 20,
                'ann_sharpe' => 1.02,
                'max_dd' => 0.12,
                'total_return' => 0.18,
                'exposure' => 0.31,
                'equity_curve_sample' => [1.0, 1.08, 1.18],
                'holdout_passed' => true,
            ],
        ], JSON_UNESCAPED_SLASHES));

        $result = (new FreqtradeSecondEngineAdapter)->evaluate($this->path, [
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'data_sha' => 'data-sha',
            'holdout_id' => 'holdout-1',
            'cost_profile_hash' => 'cost-hash',
        ]);

        $this->assertSame('ready', $result['status']);
        $this->assertSame('freqtrade', $result['engine']);
        $this->assertNotEmpty($result['report_sha256']);
        $this->assertSame(20, $result['trade_count']);
        $this->assertSame(1.02, $result['ann_sharpe']);
        $this->assertSame(0.12, $result['max_dd']);
        $this->assertSame(0.18, $result['total_return']);
        $this->assertSame(0.31, $result['exposure']);
        $this->assertSame([1.0, 1.08, 1.18], $result['equity_curve_sample']);
        $this->assertTrue($result['holdout_passed']);
        $this->assertSame('pinned_external_report', $result['source']);
        $this->assertSame('BTCUSDT', $result['scenario']['symbol']);
    }

    public function test_fails_closed_when_pinned_report_metadata_does_not_match_campaign(): void
    {
        file_put_contents($this->path, json_encode([
            'engine' => 'freqtrade',
            'trading_mode' => 'spot',
            'propose_only' => true,
            'live_trading' => 'forbidden',
            'symbol' => 'ETHUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'data_sha' => 'data-sha',
            'holdout_id' => 'holdout-1',
            'cost_profile_hash' => 'cost-hash',
            'metrics' => [
                'trade_count' => 20,
                'ann_sharpe' => 1.02,
                'max_dd' => 0.12,
            ],
        ], JSON_UNESCAPED_SLASHES));

        $result = (new FreqtradeSecondEngineAdapter)->evaluate($this->path, [
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'data_sha' => 'data-sha',
            'holdout_id' => 'holdout-1',
            'cost_profile_hash' => 'cost-hash',
        ]);

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('freqtrade_report_symbol_mismatch', $result['reason']);
    }

    public function test_fails_closed_when_report_is_not_explicitly_propose_only(): void
    {
        file_put_contents($this->path, json_encode([
            'engine' => 'freqtrade',
            'trading_mode' => 'spot',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'data_sha' => 'data-sha',
            'holdout_id' => 'holdout-1',
            'cost_profile_hash' => 'cost-hash',
            'metrics' => [
                'trade_count' => 20,
                'ann_sharpe' => 1.02,
                'max_dd' => 0.12,
            ],
        ], JSON_UNESCAPED_SLASHES));

        $result = (new FreqtradeSecondEngineAdapter)->evaluate($this->path);

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('freqtrade_report_not_propose_only', $result['reason']);
    }
}
