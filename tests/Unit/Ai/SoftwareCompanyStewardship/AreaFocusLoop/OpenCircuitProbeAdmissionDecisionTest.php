<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OpenCircuitProbeAdmissionDecision;
use PHPUnit\Framework\TestCase;

final class OpenCircuitProbeAdmissionDecisionTest extends TestCase
{
    private OpenCircuitProbeAdmissionDecision $decision;

    protected function setUp(): void
    {
        $this->decision = new OpenCircuitProbeAdmissionDecision();
    }

    public function testAdmitsProbeWhenOpenCooldownElapsed(): void
    {
        $result = $this->decision->decide([
            'state' => 'open',
            'seconds_since_open' => 90,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertSame('open', $result['circuit_state_in']);
        $this->assertSame(90, $result['seconds_since_open']);
        $this->assertSame(60, $result['cooldown_seconds']);
        $this->assertSame(0, $result['seconds_remaining']);
        $this->assertTrue($result['admit_probe']);
        $this->assertSame('half_open', $result['decided_state']);
        $this->assertSame('cooldown_elapsed_admit_probe', $result['reason']);
    }

    public function testStaysOpenWhenCooldownNotElapsed(): void
    {
        $result = $this->decision->decide([
            'state' => 'open',
            'seconds_since_open' => 10,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertSame(60, $result['cooldown_seconds']);
        $this->assertSame(50, $result['seconds_remaining']);
        $this->assertFalse($result['admit_probe']);
        $this->assertSame('open', $result['decided_state']);
        $this->assertSame('cooldown_not_elapsed_stay_open', $result['reason']);
    }

    public function testHalfOpenCircuitDoesNotAdmitProbe(): void
    {
        $result = $this->decision->decide([
            'state' => 'half_open',
            'seconds_since_open' => 90,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertSame('half_open', $result['circuit_state_in']);
        $this->assertFalse($result['admit_probe']);
        $this->assertSame('half_open', $result['decided_state']);
        $this->assertSame('already_half_open', $result['reason']);
    }

    public function testClosedCircuitDoesNotAdmitProbe(): void
    {
        $result = $this->decision->decide([
            'state' => 'closed',
            'seconds_since_open' => 90,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertSame('closed', $result['circuit_state_in']);
        $this->assertFalse($result['admit_probe']);
        $this->assertSame('closed', $result['decided_state']);
        $this->assertSame('not_open', $result['reason']);
    }

    public function testCustomCooldownOverrideBlocksEarlyProbe(): void
    {
        $result = $this->decision->decide([
            'state' => 'open',
            'seconds_since_open' => 120,
            'cooldown_seconds' => 300,
        ]);

        $this->assertDecisionSchema($result);
        $this->assertSame(300, $result['cooldown_seconds']);
        $this->assertSame(180, $result['seconds_remaining']);
        $this->assertFalse($result['admit_probe']);
        $this->assertSame('open', $result['decided_state']);
        $this->assertSame('cooldown_not_elapsed_stay_open', $result['reason']);
    }

    public function testCooldownClampedToAtLeastOne(): void
    {
        $result = $this->decision->decide([
            'state' => 'open',
            'seconds_since_open' => 0,
            'cooldown_seconds' => 0,
        ]);

        $this->assertSame(1, $result['cooldown_seconds']);
        $this->assertFalse($result['admit_probe']);
        $this->assertSame(1, $result['seconds_remaining']);
        $this->assertSame('cooldown_not_elapsed_stay_open', $result['reason']);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertDecisionSchema(array $result): void
    {
        $this->assertSame(
            'atlas.software_company_stewardship.open_circuit_probe_admission.v1',
            $result['schema_version'],
        );
        $this->assertArrayHasKey('circuit_state_in', $result);
        $this->assertArrayHasKey('seconds_since_open', $result);
        $this->assertArrayHasKey('cooldown_seconds', $result);
        $this->assertArrayHasKey('seconds_remaining', $result);
        $this->assertArrayHasKey('admit_probe', $result);
        $this->assertArrayHasKey('decided_state', $result);
        $this->assertArrayHasKey('reason', $result);
    }
}
