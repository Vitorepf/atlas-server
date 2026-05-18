<?php

namespace Tests\Feature\Ai\AutomationDomain;

use App\Services\Ai\AutomationDomain\AutomationDomainCanon;
use App\Services\Ai\AutomationDomain\AutomationRuntimeService;
use Tests\Concerns\CreatesAutomationDomainTables;
use Tests\TestCase;

class AutomationDomainSmokeTest extends TestCase
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

    public function test_smoke_run_emits_four_plans_blocks_risky_plans_and_records_evolution_event(): void
    {
        $result = app(AutomationRuntimeService::class)->smokeRun();

        $run = $result['run'];
        $summary = $result['summary'];

        $this->assertSame(AutomationDomainCanon::STATUS_CLOSED, $run->status);
        $this->assertSame(AutomationDomainCanon::RUN_TOOL_BUILD, $run->run_kind);
        $this->assertNotEmpty($run->receipt_hash);

        // 4 plans: api, terminal, browser, tool_builder
        $this->assertCount(4, $summary['plans']);

        // Browser (auth_required + tos_restrictive + anti_bot) MUST be blocked
        $this->assertSame(AutomationDomainCanon::PLAN_STATUS_BLOCKED, $summary['plans']['browser']['status']);
        // Tool builder with risk=high MUST be blocked
        $this->assertSame(AutomationDomainCanon::PLAN_STATUS_BLOCKED, $summary['plans']['tool_builder']['status']);

        // Read-only api and terminal plans either get approved by the local
        // policy fallback or stay planned — never silently blocked.
        $this->assertContains(
            $summary['plans']['api']['status'],
            [AutomationDomainCanon::PLAN_STATUS_APPROVED, AutomationDomainCanon::PLAN_STATUS_PLANNED]
        );
        $this->assertContains(
            $summary['plans']['terminal']['status'],
            [AutomationDomainCanon::PLAN_STATUS_APPROVED, AutomationDomainCanon::PLAN_STATUS_PLANNED]
        );

        // Tool decision MUST exist and prefer the official API for low-risk needs
        $this->assertContains(
            $summary['tool_decision_kind'],
            [AutomationDomainCanon::DECISION_USE_EXISTING, AutomationDomainCanon::DECISION_API_CALL]
        );

        // Evolution event recorded
        $this->assertSame(AutomationDomainCanon::EVOLUTION_SUCCESS_STREAK, $summary['evolution']['event_kind']);
        $this->assertSame(AutomationDomainCanon::EVOLUTION_RECOMMENDATION_KEEP, $summary['evolution']['recommendation']);

        // At least one plan blocked
        $this->assertGreaterThanOrEqual(2, $summary['plans_blocked']);
    }

    public function test_smoke_run_is_deterministic_across_two_invocations(): void
    {
        $first = app(AutomationRuntimeService::class)->smokeRun();
        $second = app(AutomationRuntimeService::class)->smokeRun();

        // Plan hashes are deterministic given the same inputs even across runs.
        $this->assertSame(
            $first['summary']['plans']['browser']['hash'],
            $second['summary']['plans']['browser']['hash']
        );
        $this->assertSame(
            $first['summary']['plans']['terminal']['hash'],
            $second['summary']['plans']['terminal']['hash']
        );
    }
}
