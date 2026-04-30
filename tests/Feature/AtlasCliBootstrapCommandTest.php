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
        $this->assertStringContainsString('atlas doctor --strict', $output);
        $this->assertFileDoesNotExist($envPath);
        $this->assertFalse(is_link($target));
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
        $this->assertTrue(is_link($target));
        $this->assertStringContainsString('# >>> atlas-cli >>>', File::get($profile));
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
    }

    private function fakeExecutable(string $name): string
    {
        $path = $this->workspace.'/bin/'.$name;
        File::put($path, "#!/usr/bin/env bash\nexit 0\n");
        chmod($path, 0755);

        return $path;
    }
}
