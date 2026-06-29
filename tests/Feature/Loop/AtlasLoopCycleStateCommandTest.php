<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the autonomous-runtime cycle state machine is live at the operator surface: a valid forward transition
 * is accepted (resulting state reported); an out-of-order transition is rejected with the expected-next reason.
 */
final class AtlasLoopCycleStateCommandTest extends TestCase
{
    private function transition(string $from, string $to): array
    {
        $exit = Artisan::call('atlas:loop:cycle-state', ['--from' => $from, '--to' => $to, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_valid_forward_transition_is_accepted(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->transition('observe', 'decide');

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.cycle_state.v1', $d['schema']);
        $this->assertTrue($d['accepted'], (string) json_encode($d));
        $this->assertSame('decide', $d['to']);
        $this->assertSame('decide', $d['resulting_state']);
    }

    public function test_deep_position_then_valid_transition(): void
    {
        // walk observe..execute, then execute -> verify (valid)
        ['d' => $d] = $this->transition('execute', 'verify');

        $this->assertTrue($d['accepted'], (string) json_encode($d));
        $this->assertSame('execute', $d['from']);
        $this->assertSame('verify', $d['resulting_state']);
    }

    public function test_out_of_order_transition_is_rejected(): void
    {
        ['d' => $d] = $this->transition('observe', 'merge_or_reject');

        $this->assertFalse($d['accepted'], (string) json_encode($d));
        $this->assertStringContainsString('invalid_transition_expected:decide', (string) $d['reason']);
        $this->assertSame('observe', $d['resulting_state']); // state unchanged on a rejected transition
    }

    public function test_missing_to_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:cycle-state', ['--from' => 'observe', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
