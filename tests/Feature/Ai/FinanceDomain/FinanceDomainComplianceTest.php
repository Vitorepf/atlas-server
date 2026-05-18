<?php

namespace Tests\Feature\Ai\FinanceDomain;

use App\Services\Ai\Finance\Kernel\FinanceComplianceService;
use App\Services\Ai\Mission\MissionFactoryService;
use Tests\Concerns\CreatesFinanceDomainTables;
use Tests\TestCase;

class FinanceDomainComplianceTest extends TestCase
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

    public function test_compliance_review_blocks_forbidden_action(): void
    {
        $mission = app(MissionFactoryService::class)->create('forbidden test', ['primary_domain' => 'finance']);
        $report = app(FinanceComplianceService::class)->review($mission, 'execute_live_trade', 'go go go');

        $this->assertSame('BLOCK', $report['decision']);
        $kinds = collect($report['flags'])->pluck('kind')->all();
        $this->assertContains('forbidden_action', $kinds);
    }

    public function test_compliance_review_detects_live_trade_keywords_in_rationale(): void
    {
        $mission = app(MissionFactoryService::class)->create('rationale test', ['primary_domain' => 'finance']);
        $report = app(FinanceComplianceService::class)->review($mission, 'finance.research_desk', 'please execute trade for AAPL now');

        $this->assertSame('BLOCK', $report['decision']);
        $kinds = collect($report['flags'])->pluck('kind')->all();
        $this->assertContains('live_trade_intent', $kinds);
    }

    public function test_compliance_review_allows_review_only_action_with_consent(): void
    {
        $mission = app(MissionFactoryService::class)->create('research note for AAPL', ['primary_domain' => 'finance']);
        $report = app(FinanceComplianceService::class)->review($mission, 'finance.research_desk', 'produce research note', ['operator_consent' => true]);

        $this->assertSame('REVIEW', $report['decision']);
        $this->assertSame(0, $report['critical_flag_count']);
    }
}
