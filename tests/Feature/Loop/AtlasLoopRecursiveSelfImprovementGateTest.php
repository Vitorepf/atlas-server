<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopRecursiveSelfImprovementGate;
use Tests\TestCase;

/**
 * §4 · RECURSIVE SELF-IMPROVEMENT — built, GATED, not activated. The constitution refuses a self-edit to a
 * cert organ FIRST and flag-independently (even with auto-apply armed); a non-pétreo brain file is a legal
 * proposal but is PARKED unless the operator policy flag is on. The loop never edits its own judge, and never
 * edits itself unattended without explicit operator policy.
 */
final class AtlasLoopRecursiveSelfImprovementGateTest extends TestCase
{
    private AtlasLoopRecursiveSelfImprovementGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasLoopRecursiveSelfImprovementGate;
    }

    public function test_a_cert_organ_self_edit_is_refused_even_when_auto_apply_is_armed(): void
    {
        // THE BEDROCK: the constitution is flag-independent. Arm the operator policy as hostile as possible…
        config()->set('atlas.loop.recursive_self_improvement_auto_apply', true);
        foreach ([
            'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopArchitectPhaseGate.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php',
        ] as $organ) {
            $v = $this->gate->evaluate($organ);
            $this->assertFalse($v['admitted'], "$organ must be refused — the loop can never edit its own gate");
            $this->assertFalse($v['auto_apply']);
            $this->assertSame(AtlasLoopRecursiveSelfImprovementGate::STATUS_REFUSED_PETREO, $v['status']);
        }
    }

    public function test_a_non_petreo_brain_file_is_parked_when_policy_is_off(): void
    {
        config()->set('atlas.loop.recursive_self_improvement_auto_apply', false);
        $v = $this->gate->evaluate('app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopOrphanWiringSupplyLane.php');
        $this->assertTrue($v['admitted'], 'a non-gate brain file is a legal self-improvement proposal');
        $this->assertFalse($v['auto_apply'], 'but never auto-applied without operator policy');
        $this->assertSame(AtlasLoopRecursiveSelfImprovementGate::STATUS_PARKED, $v['status']);
    }

    public function test_operator_policy_arms_auto_apply_for_a_legal_target_only(): void
    {
        config()->set('atlas.loop.recursive_self_improvement_auto_apply', true);
        $v = $this->gate->evaluate('app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopOrphanWiringSupplyLane.php');
        $this->assertTrue($v['admitted']);
        $this->assertTrue($v['auto_apply'], 'operator policy arms auto-apply for a non-pétreo target');
        $this->assertSame(AtlasLoopRecursiveSelfImprovementGate::STATUS_AUTO_APPLY_ARMED, $v['status']);
    }
}
