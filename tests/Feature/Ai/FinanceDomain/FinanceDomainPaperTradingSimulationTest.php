<?php

namespace Tests\Feature\Ai\FinanceDomain;

use App\Services\Ai\Finance\Kernel\FinanceDomainException;
use App\Services\Ai\Finance\Kernel\FinancePaperTradingSimulationService;
use App\Services\Ai\Mission\MissionFactoryService;
use Tests\Concerns\CreatesFinanceDomainTables;
use Tests\TestCase;

class FinanceDomainPaperTradingSimulationTest extends TestCase
{
    use CreatesFinanceDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFinanceDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropFinanceDomainTables();
        parent::tearDown();
    }

    public function test_paper_trading_simulation_computes_cash_and_positions(): void
    {
        $mission = app(MissionFactoryService::class)->create('paper trade AAPL', ['primary_domain' => 'finance']);
        $report = app(FinancePaperTradingSimulationService::class)->simulate($mission, [
            ['asset' => 'AAPL', 'side' => 'buy', 'qty' => 10, 'price' => 150.0],
            ['asset' => 'AAPL', 'side' => 'sell', 'qty' => 4, 'price' => 158.0],
        ], 100000.0);

        $this->assertSame('paper_simulation_only', $report['mode']);
        $this->assertSame('paper_trade_simulation_report', $report['kind']);
        $this->assertSame(98500 + 632, (int) $report['ending_cash']);
        $this->assertSame(6, (int) $report['positions']['AAPL']);
        $this->assertCount(2, $report['tape']);
        $this->assertTrue($report['live_trade_blocked']);
        $this->assertSame('none', $report['broker_connection']);
        $this->assertSame('none', $report['auto_rebalance']);
    }

    public function test_paper_trading_simulation_rejects_invalid_intent(): void
    {
        $mission = app(MissionFactoryService::class)->create('paper trade AAPL', ['primary_domain' => 'finance']);

        $this->expectException(FinanceDomainException::class);
        app(FinancePaperTradingSimulationService::class)->simulate($mission, [
            ['asset' => 'AAPL', 'side' => 'short', 'qty' => 1, 'price' => 150.0],
        ]);
    }
}
