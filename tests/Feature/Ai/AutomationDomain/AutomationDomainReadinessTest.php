<?php

namespace Tests\Feature\Ai\AutomationDomain;

use App\Services\Ai\AutomationDomain\AutomationReadinessService;
use Tests\Concerns\CreatesAutomationDomainTables;
use Tests\TestCase;

class AutomationDomainReadinessTest extends TestCase
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

    public function test_readiness_reports_ok_when_all_automation_tables_and_services_resolvable(): void
    {
        $payload = app(AutomationReadinessService::class)->report();

        $this->assertTrue($payload['ok'], 'expected readiness ok=true when tables and services are present, got: '.json_encode($payload['checks']));
        $this->assertSame('atlas.ai.automation_domain.readiness.v1', $payload['schema']);
        $this->assertSame(0, $payload['summary']['failed']);
        $this->assertGreaterThanOrEqual(20, $payload['summary']['total']);

        $names = collect($payload['checks'])->pluck('name')->all();
        $this->assertContains('table:ai_automation_runs', $names);
        $this->assertContains('service:AutomationRuntimeService', $names);
        $this->assertContains('service:ToolSelectionWorkflowService', $names);
        $this->assertContains('service:BrowserAutomationPlanningService', $names);
        $this->assertContains('service:ApiAutomationPlanningService', $names);
        $this->assertContains('service:TerminalAutomationPlanningService', $names);
        $this->assertContains('service:ToolBuilderPlanService', $names);
        $this->assertContains('service:ToolEvolutionLoopService', $names);
        $this->assertContains('service:AutomationControlPlaneProjection', $names);
        $this->assertContains('canon:automation_enums', $names);
    }

    public function test_readiness_reports_failure_when_required_table_is_missing(): void
    {
        $this->dropAutomationDomainTables();

        $payload = app(AutomationReadinessService::class)->report();

        $this->assertFalse($payload['ok']);
        $this->assertGreaterThan(0, $payload['summary']['failed']);
        $tableChecks = collect($payload['checks'])->where('name', 'table:ai_automation_runs')->first();
        $this->assertSame('failed', $tableChecks['status']);
    }
}
