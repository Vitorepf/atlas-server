<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasDevDesktopGoalAuditCommandTest extends TestCase
{
    private string $workspace;

    private string $binDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-dev-desktop-goal-audit-'.bin2hex(random_bytes(4));
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
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('G', 32)));

        $this->writeAcceptanceEvidence();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_goal_audit_blocks_when_comparative_efficiency_is_missing(): void
    {
        $exit = Artisan::call('atlas:dev:desktop:goal-audit', [
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('atlas.dev.desktop_goal_audit.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertSame(['comparative_efficiency_10x_proof'], $payload['missing_or_unproven']);

        $criterion = collect($payload['criteria'])->firstWhere('id', 'comparative_efficiency_10x_proof');
        $this->assertSame('blocked', $criterion['status']);
        $this->assertContains('missing_comparative_efficiency_evidence', $criterion['violations']);
    }

    public function test_goal_audit_passes_when_all_required_evidence_is_present(): void
    {
        $this->writeEfficiencyEvidence([
            'schema_version' => 'atlas.dev.desktop_efficiency_evidence.v1',
            'status' => 'passed',
            'input_sha256' => str_repeat('b', 64),
            'measured_multiplier' => 10.25,
            'compared_against' => ['claude_code', 'codex'],
            'case_count' => 5,
            'measurement_mode' => 'observed_operator_runs',
            'aggregate_multipliers' => [
                'claude_code' => 10.5,
                'codex' => 10.25,
            ],
            'evidence_quality' => [
                'required_task_kinds_covered' => true,
                'participant_evidence_refs_required' => true,
            ],
            'cases' => [
                ['case_id' => 'patch_case'],
                ['case_id' => 'repair_case'],
                ['case_id' => 'review_case'],
                ['case_id' => 'frontend_case'],
                ['case_id' => 'question_case'],
            ],
            'blocking_findings' => [],
        ]);

        $exit = Artisan::call('atlas:dev:desktop:goal-audit', [
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('passed', $payload['status']);
        $this->assertTrue($payload['completion_claim_allowed']);
        $this->assertSame([], $payload['missing_or_unproven']);
        $this->assertSame(
            ['desktop_operational_enterprise_certification', 'desktop_real_provider_acceptance', 'comparative_efficiency_10x_proof'],
            array_column($payload['criteria'], 'id'),
        );
    }

    private function writeAcceptanceEvidence(): void
    {
        $dir = $this->workspace.'/receipts/desktop_acceptance';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/latest.json', json_encode([
            'schema_version' => 'atlas.dev.desktop_acceptance_evidence.v1',
            'status' => 'passed',
            'run_id' => 'dev-goal-audit',
            'external_provider_call' => true,
            'provider' => 'claude_cli',
            'model_family' => 'sonnet',
            'completion_state' => 'passed',
            'scope_guard_status' => 'passed',
            'verification_status' => 'passed',
            'patch_apply_status' => 'applied',
            'receipt_hash' => str_repeat('a', 64),
            'changed_files' => ['src/SmokeSubject.php'],
            'tests_count' => 1,
            'honesty_flags' => [],
            'workspace_assertion_passed' => true,
            'operator_confirmation' => [
                'token_consumed' => true,
                'compact_sdd_hash_pinned' => true,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeEfficiencyEvidence(array $payload): void
    {
        $dir = $this->workspace.'/receipts/desktop_efficiency';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/latest.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
}
