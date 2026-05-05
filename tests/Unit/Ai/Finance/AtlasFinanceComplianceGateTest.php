<?php

namespace Tests\Unit\Ai\Finance;

use App\Services\Ai\Finance\AtlasFinanceComplianceGate;
use App\Services\Ai\Finance\AtlasFinanceOrchestrator;
use Tests\TestCase;

class AtlasFinanceComplianceGateTest extends TestCase
{
    public function test_it_passes_valid_analysis_only_finance_plan(): void
    {
        $plan = app(AtlasFinanceOrchestrator::class)->flowPlan('risk_review');
        $result = app(AtlasFinanceComplianceGate::class)->evaluate($plan);

        $this->assertTrue($result['passed']);
        $this->assertSame('passed_for_analysis_review_only', $result['status']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_it_blocks_plan_that_allows_market_execution(): void
    {
        $plan = app(AtlasFinanceOrchestrator::class)->flowPlan('risk_review');
        $plan['market_execution_allowed'] = true;

        $result = app(AtlasFinanceComplianceGate::class)->evaluate($plan);

        $this->assertFalse($result['passed']);
        $this->assertContains('market_execution_must_be_disabled', $result['reasons']);
    }

    public function test_it_blocks_plan_missing_global_gates(): void
    {
        $plan = app(AtlasFinanceOrchestrator::class)->flowPlan('risk_review');
        $plan['required_gates'] = ['finance_compliance_review'];

        $result = app(AtlasFinanceComplianceGate::class)->evaluate($plan);

        $this->assertFalse($result['passed']);
        $this->assertContains('missing_required_gate:source_attribution', $result['reasons']);
        $this->assertContains('missing_required_gate:risk_disclosure', $result['reasons']);
        $this->assertContains('missing_required_gate:analysis_review_only', $result['reasons']);
    }

    public function test_it_blocks_market_execution_intent_even_with_valid_plan(): void
    {
        $plan = app(AtlasFinanceOrchestrator::class)->flowPlan('risk_review');
        $result = app(AtlasFinanceComplianceGate::class)->evaluate($plan, ['place_order']);

        $this->assertFalse($result['passed']);
        $this->assertContains('market_execution_request_blocked', $result['reasons']);
    }
}
