<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\HumanSurface;

use App\Services\Ai\HumanSurface\EngineeringGoalReadinessBandDecider;
use PHPUnit\Framework\TestCase;

final class EngineeringGoalReadinessBandDeciderTest extends TestCase
{
    private EngineeringGoalReadinessBandDecider $decider;

    protected function setUp(): void
    {
        $this->decider = new EngineeringGoalReadinessBandDecider();
    }

    public function testCompleteWithVerificationIsReady(): void
    {
        $result = $this->decider->decide(true, ['target_artifact', 'change_type', 'verification']);

        $this->assertSame('atlas.human_surface.engineering_goal_readiness.v1', $result['schema_version']);
        $this->assertSame('ready', $result['readiness']);
        $this->assertSame('complete_with_verification', $result['reason']);
    }

    public function testCompleteWithoutVerificationNeedsDetail(): void
    {
        $result = $this->decider->decide(true, ['target_artifact', 'change_type']);

        $this->assertSame('needs_detail', $result['readiness']);
        $this->assertSame('complete_without_verification', $result['reason']);
    }

    public function testNotCompleteNeedsDetailWithRequiredSlotsMissing(): void
    {
        $result = $this->decider->decide(false, ['verification']);

        $this->assertSame('needs_detail', $result['readiness']);
        $this->assertSame('required_slots_missing', $result['reason']);
    }

    public function testVerificationSlotIsCaseInsensitive(): void
    {
        $result = $this->decider->decide(true, ['VERIFICATION']);

        $this->assertSame('ready', $result['readiness']);
        $this->assertSame('complete_with_verification', $result['reason']);
    }

    public function testReadinessIsAlwaysOneOfTheTwoBandsAcrossAllCombos(): void
    {
        $allowed = ['ready', 'needs_detail'];

        $combos = [
            [true, ['target_artifact', 'change_type', 'verification']],
            [true, ['target_artifact', 'change_type']],
            [false, ['verification']],
            [false, ['target_artifact', 'change_type']],
        ];

        foreach ($combos as [$complete, $slots]) {
            $result = $this->decider->decide($complete, $slots);

            $this->assertContains($result['readiness'], $allowed);
        }
    }

    public function testVerificationWithSurroundingWhitespaceIsNormalized(): void
    {
        $result = $this->decider->decide(true, ['target_artifact', '  Verification  ']);

        $this->assertSame('ready', $result['readiness']);
        $this->assertSame('complete_with_verification', $result['reason']);
    }

    public function testUnknownExtraSlotsAreIgnoredWhenVerificationAbsent(): void
    {
        $result = $this->decider->decide(true, ['target_artifact', 'change_type', 'rollback_plan', 'owner']);

        $this->assertSame('needs_detail', $result['readiness']);
        $this->assertSame('complete_without_verification', $result['reason']);
    }

    public function testIncompleteAlwaysReportsMissingRegardlessOfVerificationPresence(): void
    {
        $result = $this->decider->decide(false, ['target_artifact', 'change_type', 'verification']);

        $this->assertSame('needs_detail', $result['readiness']);
        $this->assertSame('required_slots_missing', $result['reason']);
    }
}
