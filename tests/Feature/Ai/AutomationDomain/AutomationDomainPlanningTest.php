<?php

namespace Tests\Feature\Ai\AutomationDomain;

use App\Services\Ai\AutomationDomain\ApiAutomationPlanningService;
use App\Services\Ai\AutomationDomain\AutomationDomainCanon;
use App\Services\Ai\AutomationDomain\AutomationDomainException;
use App\Services\Ai\AutomationDomain\AutomationRuntimeService;
use App\Services\Ai\AutomationDomain\BrowserAutomationPlanningService;
use App\Services\Ai\AutomationDomain\TerminalAutomationPlanningService;
use Tests\Concerns\CreatesAutomationDomainTables;
use Tests\TestCase;

class AutomationDomainPlanningTest extends TestCase
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

    public function test_browser_plan_blocks_when_auth_or_tos_or_anti_bot_flagged(): void
    {
        $run = app(AutomationRuntimeService::class)->startRun([
            'run_kind' => AutomationDomainCanon::RUN_BROWSER,
            'objective' => 'plan browser automation',
        ]);

        $plan = app(BrowserAutomationPlanningService::class)->plan($run, [
            'target_url' => 'https://example.com/private',
            'auth_required' => true,
        ]);

        $this->assertSame(AutomationDomainCanon::PLAN_BROWSER, $plan->plan_type);
        $this->assertSame(AutomationDomainCanon::PLAN_STATUS_BLOCKED, $plan->status);
        $this->assertTrue((bool) $plan->safety_factors['auth_required']);
    }

    public function test_browser_plan_planned_when_public_and_no_auth(): void
    {
        $run = app(AutomationRuntimeService::class)->startRun([
            'run_kind' => AutomationDomainCanon::RUN_BROWSER,
            'objective' => 'plan public browser fetch',
        ]);

        $plan = app(BrowserAutomationPlanningService::class)->plan($run, [
            'target_url' => 'https://example.com/public',
            'auth_required' => false,
            'tos_restrictive' => false,
            'anti_bot_protection' => false,
        ]);

        $this->assertSame(AutomationDomainCanon::PLAN_STATUS_PLANNED, $plan->status);
    }

    public function test_api_plan_blocks_writes_and_planned_for_read_only_public(): void
    {
        $run = app(AutomationRuntimeService::class)->startRun([
            'run_kind' => AutomationDomainCanon::RUN_API,
            'objective' => 'plan API automation',
        ]);

        $write = app(ApiAutomationPlanningService::class)->plan($run, [
            'endpoint' => 'https://api.example.com/items',
            'method' => 'POST',
            'auth_required' => true,
        ]);
        $this->assertSame(AutomationDomainCanon::PLAN_STATUS_BLOCKED, $write->status);

        $read = app(ApiAutomationPlanningService::class)->plan($run, [
            'endpoint' => 'https://api.example.com/items',
            'method' => 'GET',
            'auth_required' => false,
        ]);
        $this->assertSame(AutomationDomainCanon::PLAN_STATUS_PLANNED, $read->status);
    }

    public function test_terminal_plan_blocks_destructive_commands(): void
    {
        $run = app(AutomationRuntimeService::class)->startRun([
            'run_kind' => AutomationDomainCanon::RUN_TERMINAL,
            'objective' => 'plan terminal automation',
        ]);

        $destructive = app(TerminalAutomationPlanningService::class)->plan($run, [
            'command' => 'rm -rf /tmp/data',
            'working_dir' => '/tmp',
        ]);
        $this->assertSame(AutomationDomainCanon::PLAN_STATUS_BLOCKED, $destructive->status);
        $this->assertTrue((bool) $destructive->safety_factors['destructive']);

        $readOnly = app(TerminalAutomationPlanningService::class)->plan($run, [
            'command' => 'ls -la docs/',
            'working_dir' => '/Users/x/atlas',
        ]);
        $this->assertSame(AutomationDomainCanon::PLAN_STATUS_PLANNED, $readOnly->status);
        $this->assertFalse((bool) $readOnly->safety_factors['destructive']);
    }

    public function test_terminal_plan_blocks_network_io_commands(): void
    {
        $run = app(AutomationRuntimeService::class)->startRun([
            'run_kind' => AutomationDomainCanon::RUN_TERMINAL,
            'objective' => 'plan with network',
        ]);

        $plan = app(TerminalAutomationPlanningService::class)->plan($run, [
            'command' => 'curl -X POST https://example.com/api',
            'working_dir' => '/Users/x/atlas',
        ]);
        $this->assertSame(AutomationDomainCanon::PLAN_STATUS_BLOCKED, $plan->status);
        $this->assertTrue((bool) $plan->safety_factors['network_io']);
    }

    public function test_planning_services_reject_payloads_with_missing_fields(): void
    {
        $run = app(AutomationRuntimeService::class)->startRun([
            'run_kind' => AutomationDomainCanon::RUN_API,
            'objective' => 'reject empty',
        ]);

        $this->expectException(AutomationDomainException::class);
        app(ApiAutomationPlanningService::class)->plan($run, [
            'method' => 'GET',
        ]);
    }
}
