<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10R1GenerativeEngineeringCertificationService;
use PHPUnit\Framework\TestCase;

final class L10R1GenerativeEngineeringCertificationServiceTest extends TestCase
{
    private L10R1GenerativeEngineeringCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new L10R1GenerativeEngineeringCertificationService();
    }

    /**
     * A fully composed, certifying R1 input: the R3 bounded-recursion certificate is
     * in force and two genuinely novel paradigms are validated, invariant-safe and
     * positively retained. Arrays (not the bool fast-path) exercise the real branches.
     *
     * @return array<string,mixed>
     */
    private function certifiedInputs(): array
    {
        return [
            'r3_certificate' => ['r3_certified' => true],
            'paradigms' => [
                [
                    'paradigm_id' => 'content_addressed_effect_graph',
                    'validated' => true,
                    'novelty_status' => 'novel_confirmed',
                    'invariant_safe' => true,
                    'retained_delta' => 0.42,
                ],
                [
                    'paradigm_id' => 'reversible_capability_lattice',
                    'validated' => true,
                    'novelty_status' => 'novel_confirmed',
                    'invariant_safe' => true,
                    'retained_delta' => 0.18,
                ],
            ],
        ];
    }

    public function testCertifiesWhenR3PresentValidatedNovelParadigmRetainedAndInvariantSafe(): void
    {
        $result = $this->service->certify($this->certifiedInputs());

        // Acceptance: schema + every required computed field.
        $this->assertSame('atlas.aaeos.l10.r1_generative_engineering_certification.v1', $result['schema_version']);
        $this->assertSame('L10-R1', $result['phase']);
        $this->assertTrue($result['r1_certified']);
        $this->assertSame(2, $result['validated_paradigm_count']);
        $this->assertSame(
            ['content_addressed_effect_graph', 'reversible_capability_lattice'],
            $result['validated_paradigm_ids'],
        );
        $this->assertSame('novel_confirmed', $result['novelty_status']);
        // retained_delta is the conservative floor: the minimum that held for all.
        $this->assertEqualsWithDelta(0.18, $result['retained_delta'], 1.0e-9);
        $this->assertTrue($result['invariant_safe']);
        $this->assertSame('l10_r1_certified', $result['status']);
        $this->assertSame([], $result['blockers']);
        // DoD: R1 certifies validated novelty; it does not invent by itself.
        $this->assertTrue($result['read_only']);
    }

    public function testResultExposesEveryRequiredKey(): void
    {
        $result = $this->service->certify($this->certifiedInputs());

        foreach ([
            'schema_version',
            'r1_certified',
            'validated_paradigm_count',
            'novelty_status',
            'retained_delta',
            'blockers',
        ] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function testMissingR3CertificateBlocks(): void
    {
        $inputs = $this->certifiedInputs();
        unset($inputs['r3_certificate']);

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['r3_certificate_present']);
        $this->assertFalse($result['r1_certified']);
        $this->assertSame('blocked_not_l10_r1', $result['status']);
        $this->assertContains('r3_certificate_missing', $result['blockers']);
        // The paradigms are otherwise fully validated: the sole gap is the R3 bound.
        $this->assertSame(['r3_certificate_missing'], $result['blockers']);
        $this->assertSame(2, $result['validated_paradigm_count']);
    }

    public function testNonCertifiedR3CertificateBlocks(): void
    {
        $inputs = $this->certifiedInputs();
        // The certificate envelope exists but does not certify: fail-closed.
        $inputs['r3_certificate'] = ['r3_certified' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['r3_certificate_present']);
        $this->assertFalse($result['r1_certified']);
        $this->assertSame('r3_certificate_missing', $result['blockers'][0]);
    }

    public function testNoValidatedParadigmBlocks(): void
    {
        $inputs = $this->certifiedInputs();
        $inputs['paradigms'] = [];

        $result = $this->service->certify($inputs);

        $this->assertSame(0, $result['validated_paradigm_count']);
        $this->assertSame([], $result['validated_paradigm_ids']);
        // novelty is NEVER confirmed without a validated novel paradigm behind it.
        $this->assertSame('unknown_not_new', $result['novelty_status']);
        // retained_delta floor with nothing validated is exactly 0.0, never positive.
        $this->assertSame(0.0, $result['retained_delta']);
        $this->assertFalse($result['r1_certified']);
        $this->assertContains('no_validated_paradigm', $result['blockers']);
    }

    public function testUnknownNoveltyParadigmIsNotValidatedAndBlocks(): void
    {
        $inputs = $this->certifiedInputs();
        // Validated + retained + invariant-safe, but novelty merely unknown: a
        // renamed/unknown candidate is NOT generative novelty.
        $inputs['paradigms'] = [
            [
                'paradigm_id' => 'renamed_existing_pattern',
                'validated' => true,
                'novelty_status' => 'unknown_not_new',
                'invariant_safe' => true,
                'retained_delta' => 0.9,
            ],
        ];

        $result = $this->service->certify($inputs);

        $this->assertSame(0, $result['validated_paradigm_count']);
        $this->assertSame('unknown_not_new', $result['novelty_status']);
        $this->assertSame(0.0, $result['retained_delta']);
        $this->assertFalse($result['r1_certified']);
        $this->assertContains('no_validated_paradigm', $result['blockers']);
    }

    public function testNonPositiveRetainedDeltaParadigmIsNotValidated(): void
    {
        $inputs = $this->certifiedInputs();
        // Novel + validated + invariant-safe, but the retained delta is zero: an
        // improvement that did not hold is not a validated paradigm.
        $inputs['paradigms'] = [
            [
                'paradigm_id' => 'unretained_paradigm',
                'validated' => true,
                'novelty_status' => 'novel_confirmed',
                'invariant_safe' => true,
                'retained_delta' => 0.0,
            ],
        ];

        $result = $this->service->certify($inputs);

        $this->assertSame(0, $result['validated_paradigm_count']);
        $this->assertSame('unknown_not_new', $result['novelty_status']);
        $this->assertFalse($result['r1_certified']);
        $this->assertContains('no_validated_paradigm', $result['blockers']);
    }

    public function testInvariantViolationBlocksEvenWhenAnotherParadigmValidated(): void
    {
        $inputs = $this->certifiedInputs();
        // One genuinely validated novel paradigm, PLUS one that violates an invariant.
        $inputs['paradigms'] = [
            [
                'paradigm_id' => 'good_novel_paradigm',
                'validated' => true,
                'novelty_status' => 'novel_confirmed',
                'invariant_safe' => true,
                'retained_delta' => 0.3,
            ],
            [
                'paradigm_id' => 'rogue_paradigm',
                'validated' => true,
                'novelty_status' => 'novel_confirmed',
                'invariant_violation' => true,
                'retained_delta' => 0.5,
            ],
        ];

        $result = $this->service->certify($inputs);

        // The absolute invariant poisons certification despite a validated paradigm.
        $this->assertFalse($result['invariant_safe']);
        $this->assertFalse($result['r1_certified']);
        $this->assertContains('invariant_violation', $result['blockers']);
        // The good paradigm still counts toward the validated count and novelty.
        $this->assertSame(1, $result['validated_paradigm_count']);
        $this->assertSame(['good_novel_paradigm'], $result['validated_paradigm_ids']);
        $this->assertSame('novel_confirmed', $result['novelty_status']);
        // The rogue (unsafe) paradigm is NOT counted as validated novelty.
        $this->assertNotContains('rogue_paradigm', $result['validated_paradigm_ids']);
    }

    public function testInvariantSafeFalseStatedExplicitlyAlsoCountsAsViolation(): void
    {
        $inputs = $this->certifiedInputs();
        $inputs['paradigms'] = [
            [
                'paradigm_id' => 'explicitly_unsafe',
                'validated' => true,
                'novelty_status' => 'novel_confirmed',
                'invariant_safe' => false,
                'retained_delta' => 0.7,
            ],
        ];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['invariant_safe']);
        $this->assertContains('invariant_violation', $result['blockers']);
        $this->assertFalse($result['r1_certified']);
    }

    public function testBlockersAreOrderedR3ThenValidatedThenInvariant(): void
    {
        // Every R1 precondition fails at once: no R3 certificate, no validated
        // paradigm, and a stated invariant violation.
        $result = $this->service->certify([
            'r3_certificate' => false,
            'paradigms' => [
                [
                    'paradigm_id' => 'broken',
                    'validated' => false,
                    'novelty_status' => 'unknown_not_new',
                    'invariant_violation' => true,
                ],
            ],
        ]);

        $this->assertFalse($result['r1_certified']);
        // Safety-first ordering: R3 bound leads, then validated novelty, then invariant.
        $this->assertSame(
            ['r3_certificate_missing', 'no_validated_paradigm', 'invariant_violation'],
            $result['blockers'],
        );
    }

    public function testValidatedCountNeverExceedsDistinctSuppliedIds(): void
    {
        $result = $this->service->certify([
            'r3_certificate' => true,
            // Same paradigm id supplied twice (the first qualifies); a duplicate
            // must never inflate the validated count past the distinct supplied set.
            'paradigms' => [
                [
                    'paradigm_id' => 'dup_paradigm',
                    'validated' => true,
                    'novelty_status' => 'novel_confirmed',
                    'invariant_safe' => true,
                    'retained_delta' => 0.5,
                ],
                [
                    'paradigm_id' => 'dup_paradigm',
                    'validated' => true,
                    'novelty_status' => 'novel_confirmed',
                    'invariant_safe' => true,
                    'retained_delta' => 0.9,
                ],
            ],
        ]);

        $this->assertSame(1, $result['supplied_paradigm_count']);
        $this->assertSame(1, $result['validated_paradigm_count']);
        $this->assertLessThanOrEqual($result['supplied_paradigm_count'], $result['validated_paradigm_count']);
        $this->assertSame(['dup_paradigm'], $result['validated_paradigm_ids']);
        $this->assertTrue($result['r1_certified']);
    }

    public function testDuplicateIdCollapsesOntoFirstQualifyingEnvelopeNotFirstMatching(): void
    {
        // The same paradigm id is supplied twice: an earlier UNVALIDATED draft and a
        // later, genuinely validated novel envelope. Per the contract duplicates
        // collapse onto the first QUALIFYING envelope, so the validated one must not
        // be shadowed by the unvalidated draft that happened to come first.
        $result = $this->service->certify([
            'r3_certificate' => true,
            'paradigms' => [
                [
                    'paradigm_id' => 'shadowed_paradigm',
                    'validated' => false,
                    'novelty_status' => 'novel_confirmed',
                    'invariant_safe' => true,
                    'retained_delta' => 0.5,
                ],
                [
                    'paradigm_id' => 'shadowed_paradigm',
                    'validated' => true,
                    'novelty_status' => 'novel_confirmed',
                    'invariant_safe' => true,
                    'retained_delta' => 0.9,
                ],
            ],
        ]);

        $this->assertSame(1, $result['supplied_paradigm_count']);
        $this->assertSame(1, $result['validated_paradigm_count']);
        $this->assertSame(['shadowed_paradigm'], $result['validated_paradigm_ids']);
        // The qualifying (second) envelope's retained delta is reported, not the
        // unvalidated draft's.
        $this->assertEqualsWithDelta(0.9, $result['retained_delta'], 1.0e-9);
        $this->assertSame('novel_confirmed', $result['novelty_status']);
        $this->assertTrue($result['r1_certified']);
    }

    public function testParadigmIdsHonourListOfStringContractAndIgnoreCoercibleNonStrings(): void
    {
        $result = $this->service->certify([
            'r3_certificate' => true,
            'paradigms' => [
                // Non-string / blank ids must NOT coerce into the set.
                ['paradigm_id' => 0, 'validated' => true, 'novelty_status' => 'novel_confirmed', 'invariant_safe' => true, 'retained_delta' => 0.5],
                ['paradigm_id' => '', 'validated' => true, 'novelty_status' => 'novel_confirmed', 'invariant_safe' => true, 'retained_delta' => 0.5],
                ['paradigm_id' => 'real_paradigm', 'validated' => true, 'novelty_status' => 'novel_confirmed', 'invariant_safe' => true, 'retained_delta' => 0.5],
            ],
        ]);

        // Only the real string id survives; the int 0 and blank id are dropped.
        $this->assertSame(1, $result['supplied_paradigm_count']);
        $this->assertSame(1, $result['validated_paradigm_count']);
        $this->assertSame(['real_paradigm'], $result['validated_paradigm_ids']);
        foreach ($result['validated_paradigm_ids'] as $id) {
            $this->assertIsString($id);
        }
        $this->assertTrue($result['r1_certified']);
    }

    public function testRetainedDeltaIsTheMinimumAmongValidatedParadigms(): void
    {
        // Three validated novel paradigms with differing retained deltas: the
        // reported floor is the smallest (the improvement that held for ALL),
        // generalising beyond the certifiedInputs fixture.
        $result = $this->service->certify([
            'r3_certificate' => true,
            'paradigms' => [
                ['paradigm_id' => 'p_a', 'validated' => true, 'novelty_status' => 'novel_confirmed', 'invariant_safe' => true, 'retained_delta' => 0.75],
                ['paradigm_id' => 'p_b', 'validated' => true, 'novelty_status' => 'novel_confirmed', 'invariant_safe' => true, 'retained_delta' => 0.11],
                ['paradigm_id' => 'p_c', 'validated' => true, 'novelty_status' => 'novel_confirmed', 'invariant_safe' => true, 'retained_delta' => 0.33],
            ],
        ]);

        $this->assertSame(3, $result['validated_paradigm_count']);
        $this->assertEqualsWithDelta(0.11, $result['retained_delta'], 1.0e-9);
        $this->assertTrue($result['r1_certified']);
    }

    public function testRetainedDeltaReadsNestedRetainedMetricsAndNumericStrings(): void
    {
        $result = $this->service->certify([
            'r3_certificate' => true,
            'paradigms' => [
                [
                    'paradigm_id' => 'nested_paradigm',
                    'validated' => true,
                    'novelty_status' => 'novel_confirmed',
                    'invariant_safe' => true,
                    // Numeric string in a nested retained_metrics block.
                    'retained_metrics' => ['retained_delta' => '0.25'],
                ],
            ],
        ]);

        $this->assertSame(1, $result['validated_paradigm_count']);
        $this->assertEqualsWithDelta(0.25, $result['retained_delta'], 1.0e-9);
        $this->assertTrue($result['r1_certified']);
    }

    public function testBareStringParadigmIsNeverValidated(): void
    {
        $result = $this->service->certify([
            'r3_certificate' => true,
            // A bare-string paradigm names an id but carries no validation evidence.
            'paradigms' => ['just_a_name'],
        ]);

        $this->assertSame(1, $result['supplied_paradigm_count']);
        $this->assertSame(0, $result['validated_paradigm_count']);
        $this->assertSame('unknown_not_new', $result['novelty_status']);
        $this->assertFalse($result['r1_certified']);
        $this->assertContains('no_validated_paradigm', $result['blockers']);
    }

    public function testEmptyInputsBlockOnEveryComposedPrecondition(): void
    {
        $result = $this->service->certify([]);

        $this->assertFalse($result['r3_certificate_present']);
        $this->assertSame(0, $result['supplied_paradigm_count']);
        $this->assertSame(0, $result['validated_paradigm_count']);
        $this->assertSame('unknown_not_new', $result['novelty_status']);
        $this->assertSame(0.0, $result['retained_delta']);
        $this->assertTrue($result['invariant_safe']);
        $this->assertFalse($result['r1_certified']);
        $this->assertSame('blocked_not_l10_r1', $result['status']);
        // With no stated violation, only R3 and validated-paradigm preconditions fire.
        $this->assertSame(['r3_certificate_missing', 'no_validated_paradigm'], $result['blockers']);
    }

    public function testBoolFastPathR3CertificateCertifies(): void
    {
        $inputs = $this->certifiedInputs();
        // The bool fast-path is equivalent to a certifying envelope.
        $inputs['r3_certificate'] = true;

        $result = $this->service->certify($inputs);

        $this->assertTrue($result['r3_certificate_present']);
        $this->assertTrue($result['r1_certified']);
    }

    public function testReadOnlyIsAlwaysTrueEvenWhenBlocked(): void
    {
        // The DoD invariant holds regardless of verdict: R1 never invents/mutates.
        $certified = $this->service->certify($this->certifiedInputs());
        $blocked = $this->service->certify([]);

        $this->assertTrue($certified['read_only']);
        $this->assertTrue($blocked['read_only']);
    }

    public function testRetainedDeltaNeverExceedsOneIsNotAssumedButFloorTracksInputs(): void
    {
        // Inputs may carry a retained_delta above 1.0; the certifier reports the
        // measured floor faithfully (it is a delta, not a 0..1 score) and stays
        // deterministic. This guards against silently clamping real evidence.
        $result = $this->service->certify([
            'r3_certificate' => true,
            'paradigms' => [
                ['paradigm_id' => 'big_delta', 'validated' => true, 'novelty_status' => 'novel_confirmed', 'invariant_safe' => true, 'retained_delta' => 2.5],
                ['paradigm_id' => 'small_delta', 'validated' => true, 'novelty_status' => 'novel_confirmed', 'invariant_safe' => true, 'retained_delta' => 1.4],
            ],
        ]);

        $this->assertEqualsWithDelta(1.4, $result['retained_delta'], 1.0e-9);
        $this->assertSame(2, $result['validated_paradigm_count']);
    }

    public function testNonFinitePositiveInfinityRetainedDeltaParadigmIsNotValidated(): void
    {
        // Novel + validated + invariant-safe, but the retained delta is +INF (a
        // degenerate, undefined signal, e.g. a zero-baseline ratio metric). It must
        // NOT count as a validated novel paradigm and the floor is never +INF.
        $result = $this->service->certify([
            'r3_certificate' => true,
            'paradigms' => [
                [
                    'paradigm_id' => 'infinite_delta_paradigm',
                    'validated' => true,
                    'novelty_status' => 'novel_confirmed',
                    'invariant_safe' => true,
                    'retained_delta' => INF,
                ],
            ],
        ]);

        $this->assertSame(0, $result['validated_paradigm_count']);
        $this->assertSame('unknown_not_new', $result['novelty_status']);
        $this->assertSame(0.0, $result['retained_delta']);
        $this->assertFalse($result['r1_certified']);
        $this->assertContains('no_validated_paradigm', $result['blockers']);
    }

    public function testNonFiniteNumericStringRetainedDeltaParadigmIsNotValidated(): void
    {
        // A serialized over-range numeric string parses to +INF; it must fail the
        // strictly-positive retained gate, not validate a paradigm on overflow.
        $result = $this->service->certify([
            'r3_certificate' => true,
            'paradigms' => [
                [
                    'paradigm_id' => 'overflow_string_paradigm',
                    'validated' => true,
                    'novelty_status' => 'novel_confirmed',
                    'invariant_safe' => true,
                    'retained_metrics' => ['retained_delta' => '1e400'],
                ],
            ],
        ]);

        $this->assertSame(0, $result['validated_paradigm_count']);
        $this->assertSame(0.0, $result['retained_delta']);
        $this->assertFalse($result['r1_certified']);
        $this->assertContains('no_validated_paradigm', $result['blockers']);
    }

    public function testIdenticalInputsAreDeterministic(): void
    {
        $inputs = $this->certifiedInputs();

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        $this->assertSame($first, $second);
    }

    public function testBlockersListIsAlwaysAZeroIndexedList(): void
    {
        $result = $this->service->certify($this->certifiedInputs());
        $this->assertSame(array_values($result['blockers']), $result['blockers']);

        $blocked = $this->service->certify([]);
        $this->assertSame(array_values($blocked['blockers']), $blocked['blockers']);
    }
}
