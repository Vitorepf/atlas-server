<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCycleGitContract;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\ModelCheck\AtlasLoopCycleStateMachineExtractor;
use Tests\TestCase;

class AtlasLoopCycleStateMachineExtractorTest extends TestCase
{
    public function test_fsm_states_include_8_canonical_phases_plus_entry_error_terminal(): void
    {
        $fsm = (new AtlasLoopCycleStateMachineExtractor)->extract();

        foreach ([
            AtlasLoopCycleStateMachineExtractor::STATE_ENTRY,
            AtlasLoopCycleStateMachineExtractor::STATE_ORIENT,
            AtlasLoopCycleStateMachineExtractor::STATE_COMPREHEND,
            AtlasLoopCycleStateMachineExtractor::STATE_DECIDE_LEVERAGE,
            AtlasLoopCycleStateMachineExtractor::STATE_ARCHITECT,
            AtlasLoopCycleStateMachineExtractor::STATE_DECOMPOSE,
            AtlasLoopCycleStateMachineExtractor::STATE_IMPLEMENT,
            AtlasLoopCycleStateMachineExtractor::STATE_CERTIFY,
            AtlasLoopCycleStateMachineExtractor::STATE_CLOSE_ON_MAIN,
            AtlasLoopCycleStateMachineExtractor::STATE_LEARN,
            AtlasLoopCycleStateMachineExtractor::STATE_ERROR,
            AtlasLoopCycleStateMachineExtractor::STATE_TERMINAL,
        ] as $state) {
            self::assertContains($state, $fsm['states']);
        }
    }

    public function test_two_extractions_are_byte_identical(): void
    {
        $a = (new AtlasLoopCycleStateMachineExtractor)->extract();
        $b = (new AtlasLoopCycleStateMachineExtractor)->extract();

        self::assertSame(json_encode($a), json_encode($b));
        self::assertSame(hash('sha256', (string) json_encode($a)), hash('sha256', (string) json_encode($b)));
    }

    public function test_every_transition_guard_anchors_on_a_real_resolvable_symbol(): void
    {
        $fsm = (new AtlasLoopCycleStateMachineExtractor)->extract();
        $hits = 0;
        foreach ($fsm['transitions'] as $t) {
            $guard = (string) $t['guard'];
            if (! str_contains($guard, '::')) {
                continue;
            }
            [$fqcn] = explode('::', $guard, 2);
            self::assertTrue(class_exists($fqcn), "guard references unknown class: {$fqcn}");
            $hits++;
        }
        self::assertGreaterThan(0, $hits, 'at least one transition must anchor on a class::symbol guard');
    }

    public function test_master_switch_gate_is_present_in_fsm(): void
    {
        $fsm = (new AtlasLoopCycleStateMachineExtractor)->extract();

        self::assertSame(AtlasLoopMasterSwitch::class.'::KEY', $fsm['master_switch_gate']);
        $hasOffEdge = false;
        foreach ($fsm['transitions'] as $t) {
            if ((string) $t['from'] === AtlasLoopCycleStateMachineExtractor::STATE_ENTRY
                && (string) $t['to'] === AtlasLoopCycleStateMachineExtractor::STATE_TERMINAL
                && str_contains((string) $t['guard'], 'AtlasLoopMasterSwitch')) {
                $hasOffEdge = true;
            }
        }
        self::assertTrue($hasOffEdge, 'master switch OFF must have an explicit ENTRY → TERMINAL edge');
    }

    public function test_extractor_never_calls_cycle_service_or_mutates_git(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/ModelCheck/AtlasLoopCycleStateMachineExtractor.php'));
        foreach (['->run(', '->startCycle(', '->mergeToMain(', 'exec(', 'shell_exec', 'proc_open'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "extractor must not invoke {$forbidden}");
        }
        // Sanity: the GitContract class is referenced as a symbol only, not invoked.
        self::assertTrue(class_exists(AtlasLoopCycleGitContract::class));
    }
}
