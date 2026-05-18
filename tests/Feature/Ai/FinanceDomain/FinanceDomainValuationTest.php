<?php

namespace Tests\Feature\Ai\FinanceDomain;

use App\Services\Ai\Finance\Kernel\FinanceValuationService;
use App\Services\Ai\Mission\MissionFactoryService;
use Tests\Concerns\CreatesFinanceDomainTables;
use Tests\TestCase;

class FinanceDomainValuationTest extends TestCase
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

    public function test_valuation_is_deterministic_for_same_inputs(): void
    {
        $mission = app(MissionFactoryService::class)->create('value AAPL', ['primary_domain' => 'finance']);
        $service = app(FinanceValuationService::class);
        $a = $service->value($mission, 'AAPL', ['method' => 'multiples']);
        $b = $service->value($mission, 'AAPL', ['method' => 'multiples']);

        $this->assertSame($a['estimated_fair_value_score'], $b['estimated_fair_value_score']);
        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
        $this->assertTrue($a['live_trade_blocked']);
    }

    public function test_valuation_falls_back_to_multiples_when_method_unknown(): void
    {
        $mission = app(MissionFactoryService::class)->create('value AAPL', ['primary_domain' => 'finance']);
        $report = app(FinanceValuationService::class)->value($mission, 'AAPL', ['method' => 'astrology']);

        $this->assertSame('multiples', $report['method']);
    }
}
