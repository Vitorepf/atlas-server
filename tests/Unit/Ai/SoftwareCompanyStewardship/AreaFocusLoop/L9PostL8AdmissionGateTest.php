<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9PostL8AdmissionGate;
use PHPUnit\Framework\TestCase;

final class L9PostL8AdmissionGateTest extends TestCase
{
    private L9PostL8AdmissionGate $gate;

    protected function setUp(): void
    {
        $this->gate = new L9PostL8AdmissionGate();
    }

    // --- Acceptance: schema + required_predecessor=S125 + admitted/blockers/allowed_l9_phase ---

    public function testReturnsCanonicalSchemaVersion(): void
    {
        $result = $this->gate->admit(
            ['id' => 'L9-SOVEREIGNTY'],
            ['certified' => true],
            [],
        );

        $this->assertSame('atlas.aaeos.l9.admission.v1', $result['schema_version']);
    }

    public function testResultExposesEveryRequiredAcceptanceKey(): void
    {
        $result = $this->gate->admit(
            ['id' => 'L9-SOVEREIGNTY'],
            ['certified' => true],
            [],
        );

        foreach (['admitted', 'required_predecessor', 'blockers', 'allowed_l9_phase'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function testRequiredPredecessorIsAlwaysS125(): void
    {
        // The hard predecessor (S125 / L8 certification) is reported on every
        // path: admitted, blocked-by-L8, blocked-by-scope and blocked-by-order.
        $admitted = $this->gate->admit(['id' => 'L9-SOVEREIGNTY'], ['certified' => true], []);
        $blockedL8 = $this->gate->admit(['id' => 'L9-SOVEREIGNTY'], ['certified' => false], []);
        $blockedScope = $this->gate->admit(['id' => 'L9-Q2', 'scope' => 'marketing'], ['certified' => true], []);
        $blockedOrder = $this->gate->admit(['id' => 'L9-Q1'], ['certified' => true], []);

        $this->assertSame('S125', $admitted['required_predecessor']);
        $this->assertSame('S125', $blockedL8['required_predecessor']);
        $this->assertSame('S125', $blockedScope['required_predecessor']);
        $this->assertSame('S125', $blockedOrder['required_predecessor']);
    }

    public function testSovereigntyInvariantIsAdmittedFirstAfterCertifiedL8(): void
    {
        $result = $this->gate->admit(
            ['id' => 'L9-SOVEREIGNTY'],
            ['certified' => true],
            [],
        );

        $this->assertTrue($result['l8_certified']);
        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('admitted_l9_sovereignty_invariant', $result['status']);
        $this->assertSame('l9_sovereignty_invariant_admitted_after_l8', $result['reason']);
        $this->assertSame('L9', $result['level']);
        $this->assertSame('SOVEREIGNTY', $result['phase']);
        $this->assertSame('L9-SOVEREIGNTY', $result['candidate']);
        // allowed_l9_phase echoes the admitted phase.
        $this->assertSame('SOVEREIGNTY', $result['allowed_l9_phase']);
    }

    // --- Acceptance: missing certified L8 blocks ---

    public function testMissingCertifiedL8BlocksAllL9(): void
    {
        $result = $this->gate->admit(
            ['id' => 'L9-SOVEREIGNTY'],
            ['certified' => false],
            [],
        );

        $this->assertFalse($result['l8_certified']);
        $this->assertTrue($result['is_l9_candidate']);
        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_l9_pre_l8', $result['status']);
        $this->assertSame(['l8_not_certified'], $result['blockers']);
        $this->assertSame('l9_never_starts_before_certified_l8', $result['reason']);
        // Nothing is admissible while L8 is uncertified.
        $this->assertSame('', $result['allowed_l9_phase']);
    }

    public function testAbsentL8CertificationBlocksFailClosed(): void
    {
        // An empty certification envelope (no `certified` key) must fail closed.
        $result = $this->gate->admit(
            ['id' => 'L9-Q2'],
            [],
            ['L9-SOVEREIGNTY'],
        );

        $this->assertFalse($result['l8_certified']);
        $this->assertFalse($result['admitted']);
        $this->assertSame(['l8_not_certified'], $result['blockers']);
    }

    public function testL8CertifiedAliasKeyIsAccepted(): void
    {
        $result = $this->gate->admit(
            ['id' => 'L9-SOVEREIGNTY'],
            ['l8_certified' => true],
            [],
        );

        $this->assertTrue($result['l8_certified']);
        $this->assertTrue($result['admitted']);
    }

    public function testCertifiedL8MustBeBooleanTrueNotTruthy(): void
    {
        // Honesty-first: a non-true `certified` value (e.g. string "yes") never
        // counts as a real L8 certificate.
        $result = $this->gate->admit(
            ['id' => 'L9-SOVEREIGNTY'],
            ['certified' => 'yes'],
            [],
        );

        $this->assertFalse($result['l8_certified']);
        $this->assertFalse($result['admitted']);
        $this->assertSame(['l8_not_certified'], $result['blockers']);
    }

    // --- Acceptance: non-engineering scope blocks ---

    public function testNonEngineeringScopeBlocks(): void
    {
        $result = $this->gate->admit(
            ['id' => 'L9-Q2', 'scope' => 'marketing'],
            ['certified' => true],
            ['L9-SOVEREIGNTY'],
        );

        $this->assertTrue($result['l8_certified']);
        $this->assertFalse($result['scope_in_engineering']);
        $this->assertSame('MARKETING', $result['scope']);
        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_non_engineering_scope', $result['status']);
        $this->assertSame(['non_engineering_scope'], $result['blockers']);
        $this->assertSame('l9_is_sovereign_engineering_only', $result['reason']);
        $this->assertSame('', $result['allowed_l9_phase']);
    }

    public function testEveryNonEngineeringDomainIsRejected(): void
    {
        // Anti-scaffold: the whole forbidden-scope set generalises, not one input.
        foreach (['finance', 'cyber', 'trading', 'external_company', 'multi_company', 'domain_generator'] as $scope) {
            $result = $this->gate->admit(
                ['id' => 'L9-Q3', 'scope' => $scope],
                ['certified' => true],
                ['L9-SOVEREIGNTY', 'L9-Q2'],
            );

            $this->assertFalse($result['admitted'], "scope {$scope} must block");
            $this->assertSame('blocked_non_engineering_scope', $result['status'], "scope {$scope}");
            $this->assertSame(['non_engineering_scope'], $result['blockers'], "scope {$scope}");
        }
    }

    public function testEngineeringScopeCandidatePasses(): void
    {
        // An explicit engineering scope is admitted (when phase-unlocked).
        $result = $this->gate->admit(
            ['id' => 'L9-Q2', 'scope' => 'engineering_only'],
            ['certified' => true],
            ['L9-SOVEREIGNTY'],
        );

        $this->assertTrue($result['scope_in_engineering']);
        $this->assertSame('ENGINEERING_ONLY', $result['scope']);
        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['blockers']);
    }

    public function testAbsentScopeDefaultsToEngineeringAndIsAdmitted(): void
    {
        // No declared scope => an L9 candidate is engineering unless it says so.
        $result = $this->gate->admit(
            ['id' => 'L9-Q2'],
            ['certified' => true],
            ['L9-SOVEREIGNTY'],
        );

        $this->assertTrue($result['scope_in_engineering']);
        $this->assertSame('ENGINEERING_ONLY', $result['scope']);
        $this->assertTrue($result['admitted']);
    }

    public function testNonEngineeringScopeBlocksBeforePhaseOrdering(): void
    {
        // Scope is rejected even when the phase ordering itself would also fail,
        // proving rule precedence (scope before ordering) is real, not canned.
        $result = $this->gate->admit(
            ['id' => 'L9-Q1', 'scope' => 'trading'],
            ['certified' => true],
            [],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_non_engineering_scope', $result['status']);
        $this->assertSame(['non_engineering_scope'], $result['blockers']);
    }

    // --- Acceptance: Q1/Q3 before Q2 blocks ---

    public function testQ1BeforeQ2Blocks(): void
    {
        // Q1 (operator-judgment amplification) requires Q2 (proven invariants).
        $result = $this->gate->admit(
            ['id' => 'L9-Q1'],
            ['certified' => true],
            ['L9-SOVEREIGNTY'],
        );

        $this->assertTrue($result['l8_certified']);
        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_safety_ordering', $result['status']);
        $this->assertSame(['sovereignty_q2_must_precede_q1_missing_q2'], $result['blockers']);
        $this->assertSame(['Q2'], $result['missing_prerequisites']);
        // What must come first is reported as the allowed phase.
        $this->assertSame('Q2', $result['allowed_l9_phase']);
        $this->assertSame('l9_child_not_dependency_unlocked', $result['reason']);
    }

    public function testQ3BeforeQ2Blocks(): void
    {
        // Q3 (discipline evolution) equally requires Q2 first.
        $result = $this->gate->admit(
            ['id' => 'L9-Q3'],
            ['certified' => true],
            ['L9-SOVEREIGNTY'],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_safety_ordering', $result['status']);
        $this->assertSame(['sovereignty_q2_must_precede_q3_missing_q2'], $result['blockers']);
        $this->assertSame(['Q2'], $result['missing_prerequisites']);
        $this->assertSame('Q2', $result['allowed_l9_phase']);
    }

    public function testQ2BeforeSovereigntyInvariantBlocks(): void
    {
        // Q2 itself is blocked until the sovereignty invariant is in place.
        $result = $this->gate->admit(
            ['id' => 'L9-Q2'],
            ['certified' => true],
            [],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_safety_ordering', $result['status']);
        $this->assertSame(['sovereignty_q2_must_precede_q2_missing_sovereignty'], $result['blockers']);
        $this->assertSame(['SOVEREIGNTY'], $result['missing_prerequisites']);
        $this->assertSame('SOVEREIGNTY', $result['allowed_l9_phase']);
    }

    public function testQ1AndQ3AreAdmittedOnceQ2IsComplete(): void
    {
        // Anti-scaffold: BOTH pillars unlock from the same completed set, proving
        // the prerequisite rule generalises rather than echoing one input.
        $completed = ['L9-SOVEREIGNTY', 'L9-Q2'];

        $q1 = $this->gate->admit(['id' => 'L9-Q1'], ['certified' => true], $completed);
        $q3 = $this->gate->admit(['id' => 'L9-Q3'], ['certified' => true], $completed);

        $this->assertTrue($q1['admitted']);
        $this->assertSame('admitted_dependency_unlocked_l9_child', $q1['status']);
        $this->assertSame([], $q1['blockers']);
        $this->assertSame('Q1', $q1['allowed_l9_phase']);
        $this->assertSame(['SOVEREIGNTY', 'Q2'], $q1['completed_phases']);

        $this->assertTrue($q3['admitted']);
        $this->assertSame('admitted_dependency_unlocked_l9_child', $q3['status']);
        $this->assertSame([], $q3['blockers']);
        $this->assertSame('Q3', $q3['allowed_l9_phase']);
    }

    public function testQ2AdmittedOnceSovereigntyIsComplete(): void
    {
        $result = $this->gate->admit(
            ['id' => 'L9-Q2'],
            ['certified' => true],
            ['L9-SOVEREIGNTY'],
        );

        $this->assertTrue($result['admitted']);
        $this->assertSame('admitted_dependency_unlocked_l9_child', $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(['SOVEREIGNTY'], $result['completed_phases']);
    }

    // --- Explicit slice dependencies ---

    public function testExplicitSliceDependencyUnmetBlocksEvenWhenPhasesComplete(): void
    {
        $result = $this->gate->admit(
            [
                'id' => 'L9-Q3',
                'depends_on' => ['S139', 'S140'],
            ],
            ['certified' => true],
            ['L9-SOVEREIGNTY', 'L9-Q2', 'S139'],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_safety_ordering', $result['status']);
        $this->assertSame(['dependency_unmet_s140'], $result['blockers']);
        // Phases are complete, so the allowed phase falls back to the candidate's own.
        $this->assertSame('Q3', $result['allowed_l9_phase']);
    }

    public function testExplicitSliceDependencyMetIsAdmitted(): void
    {
        $result = $this->gate->admit(
            [
                'id' => 'L9-Q3',
                'depends_on' => ['S139', 'S140'],
            ],
            ['certified' => true],
            ['L9-SOVEREIGNTY', 'L9-Q2', 'S139', 'S140'],
        );

        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('admitted_dependency_unlocked_l9_child', $result['status']);
    }

    // --- Fail-closed / pass-through ---

    public function testUnknownL9PhaseFailsClosed(): void
    {
        $result = $this->gate->admit(
            ['level' => 'L9', 'phase' => 'Q9'],
            ['certified' => true],
            ['L9-SOVEREIGNTY', 'L9-Q2'],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_unknown_l9_phase', $result['status']);
        $this->assertSame(['l9_phase_unknown'], $result['blockers']);
        $this->assertSame('', $result['allowed_l9_phase']);
    }

    public function testNonL9CandidateIsNotGated(): void
    {
        $result = $this->gate->admit(
            ['id' => 'L8-P5'],
            ['certified' => true],
            [],
        );

        $this->assertFalse($result['is_l9_candidate']);
        $this->assertTrue($result['admitted']);
        $this->assertSame('not_l9_candidate', $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('candidate_outside_l9_not_gated', $result['reason']);
    }

    public function testSeparateLevelAndPhaseKeysAreParsedCaseInsensitively(): void
    {
        $result = $this->gate->admit(
            ['level' => 'l9', 'phase' => 'sovereignty'],
            ['certified' => true],
            [],
        );

        $this->assertSame('L9', $result['level']);
        $this->assertSame('SOVEREIGNTY', $result['phase']);
        $this->assertSame('L9-SOVEREIGNTY', $result['candidate']);
        $this->assertTrue($result['admitted']);
    }

    // --- DoD: post-L8 only; admission never claims an L9 runtime ---

    public function testAdmissionNeverClaimsAnL9Runtime(): void
    {
        $result = $this->gate->admit(
            ['id' => 'L9-SOVEREIGNTY'],
            ['certified' => true],
            [],
        );

        // No field claims a running/executing L9: the gate only decides
        // admissibility (status starts with "admitted_", never "running"/"executing").
        $this->assertArrayNotHasKey('runtime', $result);
        $this->assertArrayNotHasKey('executed', $result);
        $this->assertArrayNotHasKey('running', $result);
        $this->assertStringStartsWith('admitted_', $result['status']);
        $this->assertStringNotContainsString('runtime', $result['status']);
        $this->assertStringNotContainsString('execut', $result['status']);
        // Post-L8 only: the predecessor that must be certified is the L8 slice.
        $this->assertSame('S125', $result['required_predecessor']);
    }

    public function testBlockedAdmissionAlsoNeverClaimsRuntime(): void
    {
        $result = $this->gate->admit(
            ['id' => 'L9-Q1'],
            ['certified' => true],
            ['L9-SOVEREIGNTY'],
        );

        $this->assertFalse($result['admitted']);
        $this->assertStringStartsWith('blocked_', $result['status']);
        $this->assertStringNotContainsString('runtime', $result['status']);
    }

    // --- Type contracts / determinism ---

    public function testCompletedPhasesAndBlockersHonourStringContractWithIntegerEntries(): void
    {
        // Integer entries in the completed set must not break the list<string>
        // contract via int key coercion.
        $result = $this->gate->admit(
            ['id' => 'L9-Q2'],
            ['certified' => true],
            ['L9-SOVEREIGNTY', 139, 'S140'],
        );

        $this->assertTrue($result['admitted']);
        $this->assertSame(['SOVEREIGNTY'], $result['completed_phases']);

        foreach ($result['completed_phases'] as $phase) {
            $this->assertIsString($phase);
        }

        foreach ($result['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }

        foreach ($result['missing_prerequisites'] as $missing) {
            $this->assertIsString($missing);
        }
    }

    public function testBlockersListIsAlwaysAZeroIndexedList(): void
    {
        $result = $this->gate->admit(
            ['id' => 'L9-Q1'],
            ['certified' => true],
            ['L9-SOVEREIGNTY'],
        );

        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        $this->assertSame(array_values($result['missing_prerequisites']), $result['missing_prerequisites']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $candidate = ['id' => 'L9-Q3', 'depends_on' => ['S139']];
        $l8 = ['certified' => true];
        $completed = ['L9-SOVEREIGNTY', 'L9-Q2', 'S139'];

        $first = $this->gate->admit($candidate, $l8, $completed);
        $second = $this->gate->admit($candidate, $l8, $completed);

        $this->assertSame($first, $second);
        $this->assertTrue($first['admitted']);
    }
}
