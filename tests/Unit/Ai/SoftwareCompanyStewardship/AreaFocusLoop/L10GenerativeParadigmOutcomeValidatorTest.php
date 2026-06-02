<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10GenerativeParadigmOutcomeValidator;
use PHPUnit\Framework\TestCase;

final class L10GenerativeParadigmOutcomeValidatorTest extends TestCase
{
    private L10GenerativeParadigmOutcomeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new L10GenerativeParadigmOutcomeValidator();
    }

    public function testProvenNoveltyRetainedOutcomeSafetyAndOperatorAdmissionValidates(): void
    {
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.effect_lanes.v1',
                'safety_refs' => ['evidence://safety/invariant-set-1'],
            ],
            ['novelty_status' => 'new', 'novelty_score' => 0.91],
            [
                'retained_delta' => 0.27,
                'invariant_violation_count' => 0,
            ],
            ['admit_to_canon' => true, 'decision' => 'admit'],
        );

        $this->assertSame('atlas.aaeos.l10.generative_paradigm_outcome_validation.v1', $result['schema_version']);
        $this->assertTrue($result['validated']);
        $this->assertEqualsWithDelta(0.27, $result['retained_delta'], 1e-9);
        $this->assertTrue($result['invariant_safe']);
        $this->assertTrue($result['canon_admitted']);
        $this->assertSame([], $result['blockers']);
    }

    public function testUnknownNoveltyBlocks(): void
    {
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.maybe_new.v1',
                'safety_refs' => ['evidence://safety/invariant-set-2'],
            ],
            ['novelty_status' => 'unknown_not_new', 'novelty_score' => 0.10],
            ['retained_delta' => 0.40, 'invariant_violation_count' => 0],
            ['admit_to_canon' => true, 'decision' => 'admit'],
        );

        $this->assertFalse($result['validated']);
        $this->assertFalse($result['canon_admitted']);
        $this->assertSame(['novelty_not_new'], $result['blockers']);
    }

    public function testNonPositiveRetainedDeltaBlocksAtZeroBoundary(): void
    {
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.neutral_outcome.v1',
                'safety_refs' => ['evidence://safety/invariant-set-3'],
            ],
            ['novelty_status' => 'new'],
            ['retained_delta' => 0.0, 'invariant_violation_count' => 0],
            ['admit_to_canon' => true, 'decision' => 'admit'],
        );

        $this->assertFalse($result['validated']);
        $this->assertFalse($result['canon_admitted']);
        $this->assertEqualsWithDelta(0.0, $result['retained_delta'], 1e-9);
        $this->assertSame(['non_positive_retained_delta'], $result['blockers']);
    }

    public function testNegativeRetainedDeltaBlocks(): void
    {
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.faded_outcome.v1',
                'safety_refs' => ['evidence://safety/invariant-set-4'],
            ],
            ['novelty_status' => 'new'],
            ['retained_delta' => -0.08, 'invariant_violation_count' => 0],
            ['admit_to_canon' => true, 'decision' => 'admit'],
        );

        $this->assertFalse($result['validated']);
        $this->assertFalse($result['canon_admitted']);
        $this->assertEqualsWithDelta(-0.08, $result['retained_delta'], 1e-9);
        $this->assertSame(['non_positive_retained_delta'], $result['blockers']);
    }

    public function testNoOperatorCanonDecisionBlocks(): void
    {
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.unadopted.v1',
                'safety_refs' => ['evidence://safety/invariant-set-5'],
            ],
            ['novelty_status' => 'new'],
            ['retained_delta' => 0.33, 'invariant_violation_count' => 0],
            ['admit_to_canon' => false],
        );

        $this->assertFalse($result['validated']);
        $this->assertFalse($result['canon_admitted']);
        $this->assertSame(['no_operator_canon_decision'], $result['blockers']);
    }

    public function testImplicitOperatorDecisionWithoutAdmitFlagBlocks(): void
    {
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.implicit.v1',
                'safety_refs' => ['evidence://safety/invariant-set-6'],
            ],
            ['novelty_status' => 'new'],
            ['retained_delta' => 0.19, 'invariant_violation_count' => 0],
            ['decision' => 'admit'],
        );

        $this->assertFalse($result['validated']);
        $this->assertFalse($result['canon_admitted']);
        $this->assertSame(['no_operator_canon_decision'], $result['blockers']);
    }

    public function testOperatorRejectDecisionBlocksEvenWithAdmitFlag(): void
    {
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.rejected.v1',
                'safety_refs' => ['evidence://safety/invariant-set-7'],
            ],
            ['novelty_status' => 'new'],
            ['retained_delta' => 0.22, 'invariant_violation_count' => 0],
            ['admit_to_canon' => true, 'decision' => 'reject'],
        );

        $this->assertFalse($result['validated']);
        $this->assertFalse($result['canon_admitted']);
        $this->assertSame(['no_operator_canon_decision'], $result['blockers']);
    }

    public function testReportedInvariantViolationMakesUnsafeAndBlocks(): void
    {
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.breaks_invariant.v1',
                'safety_refs' => ['evidence://safety/invariant-set-8'],
            ],
            ['novelty_status' => 'new'],
            ['retained_delta' => 0.45, 'invariant_violation_count' => 2],
            ['admit_to_canon' => true, 'decision' => 'admit'],
        );

        $this->assertFalse($result['validated']);
        $this->assertFalse($result['invariant_safe']);
        $this->assertFalse($result['canon_admitted']);
        $this->assertSame(['invariant_unsafe'], $result['blockers']);
    }

    public function testMissingSafetyProofFailsClosedAsUnsafeAndBlocks(): void
    {
        $result = $this->validator->validate(
            ['paradigm_candidate_id' => 'paradigm.no_safety_proof.v1'],
            ['novelty_status' => 'new'],
            ['retained_delta' => 0.31, 'invariant_violation_count' => 0],
            ['admit_to_canon' => true, 'decision' => 'admit'],
        );

        $this->assertFalse($result['validated']);
        $this->assertFalse($result['invariant_safe']);
        $this->assertFalse($result['canon_admitted']);
        $this->assertSame(['invariant_unsafe'], $result['blockers']);
    }

    public function testAllFourFailuresAreReportedInRuleOrder(): void
    {
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.broken.v1',
                'invariant_violation_count' => 3,
            ],
            ['novelty_status' => 'known'],
            ['retained_delta' => -1.20],
            ['admit_to_canon' => false],
        );

        $this->assertFalse($result['validated']);
        $this->assertFalse($result['invariant_safe']);
        $this->assertFalse($result['canon_admitted']);
        $this->assertEqualsWithDelta(-1.20, $result['retained_delta'], 1e-9);
        $this->assertSame(
            ['novelty_not_new', 'non_positive_retained_delta', 'invariant_unsafe', 'no_operator_canon_decision'],
            $result['blockers'],
        );
    }

    public function testNestedMetricsAndSafetyProofAreRead(): void
    {
        $result = $this->validator->validate(
            ['paradigm_candidate_id' => 'paradigm.nested.v1'],
            ['novelty_status' => 'new'],
            [
                'retained_metrics' => ['retained_delta' => 0.36],
                'safety_proof' => [
                    'invariant_violation_count' => 0,
                    'safety_refs' => ['evidence://safety/nested-proof-1'],
                ],
            ],
            ['admit_to_canon' => true, 'decision' => 'admit'],
        );

        $this->assertTrue($result['validated']);
        $this->assertEqualsWithDelta(0.36, $result['retained_delta'], 1e-9);
        $this->assertTrue($result['invariant_safe']);
        $this->assertTrue($result['canon_admitted']);
        $this->assertSame([], $result['blockers']);
    }

    public function testMissingRetainedSignalFailsClosedAsNegative(): void
    {
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.no_retained_signal.v1',
                'safety_refs' => ['evidence://safety/invariant-set-9'],
            ],
            ['novelty_status' => 'new'],
            ['invariant_violation_count' => 0],
            ['admit_to_canon' => true, 'decision' => 'admit'],
        );

        $this->assertFalse($result['validated']);
        $this->assertEqualsWithDelta(-1.0, $result['retained_delta'], 1e-9);
        $this->assertSame(['non_positive_retained_delta'], $result['blockers']);
    }

    public function testNegativeViolationCountIsClampedAndDoesNotFalselyBlock(): void
    {
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.garbled_violation.v1',
                'safety_refs' => ['evidence://safety/invariant-set-10'],
            ],
            ['novelty_status' => 'new'],
            ['retained_delta' => 0.14, 'invariant_violation_count' => -5],
            ['admit_to_canon' => true, 'decision' => 'admit'],
        );

        $this->assertTrue($result['validated']);
        $this->assertTrue($result['invariant_safe']);
        $this->assertTrue($result['canon_admitted']);
        $this->assertSame([], $result['blockers']);
    }

    public function testNonFiniteNanRetainedDeltaFailsClosedAndBlocks(): void
    {
        // A NaN retained signal is undefined, never a proven positive improvement.
        // The <= 0 gate alone would let it through (NaN <= 0 is false in PHP), so
        // the kernel must fail closed on non-finite retained deltas.
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.nan_outcome.v1',
                'safety_refs' => ['evidence://safety/invariant-set-12'],
            ],
            ['novelty_status' => 'new'],
            ['retained_delta' => NAN, 'invariant_violation_count' => 0],
            ['admit_to_canon' => true, 'decision' => 'admit'],
        );

        $this->assertFalse($result['validated']);
        $this->assertFalse($result['canon_admitted']);
        $this->assertSame(['non_positive_retained_delta'], $result['blockers']);
    }

    public function testNonFinitePositiveInfinityRetainedDeltaFailsClosedAndBlocks(): void
    {
        // A +INF retained signal is undefined (e.g. a zero-baseline ratio metric),
        // never a proven measured improvement. The <= 0 gate alone would let it
        // through (INF <= 0 is false, just like NaN), so the kernel must fail
        // closed on non-finite retained deltas of either sign.
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.inf_outcome.v1',
                'safety_refs' => ['evidence://safety/invariant-set-13'],
            ],
            ['novelty_status' => 'new'],
            ['retained_delta' => INF, 'invariant_violation_count' => 0],
            ['admit_to_canon' => true, 'decision' => 'admit'],
        );

        $this->assertFalse($result['validated']);
        $this->assertFalse($result['canon_admitted']);
        $this->assertSame(['non_positive_retained_delta'], $result['blockers']);
        // The returned retained_delta is the fail-closed sentinel, never +INF.
        $this->assertEqualsWithDelta(-1.0, $result['retained_delta'], 1e-9);
    }

    public function testNonFiniteNumericStringRetainedDeltaFailsClosedAndBlocks(): void
    {
        // A serialized over-range numeric string parses to +INF; it must also fail
        // closed rather than validate a paradigm on a degenerate magnitude.
        $result = $this->validator->validate(
            [
                'paradigm_candidate_id' => 'paradigm.overflow_string_outcome.v1',
                'safety_refs' => ['evidence://safety/invariant-set-14'],
            ],
            ['novelty_status' => 'new'],
            ['retained_delta' => '1e400', 'invariant_violation_count' => 0],
            ['admit_to_canon' => true, 'decision' => 'admit'],
        );

        $this->assertFalse($result['validated']);
        $this->assertFalse($result['canon_admitted']);
        $this->assertEqualsWithDelta(-1.0, $result['retained_delta'], 1e-9);
        $this->assertSame(['non_positive_retained_delta'], $result['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $candidate = [
            'paradigm_candidate_id' => 'paradigm.stable.v1',
            'safety_refs' => ['evidence://safety/invariant-set-11'],
        ];
        $novelty = ['novelty_status' => 'new'];
        $outcomes = ['retained_delta' => 0.17, 'invariant_violation_count' => 0];
        $operatorDecision = ['admit_to_canon' => true, 'decision' => 'admit'];

        $first = $this->validator->validate($candidate, $novelty, $outcomes, $operatorDecision);
        $second = $this->validator->validate($candidate, $novelty, $outcomes, $operatorDecision);

        $this->assertSame($first, $second);
    }
}
