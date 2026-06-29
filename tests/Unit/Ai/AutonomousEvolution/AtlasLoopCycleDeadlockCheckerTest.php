<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\ModelCheck\AtlasLoopCycleDeadlockChecker;
use App\Services\Ai\AutonomousEvolution\ModelCheck\AtlasLoopCycleStateMachineExtractor;
use Tests\TestCase;

class AtlasLoopCycleDeadlockCheckerTest extends TestCase
{
    private string $verdictPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->verdictPath = sys_get_temp_dir().'/atlas-deadlock-verdict-'.bin2hex(random_bytes(4)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->verdictPath);
        parent::tearDown();
    }

    public function test_synthetic_deadlock_state_is_detected_with_witness_trace(): void
    {
        $fsm = [
            'entry_state' => 'A',
            'terminal_states' => ['T'],
            'states' => ['A', 'B', 'DEAD', 'T'],
            'transitions' => [
                ['from' => 'A', 'to' => 'B', 'guard' => 'g1'],
                ['from' => 'B', 'to' => 'DEAD', 'guard' => 'g2'],
                // DEAD has no outgoing edges and is NOT terminal.
            ],
        ];
        $verdict = (new AtlasLoopCycleDeadlockChecker)->check($fsm);

        self::assertNotEmpty($verdict['deadlocks']);
        $deadStates = array_column($verdict['deadlocks'], 'state');
        self::assertContains('DEAD', $deadStates);
        $dead = $verdict['deadlocks'][array_search('DEAD', $deadStates, true)];
        self::assertSame(['A', 'B', 'DEAD'], $dead['witness_trace']);
    }

    public function test_synthetic_livelock_scc_with_no_exit_is_detected(): void
    {
        $fsm = [
            'entry_state' => 'A',
            'terminal_states' => ['T'],
            'states' => ['A', 'X', 'Y'],
            'transitions' => [
                ['from' => 'A', 'to' => 'X', 'guard' => 'g1'],
                ['from' => 'X', 'to' => 'Y', 'guard' => 'g2'],
                ['from' => 'Y', 'to' => 'X', 'guard' => 'g3'],
                // X<->Y is an SCC with no exit.
            ],
        ];
        $verdict = (new AtlasLoopCycleDeadlockChecker)->check($fsm);

        self::assertCount(1, $verdict['livelocks']);
        self::assertSame(['X', 'Y'], $verdict['livelocks'][0]['component']);
    }

    public function test_real_atlas_loop_fsm_runs_quickly_and_writes_deterministic_verdict(): void
    {
        $fsm = (new AtlasLoopCycleStateMachineExtractor)->extract();
        $start = microtime(true);
        $a = (new AtlasLoopCycleDeadlockChecker)->checkAndWrite($fsm, $this->verdictPath);
        $bytesA = (string) file_get_contents($this->verdictPath);

        @unlink($this->verdictPath);
        $b = (new AtlasLoopCycleDeadlockChecker)->checkAndWrite($fsm, $this->verdictPath);
        $bytesB = (string) file_get_contents($this->verdictPath);

        $elapsed = microtime(true) - $start;
        self::assertLessThan(5.0, $elapsed, 'checker must complete in under 5s');
        self::assertSame($bytesA, $bytesB, 'verdict file must be byte-identical across runs');
        self::assertTrue($a['is_fact']);
        self::assertTrue($b['is_fact']);
    }

    public function test_scc_containing_terminal_state_is_not_flagged_as_livelock(): void
    {
        $fsm = [
            'entry_state' => 'A',
            'terminal_states' => ['T'],
            'states' => ['A', 'B', 'T'],
            'transitions' => [
                ['from' => 'A', 'to' => 'B', 'guard' => 'g1'],
                ['from' => 'B', 'to' => 'T', 'guard' => 'g2'],
                ['from' => 'T', 'to' => 'B', 'guard' => 'g3'],
                // B<->T is an SCC but T is terminal (haltable) — NOT a livelock.
            ],
        ];
        $verdict = (new AtlasLoopCycleDeadlockChecker)->check($fsm);

        self::assertEmpty($verdict['livelocks'], 'SCC containing a terminal state must not be flagged as livelock');
    }

    public function test_self_looping_singleton_is_detected_as_livelock(): void
    {
        $fsm = [
            'entry_state' => 'A',
            'terminal_states' => ['T'],
            'states' => ['A', 'TRAP'],
            'transitions' => [
                ['from' => 'A', 'to' => 'TRAP', 'guard' => 'g1'],
                ['from' => 'TRAP', 'to' => 'TRAP', 'guard' => 'g2'],
                // TRAP->TRAP is a self-loop with no exit — a real livelock.
            ],
        ];
        $verdict = (new AtlasLoopCycleDeadlockChecker)->check($fsm);

        self::assertCount(1, $verdict['livelocks']);
        self::assertSame(['TRAP'], $verdict['livelocks'][0]['component']);
    }

    public function test_verdict_carries_no_scalar_quality_keys_anywhere(): void
    {
        $fsm = (new AtlasLoopCycleStateMachineExtractor)->extract();
        $verdict = (new AtlasLoopCycleDeadlockChecker)->check($fsm);
        $blob = (string) json_encode($verdict);

        foreach (['"score"', '"quality"', '"rating"', '"grade"'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $blob, "verdict must not contain {$forbidden}");
        }
    }
}
