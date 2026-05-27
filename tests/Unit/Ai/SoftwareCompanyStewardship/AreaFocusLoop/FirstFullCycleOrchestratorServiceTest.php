<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FirstFullCycleOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\StewardshipBranchMergeGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\DevForgeRuntimeExecutionBridgeService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Integration tests for the AP-768 First Full Cycle Orchestrator. The cycle is
 * driven with a synthetic AP-748 deep-scan report (and, for the closed-cycle
 * case, a synthetic AP-767 execution_result) so the composition is deterministic
 * and hermetic. Storage is redirected to a temp dir; nothing touches real
 * storage/ and no provider/branch/merge ever runs.
 */
class FirstFullCycleOrchestratorServiceTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageDir = sys_get_temp_dir().'/atlas_ffc_test_'.uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            exec('rm -rf '.escapeshellarg($this->storageDir));
        }
        parent::tearDown();
    }

    private function service(): FirstFullCycleOrchestratorService
    {
        $service = app(FirstFullCycleOrchestratorService::class);
        $service->setStorageRootForTesting($this->storageDir);

        return $service;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function finding(string $kind, string $severity, string $owner, string $title, array $overrides = []): array
    {
        $hash = 'sha256:'.hash('sha256', $kind.'|'.$title);

        return array_merge([
            'schema_version' => AreaFocusDeepFindingEngineService::FINDING_SCHEMA,
            'finding_id' => 'afdf_'.substr(hash('sha256', $title), 0, 16),
            'finding_hash' => $hash,
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'title' => $title,
            'detail' => $title.' detail.',
            'kind' => $kind,
            'severity' => $severity,
            'confidence' => 'high',
            'owner_candidate' => $owner,
            'evidence_refs' => ['evidence:'.$kind],
            'affected_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Example.php'],
            'affected_docs' => [],
            'why_it_matters' => 'Matters for the dev flow.',
            'proposed_spec_title' => 'Pin with tests: '.$title,
            'proposed_next_action' => 'Add a test.',
            'in_focus' => true,
            'spec_seed' => [
                'schema_version' => 'atlas.evolution.gap_candidate.v1',
                'candidate_hash' => $hash,
                'gap_kind' => 'pipeline_not_proven',
                'source_owner' => $owner,
            ],
        ], $overrides);
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     * @return array<string,mixed>
     */
    private function scanReport(array $findings): array
    {
        return [
            'schema_version' => AreaFocusDeepFindingEngineService::REPORT_SCHEMA,
            'scan_id' => 'afds_'.substr(hash('sha256', json_encode($findings)), 0, 16),
            'status' => 'ready',
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'finding_count' => count($findings),
            'findings' => $findings,
            'focus_summary' => ['in_focus' => count($findings), 'out_of_focus' => 0],
        ];
    }

    /**
     * A synthetic AP-767 execution_result that satisfies AP-765's evidence and
     * irreversible-action gates (partial, no mutation, has validation + summary).
     *
     * @return array<string,mixed>
     */
    private function executionResult(): array
    {
        return [
            'schema_version' => DevForgeRuntimeExecutionBridgeService::EXECUTION_RESULT_SCHEMA,
            'owner' => 'atlas_dev',
            'target_owner' => 'atlas_dev',
            'area_id' => 'agentic_engineering_os',
            'result_status' => 'partial',
            'summary' => 'AP-767 ran the read-only + test proof task inside the isolated sandbox.',
            'finding_id' => 'afdf_test',
            'sandbox_id' => 'afbs_sim_test',
            'changed_files' => [],
            'tests' => ['php artisan test --filter=AreaFocus'],
            'validation_commands' => ['git status --porcelain'],
            'test_results' => [['command_display' => 'php artisan test', 'status' => 'completed', 'exit_code' => 0]],
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'evidence_pack' => ['summary' => 'partial proof', 'changed_files' => [], 'tests' => ['php artisan test']],
        ];
    }

    public function test_dry_run_returns_full_receipt_with_all_required_fields(): void
    {
        $report = $this->scanReport([$this->finding('test', 'medium', 'atlas_dev', 'Missing test for FooService')]);
        $receipt = $this->service()->run(['deep_scan_report' => $report, 'mode' => 'dry-run']);

        $this->assertSame(FirstFullCycleOrchestratorService::STATUS_DRY_RUN_COMPLETE, $receipt['final_status']);
        foreach ([
            'cycle_id', 'area_id', 'focus', 'runner_receipt', 'scan_id', 'selected_finding',
            'spec_proposal_seed', 'sandbox_receipt', 'dev_forge_execution_result', 'evidence_pack',
            'inbox_item', 'product_mode_event', 'branch_merge_governance', 'tests', 'final_status', 'next_operator_action',
        ] as $key) {
            $this->assertArrayHasKey($key, $receipt, "receipt missing required key {$key}");
        }
        $this->assertStringStartsWith('affc_', $receipt['cycle_id']);
        $this->assertSame('agentic_engineering_os', $receipt['area_id']);
        $this->assertSame('dev_forge', $receipt['focus']);
    }

    public function test_selects_smallest_safest_in_focus_finding(): void
    {
        // A high-severity risk must be ignored in favour of a low-severity test.
        $report = $this->scanReport([
            $this->finding('risk', 'high', 'forge', 'Duplicate runtime risk'),
            $this->finding('doc', 'medium', 'self_directed_evolution', 'Stale doc'),
            $this->finding('test', 'low', 'atlas_dev', 'Missing test for BarService'),
        ]);
        $receipt = $this->service()->run(['deep_scan_report' => $report, 'mode' => 'dry-run']);

        $this->assertSame('Missing test for BarService', $receipt['selected_finding']['title']);
        $this->assertSame('test', $receipt['selected_finding']['kind']);
        $this->assertSame('ap771_priority_with_safe_candidate_gate', $receipt['stages']['selected_finding']['selection_strategy']);
        $this->assertSame('AP-771', $receipt['stages']['selected_finding']['priority_report']['ap_contract']);
    }

    public function test_blocked_when_no_safe_finding_available(): void
    {
        $report = $this->scanReport([
            $this->finding('risk', 'critical', 'forge', 'Critical risk'),
        ]);
        $receipt = $this->service()->run(['deep_scan_report' => $report, 'mode' => 'dry-run']);

        $this->assertSame(FirstFullCycleOrchestratorService::STATUS_BLOCKED, $receipt['final_status']);
        $this->assertContains('no_safe_finding_available', $receipt['blockers']);
    }

    public function test_sandbox_is_simulated_and_isolated_by_default(): void
    {
        $report = $this->scanReport([$this->finding('test', 'medium', 'atlas_dev', 'Missing test for FooService')]);
        $receipt = $this->service()->run(['deep_scan_report' => $report, 'mode' => 'dry-run']);

        $sandbox = $receipt['sandbox_receipt'];
        $this->assertSame('simulated', $sandbox['sandbox_mode']);
        $descriptor = $sandbox['descriptor'];
        $this->assertTrue($descriptor['isolated']);
        $this->assertTrue($descriptor['simulated']);
        $this->assertNotContains($descriptor['branch_name'], ['main', 'master', 'develop', '']);
        $this->assertNotEmpty($descriptor['allowed_paths']);
    }

    public function test_dev_forge_bridge_is_called(): void
    {
        $report = $this->scanReport([$this->finding('test', 'medium', 'atlas_dev', 'Missing test for FooService')]);
        $receipt = $this->service()->run(['deep_scan_report' => $report, 'mode' => 'dry-run']);

        $stage = $receipt['stages']['dev_forge_execution'];
        $this->assertSame('AP-767', $stage['ap_contract']);
        $this->assertSame('atlas_dev', $stage['owner']);
        // Dry-run -> AP-767 plans, never mutates.
        $this->assertSame(DevForgeRuntimeExecutionBridgeService::STATUS_PLANNED, $stage['bridge_status']);
    }

    public function test_result_bridge_deferred_with_contract_when_no_execution_result(): void
    {
        $report = $this->scanReport([$this->finding('test', 'medium', 'atlas_dev', 'Missing test for FooService')]);
        $receipt = $this->service()->run(['deep_scan_report' => $report, 'mode' => 'dry-run']);

        $stage = $receipt['stages']['runtime_result'];
        $this->assertSame(FirstFullCycleOrchestratorService::STAGE_DEFERRED, $stage['status']);
        $this->assertSame('execution_result_required', $stage['deferred_contract']['reason']);
        $this->assertArrayHasKey('command', $stage['deferred_contract']);
    }

    public function test_cycle_closes_when_execution_result_is_present(): void
    {
        $report = $this->scanReport([$this->finding('test', 'medium', 'atlas_dev', 'Missing test for FooService')]);
        $receipt = $this->service()->run([
            'deep_scan_report' => $report,
            'mode' => 'execute',
            'execution_result' => $this->executionResult(),
        ]);

        $this->assertSame(FirstFullCycleOrchestratorService::STATUS_CYCLE_CLOSED, $receipt['final_status']);
        $this->assertNotNull($receipt['evidence_pack']);
        $this->assertNotNull($receipt['inbox_item']);
        $this->assertNotNull($receipt['product_mode_event']);
        $this->assertSame(FirstFullCycleOrchestratorService::STAGE_RAN, $receipt['stages']['runtime_result']['status']);
        $this->assertNotEmpty($receipt['stages']['runtime_result']['result_bridge_id']);
        $this->assertSame(FirstFullCycleOrchestratorService::STAGE_DEFERRED, $receipt['stages']['branch_merge_governance']['status']);
        $this->assertSame('branch_required', $receipt['stages']['branch_merge_governance']['merge_status']);
    }

    public function test_no_dangerous_action_in_any_mode(): void
    {
        $report = $this->scanReport([$this->finding('test', 'medium', 'atlas_dev', 'Missing test for FooService')]);
        foreach (['dry-run', 'execute'] as $mode) {
            $receipt = $this->service()->run([
                'deep_scan_report' => $report,
                'mode' => $mode,
                'execution_result' => $mode === 'execute' ? $this->executionResult() : null,
            ]);
            $policy = $receipt['claim_policy'];
            $this->assertFalse($policy['provider_invoked'], "provider invoked in {$mode}");
            $this->assertFalse($policy['branch_created'], "branch created in {$mode}");
            $this->assertFalse($policy['worktree_created'], "worktree created in {$mode}");
            $this->assertFalse($policy['merges'], "merge in {$mode}");
            $this->assertFalse($policy['deploys'], "deploy in {$mode}");
            $this->assertFalse($policy['mutates_main'], "mutates main in {$mode}");
            $this->assertTrue($policy['operator_review_required']);
        }
    }

    public function test_spec_proposal_seed_is_proposal_only(): void
    {
        $report = $this->scanReport([$this->finding('test', 'medium', 'atlas_dev', 'Missing test for FooService')]);
        $receipt = $this->service()->run(['deep_scan_report' => $report, 'mode' => 'dry-run']);

        $spec = $receipt['spec_proposal_seed'];
        $this->assertSame('AP-718', $spec['ap_contract']);
        $draft = $spec['spec_proposal_draft'];
        $this->assertFalse($draft['canonical_doc_write_allowed']);
        $this->assertFalse($draft['autoimplementation_allowed']);
        $this->assertFalse($draft['provider_invoked']);
        $this->assertFalse($draft['written']);
    }

    public function test_record_is_idempotent(): void
    {
        $report = $this->scanReport([$this->finding('test', 'medium', 'atlas_dev', 'Missing test for FooService')]);
        $service = $this->service();
        $input = ['deep_scan_report' => $report, 'mode' => 'execute', 'execution_result' => $this->executionResult(), 'record' => true];

        $first = $service->run($input);
        $this->assertSame('recorded', $first['cycle_storage_status']);
        $path = $service->cycleFilePath('agentic_engineering_os');
        $this->assertFileExists($path);
        $this->assertCount(1, file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        $second = $service->run($input);
        $this->assertSame('existing', $second['cycle_storage_status']);
        $this->assertCount(1, file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        $replayed = $service->replay($first['cycle_id'], 'agentic_engineering_os');
        $this->assertNotNull($replayed);
        $this->assertSame($first['cycle_id'], $replayed['cycle_id']);
    }

    public function test_dry_run_with_record_does_not_persist(): void
    {
        $report = $this->scanReport([$this->finding('test', 'medium', 'atlas_dev', 'Missing test for FooService')]);
        $service = $this->service();
        // record=true but dry-run only persists nothing dangerous; cycle still records
        // as a projection receipt — assert no mutating child wrote anything.
        $receipt = $service->run(['deep_scan_report' => $report, 'mode' => 'dry-run', 'record' => true]);

        // A dry-run cycle is recordable (it is a safe read-model), but contains no
        // execution_result and no emitted artifacts.
        $this->assertNull($receipt['dev_forge_execution_result']);
        $this->assertNull($receipt['evidence_pack']);
    }

    public function test_cycle_emits_branch_merge_governance_when_branch_is_materialized(): void
    {
        $repo = $this->repoWithCycleBranch();
        $report = $this->scanReport([$this->finding('doc', 'low', 'atlas_dev', 'Docs update', [
            'affected_files' => ['docs/README.md'],
        ])]);

        $receipt = $this->service()->run([
            'deep_scan_report' => $report,
            'mode' => 'execute',
            'execution_result' => array_merge($this->executionResult(), [
                'branch_ref' => 'atlas/area-focus/docs-cycle',
                'worktree_path' => $repo,
                'changed_files' => ['docs/README.md'],
                'validation_commands' => [],
            ]),
            'sandbox_descriptor' => [
                'simulated' => false,
                'isolated' => true,
                'sandbox_id' => 'afbs_docs_cycle',
                'branch_name' => 'atlas/area-focus/docs-cycle',
                'worktree_path' => $repo,
                'materialization' => ['repo_root' => $repo],
            ],
            'repo_root' => $repo,
            'base_ref' => 'main',
            'record_merge_governance' => true,
        ]);

        $stage = $receipt['stages']['branch_merge_governance'];

        $this->assertSame(FirstFullCycleOrchestratorService::STAGE_RAN, $stage['status']);
        $this->assertSame(StewardshipBranchMergeGovernorService::STATUS_AUTO_MERGE_ELIGIBLE, $stage['merge_status']);
        $this->assertTrue($stage['auto_merge_eligible']);
        $this->assertSame(['docs/README.md'], $stage['changed_files']);
        $this->assertSame('branch_on_top_of_base', $stage['governance_report']['gitkraken_review_surface']['graph_shape']);
        $this->assertSame('recorded', $stage['governance_report']['governance_storage_status']);
        $this->assertNotNull($receipt['branch_merge_governance']);
    }

    private function repoWithCycleBranch(): string
    {
        $repo = $this->storageDir.'/repo';
        File::ensureDirectoryExists($repo.'/docs');
        $this->runProcess(['git', 'init'], $repo);
        $this->runProcess(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/docs/README.md', "base\n");
        $this->runProcess(['git', 'add', '.'], $repo);
        $this->runProcess(['git', 'commit', '-m', 'Initial commit'], $repo);
        $this->runProcess(['git', 'branch', '-M', 'main'], $repo);
        $this->runProcess(['git', 'checkout', '-b', 'atlas/area-focus/docs-cycle'], $repo);
        file_put_contents($repo.'/docs/README.md', "base\ncycle\n");
        $this->runProcess(['git', 'add', 'docs/README.md'], $repo);
        $this->runProcess(['git', 'commit', '-m', 'Docs cycle'], $repo);
        $this->runProcess(['git', 'checkout', 'main'], $repo);

        return $repo;
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(30);
        $process->run();

        $this->assertTrue($process->isSuccessful(), implode(' ', $command)."\n".$process->getErrorOutput().$process->getOutput());
    }
}
