<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\ModelCheck\AtlasLoopCycleStateMachineExtractor;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the cycle state-machine extractor is live at the operator surface: the command emits the canonical
 * Loop FSM (states + transitions) deterministically, with ENTRY as the entry state and a stable fingerprint.
 */
final class AtlasLoopCycleStateMachineCommandTest extends TestCase
{
    public function test_emits_deterministic_cycle_state_machine(): void
    {
        $exit = Artisan::call('atlas:loop:cycle-state-machine', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopCycleStateMachineExtractor::SCHEMA, $decoded['schema']);
        $this->assertSame(AtlasLoopCycleStateMachineExtractor::STATE_ENTRY, $decoded['entry_state']);
        $this->assertContains(AtlasLoopCycleStateMachineExtractor::STATE_TERMINAL, $decoded['terminal_states']);
        $this->assertCount(12, $decoded['states']);
        $this->assertCount(14, $decoded['transitions']);

        // every transition names its from/to/guard
        foreach ($decoded['transitions'] as $t) {
            $this->assertArrayHasKey('from', $t);
            $this->assertArrayHasKey('to', $t);
            $this->assertNotSame('', (string) $t['guard']);
        }

        $this->assertStringStartsWith('fsm_', (string) $decoded['fingerprint']);
    }

    public function test_fingerprint_is_stable_across_runs(): void
    {
        Artisan::call('atlas:loop:cycle-state-machine', ['--json' => true]);
        $a = json_decode(trim(Artisan::output()), true);
        Artisan::call('atlas:loop:cycle-state-machine', ['--json' => true]);
        $b = json_decode(trim(Artisan::output()), true);

        $this->assertSame($a['fingerprint'], $b['fingerprint']);
    }
}
