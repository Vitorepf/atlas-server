<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasDevDesktopCertifyCommandTest extends TestCase
{
    private string $workspace;

    private string $binDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-dev-desktop-certify-'.bin2hex(random_bytes(4));
        $this->binDir = $this->workspace.'/bin';
        File::ensureDirectoryExists($this->binDir);
        File::put($this->binDir.'/claude', "#!/bin/sh\nif [ \"$1\" = \"--version\" ]; then echo \"2.1.143 (Claude Code)\"; exit 0; fi\nexit 0\n");
        chmod($this->binDir.'/claude', 0o755);

        config()->set('atlas_dev.receipts_path', $this->workspace.'/receipts');
        config()->set('atlas_dev.efficient.plan_enabled', true);
        config()->set('atlas_dev.efficient.run_enabled', true);
        config()->set('atlas_dev.efficient.desktop_enabled', true);
        config()->set('atlas_dev.efficient.run_dispatch_mode', 'process');
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
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('C', 32)));

        $this->seedAcceptanceEvidence();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_desktop_certification_passes_when_runtime_surface_and_client_are_ready(): void
    {
        $exit = Artisan::call('atlas:dev:desktop:certify', [
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('atlas.dev.desktop_certification.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertContains('provider_runtime_launchable_without_model_call', $payload['certifies']);
        $this->assertContains('readiness_gated_run_button', $payload['certifies']);
        $this->assertContains('readiness_visual_smoke_covered', $payload['certifies']);
        $this->assertContains('expired_confirmation_token_client_blocked', $payload['certifies']);
        $this->assertContains('live_confirmation_token_expiry_guard', $payload['certifies']);
        $this->assertContains('real_smoke_command_available', $payload['certifies']);
        $this->assertContains('desktop_acceptance_evidence_gate_available', $payload['certifies']);
        $this->assertContains('desktop_contract_test_harness_available', $payload['certifies']);
        $this->assertContains('desktop_certification_script_available', $payload['certifies']);
        $this->assertContains('compact_sdd_hash_pin_enforced', $payload['certifies']);
        $this->assertContains('real_http_pipeline_smoke_available', $payload['certifies']);
        $this->assertContains('real_provider_smoke_passed', $payload['certifies']);
        $this->assertSame([], $payload['remaining_blockers']);
        $this->assertSame(7, $payload['stage_summary']['total']);
        $this->assertSame(7, $payload['stage_summary']['passed']);
        $this->assertSame(0, $payload['stage_summary']['blocked']);
        $this->assertSame('php artisan atlas:dev:desktop:certify --json --strict', $payload['commands']['self']);
        $this->assertSame('npm run atlas-dev:certify', $payload['commands']['desktop_certify']);
        $this->assertSame(
            'php artisan test tests/Unit/Ai/Programming/AtlasDev tests/Feature/Ai/Programming/AtlasDev',
            $payload['commands']['backend_suite'],
        );
        $this->assertSame('php artisan atlas:engineering:knowledge docs-health --json', $payload['commands']['docs_health']);
        $this->assertSame(
            'php artisan atlas:dev:desktop:real-smoke --yes --json',
            $payload['commands']['real_provider_smoke'],
        );
        $this->assertSame(
            'php artisan atlas:dev:desktop:acceptance --json --strict',
            $payload['commands']['acceptance_evidence'],
        );
        $this->assertStringContainsString('explicit-operator', $payload['note']);
        $this->assertSame(
            [
                'runtime_readiness',
                'http_surface_contract',
                'backend_runtime_components',
                'compact_sdd_integrity_contract',
                'real_provider_acceptance_evidence',
                'desktop_client_contract',
                'stream_runtime_contract',
            ],
            array_column($payload['stages'], 'name'),
        );
        $integrityStage = collect($payload['stages'])->firstWhere('name', 'compact_sdd_integrity_contract');
        $this->assertIsArray($integrityStage);
        $this->assertContains(
            'tests/Feature/Ai/Programming/AtlasDev/Http/PipelineRunExecutorHttpSmokeTest.php',
            $integrityStage['checked_files'],
        );
        $acceptanceStage = collect($payload['stages'])->firstWhere('name', 'real_provider_acceptance_evidence');
        $this->assertIsArray($acceptanceStage);
        $this->assertSame('passed', $acceptanceStage['status']);
        $this->assertSame('desktop_acceptance/latest.json', $acceptanceStage['latest_ref']);
        $this->assertSame('claude_cli', $acceptanceStage['provider']);
        $this->assertSame('sonnet', $acceptanceStage['model_family']);
        $this->assertSame([], $acceptanceStage['failed_checks']);
        $desktopStage = collect($payload['stages'])->firstWhere('name', 'desktop_client_contract');
        $this->assertIsArray($desktopStage);
        $this->assertContains(
            'apps/desktop/package.json',
            $desktopStage['checked_files'],
        );
        $this->assertSame([], $desktopStage['missing_tokens']);
        $this->assertContains(
            'apps/desktop/src/components/atlasDev/ReadinessGate.tsx',
            $desktopStage['checked_files'],
        );
        $this->assertContains(
            'apps/desktop/src/components/atlasDev/RunPanel.tsx',
            $desktopStage['checked_files'],
        );
        $this->assertContains(
            'apps/desktop/scripts/visualRender.mjs',
            $desktopStage['checked_files'],
        );
        $runtimeStage = collect($payload['stages'])->firstWhere('name', 'backend_runtime_components');
        $this->assertIsArray($runtimeStage);
        $this->assertContains(
            'App\\Console\\Commands\\AtlasDevDesktopRealSmokeCommand',
            $runtimeStage['required_classes'],
        );
        $this->assertContains(
            'App\\Console\\Commands\\AtlasDevDesktopAcceptanceCommand',
            $runtimeStage['required_classes'],
        );
    }

    public function test_desktop_certification_strict_fails_when_readiness_is_blocked(): void
    {
        config()->set('atlas_dev.efficient.desktop_enabled', false);

        $exit = Artisan::call('atlas:dev:desktop:certify', [
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('runtime_readiness', $payload['remaining_blockers']);
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
            'run_id' => 'dev-cert-test',
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
