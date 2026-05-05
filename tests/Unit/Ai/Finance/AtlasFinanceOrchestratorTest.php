<?php

namespace Tests\Unit\Ai\Finance;

use App\Services\Ai\Finance\AtlasFinanceDomainContract;
use App\Services\Ai\Finance\AtlasFinanceOrchestrator;
use Tests\TestCase;

class AtlasFinanceOrchestratorTest extends TestCase
{
    public function test_it_declares_enterprise_finance_flows(): void
    {
        $orchestrator = app(AtlasFinanceOrchestrator::class);
        $contract = app(AtlasFinanceDomainContract::class);

        $this->assertSame('AtlasFinanceOrchestrator', $orchestrator->orchestratorId());
        $this->assertSame(['finance'], $orchestrator->supportedDomains());
        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertSame(array_keys($contract->flowDefinitions()), $orchestrator->supportedFlows());
    }

    public function test_default_plan_is_low_autonomy_review_only_and_compliance_gated(): void
    {
        $plan = app(AtlasFinanceOrchestrator::class)->flowPlan('market_research', [
            'subject' => 'AAPL',
            'time_horizon' => 'quarter',
        ]);

        $this->assertSame('finance.market_research', $plan['flow']);
        $this->assertSame('AtlasFinanceRuntime', $plan['runtime']);
        $this->assertSame('analysis_review_only', $plan['output_mode']);
        $this->assertSame('low', $plan['autonomy']);
        $this->assertFalse($plan['background_allowed']);
        $this->assertFalse($plan['market_execution_allowed']);
        $this->assertFalse($plan['destructive_actions_allowed']);
        $this->assertFalse($plan['human_approval_required']);
        $this->assertTrue($plan['compliance_gate_required']);
        $this->assertContains('finance_compliance_review', $plan['required_gates']);
        $this->assertContains('analysis_review_only', $plan['required_gates']);
        $this->assertFalse((bool) data_get($plan, 'tool_policy.broker_api_access'));
        $this->assertFalse((bool) data_get($plan, 'tool_policy.order_entry'));
        $this->assertSame('AAPL', data_get($plan, 'operator_options.subject'));
        $this->assertSame('quarter', data_get($plan, 'operator_options.time_horizon'));
        $this->assertSame('unspecified', data_get($plan, 'operator_options.jurisdiction'));
        $this->assertNotEmpty(data_get($plan, 'audit.review_scope_hash'));
    }

    public function test_finance_forge_requires_human_approval(): void
    {
        $plan = app(AtlasFinanceOrchestrator::class)->flowPlan('forge');

        $this->assertSame('finance.forge', $plan['flow']);
        $this->assertSame('awaiting_human_approval', $plan['status']);
        $this->assertTrue($plan['human_approval_required']);
        $this->assertContains('operator_approval', $plan['required_gates']);
        $this->assertFalse($plan['market_execution_allowed']);
    }

    public function test_unknown_finance_flow_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AtlasFinanceOrchestrator::class)->flowPlan('auto_trade');
    }

    public function test_all_flow_plans_use_the_domain_contract_and_forbid_market_execution(): void
    {
        $orchestrator = app(AtlasFinanceOrchestrator::class);
        $definitions = app(AtlasFinanceDomainContract::class)->flowDefinitions();

        foreach ($orchestrator->supportedFlows() as $flow) {
            $plan = $orchestrator->flowPlan($flow);

            $this->assertSame((array) $definitions[$flow]['required_gates'], $plan['required_gates'], $flow);
            $this->assertSame((array) $definitions[$flow]['required_evidence'], $plan['required_evidence'], $flow);
            $this->assertFalse($plan['market_execution_allowed'], $flow);
            $this->assertSame(AtlasFinanceDomainContract::FORBIDDEN_MARKET_ACTIONS, $plan['forbidden_actions'], $flow);
        }
    }

    public function test_scope_hash_changes_when_jurisdiction_changes(): void
    {
        $orchestrator = app(AtlasFinanceOrchestrator::class);

        $us = $orchestrator->flowPlan('risk_review', ['subject' => 'AAPL', 'jurisdiction' => 'US']);
        $br = $orchestrator->flowPlan('risk_review', ['subject' => 'AAPL', 'jurisdiction' => 'BR']);

        $this->assertNotSame(data_get($us, 'audit.review_scope_hash'), data_get($br, 'audit.review_scope_hash'));
    }
}
