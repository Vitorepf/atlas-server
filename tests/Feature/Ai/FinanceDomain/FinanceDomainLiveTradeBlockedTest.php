<?php

namespace Tests\Feature\Ai\FinanceDomain;

use App\Services\Ai\Finance\Kernel\FinanceComplianceService;
use App\Services\Ai\Finance\Kernel\FinanceDomainCanon;
use App\Services\Ai\Finance\Kernel\FinanceDomainException;
use App\Services\Ai\Finance\Kernel\FinanceRuntimeService;
use App\Services\Ai\Mission\MissionFactoryService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesFinanceDomainTables;
use Tests\TestCase;

class FinanceDomainLiveTradeBlockedTest extends TestCase
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

    public function test_canon_live_trading_blocked_default(): void
    {
        $this->assertTrue(FinanceDomainCanon::liveTradingBlocked());
    }

    /**
     * @return array<string,array<int,string>>
     */
    public static function forbiddenActionProvider(): array
    {
        return [
            'execute_live_trade' => ['execute_live_trade'],
            'submit_broker_order' => ['submit_broker_order'],
            'transfer_funds' => ['transfer_funds'],
            'wire_transfer' => ['wire_transfer'],
            'automatic_rebalance' => ['automatic_rebalance'],
            'short_sell_without_mandate' => ['short_sell_without_mandate'],
        ];
    }

    #[DataProvider('forbiddenActionProvider')]
    public function test_compliance_blocks_each_forbidden_action(string $action): void
    {
        $mission = app(MissionFactoryService::class)->create('test '.$action, ['primary_domain' => 'finance']);
        $compliance = app(FinanceComplianceService::class);

        $report = $compliance->review($mission, $action);
        $this->assertSame('BLOCK', $report['decision'], "[{$action}] expected BLOCK decision");

        $this->expectException(FinanceDomainException::class);
        $compliance->assertNotLiveTrade($action);
    }

    public function test_runtime_refuses_to_open_with_live_trade_intent_in_prompt(): void
    {
        $mission = app(MissionFactoryService::class)->create('please execute trade for AAPL now', ['primary_domain' => 'finance']);

        $this->expectException(FinanceDomainException::class);
        $this->expectExceptionMessageMatches('/live trading is blocked by default/');
        app(FinanceRuntimeService::class)->open($mission, 'finance.research_desk');
    }
}
