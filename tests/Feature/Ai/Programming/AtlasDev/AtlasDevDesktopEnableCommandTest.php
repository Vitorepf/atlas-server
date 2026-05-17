<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasDevDesktopEnableCommandTest extends TestCase
{
    private string $workspace;

    private string $binDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-dev-desktop-enable-'.bin2hex(random_bytes(4));
        $this->binDir = $this->workspace.'/bin';
        File::ensureDirectoryExists($this->binDir);
        File::put($this->binDir.'/claude', "#!/bin/sh\nexit 0\n");
        chmod($this->binDir.'/claude', 0o755);

        config()->set('atlas_dev.receipts_path', $this->workspace.'/receipts');
        config()->set('atlas.ai.providers.claude_cli.binary', $this->binDir.'/claude');
        config()->set('atlas.ai.providers.claude_cli.args', [
            '-p',
            '--output-format',
            'stream-json',
            '--verbose',
            '--no-session-persistence',
            '--allowedTools',
            'Read',
        ]);
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('D', 32)));

        $this->seedAcceptanceEvidence();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_desktop_enable_writes_flags_backup_and_reports_readiness_passed(): void
    {
        $envPath = $this->workspace.'/.env';
        File::put($envPath, "APP_NAME=Atlas\nATLAS_DEV_EFFICIENT_RUN_ENABLED=false\n");

        $exit = Artisan::call('atlas:dev:desktop:enable', [
            '--env-path' => $envPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('atlas.dev.desktop_enable.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['readiness']['status']);
        $this->assertSame('passed', $payload['certification']['status']);
        $this->assertContains('readiness_gated_run_button', $payload['certification']['certifies']);
        $this->assertContains('real_provider_smoke_passed', $payload['certification']['certifies']);
        $this->assertSame(false, $payload['write_env']['dry_run']);
        $this->assertFileExists($payload['write_env']['backup_path']);

        $env = File::get($envPath);
        $this->assertStringContainsString('ATLAS_DEV_EFFICIENT_ENABLED=true', $env);
        $this->assertStringContainsString('ATLAS_DEV_EFFICIENT_PLAN_ENABLED=true', $env);
        $this->assertStringContainsString('ATLAS_DEV_EFFICIENT_RUN_ENABLED=true', $env);
        $this->assertStringContainsString('ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED=true', $env);
        $this->assertStringContainsString('ATLAS_DEV_RUN_DISPATCH_MODE=process', $env);
        $this->assertSame(1, substr_count($env, 'ATLAS_DEV_EFFICIENT_RUN_ENABLED='));
    }

    public function test_desktop_enable_dry_run_does_not_write_env(): void
    {
        $envPath = $this->workspace.'/.env';
        File::put($envPath, "APP_NAME=Atlas\n");

        $exit = Artisan::call('atlas:dev:desktop:enable', [
            '--env-path' => $envPath,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['write_env']['dry_run']);
        $this->assertSame('passed', $payload['certification']['status']);
        $this->assertSame("APP_NAME=Atlas\n", File::get($envPath));
    }

    private function seedAcceptanceEvidence(): void
    {
        $dir = $this->workspace.'/receipts/desktop_acceptance';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/latest.json', json_encode([
            'schema_version' => 'atlas.dev.desktop_acceptance_evidence.v1',
            'recorded_at' => now()->toISOString(),
            'source_command' => 'atlas:dev:desktop:real-smoke',
            'status' => 'passed',
            'run_id' => 'dev-enable-test',
            'external_provider_call' => true,
            'provider' => 'claude_cli',
            'model_family' => 'sonnet',
            'completion_state' => 'passed',
            'scope_guard_status' => 'passed',
            'verification_status' => 'passed',
            'patch_apply_status' => 'applied',
            'receipt_hash' => hash('sha256', 'receipt'),
            'changed_files' => ['src/SmokeSubject.php'],
            'tests_count' => 1,
            'honesty_flags' => [],
            'workspace_assertion_passed' => true,
            'operator_confirmation' => [
                'token_issued' => true,
                'token_consumed' => true,
                'task_contract_hash' => hash('sha256', 'task-contract'),
                'compact_sdd_hash_pinned' => true,
            ],
            'reason' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
}
