<?php

namespace Tests\Feature\Ai\ResearchDomain;

use App\Models\AiResearchSource;
use App\Services\Ai\ResearchDomain\ResearchDomainCanon;
use App\Services\Ai\ResearchDomain\ResearchSourcePlanService;
use Tests\Concerns\CreatesResearchDomainTables;
use Tests\TestCase;

class ResearchSourcePlanTest extends TestCase
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

    public function test_plan_persists_run_with_source_candidates(): void
    {
        $run = app(ResearchSourcePlanService::class)->plan(
            'How does prompt caching change provider economics?',
            [
                ['source_type' => ResearchDomainCanon::SOURCE_PRIMARY, 'source_ref' => 'https://www.anthropic.com/news/prompt-caching'],
                ['source_type' => ResearchDomainCanon::SOURCE_ACADEMIC, 'source_ref' => 'arxiv:2310.00000'],
            ],
        );

        $this->assertSame(ResearchDomainCanon::STATUS_PLANNED, $run->status);
        $this->assertSame(2, AiResearchSource::query()->where('research_run_id', $run->id)->count());
        $this->assertSame(2, count((array) $run->source_plan));
        $this->assertEquals(2, $run->sources()->where('status', ResearchDomainCanon::SOURCE_STATUS_PLANNED)->count());
    }
}
