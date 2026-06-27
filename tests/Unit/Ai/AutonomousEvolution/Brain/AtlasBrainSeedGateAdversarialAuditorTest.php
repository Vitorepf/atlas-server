<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedGateAdversarialAuditor;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSeedQualityGate;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Tests\TestCase;

/**
 * FROZEN proof that the seed-quality gate REFUSES every promoted advisory + every universal blocking key.
 * If a future refactor of the gate drops one of its promotions, this test goes red AND the runtime auditor
 * starts surfacing the hole — anti-regression by construction (mirrors the inspector auditor's contract).
 */
final class AtlasBrainSeedGateAdversarialAuditorTest extends TestCase
{
    private function gate(): AtlasBrainSeedQualityGate
    {
        return new AtlasBrainSeedQualityGate(new AtlasTaskPacketQualityInspector);
    }

    public function test_current_seed_gate_has_zero_holes_against_the_battery(): void
    {
        $auditor = new AtlasBrainSeedGateAdversarialAuditor;
        $report = $auditor->audit($this->gate());

        self::assertGreaterThanOrEqual(3, $report['attacks_tried']);
        self::assertSame([], $report['holes'], 'seed gate regression: an adversarial attack passed → '.json_encode($report['holes']));
    }

    public function test_audit_is_byte_stable_across_invocations(): void
    {
        $auditor = new AtlasBrainSeedGateAdversarialAuditor;
        self::assertSame($auditor->audit($this->gate()), $auditor->audit($this->gate()));
    }

    public function test_auditor_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSeedGateAdversarialAuditor.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}
