<?php

namespace Tests\Feature\Ai\DomainRuntime;

use App\Services\Ai\DomainRuntime\DomainManifestRegistryService;
use App\Services\Ai\DomainRuntime\DomainMaturityAssessmentService;
use App\Services\Ai\DomainRuntime\DomainSeedManifests;
use Tests\Concerns\CreatesDomainRuntimeTables;
use Tests\TestCase;

class DomainRuntimeMaturityAssessmentTest extends TestCase
{
    use CreatesDomainRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDomainRuntimeTables();
        app(DomainManifestRegistryService::class)->seedDefaults(DomainSeedManifests::all());
    }

    protected function tearDown(): void
    {
        $this->dropDomainRuntimeTables();
        parent::tearDown();
    }

    public function test_stage_2_assessment_passes_for_seeded_specialist_domain(): void
    {
        $registry = app(DomainManifestRegistryService::class);
        $maturity = app(DomainMaturityAssessmentService::class);
        $manifest = $registry->findByDomainId('research');

        $assessment = $maturity->assess($manifest, 2);

        $this->assertSame('passed', $assessment->status);
        $this->assertNotNull($assessment->assessed_at);
        $this->assertSame([], $assessment->missing_requirements);
    }

    public function test_stage_4_is_blocked_without_evidence_metrics_and_certification(): void
    {
        $registry = app(DomainManifestRegistryService::class);
        $maturity = app(DomainMaturityAssessmentService::class);
        $manifest = $registry->findByDomainId('software');

        $assessment = $maturity->assess($manifest, 4);

        $this->assertSame('blocked', $assessment->status);
        $missing = collect($assessment->missing_requirements)->pluck('requirement')->all();
        $this->assertContains('evidence_refs_present_for_high_stage', $missing);
        $this->assertContains('metrics_recorded_for_high_stage', $missing);
        $this->assertContains('certification_hash_for_high_stage', $missing);
        $this->assertNull($assessment->assessed_at);
    }

    public function test_stage_5_passes_with_evidence_metrics_and_certification(): void
    {
        $registry = app(DomainManifestRegistryService::class);
        $maturity = app(DomainMaturityAssessmentService::class);
        $manifest = $registry->findByDomainId('software');

        $assessment = $maturity->assess($manifest, 5, [
            'evidence_refs' => ['ev:1', 'ev:2'],
            'metrics' => ['delivery_lead_time' => 3.2, 'review_pass_rate' => 0.96],
            'certification_hash' => str_repeat('a', 64),
        ]);

        $this->assertSame('passed', $assessment->status);
        $this->assertSame([], $assessment->missing_requirements);
        $this->assertSame(5, $manifest->refresh()->maturity_stage);
    }
}
