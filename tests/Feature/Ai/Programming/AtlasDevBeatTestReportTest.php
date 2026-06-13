<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasDevBeatTestReportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasDevBeatTestReportTest extends TestCase
{
    private string $evidencePath;

    private string $manifestPath;

    private string $reportPath;

    private string $receiptRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $id = (string) Str::uuid();
        $this->evidencePath = storage_path("framework/testing/dev-beat-test-{$id}.json");
        $this->manifestPath = storage_path("framework/testing/dev-beat-test-backlog-{$id}.json");
        $this->reportPath = storage_path("framework/testing/dev-beat-test-report-{$id}.json");
        $this->receiptRoot = storage_path("framework/testing/dev-beat-test-receipts-{$id}");
    }

    protected function tearDown(): void
    {
        @File::delete($this->evidencePath);
        @File::delete($this->manifestPath);
        @File::delete($this->reportPath);
        if (is_dir($this->receiptRoot)) {
            File::deleteDirectory($this->receiptRoot);
        }

        parent::tearDown();
    }

    public function test_report_scores_three_medium_atlas_dev_tasks_and_blocks_external_claim_without_comparable_baseline(): void
    {
        $this->writeEvidence([
            $this->task('bug', 320),
            $this->task('feature', 540),
            $this->task('refactor', 610),
        ]);

        $exit = Artisan::call('atlas:dev:beat-test', [
            '--evidence' => $this->evidencePath,
            '--manifest-path' => $this->manifestPath,
            '--enqueue-backlog-gaps' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('external_claim_blocked', $payload['status']);
        $this->assertSame(3, $payload['summary']['atlas_dev_passed_count']);
        $this->assertSame(0, $payload['summary']['comparable_external_count']);
        $this->assertFalse($payload['claim_policy']['external_superiority_claim_allowed']);
        $this->assertFalse($payload['claim_policy']['provider_dispatches_now']);
        $this->assertSame(3, $payload['backlog']['enqueued_count']);

        $manifest = json_decode((string) File::get($this->manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(3, $manifest['items']);
        $this->assertSame(
            [AtlasDevBeatTestReportService::BACKLOG_SOURCE],
            array_values(array_unique(array_column($manifest['items'], 'source'))),
        );
        $this->assertContains('comparable_external_evidence_missing:bug', array_column($manifest['items'], 'reason'));
    }

    public function test_report_emits_fixed_criteria_gap_when_atlas_dev_task_fails(): void
    {
        $this->writeEvidence([
            $this->task('bug', 320, testsPassed: false),
            $this->task('feature', 540),
            $this->task('refactor', 610),
        ]);

        $exit = Artisan::call('atlas:dev:beat-test', [
            '--evidence' => $this->evidencePath,
            '--manifest-path' => $this->manifestPath,
            '--enqueue-backlog-gaps' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('atlas_dev_failed', $payload['status']);
        $bug = collect($payload['tasks'])->firstWhere('task_type', 'bug');
        $this->assertSame('atlas_dev_failed', $bug['status']);
        $this->assertFalse($bug['criteria']['tests_passed']);
        $this->assertContains('atlas_dev_fixed_criteria_not_met', array_column($payload['gaps'], 'reason'));
    }

    public function test_receipt_autopsy_rejects_smoke_fixture_and_reports_candidate_real_repo_receipt(): void
    {
        $this->writeReceiptRun('dev-smoke-fixture', ['src/SmokeSubject.php']);
        $this->writeReceiptRun('dev-real-repo', ['app/Services/Ai/Programming/AtlasDevBeatTestReportService.php']);

        $exit = Artisan::call('atlas:dev:beat-test', [
            '--receipt-root' => $this->receiptRoot,
            '--receipt-limit' => 10,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('evidence_missing', $payload['status']);
        $this->assertFalse($payload['claim_policy']['receipt_autopsy_auto_promotes_tasks']);
        $this->assertSame('candidate_repo_receipts_found_not_auto_promoted', $payload['receipt_autopsy']['status']);
        $this->assertSame(2, $payload['receipt_autopsy']['summary']['real_hermes_gpt_55_passed_count']);
        $this->assertSame(1, $payload['receipt_autopsy']['summary']['candidate_repo_receipt_count']);
        $this->assertSame(1, $payload['receipt_autopsy']['summary']['fixture_or_generated_rejection_count']);

        $smoke = collect($payload['receipt_autopsy']['runs'])->firstWhere('run_id', 'dev-smoke-fixture');
        $real = collect($payload['receipt_autopsy']['runs'])->firstWhere('run_id', 'dev-real-repo');

        $this->assertSame('rejected', $smoke['status']);
        $this->assertContains('fixture_or_generated_target', $smoke['rejection_reasons']);
        $this->assertSame('candidate_repo_receipt', $real['status']);
        $this->assertSame([], $real['rejection_reasons']);
    }

    public function test_report_allows_superiority_claim_only_with_three_comparable_atlas_wins(): void
    {
        $this->writeEvidence([
            $this->task('bug', 320, baselineDuration: 500),
            $this->task('feature', 540, baselineDuration: 700),
            $this->task('refactor', 610, baselineDuration: 850),
        ]);

        $exit = Artisan::call('atlas:dev:beat-test', [
            '--evidence' => $this->evidencePath,
            '--manifest-path' => $this->manifestPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('atlas_dev_beats_baseline', $payload['status']);
        $this->assertSame(3, $payload['summary']['comparable_external_count']);
        $this->assertSame(3, $payload['summary']['atlas_win_count']);
        $this->assertTrue($payload['claim_policy']['external_comparison_claim_allowed']);
        $this->assertTrue($payload['claim_policy']['external_superiority_claim_allowed']);
        $this->assertSame([], $payload['gaps']);
    }

    public function test_report_rejects_thin_external_baseline_without_material_receipts(): void
    {
        $this->writeEvidence([
            $this->task('bug', 320, baselineDuration: 500, completeBaseline: false),
            $this->task('feature', 540, baselineDuration: 700, completeBaseline: false),
            $this->task('refactor', 610, baselineDuration: 850, completeBaseline: false),
        ]);

        $exit = Artisan::call('atlas:dev:beat-test', [
            '--evidence' => $this->evidencePath,
            '--manifest-path' => $this->manifestPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('external_claim_blocked', $payload['status']);
        $this->assertSame(0, $payload['summary']['comparable_external_count']);
        $this->assertFalse($payload['claim_policy']['external_comparison_claim_allowed']);
        $this->assertFalse($payload['claim_policy']['external_superiority_claim_allowed']);

        $bug = collect($payload['tasks'])->firstWhere('task_type', 'bug');
        $this->assertFalse($bug['baseline']['executed']);
        $this->assertTrue($bug['baseline']['raw_executed']);
        $this->assertFalse($bug['baseline']['evidence_complete']);
        $this->assertSame('external_baseline_evidence_incomplete', $bug['comparison']['reason']);
        $this->assertContains('external_baseline_model_missing', $bug['baseline']['evidence_blockers']);
        $this->assertContains('external_baseline_changed_files_missing', $bug['baseline']['evidence_blockers']);
        $this->assertContains('external_baseline_validation_commands_missing', $bug['baseline']['evidence_blockers']);
        $this->assertContains('external_baseline_evidence_refs_missing', $bug['baseline']['evidence_blockers']);
    }

    public function test_report_rejects_placeholder_external_baseline_even_when_green_fields_are_flipped(): void
    {
        $this->writeEvidence([
            $this->task('bug', 320, placeholderBaseline: true),
            $this->task('feature', 540, placeholderBaseline: true),
            $this->task('refactor', 610, placeholderBaseline: true),
        ]);

        $exit = Artisan::call('atlas:dev:beat-test', [
            '--evidence' => $this->evidencePath,
            '--manifest-path' => $this->manifestPath,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame('external_claim_blocked', $payload['status']);
        $this->assertSame(0, $payload['summary']['comparable_external_count']);
        $this->assertFalse($payload['claim_policy']['external_comparison_claim_allowed']);
        $this->assertFalse($payload['claim_policy']['external_superiority_claim_allowed']);

        $bug = collect($payload['tasks'])->firstWhere('task_type', 'bug');
        $this->assertFalse($bug['baseline']['executed']);
        $this->assertTrue($bug['baseline']['raw_executed']);
        $this->assertFalse($bug['baseline']['evidence_complete']);
        $this->assertSame('external_baseline_evidence_incomplete', $bug['comparison']['reason']);
        $this->assertContains('external_baseline_template_or_placeholder_evidence_not_allowed', $bug['baseline']['evidence_blockers']);
    }

    public function test_command_writes_report_without_provider_dispatch(): void
    {
        $this->writeEvidence([
            $this->task('bug', 320),
            $this->task('feature', 540),
            $this->task('refactor', 610),
        ]);

        $exit = Artisan::call('atlas:dev:beat-test', [
            '--evidence' => $this->evidencePath,
            '--report-path' => $this->reportPath,
            '--write-report' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame($this->reportPath, $payload['written_report_path']);
        $this->assertFileExists($this->reportPath);
        $written = json_decode((string) File::get($this->reportPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('external_claim_blocked', $written['status']);
        $this->assertFalse($written['claim_policy']['provider_dispatches_now']);
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     */
    private function writeEvidence(array $tasks): void
    {
        File::ensureDirectoryExists(dirname($this->evidencePath));
        File::put($this->evidencePath, json_encode([
            'schema_version' => AtlasDevBeatTestReportService::EVIDENCE_SCHEMA_VERSION,
            'tasks' => $tasks,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  list<string>  $changedFiles
     */
    private function writeReceiptRun(string $runId, array $changedFiles): void
    {
        $path = $this->receiptRoot.'/'.$runId;
        File::ensureDirectoryExists($path);

        $providerCall = [
            'provider' => 'hermes_cli',
            'model_family' => 'gpt-5.5',
            'provider_calls' => 1,
            'duration_ms' => 64000,
        ];
        File::put($path.'/senior_engineer_loop_execution.json', json_encode([
            'schema_version' => 'atlas.dev.senior_engineer_loop_execution.v1',
            'run_id' => $runId,
            'status' => 'passed',
            'run_summary' => [
                'completion_state' => 'passed',
                'provider_call' => $providerCall,
                'scope_guard_status' => 'passed',
                'verification_status' => 'passed',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put($path.'/verification_receipt.json', json_encode([
            'schema_version' => 'atlas.dev.verification_receipt.v1',
            'run_id' => $runId,
            'provider' => 'hermes_cli',
            'model' => 'hermes_cli:gpt-5.5',
            'changed_files' => $changedFiles,
            'completion' => ['status' => 'passed'],
            'cost' => ['provider_calls' => 1, 'wall_time_ms' => 64000],
            'tests' => [
                ['command' => 'composer test', 'exit_code' => 0, 'ok' => true],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put($path.'/scope_guard_receipt.json', json_encode([
            'schema_version' => 'atlas.dev.scope_guard_receipt.v1',
            'run_id' => $runId,
            'status' => 'passed',
            'observed' => ['changed_files' => $changedFiles],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put($path.'/task_contract.json', json_encode([
            'schema_version' => 'atlas.dev.light_task_contract.v1',
            'run_id' => $runId,
            'provider_lock' => ['provider' => 'hermes_cli', 'model_family' => 'gpt-5.5'],
            'validation_commands' => ['composer test'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put($path.'/provider_call_result.json', json_encode([
            'schema_version' => 'atlas.dev.provider_call_result.v1',
            'run_id' => $runId,
            'actual_provider' => 'hermes_cli',
            'actual_model_family' => 'gpt-5.5',
            'duration_ms' => 64000,
            'ok' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        File::put($path.'/diff_parse_result.json', json_encode([
            'schema_version' => 'atlas.dev.diff_parse_result.v1',
            'run_id' => $runId,
            'changed_files' => $changedFiles,
            'mode' => 'patch',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string,mixed>
     */
    private function task(
        string $type,
        int $duration,
        bool $testsPassed = true,
        ?int $baselineDuration = null,
        bool $completeBaseline = true,
        bool $placeholderBaseline = false,
    ): array {
        return [
            'id' => $type.'-medium-001',
            'task_type' => $type,
            'title' => ucfirst($type).' medium task',
            'difficulty' => 'medium',
            'target_path' => match ($type) {
                'bug' => 'app/Services/Ai/Programming/ProgrammingRivalsReadinessService.php',
                'feature' => 'app/Services/Ai/Programming/BenchmarkReadiness/BenchmarkReadinessHarness.php',
                default => 'app/Services/Ai/Programming/AtlasDevBeatTestReportService.php',
            },
            'atlas_dev' => [
                'executed' => true,
                'provider' => 'hermes_cli',
                'model' => 'gpt-5.5',
                'duration_seconds' => $duration,
                'tests_passed' => $testsPassed,
                'scope_passed' => true,
                'changed_files' => ['tests/Feature/Ai/Programming/'.$type.'.php'],
                'validation_commands' => [
                    ['command' => 'php artisan test tests/Feature/Ai/Programming/'.$type.'.php', 'exit_code' => $testsPassed ? 0 : 1],
                ],
                'evidence_refs' => ['receipt:'.$type],
            ],
            'baseline' => $this->baseline($type, $baselineDuration, $completeBaseline, $placeholderBaseline),
        ];
    }

    private function baseline(string $type, ?int $baselineDuration, bool $complete, bool $placeholder): array
    {
        if ($placeholder) {
            return [
                'executed' => true,
                'provider' => 'claude_code',
                'runner' => 'claude_code',
                'model' => '<external runner model name>',
                'duration_ms' => 800000,
                'tests_passed' => true,
                'scope_passed' => true,
                'changed_files' => ['<path changed by external runner>'],
                'validation_commands' => [
                    ['command' => '<exact command run by external runner validation>', 'exit_code' => 0],
                ],
                'evidence_refs' => [
                    '<path or ledger ref to external run transcript/receipt>',
                    '<path or ledger ref to validation output>',
                ],
            ];
        }

        if ($baselineDuration === null) {
            return [
                'executed' => false,
                'provider' => 'claude_code',
            ];
        }

        $baseline = [
            'executed' => true,
            'provider' => 'claude_code',
            'duration_seconds' => $baselineDuration,
            'tests_passed' => true,
            'scope_passed' => true,
        ];

        if (! $complete) {
            return $baseline;
        }

        return [
            ...$baseline,
            'model' => 'claude-sonnet',
            'changed_files' => ['tests/Feature/Ai/Programming/'.$type.'External.php'],
            'validation_commands' => [
                ['command' => 'php artisan test tests/Feature/Ai/Programming/'.$type.'External.php', 'exit_code' => 0],
            ],
            'evidence_refs' => ['external-receipt:'.$type],
        ];
    }
}
