<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasCliDoctorCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-doctor-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);

        config([
            'atlas.ai.tool_permissions.allowed_roots' => [$this->workspace],
            'atlas.ai.providers.claude_cli.binary' => 'atlas-missing-claude-binary',
            'atlas.ai.providers.codex_cli.binary' => 'atlas-missing-codex-binary',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_cli_doctor_outputs_json_readiness_packet(): void
    {
        $exitCode = Artisan::call('atlas:cli:doctor', [
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"readiness"', $output);
        $this->assertStringContainsString('"permission_scope"', $output);
        $this->assertStringContainsString('"provider_binaries"', $output);
        $this->assertStringContainsString('"provider_projection"', $output);
        $this->assertFileDoesNotExist($this->workspace.'/CLAUDE.md');
        $this->assertFileDoesNotExist($this->workspace.'/AGENTS.md');
    }
}
