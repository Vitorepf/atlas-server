<?php

namespace Tests\Feature\Ai\FinanceDomain;

use App\Services\Ai\Finance\Kernel\FinanceRiskReviewService;
use App\Services\Ai\Mission\MissionFactoryService;
use Tests\Concerns\CreatesFinanceDomainTables;
use Tests\TestCase;

class FinanceDomainRiskReviewTest extends TestCase
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

    public function test_risk_review_returns_metrics_and_qualitative_label(): void
    {
        $mission = app(MissionFactoryService::class)->create('risk review', ['primary_domain' => 'finance']);
        $report = app(FinanceRiskReviewService::class)->review($mission, 'AAPL', [0.01, -0.012, 0.022, -0.018, 0.005]);

        $this->assertSame('risk_report', $report['kind']);
        $this->assertArrayHasKey('volatility', $report);
        $this->assertArrayHasKey('var_95', $report);
        $this->assertArrayHasKey('max_drawdown', $report);
        $this->assertContains($report['qualitative_label'], ['low_volatility', 'medium_volatility', 'high_volatility']);
        $this->assertTrue($report['live_trade_blocked']);
    }
}
