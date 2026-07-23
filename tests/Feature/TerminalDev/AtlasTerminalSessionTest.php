<?php

namespace Tests\Feature\TerminalDev;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasTerminalSessionTest extends TestCase
{
    private string $workspace;

    private string $sessionsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-terminal-ws-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        File::put($this->workspace.'/README.md', "# Hello Terminal\n\nfixture\n");
        File::put($this->workspace.'/composer.json', "{\"name\":\"fixture/terminal\"}\n");
        @exec('git -C '.escapeshellarg($this->workspace).' init 2>/dev/null');

        $this->sessionsRoot = sys_get_temp_dir().'/atlas-terminal-sessions-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->sessionsRoot);
        config([
            'atlas_terminal.sessions_root' => $this->sessionsRoot,
            'atlas_terminal.open_brain.enabled' => false,
            'atlas_terminal.hermes_allowed' => true,
            'atlas_terminal.provider' => 'hermes_cli',
            'atlas_terminal.default_provider_order' => ['hermes_cli'],
            'atlas_terminal.hermes_dry_run' => true,
            'atlas_terminal.force_local_planner' => true,
            'atlas_terminal.auto_compact_turns' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        File::deleteDirectory($this->sessionsRoot);
        parent::tearDown();
    }

    public function test_scorecard_passes_core_checks(): void
    {
        $code = Artisan::call('atlas:terminal:scorecard', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $code, Artisan::output());
        $this->assertTrue($payload['ok'] ?? false);
        $this->assertTrue($payload['checks']['hermes_is_default'] ?? false);
        $this->assertTrue($payload['checks']['hermes_bridge'] ?? false);
        $this->assertTrue($payload['checks']['subagents'] ?? false);
        $this->assertTrue($payload['checks']['hooks'] ?? false);
        $this->assertTrue($payload['checks']['mcp_client'] ?? false);
        $this->assertTrue($payload['checks']['slash_surface'] ?? false);
        $this->assertGreaterThanOrEqual(9.0, (float) ($payload['score_10'] ?? 0));
    }

    public function test_oneshot_read_prompt_runs_tools_and_persists_session(): void
    {
        $code = Artisan::call('atlas:terminal', [
            'task' => ['leia', 'README.md'],
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('session/update', $out);
        $this->assertStringContainsString('tool_call', $out);
        $this->assertStringContainsString('Hello Terminal', $out);
        $this->assertStringContainsString('atlas.terminal.turn_result.v1', $out);
        $this->assertStringContainsString('atlas/evidence', $out);

        $dirs = File::directories($this->sessionsRoot);
        $this->assertNotEmpty($dirs);
    }

    public function test_plan_mode_blocks_workspace_write(): void
    {
        $code = Artisan::call('atlas:terminal', [
            'task' => ['tool:file.write', 'path=HACK.md', 'content=nope'],
            '--workspace' => $this->workspace,
            '--plan' => true,
            '--json' => true,
        ]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('plan', strtolower($out));
        $this->assertFalse(File::exists($this->workspace.'/HACK.md'));
    }

    public function test_explicit_tool_dsl_file_read(): void
    {
        $code = Artisan::call('atlas:terminal', [
            'task' => ['tool:file.read', 'path=README.md'],
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Hello Terminal', $out);
    }

    public function test_hermes_is_default_provider(): void
    {
        $order = config('atlas_terminal.default_provider_order');
        $this->assertIsArray($order);
        $this->assertContains('hermes_cli', $order);
        $this->assertTrue((bool) config('atlas_terminal.hermes_allowed'));
        $this->assertSame('hermes_cli', (string) config('atlas_terminal.provider'));
    }

    public function test_hermes_path_dry_run_when_not_forced_local(): void
    {
        config(['atlas_terminal.force_local_planner' => false, 'atlas_terminal.hermes_dry_run' => true]);
        $code = Artisan::call('atlas:terminal', [
            'task' => ['explique', 'o', 'README'],
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('hermes_cli', $out);
        $this->assertStringContainsString('dry-run', strtolower($out));
    }

    public function test_slash_help_and_sessions(): void
    {
        $code = Artisan::call('atlas:terminal', [
            'task' => ['/help'],
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('subagent', $out);
        $this->assertStringContainsString('review', $out);

        Artisan::call('atlas:terminal', [
            'task' => ['tool:file.read', 'path=README.md'],
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $code = Artisan::call('atlas:terminal', [
            'task' => ['/sessions'],
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Sessions', $out);
    }

    public function test_subagent_explore(): void
    {
        $code = Artisan::call('atlas:terminal', [
            'task' => ['/subagent', 'explore', 'leia', 'README.md'],
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Subagent', $out);
    }

    public function test_review_emits_evidence(): void
    {
        $code = Artisan::call('atlas:terminal', [
            'task' => ['/review'],
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertTrue(
            str_contains($out, 'atlas/evidence') || str_contains($out, 'review'),
            $out
        );
    }

    public function test_forge_profile_switch(): void
    {
        $code = Artisan::call('atlas:terminal', [
            'task' => ['/forge'],
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('forge', strtolower($out));
    }

    public function test_doctor(): void
    {
        $code = Artisan::call('atlas:terminal', [
            'task' => ['/doctor'],
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('atlas.terminal.doctor.v1', $out);
    }
}
