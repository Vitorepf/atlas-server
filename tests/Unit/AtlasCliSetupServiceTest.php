<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\AtlasCliSetupService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasCliSetupServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-setup-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace.'/bin');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_diagnose_resolves_configured_provider_binary(): void
    {
        $codex = $this->fakeExecutable('codex');

        config([
            'atlas.ai.providers.codex_cli.binary' => $codex,
            'atlas.ai.providers.claude_cli.binary' => 'atlas-missing-claude-binary',
        ]);

        $payload = app(AtlasCliSetupService::class)->diagnose();

        $this->assertTrue($payload['summary']['has_any_ready_provider']);
        $this->assertTrue($payload['summary']['codex_ready']);
        $this->assertFalse($payload['summary']['claude_ready']);
        $this->assertSame('ready', collect($payload['providers'])->firstWhere('provider', 'codex_cli')['status']);
        $this->assertSame('missing', collect($payload['providers'])->firstWhere('provider', 'claude_cli')['status']);
    }

    public function test_write_env_persists_only_resolved_binaries(): void
    {
        $codex = $this->fakeExecutable('codex');
        $envPath = $this->workspace.'/.env';
        File::put($envPath, "ATLAS_AI_CODEX_BIN=old-codex\nATLAS_AI_CLAUDE_BIN=old-claude\n");

        config([
            'atlas.ai.providers.codex_cli.binary' => $codex,
            'atlas.ai.providers.claude_cli.binary' => 'atlas-missing-claude-binary',
        ]);

        $service = app(AtlasCliSetupService::class);
        $result = $service->writeEnv($service->diagnose(), $envPath);

        $this->assertTrue($result['written']);
        $this->assertIsString($result['backup_path']);
        $this->assertFileExists($result['backup_path']);
        $contents = File::get($envPath);
        $this->assertStringContainsString('ATLAS_AI_CODEX_BIN='.(realpath($codex) ?: $codex), $contents);
        $this->assertStringContainsString('ATLAS_AI_CLAUDE_BIN=old-claude', $contents);
    }

    private function fakeExecutable(string $name): string
    {
        $path = $this->workspace.'/bin/'.$name;
        File::put($path, "#!/usr/bin/env bash\nexit 0\n");
        chmod($path, 0755);

        return $path;
    }
}
