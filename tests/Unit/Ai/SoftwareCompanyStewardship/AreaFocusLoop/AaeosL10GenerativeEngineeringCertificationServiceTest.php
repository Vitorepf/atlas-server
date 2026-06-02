<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AaeosL10GenerativeEngineeringCertificationService;
use PHPUnit\Framework\TestCase;

final class AaeosL10GenerativeEngineeringCertificationServiceTest extends TestCase
{
    private AaeosL10GenerativeEngineeringCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new AaeosL10GenerativeEngineeringCertificationService();
    }

    /**
     * Every composed L10 artefact green, each in its OWN native shape with an
     * explicit evidence ref. Inputs are arrays (not the bool fast-path) so the real
     * branches are exercised end to end: the S145 admission carries `admitted`, the
     * bound+sovereignty floor carries `preconditions_locked`, the S152/S156/S160
     * certs carry `r3_certified`/`r2_certified`/`r1_certified`, the S163 latency
     * scorer carries `r4_certified`, the S164 guard carries `in_scope`.
     *
     * @return array<string,array<string,mixed>>
     */
    private function fullyCertifiedInputs(): array
    {
        return [
            'admission' => ['admitted' => true, 'evidence_ref' => 'evidence://l10/generative/admission/s145'],
            'preconditions' => ['preconditions_locked' => true, 'evidence_ref' => 'evidence://l10/generative/preconditions/bound'],
            'r3' => ['r3_certified' => true, 'evidence_ref' => 'evidence://l10/generative/r3/s152'],
            'r2' => ['r2_certified' => true, 'evidence_ref' => 'evidence://l10/generative/r2/s156'],
            'r1' => ['r1_certified' => true, 'evidence_ref' => 'evidence://l10/generative/r1/s160'],
            'r4' => ['r4_certified' => true, 'evidence_ref' => 'evidence://l10/generative/r4/s163'],
            'scope_guard' => ['in_scope' => true, 'evidence_ref' => 'evidence://l10/generative/scope_guard/s164'],
        ];
    }

    public function testCertifiesWhenAdmissionPreconditionsAndEveryPillarPass(): void
    {
        $result = $this->service->certify($this->fullyCertifiedInputs());

        // Acceptance: schema + the seven composed flags + certified + blockers + evidence_refs.
        $this->assertSame('atlas.aaeos.l10.generative_engineering_certification.v1', $result['schema_version']);
        $this->assertTrue($result['admitted']);
        $this->assertTrue($result['preconditions']);
        $this->assertTrue($result['r3']);
        $this->assertTrue($result['r2']);
        $this->assertTrue($result['r1']);
        $this->assertTrue($result['r4']);
        $this->assertTrue($result['scope_guard']);
        $this->assertTrue($result['certified']);
        $this->assertSame('l10_generative_engineering', $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(7, $result['checks_passed_count']);
        $this->assertSame(7, $result['check_total']);
        $this->assertSame(
            [
                'evidence://l10/generative/admission/s145',
                'evidence://l10/generative/preconditions/bound',
                'evidence://l10/generative/r3/s152',
                'evidence://l10/generative/r2/s156',
                'evidence://l10/generative/r1/s160',
                'evidence://l10/generative/r4/s163',
                'evidence://l10/generative/scope_guard/s164',
            ],
            $result['evidence_refs'],
        );
    }

    public function testResultExposesEveryRequiredSchemaKey(): void
    {
        $result = $this->service->certify($this->fullyCertifiedInputs());

        // Acceptance enumerates exactly these returned fields.
        foreach (
            [
                'schema_version',
                'admitted',
                'preconditions',
                'r3',
                'r2',
                'r1',
                'r4',
                'scope_guard',
                'certified',
                'blockers',
                'evidence_refs',
            ] as $key
        ) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function testBoolArtefactsAreAcceptedFastPath(): void
    {
        $result = $this->service->certify([
            'admission' => true,
            'preconditions' => true,
            'r3' => true,
            'r2' => true,
            'r1' => true,
            'r4' => true,
            'scope_guard' => true,
        ]);

        $this->assertTrue($result['certified']);
        $this->assertSame('l10_generative_engineering', $result['status']);
        $this->assertSame(7, $result['checks_passed_count']);
        // A certified bool artefact with no explicit ref gets a deterministic met marker.
        $this->assertSame(
            [
                'evidence://l10/generative/admission/met',
                'evidence://l10/generative/preconditions/met',
                'evidence://l10/generative/r3/met',
                'evidence://l10/generative/r2/met',
                'evidence://l10/generative/r1/met',
                'evidence://l10/generative/r4/met',
                'evidence://l10/generative/scope_guard/met',
            ],
            $result['evidence_refs'],
        );
    }

    public function testMissingAdmissionBlocksEvenWhenEveryOtherCheckPasses(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // Post-L9 admission (S145 — carries the L9-real floor) not granted.
        $inputs['admission'] = ['admitted' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['admitted']);
        $this->assertTrue($result['preconditions']);
        $this->assertTrue($result['r3']);
        $this->assertTrue($result['r2']);
        $this->assertTrue($result['r1']);
        $this->assertTrue($result['r4']);
        $this->assertTrue($result['scope_guard']);
        // DoD: certified=true requires S145 (carried by admission) — without it, blocked.
        $this->assertFalse($result['certified']);
        $this->assertSame('blocked_not_l10', $result['status']);
        $this->assertSame(['admission_not_granted'], $result['blockers']);
        $this->assertSame(6, $result['checks_passed_count']);
    }

    public function testPreconditionsUnlockedBlockEvenWithEveryPillarGreen(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // The inviolable precondition (convergence bound + sovereignty) is not locked.
        $inputs['preconditions'] = ['preconditions_locked' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['preconditions']);
        $this->assertTrue($result['r3']);
        $this->assertTrue($result['r2']);
        $this->assertTrue($result['r1']);
        $this->assertTrue($result['r4']);
        // "O bound e a soberania precedem R1/R2/R4" — without them, blocked.
        $this->assertFalse($result['certified']);
        $this->assertSame(['preconditions_not_locked'], $result['blockers']);
        $this->assertSame(6, $result['checks_passed_count']);
    }

    public function testMissingR3BlocksEvenWhenR2R1R4Pass(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // R3 (bounded recursion) is the safety precondition; R2/R1/R4 stay green.
        $inputs['r3'] = ['r3_certified' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['r3']);
        $this->assertTrue($result['r2']);
        $this->assertTrue($result['r1']);
        $this->assertTrue($result['r4']);
        // DoD: certified=true requires all pillars to pass.
        $this->assertFalse($result['certified']);
        $this->assertSame(['r3_not_certified'], $result['blockers']);
        $this->assertSame(6, $result['checks_passed_count']);
    }

    public function testMissingR2Blocks(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        $inputs['r2'] = ['r2_certified' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['r2']);
        $this->assertFalse($result['certified']);
        $this->assertSame(['r2_not_certified'], $result['blockers']);
    }

    public function testMissingR1Blocks(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        $inputs['r1'] = ['r1_certified' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['r1']);
        $this->assertFalse($result['certified']);
        $this->assertSame(['r1_not_certified'], $result['blockers']);
    }

    public function testMissingR4Blocks(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        $inputs['r4'] = ['r4_certified' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['r4']);
        $this->assertFalse($result['certified']);
        $this->assertSame(['r4_not_certified'], $result['blockers']);
    }

    public function testScopeNotIntactBlocks(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // Scope creeped outside engineering / runaway (S164 guard reports not in scope).
        $inputs['scope_guard'] = ['in_scope' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['scope_guard']);
        $this->assertFalse($result['certified']);
        $this->assertSame(['scope_not_intact'], $result['blockers']);
    }

    public function testBlockersAreOrderedAdmissionPreconditionsR3R2R1R4ScopeAcrossMultipleGaps(): void
    {
        // Admission, R3 and scope fail; preconditions/R2/R1/R4 pass. Blocker order is
        // the safety-first walk: admission, then preconditions, then R3 before
        // R2/R1/R4, then scope.
        $result = $this->service->certify([
            'admission' => false,
            'preconditions' => true,
            'r3' => ['r3_certified' => false],
            'r2' => true,
            'r1' => true,
            'r4' => true,
            'scope_guard' => false,
        ]);

        $this->assertSame(
            ['admission_not_granted', 'r3_not_certified', 'scope_not_intact'],
            $result['blockers'],
        );
        $this->assertFalse($result['certified']);
        $this->assertSame(4, $result['checks_passed_count']);
        $this->assertTrue($result['preconditions']);
        $this->assertTrue($result['r2']);
        $this->assertTrue($result['r1']);
        $this->assertTrue($result['r4']);
    }

    public function testEmptyInputsBlockEveryComposedCheck(): void
    {
        $result = $this->service->certify([]);

        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['preconditions']);
        $this->assertFalse($result['r3']);
        $this->assertFalse($result['r2']);
        $this->assertFalse($result['r1']);
        $this->assertFalse($result['r4']);
        $this->assertFalse($result['scope_guard']);
        $this->assertFalse($result['certified']);
        $this->assertSame(0, $result['checks_passed_count']);
        $this->assertSame(
            [
                'admission_not_granted',
                'preconditions_not_locked',
                'r3_not_certified',
                'r2_not_certified',
                'r1_not_certified',
                'r4_not_certified',
                'scope_not_intact',
            ],
            $result['blockers'],
        );
        $this->assertSame([], $result['evidence_refs']);
    }

    public function testUncertifiedCheckCarriesBlockedMarkerAndIsAbsentFromEvidenceRefs(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        $inputs['r1'] = false;

        $result = $this->service->certify($inputs);

        $checksByKey = [];
        foreach ($result['checks'] as $check) {
            $checksByKey[$check['key']] = $check;
        }

        $this->assertFalse($checksByKey['r1']['passed']);
        $this->assertSame('evidence://l10/generative/r1/blocked', $checksByKey['r1']['evidence_ref']);
        // The blocked check's marker is never advertised as proof.
        $this->assertNotContains('evidence://l10/generative/r1/blocked', $result['evidence_refs']);
        // Each composed check reports its originating slice.
        $this->assertSame('S160', $checksByKey['r1']['slice']);
        $this->assertSame('S145', $checksByKey['admission']['slice']);
        $this->assertSame('S164', $checksByKey['scope_guard']['slice']);
    }

    public function testNoL11PromotionSideEffectAndReadOnlyDeterminism(): void
    {
        $inputs = $this->fullyCertifiedInputs();

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        // Read-only: identical inputs yield an identical certification (no hidden state).
        $this->assertSame($first, $second);

        // No L11 promotion side effect: the result never advertises a promotion / next-level
        // field and the status stays an L10 verdict, never an L11 claim.
        $this->assertArrayNotHasKey('promoted', $first);
        $this->assertArrayNotHasKey('promote', $first);
        $this->assertArrayNotHasKey('l11', $first);
        $this->assertArrayNotHasKey('next_level', $first);
        $this->assertArrayNotHasKey('l11_promoted', $first);
        $this->assertSame('L10', $first['phase']);
        $this->assertStringNotContainsString('l11', $first['status']);
    }

    public function testTruthyNonBoolPassEvidenceIsFailClosedNotCoerced(): void
    {
        // Fail-closed doctrine: a composed check passes ONLY on a strict (=== true)
        // pass flag. Truthy-but-not-true native evidence (int 1, numeric-string "1",
        // the string "true", a non-empty array) must NEVER be coerced into a pass —
        // otherwise a malformed upstream artefact could fake the L10 asymptote. Every
        // check carries such a value here, so the certification must stay fully blocked.
        $result = $this->service->certify([
            'admission' => ['admitted' => 1],
            'preconditions' => ['preconditions_locked' => '1'],
            'r3' => ['r3_certified' => 'true'],
            'r2' => ['r2_certified' => 1.0],
            'r1' => ['r1_certified' => 'yes'],
            'r4' => ['r4_certified' => [true]],
            'scope_guard' => ['in_scope' => -1],
        ]);

        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['preconditions']);
        $this->assertFalse($result['r3']);
        $this->assertFalse($result['r2']);
        $this->assertFalse($result['r1']);
        $this->assertFalse($result['r4']);
        $this->assertFalse($result['scope_guard']);
        $this->assertFalse($result['certified']);
        $this->assertSame('blocked_not_l10', $result['status']);
        $this->assertSame(0, $result['checks_passed_count']);
        $this->assertSame([], $result['evidence_refs']);
        $this->assertSame(
            [
                'admission_not_granted',
                'preconditions_not_locked',
                'r3_not_certified',
                'r2_not_certified',
                'r1_not_certified',
                'r4_not_certified',
                'scope_not_intact',
            ],
            $result['blockers'],
        );
    }

    public function testPresentButNullNativeFlagDoesNotFallThroughToCertifiedKey(): void
    {
        // First-recognised-key precedence is fail-closed: when an artefact carries its
        // native pass key present-but-null, that key DECIDES the check (null !== true),
        // and a later fallback `certified => true` must NOT rescue it. A null native
        // flag is an explicit "no proof", not an invitation to read weaker evidence.
        $inputs = $this->fullyCertifiedInputs();
        $inputs['admission'] = ['admitted' => null, 'certified' => true];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['certified']);
        $this->assertSame(['admission_not_granted'], $result['blockers']);
        $this->assertSame(6, $result['checks_passed_count']);
    }

    public function testBlockersListIsAlwaysAReindexedListEvenWithGaps(): void
    {
        $result = $this->service->certify([
            'admission' => true,
            'preconditions' => true,
            'r3' => false,
            'r2' => true,
            'r1' => false,
            'r4' => true,
            'scope_guard' => true,
        ]);

        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        $this->assertSame(['r3_not_certified', 'r1_not_certified'], $result['blockers']);
    }
}
