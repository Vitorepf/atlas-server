<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasCliFinalCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-final-test-'.bin2hex(random_bytes(4));
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

    public function test_final_command_outputs_b0_to_b8_readiness_packet(): void
    {
        $exitCode = Artisan::call('atlas:cli:final', [
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"B0_glossary"', $output);
        $this->assertStringContainsString('"B8_distribution"', $output);
        $this->assertStringContainsString('"release_preflight"', $output);
        $this->assertStringContainsString('"final_doctor"', $output);
    }
}
