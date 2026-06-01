<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\CompoundingFlywheelCertificationEvaluator;
use PHPUnit\Framework\TestCase;

final class CompoundingFlywheelCertificationEvaluatorTest extends TestCase
{
    private CompoundingFlywheelCertificationEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new CompoundingFlywheelCertificationEvaluator();
    }

    public function testConsecutiveMeasuredCyclesWithPositiveAggregateLiftCertify(): void
    {
        $result = $this->evaluator->certify([
            ['lift' => 4.0, 'measured' => true, 'replay_hash' => 'h1', 'expected_replay_hash' => 'h1'],
            ['lift' => 2.5, 'measured' => true, 'replay_hash' => 'h2', 'expected_replay_hash' => 'h2'],
            ['lift' => 1.5, 'measured' => true, 'replay_hash' => 'h3', 'expected_replay_hash' => 'h3'],
        ]);

        $this->assertSame('atlas.loop.compounding_flywheel_certification.v1', $result['schema_version']);
        $this->assertTrue($result['certified']);
        $this->assertSame(3, $result['cycle_count']);
        $this->assertSame(3, $result['positive_lift_count']);
        $this->assertSame(0, $result['unmeasured_auto_apply_count']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(8.0, $result['aggregate_lift']);
    }

    public function testUnmeasuredAutoApplyBlocksEvenWithPositiveLift(): void
    {
        $result = $this->evaluator->certify([
            ['lift' => 4.0, 'measured' => true],
            ['lift' => 3.0, 'measured' => true],
            ['lift' => 5.0, 'measured' => false, 'auto_apply' => true],
        ]);

        $this->assertFalse($result['certified']);
        $this->assertSame(1, $result['unmeasured_auto_apply_count']);
        $this->assertContains('unmeasured_auto_apply', $result['blockers']);
        $this->assertSame(7.0, $result['aggregate_lift']);
        $this->assertSame(2, $result['positive_lift_count']);
    }

    public function testInsufficientConsecutiveCyclesBlocks(): void
    {
        $result = $this->evaluator->certify([
            ['lift' => 6.0, 'measured' => true],
            ['lift' => 4.0, 'measured' => true],
        ]);

        $this->assertFalse($result['certified']);
        $this->assertSame(2, $result['cycle_count']);
        $this->assertContains('insufficient_cycles', $result['blockers']);
    }

    public function testOneRegressionBelowToleranceBlocks(): void
    {
        $result = $this->evaluator->certify(
            [
                ['lift' => 4.0, 'measured' => true],
                ['lift' => 3.0, 'measured' => true],
                ['lift' => -2.0, 'measured' => true],
            ],
            ['regression_tolerance' => 0.5],
        );

        $this->assertFalse($result['certified']);
        $this->assertContains('regression_below_tolerance', $result['blockers']);
        $this->assertSame(5.0, $result['aggregate_lift']);
        $this->assertSame(2, $result['positive_lift_count']);
    }

    public function testReplayHashMismatchBlocks(): void
    {
        $result = $this->evaluator->certify([
            ['lift' => 3.0, 'measured' => true, 'replay_hash' => 'a1', 'expected_replay_hash' => 'a1'],
            ['lift' => 2.0, 'measured' => true, 'replay_hash' => 'WRONG', 'expected_replay_hash' => 'b2'],
            ['lift' => 2.0, 'measured' => true, 'replay_hash' => 'c3', 'expected_replay_hash' => 'c3'],
        ]);

        $this->assertFalse($result['certified']);
        $this->assertContains('replay_hash_mismatch', $result['blockers']);
    }

    public function testRegressionWithinToleranceDoesNotBlock(): void
    {
        $result = $this->evaluator->certify(
            [
                ['lift' => 5.0, 'measured' => true],
                ['lift' => 3.0, 'measured' => true],
                ['lift' => -0.2, 'measured' => true],
            ],
            ['regression_tolerance' => 0.5],
        );

        $this->assertTrue($result['certified']);
        $this->assertNotContains('regression_below_tolerance', $result['blockers']);
        $this->assertSame(7.8, $result['aggregate_lift']);
        $this->assertSame(2, $result['positive_lift_count']);
    }

    public function testCustomRequiredCyclesRaisesTheBar(): void
    {
        $result = $this->evaluator->certify(
            [
                ['lift' => 2.0, 'measured' => true],
                ['lift' => 2.0, 'measured' => true],
                ['lift' => 2.0, 'measured' => true],
            ],
            ['required_cycles' => 5],
        );

        $this->assertFalse($result['certified']);
        $this->assertContains('insufficient_cycles', $result['blockers']);
        $this->assertSame(3, $result['cycle_count']);
    }

    public function testNonPositiveAggregateLiftBlocksDespiteEnoughCycles(): void
    {
        $result = $this->evaluator->certify([
            ['lift' => 1.0, 'measured' => true],
            ['lift' => -1.5, 'measured' => true],
            ['lift' => 0.5, 'measured' => true],
        ]);

        $this->assertFalse($result['certified']);
        $this->assertSame(0.0, $result['aggregate_lift']);
        $this->assertContains('non_positive_aggregate_lift', $result['blockers']);
    }

    public function testUnmeasuredDeltasDoNotProveLift(): void
    {
        $result = $this->evaluator->certify([
            ['lift' => 10.0, 'measured' => false],
            ['lift' => 3.0, 'measured' => true],
            ['lift' => 2.0, 'measured' => true],
        ]);

        $this->assertSame(2, $result['positive_lift_count']);
        $this->assertSame(5.0, $result['aggregate_lift']);
        $this->assertSame(0, $result['unmeasured_auto_apply_count']);
    }

    public function testMultipleBlockersAreEmittedInCanonicalOrder(): void
    {
        $result = $this->evaluator->certify([
            ['lift' => -5.0, 'measured' => true],
            ['lift' => 9.9, 'measured' => false, 'auto_apply' => true, 'replay_hash' => 'WRONG', 'expected_replay_hash' => 'right'],
        ]);

        $this->assertFalse($result['certified']);
        $this->assertSame(
            ['insufficient_cycles', 'unmeasured_auto_apply', 'replay_hash_mismatch', 'regression_below_tolerance', 'non_positive_aggregate_lift'],
            $result['blockers'],
        );
        $this->assertSame(1, $result['unmeasured_auto_apply_count']);
        $this->assertSame(-5.0, $result['aggregate_lift']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $cycles = [
            ['lift' => 3.0, 'measured' => true, 'replay_hash' => 'z', 'expected_replay_hash' => 'z'],
            ['lift' => 2.0, 'measured' => true],
            ['lift' => 1.0, 'measured' => true],
        ];

        $this->assertSame(
            $this->evaluator->certify($cycles),
            $this->evaluator->certify($cycles),
        );
    }
}
