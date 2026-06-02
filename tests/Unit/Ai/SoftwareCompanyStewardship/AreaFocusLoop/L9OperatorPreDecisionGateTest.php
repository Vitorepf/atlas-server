<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9OperatorPreDecisionGate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class L9OperatorPreDecisionGateTest extends TestCase
{
    private L9OperatorPreDecisionGate $gate;

    protected function setUp(): void
    {
        $this->gate = new L9OperatorPreDecisionGate();
    }

    /**
     * @return array<string,mixed>
     */
    private function governedModelSpec(): array
    {
        return [
            'model_spec_id' => 'atlas.l9.judgment_spec.v1',
            'override_contract' => ['channel' => 'operator_override'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function provenBoundary(): array
    {
        return [
            'allowed_decision_classes' => ['format_only', 'low_risk_refactor'],
            'max_risk_level' => 'medium',
        ];
    }

    public function testInsideBoundaryWithEvidenceAndConfidenceAllowsAutoPreDecision(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'low_risk_refactor',
                'risk_level' => 'low',
                'confidence' => 0.92,
                'evidence_refs' => ['receipt:abc', 'proof:xyz'],
            ],
            $this->governedModelSpec(),
            $this->provenBoundary(),
        );

        $this->assertSame('atlas.aaeos.l9.operator_pre_decision_gate.v1', $result['schema_version']);
        $this->assertTrue($result['allowed']);
        $this->assertSame('allow_auto', $result['decision_class']);
        $this->assertFalse($result['required_human_review']);
        $this->assertSame(['receipt:abc', 'proof:xyz'], $result['evidence_refs']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['inside_boundary']);
        $this->assertTrue($result['operator_override_available']);
        $this->assertSame(0.92, $result['confidence']);
        $this->assertSame(0.70, $result['confidence_threshold']);
        $this->assertSame(0.0, $result['confidence_deficit']);
    }

    public function testDecisionClassOutsideBoundaryRequiresHumanReview(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'production_merge',
                'risk_level' => 'low',
                'confidence' => 0.99,
                'evidence_refs' => ['receipt:abc'],
            ],
            $this->governedModelSpec(),
            $this->provenBoundary(),
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('require_review', $result['decision_class']);
        $this->assertTrue($result['required_human_review']);
        $this->assertFalse($result['inside_boundary']);
        $this->assertContains('decision_class_outside_boundary', $result['blockers']);
        $this->assertTrue($result['operator_override_available']);
    }

    public function testRiskAboveProvenBoundaryRequiresHumanReview(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'low_risk_refactor',
                'risk_level' => 'critical',
                'confidence' => 0.99,
                'evidence_refs' => ['receipt:abc'],
            ],
            $this->governedModelSpec(),
            $this->provenBoundary(),
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('require_review', $result['decision_class']);
        $this->assertTrue($result['required_human_review']);
        $this->assertFalse($result['inside_boundary']);
        $this->assertContains('risk_above_proven_boundary', $result['blockers']);
    }

    public function testLowConfidenceBlocks(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'low_risk_refactor',
                'risk_level' => 'low',
                'confidence' => 0.40,
                'evidence_refs' => ['receipt:abc'],
            ],
            $this->governedModelSpec(),
            $this->provenBoundary(),
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('require_review', $result['decision_class']);
        $this->assertTrue($result['required_human_review']);
        $this->assertContains('confidence_below_threshold', $result['blockers']);
        $this->assertEqualsWithDelta(0.30, $result['confidence_deficit'], 0.0001);
    }

    /**
     * A confidence just below the 0.70 threshold — inside the sub-rounding band
     * (its raw deficit 0.00001 rounds to 0.0) — is still below the proven
     * threshold and MUST block. The block decision may not ride on the rounded
     * deficit, or the gate fails open and auto-approves under-confident decisions.
     */
    public function testConfidenceJustBelowThresholdStillBlocksDespiteRoundingToZeroDeficit(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'low_risk_refactor',
                'risk_level' => 'low',
                'confidence' => 0.69999,
                'evidence_refs' => ['receipt:abc'],
            ],
            $this->governedModelSpec(),
            $this->provenBoundary(),
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('require_review', $result['decision_class']);
        $this->assertTrue($result['required_human_review']);
        $this->assertContains('confidence_below_threshold', $result['blockers']);
    }

    /**
     * Confidence exactly at the threshold is sufficient (>= passes): it must NOT
     * raise the low-confidence blocker.
     */
    public function testConfidenceExactlyAtThresholdIsSufficient(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'low_risk_refactor',
                'risk_level' => 'low',
                'confidence' => 0.70,
                'evidence_refs' => ['receipt:abc'],
            ],
            $this->governedModelSpec(),
            $this->provenBoundary(),
        );

        $this->assertTrue($result['allowed']);
        $this->assertNotContains('confidence_below_threshold', $result['blockers']);
        $this->assertSame(0.0, $result['confidence_deficit']);
    }

    /**
     * @return iterable<string,array{float}>
     */
    public static function nonFiniteConfidences(): iterable
    {
        yield 'NAN' => [NAN];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
    }

    /**
     * A non-finite confidence (NAN or ±INF) is not a real measurement of
     * sufficient confidence and must fail closed: NAN slips past every numeric
     * comparison (NAN < threshold is false) and +INF would clamp up to 1.0 and
     * masquerade as maximum confidence — either path would auto-approve an
     * under-confident pre-decision. The gate must block and never leak a
     * non-finite confidence into the 0..1 output field.
     */
    #[DataProvider('nonFiniteConfidences')]
    public function testNonFiniteConfidenceFailsClosed(float $confidence): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'low_risk_refactor',
                'risk_level' => 'low',
                'confidence' => $confidence,
                'evidence_refs' => ['receipt:abc'],
            ],
            $this->governedModelSpec(),
            $this->provenBoundary(),
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('require_review', $result['decision_class']);
        $this->assertTrue($result['required_human_review']);
        $this->assertContains('confidence_below_threshold', $result['blockers']);
        $this->assertSame(0.0, $result['confidence']);
        $this->assertTrue(is_finite($result['confidence']));
        $this->assertEqualsWithDelta(0.70, $result['confidence_deficit'], 0.0001);
    }

    public function testMissingEvidenceBlocks(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'low_risk_refactor',
                'risk_level' => 'low',
                'confidence' => 0.95,
                'evidence_refs' => [],
            ],
            $this->governedModelSpec(),
            $this->provenBoundary(),
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('require_review', $result['decision_class']);
        $this->assertTrue($result['required_human_review']);
        $this->assertSame([], $result['evidence_refs']);
        $this->assertContains('evidence_missing', $result['blockers']);
    }

    public function testUngovernedModelSpecBlocks(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'low_risk_refactor',
                'risk_level' => 'low',
                'confidence' => 0.95,
                'evidence_refs' => ['receipt:abc'],
            ],
            [
                'model_spec_id' => '',
                'override_contract' => [],
            ],
            $this->provenBoundary(),
        );

        $this->assertFalse($result['allowed']);
        $this->assertTrue($result['required_human_review']);
        $this->assertContains('model_spec_not_governed', $result['blockers']);
    }

    /**
     * @return iterable<string,array{mixed}>
     */
    public static function blankOverrideContracts(): iterable
    {
        yield 'boolean false' => [false];
        yield 'integer zero' => [0];
        yield 'float zero' => [0.0];
        yield 'null' => [null];
        yield 'empty array' => [[]];
        yield 'empty string' => [''];
        yield 'whitespace string' => ['   '];
    }

    /**
     * The L9 sovereignty gate may only pre-decide while the operator override
     * path is genuinely open. A falsy or blank override contract carries no
     * usable override and must route to human review, never allow_auto.
     */
    #[DataProvider('blankOverrideContracts')]
    public function testBlankOperatorOverridePathBlocksAutoDecision(mixed $override): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'low_risk_refactor',
                'risk_level' => 'low',
                'confidence' => 0.99,
                'evidence_refs' => ['receipt:abc'],
            ],
            [
                'model_spec_id' => 'atlas.l9.judgment_spec.v1',
                'override_contract' => $override,
            ],
            $this->provenBoundary(),
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('require_review', $result['decision_class']);
        $this->assertTrue($result['required_human_review']);
        $this->assertFalse($result['inside_boundary']);
        $this->assertContains('model_spec_not_governed', $result['blockers']);
        $this->assertTrue($result['operator_override_available']);
    }

    public function testNonBlankStringOverrideContractIsAccepted(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'low_risk_refactor',
                'risk_level' => 'low',
                'confidence' => 0.99,
                'evidence_refs' => ['receipt:abc'],
            ],
            [
                'model_spec_id' => 'atlas.l9.judgment_spec.v1',
                'override_contract' => 'operator_override_channel',
            ],
            $this->provenBoundary(),
        );

        $this->assertTrue($result['allowed']);
        $this->assertSame('allow_auto', $result['decision_class']);
        $this->assertTrue($result['inside_boundary']);
        $this->assertNotContains('model_spec_not_governed', $result['blockers']);
    }

    public function testEmptyBoundaryBlocksEveryRequestedClass(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'format_only',
                'risk_level' => 'low',
                'confidence' => 0.99,
                'evidence_refs' => ['receipt:abc'],
            ],
            $this->governedModelSpec(),
            [
                'allowed_decision_classes' => [],
                'max_risk_level' => 'medium',
            ],
        );

        $this->assertFalse($result['allowed']);
        $this->assertFalse($result['inside_boundary']);
        $this->assertContains('decision_class_outside_boundary', $result['blockers']);
    }

    /**
     * The boundary's allowed_decision_classes must be normalized on the SAME side
     * as the requested class: the request is trimmed, so a whitespace-padded
     * boundary entry like " low_risk_refactor " names the same proven class and
     * must still match. Otherwise a legitimately in-boundary request is wrongly
     * routed to human review (a false negative on "pre-decision collapses latency
     * within proven limits").
     */
    public function testWhitespacePaddedBoundaryClassStillMatchesTrimmedRequest(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'low_risk_refactor',
                'risk_level' => 'low',
                'confidence' => 0.99,
                'evidence_refs' => ['receipt:abc'],
            ],
            $this->governedModelSpec(),
            [
                'allowed_decision_classes' => ['  low_risk_refactor  ', 'format_only'],
                'max_risk_level' => 'medium',
            ],
        );

        $this->assertTrue($result['allowed']);
        $this->assertSame('allow_auto', $result['decision_class']);
        $this->assertTrue($result['inside_boundary']);
        $this->assertNotContains('decision_class_outside_boundary', $result['blockers']);
    }

    public function testMultipleViolationsAccumulateInDeterministicOrder(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'production_merge',
                'risk_level' => 'critical',
                'confidence' => 0.10,
                'evidence_refs' => [],
            ],
            $this->governedModelSpec(),
            $this->provenBoundary(),
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('require_review', $result['decision_class']);
        $this->assertSame(
            [
                'decision_class_outside_boundary',
                'risk_above_proven_boundary',
                'confidence_below_threshold',
                'evidence_missing',
            ],
            $result['blockers'],
        );
    }

    public function testConfidenceNeverExceedsOneAndEvidenceStaysListOfStrings(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'low_risk_refactor',
                'risk_level' => 'low',
                'confidence' => 4.2,
                'evidence_refs' => ['receipt:abc', '', 42, 'proof:xyz', 'receipt:abc'],
            ],
            $this->governedModelSpec(),
            $this->provenBoundary(),
        );

        $this->assertSame(1.0, $result['confidence']);
        $this->assertLessThanOrEqual(1.0, $result['confidence']);
        $this->assertSame(0.0, $result['confidence_deficit']);
        $this->assertSame(['receipt:abc', 'proof:xyz'], $result['evidence_refs']);
        $this->assertSame(array_values($result['evidence_refs']), $result['evidence_refs']);
        foreach ($result['evidence_refs'] as $ref) {
            $this->assertIsString($ref);
        }
        $this->assertTrue($result['allowed']);
    }

    public function testGeneralisesToUnseenBoundaryAndClass(): void
    {
        $result = $this->gate->decide(
            [
                'decision_class' => 'dependency_bump',
                'risk_level' => 'high',
                'confidence' => 0.81,
                'evidence_refs' => ['ledger:42'],
            ],
            [
                'model_spec_id' => 'atlas.l9.spec.v2',
                'override_contract' => ['channel' => 'op'],
            ],
            [
                'allowed_decision_classes' => ['dependency_bump', 'doc_edit'],
                'max_risk_level' => 'high',
            ],
        );

        $this->assertTrue($result['allowed']);
        $this->assertSame('allow_auto', $result['decision_class']);
        $this->assertFalse($result['required_human_review']);
        $this->assertTrue($result['inside_boundary']);
        $this->assertSame(['ledger:42'], $result['evidence_refs']);
        $this->assertSame([], $result['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $request = [
            'decision_class' => 'low_risk_refactor',
            'risk_level' => 'medium',
            'confidence' => 0.88,
            'evidence_refs' => ['receipt:abc'],
        ];
        $modelSpec = $this->governedModelSpec();
        $boundary = $this->provenBoundary();

        $first = $this->gate->decide($request, $modelSpec, $boundary);
        $second = $this->gate->decide($request, $modelSpec, $boundary);

        $this->assertSame($first, $second);
    }
}
