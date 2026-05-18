<?php

namespace Tests\Feature\Ai\FinanceDomain;

use App\Services\Ai\Finance\Kernel\FinanceDomainSmokeService;
use App\Services\Ai\Mission\MissionLifecycleService;
use Tests\Concerns\CreatesFinanceDomainTables;
use Tests\TestCase;

class FinanceDomainSmokeTest extends TestCase
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

    public function test_smoke_runs_full_company_runtime_end_to_end(): void
    {
        $report = app(FinanceDomainSmokeService::class)->run(null, 'AAPL');

        $this->assertTrue($report['ok'], 'smoke ok expected, got: '.json_encode($report));
        $this->assertSame('finance', $report['manifest']['domain_id']);
        $this->assertSame(MissionLifecycleService::STATUS_COMPLETED, $report['mission']['status']);
        $this->assertSame('passed', $report['certification']['status']);
        $this->assertSame('REVIEW', $report['sections']['compliance_decision']);
        $this->assertTrue($report['invariants']['live_trading_blocked_default']);
        $this->assertSame(64, strlen((string) $report['sections']['research_hash']));
        $this->assertSame(64, strlen((string) $report['sections']['brief_hash']));
    }

    public function test_smoke_command_returns_ok_via_artisan(): void
    {
        $exit = $this->artisan('atlas:ai:finance-domain', [
            '--action' => 'smoke',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);
    }
}
