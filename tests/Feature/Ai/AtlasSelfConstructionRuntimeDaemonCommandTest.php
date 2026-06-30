<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimeDaemonCommandTest extends TestCase
{
    private string $factsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas-rd-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    private function writeFacts(array $facts): void
    {
        file_put_contents($this->factsPath, json_encode($facts, JSON_UNESCAPED_SLASHES));
    }

    private function readyFacts(array $overrides = []): array
    {
        return array_replace([
            'daemon_state' => [
                'status' => 'planned',
                'safety_stop' => false,
            ],
            'heartbeat_event' => ['type' => 'heartbeat', 'now_at' => '2026-06-25T05:30:00+00:00'],
            'planned_actions' => [['kind' => 'native_tick']],
        ], $overrides);
    }

    public function test_status_action_is_read_only_and_reports_atlas_native_owners(): void
    {
        $this->writeFacts($this->readyFacts());
        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'status', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertSame('atlas_native', $p['final_runtime_owner']);
        $this->assertSame('atlas_server', $p['steady_state_runtime_owner']);
        $this->assertTrue($p['dry_run']);
        $this->assertSame([], $p['applied_actions']);
    }

    public function test_plan_action_emits_planned_envelope_with_zero_apply(): void
    {
        $this->writeFacts($this->readyFacts());
        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'plan', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertTrue($p['dry_run']);
        $this->assertSame([], $p['applied_actions']);
        $this->assertNotEmpty($p['daemon_cycle_hash']);
    }

    public function test_tick_default_is_dry_run_without_apply_flag(): void
    {
        $touched = false;
        app()->bind('atlas.self_construction.runtime_daemon.action_callbacks', function () use (&$touched) {
            return [
                'native_tick' => function () use (&$touched) { $touched = true; },
            ];
        });
        $this->writeFacts($this->readyFacts());

        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'tick', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertTrue($p['dry_run']);
        $this->assertFalse($touched, 'dry-run tick must not invoke any callback');
    }

    public function test_apply_invokes_only_injected_atlas_native_callback(): void
    {
        $captured = [];
        app()->bind('atlas.self_construction.runtime_daemon.action_callbacks', function () use (&$captured) {
            return [
                'native_tick' => function (array $action, array $state) use (&$captured): array {
                    $captured[] = $action;

                    return ['ok' => true];
                },
            ];
        });
        $this->writeFacts($this->readyFacts());

        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'tick', '--facts' => $this->factsPath, '--apply' => true, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertFalse($p['dry_run']);
        $this->assertCount(1, $captured);
        $this->assertCount(1, $p['applied_actions']);
    }

    public function test_run_once_is_bounded_by_max_cycles(): void
    {
        $calls = 0;
        app()->bind('atlas.self_construction.runtime_daemon.action_callbacks', function () use (&$calls) {
            return [
                'native_tick' => function () use (&$calls): array { $calls++; return ['ok' => true]; },
            ];
        });
        $this->writeFacts($this->readyFacts());

        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'run-once', '--facts' => $this->factsPath, '--apply' => true, '--max-cycles' => 3, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertLessThanOrEqual(3, $p['cycle_count']);
        $this->assertLessThanOrEqual(3, $calls);
    }

    public function test_provider_git_and_external_actions_are_refused_in_apply(): void
    {
        $invoked = false;
        app()->bind('atlas.self_construction.runtime_daemon.action_callbacks', function () use (&$invoked) {
            return [
                'git' => function () use (&$invoked) { $invoked = true; },
                'external_provider_call' => function () use (&$invoked) { $invoked = true; },
                'claude_code' => function () use (&$invoked) { $invoked = true; },
            ];
        });
        $this->writeFacts($this->readyFacts(['planned_actions' => [
            ['kind' => 'git'],
            ['kind' => 'external_provider_call'],
            ['kind' => 'claude_code'],
        ]]));

        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'tick', '--facts' => $this->factsPath, '--apply' => true, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertFalse($invoked);
        $this->assertSame([], $p['applied_actions']);
        $kinds = array_column($p['withheld_actions'], 'kind');
        foreach (['git', 'external_provider_call', 'claude_code'] as $k) {
            $this->assertContains($k, $kinds);
        }
    }

    public function test_pause_and_resume_emit_deterministic_state_events(): void
    {
        $this->writeFacts($this->readyFacts());

        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'pause', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('paused', $p['daemon_status']);
        $this->assertFalse($p['next_tick_allowed']);

        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'resume', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('planned', $p['daemon_status']);
        $this->assertTrue($p['next_tick_allowed']);
    }

    public function test_invalid_facts_path_returns_usage_error(): void
    {
        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'status', '--facts' => '/does/not/exist.json', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('usage_error', $p['status']);
    }

    public function test_no_operator_needed_for_ordinary_forward_progress(): void
    {
        // No operator input — but tick still proceeds (dry-run plan).
        $this->writeFacts($this->readyFacts());
        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'tick', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertTrue($p['dry_run']);
        $this->assertFalse($p['safety_stop']);
    }

    public function test_plan_with_brain_stall_verdict_emits_recovery_in_planned_actions(): void
    {
        $this->writeFacts($this->readyFacts([
            'planned_actions'    => [],
            'unattended_verdict' => [
                'recovery_needed'  => true,
                'critical_blocker' => false,
                'classification'   => 'stale_brain_heartbeat',
                'severity'         => 'medium',
                'reasons'          => ['brain_quota_stall_reason_stale_brain_heartbeat'],
            ],
        ]));

        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'plan', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame('ok', $p['status']);
        $this->assertTrue($p['dry_run']);
        $plannedKinds = array_column($p['planned_actions'], 'kind');
        $this->assertContains('atlas_native_brain_recovery', $plannedKinds, 'plan must surface brain recovery action from verdict');
        $this->assertSame([], $p['applied_actions']);
    }

    public function test_tick_dry_run_with_brain_stall_shows_recovery_in_planned_not_applied(): void
    {
        $this->writeFacts($this->readyFacts([
            'planned_actions'    => [],
            'unattended_verdict' => [
                'recovery_needed'  => true,
                'critical_blocker' => false,
                'classification'   => 'zero_active_brain_commands',
                'severity'         => 'low',
            ],
        ]));

        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'tick', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertTrue($p['dry_run']);
        $this->assertContains('atlas_native_brain_recovery', array_column($p['planned_actions'], 'kind'));
        $this->assertSame([], $p['applied_actions']);
    }

    public function test_tick_apply_with_brain_stall_fires_recovery_callback_and_refuses_git(): void
    {
        $fired = false;
        app()->bind('atlas.self_construction.runtime_daemon.action_callbacks', function () use (&$fired) {
            return [
                'atlas_native_brain_recovery' => function (array $action) use (&$fired): array {
                    $fired = true;

                    return ['recovered' => true, 'class' => $action['verdict_classification']];
                },
                'git' => function (): array { return ['should_never_run' => true]; },
            ];
        });

        $this->writeFacts($this->readyFacts([
            'planned_actions'    => [['kind' => 'git']],
            'unattended_verdict' => [
                'recovery_needed'  => true,
                'critical_blocker' => false,
                'classification'   => 'stale_brain_heartbeat',
                'severity'         => 'medium',
            ],
        ]));

        Artisan::call('atlas:self-construction:runtime-daemon', ['action' => 'tick', '--facts' => $this->factsPath, '--apply' => true, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertTrue($fired, 'atlas_native_brain_recovery callback must fire');
        $this->assertFalse($p['dry_run']);
        $appliedKinds  = array_column($p['applied_actions'], 'kind');
        $withheldKinds = array_column($p['withheld_actions'], 'kind');
        $this->assertContains('atlas_native_brain_recovery', $appliedKinds);
        $this->assertNotContains('git', $appliedKinds);
        $this->assertContains('git', $withheldKinds);
        $this->assertNotEmpty($p['evidence_obligations']);
    }
}
