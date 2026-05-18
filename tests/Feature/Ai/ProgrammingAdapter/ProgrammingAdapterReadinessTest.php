<?php

namespace Tests\Feature\Ai\ProgrammingAdapter;

use App\Services\Ai\Programming\Kernel\ProgrammingAdapterReadinessService;
use App\Services\Ai\Programming\Kernel\ProgrammingDomainManifestSeeder;
use Tests\Concerns\CreatesProgrammingAdapterTables;
use Tests\TestCase;

class ProgrammingAdapterReadinessTest extends TestCase
{
    use CreatesProgrammingAdapterTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProgrammingAdapterTables();
    }

    protected function tearDown(): void
    {
        $this->dropProgrammingAdapterTables();
        parent::tearDown();
    }

    public function test_readiness_fails_until_programming_manifest_seeded(): void
    {
        $report = app(ProgrammingAdapterReadinessService::class)->report();

        $manifestCheck = collect($report['checks'])->firstWhere('name', 'seed:programming_manifest');
        $this->assertNotNull($manifestCheck);
        $this->assertSame('failed', $manifestCheck['status']);
        $this->assertFalse($report['ok']);
    }

    public function test_readiness_passes_after_seeding_manifest(): void
    {
        app(ProgrammingDomainManifestSeeder::class)->seed();

        $report = app(ProgrammingAdapterReadinessService::class)->report();

        $this->assertTrue($report['ok'], 'readiness summary='.json_encode($report['summary']));
        $this->assertSame(0, $report['summary']['failed']);
        $bridgeNames = collect($report['checks'])->pluck('name')->all();
        foreach (['bridge:policy_runtime', 'bridge:mission_evidence', 'bridge:evidence_runtime', 'bridge:tool_runtime'] as $expected) {
            $this->assertContains($expected, $bridgeNames);
        }
    }
}
