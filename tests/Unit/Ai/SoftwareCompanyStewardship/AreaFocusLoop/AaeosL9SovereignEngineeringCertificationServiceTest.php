<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AaeosL9SovereignEngineeringCertificationService;
use PHPUnit\Framework\TestCase;

final class AaeosL9SovereignEngineeringCertificationServiceTest extends TestCase
{
    private AaeosL9SovereignEngineeringCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new AaeosL9SovereignEngineeringCertificationService();
    }

    /**
     * Every composed L9 artefact green, each in its OWN native shape with an
     * explicit evidence ref. Inputs are arrays (not the bool fast-path) so the real
     * branches are exercised end to end: the S126 admission carries `admitted`, the
     * S132/S138/S143 certs carry `q2_certified`/`q1_certified`/`q3_certified`, the
     * S127 registry carries `sovereignty_locked`, the S144 guard carries `in_scope`.
     *
     * @return array<string,array<string,mixed>>
     */
    private function fullyCertifiedInputs(): array
    {
        return [
            'admission' => ['admitted' => true, 'evidence_ref' => 'evidence://l9/sovereign/admission/s126'],
            'sovereignty' => ['sovereignty_locked' => true, 'evidence_ref' => 'evidence://l9/sovereign/sovereignty/s127'],
            'q2' => ['q2_certified' => true, 'evidence_ref' => 'evidence://l9/sovereign/q2/s132'],
            'q1' => ['q1_certified' => true, 'evidence_ref' => 'evidence://l9/sovereign/q1/s138'],
            'q3' => ['q3_certified' => true, 'evidence_ref' => 'evidence://l9/sovereign/q3/s143'],
            'scope_guard' => ['in_scope' => true, 'evidence_ref' => 'evidence://l9/sovereign/scope_guard/s144'],
        ];
    }

    public function testCertifiesWhenAdmissionSovereigntyQ2Q1Q3AndScopeAllPass(): void
    {
        $result = $this->service->certify($this->fullyCertifiedInputs());

        // Acceptance: schema + the six composed flags + certified + blockers + evidence_refs.
        $this->assertSame('atlas.aaeos.l9.sovereign_engineering_certification.v1', $result['schema_version']);
        $this->assertTrue($result['admitted']);
        $this->assertTrue($result['sovereignty_locked']);
        $this->assertTrue($result['q2']);
        $this->assertTrue($result['q1']);
        $this->assertTrue($result['q3']);
        $this->assertTrue($result['scope_guard']);
        $this->assertTrue($result['certified']);
        $this->assertSame('l9_sovereign_engineering', $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(6, $result['checks_passed_count']);
        $this->assertSame(6, $result['check_total']);
        $this->assertSame(
            [
                'evidence://l9/sovereign/admission/s126',
                'evidence://l9/sovereign/sovereignty/s127',
                'evidence://l9/sovereign/q2/s132',
                'evidence://l9/sovereign/q1/s138',
                'evidence://l9/sovereign/q3/s143',
                'evidence://l9/sovereign/scope_guard/s144',
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
                'sovereignty_locked',
                'q2',
                'q1',
                'q3',
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
            'sovereignty' => true,
            'q2' => true,
            'q1' => true,
            'q3' => true,
            'scope_guard' => true,
        ]);

        $this->assertTrue($result['certified']);
        $this->assertSame('l9_sovereign_engineering', $result['status']);
        $this->assertSame(6, $result['checks_passed_count']);
        // A certified bool artefact with no explicit ref gets a deterministic met marker.
        $this->assertSame(
            [
                'evidence://l9/sovereign/admission/met',
                'evidence://l9/sovereign/sovereignty/met',
                'evidence://l9/sovereign/q2/met',
                'evidence://l9/sovereign/q1/met',
                'evidence://l9/sovereign/q3/met',
                'evidence://l9/sovereign/scope_guard/met',
            ],
            $result['evidence_refs'],
        );
    }

    public function testMissingAdmissionBlocksEvenWhenEveryOtherCheckPasses(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // Post-L8 admission (S126 — carries the S125/L8-real floor) not granted.
        $inputs['admission'] = ['admitted' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['admitted']);
        $this->assertTrue($result['sovereignty_locked']);
        $this->assertTrue($result['q2']);
        $this->assertTrue($result['q1']);
        $this->assertTrue($result['q3']);
        $this->assertTrue($result['scope_guard']);
        // DoD: certified=true requires S125 (carried by admission) — without it, blocked.
        $this->assertFalse($result['certified']);
        $this->assertSame('blocked_not_l9', $result['status']);
        $this->assertSame(['admission_not_granted'], $result['blockers']);
        $this->assertSame(5, $result['checks_passed_count']);
    }

    public function testMissingQ2BlocksEvenWhenQ1AndQ3Pass(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // Q2 (proven invariants) is the safety precondition; Q1/Q3 stay green.
        $inputs['q2'] = ['q2_certified' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['q2']);
        $this->assertTrue($result['q1']);
        $this->assertTrue($result['q3']);
        // DoD: certified=true requires Q2/Q1/Q3 to ALL pass.
        $this->assertFalse($result['certified']);
        $this->assertSame(['q2_not_certified'], $result['blockers']);
        $this->assertSame(5, $result['checks_passed_count']);
    }

    public function testMissingQ1Blocks(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        $inputs['q1'] = ['q1_certified' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['q1']);
        $this->assertFalse($result['certified']);
        $this->assertSame(['q1_not_certified'], $result['blockers']);
    }

    public function testMissingQ3Blocks(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        $inputs['q3'] = ['q3_certified' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['q3']);
        $this->assertFalse($result['certified']);
        $this->assertSame(['q3_not_certified'], $result['blockers']);
    }

    public function testSovereigntyUnlockedBlocksEvenWithEveryPillarGreen(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // The sovereignty invariant is dead: the operator is not locked as the value source.
        $inputs['sovereignty'] = ['sovereignty_locked' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['sovereignty_locked']);
        $this->assertTrue($result['q2']);
        $this->assertTrue($result['q1']);
        $this->assertTrue($result['q3']);
        // "Invariante vivo" is part of the arrival checklist — a dead invariant blocks.
        $this->assertFalse($result['certified']);
        $this->assertSame(['sovereignty_not_locked'], $result['blockers']);
    }

    public function testScopeNotIntactBlocks(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        // Scope creeped outside engineering (S144 guard reports not in scope).
        $inputs['scope_guard'] = ['in_scope' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['scope_guard']);
        $this->assertFalse($result['certified']);
        $this->assertSame(['scope_not_intact'], $result['blockers']);
    }

    public function testBlockersAreOrderedAdmissionSovereigntyQ2Q1Q3ScopeAcrossMultipleGaps(): void
    {
        // Admission, Q2 and scope fail; sovereignty/Q1/Q3 pass. Blocker order is the
        // safety-first walk: admission, then sovereignty, then Q2 before Q1/Q3, then scope.
        $result = $this->service->certify([
            'admission' => false,
            'sovereignty' => true,
            'q2' => ['q2_certified' => false],
            'q1' => true,
            'q3' => true,
            'scope_guard' => false,
        ]);

        $this->assertSame(
            ['admission_not_granted', 'q2_not_certified', 'scope_not_intact'],
            $result['blockers'],
        );
        $this->assertFalse($result['certified']);
        $this->assertSame(3, $result['checks_passed_count']);
        $this->assertTrue($result['sovereignty_locked']);
        $this->assertTrue($result['q1']);
        $this->assertTrue($result['q3']);
    }

    public function testEmptyInputsBlockEveryComposedCheck(): void
    {
        $result = $this->service->certify([]);

        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['sovereignty_locked']);
        $this->assertFalse($result['q2']);
        $this->assertFalse($result['q1']);
        $this->assertFalse($result['q3']);
        $this->assertFalse($result['scope_guard']);
        $this->assertFalse($result['certified']);
        $this->assertSame(0, $result['checks_passed_count']);
        $this->assertSame(
            [
                'admission_not_granted',
                'sovereignty_not_locked',
                'q2_not_certified',
                'q1_not_certified',
                'q3_not_certified',
                'scope_not_intact',
            ],
            $result['blockers'],
        );
        $this->assertSame([], $result['evidence_refs']);
    }

    public function testUncertifiedCheckCarriesBlockedMarkerAndIsAbsentFromEvidenceRefs(): void
    {
        $inputs = $this->fullyCertifiedInputs();
        $inputs['q3'] = false;

        $result = $this->service->certify($inputs);

        $checksByKey = [];
        foreach ($result['checks'] as $check) {
            $checksByKey[$check['key']] = $check;
        }

        $this->assertFalse($checksByKey['q3']['passed']);
        $this->assertSame('evidence://l9/sovereign/q3/blocked', $checksByKey['q3']['evidence_ref']);
        // The blocked check's marker is never advertised as proof.
        $this->assertNotContains('evidence://l9/sovereign/q3/blocked', $result['evidence_refs']);
        // Each composed check reports its originating slice.
        $this->assertSame('S143', $checksByKey['q3']['slice']);
        $this->assertSame('S126', $checksByKey['admission']['slice']);
        $this->assertSame('S132', $checksByKey['q2']['slice']);
    }

    public function testNoL10PromotionSideEffectAndReadOnlyDeterminism(): void
    {
        $inputs = $this->fullyCertifiedInputs();

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        // Read-only: identical inputs yield an identical certification (no hidden state).
        $this->assertSame($first, $second);

        // No L10 promotion side effect: the result never advertises a promotion / next-level
        // field and the status stays an L9 verdict, never an L10 claim.
        $this->assertArrayNotHasKey('promoted', $first);
        $this->assertArrayNotHasKey('promote', $first);
        $this->assertArrayNotHasKey('l10', $first);
        $this->assertArrayNotHasKey('next_level', $first);
        $this->assertArrayNotHasKey('l10_promoted', $first);
        $this->assertSame('L9', $first['phase']);
        $this->assertStringNotContainsString('l10', $first['status']);
    }

    public function testBlockersListIsAlwaysAReindexedListEvenWithGaps(): void
    {
        $result = $this->service->certify([
            'admission' => true,
            'sovereignty' => true,
            'q2' => false,
            'q1' => true,
            'q3' => false,
            'scope_guard' => true,
        ]);

        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        $this->assertSame(['q2_not_certified', 'q3_not_certified'], $result['blockers']);
    }
}
