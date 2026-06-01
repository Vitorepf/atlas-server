<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasLayerStatusService;
use Tests\TestCase;

final class AtlasLayerStatusTest extends TestCase
{
    private AtlasLayerStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasLayerStatusService();
    }

    public function testLayerMapIsTheClosedTableAndSplitsActiveFromDocumented(): void
    {
        $map = $this->service->layerMap();

        // The doc's table lists exactly 10 layers.
        $this->assertSame(10, $map['count']);

        // Layer ids verbatim and in documented order.
        $this->assertSame(
            ['0', '0.5', '0.6', '0.7', '0.8', '1', '2', '2.5', '3', '4'],
            array_column($map['layers'], 'layer')
        );

        // The table splits active/implemented (0, 0.5, 1, 2, 2.5, 3, 4 = 7) from
        // the documented-only / phased layers (0.6, 0.7, 0.8 = 3).
        $this->assertSame(7, $map['active_count']);
        $this->assertSame(3, $map['documented_count']);
    }

    public function testResolveLayerPinsStateAndMaturityAndFlagsUnknown(): void
    {
        // 0.5 is active runtime.
        $half = $this->service->resolveLayer('0.5');
        $this->assertTrue($half['resolved']);
        $this->assertSame(AtlasLayerStatusService::MATURITY_ACTIVE, $half['maturity']);

        // 0.7 (Spec OS) is documented as law, runtime still phased.
        $specOs = $this->service->resolveLayer('0.7');
        $this->assertTrue($specOs['resolved']);
        $this->assertSame(AtlasLayerStatusService::MATURITY_DOCUMENTED, $specOs['maturity']);
        $this->assertSame(
            'Spec Operating System documented as SDD law; runtime implementation still phased.',
            $specOs['state']
        );

        // Whole-number forms (int 2 and "Layer 2") normalize to the same id.
        $this->assertTrue($this->service->resolveLayer(2)['resolved']);
        $this->assertSame('2', $this->service->resolveLayer('Layer 2')['layer']);

        // A layer not in the table is reported, not guessed.
        $unknown = $this->service->resolveLayer('5');
        $this->assertFalse($unknown['resolved']);
        $this->assertNull($unknown['state']);
        $this->assertSame('layer_not_in_canonical_status_table', $unknown['reason']);
    }

    public function testOverrideGuardHardDeniesKernelMasterAndDomainSpecs(): void
    {
        // Decision: "Layer status is descriptive and must not override Kernel,
        // Master or domain specs." All three are hard-denied.
        foreach (['kernel', 'Master', ' DOMAIN '] as $target) {
            $verdict = $this->service->evaluateOverride($target);
            $this->assertFalse($verdict['allowed']);
            $this->assertSame(
                'layer_status_is_descriptive_must_not_override_kernel_master_or_domain_specs',
                $verdict['reason']
            );
        }

        // An unrelated target is also not granted an override.
        $other = $this->service->evaluateOverride('surface');
        $this->assertFalse($other['allowed']);
        $this->assertSame('target_not_governed_by_layer_status_read_model', $other['reason']);
    }

    public function testPromotionRequiresVerifiableEvidenceAndGreenGates(): void
    {
        // forbidden_changes: runtime/maturity/readiness may not be declared
        // without verifiable evidence AND green gates.

        // Both present -> promoted.
        $ok = $this->service->evaluatePromotion('2', true, true);
        $this->assertTrue($ok['promoted']);
        $this->assertSame('promotion_backed_by_verifiable_evidence_and_green_gates', $ok['reason']);

        // Evidence but no green gates -> denied.
        $noGates = $this->service->evaluatePromotion('2', true, false);
        $this->assertFalse($noGates['promoted']);
        $this->assertSame('promotion_requires_green_gates', $noGates['reason']);

        // Green gates but no evidence -> denied.
        $noEvidence = $this->service->evaluatePromotion('2', false, true);
        $this->assertFalse($noEvidence['promoted']);
        $this->assertSame('promotion_requires_verifiable_evidence', $noEvidence['reason']);

        // Neither -> denied with the combined reason. A documented-only layer
        // (0.7) is no exception: its own doc never makes it "ready".
        $bare = $this->service->evaluatePromotion('0.7', false, false);
        $this->assertFalse($bare['promoted']);
        $this->assertSame('promotion_requires_verifiable_evidence_and_green_gates', $bare['reason']);
        $this->assertSame(AtlasLayerStatusService::MATURITY_DOCUMENTED, $bare['current_maturity']);
    }

    public function testPromotionOfUnknownLayerIsDenied(): void
    {
        $unknown = $this->service->evaluatePromotion('9', true, true);
        $this->assertFalse($unknown['promoted']);
        $this->assertFalse($unknown['resolved']);
        $this->assertSame('layer_not_in_canonical_status_table', $unknown['reason']);
    }

    public function testBlockShipRequiresCodeTestsAndDocsTogether(): void
    {
        // Doc: "Each block must ship code, tests and docs together."

        // All three legs -> shippable.
        $full = $this->service->evaluateBlockShip([
            'code' => true,
            'tests' => true,
            'docs' => true,
        ]);
        $this->assertTrue($full['shippable']);
        $this->assertSame('shippable', $full['verdict']);
        $this->assertSame([], $full['missing']);
        $this->assertSame(3, $full['total']);

        // Missing docs -> blocked, and docs is the listed hole.
        $noDocs = $this->service->evaluateBlockShip([
            'code' => true,
            'tests' => true,
            'docs' => false,
        ]);
        $this->assertFalse($noDocs['shippable']);
        $this->assertSame('blocked', $noDocs['verdict']);
        $this->assertSame(['docs'], $noDocs['missing']);
        $this->assertSame('block_must_ship_code_tests_and_docs_together', $noDocs['reason']);

        // Empty -> nothing shipped, all three missing.
        $empty = $this->service->evaluateBlockShip([]);
        $this->assertFalse($empty['shippable']);
        $this->assertSame([], $empty['shipped']);
        $this->assertSame(['code', 'tests', 'docs'], $empty['missing']);
    }
}
