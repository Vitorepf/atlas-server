<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopRecursiveSelfImprovementGate;
use App\Services\Ai\Foundry\Rsi\RsiInvariantGuardService;
use App\Services\Ai\Foundry\Rsi\RsiSelfImprovementProposalGate;
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
        RsiSelfImprovementProposalGate::$screenObserver = null;
    }

    protected function tearDown(): void
    {
        RsiSelfImprovementProposalGate::$screenObserver = null;

        parent::tearDown();
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

    public function test_constitution_first_is_intent_independent_even_for_a_harden_proposal(): void
    {
        // a "harden" intent must NOT bypass the constitution to edit the judge — flag- AND intent-independent.
        config()->set('atlas.loop.recursive_self_improvement_auto_apply', true);
        $v = $this->gate->evaluate('app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php', AtlasLoopRecursiveSelfImprovementGate::KIND_HARDEN);
        $this->assertFalse($v['admitted'], 'a harden intent cannot reach past the constitution to a cert organ');
        $this->assertSame(AtlasLoopRecursiveSelfImprovementGate::STATUS_REFUSED_PETREO, $v['status']);
    }

    public function test_a_harden_proposal_on_a_non_petreo_file_is_classified_and_gated(): void
    {
        config()->set('atlas.loop.recursive_self_improvement_auto_apply', false);
        $v = $this->gate->evaluate('app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopOrphanWiringSupplyLane.php', AtlasLoopRecursiveSelfImprovementGate::KIND_HARDEN);
        $this->assertTrue($v['admitted'], 'hardening a non-pétreo file is the safe exponential direction');
        $this->assertSame(AtlasLoopRecursiveSelfImprovementGate::KIND_HARDEN, $v['kind']);
        $this->assertSame(AtlasLoopRecursiveSelfImprovementGate::STATUS_PARKED, $v['status'], 'still parked without operator policy');
    }

    public function test_operator_policy_arms_auto_apply_for_a_legal_target_only(): void
    {
        config()->set('atlas.loop.recursive_self_improvement_auto_apply', true);
        $v = $this->gate->evaluate('app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopOrphanWiringSupplyLane.php');
        $this->assertTrue($v['admitted']);
        $this->assertTrue($v['auto_apply'], 'operator policy arms auto-apply for a non-pétreo target');
        $this->assertSame(AtlasLoopRecursiveSelfImprovementGate::STATUS_AUTO_APPLY_ARMED, $v['status']);
    }

    public function test_rsi_gate_blocks_a_cert_organ_before_the_invariant_guard_runs(): void
    {
        $screenCalls = 0;
        RsiSelfImprovementProposalGate::$screenObserver = static function () use (&$screenCalls): void {
            $screenCalls++;
        };

        $result = new RsiSelfImprovementProposalGate(
            app(RsiInvariantGuardService::class),
            null,
            new AtlasLoopRecursiveSelfImprovementGate,
        )->admit([
            'diff' => [
                'changed_paths' => ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'],
            ],
        ], [
            'rsi_mode_enabled' => true,
        ]);

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_BLOCKED_BY_INVARIANT, $result['status']);
        $this->assertTrue((bool) data_get($result, 'screening.constitution_refused'));
        $this->assertSame('app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php', data_get($result, 'screening.refused_path'));
        $this->assertSame('constitution_forbids_editing_a_cert_organ', data_get($result, 'screening.reason'));
        $this->assertFalse((bool) ($result['auto_applied'] ?? true));
        $this->assertSame(0, $screenCalls, 'constitution must short-circuit before invariant guard screen()');
    }

    public function test_rsi_gate_preserves_guard_and_earned_autonomy_flow_for_non_petreo_paths(): void
    {
        $screenCalls = 0;
        RsiSelfImprovementProposalGate::$screenObserver = static function () use (&$screenCalls): void {
            $screenCalls++;
        };

        $result = new RsiSelfImprovementProposalGate(
            app(RsiInvariantGuardService::class),
            null,
            new AtlasLoopRecursiveSelfImprovementGate,
        )->admit([
            'diff' => [
                'changed_paths' => ['app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopOrphanWiringSupplyLane.php'],
            ],
        ], [
            'rsi_mode_enabled' => true,
        ]);

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_ROUTED_TO_HUMAN_GATE, $result['status']);
        $this->assertSame(RsiInvariantGuardService::VERDICT_PASSED, data_get($result, 'screening.verdict'));
        $this->assertFalse((bool) data_get($result, 'screening.constitution_refused', false));
        $this->assertTrue((bool) ($result['routed_to_human_gate'] ?? false));
        $this->assertFalse((bool) ($result['auto_applied'] ?? true));
        $this->assertSame(1, $screenCalls, 'non-petreo paths must fall through to invariant guard + earned autonomy');
    }

    public function test_rsi_mode_off_still_short_circuits_to_skipped_before_constitution_and_guard(): void
    {
        $screenCalls = 0;
        RsiSelfImprovementProposalGate::$screenObserver = static function () use (&$screenCalls): void {
            $screenCalls++;
        };

        $result = new RsiSelfImprovementProposalGate(
            app(RsiInvariantGuardService::class),
            null,
            new AtlasLoopRecursiveSelfImprovementGate,
        )->admit([
            'diff' => [
                'changed_paths' => ['app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php'],
            ],
        ]);

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_SKIPPED, $result['status']);
        $this->assertSame(0, $screenCalls);
    }
}
