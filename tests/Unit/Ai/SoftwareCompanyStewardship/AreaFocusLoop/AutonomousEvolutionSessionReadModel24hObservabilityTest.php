<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousLoopReceiptIntegrityService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AgenticEngineeringOsFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Loop24hCertificationHarnessService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AutonomousEvolutionSessionReadModel24hObservabilityTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap790_obs_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function readModel(): AutonomousEvolutionSessionReadModelService
    {
        $service = app(AutonomousEvolutionSessionReadModelService::class);
        $service->setStorageRootForTesting($this->tmp.'/sessions');

        return $service;
    }

    private function harness(): Loop24hCertificationHarnessService
    {
        $harness = app(Loop24hCertificationHarnessService::class);
        app(Reliable24hLoopRunnerService::class)->setStorageRootForTesting($this->tmp.'/reliable_24h_loop');
        $this->readModel();

        return $harness;
    }

    /**
     * @param  list<array<string,mixed>>  $cycles
     */
    private function writeSession(array $cycles, string $sessionId = 'aes_test_1'): void
    {
        $path = $this->tmp.'/sessions/agentic_engineering_os.jsonl';
        File::ensureDirectoryExists(dirname($path));
        File::append($path, json_encode([
            'session_id' => $sessionId,
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'status' => 'completed',
            'cycles' => $cycles,
            'generated_at' => '2026-05-27T12:00:00+00:00',
        ], JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /**
     * @param  list<array<string,mixed>>  $records
     */
    private function writeLedger(array $records): void
    {
        $path = $this->tmp.'/reliable_24h_loop/agentic_engineering_os__dev_forge.jsonl';
        File::ensureDirectoryExists(dirname($path));
        foreach ($records as $record) {
            File::append($path, json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL);
        }
    }

    public function test_read_model_resumes_merged_and_blocked_cycles(): void
    {
        $this->writeSession([
            [
                'cycle_id' => 'c_merged',
                'final_status' => 'cycle_completed',
                'merge_performed' => true,
                'owner' => 'atlas_dev',
                'blockers' => [],
                'selected_finding' => ['finding_id' => 'find_merged', 'title' => 'Merged finding'],
                'commit' => ['status' => 'committed', 'commit_hash' => 'abc123merged'],
                'inbox_item_id' => 'inbox_merged',
                'merge_governance' => ['status' => 'merged'],
            ],
            [
                'cycle_id' => 'c_blocked',
                'final_status' => 'blocked',
                'merge_performed' => false,
                'owner' => 'atlas_forge',
                'blockers' => ['full_atlas_forge_flow_required'],
                'selected_finding' => ['finding_id' => 'find_blocked', 'title' => 'Blocked finding'],
                'inbox_item_id' => 'inbox_blocked',
            ],
        ]);

        $this->writeLedger([
            [
                'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
                'cycle_index' => 1,
                'outcome' => 'merged',
                'recorded_at' => '2026-05-27T12:00:00+00:00',
                'cumulative' => ['merges_total' => 1, 'blocked_in_row' => 0],
            ],
            [
                'schema_version' => Reliable24hLoopRunnerService::LEDGER_SCHEMA,
                'cycle_index' => 2,
                'outcome' => 'blocked',
                'blockers' => ['full_atlas_forge_flow_required'],
                'recorded_at' => '2026-05-27T12:30:00+00:00',
                'cumulative' => ['merges_total' => 1, 'blocked_in_row' => 1],
            ],
        ]);

        $payload = $this->readModel()->project24hObservability([
            'area_id' => 'agentic_engineering_os',
            'backlog_snapshot' => ['status' => 'ready', 'available_count' => 2, 'finding_count' => 2],
        ]);

        $this->assertSame(AutonomousEvolutionSessionReadModelService::OBSERVABILITY_SCHEMA, $payload['schema_version']);
        $metrics = $payload['metrics'];
        $this->assertSame(2, $metrics['cycles_total']);
        $this->assertSame(1, $metrics['merges_total']);
        $this->assertSame(1, $metrics['blocked_total']);
        $this->assertArrayHasKey('full_atlas_forge_flow_required', $metrics['blocked_by_reason']);
        $this->assertSame('abc123merged', $metrics['latest_commit']['commit_hash']);
        $this->assertSame('inbox_blocked', $metrics['latest_inbox_item']['inbox_item_id']);
        $this->assertCount(2, $payload['cycle_inbox_summaries']);
    }

    public function test_blocker_aggregation_counts_reasons(): void
    {
        $this->writeSession([
            [
                'cycle_id' => 'c1',
                'final_status' => 'blocked',
                'blockers' => ['validation_failed'],
                'selected_finding' => ['finding_id' => 'f1', 'title' => 'One'],
            ],
            [
                'cycle_id' => 'c2',
                'final_status' => 'blocked',
                'blockers' => ['validation_failed', 'commit_failed'],
                'selected_finding' => ['finding_id' => 'f2', 'title' => 'Two'],
            ],
        ]);

        $payload = $this->readModel()->project24hObservability([
            'backlog_snapshot' => ['available_count' => 1],
        ]);

        $reasons = $payload['metrics']['blocked_by_reason'];
        $this->assertSame(2, $reasons['validation_failed']);
        $this->assertSame(1, $reasons['commit_failed']);
    }

    public function test_24h_readiness_blocked_when_no_backlog(): void
    {
        Config::set('atlas.ai.providers.cursor_cli.enabled', true);
        Config::set('atlas.ai.providers.cursor_cli.model', 'composer-2.5-fast');

        $payload = $this->harness()->assess24hTestReadiness([
            'area_id' => 'agentic_engineering_os',
            'backlog_snapshot' => ['status' => 'ready', 'available_count' => 0, 'finding_count' => 0],
            'finding_scan' => ['docs' => [], 'service_files' => [], 'test_files' => []],
        ]);

        $this->assertSame(Loop24hCertificationHarnessService::STATUS_BLOCKED, $payload['status']);
        $this->assertContains('no_backlog_available', $payload['blockers']);
        $this->assertFalse($payload['checks']['backlog_available']['ok']);
    }

    public function test_24h_readiness_excludes_candidate_quarantine_ledger(): void
    {
        Config::set('atlas.ai.providers.cursor_cli.enabled', true);
        Config::set('atlas.ai.providers.cursor_cli.model', 'composer-2.5-fast');

        $quarantine = app(AreaFocusCandidateQuarantineService::class);
        $quarantine->setStorageRootForTesting($this->tmp.'/sessions/area_focus_candidate_quarantine');
        $quarantine->appendFromCycle(
            'agentic_engineering_os',
            'dev_forge',
            [
                'finding_id' => 'afdf_q',
                'finding_hash' => 'sha256:q',
                'title' => 'Quarantined finding',
            ],
            ['owner_runtime_no_patch_needed'],
        );

        $this->mock(AgenticEngineeringOsFindingEngineService::class, function ($mock): void {
            $mock->shouldReceive('scan')->once()->andReturn([
                'status' => 'ready',
                'findings' => [[
                    'finding_id' => 'afdf_q',
                    'finding_hash' => 'sha256:q',
                    'title' => 'Quarantined finding',
                ]],
            ]);
        });
        app()->forgetInstance(AutonomousEvolutionSessionReadModelService::class);
        $readModel = app(AutonomousEvolutionSessionReadModelService::class);
        $readModel->setStorageRootForTesting($this->tmp.'/sessions');

        $payload = $readModel->project24hObservability([
            'area_id' => 'agentic_engineering_os',
        ]);

        $this->assertSame(0, $payload['backlog']['available_count']);
        $this->assertGreaterThan(0, $payload['backlog']['quarantined_excluded']);
    }

    public function test_24h_readiness_ready_when_real_fixtures_present(): void
    {
        Config::set('atlas.ai.providers.cursor_cli.enabled', true);
        Config::set('atlas.ai.providers.cursor_cli.model', 'composer-2.5-fast');

        $this->writeSession([
            [
                'cycle_id' => 'c_ready',
                'final_status' => 'cycle_completed',
                'merge_performed' => true,
                'owner' => 'atlas_dev',
                'blockers' => [],
                'selected_finding' => ['finding_id' => 'find_ready', 'title' => 'Ready cycle'],
                'commit' => ['status' => 'committed', 'commit_hash' => 'deadbeef'],
                'inbox_item_id' => 'inbox_ready',
                'provider_result' => ['provider' => 'cursor_cli', 'model' => 'composer-2.5-fast'],
                'merge_governance' => ['status' => 'merged'],
            ],
        ]);

        $payload = $this->harness()->assess24hTestReadiness([
            'area_id' => 'agentic_engineering_os',
            'backlog_snapshot' => ['status' => 'ready', 'available_count' => 3, 'finding_count' => 5],
        ]);

        $this->assertSame(Loop24hCertificationHarnessService::STATUS_READY_FOR_24H_TEST, $payload['status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertTrue($payload['checks']['observability_ready']['ok']);
        $this->assertTrue($payload['checks']['merge_governor_ready']['ok']);
    }

    public function test_active_worktrees_only_counts_materialized_existing_paths(): void
    {
        $records = $this->tmp.'/sessions/area_focus_branch_sandboxes/agentic-engineering-os.jsonl';
        $realWorktree = $this->tmp.'/real-worktree';
        File::ensureDirectoryExists($realWorktree);
        File::ensureDirectoryExists(dirname($records));

        foreach ([
            [
                'schema_version' => 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_record.v1',
                'sandbox_id' => 'sandbox_real',
                'status' => 'materialized',
                'materialization' => [
                    'branch_name' => 'atlas/area-focus/agentic_engineering_os/atlas_dev/real',
                    'worktree_path' => $realWorktree,
                ],
                'recorded_at' => '2026-05-27T12:00:00+00:00',
            ],
            [
                'schema_version' => 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_record.v1',
                'sandbox_id' => 'sandbox_empty_path',
                'status' => 'materialized',
                'materialization' => [
                    'branch_name' => 'atlas/area-focus/agentic_engineering_os/atlas_dev/empty',
                    'worktree_path' => '',
                ],
                'recorded_at' => '2026-05-27T12:01:00+00:00',
            ],
            [
                'schema_version' => 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_record.v1',
                'sandbox_id' => 'sandbox_missing_path',
                'status' => 'materialized',
                'materialization' => [
                    'branch_name' => 'atlas/area-focus/agentic_engineering_os/atlas_dev/missing',
                    'worktree_path' => $this->tmp.'/missing-worktree',
                ],
                'recorded_at' => '2026-05-27T12:02:00+00:00',
            ],
            [
                'schema_version' => 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_record.v1',
                'sandbox_id' => 'sandbox_planned',
                'status' => 'planned',
                'materialization' => [
                    'branch_name' => 'atlas/area-focus/agentic_engineering_os/atlas_dev/planned',
                    'worktree_path' => $this->tmp.'/planned-worktree',
                ],
                'recorded_at' => '2026-05-27T12:03:00+00:00',
            ],
        ] as $record) {
            File::append($records, json_encode($record, JSON_UNESCAPED_SLASHES).PHP_EOL);
        }

        $payload = $this->readModel()->project24hObservability([
            'area_id' => 'agentic_engineering_os',
            'backlog_snapshot' => ['status' => 'ready', 'available_count' => 1, 'finding_count' => 1],
        ]);

        $this->assertCount(1, $payload['active_worktrees']);
        $this->assertSame('sandbox_real', $payload['active_worktrees'][0]['sandbox_id']);
        $this->assertSame($realWorktree, $payload['active_worktrees'][0]['worktree_path']);
    }

    public function test_inbox_summary_loads_commit_inbox_and_blocker(): void
    {
        $cycle = [
            'cycle_id' => 'c_sum',
            'final_status' => 'blocked',
            'owner' => 'atlas_forge',
            'blockers' => ['sandbox_materialization_failed'],
            'selected_finding' => ['finding_id' => 'find_sum', 'title' => 'Sandbox failed'],
            'changed_files' => ['app/Foo.php'],
            'commit' => ['status' => 'git_commit_failed', 'commit_hash' => ''],
            'inbox_item_id' => 'inbox_sum_1',
            'provider_result' => ['provider' => 'cursor_cli', 'resolved_model_id' => 'composer-2.5-fast'],
            'validation' => ['passed' => false, 'status' => 'failed', 'commands' => ['php artisan test']],
        ];

        $summary = (new AutonomousLoopReceiptIntegrityService())->cycleInboxSummary($cycle, [
            'session_id' => 'aes_sum',
        ]);

        $this->assertSame(AutonomousLoopReceiptIntegrityService::INBOX_SUMMARY_SCHEMA, $summary['schema_version']);
        $this->assertSame('Sandbox failed', $summary['achado']);
        $this->assertSame('atlas_forge', $summary['owner']);
        $this->assertSame('cursor_cli', $summary['provider']);
        $this->assertSame(['app/Foo.php'], $summary['changed_files']);
        $this->assertSame('inbox_sum_1', $summary['inbox_item_id']);
        $this->assertSame('sandbox_materialization_failed', $summary['blocker']);
        $this->assertNotSame('', $summary['next_operator_action']);
        $this->assertSame('failed', $summary['tests']['status']);
    }
}
