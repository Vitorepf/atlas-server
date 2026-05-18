<?php

namespace Tests\Feature\Ai\ResearchDomain;

use App\Services\Ai\ResearchDomain\ResearchClaimService;
use App\Services\Ai\ResearchDomain\ResearchDomainCanon;
use App\Services\Ai\ResearchDomain\ResearchSourcePlanService;
use App\Services\Ai\ResearchDomain\ResearchSourceQualityService;
use Tests\Concerns\CreatesResearchDomainTables;
use Tests\TestCase;

class ResearchContradictionCheckTest extends TestCase
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

    public function test_contradiction_check_flags_opposing_claims(): void
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

        $svc = app(ResearchClaimService::class);
        $a = $svc->record($run, 'X is true', [$sources[0]->citation_hash], 0.8, ['assertion' => 'x_is_true']);
        $b = $svc->record($run, 'not X', [$sources[1]->citation_hash], 0.7, ['assertion' => 'not x_is_true']);

        $claims = $svc->runContradictionCheck($run);
        $byHash = $claims->keyBy('claim_hash');
        $this->assertSame(
            ResearchDomainCanon::CONTRADICTION_DETECTED,
            $byHash[$a->claim_hash]->contradiction_status,
        );
        $this->assertSame(
            ResearchDomainCanon::CONTRADICTION_DETECTED,
            $byHash[$b->claim_hash]->contradiction_status,
        );
        $this->assertContains($b->claim_hash, $byHash[$a->claim_hash]->contradiction_refs);
        $this->assertContains($a->claim_hash, $byHash[$b->claim_hash]->contradiction_refs);
    }

    public function test_declare_contradiction_keeps_state_with_reason(): void
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
            'open question claim',
            [$source->fresh()->citation_hash],
            0.5,
            ['assertion' => 'open_question'],
        );
        $declared = app(ResearchClaimService::class)->declareContradiction($claim, 'open_research_question');
        $this->assertSame(ResearchDomainCanon::CONTRADICTION_DECLARED, $declared->contradiction_status);
        $this->assertSame('open_research_question', $declared->metadata['declared_contradiction_reason']);
    }
}
