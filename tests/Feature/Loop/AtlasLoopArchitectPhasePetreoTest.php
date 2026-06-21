<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use Tests\TestCase;

/**
 * §4 · RECURSIVE SELF-IMPROVEMENT — the IMMUTABLE constitution holds over the NEW quality organs. The loop may
 * evolve its own non-pétreo brain, but it must NEVER be able to weaken the architect phase back into theater:
 * the grounded critic, the admission gate, the per-type proof contract, the veto bridge, the cross-model
 * critique, and the leverage decider are pétreo by the SAME principle as the cert-chain + the NextWorkDecider.
 * A self-edit can ADD to the constitution but never loosen it.
 */
final class AtlasLoopArchitectPhasePetreoTest extends TestCase
{
    public function test_the_architect_phase_quality_organs_are_forbidden_self_targets(): void
    {
        $guard = new AtlasLoopHarnessGuard;
        foreach ([
            'app/Services/Ai/AutonomousEvolution/AtlasLoopGroundedProjectionRoles.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopArchitectPhaseGate.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopWorkTypeContract.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopProjectionObligationContracts.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopModelProjectionCritic.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopLeverageSelector.php',
        ] as $organ) {
            $this->assertTrue(
                $guard->isForbiddenSelfTarget($organ),
                "$organ must be pétreo — the loop can never weaken its own quality gate",
            );
        }
    }

    public function test_a_non_organ_brain_file_stays_editable_so_recursive_self_improvement_is_possible(): void
    {
        // The constitution protects the GATES, not the whole brain — the loop must still be able to evolve its
        // non-pétreo machinery (e.g. a supply lane), else recursive self-improvement is impossible.
        $guard = new AtlasLoopHarnessGuard;
        $this->assertFalse(
            $guard->isForbiddenSelfTarget('app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopOrphanWiringSupplyLane.php'),
            'a non-gate brain file stays editable — the loop evolves itself, just never its judge',
        );
    }
}
