<?php

namespace Tests\Feature\Ai\DomainRuntime;

use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainRuntimeReadinessService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class DomainRuntimeReadinessTest extends TestCase
{
    use CreatesDomainRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDomainRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropDomainRuntimeTables();
        parent::tearDown();
    }

    public function test_readiness_reports_seed_missing_until_seed_runs(): void
    {
        $report = app(DomainRuntimeReadinessService::class)->report();

        $this->assertIsArray($report['checks']);
        $seedCheck = collect($report['checks'])->firstWhere('name', 'seed:default_manifests');
        $this->assertNotNull($seedCheck);
        $this->assertSame('failed', $seedCheck['status']);
        $this->assertFalse($report['ok']);
    }

    public function test_readiness_passes_after_seeding_defaults(): void
    {
        app(DomainManifestRegistryService::class)->seedDefaults(DomainSeedManifests::all());

        $report = app(DomainRuntimeReadinessService::class)->report();

        $this->assertTrue($report['ok'], 'expected readiness ok=true after seed, summary='.json_encode($report['summary']));
        $this->assertSame(0, $report['summary']['failed']);
    }
}
