<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemonState;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimeDaemonStateTest extends TestCase
{
    private function initial(): array
    {
        return [
            'status' => AtlasSelfConstructionRuntimeDaemonState::STATUS_STOPPED,
        ];
    }

    public function test_plan_transitions_to_planned_and_next_tick_allowed(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $next = $reducer->reduce($this->initial(), ['type' => 'plan']);

        $this->assertSame('planned', $next['status']);
        $this->assertTrue($next['next_tick_allowed']);
        $this->assertSame('missing', $next['heartbeat_status']);
        $this->assertSame(AtlasSelfConstructionRuntimeDaemonState::SCHEMA, $next['schema_version']);
    }

    public function test_tick_started_records_heartbeat_and_marks_running(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce($this->initial(), ['type' => 'plan']);
        $b = $reducer->reduce($a, ['type' => 'tick_started', 'now_at' => '2026-06-25T05:30:00+00:00']);

        $this->assertSame('running', $b['status']);
        $this->assertSame('2026-06-25T05:30:00+00:00', $b['last_heartbeat_at']);
        $this->assertSame('fresh', $b['heartbeat_status']);
    }

    public function test_tick_completed_records_receipt_hash(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce($this->initial(), ['type' => 'plan']);
        $b = $reducer->reduce($a, ['type' => 'tick_started', 'now_at' => '2026-06-25T05:30:00+00:00']);
        $c = $reducer->reduce($b, ['type' => 'tick_completed', 'now_at' => '2026-06-25T05:31:00+00:00', 'receipt_hash' => 'rcpt-abc']);

        $this->assertSame('rcpt-abc', $c['last_cycle_receipt_hash']);
        $this->assertSame('2026-06-25T05:31:00+00:00', $c['last_heartbeat_at']);
    }

    public function test_pause_request_blocks_next_tick(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce($this->initial(), ['type' => 'plan']);
        $b = $reducer->reduce($a, ['type' => 'pause_requested']);

        $this->assertSame('paused', $b['status']);
        $this->assertTrue($b['pause_requested']);
        $this->assertFalse($b['next_tick_allowed']);
    }

    public function test_resume_clears_pause_and_replans(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce($this->initial(), ['type' => 'plan']);
        $b = $reducer->reduce($a, ['type' => 'pause_requested']);
        $c = $reducer->reduce($b, ['type' => 'resume']);

        $this->assertFalse($c['pause_requested']);
        $this->assertSame('planned', $c['status']);
        $this->assertTrue($c['next_tick_allowed']);
    }

    public function test_stop_request_blocks_next_tick_and_marks_stopped(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $next = $reducer->reduce(['status' => 'running'], ['type' => 'stop_requested', 'reason' => 'operator_shutdown']);

        $this->assertSame('stopped', $next['status']);
        $this->assertTrue($next['stop_requested']);
        $this->assertSame('operator_shutdown', $next['status_reason']);
        $this->assertFalse($next['next_tick_allowed']);
    }

    public function test_safety_stop_is_sticky_until_safety_reset(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce($this->initial(), ['type' => 'safety_stop', 'reason' => 'master_switch_off']);

        $this->assertSame('safety_stopped', $a['status']);
        $this->assertTrue($a['safety_stop']);
        $this->assertFalse($a['next_tick_allowed']);

        // A plan event does NOT clear safety_stop.
        $b = $reducer->reduce($a, ['type' => 'plan']);
        $this->assertTrue($b['safety_stop']);
        $this->assertFalse($b['next_tick_allowed']);

        $c = $reducer->reduce($a, ['type' => 'safety_reset']);
        $this->assertFalse($c['safety_stop']);
        $this->assertTrue($c['next_tick_allowed']);
    }

    public function test_heartbeat_stale_downgrades_running_to_degraded(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce(['status' => 'running'], ['type' => 'tick_started', 'now_at' => '2026-06-25T05:00:00+00:00']);
        // Check status 10 minutes later WITHOUT a fresh heartbeat (e.g. supervisor sweep).
        $b = $reducer->reduce($a, ['type' => 'staleness_check', 'now_at' => '2026-06-25T05:10:00+00:00']);

        $this->assertSame('degraded', $b['status']);
        $this->assertSame('stale', $b['heartbeat_status']);
        $this->assertTrue($b['next_tick_allowed'] === false);
    }

    public function test_no_wall_clock_used_when_now_at_absent(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce(['status' => 'planned', 'last_heartbeat_at' => '2020-01-01T00:00:00+00:00'], ['type' => 'heartbeat']);

        // No `now_at` supplied — heartbeat_status stays fresh because we can't measure age without a clock.
        $this->assertSame('fresh', $a['heartbeat_status']);
        $this->assertTrue($a['next_tick_allowed']);
    }

    public function test_state_hash_is_deterministic_for_same_state(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce($this->initial(), ['type' => 'plan']);
        $b = $reducer->reduce($this->initial(), ['type' => 'plan']);

        $this->assertSame($a['state_hash'], $b['state_hash']);
    }

    public function test_pause_and_stop_are_not_a_human_dependency_for_tick_eligibility(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce($this->initial(), ['type' => 'plan']);
        // No operator/human input has been recorded — next_tick still allowed.
        $this->assertTrue($a['next_tick_allowed']);
    }
}
