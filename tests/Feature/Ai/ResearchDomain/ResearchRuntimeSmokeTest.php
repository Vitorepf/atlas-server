<?php

namespace Tests\Feature\Ai\ResearchDomain;

use App\Services\Ai\ResearchDomain\ResearchDomainCanon;
use App\Services\Ai\ResearchDomain\ResearchRuntimeService;
use Tests\Concerns\CreatesResearchDomainTables;
use Tests\TestCase;

class ResearchRuntimeSmokeTest extends TestCase
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

    public function test_smoke_run_certifies_with_two_diverse_sources_and_one_rejected(): void
    {
        $payload = app(ResearchRuntimeService::class)->smokeRun();
        $run = $payload['run'];
        $synthesis = $payload['synthesis'];

        $this->assertSame(ResearchDomainCanon::CERT_PASSED, $run->certification_status);
        $this->assertSame(ResearchDomainCanon::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $run->sources()->where('status', ResearchDomainCanon::SOURCE_STATUS_ACCEPTED)->count());
        $this->assertSame(1, $run->sources()->where('status', ResearchDomainCanon::SOURCE_STATUS_REJECTED)->count());
        $this->assertNotEmpty($synthesis->synthesis_hash);
        $this->assertArrayHasKey('sources_accepted', $synthesis->evidence_refs);
    }
}
