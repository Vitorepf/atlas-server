<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * S2 (Obra #19) — session-bootstrap carries the minute-1 ops room: master switch,
 * active obra (consumed from the T1 obra-state file), a non-blocking probe of the
 * two main-write locks, and the open-WO count — so a new session answers "what is
 * in flight and what would clobber me" without a grep. Additive + fail-safe.
 */
final class AtlasSessionBootstrapOpsRoomTest extends TestCase
{
    public function test_bootstrap_json_carries_a_failsafe_ops_room(): void
    {
        $code = Artisan::call('atlas:ai:session-bootstrap', [
            '--task' => 'ops room smoke',
            '--json' => true,
        ]);
        $out = Artisan::output();

        // Bootstrap never fails just because the ops room could not read something.
        $this->assertContains($code, [0, 1], 'bootstrap returns a gate exit code, never a crash');

        $payload = json_decode($out, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('ops_room', $payload);

        $room = $payload['ops_room'];
        // The two questions a new session must answer at minute 1 …
        $this->assertArrayHasKey('master_switch', $room);          // is the loop live?
        $this->assertArrayHasKey('locks', $room);                  // what would clobber me?
        $this->assertArrayHasKey('task_commit', $room['locks']);
        $this->assertArrayHasKey('main_merge', $room['locks']);
        // … a probe result is always one of the bounded states (never blocked/crashed).
        $this->assertContains($room['locks']['task_commit'], ['free', 'contended', 'unknown']);
        $this->assertContains($room['locks']['main_merge'], ['free', 'contended', 'unknown']);
        $this->assertArrayHasKey('open_work_orders', $room);
    }
}
