<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8P4LocalEngineDistillationCertificationService;
use PHPUnit\Framework\TestCase;

final class L8P4LocalEngineDistillationCertificationServiceTest extends TestCase
{
    private L8P4LocalEngineDistillationCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new L8P4LocalEngineDistillationCertificationService();
    }

    /**
     * A recurring task class genuinely served by a local distilled engine at or
     * above external quality, capturing real volume off the external path.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function servedClass(array $overrides = []): array
    {
        return array_merge([
            'task_class_id' => 'recurring_repair_drafting',
            'privacy_class' => 'normal',
            'served_by_local' => true,
            'local_engine_id' => 'atlas_distilled_repair_v1',
            'local_quality' => 0.84,
            'external_quality' => 0.80,
            'invocation_volume' => 100,
        ], $overrides);
    }

    public function testServedLocalClassAtOrAboveExternalCertifiesAndReturnsAllContractKeys(): void
    {
        $result = $this->service->certify([
            'task_classes' => [
                $this->servedClass(),
            ],
        ]);

        // Contract keys (Aceite): certify(inputs) returns p4_certified,
        // local_task_class_count, quality_delta, external_dependency_reduction, blockers.
        $this->assertArrayHasKey('p4_certified', $result);
        $this->assertArrayHasKey('local_task_class_count', $result);
        $this->assertArrayHasKey('quality_delta', $result);
        $this->assertArrayHasKey('external_dependency_reduction', $result);
        $this->assertArrayHasKey('blockers', $result);

        $this->assertSame('atlas.aaeos.l8.p4_local_engine_distillation_certification.v1', $result['schema_version']);
        $this->assertTrue($result['p4_certified']);
        $this->assertSame(1, $result['local_task_class_count']);
        $this->assertSame(1, $result['served_quality_pass_count']);
        $this->assertSame(0, $result['privacy_violation_count']);
        // quality_delta is the measured (local - external) margin: 0.84 - 0.80.
        $this->assertEqualsWithDelta(0.04, $result['quality_delta'], 1e-9);
        // The single 100-volume class is fully captured off the external path.
        $this->assertEqualsWithDelta(1.0, $result['external_dependency_reduction'], 1e-9);
        $this->assertSame([], $result['blockers']);
    }

    public function testNoLocalProviderEvidenceBlocks(): void
    {
        // The class claims to be served locally but carries no engine id and no
        // measured local/external qualities: hype, not measured capability.
        $result = $this->service->certify([
            'task_classes' => [
                [
                    'task_class_id' => 'recurring_repair_drafting',
                    'served_by_local' => true,
                    'invocation_volume' => 100,
                ],
            ],
        ]);

        $this->assertFalse($result['p4_certified']);
        $this->assertSame(0, $result['local_task_class_count']);
        $this->assertSame(0, $result['served_quality_pass_count']);
        $this->assertEqualsWithDelta(0.0, $result['quality_delta'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $result['external_dependency_reduction'], 1e-9);
        $this->assertSame(['no_local_provider_evidence'], $result['blockers']);
    }

    public function testEmptyPortfolioBlocksAsNoLocalProviderEvidence(): void
    {
        $result = $this->service->certify(['task_classes' => []]);

        $this->assertFalse($result['p4_certified']);
        $this->assertSame(0, $result['local_task_class_count']);
        $this->assertSame(['no_local_provider_evidence'], $result['blockers']);
    }

    public function testMissingTaskClassesKeyBlocksAsNoLocalProviderEvidence(): void
    {
        $result = $this->service->certify([]);

        $this->assertFalse($result['p4_certified']);
        $this->assertSame(0, $result['local_task_class_count']);
        $this->assertSame(['no_local_provider_evidence'], $result['blockers']);
    }

    public function testQualityBelowExternalPathBlocks(): void
    {
        // Real local provider evidence, but the measured local quality is below the
        // external path: P4 is measured capability, not model hype.
        $result = $this->service->certify([
            'task_classes' => [
                $this->servedClass([
                    'local_quality' => 0.71,
                    'external_quality' => 0.80,
                ]),
            ],
        ]);

        $this->assertFalse($result['p4_certified']);
        // The class has real evidence, so it counts as a local candidate...
        $this->assertSame(1, $result['local_task_class_count']);
        // ...but none passed the quality bar.
        $this->assertSame(0, $result['served_quality_pass_count']);
        // quality_delta is the (negative) binding margin: 0.71 - 0.80.
        $this->assertEqualsWithDelta(-0.09, $result['quality_delta'], 1e-9);
        // A below-external class captured no dependency.
        $this->assertEqualsWithDelta(0.0, $result['external_dependency_reduction'], 1e-9);
        $this->assertSame(['quality_below_external_path'], $result['blockers']);
    }

    public function testExactQualityParityCountsAsAPass(): void
    {
        // local == external is sufficient (>=): parity starts capturing N locally.
        $result = $this->service->certify([
            'task_classes' => [
                $this->servedClass([
                    'local_quality' => 0.77,
                    'external_quality' => 0.77,
                ]),
            ],
        ]);

        $this->assertTrue($result['p4_certified']);
        $this->assertSame(1, $result['served_quality_pass_count']);
        $this->assertEqualsWithDelta(0.0, $result['quality_delta'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $result['external_dependency_reduction'], 1e-9);
        $this->assertSame([], $result['blockers']);
    }

    public function testPrivacyViolationBlocksWhenLocalFirstClassRoutesExternally(): void
    {
        // A sensitive class is a local-first class; serving it via an engine that
        // routes externally is a sovereignty breach, even at strong quality.
        $result = $this->service->certify([
            'task_classes' => [
                $this->servedClass([
                    'task_class_id' => 'incident_secret_triage',
                    'privacy_class' => 'sensitive',
                    'local_quality' => 0.90,
                    'external_quality' => 0.80,
                    'routes_external' => true,
                ]),
            ],
        ]);

        $this->assertFalse($result['p4_certified']);
        $this->assertSame(1, $result['local_task_class_count']);
        $this->assertSame(1, $result['privacy_violation_count']);
        $this->assertContains('privacy_violation', $result['blockers']);
        // The violating class never captures dependency despite passing quality.
        $this->assertEqualsWithDelta(0.0, $result['external_dependency_reduction'], 1e-9);
    }

    public function testSecretClassServedLocalFirstOnDeviceCertifies(): void
    {
        // The same sensitivity, served honestly on-device, is allowed.
        $result = $this->service->certify([
            'task_classes' => [
                $this->servedClass([
                    'task_class_id' => 'cyber_log_classification',
                    'privacy_class' => 'cyber',
                    'local_first' => true,
                    'local_quality' => 0.88,
                    'external_quality' => 0.80,
                ]),
            ],
        ]);

        $this->assertTrue($result['p4_certified']);
        $this->assertSame(0, $result['privacy_violation_count']);
        $this->assertSame(1, $result['served_quality_pass_count']);
        $this->assertSame([], $result['blockers']);
    }

    public function testSensitiveClassWithoutLocalFirstFlagIsAPrivacyViolation(): void
    {
        // Sensitive class, local engine, strong quality, but no local-first/on-device
        // assertion at all -> cannot prove it stayed local -> violation.
        $result = $this->service->certify([
            'task_classes' => [
                $this->servedClass([
                    'privacy_class' => 'secret',
                    'local_quality' => 0.95,
                    'external_quality' => 0.80,
                ]),
            ],
        ]);

        $this->assertFalse($result['p4_certified']);
        $this->assertSame(1, $result['privacy_violation_count']);
        $this->assertContains('privacy_violation', $result['blockers']);
    }

    public function testDependencyReductionIsWeightedByVolumeAndNeverExceedsOne(): void
    {
        // Two served classes: one qualifies and captures its full 30 volume, the
        // other is below external (no capture). Reduction = 30 / (30 + 70) = 0.30.
        $result = $this->service->certify([
            'task_classes' => [
                $this->servedClass([
                    'task_class_id' => 'qualifying_small',
                    'local_quality' => 0.83,
                    'external_quality' => 0.80,
                    'invocation_volume' => 30,
                ]),
                $this->servedClass([
                    'task_class_id' => 'below_external_large',
                    'local_quality' => 0.60,
                    'external_quality' => 0.80,
                    'invocation_volume' => 70,
                ]),
            ],
        ]);

        $this->assertSame(2, $result['local_task_class_count']);
        $this->assertSame(1, $result['served_quality_pass_count']);
        $this->assertEqualsWithDelta(0.30, $result['external_dependency_reduction'], 1e-9);
        // quality_delta is the minimum (binding) margin across served classes: 0.60-0.80.
        $this->assertEqualsWithDelta(-0.20, $result['quality_delta'], 1e-9);
        // It still certifies: at least one class passes quality AND reduction > 0.
        $this->assertTrue($result['p4_certified']);
        $this->assertSame([], $result['blockers']);
        // Bound honesty: the reduction share is inside the 0..1 band.
        $this->assertLessThanOrEqual(1.0, $result['external_dependency_reduction']);
        $this->assertGreaterThanOrEqual(0.0, $result['external_dependency_reduction']);
    }

    public function testPartialLocalVolumeSplitIsHonouredAndClampedToVolume(): void
    {
        // Qualifying class with volume 200 but only 50 actually served locally.
        // Reduction = 50 / 200 = 0.25, never the full 1.0.
        $result = $this->service->certify([
            'task_classes' => [
                $this->servedClass([
                    'invocation_volume' => 200,
                    'local_invocation_volume' => 50,
                ]),
            ],
        ]);

        $this->assertTrue($result['p4_certified']);
        $this->assertEqualsWithDelta(0.25, $result['external_dependency_reduction'], 1e-9);
    }

    public function testMalformedLocalVolumeAboveTotalCannotPushReductionAboveOne(): void
    {
        // A nonsense local split larger than the class volume must clamp to the
        // class volume, so reduction stays exactly 1.0 (never above the 0..1 bound).
        $result = $this->service->certify([
            'task_classes' => [
                $this->servedClass([
                    'invocation_volume' => 40,
                    'local_invocation_volume' => 9999,
                ]),
            ],
        ]);

        $this->assertEqualsWithDelta(1.0, $result['external_dependency_reduction'], 1e-9);
        $this->assertLessThanOrEqual(1.0, $result['external_dependency_reduction']);
        $this->assertTrue($result['p4_certified']);
    }

    public function testCleanQualityPassButZeroCapturedVolumeStillBlocks(): void
    {
        // Quality passes and there is no privacy breach, but the local engine served
        // none of the volume (local split = 0): no dependency was actually reduced.
        $result = $this->service->certify([
            'task_classes' => [
                $this->servedClass([
                    'invocation_volume' => 100,
                    'local_invocation_volume' => 0,
                ]),
            ],
        ]);

        $this->assertFalse($result['p4_certified']);
        $this->assertSame(1, $result['served_quality_pass_count']);
        $this->assertSame(0, $result['privacy_violation_count']);
        $this->assertEqualsWithDelta(0.0, $result['external_dependency_reduction'], 1e-9);
        $this->assertSame(['quality_below_external_path'], $result['blockers']);
    }

    public function testServingEngineObjectShapeProvidesEvidenceAndPrivacy(): void
    {
        // Evidence and local-first via a nested serving_engine object instead of
        // flat flags — exercises the alternate accepted shape generally.
        $result = $this->service->certify([
            'task_classes' => [
                [
                    'class_id' => 'secret_summary',
                    'privacy' => 'secret',
                    'serving_engine' => [
                        'kind' => 'local',
                        'id' => 'atlas_distilled_secret_v2',
                        'on_device' => true,
                    ],
                    'measured_local_quality' => 0.82,
                    'external_path_quality' => 0.79,
                    'volume' => 64,
                ],
            ],
        ]);

        $this->assertTrue($result['p4_certified']);
        $this->assertSame(1, $result['local_task_class_count']);
        $this->assertSame(0, $result['privacy_violation_count']);
        $this->assertEqualsWithDelta(0.03, $result['quality_delta'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $result['external_dependency_reduction'], 1e-9);
    }

    public function testNonLocalServedClassesAreNotCountedAsLocalCapability(): void
    {
        // A class still served by the external path (served_by_local false) carries
        // no local provider evidence and must not count toward P4 at all.
        $result = $this->service->certify([
            'task_classes' => [
                [
                    'task_class_id' => 'still_external',
                    'served_by_local' => false,
                    'external_quality' => 0.80,
                    'invocation_volume' => 100,
                ],
            ],
        ]);

        $this->assertFalse($result['p4_certified']);
        $this->assertSame(0, $result['local_task_class_count']);
        $this->assertSame(['no_local_provider_evidence'], $result['blockers']);
    }

    public function testPrivacyAndQualityBlockersAreOrderedPrivacyFirst(): void
    {
        // One served class that both breaches local-first AND is below external.
        // Privacy must be surfaced before the quality blocker.
        $result = $this->service->certify([
            'task_classes' => [
                $this->servedClass([
                    'privacy_class' => 'sensitive',
                    'routes_external' => true,
                    'local_quality' => 0.55,
                    'external_quality' => 0.80,
                ]),
            ],
        ]);

        $this->assertSame(
            ['privacy_violation', 'quality_below_external_path'],
            $result['blockers'],
        );
        $this->assertFalse($result['p4_certified']);
    }

    public function testNonFiniteQualityIsNotEvidenceAndCannotCertifyP4(): void
    {
        // A non-finite local quality (INF/NAN) is the residue of an upstream
        // divide-by-zero or overflow on a 0..1-declared score, not a measured
        // capability. It must NOT count as real provider evidence: otherwise
        // `INF >= external` would falsely certify the P4 teto with quality_delta=INF
        // (while NAN already fails closed). Both must fail closed identically.
        foreach ([INF, NAN] as $poison) {
            $result = $this->service->certify([
                'task_classes' => [
                    $this->servedClass([
                        'local_quality' => $poison,
                        'external_quality' => 0.80,
                    ]),
                ],
            ]);

            $this->assertFalse($result['p4_certified']);
            // No finite measured local quality -> no real provider evidence at all.
            $this->assertSame(0, $result['local_task_class_count']);
            $this->assertSame(0, $result['served_quality_pass_count']);
            $this->assertSame(['no_local_provider_evidence'], $result['blockers']);
            // quality_delta stays a finite contract float.
            $this->assertIsFloat($result['quality_delta']);
            $this->assertTrue(is_finite($result['quality_delta']));
            $this->assertEqualsWithDelta(0.0, $result['quality_delta'], 1e-9);
        }
    }

    public function testNonFiniteVolumeNeverPushesReductionOutOfBand(): void
    {
        // A non-finite invocation volume (INF) is not a measured sample volume; it
        // must not become evidence (volume>0 requires a finite measurement) nor
        // poison external_dependency_reduction into NAN via INF/INF.
        $result = $this->service->certify([
            'task_classes' => [
                $this->servedClass(['invocation_volume' => INF]),
            ],
        ]);

        $this->assertSame(0, $result['local_task_class_count']);
        $this->assertSame(['no_local_provider_evidence'], $result['blockers']);
        $this->assertTrue(is_finite($result['external_dependency_reduction']));
        $this->assertEqualsWithDelta(0.0, $result['external_dependency_reduction'], 1e-9);
    }

    public function testCertificationIsDeterministicForIdenticalInput(): void
    {
        $inputs = ['task_classes' => [$this->servedClass()]];

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        $this->assertSame($first, $second);
    }
}
