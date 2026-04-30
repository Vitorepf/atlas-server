<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasCliSetupCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-setup-command-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace.'/bin');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_setup_command_outputs_json_and_can_write_env(): void
    {
        $codex = $this->fakeExecutable('codex');
        $envPath = $this->workspace.'/.env';
        File::put($envPath, "ATLAS_AI_CODEX_BIN=codex\n");

        $exitCode = Artisan::call('atlas:cli:setup', [
            '--provider' => 'codex',
            '--codex-bin' => $codex,
            '--env-path' => $envPath,
            '--write-env' => true,
            '--strict' => true,
            '--json' => true,
        ]);

        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"ok": true', $output);
        $this->assertStringContainsString('"codex_ready": true', $output);
        $this->assertStringContainsString('ATLAS_AI_CODEX_BIN='.(realpath($codex) ?: $codex), File::get($envPath));
    }

    private function fakeExecutable(string $name): string
    {
        $path = $this->workspace.'/bin/'.$name;
        File::put($path, "#!/usr/bin/env bash\nexit 0\n");
        chmod($path, 0755);

        return $path;
    }
}
