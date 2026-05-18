<?php

namespace Tests\Feature\Ai\AutomationDomain;

use App\Services\Ai\AutomationDomain\AutomationControlPlaneProjection;
use App\Services\Ai\AutomationDomain\AutomationRuntimeService;
use Tests\Concerns\CreatesAutomationDomainTables;
use Tests\TestCase;

class AutomationDomainControlPlaneTest extends TestCase
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

    public function test_snapshot_shape_is_complete_after_smoke_run(): void
    {
        app(AutomationRuntimeService::class)->smokeRun();
        $snap = app(AutomationControlPlaneProjection::class)->snapshot();

        $this->assertSame('atlas.ai.automation_domain.control_plane.v1', $snap['schema']);
        $this->assertArrayHasKey('runs', $snap);
        $this->assertArrayHasKey('plans', $snap);
        $this->assertArrayHasKey('tool_decisions', $snap);
        $this->assertArrayHasKey('evolution_events', $snap);

        $this->assertSame('ready', $snap['runs']['status']);
        $this->assertGreaterThanOrEqual(1, $snap['runs']['count']);
        $this->assertGreaterThanOrEqual(4, $snap['plans']['count']);
        $this->assertGreaterThanOrEqual(2, $snap['plans']['blocked']);
        $this->assertGreaterThanOrEqual(1, $snap['tool_decisions']['count']);
        $this->assertGreaterThanOrEqual(1, $snap['evolution_events']['count']);
    }

    public function test_snapshot_reports_missing_block_when_table_dropped(): void
    {
        $this->dropAutomationDomainTables();

        $snap = app(AutomationControlPlaneProjection::class)->snapshot();

        $this->assertSame('missing', $snap['runs']['status']);
        $this->assertSame('missing', $snap['plans']['status']);
        $this->assertSame('missing', $snap['tool_decisions']['status']);
        $this->assertSame('missing', $snap['evolution_events']['status']);
    }
}
