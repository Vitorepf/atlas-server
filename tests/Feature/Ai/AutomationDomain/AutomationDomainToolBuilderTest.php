<?php

namespace Tests\Feature\Ai\AutomationDomain;

use App\Services\Ai\AutomationDomain\AutomationDomainCanon;
use App\Services\Ai\AutomationDomain\AutomationDomainException;
use App\Services\Ai\AutomationDomain\AutomationRuntimeService;
use App\Services\Ai\AutomationDomain\ToolBuilderPlanService;
use Tests\Concerns\CreatesAutomationDomainTables;
use Tests\TestCase;

class AutomationDomainToolBuilderTest extends TestCase
{
    use CreatesAutomationDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAutomationDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropAutomationDomainTables();
        parent::tearDown();
    }

    public function test_builder_blueprint_blocks_high_risk_external_action(): void
    {
        $run = app(AutomationRuntimeService::class)->startRun([
            'run_kind' => AutomationDomainCanon::RUN_TOOL_BUILD,
            'objective' => 'Plan a Helm deploy tool',
        ]);

        $plan = app(ToolBuilderPlanService::class)->plan($run, [
            'tool_id' => 'atlas.demo.deploy_helm',
            'name' => 'Atlas demo Helm deployer',
            'purpose' => 'Deploy a demo chart to non-prod',
            'risk_level' => 'high',
            'external_action' => true,
            'requires_credentials' => true,
        ]);

        $this->assertSame(AutomationDomainCanon::PLAN_TOOL_BUILDER, $plan->plan_type);
        $this->assertSame(AutomationDomainCanon::PLAN_STATUS_BLOCKED, $plan->status);
        $this->assertSame('atlas.demo.deploy_helm', $plan->payload['tool_id']);
        $this->assertNotEmpty($plan->plan_hash);
    }

    public function test_builder_blueprint_planned_for_workspace_safe_low_risk(): void
    {
        $run = app(AutomationRuntimeService::class)->startRun([
            'run_kind' => AutomationDomainCanon::RUN_TOOL_BUILD,
            'objective' => 'Plan a docs lint tool',
        ]);

        $plan = app(ToolBuilderPlanService::class)->plan($run, [
            'tool_id' => 'atlas.docs.lint',
            'name' => 'Atlas docs linter',
            'purpose' => 'Lint markdown files in workspace',
            'risk_level' => 'low',
        ]);

        $this->assertSame(AutomationDomainCanon::PLAN_STATUS_PLANNED, $plan->status);
    }

    public function test_builder_rejects_invalid_tool_id(): void
    {
        $run = app(AutomationRuntimeService::class)->startRun([
            'run_kind' => AutomationDomainCanon::RUN_TOOL_BUILD,
            'objective' => 'invalid id',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(ToolBuilderPlanService::class)->plan($run, [
            'tool_id' => 'INVALID Tool ID',
            'name' => 'Bad',
            'purpose' => 'bad',
        ]);
    }

    public function test_builder_rejects_missing_purpose(): void
    {
        $run = app(AutomationRuntimeService::class)->startRun([
            'run_kind' => AutomationDomainCanon::RUN_TOOL_BUILD,
            'objective' => 'missing purpose',
        ]);

        $this->expectException(AutomationDomainException::class);
        app(ToolBuilderPlanService::class)->plan($run, [
            'tool_id' => 'atlas.demo.x',
            'name' => 'X',
        ]);
    }
}
