<?php

namespace Tests\Feature\Ai\ResearchDomain;

use App\Services\Ai\ResearchDomain\ResearchRuntimeService;
use Tests\Concerns\CreatesResearchDomainTables;
use Tests\TestCase;

class ResearchEvidenceBridgeTest extends TestCase
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

    public function test_evidence_pack_in_synthesis_includes_accepted_and_rejected_sources_and_claim_refs(): void
    {
        $payload = app(ResearchRuntimeService::class)->smokeRun();
        $synthesis = $payload['synthesis'];

        $pack = $synthesis->evidence_refs;
        $this->assertIsArray($pack);
        $this->assertSame(2, count($pack['sources_accepted']));
        $this->assertSame(1, count($pack['sources_rejected']));
        $this->assertSame(1, count($pack['claims']));
        $this->assertSame($synthesis->synthesis_hash, $pack['synthesis_hash']);
    }
}
