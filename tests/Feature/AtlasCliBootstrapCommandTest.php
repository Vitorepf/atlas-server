<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasCliBootstrapCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-bootstrap-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace.'/bin');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_bootstrap_dry_run_outputs_professional_plan_without_writing(): void
    {
        $codex = $this->fakeExecutable('codex');
        $target = $this->workspace.'/local/bin/atlas';
        $envPath = $this->workspace.'/.env';

        $exitCode = Artisan::call('atlas:cli:bootstrap', [
            '--codex-bin' => $codex,
            '--target' => $target,
            '--env-path' => $envPath,
            '--dry-run' => true,
            '--no-scheduler-cron-check' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"dry_run": true', $output);
        $this->assertStringContainsString('"provider_resolution"', $output);
        $this->assertStringContainsString('"launcher_install"', $output);
        $this->assertStringContainsString('"final_doctor"', $output);
        $this->assertStringContainsString('"ATLAS_AI_TOOL_ALLOWED_ROOTS"', $output);
        $this->assertStringContainsString('atlas doctor --strict', $output);
        $this->assertFileDoesNotExist($envPath);
        $this->assertFalse(is_link($target));
        $this->assertFileDoesNotExist($this->workspace.'/CLAUDE.md');
        $this->assertFileDoesNotExist($this->workspace.'/AGENTS.md');
    }

    public function test_bootstrap_can_write_env_install_launcher_and_shell_profile(): void
    {
        $codex = $this->fakeExecutable('codex');
        $target = $this->workspace.'/local/bin/atlas';
        $envPath = $this->workspace.'/.env';
        $profile = $this->workspace.'/.zshrc';

        $exitCode = Artisan::call('atlas:cli:bootstrap', [
            '--codex-bin' => $codex,
            '--target' => $target,
            '--env-path' => $envPath,
            '--shell-profile' => $profile,
            '--write-shell-profile' => true,
            '--no-doctor' => true,
            '--no-scheduler-cron-check' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status": "passed"', $output);
        $this->assertStringContainsString('ATLAS_AI_CODEX_BIN='.(realpath($codex) ?: $codex), File::get($envPath));
        $this->assertStringContainsString('ATLAS_AI_TOOL_ALLOWED_ROOTS=', File::get($envPath));
        $this->assertTrue(is_link($target));
        $this->assertStringContainsString('# >>> atlas-cli >>>', File::get($profile));
    }

    public function test_bootstrap_can_enable_operator_mode_for_project_root(): void
    {
        $codex = $this->fakeExecutable('codex');
        $target = $this->workspace.'/local/bin/atlas';
        $envPath = $this->workspace.'/.env';

        $exitCode = Artisan::call('atlas:cli:bootstrap', [
            '--codex-bin' => $codex,
            '--target' => $target,
            '--env-path' => $envPath,
            '--operator-mode' => true,
            '--operator-root' => $this->workspace,
            '--no-doctor' => true,
            '--no-scheduler-cron-check' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $contents = File::get($envPath);
        $this->assertStringContainsString('ATLAS_AI_TOOL_ALLOWED_ROOTS=', $contents);
        $this->assertStringContainsString($this->workspace, $contents);
        $this->assertStringContainsString('ATLAS_AI_TOOL_PERMISSION_MODE=danger', $contents);
        $this->assertStringContainsString('ATLAS_AI_TOOL_ALLOW_DANGER=true', $contents);
        $this->assertStringContainsString('ATLAS_AI_ALLOW_UNSANDBOXED_WRITE=true', $contents);
    }

    public function test_bootstrap_runs_final_doctor_by_default(): void
    {
        $codex = $this->fakeExecutable('codex');
        $target = $this->workspace.'/local/bin/atlas';

        $exitCode = Artisan::call('atlas:cli:bootstrap', [
            '--codex-bin' => $codex,
            '--target' => $target,
            '--no-write-env' => true,
            '--no-scheduler-cron-check' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"final_doctor"', $output);
        $this->assertStringContainsString('"command": "atlas doctor --strict"', $output);
        $this->assertStringContainsString('"payload"', $output);
        $this->assertStringContainsString('"provider_projection"', $output);
    }

    public function test_bootstrap_provider_projection_write_is_explicit_and_dry_run_safe(): void
    {
        $codex = $this->fakeExecutable('codex');
        $target = $this->workspace.'/local/bin/atlas';

        $exitCode = Artisan::call('atlas:cli:bootstrap', [
            '--codex-bin' => $codex,
            '--target' => $target,
            '--no-write-env' => true,
            '--no-scheduler-cron-check' => true,
            '--provider-projection' => 'write',
            '--provider-projection-target' => 'all',
            '--workspace' => $this->workspace,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"provider_projection"', $output);
        $this->assertStringContainsString('"action": "write"', $output);
        $this->assertStringContainsString('"status": "planned"', $output);
        $this->assertFileDoesNotExist($this->workspace.'/CLAUDE.md');
        $this->assertFileDoesNotExist($this->workspace.'/AGENTS.md');
    }

    public function test_bootstrap_rejects_invalid_provider_projection_action(): void
    {
        $exitCode = Artisan::call('atlas:cli:bootstrap', [
            '--provider-projection' => 'auto',
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Valor invalido para --provider-projection', Artisan::output());
        $this->assertFileDoesNotExist($this->workspace.'/CLAUDE.md');
        $this->assertFileDoesNotExist($this->workspace.'/AGENTS.md');
    }

    public function test_bootstrap_provider_projection_review_shows_diff_without_writing(): void
    {
        $codex = $this->fakeExecutable('codex');
        $target = $this->workspace.'/local/bin/atlas';
        File::put($this->workspace.'/AGENTS.md', "Existing review-only provider rule.\nKeep this note.");

        $exitCode = Artisan::call('atlas:cli:bootstrap', [
            '--codex-bin' => $codex,
            '--target' => $target,
            '--no-write-env' => true,
            '--no-scheduler-cron-check' => true,
            '--provider-projection' => 'review',
            '--provider-projection-target' => 'agents',
            '--workspace' => $this->workspace,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"action": "review"', $output);
        $this->assertStringContainsString('"change_type": "adopt"', $output);
        $this->assertStringContainsString('Existing review-only provider rule.', $output);
        $this->assertSame("Existing review-only provider rule.\nKeep this note.", File::get($this->workspace.'/AGENTS.md'));
        $this->assertFileDoesNotExist($this->workspace.'/CLAUDE.md');
    }

    public function test_bootstrap_provider_projection_apply_requires_confirmation(): void
    {
        $codex = $this->fakeExecutable('codex');
        $target = $this->workspace.'/local/bin/atlas';
        File::put($this->workspace.'/AGENTS.md', "Existing apply provider rule.\nKeep this note.");

        $exitCode = Artisan::call('atlas:cli:bootstrap', [
            '--codex-bin' => $codex,
            '--target' => $target,
            '--no-write-env' => true,
            '--no-doctor' => true,
            '--no-scheduler-cron-check' => true,
            '--provider-projection' => 'apply',
            '--provider-projection-target' => 'agents',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"error": "confirmation_required"', $output);
        $this->assertSame("Existing apply provider rule.\nKeep this note.", File::get($this->workspace.'/AGENTS.md'));
    }

    public function test_bootstrap_provider_projection_apply_confirmed_writes_reviewed_changes(): void
    {
        $codex = $this->fakeExecutable('codex');
        $target = $this->workspace.'/local/bin/atlas';
        File::put($this->workspace.'/AGENTS.md', "Existing confirmed apply rule.\nKeep this note.");

        $exitCode = Artisan::call('atlas:cli:bootstrap', [
            '--codex-bin' => $codex,
            '--target' => $target,
            '--no-write-env' => true,
            '--no-doctor' => true,
            '--no-scheduler-cron-check' => true,
            '--provider-projection' => 'apply',
            '--provider-projection-target' => 'agents',
            '--provider-projection-yes' => true,
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"action": "apply"', $output);
        $this->assertStringContainsString('"confirmed": true', $output);
        $this->assertStringContainsString('atlas_provider_projection_v1', File::get($this->workspace.'/AGENTS.md'));
        $this->assertStringContainsString('Existing confirmed apply rule.', File::get($this->workspace.'/AGENTS.md'));
    }

    public function test_bootstrap_can_write_provider_projections_when_explicit(): void
    {
        $codex = $this->fakeExecutable('codex');
        $target = $this->workspace.'/local/bin/atlas';

        $exitCode = Artisan::call('atlas:cli:bootstrap', [
            '--codex-bin' => $codex,
            '--target' => $target,
            '--no-write-env' => true,
            '--no-doctor' => true,
            '--no-scheduler-cron-check' => true,
            '--provider-projection' => 'write',
            '--provider-projection-target' => 'all',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"action": "write"', $output);
        $this->assertFileExists($this->workspace.'/CLAUDE.md');
        $this->assertFileExists($this->workspace.'/AGENTS.md');
        $this->assertStringContainsString('atlas_provider_projection_v1', File::get($this->workspace.'/CLAUDE.md'));
        $this->assertStringContainsString('atlas_provider_projection_v1', File::get($this->workspace.'/AGENTS.md'));
    }

    public function test_bootstrap_can_adopt_provider_projection_when_explicit(): void
    {
        $codex = $this->fakeExecutable('codex');
        $target = $this->workspace.'/local/bin/atlas';
        File::put($this->workspace.'/AGENTS.md', "Existing local provider rule.\nKeep this note.");

        $exitCode = Artisan::call('atlas:cli:bootstrap', [
            '--codex-bin' => $codex,
            '--target' => $target,
            '--no-write-env' => true,
            '--no-doctor' => true,
            '--no-scheduler-cron-check' => true,
            '--provider-projection' => 'adopt',
            '--provider-projection-target' => 'agents',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"action": "adopt"', $output);
        $this->assertStringContainsString('atlas_provider_projection_v1', File::get($this->workspace.'/AGENTS.md'));
        $this->assertStringContainsString('Existing local provider rule.', File::get($this->workspace.'/AGENTS.md'));
    }

    private function fakeExecutable(string $name): string
    {
        $path = $this->workspace.'/bin/'.$name;
        File::put($path, "#!/usr/bin/env bash\nexit 0\n");
        chmod($path, 0755);

        return $path;
    }
}
