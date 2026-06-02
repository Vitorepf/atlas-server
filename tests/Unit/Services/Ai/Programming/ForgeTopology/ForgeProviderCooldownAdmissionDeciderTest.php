<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Programming\ForgeTopology;

use App\Services\Ai\Programming\ForgeTopology\ForgeProviderCooldownAdmissionDecider;
use PHPUnit\Framework\TestCase;

final class ForgeProviderCooldownAdmissionDeciderTest extends TestCase
{
    private ForgeProviderCooldownAdmissionDecider $decider;

    protected function setUp(): void
    {
        $this->decider = new ForgeProviderCooldownAdmissionDecider();
    }

    public function testSchemaVersionLiteral(): void
    {
        $result = $this->decider->decide('rate_limit', 0);

        $this->assertSame('atlas.forge.provider_cooldown_admission.v1', $result['schema_version']);
    }

    public function testRateLimitElapsedPastWindowAdmits(): void
    {
        $result = $this->decider->decide('rate_limit', 61);

        $this->assertTrue($result['admit']);
        $this->assertSame('cooldown_elapsed', $result['reason']);
        $this->assertSame(0, $result['remaining_seconds']);
        $this->assertSame(60, $result['cooldown_seconds']);
        $this->assertSame('rate_limit', $result['failure_type']);
        $this->assertSame(61, $result['elapsed_seconds']);
    }

    public function testRateLimitStillCoolingDown(): void
    {
        $result = $this->decider->decide('rate_limit', 10);

        $this->assertFalse($result['admit']);
        $this->assertSame(50, $result['remaining_seconds']);
        $this->assertSame('still_cooling_down', $result['reason']);
        $this->assertSame(60, $result['cooldown_seconds']);
        $this->assertSame(10, $result['elapsed_seconds']);
    }

    public function testProviderCapacityExhaustedAtExactBoundaryAdmits(): void
    {
        $result = $this->decider->decide('provider_capacity_exhausted', 900);

        $this->assertTrue($result['admit']);
        $this->assertSame(0, $result['remaining_seconds']);
        $this->assertSame('cooldown_elapsed', $result['reason']);
        $this->assertSame(900, $result['cooldown_seconds']);
    }

    public function testProviderCapacityExhaustedOneSecondShortBlocks(): void
    {
        $result = $this->decider->decide('provider_capacity_exhausted', 899);

        $this->assertFalse($result['admit']);
        $this->assertSame(1, $result['remaining_seconds']);
        $this->assertSame('still_cooling_down', $result['reason']);
        $this->assertSame(900, $result['cooldown_seconds']);
    }

    public function testAuthFailedNeverReadmits(): void
    {
        $result = $this->decider->decide('auth_failed', 99999);

        $this->assertFalse($result['admit']);
        $this->assertSame('still_cooling_down', $result['reason']);
        $this->assertSame(PHP_INT_MAX, $result['remaining_seconds']);
        $this->assertSame(-1, $result['cooldown_seconds']);
        $this->assertSame('auth_failed', $result['failure_type']);
        $this->assertSame(99999, $result['elapsed_seconds']);
    }

    public function testUnknownFailureFailsClosed(): void
    {
        $result = $this->decider->decide('banana', 500);

        $this->assertFalse($result['admit']);
        $this->assertSame('unknown_failure_blocks', $result['reason']);
        $this->assertSame(0, $result['cooldown_seconds']);
        $this->assertSame(0, $result['remaining_seconds']);
        $this->assertSame('banana', $result['failure_type']);
        $this->assertSame(500, $result['elapsed_seconds']);
    }

    public function testNegativeElapsedIsClampedToZeroBeforeComparison(): void
    {
        $result = $this->decider->decide('timeout', -40);

        $this->assertSame(0, $result['elapsed_seconds']);
        $this->assertFalse($result['admit']);
        $this->assertSame(30, $result['cooldown_seconds']);
        $this->assertSame(30, $result['remaining_seconds']);
        $this->assertSame('still_cooling_down', $result['reason']);
    }

    public function testQuotaExhaustedWindowAndRemainingGeneralise(): void
    {
        $result = $this->decider->decide('quota_exhausted', 150);

        $this->assertSame(600, $result['cooldown_seconds']);
        $this->assertFalse($result['admit']);
        $this->assertSame(450, $result['remaining_seconds']);
        $this->assertSame('still_cooling_down', $result['reason']);
    }

    public function testModelUnavailableExactBoundaryAdmits(): void
    {
        $result = $this->decider->decide('model_unavailable', 120);

        $this->assertSame(120, $result['cooldown_seconds']);
        $this->assertTrue($result['admit']);
        $this->assertSame(0, $result['remaining_seconds']);
        $this->assertSame('cooldown_elapsed', $result['reason']);
    }

    public function testProviderErrorWellPastWindowAdmitsWithZeroRemaining(): void
    {
        $result = $this->decider->decide('provider_error', 5000);

        $this->assertSame(30, $result['cooldown_seconds']);
        $this->assertTrue($result['admit']);
        $this->assertSame(0, $result['remaining_seconds']);
        $this->assertSame('cooldown_elapsed', $result['reason']);
    }
}
