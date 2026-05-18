<?php

namespace Tests\Feature\Ai\FinanceDomain;

use App\Services\Ai\Finance\Kernel\FinanceControlPlaneProjection;
use App\Services\Ai\Finance\Kernel\FinanceDomainManifestSeeder;
use App\Services\Ai\Finance\Kernel\FinanceRuntimeService;
use App\Services\Ai\Mission\MissionFactoryService;
use Tests\Concerns\CreatesFinanceDomainTables;
use Tests\TestCase;

class FinanceDomainControlPlaneTest extends TestCase
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

    public function test_control_plane_snapshot_exposes_invariants_and_finance_block(): void
    {
        app(FinanceDomainManifestSeeder::class)->seed();
        $mission = app(MissionFactoryService::class)->create('research AAPL', ['primary_domain' => 'finance']);
        app(FinanceRuntimeService::class)->open($mission, 'finance.research_desk');

        $snapshot = app(FinanceControlPlaneProjection::class)->snapshot();

        $this->assertSame(FinanceControlPlaneProjection::SCHEMA, $snapshot['schema']);
        $this->assertSame('finance', $snapshot['domain_id']);
        $this->assertTrue($snapshot['invariants']['live_trading_blocked_default']);
        $this->assertFalse($snapshot['invariants']['auto_rebalance_allowed']);
        $this->assertFalse($snapshot['invariants']['broker_execution_allowed']);
        $this->assertSame(1, $snapshot['finance']['missions_total']);
        $this->assertGreaterThanOrEqual(1, array_sum($snapshot['finance']['runtime_records_by_status']));
    }
}
