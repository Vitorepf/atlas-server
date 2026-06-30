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

    public function test_brain_recovery_verdict_injects_atlas_native_action_and_fires_with_callback(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $out = $cycle->tick($this->readyFacts([
            'planned_actions' => [],
            'unattended_verdict' => [
                'recovery_needed'  => true,
                'critical_blocker' => false,
                'classification'   => 'stale_brain_heartbeat',
                'severity'         => 'medium',
                'reasons'          => ['brain_quota_stall_reason_stale_brain_heartbeat'],
            ],
        ]), [
            'apply' => true,
            'action_callbacks' => [
                'atlas_native_brain_recovery' => static fn (array $a): array => [
                    'recovered' => true,
                    'class'     => $a['verdict_classification'],
                ],
            ],
        ]);

        $this->assertCount(1, $out['applied_actions']);
        $this->assertSame('atlas_native_brain_recovery', $out['applied_actions'][0]['kind']);
        $this->assertSame('stale_brain_heartbeat', $out['applied_actions'][0]['result']['class']);
        $this->assertSame([], $out['withheld_actions']);
    }

    public function test_brain_recovery_verdict_does_not_unblock_refused_action_kinds(): void
    {
        $cycle = new AtlasSelfConstructionRuntimeDaemonCycle;
        $out = $cycle->tick($this->readyFacts([
            'planned_actions'    => [['kind' => 'git']],
            'unattended_verdict' => [
                'recovery_needed'  => true,
                'critical_blocker' => false,
                'classification'   => 'stale_brain_heartbeat',
            ],
        ]), [
            'apply' => true,
            'action_callbacks' => [
                'git'                         => static fn () => ['ok' => true],
                'atlas_native_brain_recovery'  => static fn () => ['ok' => true],
            ],
        ]);

        $appliedKinds  = array_column($out['applied_actions'], 'kind');
        $withheldKinds = array_column($out['withheld_actions'], 'kind');

        $this->assertNotContains('git', $appliedKinds);
        $this->assertContains('git', $withheldKinds);
        $this->assertContains('atlas_native_brain_recovery', $appliedKinds);
    }

    // --- action_feedback tests ---

    public function test_output_has_action_feedback_key(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick($this->readyFacts());

        $this->assertArrayHasKey('action_feedback', $out);
        $this->assertIsArray($out['action_feedback']);
    }

    public function test_dry_run_action_feedback_has_withheld_retryable_entry(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick($this->readyFacts());

        $fb = $out['action_feedback'];
        $this->assertCount(1, $fb);
        $this->assertSame('native_tick', $fb[0]['kind']);
        $this->assertSame('withheld',    $fb[0]['outcome_class']);
        $this->assertTrue($fb[0]['retryable']);
        $this->assertStringContainsString('native_tick', $fb[0]['next_safe_action']);
        $this->assertIsArray($fb[0]['receipt_refs']);
    }

    public function test_applied_action_feedback_has_applied_entry_not_retryable(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick(
            $this->readyFacts(),
            ['apply' => true, 'action_callbacks' => ['native_tick' => static fn () => ['ok' => true]]],
        );

        $fb = array_column($out['action_feedback'], null, 'outcome_class');
        $this->assertArrayHasKey('applied', $fb);
        $this->assertSame('native_tick', $fb['applied']['kind']);
        $this->assertFalse($fb['applied']['retryable']);
        $this->assertStringContainsString('verify_applied_outcome', $fb['applied']['next_safe_action']);
    }

    public function test_refused_action_kind_feedback_is_withheld_not_retryable(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick(
            $this->readyFacts(['planned_actions' => [['kind' => 'git']]]),
            ['apply' => true, 'action_callbacks' => ['git' => static fn () => []]],
        );

        $fb = $out['action_feedback'];
        $this->assertCount(1, $fb);
        $this->assertSame('git', $fb[0]['kind']);
        $this->assertSame('withheld', $fb[0]['outcome_class']);
        $this->assertFalse($fb[0]['retryable']);
        $this->assertStringContainsString('permanently_refused', $fb[0]['next_safe_action']);
    }

    public function test_blocked_action_feedback_is_retryable(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick(
            $this->readyFacts(),
            [
                'apply' => true,
                'action_callbacks' => [
                    'native_tick' => static function (): never {
                        throw new \RuntimeException('simulated callback failure');
                    },
                ],
            ],
        );

        $fb = array_column($out['action_feedback'], null, 'outcome_class');
        $this->assertArrayHasKey('blocked', $fb);
        $this->assertSame('native_tick', $fb['blocked']['kind']);
        $this->assertTrue($fb['blocked']['retryable']);
        $this->assertStringContainsString('inspect_callback_error', $fb['blocked']['next_safe_action']);
    }

    public function test_feedback_entry_has_all_required_keys(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick($this->readyFacts());

        foreach ($out['action_feedback'] as $entry) {
            foreach (['kind', 'outcome_class', 'retryable', 'next_safe_action', 'receipt_refs'] as $key) {
                $this->assertArrayHasKey($key, $entry);
            }
            $this->assertIsBool($entry['retryable']);
            $this->assertIsArray($entry['receipt_refs']);
        }
    }

    public function test_no_callback_supplied_yields_withheld_not_retryable(): void
    {
        $out = (new AtlasSelfConstructionRuntimeDaemonCycle)->tick(
            $this->readyFacts(),
            ['apply' => true],  // no callbacks
        );

        $fb = $out['action_feedback'];
        $this->assertCount(1, $fb);
        $this->assertSame('withheld', $fb[0]['outcome_class']);
        $this->assertFalse($fb[0]['retryable']);
        $this->assertStringContainsString('supply_callback', $fb[0]['next_safe_action']);
    }
}
