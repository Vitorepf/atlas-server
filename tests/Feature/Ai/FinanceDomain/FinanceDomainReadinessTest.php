<?php

namespace Tests\Feature\Ai\FinanceDomain;

use App\Services\Ai\Finance\Kernel\FinanceDomainManifestSeeder;
use App\Services\Ai\Finance\Kernel\FinanceDomainReadinessService;
use Tests\Concerns\CreatesFinanceDomainTables;
use Tests\TestCase;

class FinanceDomainReadinessTest extends TestCase
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

    public function test_readiness_fails_until_finance_manifest_seeded(): void
    {
        $report = app(FinanceDomainReadinessService::class)->report();

        $seedCheck = collect($report['checks'])->firstWhere('name', 'seed:finance_manifest');
        $this->assertNotNull($seedCheck);
        $this->assertSame('failed', $seedCheck['status']);
        $this->assertFalse($report['ok']);
    }

    public function test_readiness_passes_after_seeding_finance_manifest(): void
    {
        app(FinanceDomainManifestSeeder::class)->seed();

        $report = app(FinanceDomainReadinessService::class)->report();

        $this->assertTrue($report['ok'], 'readiness summary='.json_encode($report['summary']));
        $this->assertSame(0, $report['summary']['failed']);
        $this->assertTrue($report['invariants']['live_trading_blocked_default']);
    }
}
