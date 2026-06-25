<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\AutonomousRuntime;

use App\Services\Ai\SelfConstruction\AutonomousRuntime\AtlasAutonomousRuntimeCycleStateMachine;
use Tests\TestCase;

final class AtlasAutonomousRuntimeCycleStateMachineTest extends TestCase
{
    public function test_happy_path_walks_the_full_cycle_back_to_observe(): void
    {
        $sm = new AtlasAutonomousRuntimeCycleStateMachine;
        $this->assertSame(AtlasAutonomousRuntimeCycleStateMachine::OBSERVE, $sm->state());

        $cycle = [
            AtlasAutonomousRuntimeCycleStateMachine::DECIDE,
            AtlasAutonomousRuntimeCycleStateMachine::ARCHITECT,
            AtlasAutonomousRuntimeCycleStateMachine::PACKETIZE,
            AtlasAutonomousRuntimeCycleStateMachine::SCHEDULE,
            AtlasAutonomousRuntimeCycleStateMachine::EXECUTE,
            AtlasAutonomousRuntimeCycleStateMachine::VERIFY,
            AtlasAutonomousRuntimeCycleStateMachine::MERGE_OR_REJECT,
            AtlasAutonomousRuntimeCycleStateMachine::KNOWLEDGE_SYNC,
            AtlasAutonomousRuntimeCycleStateMachine::LEARN,
            AtlasAutonomousRuntimeCycleStateMachine::REPLAN,
            AtlasAutonomousRuntimeCycleStateMachine::OBSERVE,
        ];
        foreach ($cycle as $next) {
            $verdict = $sm->transitionTo($next);
            $this->assertTrue($verdict['accepted'], "transition to {$next} must be accepted; reason={$verdict['reason']}");
            $this->assertSame($next, $sm->state());
        }
    }

    public function test_invalid_transition_is_rejected_with_facts_only_reason(): void
    {
        $sm = new AtlasAutonomousRuntimeCycleStateMachine;

        $verdict = $sm->transitionTo(AtlasAutonomousRuntimeCycleStateMachine::EXECUTE);
        $this->assertFalse($verdict['accepted']);
        $this->assertStringStartsWith('invalid_transition_expected:', $verdict['reason']);
        $this->assertSame(AtlasAutonomousRuntimeCycleStateMachine::OBSERVE, $sm->state(), 'rejected transition must NOT mutate state');
    }

    public function test_safety_stop_is_enterable_from_any_active_state(): void
    {
        foreach (AtlasAutonomousRuntimeCycleStateMachine::CYCLE as $active) {
            $sm = new AtlasAutonomousRuntimeCycleStateMachine;
            // Walk to the active state.
            $idx = (int) array_search($active, AtlasAutonomousRuntimeCycleStateMachine::CYCLE, true);
            for ($i = 0; $i < $idx; $i++) {
                $sm->transitionTo(AtlasAutonomousRuntimeCycleStateMachine::CYCLE[$i + 1]);
            }
            $this->assertSame($active, $sm->state());

            $verdict = $sm->transitionTo(AtlasAutonomousRuntimeCycleStateMachine::SAFETY_STOP);
            $this->assertTrue($verdict['accepted'], "safety_stop must be enterable from {$active}");
            $this->assertSame(AtlasAutonomousRuntimeCycleStateMachine::SAFETY_STOP, $sm->state());
        }
    }

    public function test_safety_stop_cannot_resume_without_atlas_native_resume_fact(): void
    {
        $sm = new AtlasAutonomousRuntimeCycleStateMachine;
        $sm->transitionTo(AtlasAutonomousRuntimeCycleStateMachine::SAFETY_STOP);

        $verdict = $sm->transitionTo(AtlasAutonomousRuntimeCycleStateMachine::OBSERVE);
        $this->assertFalse($verdict['accepted']);
        $this->assertSame('safety_stop_requires_atlas_native_resume_fact', $verdict['reason']);
        $this->assertSame(AtlasAutonomousRuntimeCycleStateMachine::SAFETY_STOP, $sm->state());
    }

    public function test_safety_stop_resume_only_lands_in_observe(): void
    {
        $sm = new AtlasAutonomousRuntimeCycleStateMachine;
        $sm->transitionTo(AtlasAutonomousRuntimeCycleStateMachine::SAFETY_STOP);

        $bad = $sm->transitionTo(AtlasAutonomousRuntimeCycleStateMachine::DECIDE, ['atlas_native_resume' => true]);
        $this->assertFalse($bad['accepted']);
        $this->assertSame('safety_stop_only_resumes_to_observe', $bad['reason']);

        $good = $sm->transitionTo(AtlasAutonomousRuntimeCycleStateMachine::OBSERVE, ['atlas_native_resume' => true]);
        $this->assertTrue($good['accepted']);
        $this->assertSame(AtlasAutonomousRuntimeCycleStateMachine::OBSERVE, $sm->state());
    }

    public function test_safety_stop_to_safety_stop_is_a_named_rejection(): void
    {
        $sm = new AtlasAutonomousRuntimeCycleStateMachine;
        $sm->transitionTo(AtlasAutonomousRuntimeCycleStateMachine::SAFETY_STOP);
        $verdict = $sm->transitionTo(AtlasAutonomousRuntimeCycleStateMachine::SAFETY_STOP);

        $this->assertFalse($verdict['accepted']);
        $this->assertSame('already_in_safety_stop', $verdict['reason']);
    }
}
