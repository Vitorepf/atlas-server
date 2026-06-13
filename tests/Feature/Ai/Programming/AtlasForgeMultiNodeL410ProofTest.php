<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasForgeMultiNodeL410ProofService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasForgeMultiNodeL410ProofTest extends TestCase
{
    private string $evidencePath;

    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $id = (string) Str::uuid();
        $this->evidencePath = storage_path('framework/testing/l4-10-proof-'.$id.'.json');
        $this->reportPath = storage_path('framework/testing/l4-10-proof-report-'.$id.'.json');
    }

    protected function tearDown(): void
    {
        @File::delete($this->evidencePath);
        @File::delete($this->reportPath);

        parent::tearDown();
    }

    public function test_without_real_evidence_plans_six_nodes_and_fails_closed(): void
    {
        $report = app(AtlasForgeMultiNodeL410ProofService::class)->report();

        $this->assertSame(AtlasForgeMultiNodeL410ProofService::SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame('real_execution_blocked', $report['status']);
        $this->assertFalse($report['certified']);
        $this->assertSame(6, $report['planned_obra']['work_node_count']);
        $this->assertSame(10, $report['planned_obra']['schedule']['recommended_agent_count']);
        $this->assertSame('parallel_no_overlap', $report['planned_obra']['schedule']['integration_plan']);
        $this->assertSame(6, $report['planned_obra']['parallel_durable_assignment']['counts']['assignments']);
        $this->assertTrue($report['delivered_item']['local_digest_command_available']);
        $this->assertFalse($report['claim_policy']['provider_dispatches_now']);
        $this->assertFalse($report['claim_policy']['completion_claim_allowed']);
        $this->assertContains('real_provider_obra_run_evidence_missing', $report['blockers']);
        $this->assertContains('kill_resume_live_evidence_missing', $report['blockers']);
    }

    public function test_simulate_only_receipt_is_rejected_even_when_shape_looks_green(): void
    {
        $this->writeEvidence([
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'status' => 'done',
            'certified' => true,
            'execution_mode' => 'simulate_only_test_double',
            'obra_id' => 'obra-l4-10-real-shaped',
            'node_count' => 6,
            'provider_calls_made' => true,
            'provider' => ['model' => 'gpt-5.5'],
            'delivered_item_id' => 'L4-6',
            'delivered_files' => ['app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php'],
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['ledger:kill', 'ledger:resume'],
            ],
            'command_results' => [
                ['command' => 'php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
        ]);

        $report = app(AtlasForgeMultiNodeL410ProofService::class)->report([
            'evidence_path' => $this->evidencePath,
        ]);

        $this->assertSame('real_execution_evidence_rejected', $report['status']);
        $this->assertFalse($report['certified']);
        $this->assertContains('non_real_or_fixture_execution_evidence', $report['blockers']);
    }

    public function test_thin_real_shaped_receipt_requires_hermes_schema_status_and_material_files(): void
    {
        $this->writeEvidence([
            'certified' => true,
            'obra_id' => 'obra-l4-10-thin-real-shaped',
            'node_count' => 6,
            'provider_calls_made' => true,
            'external_provider_call' => true,
            'provider' => [
                'name' => 'codex_cli',
                'model' => 'gpt-5.5',
            ],
            'delivered_item_id' => 'L4-6',
            'delivered_files' => ['app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php'],
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['ledger:kill-real', 'ledger:resume-real'],
            ],
            'command_results' => [
                ['command' => 'php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
        ]);

        $report = app(AtlasForgeMultiNodeL410ProofService::class)->report([
            'evidence_path' => $this->evidencePath,
        ]);

        $this->assertSame('real_execution_evidence_rejected', $report['status']);
        $this->assertFalse($report['certified']);
        $this->assertContains('real_receipt_schema_version_mismatch', $report['blockers']);
        $this->assertContains('real_receipt_done_status_missing', $report['blockers']);
        $this->assertContains('hermes_cli_provider_evidence_missing', $report['blockers']);
        $this->assertContains('non_real_or_fixture_execution_evidence', $report['blockers']);
        $this->assertContains('l4_6_material_files_evidence_missing', $report['blockers']);
    }

    public function test_partially_filled_template_receipt_is_rejected_even_when_green_fields_are_flipped(): void
    {
        $this->writeEvidence([
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'template_only' => true,
            'status' => 'done',
            'certified' => true,
            'execution_mode' => 'real_provider_obra_run',
            'obra_id' => '<real Obra id>',
            'node_count' => 6,
            'provider_calls_made' => true,
            'external_provider_call' => true,
            'provider' => [
                'name' => '<hermes_cli>',
                'model' => '<gpt-5.5>',
            ],
            'delivered_item_id' => '<L4-6>',
            'delivered_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
                'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
                'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
            ],
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['<real kill event receipt ref>', '<real resume event receipt ref>'],
            ],
            'command_results' => [
                ['command' => '/opt/homebrew/bin/php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
        ]);

        $exit = Artisan::call('atlas:forge:l4-10-proof', [
            '--evidence' => $this->evidencePath,
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('real_execution_evidence_rejected', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertContains('template_or_placeholder_evidence_not_allowed', $payload['blockers']);
        $this->assertFalse($payload['claim_policy']['completion_claim_allowed']);
    }

    public function test_kill_resume_claim_requires_executor_resume_runtime_evidence(): void
    {
        $this->writeEvidence([
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'status' => 'done',
            'certified' => true,
            'execution_mode' => 'real_provider_obra_run',
            'obra_id' => 'obra-l4-10-real-no-executor-resume',
            'node_count' => 6,
            'provider_calls_made' => true,
            'external_provider_call' => true,
            'provider' => [
                'name' => 'hermes_cli',
                'model' => 'gpt-5.5',
            ],
            'delivered_item_id' => 'L4-6',
            'delivered_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
                'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
                'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
            ],
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['ledger:kill-real', 'ledger:resume-real'],
            ],
            'command_results' => [
                ['command' => 'php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
        ]);

        $report = app(AtlasForgeMultiNodeL410ProofService::class)->report([
            'evidence_path' => $this->evidencePath,
        ]);

        $this->assertSame('real_execution_evidence_rejected', $report['status']);
        $this->assertFalse($report['certified']);
        $this->assertContains('obra_executor_resume_evidence_missing', $report['blockers']);
        $this->assertFalse((bool) data_get($report, 'validation.checks.kill_resume.executor_resumed'));
        $this->assertSame(0, data_get($report, 'validation.checks.kill_resume.resume_count'));
    }

    public function test_valid_real_receipt_certifies_l4_10_and_strict_command_passes(): void
    {
        $this->writeEvidence([
            'schema_version' => AtlasForgeMultiNodeL410ProofService::REAL_RECEIPT_SCHEMA_VERSION,
            'status' => 'done',
            'certified' => true,
            'execution_mode' => 'real_provider_obra_run',
            'obra_id' => 'obra-l4-10-real-20260612',
            'node_count' => 6,
            'resumed' => true,
            'resume_count' => 1,
            'provider_calls_made' => true,
            'external_provider_call' => true,
            'provider' => [
                'name' => 'hermes_cli',
                'model' => 'gpt-5.5',
            ],
            'delivered_item_id' => 'L4-6',
            'delivered_files' => [
                'app/Services/Ai/AutonomousEvolution/AtlasLoopMorningDigestService.php',
                'app/Console/Commands/AtlasLoopMorningDigestCommand.php',
                'tests/Feature/Loop/AtlasLoopMorningDigestTest.php',
            ],
            'kill_resume' => [
                'kill_exercised' => true,
                'resume_exercised' => true,
                'evidence_refs' => ['ledger:kill-real', 'ledger:resume-real'],
            ],
            'command_results' => [
                ['command' => 'php artisan atlas:loop:morning-digest --json', 'exit_code' => 0],
            ],
        ]);

        $exit = Artisan::call('atlas:forge:l4-10-proof', [
            '--evidence' => $this->evidencePath,
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('certified', $payload['status']);
        $this->assertTrue($payload['certified']);
        $this->assertSame([], $payload['blockers']);
        $this->assertTrue($payload['claim_policy']['completion_claim_allowed']);
        $this->assertTrue($payload['delivered_item']['delivered_by_real_multi_node_obra']);
    }

    public function test_strict_command_fails_without_real_evidence(): void
    {
        $exit = Artisan::call('atlas:forge:l4-10-proof', [
            '--strict' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('real_execution_blocked', $payload['status']);
        $this->assertFalse($payload['certified']);
    }

    public function test_command_writes_blocked_proof_report_without_promoting_real_claim(): void
    {
        $exit = Artisan::call('atlas:forge:l4-10-proof', [
            '--write-report' => true,
            '--report-path' => $this->reportPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('real_execution_blocked', $payload['status']);
        $this->assertFalse($payload['certified']);
        $this->assertSame($this->reportPath, $payload['written_report_path']);
        $this->assertFileExists($this->reportPath);

        $written = json_decode((string) File::get($this->reportPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasForgeMultiNodeL410ProofService::SCHEMA_VERSION, $written['schema_version']);
        $this->assertSame('real_execution_blocked', $written['status']);
        $this->assertFalse($written['claim_policy']['provider_dispatches_now']);
        $this->assertFalse($written['claim_policy']['completion_claim_allowed']);
        $this->assertContains('real_provider_obra_run_evidence_missing', $written['blockers']);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeEvidence(array $payload): void
    {
        File::ensureDirectoryExists(dirname($this->evidencePath));
        File::put($this->evidencePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
