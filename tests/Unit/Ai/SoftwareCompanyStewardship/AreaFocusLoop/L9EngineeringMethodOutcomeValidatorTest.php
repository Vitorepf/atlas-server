<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9EngineeringMethodOutcomeValidator;
use PHPUnit\Framework\TestCase;

final class L9EngineeringMethodOutcomeValidatorTest extends TestCase
{
    private L9EngineeringMethodOutcomeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new L9EngineeringMethodOutcomeValidator();
    }

    public function testRetainedImprovementWithoutRegressionValidatesAndIsNotReverted(): void
    {
        $result = $this->validator->validate(
            ['method_candidate_id' => 'method.review_split.v1'],
            [
                'retained_delta' => 0.21,
                'regression_count' => 0,
                'evidence_refs' => ['evidence://replay/obra-1', 'evidence://replay/obra-2'],
            ],
        );

        $this->assertSame('atlas.aaeos.l9.engineering_method_outcome_validation.v1', $result['schema_version']);
        $this->assertTrue($result['validated']);
        $this->assertFalse($result['reverted']);
        $this->assertEqualsWithDelta(0.21, $result['retained_delta'], 1e-9);
        $this->assertSame(0, $result['regression_count']);
        $this->assertSame(['evidence://replay/obra-1', 'evidence://replay/obra-2'], $result['evidence_refs']);
        $this->assertSame([], $result['blockers']);
    }

    public function testNoOutcomeEvidenceBlocksAndReverts(): void
    {
        $result = $this->validator->validate(
            ['method_candidate_id' => 'method.unproven.v1'],
            [
                'retained_delta' => 0.40,
                'regression_count' => 0,
                'evidence_refs' => [],
            ],
        );

        $this->assertFalse($result['validated']);
        $this->assertTrue($result['reverted']);
        $this->assertSame([], $result['evidence_refs']);
        $this->assertSame(['no_outcome_evidence'], $result['blockers']);
    }

    public function testRegressionCountAboveZeroRejects(): void
    {
        $result = $this->validator->validate(
            ['method_candidate_id' => 'method.regressing.v1'],
            [
                'retained_delta' => 0.30,
                'regression_count' => 2,
                'evidence_refs' => ['evidence://replay/obra-3'],
            ],
        );

        $this->assertFalse($result['validated']);
        $this->assertTrue($result['reverted']);
        $this->assertSame(2, $result['regression_count']);
        $this->assertSame(['regression_observed'], $result['blockers']);
    }

    public function testNegativeRetainedDeltaRejects(): void
    {
        $result = $this->validator->validate(
            ['method_candidate_id' => 'method.faded.v1'],
            [
                'retained_delta' => -0.05,
                'regression_count' => 0,
                'evidence_refs' => ['evidence://replay/obra-4'],
            ],
        );

        $this->assertFalse($result['validated']);
        $this->assertTrue($result['reverted']);
        $this->assertEqualsWithDelta(-0.05, $result['retained_delta'], 1e-9);
        $this->assertSame(['negative_retained_delta'], $result['blockers']);
    }

    public function testZeroRetainedDeltaWithEvidenceAndNoRegressionStillValidates(): void
    {
        $result = $this->validator->validate(
            ['method_candidate_id' => 'method.neutral.v1'],
            [
                'retained_delta' => 0.0,
                'regression_count' => 0,
                'evidence_refs' => ['evidence://replay/obra-5'],
            ],
        );

        $this->assertTrue($result['validated']);
        $this->assertFalse($result['reverted']);
        $this->assertEqualsWithDelta(0.0, $result['retained_delta'], 1e-9);
        $this->assertSame([], $result['blockers']);
    }

    public function testAllThreeFailuresAreReportedInRuleOrder(): void
    {
        $result = $this->validator->validate(
            ['method_candidate_id' => 'method.broken.v1'],
            [
                'retained_delta' => -1.50,
                'regression_count' => 4,
                'evidence_refs' => [],
            ],
        );

        $this->assertFalse($result['validated']);
        $this->assertTrue($result['reverted']);
        $this->assertSame(4, $result['regression_count']);
        $this->assertEqualsWithDelta(-1.50, $result['retained_delta'], 1e-9);
        $this->assertSame(
            ['no_outcome_evidence', 'regression_observed', 'negative_retained_delta'],
            $result['blockers'],
        );
    }

    public function testEvidenceRefsAreNormalisedToStringList(): void
    {
        $result = $this->validator->validate(
            ['method_candidate_id' => 'method.mixed_refs.v1'],
            [
                'retained_delta' => 0.10,
                'regression_count' => 0,
                'evidence_refs' => ['evidence://replay/obra-6', '', 42, 'evidence://replay/obra-7'],
            ],
        );

        $this->assertTrue($result['validated']);
        $this->assertFalse($result['reverted']);
        $this->assertSame(['evidence://replay/obra-6', 'evidence://replay/obra-7'], $result['evidence_refs']);
    }

    public function testNegativeRegressionCountIsClampedToZeroAndDoesNotReject(): void
    {
        $result = $this->validator->validate(
            ['method_candidate_id' => 'method.garbled_regression.v1'],
            [
                'retained_delta' => 0.12,
                'regression_count' => -3,
                'evidence_refs' => ['evidence://replay/obra-8'],
            ],
        );

        $this->assertTrue($result['validated']);
        $this->assertFalse($result['reverted']);
        $this->assertSame(0, $result['regression_count']);
        $this->assertSame([], $result['blockers']);
    }

    public function testMissingRetainedDeltaFailsClosedAsNegative(): void
    {
        $result = $this->validator->validate(
            ['method_candidate_id' => 'method.no_retained_signal.v1'],
            [
                'regression_count' => 0,
                'evidence_refs' => ['evidence://replay/obra-9'],
            ],
        );

        $this->assertFalse($result['validated']);
        $this->assertTrue($result['reverted']);
        $this->assertEqualsWithDelta(-1.0, $result['retained_delta'], 1e-9);
        $this->assertSame(['negative_retained_delta'], $result['blockers']);
    }

    public function testNonFiniteRetainedDeltaFailsClosedAndDoesNotLeakIntoOutput(): void
    {
        // NAN < 0.0 is false in IEEE-754, so a NAN retained signal would slip
        // past the negative-delta veto and validate the method on a
        // non-measurement (and poison JSON encoding). A non-finite retained
        // delta must fail closed exactly like an absent signal: the negative
        // sentinel, blocked, reverted -- "survives only by outcome, not style".
        foreach ([NAN, INF, -INF] as $nonFinite) {
            $result = $this->validator->validate(
                ['method_candidate_id' => 'method.non_finite.v1'],
                [
                    'retained_delta' => $nonFinite,
                    'regression_count' => 0,
                    'evidence_refs' => ['evidence://replay/obra-nf'],
                ],
            );

            $this->assertFalse($result['validated']);
            $this->assertTrue($result['reverted']);
            $this->assertEqualsWithDelta(-1.0, $result['retained_delta'], 1e-9);
            $this->assertTrue(is_finite($result['retained_delta']));
            $this->assertSame(['negative_retained_delta'], $result['blockers']);
            // The whole envelope stays JSON-encodable (no NAN/INF leak).
            $this->assertIsString(json_encode($result));
            $this->assertSame(JSON_ERROR_NONE, json_last_error());
        }
    }

    public function testNonFiniteRegressionCountResolvesToZeroWithoutWarningOrLeak(): void
    {
        // A non-finite regression signal (e.g. a numeric string overflowing to
        // INF) must not coerce to a garbage int with a runtime warning; it
        // resolves to the clamp default of zero (consistent with the
        // negative-clamp contract) and leaves the envelope encodable.
        foreach ([INF, NAN, '1e400'] as $garbledRegression) {
            $result = $this->validator->validate(
                ['method_candidate_id' => 'method.garbled_regression_nf.v1'],
                [
                    'retained_delta' => 0.12,
                    'regression_count' => $garbledRegression,
                    'evidence_refs' => ['evidence://replay/obra-nf2'],
                ],
            );

            $this->assertSame(0, $result['regression_count']);
            $this->assertIsString(json_encode($result));
            $this->assertSame(JSON_ERROR_NONE, json_last_error());
        }
    }

    public function testNestedMetricsAndCandidateCarriedEvidenceAreRead(): void
    {
        $result = $this->validator->validate(
            ['evidence_refs' => ['evidence://candidate/obra-10']],
            [
                'retained_metrics' => ['retained_delta' => 0.33],
                'regression_metrics' => ['regression_count' => 0],
            ],
        );

        $this->assertTrue($result['validated']);
        $this->assertFalse($result['reverted']);
        $this->assertEqualsWithDelta(0.33, $result['retained_delta'], 1e-9);
        $this->assertSame(0, $result['regression_count']);
        $this->assertSame(['evidence://candidate/obra-10'], $result['evidence_refs']);
        $this->assertSame([], $result['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $candidate = ['method_candidate_id' => 'method.stable.v1'];
        $outcomes = [
            'retained_delta' => 0.18,
            'regression_count' => 0,
            'evidence_refs' => ['evidence://replay/obra-11'],
        ];

        $first = $this->validator->validate($candidate, $outcomes);
        $second = $this->validator->validate($candidate, $outcomes);

        $this->assertSame($first, $second);
    }
}
