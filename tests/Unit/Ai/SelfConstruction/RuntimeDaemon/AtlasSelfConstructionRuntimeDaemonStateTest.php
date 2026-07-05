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

    public function test_safety_stop_is_sticky_until_recovery_completed_with_proof(): void
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

        // A bare safety_reset (no proof) is INSUFFICIENT — safety_stop remains sticky.
        $c = $reducer->reduce($a, ['type' => 'safety_reset']);
        $this->assertTrue($c['safety_stop']);
        $this->assertFalse($c['next_tick_allowed']);

        // recovery_completed WITHOUT a proof_ref is also insufficient.
        $d = $reducer->reduce($a, ['type' => 'recovery_completed']);
        $this->assertTrue($d['safety_stop']);
        $this->assertSame('recovery_completed_missing_proof_ref', $d['status_reason']);

        // Only recovery_completed WITH a proof_ref clears it.
        $e = $reducer->reduce($a, ['type' => 'recovery_completed', 'proof_ref' => 'evidence:soak-run-42']);
        $this->assertFalse($e['safety_stop']);
        $this->assertTrue($e['next_tick_allowed']);
        $this->assertContains('evidence:soak-run-42', $e['evidence_refs']);
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

        // No `now_at` supplied — heartbeat_status is missing because we cannot
        // assess freshness without a clock (fail-closed). But a missing clock
        // does not block nextTickAllowed (only STALE does), so the tick is
        // still allowed.
        $this->assertSame('missing', $a['heartbeat_status']);
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

    public function test_idempotent_tick_completed_with_same_receipt_produces_stable_state(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $running = $reducer->reduce($this->initial(), ['type' => 'plan']);
        $running = $reducer->reduce($running, ['type' => 'tick_started', 'now_at' => '2026-06-30T10:00:00+00:00']);

        $first  = $reducer->reduce($running, ['type' => 'tick_completed', 'now_at' => '2026-06-30T10:01:00+00:00', 'receipt_hash' => 'rcpt-xyz']);
        $second = $reducer->reduce($first,   ['type' => 'tick_completed', 'now_at' => '2026-06-30T10:01:00+00:00', 'receipt_hash' => 'rcpt-xyz']);

        $this->assertSame('rcpt-xyz', $second['last_cycle_receipt_hash']);
        $this->assertSame($first['state_hash'], $second['state_hash'], 'same event+now_at must produce identical state_hash');
    }

    public function test_bounded_json_shape_output_has_exactly_known_keys(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $state = $reducer->reduce($this->initial(), ['type' => 'plan']);

        $expected = [
            'schema_version', 'status', 'status_reason', 'last_event', 'safety_stop', 'pause_requested',
            'stop_requested', 'last_heartbeat_at', 'last_cycle_receipt_hash', 'heartbeat_status',
            'heartbeat_max_age_s', 'next_tick_allowed', 'blockers', 'recovery_action', 'evidence_refs',
            'now_at', 'state_hash',
        ];
        $actual = array_keys($state);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, 'state shape must be bounded to the 17 canonical keys');
    }

    // ── AC2: cycle_success / cycle_failure / recovery_started deterministic handling ──

    public function test_cycle_success_records_receipt_and_evidence_ref(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce($this->initial(), ['type' => 'plan']);
        $b = $reducer->reduce($a, ['type' => 'tick_started', 'now_at' => '2026-06-25T05:30:00+00:00']);
        $c = $reducer->reduce($b, ['type' => 'cycle_success', 'now_at' => '2026-06-25T05:31:00+00:00', 'receipt_hash' => 'rcpt-success']);

        $this->assertSame('rcpt-success', $c['last_cycle_receipt_hash']);
        $this->assertContains('rcpt-success', $c['evidence_refs']);
        $this->assertSame('cycle_success', $c['last_event']);
    }

    public function test_cycle_failure_downgrades_to_degraded(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce(['status' => 'running'], ['type' => 'cycle_failure', 'reason' => 'gate_failed']);

        $this->assertSame('degraded', $a['status']);
        $this->assertSame('gate_failed', $a['status_reason']);
        $this->assertContains('cycle_failure', [$a['last_event']]);
    }

    public function test_cycle_failure_does_not_override_safety_stopped(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce($this->initial(), ['type' => 'safety_stop']);
        $b = $reducer->reduce($a, ['type' => 'cycle_failure']);

        $this->assertSame('safety_stopped', $b['status']);
        $this->assertTrue($b['safety_stop']);
    }

    public function test_recovery_started_does_not_clear_safety_stop(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce($this->initial(), ['type' => 'safety_stop']);
        $b = $reducer->reduce($a, ['type' => 'recovery_started', 'reason' => 'operator_investigating']);

        $this->assertTrue($b['safety_stop']);
        $this->assertSame('operator_investigating', $b['status_reason']);
    }

    // ── AC4: current mode, last event, blockers, recovery action, evidence refs ──

    public function test_output_includes_last_event(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $state = $reducer->reduce($this->initial(), ['type' => 'plan']);

        $this->assertSame('plan', $state['last_event']);
    }

    public function test_blockers_empty_when_running_cleanly(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $state = $reducer->reduce($this->initial(), ['type' => 'plan']);

        $this->assertSame([], $state['blockers']);
        $this->assertSame('none', $state['recovery_action']);
    }

    public function test_blockers_and_recovery_action_reflect_safety_stop(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $state = $reducer->reduce($this->initial(), ['type' => 'safety_stop']);

        $this->assertContains('safety_stop_active', $state['blockers']);
        $this->assertSame('await_recovery_completed_with_proof_ref', $state['recovery_action']);
    }

    public function test_blockers_and_recovery_action_reflect_pause(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce($this->initial(), ['type' => 'plan']);
        $state = $reducer->reduce($a, ['type' => 'pause_requested']);

        $this->assertContains('pause_requested', $state['blockers']);
        $this->assertSame('send_resume_event', $state['recovery_action']);
    }

    public function test_evidence_refs_accumulate_and_are_deduplicated(): void
    {
        $reducer = new AtlasSelfConstructionRuntimeDaemonState;
        $a = $reducer->reduce($this->initial(), ['type' => 'plan']);
        $b = $reducer->reduce($a, ['type' => 'tick_started', 'now_at' => '2026-06-25T05:30:00+00:00']);
        $c = $reducer->reduce($b, ['type' => 'cycle_success', 'now_at' => '2026-06-25T05:31:00+00:00', 'receipt_hash' => 'rcpt-1']);
        $d = $reducer->reduce($c, ['type' => 'tick_started', 'now_at' => '2026-06-25T05:32:00+00:00']);
        $e = $reducer->reduce($d, ['type' => 'cycle_success', 'now_at' => '2026-06-25T05:33:00+00:00', 'receipt_hash' => 'rcpt-1']);

        $this->assertSame(['rcpt-1'], $e['evidence_refs'], 'duplicate receipt refs must not be repeated');
    }
}
