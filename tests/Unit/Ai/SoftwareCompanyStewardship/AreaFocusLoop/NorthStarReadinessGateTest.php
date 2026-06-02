<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\NorthStarReadinessGate;
use PHPUnit\Framework\TestCase;

final class NorthStarReadinessGateTest extends TestCase
{
    private NorthStarReadinessGate $gate;

    protected function setUp(): void
    {
        $this->gate = new NorthStarReadinessGate();
    }

    public function testSchemaVersionIsStable(): void
    {
        $result = $this->gate->evaluate(
            ['level' => 'L8', 'phase' => 'P5'],
            ['l7_certified' => true],
        );

        $this->assertSame('atlas.aaeos.north_star_readiness_gate.v1', $result['schema_version']);
    }

    public function testFutureMapCandidateBlockedNorthStarPreL7WhenNotCertified(): void
    {
        $result = $this->gate->evaluate(
            ['level' => 'L8', 'phase' => 'P5'],
            ['l7_certified' => false],
        );

        $this->assertSame('blocked_north_star_pre_l7', $result['status']);
        $this->assertFalse($result['admitted']);
        $this->assertFalse($result['l7_certified']);
        $this->assertTrue($result['north_star_candidate']);
        $this->assertSame(['l7_not_certified'], $result['blockers']);
        $this->assertSame('L8-P5', $result['candidate']);
    }

    public function testMissingL7CertifiedFlagDefaultsToBlocked(): void
    {
        $result = $this->gate->evaluate(
            ['level' => 'L9', 'phase' => 'Q2'],
            [],
        );

        $this->assertSame('blocked_north_star_pre_l7', $result['status']);
        $this->assertFalse($result['admitted']);
        $this->assertSame(['l7_not_certified'], $result['blockers']);
    }

    public function testL10R1BeforeL7Blocks(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L10-R1'],
            ['l7_certified' => false],
        );

        $this->assertSame('blocked_north_star_pre_l7', $result['status']);
        $this->assertFalse($result['admitted']);
        $this->assertTrue($result['north_star_candidate']);
        $this->assertSame('L10', $result['level']);
        $this->assertSame('R1', $result['phase']);
        $this->assertSame('L10-R1', $result['candidate']);
        $this->assertSame(['l7_not_certified'], $result['blockers']);
    }

    public function testL9CandidateBeforeL7Blocks(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L9-Q1'],
            ['l7_certified' => false],
        );

        $this->assertSame('blocked_north_star_pre_l7', $result['status']);
        $this->assertFalse($result['admitted']);
        $this->assertSame('L9', $result['level']);
    }

    public function testWhenL7CertifiedOnlyL8P5CanEnter(): void
    {
        $result = $this->gate->evaluate(
            ['level' => 'L8', 'phase' => 'P5'],
            ['l7_certified' => true],
        );

        $this->assertSame('admitted_l8_p5_only', $result['status']);
        $this->assertTrue($result['admitted']);
        $this->assertTrue($result['l7_certified']);
        $this->assertTrue($result['north_star_candidate']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('L8-P5', $result['candidate']);
    }

    public function testWhenL7CertifiedNonP5L8CandidateStillBlocked(): void
    {
        $result = $this->gate->evaluate(
            ['level' => 'L8', 'phase' => 'P1'],
            ['l7_certified' => true],
        );

        $this->assertSame('blocked_safety_ordering_requires_l8_p5', $result['status']);
        $this->assertFalse($result['admitted']);
        $this->assertTrue($result['l7_certified']);
        $this->assertSame(['safety_ordering_requires_l8_p5_first'], $result['blockers']);
    }

    public function testWhenL7CertifiedL10R1StillBlockedBySafetyOrdering(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L10-R1'],
            ['l7_certified' => true],
        );

        $this->assertSame('blocked_safety_ordering_requires_l8_p5', $result['status']);
        $this->assertFalse($result['admitted']);
        $this->assertSame(['safety_ordering_requires_l8_p5_first'], $result['blockers']);
    }

    public function testNorthStarFlaggedCandidateBlockedBeforeL7(): void
    {
        $result = $this->gate->evaluate(
            ['level' => 'L7', 'phase' => 'X', 'north_star' => true],
            ['l7_certified' => false],
        );

        $this->assertTrue($result['north_star_candidate']);
        $this->assertSame('blocked_north_star_pre_l7', $result['status']);
        $this->assertFalse($result['admitted']);
    }

    public function testNonFutureMapCandidateIsNotGated(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L6-A2'],
            ['l7_certified' => false],
        );

        $this->assertFalse($result['north_star_candidate']);
        $this->assertSame('not_north_star_candidate', $result['status']);
        $this->assertTrue($result['admitted']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('L6', $result['level']);
    }

    public function testLowercaseAndUnderscoreIdsAreNormalized(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'l8_p5'],
            ['l7_certified' => true],
        );

        $this->assertSame('L8-P5', $result['candidate']);
        $this->assertSame('L8', $result['level']);
        $this->assertSame('P5', $result['phase']);
        $this->assertSame('admitted_l8_p5_only', $result['status']);
        $this->assertTrue($result['admitted']);
    }

    public function testBlockersIsAlwaysAListOfStrings(): void
    {
        $result = $this->gate->evaluate(
            ['id' => 'L10-R1'],
            ['l7_certified' => false],
        );

        $blockers = $result['blockers'];

        $this->assertSame(array_values($blockers), $blockers);
        $this->assertSame(array_keys($blockers), range(0, count($blockers) - 1));

        foreach ($blockers as $blocker) {
            $this->assertIsString($blocker);
        }
    }

    public function testEvaluationIsDeterministic(): void
    {
        $candidate = ['id' => 'L8-P5'];
        $l7 = ['l7_certified' => true];

        $first = $this->gate->evaluate($candidate, $l7);
        $second = $this->gate->evaluate($candidate, $l7);

        $this->assertSame($first, $second);
    }
}
