<?php

namespace Tests\Feature\Ai\ResearchDomain;

use App\Services\Ai\ResearchDomain\ResearchReadinessService;
use Tests\Concerns\CreatesResearchDomainTables;
use Tests\TestCase;

class ResearchDomainReadinessTest extends TestCase
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

    public function test_readiness_ok_when_tables_present(): void
    {
        $report = app(ResearchReadinessService::class)->report();

        $this->assertTrue($report['ok'], 'expected readiness ok=true, got: '.json_encode($report['summary']));
        $this->assertSame('atlas.ai.research_domain.readiness.v1', $report['schema']);
        $this->assertSame(0, $report['summary']['failed']);
    }

    public function test_readiness_lists_required_checks(): void
    {
        $names = collect(app(ResearchReadinessService::class)->report()['checks'])
            ->pluck('name')->all();

        foreach ([
            'table:ai_research_runs',
            'table:ai_research_sources',
            'table:ai_research_claims',
            'table:ai_research_syntheses',
            'service:ResearchRuntimeService',
            'service:ResearchSourcePlanService',
            'service:ResearchSourceQualityService',
            'service:ResearchClaimService',
            'service:ResearchSynthesisService',
            'service:ResearchEvidenceBridge',
            'service:ResearchControlPlaneProjection',
            'canon:research_enums',
            'bridge:mission_evidence_tolerant',
        ] as $expected) {
            $this->assertContains($expected, $names, "missing readiness check [{$expected}]");
        }
    }

    public function test_readiness_fails_when_table_missing(): void
    {
        $this->dropResearchDomainTables();
        $report = app(ResearchReadinessService::class)->report();
        $this->assertFalse($report['ok']);
        $this->assertGreaterThan(0, $report['summary']['failed']);
    }
}
