<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8PostL7AdmissionGate;
use PHPUnit\Framework\TestCase;

final class L8PostL7AdmissionGateTest extends TestCase
{
    private L8PostL7AdmissionGate $gate;

    protected function setUp(): void
    {
        $this->gate = new L8PostL7AdmissionGate();
    }

    public function testReturnsCanonicalSchemaVersion(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L8-P5'],
            ['certified' => true],
            [],
        );

        $this->assertSame('atlas.aaeos.l8.admission_gate.v1', $result['schema_version']);
    }

    public function testL7NotCertifiedBlocksAllL8(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L8-P5'],
            ['certified' => false],
            [],
        );

        $this->assertFalse($result['l7_certified']);
        $this->assertTrue($result['is_l8_candidate']);
        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_l8_pre_l7', $result['status']);
        $this->assertSame(['l7_not_certified'], $result['blockers']);
        $this->assertSame('l8_never_starts_before_real_l7', $result['reason']);
    }

    public function testL7NotCertifiedBlocksCapabilityPhaseToo(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L8-P1'],
            ['certified' => false],
            ['L8-P5'],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_l8_pre_l7', $result['status']);
        $this->assertSame(['l7_not_certified'], $result['blockers']);
    }

    public function testL8P5PassesOnlyAfterS100CertifiedTrue(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L8-P5'],
            ['certified' => true],
            [],
        );

        $this->assertTrue($result['l7_certified']);
        $this->assertTrue($result['is_safety_phase']);
        $this->assertTrue($result['admitted']);
        $this->assertSame('admitted_l8_p5_safety_precondition', $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('l8_p5_safety_precondition_admitted_after_l7', $result['reason']);
        $this->assertSame('L8', $result['level']);
        $this->assertSame('P5', $result['phase']);
        $this->assertSame('L8-P5', $result['candidate']);
    }

    public function testL8P1BeforeP5Blocks(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L8-P1'],
            ['certified' => true],
            [],
        );

        $this->assertTrue($result['l7_certified']);
        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_safety_ordering', $result['status']);
        $this->assertSame(['safety_ordering_requires_p5_first'], $result['blockers']);
        $this->assertSame(['P5'], $result['missing_prerequisites']);
    }

    public function testL8P2BeforeP5Blocks(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L8-P2'],
            ['certified' => true],
            [],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_safety_ordering', $result['status']);
        $this->assertSame(['safety_ordering_requires_p5_first'], $result['blockers']);
    }

    public function testL8P3BeforeP5Blocks(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L8-P3'],
            ['certified' => true],
            [],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_safety_ordering', $result['status']);
        $this->assertSame(['safety_ordering_requires_p1_first', 'safety_ordering_requires_p2_first'], $result['blockers']);
        $this->assertSame(['P1', 'P2'], $result['missing_prerequisites']);
    }

    public function testL8P4BeforeP5Blocks(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L8-P4'],
            ['certified' => true],
            [],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_safety_ordering', $result['status']);
        $this->assertSame(['safety_ordering_requires_p3_first'], $result['blockers']);
        $this->assertSame(['P3'], $result['missing_prerequisites']);
    }

    public function testDependencyUnlockedL8ChildIsAdmittedWhenPhasePrerequisitesComplete(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L8-P1'],
            ['certified' => true],
            ['L8-P5'],
        );

        $this->assertTrue($result['admitted']);
        $this->assertFalse($result['is_safety_phase']);
        $this->assertSame('admitted_dependency_unlocked_l8_child', $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('dependency_unlocked_l8_child_admitted', $result['reason']);
        $this->assertSame(['P5'], $result['completed_phases']);
    }

    public function testP3AdmittedOnlyAfterBothP1AndP2Complete(): void
    {
        $blockedWithP1Only = $this->gate->evaluate(
            ['id' => 'L8-P3'],
            ['certified' => true],
            ['L8-P5', 'L8-P1'],
        );

        $this->assertFalse($blockedWithP1Only['admitted']);
        $this->assertSame(['safety_ordering_requires_p2_first'], $blockedWithP1Only['blockers']);
        $this->assertSame(['P2'], $blockedWithP1Only['missing_prerequisites']);

        $admittedWithBoth = $this->gate->evaluate(
            ['id' => 'L8-P3'],
            ['certified' => true],
            ['L8-P5', 'L8-P1', 'L8-P2'],
        );

        $this->assertTrue($admittedWithBoth['admitted']);
        $this->assertSame('admitted_dependency_unlocked_l8_child', $admittedWithBoth['status']);
        $this->assertSame([], $admittedWithBoth['blockers']);
    }

    public function testP4AdmittedOnlyAfterP3Complete(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L8-P4'],
            ['certified' => true],
            ['L8-P5', 'L8-P1', 'L8-P2', 'L8-P3'],
        );

        $this->assertTrue($result['admitted']);
        $this->assertSame('admitted_dependency_unlocked_l8_child', $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(['P5', 'P1', 'P2', 'P3'], $result['completed_phases']);
    }

    public function testExplicitSliceDependencyUnmetBlocksEvenWhenPhasesComplete(): void
    {
        $result = $this->gate->evaluate(
            [
                'id' => 'L8-P3',
                'depends_on' => ['S102', 'S103'],
            ],
            ['certified' => true],
            ['L8-P5', 'L8-P1', 'L8-P2', 'S102'],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_safety_ordering', $result['status']);
        $this->assertSame(['dependency_unmet_s103'], $result['blockers']);
    }

    public function testExplicitSliceDependencyMetIsAdmitted(): void
    {
        $result = $this->gate->evaluate(
            [
                'id' => 'L8-P3',
                'depends_on' => ['S102', 'S103'],
            ],
            ['certified' => true],
            ['L8-P5', 'L8-P1', 'L8-P2', 'S102', 'S103'],
        );

        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('admitted_dependency_unlocked_l8_child', $result['status']);
    }

    public function testSafetyPhaseP5WithUnmetExplicitDependencyIsBlocked(): void
    {
        // P5 has no phase prerequisite, but the admission contract still
        // requires every explicit slice dependency to be in the completed set.
        // A P5 candidate that declares an unmet dependency must NOT slip through
        // on the safety-precondition path.
        $result = $this->gate->evaluate(
            ['id' => 'L8-P5', 'depends_on' => ['S102']],
            ['certified' => true],
            [],
        );

        $this->assertTrue($result['is_safety_phase']);
        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_safety_ordering', $result['status']);
        $this->assertSame(['dependency_unmet_s102'], $result['blockers']);
    }

    public function testUnknownL8PhaseFailsClosed(): void
    {
        $result = $this->gate->evaluate(
            ['level' => 'L8', 'phase' => 'P9'],
            ['certified' => true],
            ['L8-P5'],
        );

        $this->assertFalse($result['admitted']);
        $this->assertSame('blocked_unknown_l8_phase', $result['status']);
        $this->assertSame(['l8_phase_unknown'], $result['blockers']);
    }

    public function testNonL8CandidateIsNotGated(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L7-P0'],
            ['certified' => true],
            [],
        );

        $this->assertFalse($result['is_l8_candidate']);
        $this->assertTrue($result['admitted']);
        $this->assertSame('not_l8_candidate', $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('candidate_outside_l8_not_gated', $result['reason']);
    }

    public function testSeparateLevelAndPhaseKeysAreParsed(): void
    {
        $result = $this->gate->evaluate(
            ['level' => 'l8', 'phase' => 'p5'],
            ['certified' => true],
            [],
        );

        $this->assertSame('L8', $result['level']);
        $this->assertSame('P5', $result['phase']);
        $this->assertSame('L8-P5', $result['candidate']);
        $this->assertTrue($result['admitted']);
    }

    public function testL7CertifiedAliasKeyIsAccepted(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L8-P5'],
            ['l7_certified' => true],
            [],
        );

        $this->assertTrue($result['l7_certified']);
        $this->assertTrue($result['admitted']);
    }

    public function testCompletedPhasesListHonoursStringContractWithIntegerEntries(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L8-P1'],
            ['certified' => true],
            ['L8-P5', 102, 'S103'],
        );

        $this->assertTrue($result['admitted']);
        $this->assertSame(['P5'], $result['completed_phases']);

        foreach ($result['completed_phases'] as $phase) {
            $this->assertIsString($phase);
        }

        foreach ($result['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $candidate = ['id' => 'L8-P3', 'depends_on' => ['S102']];
        $l7 = ['certified' => true];
        $completed = ['L8-P5', 'L8-P1', 'L8-P2', 'S102'];

        $first = $this->gate->evaluate($candidate, $l7, $completed);
        $second = $this->gate->evaluate($candidate, $l7, $completed);

        $this->assertSame($first, $second);
        $this->assertTrue($first['admitted']);
    }
}
