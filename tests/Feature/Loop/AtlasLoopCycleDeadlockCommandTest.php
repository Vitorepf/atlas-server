<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the cycle deadlock checker is live at the operator surface: a clean FSM is deadlock/livelock-free; an
 * FSM with a non-terminal sink reports a deadlock; a 2-state no-exit loop reports a livelock SCC.
 */
final class AtlasLoopCycleDeadlockCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-deadlock-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function check(array $fsm): array
    {
        file_put_contents($this->input, (string) json_encode($fsm));
        $exit = Artisan::call('atlas:loop:cycle-deadlock-check', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_clean_fsm_has_no_deadlock_or_livelock(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->check([
            'entry_state' => 'ENTRY',
            'terminal_states' => ['T'],
            'states' => ['ENTRY', 'A', 'T'],
            'transitions' => [
                ['from' => 'ENTRY', 'to' => 'A', 'guard' => 'g1'],
                ['from' => 'A', 'to' => 'T', 'guard' => 'g2'],
            ],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.cycle_deadlock_check.v1', $d['schema']);
        $this->assertSame(0, $d['deadlock_count'], (string) json_encode($d));
        $this->assertSame(0, $d['livelock_count']);
        $this->assertContains('T', $d['visited_states']);
    }

    public function test_non_terminal_sink_is_a_deadlock(): void
    {
        ['d' => $d] = $this->check([
            'entry_state' => 'ENTRY',
            'terminal_states' => ['T'],
            'states' => ['ENTRY', 'D', 'T'],
            'transitions' => [
                ['from' => 'ENTRY', 'to' => 'D', 'guard' => 'g1'],
            ],
        ]);

        $this->assertSame(1, $d['deadlock_count'], (string) json_encode($d));
        $this->assertSame('D', $d['deadlocks'][0]['state']);
        $this->assertSame(['ENTRY', 'D'], $d['deadlocks'][0]['witness_trace']);
    }

    public function test_two_state_no_exit_loop_is_a_livelock(): void
    {
        ['d' => $d] = $this->check([
            'entry_state' => 'ENTRY',
            'terminal_states' => ['T'],
            'states' => ['ENTRY', 'X', 'Y', 'T'],
            'transitions' => [
                ['from' => 'ENTRY', 'to' => 'X', 'guard' => 'g1'],
                ['from' => 'X', 'to' => 'Y', 'guard' => 'g2'],
                ['from' => 'Y', 'to' => 'X', 'guard' => 'g3'],
            ],
        ]);

        $this->assertSame(0, $d['deadlock_count']);
        $this->assertSame(1, $d['livelock_count'], (string) json_encode($d));
        $this->assertSame(['X', 'Y'], $d['livelocks'][0]['component']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:cycle-deadlock-check', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
