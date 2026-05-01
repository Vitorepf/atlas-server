<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AtlasCliInstallCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-install-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_install_command_can_create_symlink_at_custom_target(): void
    {
        $target = $this->workspace.'/atlas';

        $exitCode = Artisan::call('atlas:cli:install', [
            '--target' => $target,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertTrue(is_link($target));
        $this->assertStringContainsString('"installed": true', $output);
    }

    public function test_installed_symlink_launcher_resolves_project_root(): void
    {
        $target = $this->workspace.'/bin/atlas';

        $exitCode = Artisan::call('atlas:cli:install', [
            '--target' => $target,
            '--json' => true,
        ]);
        $this->assertSame(0, $exitCode);

        $process = new Process([$target, 'help', '--json'], $this->workspace);
        $process->setTimeout(10);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());
        $this->assertStringContainsString('"product": "Atlas CLI"', $process->getOutput());
    }

    public function test_install_command_blocks_existing_target_without_force(): void
    {
        $target = $this->workspace.'/atlas';
        File::put($target, 'existing');

        $exitCode = Artisan::call('atlas:cli:install', [
            '--target' => $target,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"force_required": true', Artisan::output());
    }

    public function test_install_command_can_write_shell_profile_path_block(): void
    {
        $target = $this->workspace.'/bin/atlas';
        $profile = $this->workspace.'/.zshrc';

        $exitCode = Artisan::call('atlas:cli:install', [
            '--target' => $target,
            '--shell-profile' => $profile,
            '--write-shell-profile' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertTrue(is_link($target));
        $this->assertStringContainsString('# >>> atlas-cli >>>', File::get($profile));
        $this->assertStringContainsString(dirname($target), File::get($profile));
    }
}
