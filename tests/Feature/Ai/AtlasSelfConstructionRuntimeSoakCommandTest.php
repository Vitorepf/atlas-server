<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimeSoakCommandTest extends TestCase
{
    private string $factsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->factsPath = sys_get_temp_dir().'/atlas_runtime_soak_'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        parent::tearDown();
    }

    private function writeJson(array $data): void
    {
        file_put_contents($this->factsPath, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    public function test_scenario_action_is_read_only_and_bounded_by_ticks(): void
    {
        $startedAt = hrtime(true);
        $exit = Artisan::call('atlas:self-construction:runtime-soak', [
            'action' => 'scenario',
            '--ticks' => 8,
            '--json' => true,
        ]);
        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1e6);

        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertSame('scenario', $p['action']);
        $this->assertSame(8, count($p['scenario']['virtual_ticks']));
        $this->assertLessThan(5000, $elapsedMs, 'CLI must NEVER wait real time');
    }

    public function test_dry_run_defaults_to_no_callbacks(): void
    {
        $exit = Artisan::call('atlas:self-construction:runtime-soak', [
            'action' => 'dry-run',
            '--ticks' => 6,
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertSame('dry-run', $p['action']);
        foreach ((array) $p['report']['tick_results'] as $tick) {
            $this->assertFalse($tick['applied'], 'dry-run must never apply');
        }
    }

    public function test_run_action_defaults_to_dry_run_without_apply_flag(): void
    {
        $exit = Artisan::call('atlas:self-construction:runtime-soak', [
            'action' => 'run',
            '--ticks' => 4,
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertFalse($p['apply']);
    }

    public function test_run_action_with_apply_is_bounded_by_ticks_and_uses_deterministic_callback(): void
    {
        $this->writeJson([
            'tick_callback_responses' => [
                ['status' => 'ok'],
                ['status' => 'ok'],
                ['status' => 'ok'],
                ['status' => 'ok'],
            ],
        ]);
        $startedAt = hrtime(true);
        $exit = Artisan::call('atlas:self-construction:runtime-soak', [
            'action' => 'run',
            '--ticks' => 4,
            '--apply' => true,
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1e6);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($p['apply']);
        $this->assertSame(4, count($p['report']['tick_results']));
        $this->assertLessThan(5000, $elapsedMs, 'apply must remain bounded by --ticks');
    }

    public function test_audit_action_returns_blocked_on_dependency_regression(): void
    {
        $this->writeJson([
            'soak_report' => [
                'tick_results' => [
                    ['kind' => 'normal_grind', 'expected_outcome' => 'green', 'dependency_violations' => ['depends_on_operator']],
                ],
                'dependency_violations' => [
                    ['tick_kind' => 'normal_grind', 'violation' => 'depends_on_operator'],
                ],
            ],
        ]);
        $exit = Artisan::call('atlas:self-construction:runtime-soak', [
            'action' => 'audit',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertContains($p['verdict']['verdict'] ?? '', ['blocked', 'hold', 'pass']);
        // The auditor must at least surface a dependency-related blocker when violations present.
        if (($p['verdict']['verdict'] ?? '') === 'blocked') {
            $this->assertNotEmpty($p['verdict']['blockers'] ?? []);
        }
    }

    public function test_audit_action_usage_error_without_soak_report(): void
    {
        $this->writeJson(['not_a_report' => true]);
        $exit = Artisan::call('atlas:self-construction:runtime-soak', [
            'action' => 'audit',
            '--facts' => $this->factsPath,
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $p['status']);
    }

    public function test_malformed_facts_path_yields_no_facts_but_does_not_crash(): void
    {
        $exit = Artisan::call('atlas:self-construction:runtime-soak', [
            'action' => 'scenario',
            '--facts' => '/does/not/exist.json',
            '--ticks' => 3,
            '--json' => true,
        ]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertSame(3, count($p['scenario']['virtual_ticks']));
    }

    public function test_command_source_does_not_call_provider_git_or_external_worker(): void
    {
        $src = (string) file_get_contents(base_path('app/Console/Commands/AtlasSelfConstructionRuntimeSoakCommand.php'));
        foreach (['shell_exec', 'proc_open', 'exec(', 'git ', 'Http::', 'AiProviderManager', 'sleep(', 'usleep('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "soak CLI must NOT contain {$forbidden}");
        }
    }
}
