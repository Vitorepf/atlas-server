<?php

namespace Tests\Feature\Ai\RouterRuntime;

use App\Services\Ai\RouterRuntime\DomainRouterService;
use App\Services\Ai\RouterRuntime\IntentKernelService;
use Tests\Concerns\CreatesRouterRuntimeTables;
use Tests\TestCase;

class HighRiskPolicyRequiredTest extends TestCase
{
    use CreatesRouterRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRouterRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropRouterRuntimeTables();
        parent::tearDown();
    }

    public function test_cyber_intent_marks_policy_required(): void
    {
        $intent = app(IntentKernelService::class)->classify('pentest autorizado: encontre vulnerabilidade');
        $decision = app(DomainRouterService::class)->route($intent);

        $this->assertSame('cyber', $decision->primary_domain);
        $this->assertTrue((bool) $decision->policy_required, 'cyber must require policy gate');
        $this->assertTrue((bool) $decision->tool_plan_required, 'cyber must require tool plan');
    }

    public function test_finance_intent_marks_policy_and_evidence_required(): void
    {
        $intent = app(IntentKernelService::class)->classify('valuation de carteira de investimentos e simulacao day trade');
        $decision = app(DomainRouterService::class)->route($intent);

        $this->assertSame('finance', $decision->primary_domain);
        $this->assertTrue((bool) $decision->policy_required, 'finance must require policy gate');
        $this->assertTrue((bool) $decision->evidence_required, 'finance must require evidence gate');
    }

    public function test_automation_intent_marks_tool_plan_and_policy_required(): void
    {
        $intent = app(IntentKernelService::class)->classify('automatize browser para extrair dados do site x');
        $decision = app(DomainRouterService::class)->route($intent);

        $this->assertSame('automation', $decision->primary_domain);
        $this->assertTrue((bool) $decision->policy_required, 'automation must require policy gate');
        $this->assertTrue((bool) $decision->tool_plan_required, 'automation must require tool plan');
    }
}
