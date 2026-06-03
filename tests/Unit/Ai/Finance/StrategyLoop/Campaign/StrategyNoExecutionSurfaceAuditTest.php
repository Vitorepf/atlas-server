<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyNoExecutionSurfaceAudit;
use PHPUnit\Framework\TestCase;

final class StrategyNoExecutionSurfaceAuditTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/atlas-no-execution-surface-'.bin2hex(random_bytes(4)).'.php';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function test_allows_propose_only_research_code(): void
    {
        file_put_contents($this->path, <<<'PHP'
<?php
return [
    'propose_only' => true,
    'live_trading' => 'forbidden',
    'note' => 'backtest metrics only',
];
PHP);

        $scan = (new StrategyNoExecutionSurfaceAudit)->scanFiles([$this->path]);

        $this->assertTrue($scan['passed'], json_encode($scan['violations']));
        $this->assertSame([], $scan['violations']);
    }

    public function test_rejects_broker_order_keys_and_live_money_path(): void
    {
        file_put_contents($this->path, <<<'PHP'
<?php
$exchange = new ccxt\binance(['apiKey' => 'x', 'secretKey' => 'y']);
$exchange->createOrder('BTC/USDT', 'market', 'buy', 1);
return ['live_trading' => 'allowed'];
PHP);

        $scan = (new StrategyNoExecutionSurfaceAudit)->scanFiles([$this->path]);
        $reasons = array_column($scan['violations'], 'reason');

        $this->assertFalse($scan['passed']);
        $this->assertContains('ccxt_dependency', $reasons);
        $this->assertContains('api_key_reference', $reasons);
        $this->assertContains('secret_key_reference', $reasons);
        $this->assertContains('create_order_call', $reasons);
        $this->assertContains('live_trading_allowed', $reasons);
    }
}
