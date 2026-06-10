<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\PolyExecConfig;
use App\Services\Ai\Finance\PolymarketExec\SimulatedPolyExecClient;
use PHPUnit\Framework\TestCase;

final class SimulatedPolyExecClientTest extends TestCase
{
    private function client(array $books): SimulatedPolyExecClient
    {
        return new SimulatedPolyExecClient(fn (string $t): ?array => $books[$t] ?? null);
    }

    public function test_buy_consumes_only_levels_at_or_below_limit_as_vwap(): void
    {
        $client = $this->client([
            'A' => ['asks' => [['price' => 0.30, 'size' => 5], ['price' => 0.32, 'size' => 100]], 'bids' => []],
        ]);

        // Limit 0.31 only reaches the 0.30/5 level.
        $fill = $client->buyLimit('A', 0.31, 10.0);
        $this->assertTrue($fill->ok);
        $this->assertEqualsWithDelta(5.0, $fill->filledSize, 1e-9, 'must not fill above the limit price');
        $this->assertEqualsWithDelta(0.30, $fill->avgPrice, 1e-9);
        $this->assertEqualsWithDelta(1.50, $fill->cashUsd, 1e-9);
        $this->assertFalse($fill->isComplete(10.0), 'partial fill is not complete');
    }

    public function test_buy_vwap_across_two_levels_within_limit(): void
    {
        $client = $this->client([
            'A' => ['asks' => [['price' => 0.30, 'size' => 4], ['price' => 0.34, 'size' => 10]], 'bids' => []],
        ]);

        $fill = $client->buyLimit('A', 0.34, 6.0); // 4@0.30 + 2@0.34
        $this->assertTrue($fill->isComplete(6.0));
        $this->assertEqualsWithDelta((4 * 0.30 + 2 * 0.34) / 6, $fill->avgPrice, 1e-6);
        $this->assertEqualsWithDelta(6.0, $client->positionSize('A'), 1e-9);
    }

    public function test_buy_with_no_level_at_limit_returns_failure(): void
    {
        $client = $this->client([
            'A' => ['asks' => [['price' => 0.50, 'size' => 100]], 'bids' => []],
        ]);

        $fill = $client->buyLimit('A', 0.40, 10.0);
        $this->assertFalse($fill->ok);
        $this->assertSame('no_fill_at_limit', $fill->error);
    }

    public function test_sell_to_market_reduces_position_and_returns_proceeds(): void
    {
        $client = $this->client([
            'A' => ['asks' => [['price' => 0.30, 'size' => 100]], 'bids' => [['price' => 0.28, 'size' => 100]]],
        ]);

        $client->buyLimit('A', 0.31, 10.0);
        $this->assertEqualsWithDelta(10.0, $client->positionSize('A'), 1e-9);

        $sell = $client->sellMarket('A', 10.0);
        $this->assertTrue($sell->ok);
        $this->assertEqualsWithDelta(2.80, $sell->cashUsd, 1e-9);
        $this->assertEqualsWithDelta(0.0, $client->positionSize('A'), 1e-9);
    }

    public function test_config_limit_price_and_kill_switch_file(): void
    {
        $kill = sys_get_temp_dir().'/atlas-poly-kill-'.uniqid();
        $cfg = new PolyExecConfig(false, 8.0, 25.0, 2, 3.0, 600, 0.01, 72.0, 250, 0.0, 0.0, $kill);

        $this->assertEqualsWithDelta(0.4100, $cfg->limitPriceFor(0.40), 1e-6); // 0.40 * 1.025
        $this->assertEqualsWithDelta(0.999, $cfg->limitPriceFor(0.999), 1e-6); // capped < 1

        $this->assertFalse($cfg->killSwitchEngaged());
        touch($kill);
        $this->assertTrue($cfg->killSwitchEngaged());
        @unlink($kill);
    }
}
