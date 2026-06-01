<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProviderAntifragilityService;
use InvalidArgumentException;
use Tests\TestCase;

final class AtlasProviderAntifragilityTest extends TestCase
{
    private AtlasProviderAntifragilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasProviderAntifragilityService();
    }

    public function testReleaseIsAThreatOnlyWhenAtlasIsWrapperPositioned(): void
    {
        // Doc Principle: "Provider improvement is an input. It is not a product threat unless Atlas is acting like a wrapper."
        $wrapper = $this->service->classifyRelease(true);
        $this->assertSame('product_threat', $wrapper['classification']);
        $this->assertTrue($wrapper['is_product_threat']);
        $this->assertFalse($wrapper['absorb_as_input']);

        $aboveProvider = $this->service->classifyRelease(false);
        $this->assertSame('absorbable_input', $aboveProvider['classification']);
        $this->assertFalse($aboveProvider['is_product_threat']);
        $this->assertTrue($aboveProvider['absorb_as_input']);
    }

    public function testIngestionRunsInTheFiveDocumentedStepsInOrder(): void
    {
        $this->assertSame(
            ['catalog', 'compare', 'position', 'measure', 'absorb'],
            AtlasProviderAntifragilityService::INGESTION_STEPS
        );

        // A valid prefix reports the correct next step and is not yet complete.
        $partial = $this->service->ingestionProgress(['catalog', 'compare']);
        $this->assertTrue($partial['valid_order']);
        $this->assertFalse($partial['complete']);
        $this->assertSame('position', $partial['next_step']);

        // Completing all five steps marks ingestion complete with no next step.
        $full = $this->service->ingestionProgress(AtlasProviderAntifragilityService::INGESTION_STEPS);
        $this->assertTrue($full['complete']);
        $this->assertNull($full['next_step']);

        // Out-of-order steps are rejected (position before compare).
        $outOfOrder = $this->service->ingestionProgress(['catalog', 'position']);
        $this->assertFalse($outOfOrder['valid_order']);
        $this->assertFalse($outOfOrder['complete']);
    }

    public function testPositioningAbsorbsEverythingExceptRejectedBacklogAndNeverHardcodesLockIn(): void
    {
        $driver = $this->service->positionRelease('driver');
        $this->assertTrue($driver['absorbed']);
        $this->assertTrue($driver['keeps_provider_behind_atlas']);
        $this->assertFalse($driver['hardcodes_lock_in']);

        // Rejected backlog is the only non-absorbing positioning.
        $rejected = $this->service->positionRelease('rejected_backlog');
        $this->assertFalse($rejected['absorbed']);
        $this->assertFalse($rejected['keeps_provider_behind_atlas']);

        $this->expectException(InvalidArgumentException::class);
        $this->service->positionRelease('direct_channel');
    }

    public function testObsoleteComponentIsConvertedOrRemovedButNeverKeptAsDuplicate(): void
    {
        // Obsolete + residual governance value -> convert to adapter/evaluation.
        $convert = $this->service->resolveObsoleteComponent('legacy-router', true, true);
        $this->assertSame('convert_to_adapter_or_evaluation', $convert['disposition']);
        $this->assertFalse($convert['keep_as_duplicate']);

        // Obsolete + no residual value -> remove.
        $remove = $this->service->resolveObsoleteComponent('legacy-router', true, false);
        $this->assertSame('remove', $remove['disposition']);
        $this->assertFalse($remove['keep_as_duplicate']);

        // Not obsolete -> keep as-is.
        $keep = $this->service->resolveObsoleteComponent('active-router', false, true);
        $this->assertSame('keep', $keep['disposition']);
    }

    public function testStructuralMoatsAreDefensibleButRawIntelligenceMustBeAbsorbed(): void
    {
        $this->assertCount(8, AtlasProviderAntifragilityService::STRUCTURAL_MOATS);

        $moat = $this->service->assessMoat('deterministic_audit_replay');
        $this->assertTrue($moat['is_structural_moat']);
        $this->assertTrue($moat['structurally_defensible']);
        $this->assertTrue($moat['weakness_is_incentive_not_intelligence']);
        $this->assertSame('hold_moat_above_provider', $moat['recommended_response']);

        // Raw intelligence is not a moat: absorb, do not defend.
        $raw = $this->service->assessMoat('raw_model_reasoning');
        $this->assertFalse($raw['is_structural_moat']);
        $this->assertFalse($raw['structurally_defensible']);
        $this->assertSame('absorb_raw_intelligence_through_governed_drivers', $raw['recommended_response']);
    }

    public function testResidualThreatsAreMitigatedButNeverEliminated(): void
    {
        // Doc "Residual Threats": the thesis does NOT eliminate these three.
        $this->assertCount(3, AtlasProviderAntifragilityService::RESIDUAL_THREATS);

        $known = $this->service->handleResidualThreat('regulation_on_local_models_or_ledger_retention');
        $this->assertTrue($known['recognized']);
        $this->assertFalse($known['eliminated']);
        $this->assertSame('mitigated', $known['status']);
        $this->assertSame('privacy_governance_and_hardware_sovereignty', $known['mitigation']);

        // An unrecognized threat is never silently eliminated; it escalates.
        $unknown = $this->service->handleResidualThreat('time_travel');
        $this->assertFalse($unknown['recognized']);
        $this->assertFalse($unknown['eliminated']);
        $this->assertSame('unrecognized', $unknown['status']);
        $this->assertNull($unknown['mitigation']);
    }
}
