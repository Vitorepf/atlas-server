<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8P2MetaCompoundingCertificationService;
use PHPUnit\Framework\TestCase;

final class L8P2MetaCompoundingCertificationServiceTest extends TestCase
{
    private L8P2MetaCompoundingCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new L8P2MetaCompoundingCertificationService();
    }

    public function testCertifiesP2WhenDiscoveredFactorIsMeasuredEvidencedAndSafelyAdopted(): void
    {
        $result = $this->service->certify([
            'dm_dt_before' => 0.40,
            'dm_dt_after' => 0.55,
            'discovered_factors' => [
                [
                    'factor_id' => 'evidence_density_multiplier',
                    'system_discovered' => true,
                    'measured_contribution' => 0.21,
                    'evidence_refs' => ['evidence://l8/p2/contribution/density-window-7'],
                    'status' => 'adopted',
                    'weight_changed' => true,
                    'p5_passed' => true,
                ],
            ],
        ]);

        $this->assertSame('atlas.aaeos.l8.p2_meta_compounding_certification.v1', $result['schema_version']);
        $this->assertSame('L8-P2', $result['phase']);
        $this->assertTrue($result['p2_certified']);
        $this->assertSame('p2_certified', $result['status']);
        $this->assertSame(1, $result['discovered_factor_count']);
        $this->assertSame(1, $result['adopted_count']);
        $this->assertSame(1, $result['qualifying_factor_count']);
        $this->assertSame(0, $result['fabricated_factor_count']);
        $this->assertSame(0, $result['weight_change_without_p5_count']);
        $this->assertEqualsWithDelta(0.15, $result['dm_dt_delta'], 1.0e-9);
        $this->assertSame([], $result['blockers']);
    }

    public function testZeroDiscoveredFactorsBlocks(): void
    {
        $result = $this->service->certify([
            'dm_dt_before' => 0.30,
            'dm_dt_after' => 0.60,
            'discovered_factors' => [
                // Present, but hand-authored — not a system discovery, so it is ignored.
                [
                    'factor_id' => 'operator_seeded_factor',
                    'system_discovered' => false,
                    'measured_contribution' => 0.40,
                    'evidence_refs' => ['evidence://l8/p2/contribution/op-seeded'],
                    'status' => 'adopted',
                ],
            ],
        ]);

        $this->assertFalse($result['p2_certified']);
        $this->assertSame('blocked_not_p2', $result['status']);
        $this->assertSame(0, $result['discovered_factor_count']);
        $this->assertSame(0, $result['adopted_count']);
        $this->assertSame(['no_discovered_factors'], $result['blockers']);
    }

    public function testWeightChangeWithoutP5Blocks(): void
    {
        $result = $this->service->certify([
            'dm_dt_before' => 0.40,
            'dm_dt_after' => 0.70,
            'discovered_factors' => [
                [
                    'factor_id' => 'compounding_recall_factor',
                    'system_discovered' => true,
                    'measured_contribution' => 0.33,
                    'evidence_refs' => ['evidence://l8/p2/contribution/recall-window-3'],
                    'status' => 'adopted',
                    'weight_changed' => true,
                    'p5_passed' => false,
                ],
            ],
        ]);

        $this->assertFalse($result['p2_certified']);
        $this->assertSame(1, $result['discovered_factor_count']);
        $this->assertSame(1, $result['weight_change_without_p5_count']);
        $this->assertSame(
            ['weight_change_without_p5', 'no_qualifying_discovered_factor'],
            $result['blockers'],
        );
    }

    public function testFabricatedFactorWithoutEvidenceBlocks(): void
    {
        $result = $this->service->certify([
            'dm_dt_before' => 0.20,
            'dm_dt_after' => 0.50,
            'discovered_factors' => [
                [
                    'factor_id' => 'imagined_synergy_factor',
                    'system_discovered' => true,
                    'measured_contribution' => 0.45,
                    'evidence_refs' => [],
                    'status' => 'proposed',
                    'weight_changed' => false,
                ],
            ],
        ]);

        $this->assertFalse($result['p2_certified']);
        $this->assertSame(1, $result['discovered_factor_count']);
        $this->assertSame(1, $result['fabricated_factor_count']);
        $this->assertSame(
            ['fabricated_factor_without_evidence', 'no_qualifying_discovered_factor'],
            $result['blockers'],
        );
    }

    public function testFlatAggregateDmDtBlocks(): void
    {
        $result = $this->service->certify([
            'dm_dt_before' => 0.50,
            'dm_dt_after' => 0.50,
            'discovered_factors' => [
                [
                    'factor_id' => 'governed_reuse_factor',
                    'system_discovered' => true,
                    'measured_contribution' => 0.18,
                    'evidence_refs' => ['evidence://l8/p2/contribution/reuse-window-5'],
                    'status' => 'adopted',
                    'weight_changed' => true,
                    'p5_passed' => true,
                ],
            ],
        ]);

        $this->assertFalse($result['p2_certified']);
        $this->assertEqualsWithDelta(0.0, $result['dm_dt_delta'], 1.0e-9);
        $this->assertSame(['dm_dt_not_improved'], $result['blockers']);
    }

    public function testProposedFactorWithMeasuredContributionAndPositiveDeltaCertifies(): void
    {
        // A governed PROPOSED factor (not yet adopted) that did not move a weight
        // still qualifies, provided it is measured + evidenced and dM/dt rose.
        $result = $this->service->certify([
            'dm_dt_delta' => 0.08,
            'discovered_factors' => [
                [
                    'factor_id' => 'cross_area_transfer_factor',
                    'discovered_by' => 'system',
                    'contribution_score' => 0.12,
                    'source_refs' => ['evidence://l8/p2/contribution/transfer-window-2'],
                    'status' => 'proposed',
                    'weight_changed' => false,
                ],
            ],
        ]);

        $this->assertTrue($result['p2_certified']);
        $this->assertSame(1, $result['discovered_factor_count']);
        $this->assertSame(0, $result['adopted_count']);
        $this->assertSame(1, $result['qualifying_factor_count']);
        $this->assertEqualsWithDelta(0.08, $result['dm_dt_delta'], 1.0e-9);
        $this->assertSame([], $result['blockers']);
    }

    public function testNonPositiveContributionDoesNotQualifyDiscoveredFactor(): void
    {
        $result = $this->service->certify([
            'dm_dt_before' => 0.10,
            'dm_dt_after' => 0.30,
            'discovered_factors' => [
                [
                    'factor_id' => 'noisy_candidate_factor',
                    'system_discovered' => true,
                    'measured_contribution' => 0.0,
                    'evidence_refs' => ['evidence://l8/p2/contribution/noisy-window-9'],
                    'status' => 'proposed',
                ],
            ],
        ]);

        $this->assertFalse($result['p2_certified']);
        $this->assertSame(1, $result['discovered_factor_count']);
        $this->assertSame(0, $result['qualifying_factor_count']);
        $this->assertSame(['no_qualifying_discovered_factor'], $result['blockers']);
    }

    public function testRejectedStatusDiscoveredFactorDoesNotQualify(): void
    {
        $result = $this->service->certify([
            'dm_dt_before' => 0.20,
            'dm_dt_after' => 0.40,
            'discovered_factors' => [
                [
                    'factor_id' => 'rejected_factor',
                    'system_discovered' => true,
                    'measured_contribution' => 0.25,
                    'evidence_refs' => ['evidence://l8/p2/contribution/rejected-window-1'],
                    'status' => 'rejected',
                    'weight_changed' => false,
                ],
            ],
        ]);

        $this->assertFalse($result['p2_certified']);
        $this->assertSame(1, $result['discovered_factor_count']);
        $this->assertSame(0, $result['adopted_count']);
        $this->assertSame(0, $result['qualifying_factor_count']);
        $this->assertSame(['no_qualifying_discovered_factor'], $result['blockers']);
    }

    public function testHonestyFirstBlockerOrderingCombinesGapsDeterministically(): void
    {
        // Two distinct system-discovered factors trigger BOTH the weight-without-P5
        // gap and the fabricated-evidence gap, while aggregate dM/dt also fell. The
        // blockers must appear in the canonical honesty-first order.
        $result = $this->service->certify([
            'dm_dt_before' => 0.60,
            'dm_dt_after' => 0.45,
            'discovered_factors' => [
                [
                    'factor_id' => 'unsafe_weight_factor',
                    'system_discovered' => true,
                    'measured_contribution' => 0.30,
                    'evidence_refs' => ['evidence://l8/p2/contribution/unsafe-window-4'],
                    'status' => 'adopted',
                    'weight_changed' => true,
                    'p5_passed' => false,
                ],
                [
                    'factor_id' => 'fabricated_factor',
                    'system_discovered' => true,
                    'measured_contribution' => 0.50,
                    'evidence_refs' => [],
                    'status' => 'proposed',
                    'weight_changed' => false,
                ],
            ],
        ]);

        $this->assertFalse($result['p2_certified']);
        $this->assertSame(2, $result['discovered_factor_count']);
        $this->assertSame(1, $result['weight_change_without_p5_count']);
        $this->assertSame(1, $result['fabricated_factor_count']);
        $this->assertEqualsWithDelta(-0.15, $result['dm_dt_delta'], 1.0e-9);
        $this->assertSame(
            [
                'weight_change_without_p5',
                'fabricated_factor_without_evidence',
                'dm_dt_not_improved',
                'no_qualifying_discovered_factor',
            ],
            $result['blockers'],
        );
    }

    public function testNonFiniteDmDtDeltaDoesNotFalselyCertify(): void
    {
        // Honesty-first fail-closed: a precomputed NaN dm_dt_delta is not a real rise.
        // Unguarded, NAN <= 0.0 is false, so the dm_dt_not_improved blocker would be
        // skipped and P2 wrongly certified. A malformed delta must read as no-signal
        // (0.0) and block.
        $result = $this->service->certify([
            'dm_dt_delta' => NAN,
            'discovered_factors' => [
                [
                    'factor_id' => 'evidence_density_multiplier',
                    'system_discovered' => true,
                    'measured_contribution' => 0.20,
                    'evidence_refs' => ['evidence://l8/p2/contribution/density-window-7'],
                    'status' => 'adopted',
                    'weight_changed' => true,
                    'p5_passed' => true,
                ],
            ],
        ]);

        $this->assertFalse($result['p2_certified']);
        $this->assertSame('blocked_not_p2', $result['status']);
        $this->assertTrue(is_finite($result['dm_dt_delta']), 'dm_dt_delta must stay finite');
        $this->assertContains('dm_dt_not_improved', $result['blockers']);
    }

    public function testOverflowingDmDtSubtractionDoesNotFalselyCertify(): void
    {
        // Honesty-first fail-closed on the SUBTRACTION path: two finite-but-huge
        // dm_dt operands near ±PHP_FLOAT_MAX overflow to +INF on after - before.
        // Unguarded, INF <= 0.0 is false, so dm_dt_not_improved would be skipped and
        // P2 wrongly certified with a non-finite delta. The computed delta must stay
        // finite and collapse to no-signal (0.0), blocking.
        $result = $this->service->certify([
            'dm_dt_before' => -1.0e308,
            'dm_dt_after' => 1.0e308,
            'discovered_factors' => [
                [
                    'factor_id' => 'evidence_density_multiplier',
                    'system_discovered' => true,
                    'measured_contribution' => 0.20,
                    'evidence_refs' => ['evidence://l8/p2/contribution/density-window-7'],
                    'status' => 'adopted',
                    'weight_changed' => true,
                    'p5_passed' => true,
                ],
            ],
        ]);

        $this->assertFalse($result['p2_certified']);
        $this->assertSame('blocked_not_p2', $result['status']);
        $this->assertTrue(is_finite($result['dm_dt_delta']), 'dm_dt_delta must stay finite on the subtraction path');
        $this->assertEqualsWithDelta(0.0, $result['dm_dt_delta'], 1.0e-9);
        $this->assertContains('dm_dt_not_improved', $result['blockers']);
    }

    public function testNonFiniteMeasuredContributionDoesNotQualifyFactor(): void
    {
        // An INF measured_contribution is not a real measured contribution: the factor
        // must not qualify on a non-finite number.
        $result = $this->service->certify([
            'dm_dt_before' => 0.10,
            'dm_dt_after' => 0.50,
            'discovered_factors' => [
                [
                    'factor_id' => 'inflated_factor',
                    'system_discovered' => true,
                    'measured_contribution' => INF,
                    'evidence_refs' => ['evidence://l8/p2/contribution/inf-window-1'],
                    'status' => 'adopted',
                    'weight_changed' => false,
                ],
            ],
        ]);

        $this->assertFalse($result['p2_certified']);
        $this->assertSame(1, $result['discovered_factor_count']);
        $this->assertSame(0, $result['qualifying_factor_count']);
        $this->assertSame(['no_qualifying_discovered_factor'], $result['blockers']);
    }

    public function testEmptyInputsBlockWithNoDiscoveredFactors(): void
    {
        $result = $this->service->certify([]);

        $this->assertFalse($result['p2_certified']);
        $this->assertSame(0, $result['discovered_factor_count']);
        $this->assertSame(0, $result['adopted_count']);
        $this->assertEqualsWithDelta(0.0, $result['dm_dt_delta'], 1.0e-9);
        $this->assertSame(['no_discovered_factors'], $result['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $inputs = [
            'dm_dt_before' => 0.40,
            'dm_dt_after' => 0.52,
            'discovered_factors' => [
                [
                    'factor_id' => 'evidence_density_multiplier',
                    'system_discovered' => true,
                    'measured_contribution' => 0.19,
                    'evidence_refs' => ['evidence://l8/p2/contribution/density-window-7'],
                    'status' => 'adopted',
                    'weight_changed' => true,
                    'p5_passed' => true,
                ],
            ],
        ];

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        $this->assertSame($first, $second);
    }

    public function testBlockersListIsAlwaysAStringList(): void
    {
        $result = $this->service->certify([
            'dm_dt_before' => 0.60,
            'dm_dt_after' => 0.45,
            'discovered_factors' => [
                [
                    'factor_id' => 'unsafe_weight_factor',
                    'system_discovered' => true,
                    'measured_contribution' => 0.30,
                    'evidence_refs' => [],
                    'status' => 'adopted',
                    'weight_changed' => true,
                    'p5_passed' => false,
                ],
            ],
        ]);

        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        foreach ($result['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }
    }
}
