<?php

namespace Tests\Feature\Ai\ResearchDomain;

use App\Services\Ai\ResearchDomain\ResearchControlPlaneProjection;
use App\Services\Ai\ResearchDomain\ResearchRuntimeService;
use Tests\Concerns\CreatesResearchDomainTables;
use Tests\TestCase;

class ResearchControlPlaneTest extends TestCase
{
    use CreatesResearchDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createResearchDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropResearchDomainTables();
        parent::tearDown();
    }

    public function test_snapshot_includes_runs_sources_claims_syntheses(): void
    {
        app(ResearchRuntimeService::class)->smokeRun();
        $snapshot = app(ResearchControlPlaneProjection::class)->snapshot();

        $this->assertSame('atlas.ai.research_domain.control_plane.v1', $snapshot['schema']);
        foreach (['runs', 'sources', 'claims', 'syntheses'] as $section) {
            $this->assertArrayHasKey($section, $snapshot);
            $this->assertArrayHasKey('count', $snapshot[$section]);
        }
        $this->assertSame(1, $snapshot['runs']['count']);
        $this->assertSame(3, $snapshot['sources']['count']);
        $this->assertSame(1, $snapshot['claims']['count']);
        $this->assertSame(1, $snapshot['syntheses']['count']);
        $this->assertArrayHasKey('rejected_reasons', $snapshot['sources']);
        $this->assertArrayHasKey('vendor_bias_high', $snapshot['sources']['rejected_reasons']);
    }
}
