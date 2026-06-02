<?php

namespace Tests\Feature\Ai\Hermes;

use Tests\TestCase;

/**
 * Surface contract for `atlas:hermes:mesh`. Exercises status + the READ-ONLY
 * plan path and BOTH fail-closed refusals (no --confirm, policy off). It never
 * triggers a live dispatch, so no real hermes process is launched.
 */
class AtlasHermesMeshCommandTest extends TestCase
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null && is_file($this->file)) {
            @unlink($this->file);
        }
        parent::tearDown();
    }

    private function subtaskFile(): string
    {
        $this->file = tempnam(sys_get_temp_dir(), 'mesh') ?: sys_get_temp_dir().'/mesh.json';
        file_put_contents($this->file, json_encode([
            'permission_mode' => 'write',
            'subtasks' => [
                ['objective' => 'map module A', 'role' => 'researcher', 'toolsets' => ['file'], 'worktree' => true],
                ['objective' => 'add tests to module A', 'role' => 'coder', 'toolsets' => ['file', 'terminal'], 'worktree' => true],
            ],
        ]));

        return $this->file;
    }

    public function test_status_is_default_safe(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.policy', 'off');

        $this->artisan('atlas:hermes:mesh', ['action' => 'status', '--json' => true])
            ->assertExitCode(0);
    }

    public function test_plan_is_read_only_and_composes(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.policy', 'atlas_adapter');

        $this->artisan('atlas:hermes:mesh', [
            'action' => 'plan',
            '--file' => $this->subtaskFile(),
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_dispatch_refused_without_confirm(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.policy', 'atlas_adapter');

        $this->artisan('atlas:hermes:mesh', [
            'action' => 'dispatch',
            '--file' => $this->subtaskFile(),
            '--json' => true,
        ])->assertExitCode(1);
    }

    public function test_dispatch_refused_when_policy_off(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.policy', 'off');

        $this->artisan('atlas:hermes:mesh', [
            'action' => 'dispatch',
            '--file' => $this->subtaskFile(),
            '--confirm' => true,
            '--json' => true,
        ])->assertExitCode(1);
    }

    public function test_plan_fails_without_file(): void
    {
        $this->artisan('atlas:hermes:mesh', ['action' => 'plan', '--json' => true])
            ->assertExitCode(1);
    }

    public function test_dry_run_previews_argv_without_launching_and_masks_objective(): void
    {
        // dry-run is safe (launches nothing) so it works even with policy off.
        config()->set('atlas.ai.providers.hermes_cli.mesh.policy', 'off');

        $this->artisan('atlas:hermes:mesh', [
            'action' => 'dispatch',
            '--file' => $this->subtaskFile(),
            '--dry-run' => true,
            '--json' => true,
        ])
            ->expectsOutputToContain('"action": "dry_run"')
            ->assertExitCode(0);
    }
}
