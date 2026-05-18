<?php

namespace Tests\Feature\Ai\ResearchDomain;

use App\Services\Ai\ResearchDomain\ResearchClaimService;
use App\Services\Ai\ResearchDomain\ResearchDomainCanon;
use App\Services\Ai\ResearchDomain\ResearchSourcePlanService;
use App\Services\Ai\ResearchDomain\ResearchSourceQualityService;
use App\Services\Ai\ResearchDomain\ResearchSynthesisService;
use Tests\Concerns\CreatesResearchDomainTables;
use Tests\TestCase;

class ResearchSynthesisCertificationTest extends TestCase
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

    public function test_certification_fails_when_no_accepted_sources(): void
    {
        $run = app(ResearchSourcePlanService::class)->plan(
            'q',
            [['source_type' => ResearchDomainCanon::SOURCE_BLOG, 'source_ref' => 'https://vendor.example/']],
        );
        $source = $run->sources()->first();
        app(ResearchSourceQualityService::class)->score($source, ['vendor_bias' => true]);

        $synthesis = app(ResearchSynthesisService::class)->synthesize(
            $run->refresh(),
            'Synthesis without accepted sources',
        );

        $run->refresh();
        $this->assertSame(ResearchDomainCanon::CERT_FAILED, $run->certification_status);
        $this->assertNotEmpty($run->missing_requirements);
        $this->assertContains('accepted_sources_below_minimum', $run->missing_requirements);
        $this->assertNull($run->completed_at);
        $this->assertSame(64, strlen((string) $synthesis->synthesis_hash));
    }

    public function test_certification_fails_when_diversity_below_minimum(): void
    {
        $run = app(ResearchSourcePlanService::class)->plan(
            'q',
            [
                ['source_type' => ResearchDomainCanon::SOURCE_ACADEMIC, 'source_ref' => 'arxiv:1'],
                ['source_type' => ResearchDomainCanon::SOURCE_ACADEMIC, 'source_ref' => 'arxiv:2'],
            ],
        );
        $sources = $run->sources()->orderBy('created_at')->get();
        app(ResearchSourceQualityService::class)->scoreRun($run, [
            $sources[0]->citation_hash => ['peer_reviewed' => true, 'primary' => true, 'recency_days' => 100, 'domain_authority' => 0.9],
            $sources[1]->citation_hash => ['peer_reviewed' => true, 'primary' => true, 'recency_days' => 200, 'domain_authority' => 0.85],
        ]);
        $sources = $run->refresh()->sources()->orderBy('created_at')->get();

        app(ResearchClaimService::class)->record(
            $run,
            'claim',
            [$sources[0]->citation_hash, $sources[1]->citation_hash],
            0.8,
        );

        $synthesis = app(ResearchSynthesisService::class)->synthesize($run->refresh(), 'brief');
        $run->refresh();

        $this->assertSame(ResearchDomainCanon::CERT_FAILED, $run->certification_status);
        $this->assertContains('source_diversity_below_minimum', $run->missing_requirements);
        $this->assertNotEmpty($synthesis->synthesis_hash);
    }

    public function test_certification_passes_with_diverse_sources_attributed_claim_and_no_contradiction(): void
    {
        $run = app(ResearchSourcePlanService::class)->plan(
            'q',
            [
                ['source_type' => ResearchDomainCanon::SOURCE_ACADEMIC, 'source_ref' => 'arxiv:1'],
                ['source_type' => ResearchDomainCanon::SOURCE_STANDARD, 'source_ref' => 'iso:1'],
            ],
        );
        $sources = $run->sources()->orderBy('created_at')->get();
        app(ResearchSourceQualityService::class)->scoreRun($run, [
            $sources[0]->citation_hash => ['peer_reviewed' => true, 'primary' => true, 'recency_days' => 100, 'domain_authority' => 0.9],
            $sources[1]->citation_hash => ['primary' => true, 'recency_days' => 500, 'domain_authority' => 0.85],
        ]);
        $sources = $run->refresh()->sources()->orderBy('created_at')->get();

        app(ResearchClaimService::class)->record(
            $run,
            'Diverse sourcing improves robustness',
            [$sources[0]->citation_hash, $sources[1]->citation_hash],
            0.85,
        );

        $synthesis = app(ResearchSynthesisService::class)->synthesize(
            $run->refresh(),
            'Brief: diverse sourcing improves robustness.',
        );
        $run->refresh();

        $this->assertSame(ResearchDomainCanon::CERT_PASSED, $run->certification_status);
        $this->assertSame(ResearchDomainCanon::STATUS_COMPLETED, $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertNotEmpty($run->certification_hash);
        $this->assertNotEmpty($run->evidence_pack_hash);
        $this->assertNotEmpty($synthesis->evidence_refs);
        $this->assertArrayHasKey('sources_accepted', $synthesis->evidence_refs);
        $this->assertArrayHasKey('sources_rejected', $synthesis->evidence_refs);
    }
}
