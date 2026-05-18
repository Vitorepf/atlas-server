<?php

namespace Tests\Feature\Ai\FinanceDomain;

use App\Services\Ai\Finance\Kernel\FinanceDomainException;
use App\Services\Ai\Finance\Kernel\FinancePortfolioReviewService;
use App\Services\Ai\Mission\MissionFactoryService;
use Tests\Concerns\CreatesFinanceDomainTables;
use Tests\TestCase;

class FinanceDomainPortfolioReviewTest extends TestCase
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

    public function test_portfolio_review_returns_concentration_drift_and_blocks_rebalance(): void
    {
        $mission = app(MissionFactoryService::class)->create('review portfolio', ['primary_domain' => 'finance']);
        $report = app(FinancePortfolioReviewService::class)->review(
            $mission,
            [
                ['asset' => 'AAPL', 'weight_pct' => 12.5],
                ['asset' => 'CASH', 'weight_pct' => 30.0],
                ['asset' => 'INDEX_VTI', 'weight_pct' => 57.5],
            ],
            ['AAPL' => 10.0, 'CASH' => 20.0, 'INDEX_VTI' => 70.0],
        );

        $this->assertSame('portfolio_view', $report['kind']);
        $this->assertSame(3, $report['positions_count']);
        $this->assertCount(3, $report['drift_vs_target']);
        $this->assertGreaterThan(0.0, $report['herfindahl_index']);
        $this->assertTrue($report['auto_rebalance_blocked']);
        $this->assertTrue($report['live_trade_blocked']);
    }

    public function test_portfolio_review_rejects_invalid_position(): void
    {
        $mission = app(MissionFactoryService::class)->create('review portfolio', ['primary_domain' => 'finance']);

        $this->expectException(FinanceDomainException::class);
        app(FinancePortfolioReviewService::class)->review($mission, [
            ['asset' => '', 'weight_pct' => 5],
        ]);
    }
}
