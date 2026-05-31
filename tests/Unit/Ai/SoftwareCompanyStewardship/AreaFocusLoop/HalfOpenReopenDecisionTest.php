<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\HalfOpenReopenDecision;
use PHPUnit\Framework\TestCase;

final class HalfOpenReopenDecisionTest extends TestCase
{
    private HalfOpenReopenDecision $decision;

    protected function setUp(): void
    {
        $this->decision = new HalfOpenReopenDecision();
    }

    public function testReopensAfterProbeFailureThresholdMet(): void
    {
        $result = $this->decision->decide([
            'state' => 'half_open',
            'probe_failures' => 2,
            'consecutive_successes' => 0,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertSame('half_open', $result['circuit_state_in']);
        $this->assertSame(2, $result['probe_failures']);
        $this->assertSame(2, $result['reopen_failure_threshold']);
        $this->assertTrue($result['reopen']);
        $this->assertSame('open', $result['decided_state']);
        $this->assertSame('reopened_after_probe_failure_threshold', $result['reason']);
    }

    public function testSingleFailureDoesNotReopen(): void
    {
        $result = $this->decision->decide([
            'state' => 'half_open',
            'probe_failures' => 1,
            'consecutive_successes' => 0,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertFalse($result['reopen']);
        $this->assertSame('half_open', $result['decided_state']);
        $this->assertSame('single_transient_tolerated_stay_half_open', $result['reason']);
    }

    public function testZeroFailuresStayHalfOpen(): void
    {
        $result = $this->decision->decide([
            'state' => 'half_open',
            'probe_failures' => 0,
            'consecutive_successes' => 1,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertFalse($result['reopen']);
        $this->assertSame('half_open', $result['decided_state']);
        $this->assertSame('probe_succeeding_stay_half_open', $result['reason']);
    }

    public function testCustomReopenFailureThresholdMustBeMet(): void
    {
        $result = $this->decision->decide([
            'state' => 'half_open',
            'probe_failures' => 3,
            'reopen_failure_threshold' => 4,
            'consecutive_successes' => 0,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertSame(4, $result['reopen_failure_threshold']);
        $this->assertFalse($result['reopen']);
        $this->assertSame('half_open', $result['decided_state']);
        $this->assertSame('insufficient_probe_failures', $result['reason']);
    }

    public function testClosedCircuitDoesNotReopen(): void
    {
        $result = $this->decision->decide([
            'state' => 'closed',
            'probe_failures' => 5,
            'consecutive_successes' => 0,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertSame('closed', $result['circuit_state_in']);
        $this->assertFalse($result['reopen']);
        $this->assertSame('closed', $result['decided_state']);
        $this->assertSame('not_half_open', $result['reason']);
    }

    public function testReopenFailureThresholdClampedToAtLeastTwo(): void
    {
        $result = $this->decision->decide([
            'state' => 'half_open',
            'probe_failures' => 1,
            'reopen_failure_threshold' => 1,
            'consecutive_successes' => 0,
        ]);

        $this->assertSame(2, $result['reopen_failure_threshold']);
        $this->assertFalse($result['reopen']);
        $this->assertSame('single_transient_tolerated_stay_half_open', $result['reason']);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertDecisionSchema(array $result): void
    {
        $this->assertSame(
            'atlas.software_company_stewardship.half_open_reopen_decision.v1',
            $result['schema_version'],
        );
        $this->assertArrayHasKey('circuit_state_in', $result);
        $this->assertArrayHasKey('probe_failures', $result);
        $this->assertArrayHasKey('reopen_failure_threshold', $result);
        $this->assertArrayHasKey('consecutive_successes', $result);
        $this->assertArrayHasKey('reopen', $result);
        $this->assertArrayHasKey('decided_state', $result);
        $this->assertArrayHasKey('reason', $result);
    }
}
