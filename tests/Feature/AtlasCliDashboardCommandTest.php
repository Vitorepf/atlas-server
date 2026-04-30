<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasCliDashboardCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-dashboard-command-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        File::put($this->workspace.'/composer.json', json_encode([
            'scripts' => [
                'test' => 'php artisan test',
            ],
        ], JSON_PRETTY_PRINT));

        config([
            'atlas.ai.workdir' => $this->workspace,
            'atlas.ai.runtime.profile_cache_ttl_seconds' => 0,
            'atlas.ai.tool_permissions.allowed_roots' => [$this->workspace],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_dashboard_command_outputs_json(): void
    {
        $exitCode = Artisan::call('atlas:cli:dashboard', [
            '--workspace' => $this->workspace,
            '--json' => true,
            '--refresh-index' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"workspace"', $output);
        $this->assertStringContainsString('"recommended_commands"', $output);
    }

    public function test_tui_command_can_render_single_frame(): void
    {
        $exitCode = Artisan::call('atlas:cli:tui', [
            '--workspace' => $this->workspace,
            '--once' => true,
            '--refresh-index' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Atlas CLI TUI', $output);
        $this->assertStringContainsString('1.Conversa', $output);
        $this->assertStringContainsString('r refresh', $output);
    }
}
