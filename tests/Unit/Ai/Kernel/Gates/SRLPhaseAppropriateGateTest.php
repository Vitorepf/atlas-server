<?php

namespace Tests\Unit\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Gates\SRLPhaseAppropriateGate;
use Tests\TestCase;

class SRLPhaseAppropriateGateTest extends TestCase
{
    public function test_gate_blocks_skipped_phase(): void
    {
        $result = app(SRLPhaseAppropriateGate::class)->evaluate([], 'self_reflection');

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('srl_phase_skipped', $result['reason']);
    }
}
