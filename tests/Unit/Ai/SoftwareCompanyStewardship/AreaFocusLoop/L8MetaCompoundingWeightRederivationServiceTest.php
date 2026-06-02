<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8MetaCompoundingWeightRederivationService;
use PHPUnit\Framework\TestCase;

final class L8MetaCompoundingWeightRederivationServiceTest extends TestCase
{
    private L8MetaCompoundingWeightRederivationService $service;

    protected function setUp(): void
    {
        $this->service = new L8MetaCompoundingWeightRederivationService();
    }

    public function testProposedWeightsSumToOne(): void
    {
        $result = $this->service->propose(
            ['memory' => 0.4, 'evidence' => 0.35, 'compounding' => 0.25],
            [
                ['factor_id' => 'memory', 'contribution_score' => 0.6, 'confidence' => 0.9, 'p5_evidence_ref' => 'p5://anchor/1'],
                ['factor_id' => 'evidence', 'contribution_score' => 0.2, 'confidence' => 0.8, 'p5_evidence_ref' => 'p5://anchor/2'],
                ['factor_id' => 'compounding', 'contribution_score' => 0.1, 'confidence' => 0.7, 'p5_evidence_ref' => 'p5://anchor/3'],
            ],
        );

        self::assertSame('proposed', $result['status']);
        self::assertEqualsWithDelta(1.0, array_sum($result['proposed_weights']), 1.0e-9);
    }

    public function testProposedWeightsSumToOneForArbitraryUnnormalisedBaseline(): void
    {
        // Baseline does not sum to 1.0 and contribution mass is uneven; output must still sum to 1.0.
        $result = $this->service->propose(
            ['a' => 2.0, 'b' => 3.0, 'c' => 5.0, 'd' => 7.0],
            [
                ['factor_id' => 'a', 'contribution_score' => 1.5, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://a'],
                ['factor_id' => 'b', 'contribution_score' => 0.0, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://b'],
                ['factor_id' => 'c', 'contribution_score' => -4.0, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://c'],
                ['factor_id' => 'd', 'contribution_score' => 0.3, 'confidence' => 0.61, 'p5_evidence_ref' => 'p5://d'],
            ],
        );

        self::assertEqualsWithDelta(1.0, array_sum($result['proposed_weights']), 1.0e-9);
        self::assertSame(['a', 'b', 'c', 'd'], array_keys($result['proposed_weights']));
    }

    public function testPositiveMeasuredContributionGrowsFactorShare(): void
    {
        $result = $this->service->propose(
            ['memory' => 0.5, 'evidence' => 0.5],
            [
                ['factor_id' => 'memory', 'contribution_score' => 0.8, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://m'],
                ['factor_id' => 'evidence', 'contribution_score' => 0.0, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://e'],
            ],
        );

        // memory measured higher contribution => its proposed share must rise above its 0.5 baseline.
        self::assertGreaterThan(0.5, $result['proposed_weights']['memory']);
        self::assertLessThan(0.5, $result['proposed_weights']['evidence']);
        self::assertContains('memory', $result['changed_factors']);
        self::assertContains('evidence', $result['changed_factors']);
    }

    public function testNegativeContributionDoesNotGrowFactorShare(): void
    {
        $result = $this->service->propose(
            ['safe' => 0.5, 'noisy' => 0.5],
            [
                ['factor_id' => 'safe', 'contribution_score' => 0.4, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://s'],
                ['factor_id' => 'noisy', 'contribution_score' => -0.9, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://n'],
            ],
        );

        // Negative contribution is floored at zero => the noisy factor can only lose share, never gain.
        self::assertLessThan(0.5, $result['proposed_weights']['noisy']);
        self::assertGreaterThan(0.5, $result['proposed_weights']['safe']);
    }

    public function testLowConfidenceContributionEarnsNoReDerivationMass(): void
    {
        // Both factors symmetric except confidence: only the high-confidence one should grow.
        $result = $this->service->propose(
            ['trusted' => 0.5, 'unverified' => 0.5],
            [
                ['factor_id' => 'trusted', 'contribution_score' => 0.7, 'confidence' => 0.95, 'p5_evidence_ref' => 'p5://t'],
                ['factor_id' => 'unverified', 'contribution_score' => 0.7, 'confidence' => 0.4, 'p5_evidence_ref' => 'p5://u'],
            ],
        );

        self::assertGreaterThan(0.5, $result['proposed_weights']['trusted']);
        self::assertLessThan(0.5, $result['proposed_weights']['unverified']);
        self::assertEqualsWithDelta(1.0, array_sum($result['proposed_weights']), 1.0e-9);
    }

    public function testEqualMeasuredContributionLeavesWeightsUnchanged(): void
    {
        $result = $this->service->propose(
            ['x' => 0.5, 'y' => 0.5],
            [
                ['factor_id' => 'x', 'contribution_score' => 0.3, 'confidence' => 0.9, 'p5_evidence_ref' => 'p5://x'],
                ['factor_id' => 'y', 'contribution_score' => 0.3, 'confidence' => 0.9, 'p5_evidence_ref' => 'p5://y'],
            ],
        );

        // Symmetric contribution keeps the split balanced; no factor changes.
        self::assertEqualsWithDelta(0.5, $result['proposed_weights']['x'], 1.0e-9);
        self::assertEqualsWithDelta(0.5, $result['proposed_weights']['y'], 1.0e-9);
        self::assertSame([], $result['changed_factors']);
        self::assertFalse(in_array('x', $result['changed_factors'], true));
    }

    public function testEqualMeasuredContributionLeavesUnequalBaselineUnchanged(): void
    {
        // Unequal baseline + identical contribution mass: the maths leaves every weight at its
        // baseline share, so changed_factors must stay empty. Naive abs(delta) > 0 detection would
        // report phantom changes from floating-point normalisation residue (~1e-16) here.
        $result = $this->service->propose(
            ['a' => 0.6, 'b' => 0.3, 'c' => 0.1],
            [
                ['factor_id' => 'a', 'contribution_score' => 0.5, 'confidence' => 0.9, 'p5_evidence_ref' => 'p5://a'],
                ['factor_id' => 'b', 'contribution_score' => 0.5, 'confidence' => 0.9, 'p5_evidence_ref' => 'p5://b'],
                ['factor_id' => 'c', 'contribution_score' => 0.5, 'confidence' => 0.9, 'p5_evidence_ref' => 'p5://c'],
            ],
        );

        self::assertEqualsWithDelta(0.6, $result['proposed_weights']['a'], 1.0e-9);
        self::assertEqualsWithDelta(0.3, $result['proposed_weights']['b'], 1.0e-9);
        self::assertEqualsWithDelta(0.1, $result['proposed_weights']['c'], 1.0e-9);
        self::assertSame([], $result['changed_factors']);
        self::assertEqualsWithDelta(1.0, array_sum($result['proposed_weights']), 1.0e-9);
    }

    public function testNoEffectiveContributionLeavesWeightsAndChangedFactorsStable(): void
    {
        // Every contribution is below the confidence floor, so nothing earns re-derivation mass.
        // The proposal must equal the normalised baseline and report no changed factors.
        $result = $this->service->propose(
            ['a' => 0.6, 'b' => 0.3, 'c' => 0.1],
            [
                ['factor_id' => 'a', 'contribution_score' => 5.0, 'confidence' => 0.2, 'p5_evidence_ref' => 'p5://a'],
                ['factor_id' => 'b', 'contribution_score' => 5.0, 'confidence' => 0.2, 'p5_evidence_ref' => 'p5://b'],
                ['factor_id' => 'c', 'contribution_score' => 5.0, 'confidence' => 0.2, 'p5_evidence_ref' => 'p5://c'],
            ],
        );

        self::assertSame([], $result['changed_factors']);
        self::assertEqualsWithDelta(0.6, $result['proposed_weights']['a'], 1.0e-9);
        self::assertEqualsWithDelta(0.1, $result['proposed_weights']['c'], 1.0e-9);
    }

    public function testMaterialShiftForcesOperatorVeto(): void
    {
        $result = $this->service->propose(
            ['big' => 0.5, 'small' => 0.5],
            [
                ['factor_id' => 'big', 'contribution_score' => 2.0, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://b'],
                ['factor_id' => 'small', 'contribution_score' => 0.0, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://s'],
            ],
        );

        // A large measured swing crosses the material-shift threshold.
        self::assertTrue($result['operator_veto_required']);
        self::assertGreaterThan(
            L8MetaCompoundingWeightRederivationService::MATERIAL_SHIFT_THRESHOLD,
            abs($result['proposed_weights']['big'] - 0.5),
        );
    }

    public function testOperatorVetoSlotAlwaysPresent(): void
    {
        // Even a tiny, sub-threshold change must keep the operator's veto slot true.
        $result = $this->service->propose(
            ['p' => 0.5, 'q' => 0.5],
            [
                ['factor_id' => 'p', 'contribution_score' => 0.3, 'confidence' => 0.9, 'p5_evidence_ref' => 'p5://p'],
                ['factor_id' => 'q', 'contribution_score' => 0.3, 'confidence' => 0.9, 'p5_evidence_ref' => 'p5://q'],
            ],
        );

        self::assertTrue($result['operator_veto_required']);
    }

    public function testMissingP5EvidenceBlocksAndLeavesWeightsUnchanged(): void
    {
        $current = ['memory' => 0.6, 'evidence' => 0.4];

        $result = $this->service->propose(
            $current,
            [
                ['factor_id' => 'memory', 'contribution_score' => 0.9, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://m'],
                // Second contribution has no P5 evidence => whole proposal is blocked.
                ['factor_id' => 'evidence', 'contribution_score' => 0.9, 'confidence' => 1.0],
            ],
        );

        self::assertSame('blocked', $result['status']);
        self::assertContains('missing_p5_evidence', $result['blockers']);
        // Blocked => no re-derivation; the proposal equals the (normalised) current weights.
        self::assertEqualsWithDelta(0.6, $result['proposed_weights']['memory'], 1.0e-9);
        self::assertEqualsWithDelta(0.4, $result['proposed_weights']['evidence'], 1.0e-9);
        self::assertSame([], $result['changed_factors']);
    }

    public function testEmptyP5EvidenceRefStillBlocks(): void
    {
        $result = $this->service->propose(
            ['a' => 0.5, 'b' => 0.5],
            [
                ['factor_id' => 'a', 'contribution_score' => 0.5, 'confidence' => 1.0, 'p5_evidence_ref' => '   '],
                ['factor_id' => 'b', 'contribution_score' => 0.5, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://b'],
            ],
        );

        self::assertSame('blocked', $result['status']);
        self::assertContains('missing_p5_evidence', $result['blockers']);
    }

    public function testNoContributionsBlocks(): void
    {
        $result = $this->service->propose(['a' => 0.5, 'b' => 0.5], []);

        self::assertSame('blocked', $result['status']);
        self::assertContains('no_contributions', $result['blockers']);
        self::assertEqualsWithDelta(1.0, array_sum($result['proposed_weights']), 1.0e-9);
    }

    public function testProposalNeverWritesConfigOrState(): void
    {
        $result = $this->service->propose(
            ['memory' => 0.5, 'evidence' => 0.5],
            [
                ['factor_id' => 'memory', 'contribution_score' => 0.6, 'confidence' => 0.9, 'p5_evidence_ref' => 'p5://m'],
                ['factor_id' => 'evidence', 'contribution_score' => 0.4, 'confidence' => 0.9, 'p5_evidence_ref' => 'p5://e'],
            ],
        );

        // The service only proposes — the rollback plan proves no mutation was applied.
        self::assertFalse($result['rollback_plan']['mutation_applied']);
        self::assertSame('restore_current_weights', $result['rollback_plan']['strategy']);
    }

    public function testRollbackPlanRestoresCurrentWeights(): void
    {
        $current = ['alpha' => 0.7, 'beta' => 0.3];

        $result = $this->service->propose(
            $current,
            [
                ['factor_id' => 'alpha', 'contribution_score' => 1.2, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://a'],
                ['factor_id' => 'beta', 'contribution_score' => 0.1, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://b'],
            ],
        );

        // Rollback restores the normalised current weights exactly, regardless of the proposal.
        self::assertEqualsWithDelta(0.7, $result['rollback_plan']['restore_weights']['alpha'], 1.0e-9);
        self::assertEqualsWithDelta(0.3, $result['rollback_plan']['restore_weights']['beta'], 1.0e-9);
        self::assertEqualsWithDelta(1.0, array_sum($result['rollback_plan']['restore_weights']), 1.0e-9);
    }

    public function testProposedWeightsRemainWithinUnitBoundForExtremeContribution(): void
    {
        $result = $this->service->propose(
            ['dominant' => 0.5, 'minor' => 0.5],
            [
                ['factor_id' => 'dominant', 'contribution_score' => 1000.0, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://d'],
                ['factor_id' => 'minor', 'contribution_score' => 0.0, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://mn'],
            ],
        );

        // No single weight may exceed 1.0 and none may go negative, even under an extreme swing.
        foreach ($result['proposed_weights'] as $weight) {
            self::assertGreaterThanOrEqual(0.0, $weight);
            self::assertLessThanOrEqual(1.0, $weight);
        }
        self::assertEqualsWithDelta(1.0, array_sum($result['proposed_weights']), 1.0e-9);
    }

    public function testNonFiniteContributionCannotPoisonProposedWeights(): void
    {
        // A malformed INF/NAN contribution_score (or confidence) must carry no mass and
        // never leak into the normalisation: an unguarded non-finite value turns every
        // proposed weight into NaN, breaking the sum-to-1.0 and 0..1 bounds.
        $result = $this->service->propose(
            ['a' => 0.5, 'b' => 0.5],
            [
                ['factor_id' => 'a', 'contribution_score' => INF, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://a'],
                ['factor_id' => 'b', 'contribution_score' => NAN, 'confidence' => NAN, 'p5_evidence_ref' => 'p5://b'],
            ],
        );

        self::assertSame('proposed', $result['status']);
        self::assertEqualsWithDelta(1.0, array_sum($result['proposed_weights']), 1.0e-9);
        foreach ($result['proposed_weights'] as $weight) {
            self::assertTrue(is_finite($weight), 'proposed weight must stay finite');
            self::assertGreaterThanOrEqual(0.0, $weight);
            self::assertLessThanOrEqual(1.0, $weight);
        }
        // INF/NAN contribution earns no mass, so the symmetric baseline stays balanced.
        self::assertEqualsWithDelta(0.5, $result['proposed_weights']['a'], 1.0e-9);
        self::assertEqualsWithDelta(0.5, $result['proposed_weights']['b'], 1.0e-9);
    }

    public function testNonFiniteBaselineWeightStillNormalisesToUnitSum(): void
    {
        // A malformed INF baseline weight must be floored (no signal), not propagated as
        // NaN through the baseline normalisation.
        $result = $this->service->propose(
            ['a' => INF, 'b' => 0.5],
            [
                ['factor_id' => 'a', 'contribution_score' => 0.1, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://a'],
                ['factor_id' => 'b', 'contribution_score' => 0.1, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://b'],
            ],
        );

        self::assertEqualsWithDelta(1.0, array_sum($result['proposed_weights']), 1.0e-9);
        foreach ($result['proposed_weights'] as $weight) {
            self::assertTrue(is_finite($weight), 'proposed weight must stay finite');
            self::assertGreaterThanOrEqual(0.0, $weight);
            self::assertLessThanOrEqual(1.0, $weight);
        }
    }

    public function testSchemaVersionIsStable(): void
    {
        $result = $this->service->propose(
            ['a' => 1.0],
            [['factor_id' => 'a', 'contribution_score' => 0.5, 'confidence' => 0.9, 'p5_evidence_ref' => 'p5://a']],
        );

        self::assertSame('atlas.aaeos.l8.meta_compounding.weight_rederivation.v1', $result['schema_version']);
        self::assertSame(
            'atlas.aaeos.l8.meta_compounding.weight_rederivation.v1',
            L8MetaCompoundingWeightRederivationService::SCHEMA_VERSION,
        );
    }

    public function testChangedFactorsIsAListOfStrings(): void
    {
        $result = $this->service->propose(
            ['m' => 0.3, 'e' => 0.3, 'c' => 0.4],
            [
                ['factor_id' => 'm', 'contribution_score' => 0.9, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://m'],
                ['factor_id' => 'e', 'contribution_score' => 0.1, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://e'],
                ['factor_id' => 'c', 'contribution_score' => 0.2, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://c'],
            ],
        );

        self::assertSame(array_values($result['changed_factors']), $result['changed_factors']);
        foreach ($result['changed_factors'] as $factorId) {
            self::assertIsString($factorId);
        }
    }

    public function testChangedFactorsKeepStringTypeAndStringSortForNumericIds(): void
    {
        // Numeric-string factor ids exercise the list<string> contract under PHP's
        // numeric-key coercion: array keys collapse '10'/'2' to int, so changed_factors
        // must re-cast to string AND order lexicographically (SORT_STRING), not numerically.
        $result = $this->service->propose(
            ['10' => 0.2, '2' => 0.2, '100' => 0.2, '3' => 0.2, '20' => 0.2],
            [
                ['factor_id' => '10', 'contribution_score' => 0.9, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://10'],
                ['factor_id' => '2', 'contribution_score' => 0.1, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://2'],
                ['factor_id' => '100', 'contribution_score' => 0.5, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://100'],
                ['factor_id' => '3', 'contribution_score' => 0.3, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://3'],
                ['factor_id' => '20', 'contribution_score' => 0.2, 'confidence' => 1.0, 'p5_evidence_ref' => 'p5://20'],
            ],
        );

        // Every element is a string (not an int coerced back from the array key).
        foreach ($result['changed_factors'] as $factorId) {
            self::assertIsString($factorId);
        }
        // Lexicographic (SORT_STRING) ordering, not numeric: '10' < '100' < '2' < '20' < '3'.
        self::assertSame(['10', '100', '2', '20', '3'], $result['changed_factors']);
        self::assertEqualsWithDelta(1.0, array_sum($result['proposed_weights']), 1.0e-9);
    }
}
