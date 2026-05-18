<?php

namespace Tests\Feature\Ai\ResearchDomain;

use App\Services\Ai\ResearchDomain\ResearchDomainCanon;
use App\Services\Ai\ResearchDomain\ResearchSourcePlanService;
use App\Services\Ai\ResearchDomain\ResearchSourceQualityService;
use Tests\Concerns\CreatesResearchDomainTables;
use Tests\TestCase;

class ResearchSourceQualityTest extends TestCase
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

    public function test_vendor_bias_source_is_rejected_with_reason(): void
    {
        $run = app(ResearchSourcePlanService::class)->plan(
            'q',
            [
                ['source_type' => ResearchDomainCanon::SOURCE_BLOG, 'source_ref' => 'https://example.com/vendor-blog'],
            ],
        );
        $source = $run->sources()->first();
        $scored = app(ResearchSourceQualityService::class)->score($source, [
            'vendor_bias' => true,
            'recency_days' => 30,
        ]);

        $this->assertSame(ResearchDomainCanon::SOURCE_STATUS_REJECTED, $scored->status);
        $this->assertSame('vendor_bias_high', $scored->reason_rejected);
        $this->assertLessThan(0.40, (float) $scored->source_quality);
    }

    public function test_primary_peer_reviewed_source_is_accepted_with_high_score(): void
    {
        $run = app(ResearchSourcePlanService::class)->plan(
            'q',
            [
                ['source_type' => ResearchDomainCanon::SOURCE_ACADEMIC, 'source_ref' => 'arxiv:2310.00000'],
            ],
        );
        $source = $run->sources()->first();
        $scored = app(ResearchSourceQualityService::class)->score($source, [
            'peer_reviewed' => true,
            'primary' => true,
            'recency_days' => 200,
            'domain_authority' => 0.9,
        ]);

        $this->assertSame(ResearchDomainCanon::SOURCE_STATUS_ACCEPTED, $scored->status);
        $this->assertGreaterThanOrEqual(0.80, (float) $scored->source_quality);
    }

    public function test_score_run_updates_diversity(): void
    {
        $run = app(ResearchSourcePlanService::class)->plan(
            'q',
            [
                ['source_type' => ResearchDomainCanon::SOURCE_ACADEMIC, 'source_ref' => 'arxiv:1'],
                ['source_type' => ResearchDomainCanon::SOURCE_STANDARD, 'source_ref' => 'iso:1'],
                ['source_type' => ResearchDomainCanon::SOURCE_BLOG, 'source_ref' => 'https://vendor.example/bad'],
            ],
        );
        $sources = $run->sources()->orderBy('created_at')->get();
        app(ResearchSourceQualityService::class)->scoreRun($run, [
            $sources[0]->citation_hash => ['peer_reviewed' => true, 'primary' => true, 'recency_days' => 100, 'domain_authority' => 0.9],
            $sources[1]->citation_hash => ['primary' => true, 'recency_days' => 500, 'domain_authority' => 0.8],
            $sources[2]->citation_hash => ['vendor_bias' => true],
        ]);
        $run->refresh();
        $this->assertNotNull($run->source_diversity);
        $this->assertSame(2, $run->sources()->where('status', ResearchDomainCanon::SOURCE_STATUS_ACCEPTED)->count());
        $this->assertSame(1, $run->sources()->where('status', ResearchDomainCanon::SOURCE_STATUS_REJECTED)->count());
    }
}
