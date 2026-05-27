<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\DevForgeRuntimeExecutionBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class DevForgeRuntimeExecutionBridgeServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap767_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): DevForgeRuntimeExecutionBridgeService
    {
        $service = new DevForgeRuntimeExecutionBridgeService;
        $service->setStorageRootForTesting($this->tmp.'/executions');

        return $service;
    }

    /**
     * @return array<string,mixed>
     */
    private function sandbox(string $worktreePath = '/nonexistent/worktree', bool $isolated = true, string $branch = 'area-focus/agentic-engineering-os/ap767'): array
    {
        return [
            'sandbox_id' => 'afsb_fixture',
            'branch_name' => $branch,
            'worktree_path' => $worktreePath,
            'base_ref' => 'HEAD',
            'isolated' => $isolated,
            'allowed_paths' => ['app/Services/Ai/SoftwareCompanyStewardship'],
        ];
    }

    public function test_blocked_without_sandbox(): void
    {
        $report = $this->service()->execute([
            'owner' => 'atlas_dev',
            'area_id' => 'agentic_engineering_os',
            'handoff_id' => 'ho_1',
        ]);

        $this->assertSame(DevForgeRuntimeExecutionBridgeService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('sandbox_required', $report['reason']);
        $this->assertContains('sandbox_required', $report['consumption_gate']['violations']);
        $this->assertFalse($report['claim_policy']['provider_invoked_by_bridge']);
        $this->assertTrue($report['operator_review_required']);
    }

    public function test_blocked_on_main_or_without_isolation(): void
    {
        $onMain = $this->service()->execute([
            'owner' => 'forge',
            'sandbox' => $this->sandbox(branch: 'main'),
            'handoff_id' => 'ho_1',
        ]);
        $this->assertSame(DevForgeRuntimeExecutionBridgeService::STATUS_BLOCKED, $onMain['status']);
        $this->assertSame('sandbox_not_isolated_branch_on_main', $onMain['reason']);

        $notIsolated = $this->service()->execute([
            'owner' => 'forge',
            'sandbox' => $this->sandbox(isolated: false),
            'handoff_id' => 'ho_1',
        ]);
        $this->assertSame(DevForgeRuntimeExecutionBridgeService::STATUS_BLOCKED, $notIsolated['status']);
        $this->assertContains('sandbox_not_isolated', $notIsolated['consumption_gate']['violations']);
    }

    public function test_kill_switch_blocks(): void
    {
        $report = $this->service()->execute([
            'owner' => 'atlas_dev',
            'sandbox' => $this->sandbox(),
            'handoff_id' => 'ho_1',
            'kill_switch' => true,
        ]);

        $this->assertSame(DevForgeRuntimeExecutionBridgeService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('kill_switch_active', $report['reason']);
    }

    public function test_dry_run_produces_plan_without_mutation(): void
    {
        $report = $this->service()->execute([
            'owner' => 'atlas_dev',
            'mode' => 'dry-run',
            'sandbox' => $this->sandbox(),
            'handoff_id' => 'ho_42',
        ]);

        $this->assertSame(DevForgeRuntimeExecutionBridgeService::STATUS_PLANNED, $report['status']);
        $this->assertSame('awaiting_execute_authorization', $report['next_state']);
        $this->assertSame([], $report['commands_executed']);
        $this->assertSame([], $report['changed_files']);
        $this->assertSame([], $report['test_results']);
        $this->assertNull($report['execution_result']);
        $this->assertNotEmpty($report['plan']['steps']);
        $this->assertNotEmpty($report['commands_considered']);
        $this->assertTrue($report['operator_review_required']);
        $this->assertFalse($report['claim_policy']['target_repo_mutated_by_bridge']);
        $this->assertFalse($report['claim_policy']['provider_invoked_by_bridge']);
        // Nothing was recorded because record_result defaulted to false.
        $this->assertSame('projected', $report['execution_storage_status']);
    }

    public function test_execute_requires_explicit_allowed_files_or_spec(): void
    {
        $report = $this->service()->execute([
            'owner' => 'atlas_dev',
            'mode' => 'execute',
            'sandbox' => $this->sandbox(),
            'handoff_id' => 'ho_1',
        ]);

        $this->assertSame(DevForgeRuntimeExecutionBridgeService::STATUS_NEEDS_OPERATOR_OR_SPEC, $report['status']);
        $this->assertContains('allowed_files_or_spec_required_for_execute', $report['blockers']);
        $this->assertSame([], $report['commands_executed']);
        $this->assertNull($report['execution_result']);
    }

    public function test_execute_with_scope_but_no_provider_reports_provider_bridge_missing(): void
    {
        $report = $this->service()->execute([
            'owner' => 'atlas_dev',
            'mode' => 'execute',
            'sandbox' => $this->sandbox(),
            'handoff_id' => 'ho_1',
            'allowed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/X.php'],
        ]);

        $this->assertSame(DevForgeRuntimeExecutionBridgeService::STATUS_PROVIDER_BRIDGE_MISSING, $report['status']);
        $this->assertTrue($report['provider_bridge']['provider_bridge_missing']);
        $this->assertContains('provider_bridge_missing', $report['blockers']);
        $this->assertSame([], $report['commands_executed']);
        // Capability slots are mapped without any hard-coded model.
        $this->assertSame('', $report['provider_bridge']['capability_slots']['executor']['model']);
        $this->assertSame('atlas.dev_runtime.v1', $report['provider_bridge']['owner_runtime_schema']);
    }

    public function test_owner_atlas_dev_and_forge_both_route(): void
    {
        $dev = $this->service()->execute([
            'owner' => 'atlas_dev',
            'mode' => 'dry-run',
            'sandbox' => $this->sandbox(),
            'handoff_id' => 'ho_1',
        ]);
        $this->assertSame('atlas_dev', $dev['owner']);
        $this->assertSame('atlas.dev_runtime.v1', $dev['provider_bridge']['owner_runtime_schema']);

        $forge = $this->service()->execute([
            'owner' => 'atlas_forge', // normalised to forge
            'mode' => 'dry-run',
            'sandbox' => $this->sandbox(),
            'finding_id' => 'find_1',
        ]);
        $this->assertSame('forge', $forge['owner']);
        $this->assertSame('atlas.forge.parallel_durable.v1', $forge['provider_bridge']['owner_runtime_schema']);
        $this->assertSame('finding', $forge['source']['kind']);
    }

    public function test_local_deterministic_task_executes_and_writes_idempotent_receipt(): void
    {
        $worktree = $this->makeWorktree();
        $service = $this->service();
        $service->setTaskRunnerForTesting(fn (array $command, string $wt, int $timeout): array => [
            'schema_version' => 'atlas.software_company_stewardship.ap767_command_result.v1',
            'command_display' => implode(' ', $command),
            'status' => 'completed',
            'executed' => true,
            'exit_code' => 0,
            'duration_ms' => 1,
            'stdout_excerpt' => 'ok',
            'stderr_excerpt' => '',
        ]);

        $input = [
            'owner' => 'atlas_dev',
            'mode' => 'execute',
            'sandbox' => $this->sandbox($worktree),
            'handoff_id' => 'ho_1',
            'finding_id' => 'find_1',
            'spec_id' => 'spec_1',
            'allowed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/X.php'],
            'test_commands' => [['php', 'artisan', 'test', '--filter=SoftwareCompanyStewardship']],
            'run_local_deterministic_task' => true,
            'record_result' => true,
        ];

        $first = $service->execute($input);
        $second = $service->execute($input);

        $this->assertSame(DevForgeRuntimeExecutionBridgeService::STATUS_EXECUTED, $first['status']);
        $this->assertSame('ready_for_runtime_result_bridge', $first['next_state']);
        $this->assertNotEmpty($first['commands_executed']);
        $this->assertNotEmpty($first['test_results']);
        $this->assertSame('partial', $first['execution_result']['result_status']);
        $this->assertFalse($first['execution_result']['provider_invoked']);
        $this->assertSame('recorded', $first['execution_storage_status']);
        $this->assertSame('existing', $second['execution_storage_status']);
        $this->assertSame($first['execution_id'], $second['execution_id']);
        $this->assertFileExists($service->executionFilePath('agentic_engineering_os'));
        $this->assertCount(1, file($service->executionFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_execution_result_is_accepted_by_ap765_runtime_result_bridge(): void
    {
        $worktree = $this->makeWorktree();
        $service = $this->service();
        $service->setTaskRunnerForTesting(fn (array $command, string $wt, int $timeout): array => [
            'command_display' => implode(' ', $command),
            'status' => 'completed',
            'executed' => true,
            'exit_code' => 0,
            'stdout_excerpt' => 'ok',
            'stderr_excerpt' => '',
        ]);

        $bridgeReport = $service->execute([
            'owner' => 'atlas_dev',
            'mode' => 'execute',
            'sandbox' => $this->sandbox($worktree),
            'handoff_id' => 'ho_1',
            'finding_id' => 'find_1',
            'allowed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/X.php'],
            'test_commands' => [['php', 'artisan', 'test', '--filter=SoftwareCompanyStewardship']],
            'run_local_deterministic_task' => true,
        ]);

        $this->assertSame(DevForgeRuntimeExecutionBridgeService::STATUS_EXECUTED, $bridgeReport['status']);

        $resultBridge = app(StewardshipRuntimeResultBridgeService::class);
        $resultBridge->setStorageRootForTesting($this->tmp.'/ap765');
        $out = $resultBridge->project([
            'area_id' => 'agentic_engineering_os',
            'owner' => 'atlas_dev',
            'finding_id' => 'find_1',
            'execution_result' => $bridgeReport['execution_result'],
        ]);

        $this->assertSame(StewardshipRuntimeResultBridgeService::STATUS_READY, $out['status']);
        $this->assertSame('partial', $out['result_status']);
    }

    public function test_real_runner_executes_allowlisted_readonly_command_in_sandbox(): void
    {
        $worktree = $this->makeWorktree(git: true);

        $report = $this->service()->execute([
            'owner' => 'atlas_dev',
            'mode' => 'execute',
            'sandbox' => $this->sandbox($worktree),
            'handoff_id' => 'ho_1',
            'allowed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/X.php'],
            'run_local_deterministic_task' => true,
        ]);

        $this->assertSame(DevForgeRuntimeExecutionBridgeService::STATUS_EXECUTED, $report['status']);
        $executed = array_filter($report['commands_executed'], static fn (array $c): bool => (bool) ($c['executed'] ?? false));
        $this->assertNotEmpty($executed, 'expected at least one real read-only command to execute');
        // Read-only inspection must not mutate the worktree.
        $this->assertSame([], $report['changed_files']);
        $this->assertFalse($report['claim_policy']['target_repo_mutated_by_bridge']);
    }

    public function test_command_outside_allowlist_is_rejected_not_run(): void
    {
        $worktree = $this->makeWorktree(git: true);

        $report = $this->service()->execute([
            'owner' => 'atlas_dev',
            'mode' => 'execute',
            'sandbox' => $this->sandbox($worktree),
            'handoff_id' => 'ho_1',
            'allowed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/X.php'],
            // Not a test command; must be rejected (never executed).
            'test_commands' => [['rm', '-rf', '/']],
            'run_local_deterministic_task' => true,
        ]);

        // The rm command is not classified as a test command, so it never reaches test_results.
        foreach ($report['test_results'] as $result) {
            $this->assertNotSame('rm', explode(' ', (string) ($result['command_display'] ?? ''))[0]);
        }
        $this->assertSame([], $report['changed_files']);
    }

    private function makeWorktree(bool $git = false): string
    {
        $worktree = $this->tmp.'/worktree_'.uniqid('', false);
        File::ensureDirectoryExists($worktree.'/app/Services/Ai/SoftwareCompanyStewardship');
        File::put($worktree.'/app/Services/Ai/SoftwareCompanyStewardship/.keep', "tracked\n");

        if ($git) {
            $this->runProcess(['git', 'init'], $worktree);
            $this->runProcess(['git', 'add', '.'], $worktree);
            $this->runProcess(['git', '-c', 'user.email=atlas@example.test', '-c', 'user.name=Atlas Test', 'commit', '-m', 'init'], $worktree);
        }

        return $worktree;
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
