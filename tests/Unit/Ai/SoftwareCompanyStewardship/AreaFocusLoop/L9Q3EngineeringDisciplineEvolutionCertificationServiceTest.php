<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9Q3EngineeringDisciplineEvolutionCertificationService;
use PHPUnit\Framework\TestCase;

final class L9Q3EngineeringDisciplineEvolutionCertificationServiceTest extends TestCase
{
    private L9Q3EngineeringDisciplineEvolutionCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new L9Q3EngineeringDisciplineEvolutionCertificationService();
    }

    public function testValidatedRetainedMethodWithQ2BoundaryCertifiesQ3(): void
    {
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.review_split.v1', 'validated' => true, 'retained' => true],
                ['method_candidate_id' => 'method.replay_gate.v1', 'validated' => true, 'reverted' => false],
            ],
            'multi_lineage_multiplier' => 1.8,
            'single_lineage_multiplier' => 1.3,
            'q2' => ['q2_certified' => true],
        ]);

        $this->assertSame(
            'atlas.aaeos.l9.q3_engineering_discipline_evolution_certification.v1',
            $result['schema_version'],
        );
        $this->assertSame('L9-Q3', $result['phase']);
        $this->assertTrue($result['q3_certified']);
        $this->assertSame('q3_certified', $result['status']);
        $this->assertSame(2, $result['validated_method_count']);
        $this->assertSame(2, $result['retained_method_count']);
        $this->assertEqualsWithDelta(0.5, $result['lineage_multiplier_delta'], 1e-9);
        $this->assertTrue($result['lineage_multiplier_positive']);
        $this->assertTrue($result['q2_boundary_present']);
        $this->assertSame([], $result['blockers']);
    }

    public function testZeroValidatedMethodsBlocks(): void
    {
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.unproven.v1', 'validated' => false, 'retained' => true],
                ['method_candidate_id' => 'method.style_only.v1', 'retained' => true],
            ],
            'lineage_multiplier_delta' => 0.4,
            'q2' => ['q2_certified' => true],
        ]);

        $this->assertFalse($result['q3_certified']);
        $this->assertSame('blocked_not_q3', $result['status']);
        $this->assertSame(0, $result['validated_method_count']);
        $this->assertSame(0, $result['retained_method_count']);
        $this->assertSame(['no_validated_method'], $result['blockers']);
    }

    public function testNonRetainedValidatedMethodBlocks(): void
    {
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.held.v1', 'validated' => true, 'retained' => true],
                ['method_candidate_id' => 'method.faded.v1', 'validated' => true, 'retained' => false],
            ],
            'lineage_multiplier_delta' => 0.25,
            'q2' => ['q2_certified' => true],
        ]);

        $this->assertFalse($result['q3_certified']);
        $this->assertSame('blocked_not_q3', $result['status']);
        $this->assertSame(2, $result['validated_method_count']);
        $this->assertSame(1, $result['retained_method_count']);
        $this->assertSame(['non_retained_method'], $result['blockers']);
    }

    public function testRevertedValidatedMethodIsNotRetainedAndBlocks(): void
    {
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.reverted.v1', 'validated' => true, 'reverted' => true],
            ],
            'lineage_multiplier_delta' => 0.30,
            'q2' => ['q2_certified' => true],
        ]);

        $this->assertFalse($result['q3_certified']);
        $this->assertSame(1, $result['validated_method_count']);
        $this->assertSame(0, $result['retained_method_count']);
        $this->assertSame(['non_retained_method'], $result['blockers']);
    }

    public function testMissingQ2BoundaryBlocks(): void
    {
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.held.v1', 'validated' => true, 'retained' => true],
            ],
            'lineage_multiplier_delta' => 0.6,
        ]);

        $this->assertFalse($result['q3_certified']);
        $this->assertSame('blocked_not_q3', $result['status']);
        $this->assertSame(1, $result['validated_method_count']);
        $this->assertSame(1, $result['retained_method_count']);
        $this->assertFalse($result['q2_boundary_present']);
        $this->assertSame(['q2_boundary_missing'], $result['blockers']);
    }

    public function testExplicitlyUncertifiedQ2BoundaryBlocks(): void
    {
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.held.v1', 'validated' => true, 'retained' => true],
            ],
            'lineage_multiplier_delta' => 0.6,
            'q2' => ['q2_certified' => false],
        ]);

        $this->assertFalse($result['q3_certified']);
        $this->assertFalse($result['q2_boundary_present']);
        $this->assertSame(['q2_boundary_missing'], $result['blockers']);
    }

    public function testAllThreeFailuresAreReportedInEnumeratedRowOrder(): void
    {
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.style_only.v1', 'validated' => false],
            ],
            'lineage_multiplier_delta' => -0.2,
            'q2' => ['q2_certified' => false],
        ]);

        // With zero validated methods the non-retained rule cannot fire (nothing
        // was validated), so only the zero-validated and Q2 blockers apply.
        $this->assertFalse($result['q3_certified']);
        $this->assertSame(0, $result['validated_method_count']);
        $this->assertSame(0, $result['retained_method_count']);
        $this->assertSame(['no_validated_method', 'q2_boundary_missing'], $result['blockers']);
    }

    public function testNonRetainedAndMissingQ2ReportInRowOrder(): void
    {
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.held.v1', 'validated' => true, 'retained' => true],
                ['method_candidate_id' => 'method.faded.v1', 'validated' => true, 'retained' => false],
            ],
            'lineage_multiplier_delta' => 0.1,
        ]);

        $this->assertFalse($result['q3_certified']);
        $this->assertSame(2, $result['validated_method_count']);
        $this->assertSame(1, $result['retained_method_count']);
        $this->assertSame(['non_retained_method', 'q2_boundary_missing'], $result['blockers']);
    }

    public function testLineageMultiplierDeltaIsComputedFromComponentsWhenNotExplicit(): void
    {
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.held.v1', 'validated' => true, 'retained' => true],
            ],
            'parallel_lineage_multiplier' => 2.4,
            'baseline_lineage_multiplier' => 1.5,
            'q2' => true,
        ]);

        $this->assertEqualsWithDelta(0.9, $result['lineage_multiplier_delta'], 1e-9);
        $this->assertTrue($result['lineage_multiplier_positive']);
        $this->assertTrue($result['q3_certified']);
        $this->assertSame([], $result['blockers']);
    }

    public function testNegativeLineageMultiplierDeltaIsNotPositiveButDoesNotBlock(): void
    {
        // The row enumerates exactly three blockers; a non-positive lineage
        // multiplier is surfaced as evidence, never a fourth gate.
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.held.v1', 'validated' => true, 'retained' => true],
            ],
            'multi_lineage_multiplier' => 1.1,
            'single_lineage_multiplier' => 1.4,
            'q2' => true,
        ]);

        $this->assertEqualsWithDelta(-0.3, $result['lineage_multiplier_delta'], 1e-9);
        $this->assertFalse($result['lineage_multiplier_positive']);
        $this->assertTrue($result['q3_certified']);
        $this->assertSame([], $result['blockers']);
    }

    public function testQ2BoundaryPresentViaConcreteProofAnchor(): void
    {
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.held.v1', 'validated' => true, 'retained' => true],
            ],
            'lineage_multiplier_delta' => 0.5,
            'q2_boundary' => ['proof_boundary_id' => 'proof://q2/invariant-merge-truth'],
        ]);

        $this->assertTrue($result['q2_boundary_present']);
        $this->assertTrue($result['q3_certified']);
        $this->assertSame([], $result['blockers']);
    }

    public function testValidatedMethodWithoutRetentionSignalFailsClosed(): void
    {
        // A method validated but carrying NO retention signal counts as validated
        // yet not retained: discipline evolution is not proven by retained outcome.
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.no_signal.v1', 'validated' => true],
            ],
            'lineage_multiplier_delta' => 0.5,
            'q2' => true,
        ]);

        $this->assertSame(1, $result['validated_method_count']);
        $this->assertSame(0, $result['retained_method_count']);
        $this->assertFalse($result['q3_certified']);
        $this->assertSame(['non_retained_method'], $result['blockers']);
    }

    public function testNonArrayMethodRecordsAreIgnored(): void
    {
        $result = $this->service->certify([
            'methods' => [
                'method.string_garbage.v1',
                42,
                ['method_candidate_id' => 'method.held.v1', 'validated' => true, 'retained' => true],
            ],
            'lineage_multiplier_delta' => 0.5,
            'q2' => true,
        ]);

        $this->assertSame(1, $result['validated_method_count']);
        $this->assertSame(1, $result['retained_method_count']);
        $this->assertTrue($result['q3_certified']);
        $this->assertSame([], $result['blockers']);
    }

    public function testMissingMethodsAndMissingQ2BothBlock(): void
    {
        $result = $this->service->certify([
            'lineage_multiplier_delta' => 0.5,
        ]);

        $this->assertFalse($result['q3_certified']);
        $this->assertSame(0, $result['validated_method_count']);
        $this->assertSame(0, $result['retained_method_count']);
        $this->assertEqualsWithDelta(0.5, $result['lineage_multiplier_delta'], 1e-9);
        $this->assertSame(['no_validated_method', 'q2_boundary_missing'], $result['blockers']);
    }

    public function testMissingLineageMultiplierComponentsDefaultDeltaToZero(): void
    {
        $result = $this->service->certify([
            'methods' => [
                ['method_candidate_id' => 'method.held.v1', 'validated' => true, 'retained' => true],
            ],
            'q2' => true,
        ]);

        $this->assertEqualsWithDelta(0.0, $result['lineage_multiplier_delta'], 1e-9);
        $this->assertFalse($result['lineage_multiplier_positive']);
        $this->assertTrue($result['q3_certified']);
    }

    public function testValidatedMethodsAlternateKeyIsRead(): void
    {
        $result = $this->service->certify([
            'validated_methods' => [
                ['method_candidate_id' => 'method.alt_key.v1', 'validated' => true, 'retained' => true],
            ],
            'lineage_multiplier_delta' => 0.5,
            'q2' => true,
        ]);

        $this->assertSame(1, $result['validated_method_count']);
        $this->assertSame(1, $result['retained_method_count']);
        $this->assertTrue($result['q3_certified']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $inputs = [
            'methods' => [
                ['method_candidate_id' => 'method.stable.v1', 'validated' => true, 'retained' => true],
            ],
            'multi_lineage_multiplier' => 2.0,
            'single_lineage_multiplier' => 1.25,
            'q2' => ['q2_certified' => true],
        ];

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        $this->assertSame($first, $second);
    }
}
