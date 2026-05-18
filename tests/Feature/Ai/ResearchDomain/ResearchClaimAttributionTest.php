<?php

namespace Tests\Feature\Ai\ResearchDomain;

use App\Services\Ai\ResearchDomain\ResearchClaimService;
use App\Services\Ai\ResearchDomain\ResearchDomainCanon;
use App\Services\Ai\ResearchDomain\ResearchSourcePlanService;
use App\Services\Ai\ResearchDomain\ResearchSourceQualityService;
use InvalidArgumentException;
use Tests\Concerns\CreatesResearchDomainTables;
use Tests\TestCase;

class ResearchClaimAttributionTest extends TestCase
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

    public function test_claim_record_requires_at_least_one_source_citation(): void
    {
        $run = app(ResearchSourcePlanService::class)->plan('q', []);
        $this->expectException(InvalidArgumentException::class);
        app(ResearchClaimService::class)->record($run, 'inference without source', []);
    }

    public function test_claim_rejects_unknown_citation_hash(): void
    {
        $run = app(ResearchSourcePlanService::class)->plan(
            'q',
            [['source_type' => ResearchDomainCanon::SOURCE_ACADEMIC, 'source_ref' => 'arxiv:1']],
        );
        $this->expectException(InvalidArgumentException::class);
        app(ResearchClaimService::class)->record($run, 'spurious claim', ['unknown_hash_aaa']);
    }

    public function test_claim_rejects_rejected_source_citations(): void
    {
        $run = app(ResearchSourcePlanService::class)->plan(
            'q',
            [['source_type' => ResearchDomainCanon::SOURCE_BLOG, 'source_ref' => 'https://vendor.example/']],
        );
        $source = $run->sources()->first();
        app(ResearchSourceQualityService::class)->score($source, ['vendor_bias' => true]);

        $this->expectException(InvalidArgumentException::class);
        app(ResearchClaimService::class)->record($run, 'biased claim', [$source->citation_hash]);
    }

    public function test_claim_is_recorded_with_supported_status_when_source_accepted(): void
    {
        $run = app(ResearchSourcePlanService::class)->plan(
            'q',
            [['source_type' => ResearchDomainCanon::SOURCE_ACADEMIC, 'source_ref' => 'arxiv:1']],
        );
        $source = $run->sources()->first();
        app(ResearchSourceQualityService::class)->score($source, [
            'peer_reviewed' => true, 'primary' => true, 'recency_days' => 100, 'domain_authority' => 0.9,
        ]);

        $claim = app(ResearchClaimService::class)->record(
            $run,
            'Proper attribution improves trust.',
            [$source->fresh()->citation_hash],
            0.8,
        );

        $this->assertSame(ResearchDomainCanon::CLAIM_SUPPORTED, $claim->claim_status);
        $this->assertSame(ResearchDomainCanon::CONTRADICTION_NONE, $claim->contradiction_status);
        $this->assertSame(64, strlen((string) $claim->claim_hash));
    }
}
