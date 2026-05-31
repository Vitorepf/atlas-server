<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\HalfOpenRecloseDecision;
use PHPUnit\Framework\TestCase;

final class HalfOpenRecloseDecisionTest extends TestCase
{
    private HalfOpenRecloseDecision $decision;

    protected function setUp(): void
    {
        $this->decision = new HalfOpenRecloseDecision();
    }

    public function testReclosesAfterRequiredConsecutiveSuccessesWithNoProbeFailures(): void
    {
        $result = $this->decision->decide([
            'state' => 'half_open',
            'consecutive_successes' => 2,
            'probe_failures' => 0,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertSame('half_open', $result['circuit_state_in']);
        $this->assertSame(2, $result['consecutive_successes']);
        $this->assertSame(2, $result['required_successes']);
        $this->assertSame(0, $result['probe_failures']);
        $this->assertTrue($result['reclose']);
        $this->assertSame('closed', $result['decided_state']);
        $this->assertSame('reclosed_after_required_consecutive_successes', $result['reason']);
    }

    public function testSingleSuccessDoesNotReclose(): void
    {
        $result = $this->decision->decide([
            'state' => 'half_open',
            'consecutive_successes' => 1,
            'probe_failures' => 0,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertFalse($result['reclose']);
        $this->assertSame('half_open', $result['decided_state']);
        $this->assertSame('insufficient_consecutive_successes', $result['reason']);
    }

    public function testProbeFailureBlocksRecloseDespiteEnoughSuccesses(): void
    {
        $result = $this->decision->decide([
            'state' => 'half_open',
            'consecutive_successes' => 3,
            'probe_failures' => 1,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertFalse($result['reclose']);
        $this->assertSame('half_open', $result['decided_state']);
        $this->assertSame('probe_failure_blocks_reclose', $result['reason']);
    }

    public function testCustomRequiredSuccessesThresholdMustBeMet(): void
    {
        $result = $this->decision->decide([
            'state' => 'half_open',
            'consecutive_successes' => 3,
            'required_successes' => 5,
            'probe_failures' => 0,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertSame(5, $result['required_successes']);
        $this->assertFalse($result['reclose']);
        $this->assertSame('half_open', $result['decided_state']);
        $this->assertSame('insufficient_consecutive_successes', $result['reason']);
    }

    public function testOpenCircuitDoesNotReclose(): void
    {
        $result = $this->decision->decide([
            'state' => 'open',
            'consecutive_successes' => 5,
            'probe_failures' => 0,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertSame('open', $result['circuit_state_in']);
        $this->assertFalse($result['reclose']);
        $this->assertSame('open', $result['decided_state']);
        $this->assertSame('not_half_open', $result['reason']);
    }

    public function testRequiredSuccessesClampedToAtLeastTwo(): void
    {
        $result = $this->decision->decide([
            'state' => 'half_open',
            'consecutive_successes' => 1,
            'required_successes' => 1,
            'probe_failures' => 0,
        ]);

        $this->assertSame(2, $result['required_successes']);
        $this->assertFalse($result['reclose']);
        $this->assertSame('insufficient_consecutive_successes', $result['reason']);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertDecisionSchema(array $result): void
    {
        $this->assertSame(
            'atlas.software_company_stewardship.half_open_reclose_decision.v1',
            $result['schema_version'],
        );
        $this->assertArrayHasKey('circuit_state_in', $result);
        $this->assertArrayHasKey('consecutive_successes', $result);
        $this->assertArrayHasKey('required_successes', $result);
        $this->assertArrayHasKey('probe_failures', $result);
        $this->assertArrayHasKey('reclose', $result);
        $this->assertArrayHasKey('decided_state', $result);
        $this->assertArrayHasKey('reason', $result);
    }
}
