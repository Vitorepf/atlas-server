<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class StewardshipOwnerSandboxRuntimeRunnerServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap759_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipOwnerSandboxRuntimeRunnerService
    {
        $service = app(StewardshipOwnerSandboxRuntimeRunnerService::class);
        $service->setStorageRootForTesting($this->tmp.'/runs');

        return $service;
    }

    public function test_requires_ap758_execution_adapter_report(): void
    {
        $report = $this->service()->project([
            'runtime_command_receipt' => $this->commandReceipt(),
            'execute' => true,
        ]);

        $this->assertSame(StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('ap758_execution_required', $report['reason']);
        $this->assertFalse($report['claim_policy']['runtime_command_executed_by_runner']);
    }

    public function test_requires_explicit_command_receipt(): void
    {
        $report = $this->service()->project([
            'execution_adapter_report' => $this->ap758Execution(),
            'execute' => true,
        ]);

        $this->assertSame(StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('runtime_command_receipt_required', $report['reason']);
        $this->assertContains('runtime_command_decision_required', $report['runtime_command_receipt_check']['missing']);
        $this->assertContains('owner_runtime_command_required', $report['runtime_command_receipt_check']['missing']);
    }

    public function test_blocks_non_allowlisted_runtime_command(): void
    {
        $report = $this->service()->project([
            'execution_adapter_report' => $this->ap758Execution(),
            'runtime_command_receipt' => $this->commandReceipt([
                'command' => [PHP_BINARY, 'artisan', 'atlas:memory:list'],
            ]),
            'execute' => true,
        ]);

        $this->assertSame(StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('runtime_command_not_allowed', $report['reason']);
        $this->assertContains('artisan_command_not_allowed_for_owner:atlas:memory:list', $report['command_check']['violations']);
    }

    public function test_plans_owner_command_without_execution_by_default(): void
    {
        $report = $this->service()->project([
            'execution_adapter_report' => $this->ap758Execution(),
            'runtime_command_receipt' => $this->commandReceipt(),
        ]);

        $this->assertSame(StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED, $report['status']);
        $this->assertSame('owner_sandbox_runtime_runner', $report['mode']);
        $this->assertNull($report['owner_result']);
        $this->assertFalse($report['claim_policy']['runtime_command_executed_by_runner']);
        $this->assertTrue($report['command_check']['ok']);
    }

    public function test_allows_atlas_dev_senior_loop_owner_command(): void
    {
        $execution = $this->ap758Execution();
        $workspace = $execution['sandbox_check']['worktree_path'].'/storage/atlas/ap759-senior-loop-fixture';
        $report = $this->service()->project([
            'execution_adapter_report' => $execution,
            'runtime_command_receipt' => $this->commandReceipt([
                'command' => [
                    PHP_BINARY,
                    'artisan',
                    'atlas:dev:senior-loop:run',
                    '--workspace='.$workspace,
                    '--create-fixture-workspace',
                    '--keep-workspace',
                    '--json',
                ],
            ]),
        ]);

        $this->assertSame(StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED, $report['status']);
        $this->assertTrue($report['command_check']['ok']);
        $this->assertTrue($report['command_preparation']['prepared']);
        $this->assertSame('atlas:dev:senior-loop:run', $report['command_check']['artisan_command']);
    }

    public function test_allows_canonical_artisan_path_for_worktrees_without_vendor(): void
    {
        $execution = $this->ap758Execution();
        $workspace = $execution['sandbox_check']['worktree_path'].'/storage/atlas/ap759-senior-loop-fixture';
        $report = $this->service()->project([
            'execution_adapter_report' => $execution,
            'runtime_command_receipt' => $this->commandReceipt([
                'command' => [
                    PHP_BINARY,
                    base_path('artisan'),
                    'atlas:dev:senior-loop:run',
                    '--workspace='.$workspace,
                    '--create-fixture-workspace',
                    '--keep-workspace',
                    '--json',
                ],
            ]),
        ]);

        $this->assertSame(StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED, $report['status']);
        $this->assertTrue($report['command_check']['ok']);
        $this->assertSame('atlas:dev:senior-loop:run', $report['command_check']['artisan_command']);
    }

    public function test_executes_atlas_dev_owner_command_inside_ap756_worktree_and_emits_ap750_result(): void
    {
        $execution = $this->ap758Execution(git: true);
        $report = $this->service()->project([
            'execution_adapter_report' => $execution,
            'runtime_command_receipt' => $this->commandReceipt(),
            'execute' => true,
        ]);

        $this->assertSame(StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY, $report['status']);
        $this->assertSame('completed', $report['command_result']['status']);
        $this->assertSame('completed', $report['owner_result']['result_status']);
        $this->assertTrue($report['owner_result']['runtime_execution_started']);
        $this->assertTrue($report['owner_result']['provider_invoked']);
        $this->assertContains('app/Services/Ai/SoftwareCompanyStewardship/Ap759Fixture.php', $report['owner_result']['changed_files']);
        $this->assertStringContainsString('atlas-dev-run-worker-ok', $report['owner_result']['evidence_pack']['stdout_excerpt']);

        $bridge = app(StewardshipOwnerRuntimeResultBridgeService::class)->project([
            'consumption_report' => $this->ap749Consumption(),
            'owner_result' => $report['owner_result'],
        ]);

        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_READY, $bridge['status']);
        $this->assertTrue($bridge['identity_check']['ok']);
        $this->assertTrue($bridge['evidence_check']['ok']);
        $this->assertTrue($bridge['isolation_check']['ok']);
    }

    public function test_failed_owner_command_still_emits_failed_result_for_ap750_review(): void
    {
        $report = $this->service()->project([
            'execution_adapter_report' => $this->ap758Execution(git: true),
            'runtime_command_receipt' => $this->commandReceipt([
                'command' => [PHP_BINARY, 'artisan', 'atlas:dev:run-worker', 'fail'],
            ]),
            'execute' => true,
        ]);

        $this->assertSame(StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY, $report['status']);
        $this->assertSame('failed', $report['command_result']['status']);
        $this->assertSame(7, $report['command_result']['exit_code']);
        $this->assertSame('failed', $report['owner_result']['result_status']);

        $bridge = app(StewardshipOwnerRuntimeResultBridgeService::class)->project([
            'consumption_report' => $this->ap749Consumption(),
            'owner_result' => $report['owner_result'],
        ]);

        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_READY, $bridge['status']);
        $this->assertSame('failed', $bridge['owner_result_status']);
    }

    public function test_record_run_is_append_only_and_prevents_duplicate_execution(): void
    {
        $input = [
            'execution_adapter_report' => $this->ap758Execution(git: true),
            'runtime_command_receipt' => $this->commandReceipt(),
            'execute' => true,
            'record_run' => true,
        ];

        $first = $this->service()->project($input);
        $second = $this->service()->project($input);
        $counterPath = $input['execution_adapter_report']['sandbox_check']['worktree_path'].'/app/Services/Ai/SoftwareCompanyStewardship/ap759-counter.txt';

        $this->assertSame(StewardshipOwnerSandboxRuntimeRunnerService::STATUS_RECORDED, $first['status']);
        $this->assertSame('recorded', $first['run_storage_status']);
        $this->assertSame('existing', $second['run_storage_status']);
        $this->assertSame($first['owner_sandbox_run_id'], $second['owner_sandbox_run_id']);
        $this->assertSame('1', trim((string) file_get_contents($counterPath)));
        $this->assertFileExists($this->service()->runFilePath('agentic_engineering_os'));
        $this->assertCount(1, file($this->service()->runFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    /**
     * @return array<string,mixed>
     */
    private function ap758Execution(bool $git = false): array
    {
        $worktree = $this->prepareWorktree($git);

        return [
            'schema_version' => StewardshipOwnerRuntimeExecutionAdapterService::REPORT_SCHEMA,
            'ap_contract' => 'AP-758',
            'status' => StewardshipOwnerRuntimeExecutionAdapterService::STATUS_READY,
            'mode' => 'owner_runtime_execution_adapter',
            'area_id' => 'agentic_engineering_os',
            'portfolio_id' => 'atlas_software_company',
            'owner_execution_id' => 'afexec_fixture',
            'consumption_id' => 'afcons_fixture',
            'release_id' => 'afrel_fixture',
            'queue_item_id' => 'afq_fixture',
            'target_owner' => 'atlas_dev',
            'sandbox_check' => [
                'schema_version' => 'atlas.software_company_stewardship.ap758_sandbox_check.v1',
                'ok' => true,
                'sandbox_id' => 'afsb_fixture',
                'branch_name' => 'area-focus/agentic-engineering-os/fixture',
                'worktree_path' => $worktree,
                'worktree_path_hash' => hash('sha256', $worktree),
                'violations' => [],
            ],
            'runtime_invocation' => [
                'schema_version' => StewardshipOwnerRuntimeExecutionAdapterService::INVOCATION_SCHEMA,
                'ap_contract' => 'AP-758',
                'target_owner' => 'atlas_dev',
                'driver_mode' => 'atlas_dev_runtime_projection',
                'status' => 'projected',
                'branch_sandbox' => [
                    'sandbox_id' => 'afsb_fixture',
                    'branch_name' => 'area-focus/agentic-engineering-os/fixture',
                    'worktree_path' => $worktree,
                    'worktree_path_hash' => hash('sha256', $worktree),
                    'binding_ok' => true,
                ],
            ],
            'owner_result' => [
                'schema_version' => StewardshipOwnerRuntimeResultBridgeService::RESULT_SCHEMA,
                'result_id' => 'afexecres_fixture',
                'source_ap_contract' => 'AP-758',
                'consumption_id' => 'afcons_fixture',
                'release_id' => 'afrel_fixture',
                'queue_item_id' => 'afq_fixture',
                'target_owner' => 'atlas_dev',
                'result_status' => 'partial',
                'runtime_execution_started' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ap749Consumption(): array
    {
        return [
            'schema_version' => AreaFocusOwnerQueueConsumptionGateService::RECORD_SCHEMA,
            'ap_contract' => 'AP-749',
            'status' => AreaFocusOwnerQueueConsumptionGateService::STATUS_RECORDED,
            'area_id' => 'agentic_engineering_os',
            'portfolio_id' => 'atlas_software_company',
            'consumption_id' => 'afcons_fixture',
            'release_id' => 'afrel_fixture',
            'queue_item_id' => 'afq_fixture',
            'target_owner' => 'atlas_dev',
            'target_runtime_schema' => AtlasDevRuntimeService::SCHEMA_VERSION,
            'branch_isolation' => [
                'ok' => true,
                'allowed_paths' => ['app/Services/Ai/SoftwareCompanyStewardship'],
                'forbidden_paths' => ['.env', 'secrets'],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function commandReceipt(array $overrides = []): array
    {
        return array_replace_recursive([
            'decision' => 'execute_owner_runtime_in_sandbox',
            'operator_actor' => 'operator',
            'target_owner' => 'atlas_dev',
            'target_owner_execution_id' => 'afexec_fixture',
            'target_consumption_id' => 'afcons_fixture',
            'allow_runtime_command_execution' => true,
            'provider_execution_authorized' => true,
            'budget_approved' => true,
            'timeout_seconds' => 30,
            'command' => [PHP_BINARY, 'artisan', 'atlas:dev:run-worker', 'fixture'],
            'validation_commands' => ['php artisan test --filter=SoftwareCompanyStewardship'],
        ], $overrides);
    }

    private function prepareWorktree(bool $git): string
    {
        $worktree = $this->tmp.'/worktree_'.uniqid('', false);
        File::ensureDirectoryExists($worktree.'/app/Services/Ai/SoftwareCompanyStewardship');
        File::put($worktree.'/app/Services/Ai/SoftwareCompanyStewardship/.keep', "tracked\n");
        File::put($worktree.'/artisan', <<<'PHP'
<?php
$command = $argv[1] ?? '';
$arg = $argv[2] ?? '';
if ($command === 'atlas:dev:run-worker' && $arg === 'fail') {
    fwrite(STDERR, 'atlas-dev-run-worker-failed');
    exit(7);
}
if ($command === 'atlas:dev:run-worker') {
    @mkdir(__DIR__.'/app/Services/Ai/SoftwareCompanyStewardship', 0775, true);
    $counter = __DIR__.'/app/Services/Ai/SoftwareCompanyStewardship/ap759-counter.txt';
    $count = is_file($counter) ? (int) trim((string) file_get_contents($counter)) : 0;
    file_put_contents($counter, (string) ($count + 1));
    file_put_contents(__DIR__.'/app/Services/Ai/SoftwareCompanyStewardship/Ap759Fixture.php', "<?php\n// AP-759 fixture\n");
    echo 'atlas-dev-run-worker-ok';
    exit(0);
}
fwrite(STDERR, 'unknown owner command');
exit(2);
PHP);

        if ($git) {
            $this->runProcess(['git', 'init'], $worktree);
            $this->runProcess(['git', 'add', 'artisan', 'app/Services/Ai/SoftwareCompanyStewardship/.keep'], $worktree);
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
