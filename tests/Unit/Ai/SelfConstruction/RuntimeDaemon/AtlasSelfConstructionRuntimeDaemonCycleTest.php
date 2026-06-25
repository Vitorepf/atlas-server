<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemonCycle;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimeDaemonCycleTest extends TestCase
{
    private function readyState(): array
    {
        return [
            'status' => 'planned',
            'safety_stop' => false,
            'pause_requested' => false,
            'stop_requested' => false,
        ];
    }

    private function readyFacts(array $overrides = []): array
    {
        return array_replace([
            'daemon_state' => $this->readyState(),
            'heartbeat_event' => ['type' => 'heartbeat', 'now_at' => '2026-06-25T05:30:00+00:00'],
            'planned_actions' => [['kind' => 'native_tick']],
        ], $overrides);
    }

    public function test_dry_run_does_not_invoke_callback_and_emits_planned_envelope(): void
    {
        $called = 0;
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $out = $cycle->tick($this->readyFacts(), [
            'action_callbacks' => ['native_tick' => function () use (&$called) { $called++; }],
        ]);

        $this->assertSame(AtlasSelfConstructionRuntimeDaemonCycle::SCHEMA, $out['schema_version']);
        $this->assertTrue($out['dry_run']);
        $this->assertSame(0, $called);
        $this->assertSame([], $out['applied_actions']);
        $this->assertNotEmpty($out['daemon_cycle_hash']);
    }

    public function test_apply_mode_runs_injected_callback_and_isolates_failures(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $out = $cycle->tick($this->readyFacts(['planned_actions' => [
            ['kind' => 'native_tick'],
            ['kind' => 'native_audit'],
        ]]), [
            'apply' => true,
            'action_callbacks' => [
                'native_tick' => static fn (array $a, array $s): array => ['ok' => true],
                'native_audit' => static function (): array { throw new \RuntimeException('audit boom'); },
            ],
        ]);

        $this->assertCount(1, $out['applied_actions']);
        $this->assertSame('native_tick', $out['applied_actions'][0]['kind']);
        $this->assertCount(1, $out['blocked_actions']);
        $this->assertSame('audit boom', $out['blocked_actions'][0]['error']);
    }

    public function test_refused_action_kinds_never_fire_even_with_callback(): void
    {
        $touched = false;
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $out = $cycle->tick($this->readyFacts(['planned_actions' => [
            ['kind' => 'git'],
            ['kind' => 'operator_action'],
            ['kind' => 'human_action'],
            ['kind' => 'external_provider_call'],
            ['kind' => 'claude_code'],
            ['kind' => 'codex'],
            ['kind' => 'cursor'],
            ['kind' => 'network'],
            ['kind' => 'unrestricted_shell'],
        ]]), [
            'apply' => true,
            'action_callbacks' => [
                'git' => function () use (&$touched) { $touched = true; },
                'operator_action' => function () use (&$touched) { $touched = true; },
            ],
        ]);

        $this->assertFalse($touched);
        $this->assertSame([], $out['applied_actions']);
        $kinds = array_column($out['withheld_actions'], 'kind');
        foreach (AtlasSelfConstructionRuntimeDaemonCycle::REFUSED_ACTION_KINDS as $r) {
            $this->assertContains($r, $kinds, "{$r} must be refused");
        }
    }

    public function test_pause_blocks_apply_and_records_cycle_blocked_reason(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $facts = $this->readyFacts();
        $facts['daemon_state']['pause_requested'] = true;
        $facts['daemon_state']['status'] = 'paused';

        $out = $cycle->tick($facts, [
            'apply' => true,
            'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]],
        ]);

        $this->assertSame([], $out['applied_actions']);
        $this->assertContains('paused', [$out['daemon_status']]);
        $this->assertNotEmpty($out['cycle_blocked_reasons']);
    }

    public function test_safety_stop_blocks_apply(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $facts = $this->readyFacts();
        $facts['daemon_state']['safety_stop'] = true;
        $facts['daemon_state']['status'] = 'safety_stopped';

        $out = $cycle->tick($facts, [
            'apply' => true,
            'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]],
        ]);

        $this->assertSame('safety_stopped', $out['daemon_status']);
        $this->assertSame([], $out['applied_actions']);
        $this->assertContains('cycle_blocked:daemon_state_blocks_tick:safety_stopped', array_column($out['withheld_actions'], 'reason'));
    }

    public function test_stale_heartbeat_blocks_apply(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $facts = $this->readyFacts(['heartbeat_event' => ['type' => 'staleness_check', 'now_at' => '2026-06-25T06:00:00+00:00']]);
        // last_heartbeat_at far in the past — heartbeat will be stale (>3min default).
        $facts['daemon_state']['last_heartbeat_at'] = '2026-06-25T05:00:00+00:00';
        $facts['daemon_state']['status'] = 'running';

        $out = $cycle->tick($facts, [
            'apply' => true,
            'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]],
        ]);

        $this->assertSame([], $out['applied_actions']);
        $this->assertSame('degraded', $out['daemon_status']);
        $this->assertNotEmpty($out['cycle_blocked_reasons']);
    }

    public function test_unattended_critical_blocker_stops_apply(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $out = $cycle->tick($this->readyFacts(['unattended_verdict' => ['critical_blocker' => true]]), [
            'apply' => true,
            'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]],
        ]);

        $this->assertSame([], $out['applied_actions']);
        $this->assertContains('unattended_supervisor_critical_blocker', $out['cycle_blocked_reasons']);
    }
}
