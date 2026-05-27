<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AreaFocusBranchSandboxMaterializerServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap756_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);

        $git = new Process(['git', '--version']);
        $git->run();
        if (! $git->isSuccessful()) {
            $this->markTestSkipped('git binary is required for AP-756 materializer tests.');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AreaFocusBranchSandboxMaterializerService
    {
        $service = app(AreaFocusBranchSandboxMaterializerService::class);
        $service->setStorageRootForTesting($this->tmp.'/sandboxes');

        return $service;
    }

    private function repo(): string
    {
        $repo = $this->tmp.'/repo';
        File::ensureDirectoryExists($repo);
        $this->runProcess(['git', 'init'], $repo);
        $this->runProcess(['git', 'config', 'user.email', 'atlas@example.test'], $repo);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Test'], $repo);
        file_put_contents($repo.'/README.md', "Atlas AP-756 fixture\n");
        $this->runProcess(['git', 'add', 'README.md'], $repo);
        $this->runProcess(['git', 'commit', '-m', 'Initial commit'], $repo);

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

        $this->assertTrue($process->isSuccessful(), implode(' ', $command)."\n".$process->getErrorOutput());
    }

    /**
     * @param  array<string,mixed>  $branchPlan
     * @return array<string,mixed>
     */
    private function preflight(array $branchPlan = []): array
    {
        return [
            'schema_version' => AreaFocusBranchSandboxHandoffService::REPORT_SCHEMA,
            'status' => AreaFocusBranchSandboxHandoffService::STATUS_READY,
            'area_id' => 'agentic_engineering_os',
            'report_hash' => 'sha256:ap726_report',
            'handoffs' => [[
                'handoff_hash' => 'sha256:h1',
                'handoff_id' => 'afho_h1',
                'handoff_status' => AreaFocusBranchSandboxHandoffService::HO_READY,
                'route' => 'atlas_dev',
                'target_owner' => 'atlas_dev',
                'work_order_id' => 'awo_1',
                'work_order_hash' => 'sha256:work_order',
                'decision_id' => 'afod_1',
                'decision_hash' => 'sha256:decision',
                'branch_plan' => array_merge([
                    'schema_version' => AreaFocusBranchSandboxHandoffService::BRANCH_PLAN_SCHEMA,
                    'mode' => AreaFocusBranchSandboxHandoffService::MODE,
                    'proposed_branch_name' => 'area-focus/agentic-engineering-os/atlas-dev/h1',
                    'proposed_base_ref' => 'HEAD',
                    'repo' => 'atlas-server',
                    'allowed_paths' => ['app/Services/Ai/SoftwareCompanyStewardship'],
                    'forbidden_paths' => ['.env'],
                    'branch_created' => false,
                    'worktree_created' => false,
                ], $branchPlan),
            ]],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function receipt(array $overrides = []): array
    {
        return array_merge([
            'decision' => 'materialize_sandbox',
            'operator_actor' => 'vitor',
            'target_handoff_hash' => 'sha256:h1',
            'sandbox_receipt_id' => 'afsbr_fixture',
            'receipt_hash' => 'sha256:sandbox_receipt',
            'sandbox_id' => 'afsb_fixture',
        ], $overrides);
    }

    public function test_dry_run_plans_branch_sandbox_without_creating_worktree(): void
    {
        $repo = $this->repo();

        $report = $this->service()->materialize([
            'preflight_report' => $this->preflight(),
            'sandbox_receipt' => $this->receipt(),
            'repo_root' => $repo,
            'base_ref' => 'HEAD',
        ]);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_PLANNED, $report['status']);
        $this->assertSame('AP-756', $report['ap_contract']);
        $this->assertFalse($report['materialization']['branch_created']);
        $this->assertFalse($report['materialization']['worktree_created']);
        $this->assertFalse($report['claim_policy']['provider_invoked']);
        $this->assertFalse(is_dir($report['materialization']['worktree_path']));
        $this->assertSame('projected', $report['sandbox_storage_status']);
    }

    public function test_blocks_without_explicit_sandbox_receipt(): void
    {
        $report = $this->service()->materialize(['preflight_report' => $this->preflight()]);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('sandbox_receipt_required', $report['reason']);
        $this->assertFalse($report['claim_policy']['branch_created']);
        $this->assertFalse($report['claim_policy']['worktree_created']);
    }

    public function test_blocks_when_receipt_target_does_not_match_ready_handoff(): void
    {
        $report = $this->service()->materialize([
            'preflight_report' => $this->preflight(),
            'sandbox_receipt' => $this->receipt(['target_handoff_hash' => 'sha256:other']),
        ]);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('ready_handoff_not_found', $report['reason']);
        $this->assertContains('sha256:h1', $report['ready_handoff_hashes']);
    }

    public function test_materializes_git_worktree_and_records_idempotently(): void
    {
        $repo = $this->repo();
        $service = $this->service();
        $input = [
            'preflight_report' => $this->preflight(),
            'sandbox_receipt' => $this->receipt(),
            'repo_root' => $repo,
            'base_ref' => 'HEAD',
            'materialize_sandbox' => true,
        ];

        $first = $service->materialize($input);
        $second = $service->materialize($input);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED, $first['status']);
        $this->assertSame('recorded', $first['sandbox_storage_status']);
        $this->assertSame('existing', $second['sandbox_storage_status']);
        $this->assertTrue(is_dir($first['materialization']['worktree_path']));
        $this->assertTrue($first['materialization']['branch_created']);
        $this->assertTrue($first['materialization']['worktree_created']);
        $this->assertSame('AP-770', $first['branch_lifecycle_registry']['ap_contract']);
        $this->assertSame('recorded', $first['branch_lifecycle_registry']['storage_status']);
        $this->assertSame('area-focus/agentic-engineering-os/atlas-dev/h1', $first['materialization']['current_worktree_branch']);
        $this->assertFileExists($service->sandboxRecordPath('agentic_engineering_os'));
        $this->assertCount(1, file($service->sandboxRecordPath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_blocks_parallel_branch_lifecycle_collision_before_git_mutation(): void
    {
        $repo = $this->repo();
        $service = $this->service();
        $service->materialize([
            'preflight_report' => $this->preflight(),
            'sandbox_receipt' => $this->receipt(),
            'repo_root' => $repo,
            'base_ref' => 'HEAD',
            'materialize_sandbox' => true,
        ]);

        $preflight = $this->preflight();
        $preflight['handoffs'][0]['handoff_hash'] = 'sha256:h2';
        $preflight['handoffs'][0]['handoff_id'] = 'afho_h2';

        $blocked = $service->materialize([
            'preflight_report' => $preflight,
            'sandbox_receipt' => $this->receipt([
                'target_handoff_hash' => 'sha256:h2',
                'sandbox_id' => 'afsb_h2',
            ]),
            'repo_root' => $repo,
            'base_ref' => 'HEAD',
            'materialize_sandbox' => true,
        ]);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED, $blocked['status']);
        $this->assertSame('branch_lifecycle_collision', $blocked['reason']);
        $this->assertSame('branch_lifecycle_collision', $blocked['branch_lifecycle_registry']['reason']);
        $this->assertFalse($blocked['claim_policy']['branch_created']);
    }

    public function test_blocks_unsafe_branch_name_before_git_mutation(): void
    {
        $repo = $this->repo();

        $report = $this->service()->materialize([
            'preflight_report' => $this->preflight(['proposed_branch_name' => '../main']),
            'sandbox_receipt' => $this->receipt(),
            'repo_root' => $repo,
            'base_ref' => 'HEAD',
            'materialize_sandbox' => true,
        ]);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED, $report['status']);
        $this->assertContains($report['reason'], ['branch_prefix_not_allowed', 'branch_name_unsafe']);
        $this->assertFalse($report['claim_policy']['branch_created']);
        $this->assertFalse($report['claim_policy']['worktree_created']);
    }

    public function test_claim_policy_allows_only_receipted_sandbox_creation(): void
    {
        $repo = $this->repo();

        $policy = $this->service()->materialize([
            'preflight_report' => $this->preflight(),
            'sandbox_receipt' => $this->receipt(),
            'repo_root' => $repo,
            'base_ref' => 'HEAD',
            'materialize_sandbox' => true,
        ])['claim_policy'];

        $this->assertTrue($policy['requires_operator_sandbox_receipt']);
        $this->assertTrue($policy['branch_created']);
        $this->assertTrue($policy['worktree_created']);
        foreach (['target_repo_mutated', 'fix_applied', 'runtime_execution_started', 'provider_invoked', 'dev_or_forge_dispatched', 'merge_performed', 'deploy_performed', 'pushed_external', 'secret_access', 'destructive_change', 'auto_approved', 'parallel_runtime_created', 'new_os_created'] as $key) {
            $this->assertFalse($policy[$key], "claim_policy.{$key} must be false");
        }
    }

    public function test_cleanup_blocks_unknown_sandbox(): void
    {
        $report = $this->service()->cleanupSandbox([
            'sandbox_id' => 'afsb_does_not_exist',
            'area_id' => 'agentic_engineering_os',
            'remove_sandbox' => true,
        ]);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('sandbox_record_not_found', $report['reason']);
        $this->assertFalse($report['cleaned']);
        $this->assertFalse($report['claim_policy']['sandbox_worktree_removed']);
    }

    public function test_dry_run_cleanup_does_not_remove_worktree(): void
    {
        $repo = $this->repo();
        $service = $this->service();
        $materialized = $this->materializeFixture($service, $repo);
        $worktreePath = (string) $materialized['materialization']['worktree_path'];
        $this->assertTrue(is_dir($worktreePath));

        $cleanup = $service->cleanupSandbox([
            'sandbox_id' => 'afsb_fixture',
            'area_id' => 'agentic_engineering_os',
            'repo_root' => $repo,
        ]);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_PLANNED, $cleanup['status']);
        $this->assertSame('dry_run_cleanup_plan', $cleanup['mode']);
        $this->assertFalse($cleanup['cleaned']);
        $this->assertSame('projected', $cleanup['cleanup_storage_status']);
        $this->assertFalse($cleanup['actions']['worktree_removed']);
        $this->assertTrue(is_dir($worktreePath), 'dry-run cleanup must not remove the worktree');
    }

    public function test_execute_cleanup_removes_worktree_records_event_and_is_idempotent(): void
    {
        $repo = $this->repo();
        $service = $this->service();
        $materialized = $this->materializeFixture($service, $repo);
        $worktreePath = (string) $materialized['materialization']['worktree_path'];

        $first = $service->cleanupSandbox([
            'sandbox_id' => 'afsb_fixture',
            'area_id' => 'agentic_engineering_os',
            'repo_root' => $repo,
            'remove_sandbox' => true,
        ]);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_CLEANED, $first['status']);
        $this->assertTrue($first['cleaned']);
        $this->assertTrue($first['actions']['worktree_removed']);
        $this->assertSame('recorded', $first['cleanup_storage_status']);
        $this->assertFalse(is_dir($worktreePath), 'execute cleanup must remove the worktree');
        $this->assertTrue($first['claim_policy']['sandbox_worktree_removed']);
        $this->assertFalse($first['claim_policy']['target_repo_mutated']);
        $this->assertFalse($first['claim_policy']['product_code_mutated']);
        $this->assertFalse($first['claim_policy']['provider_invoked']);

        // Idempotent: a second cleanup returns the prior recorded event, not a new removal.
        $second = $service->cleanupSandbox([
            'sandbox_id' => 'afsb_fixture',
            'area_id' => 'agentic_engineering_os',
            'repo_root' => $repo,
            'remove_sandbox' => true,
        ]);
        $this->assertSame('existing', $second['cleanup_storage_status']);

        // The materialize record stays; list folds the cleanup lifecycle state.
        $list = $service->listSandboxes('agentic_engineering_os');
        $this->assertSame(1, $list['sandbox_count']);
        $this->assertSame(0, $list['active_sandbox_count']);
        $this->assertSame(1, $list['cleaned_sandbox_count']);
        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_CLEANED, $list['sandboxes'][0]['lifecycle_state']);
        $this->assertTrue($list['sandboxes'][0]['cleanup']['cleaned']);
    }

    public function test_cleanup_blocks_dirty_worktree_without_allow_dirty_flag(): void
    {
        $repo = $this->repo();
        $service = $this->service();
        $materialized = $this->materializeFixture($service, $repo);
        $worktreePath = (string) $materialized['materialization']['worktree_path'];

        // Introduce an uncommitted change inside the isolated worktree.
        file_put_contents($worktreePath.'/scratch.txt', "uncommitted work\n");

        $blocked = $service->cleanupSandbox([
            'sandbox_id' => 'afsb_fixture',
            'area_id' => 'agentic_engineering_os',
            'repo_root' => $repo,
            'remove_sandbox' => true,
        ]);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_BLOCKED, $blocked['status']);
        $this->assertSame('worktree_dirty_requires_allow_dirty_removal', $blocked['reason']);
        $this->assertTrue(is_dir($worktreePath), 'a dirty worktree must not be removed without the explicit flag');

        // With the explicit operator flag the dirty worktree can be removed.
        $forced = $service->cleanupSandbox([
            'sandbox_id' => 'afsb_fixture',
            'area_id' => 'agentic_engineering_os',
            'repo_root' => $repo,
            'remove_sandbox' => true,
            'allow_dirty_removal' => true,
        ]);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_CLEANED, $forced['status']);
        $this->assertTrue($forced['actions']['worktree_removed']);
        $this->assertFalse(is_dir($worktreePath));
    }

    public function test_cleanup_deletes_branch_only_when_requested(): void
    {
        $repo = $this->repo();
        $service = $this->service();
        $this->materializeFixture($service, $repo);

        $cleanup = $service->cleanupSandbox([
            'sandbox_id' => 'afsb_fixture',
            'area_id' => 'agentic_engineering_os',
            'repo_root' => $repo,
            'remove_sandbox' => true,
            'delete_branch' => true,
        ]);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_CLEANED, $cleanup['status']);
        $this->assertTrue($cleanup['actions']['branch_deleted']);

        $branch = new Process(['git', 'show-ref', '--verify', '--quiet', 'refs/heads/area-focus/agentic-engineering-os/atlas-dev/h1'], $repo);
        $branch->run();
        $this->assertFalse($branch->isSuccessful(), 'the sandbox branch should be gone after delete-branch cleanup');
    }

    /**
     * @return array<string,mixed>
     */
    private function materializeFixture(AreaFocusBranchSandboxMaterializerService $service, string $repo): array
    {
        $report = $service->materialize([
            'preflight_report' => $this->preflight(),
            'sandbox_receipt' => $this->receipt(),
            'repo_root' => $repo,
            'base_ref' => 'HEAD',
            'materialize_sandbox' => true,
        ]);

        $this->assertSame(AreaFocusBranchSandboxMaterializerService::STATUS_MATERIALIZED, $report['status']);

        return $report;
    }
}
