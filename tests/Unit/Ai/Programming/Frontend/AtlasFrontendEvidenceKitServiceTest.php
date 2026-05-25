<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidenceKitService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendEvidenceKitServiceTest extends TestCase
{
    public function test_prepare_writes_full_evidence_collection_kit_with_task_spec_hash(): void
    {
        $output = sys_get_temp_dir().'/atlas-frontend-evidence-kit-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendEvidenceKitService::class)->prepare([
            'task' => 'Criar dashboard SaaS premium com estados mobile e desktop',
            'workspace' => '/company/repo',
            'output' => $output,
            'acceptance_criteria' => true,
        ]);

        $this->assertSame(AtlasFrontendEvidenceKitService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['task_spec_hash']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['evidence_kit_hash']);
        $this->assertFileExists($output.'/scenario-matrix.json');
        $this->assertFileExists($output.'/visual-quality-report.json');
        $this->assertFileExists($output.'/quality-budget-report.json');
        $this->assertFileExists($output.'/design-review-report.json');
        $this->assertFileExists($output.'/evidence/evidence-pack.json');
        $this->assertFileExists($output.'/outcome-record-template.json');
        $this->assertFileExists($output.'/evidence-kit-manifest.json');
        $this->assertTrue((bool) data_get($payload, 'claim_policy.evidence_kit_is_not_completion_evidence'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));

        $visual = json_decode(File::get($output.'/visual-quality-report.json'), true);
        $quality = json_decode(File::get($output.'/quality-budget-report.json'), true);
        $review = json_decode(File::get($output.'/design-review-report.json'), true);
        $pack = json_decode(File::get($output.'/evidence/evidence-pack.json'), true);

        $this->assertSame($payload['task_spec_hash'], $visual['task_spec_hash']);
        $this->assertSame($payload['task_spec_hash'], $quality['task_spec_hash']);
        $this->assertSame($payload['task_spec_hash'], $review['task_spec_hash']);
        $this->assertSame($payload['task_spec_hash'], $pack['task_spec_hash']);
        $this->assertStringContainsString('run-certify', implode("\n", $payload['collection_commands']));
    }

    public function test_prepare_carries_frontend_app_scope_into_manifest_and_collection_commands(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-evidence-kit-scope-workspace-'.bin2hex(random_bytes(4));
        $output = sys_get_temp_dir().'/atlas-frontend-evidence-kit-scope-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace.'/apps/web');

        $payload = app(AtlasFrontendEvidenceKitService::class)->prepare([
            'task' => 'Criar dashboard SaaS premium com estados mobile e desktop',
            'workspace' => $workspace,
            'frontend_app' => 'apps/web',
            'output' => $output,
            'acceptance_criteria' => true,
        ]);

        $manifest = json_decode(File::get($output.'/evidence-kit-manifest.json'), true);
        $visual = json_decode(File::get($output.'/visual-quality-report.json'), true);
        $quality = json_decode(File::get($output.'/quality-budget-report.json'), true);
        $review = json_decode(File::get($output.'/design-review-report.json'), true);
        $pack = json_decode(File::get($output.'/evidence/evidence-pack.json'), true);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertSame('apps/web', data_get($manifest, 'frontend_app_scope.relative_name'));
        $this->assertSame('apps/web', data_get($visual, 'frontend_app_scope.relative_name'));
        $this->assertSame('apps/web', data_get($quality, 'frontend_app_scope.relative_name'));
        $this->assertSame('apps/web', data_get($review, 'frontend_app_scope.relative_name'));
        $this->assertSame('apps/web', data_get($pack, 'frontend_app_scope.relative_name'));
        $this->assertStringContainsString('--frontend-app=apps/web', implode("\n", $payload['collection_commands']));
        $this->assertStringNotContainsString($workspace.'/apps/web', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_prepare_blocks_invalid_frontend_app_scope_without_emitting_unsafe_command(): void
    {
        $output = sys_get_temp_dir().'/atlas-frontend-evidence-kit-invalid-scope-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendEvidenceKitService::class)->prepare([
            'task' => 'Criar dashboard SaaS premium com estados mobile e desktop',
            'frontend_app' => '../secrets',
            'output' => $output,
            'acceptance_criteria' => true,
        ]);

        $commands = implode("\n", $payload['collection_commands']);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('invalid_subscope', data_get($payload, 'frontend_app_scope.status'));
        $this->assertContains('scenario_matrix_not_ready', $payload['blockers']);
        $this->assertStringNotContainsString('../secrets', $commands);
        $this->assertStringNotContainsString('--frontend-app=', $commands);
    }

    public function test_prepare_blocks_when_scenario_matrix_is_not_ready(): void
    {
        $output = sys_get_temp_dir().'/atlas-frontend-evidence-kit-blocked-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendEvidenceKitService::class)->prepare([
            'task' => 'Melhorar tela',
            'output' => $output,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('scenario_matrix_not_ready', $payload['blockers']);
        $this->assertContains('fix_task_spec_or_acceptance_before_collecting_evidence', $payload['required_next_actions']);
        $this->assertTrue(File::isFile($output.'/scenario-matrix.json'));
    }
}
