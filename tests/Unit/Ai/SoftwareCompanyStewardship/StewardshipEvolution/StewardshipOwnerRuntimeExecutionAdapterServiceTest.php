<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeExecutionAdapterService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class StewardshipOwnerRuntimeExecutionAdapterServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap758_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipOwnerRuntimeExecutionAdapterService
    {
        $service = app(StewardshipOwnerRuntimeExecutionAdapterService::class);
        $service->setStorageRootForTesting($this->tmp.'/executions');

        return $service;
    }

    public function test_requires_ap749_consumption(): void
    {
        $report = $this->service()->project([
            'runtime_start_receipt' => $this->startReceipt(),
        ]);

        $this->assertSame(StewardshipOwnerRuntimeExecutionAdapterService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('ap749_consumption_required', $report['reason']);
        $this->assertFalse($report['claim_policy']['provider_invoked_by_adapter']);
        $this->assertFalse($report['claim_policy']['target_repo_mutated_by_adapter']);
    }

    public function test_requires_ready_ap749_consumption(): void
    {
        $report = $this->service()->project([
            'consumption_report' => $this->consumption([
                'status' => AreaFocusOwnerQueueConsumptionGateService::STATUS_REVIEW_REQUIRED,
            ]),
            'runtime_start_receipt' => $this->startReceipt(),
        ]);

        $this->assertSame(StewardshipOwnerRuntimeExecutionAdapterService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('ap749_consumption_not_ready', $report['reason']);
    }

    public function test_requires_ap757_sandbox_binding(): void
    {
        $report = $this->service()->project([
            'consumption_report' => $this->consumption([
                'sandbox_binding' => [
                    'schema_version' => 'atlas.software_company_stewardship.ap757_sandbox_binding_check.v1',
                    'ok' => false,
                ],
            ]),
            'runtime_start_receipt' => $this->startReceipt(),
        ]);

        $this->assertSame(StewardshipOwnerRuntimeExecutionAdapterService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('ap757_sandbox_binding_required', $report['reason']);
        $this->assertContains('ap757_binding_not_ok', $report['sandbox_check']['violations']);
    }

    public function test_requires_explicit_runtime_start_receipt(): void
    {
        $report = $this->service()->project([
            'consumption_report' => $this->consumption(),
        ]);

        $this->assertSame(StewardshipOwnerRuntimeExecutionAdapterService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('runtime_start_receipt_required', $report['reason']);
        $this->assertContains('runtime_start_decision_required', $report['runtime_start_receipt_check']['missing']);
        $this->assertContains('operator_actor_required', $report['runtime_start_receipt_check']['missing']);
    }

    public function test_atlas_dev_projection_emits_ap750_compatible_owner_result(): void
    {
        $execution = $this->service()->project([
            'consumption_report' => $this->consumption(),
            'runtime_start_receipt' => $this->startReceipt(),
        ]);

        $this->assertSame(StewardshipOwnerRuntimeExecutionAdapterService::REPORT_SCHEMA, $execution['schema_version']);
        $this->assertSame(StewardshipOwnerRuntimeExecutionAdapterService::STATUS_READY, $execution['status']);
        $this->assertSame('atlas_dev', $execution['target_owner']);
        $this->assertSame('atlas_dev_runtime_projection', $execution['runtime_invocation']['driver_mode']);
        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::RESULT_SCHEMA, $execution['owner_result']['schema_version']);
        $this->assertSame('partial', $execution['owner_result']['result_status']);
        $this->assertTrue($execution['owner_result']['runtime_execution_started']);
        $this->assertFalse($execution['owner_result']['provider_invoked']);
        $this->assertFalse($execution['claim_policy']['provider_invoked_by_adapter']);
        $this->assertFalse($execution['claim_policy']['target_repo_mutated_by_adapter']);

        $bridge = app(StewardshipOwnerRuntimeResultBridgeService::class)->project([
            'consumption_report' => $this->consumption(),
            'owner_result' => $execution['owner_result'],
        ]);

        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_READY, $bridge['status']);
        $this->assertTrue($bridge['identity_check']['ok']);
        $this->assertTrue($bridge['evidence_check']['ok']);
        $this->assertTrue($bridge['isolation_check']['ok']);
        $this->assertSame('partial', $bridge['owner_result_status']);
    }

    public function test_forge_projection_reuses_parallel_durable_coordinator(): void
    {
        $execution = $this->service()->project([
            'consumption_report' => $this->forgeConsumption(),
            'runtime_start_receipt' => $this->startReceipt([
                'target_consumption_id' => 'afcons_forge',
                'target_queue_item_id' => 'afq_forge',
            ]),
        ]);

        $this->assertSame(StewardshipOwnerRuntimeExecutionAdapterService::STATUS_READY, $execution['status']);
        $this->assertSame('forge', $execution['target_owner']);
        $this->assertSame('forge_parallel_durable_projection', $execution['runtime_invocation']['driver_mode']);
        $this->assertSame(AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION, $execution['runtime_invocation']['runtime_schema']);
        $this->assertSame(1, $execution['runtime_invocation']['forge_parallel_durable_proposal']['counts']['assignments']);
        $this->assertSame('partial', $execution['owner_result']['result_status']);
        $this->assertFalse($execution['owner_result']['provider_invoked']);
    }

    public function test_record_execution_is_append_only_and_idempotent(): void
    {
        $input = [
            'consumption_report' => $this->consumption(),
            'runtime_start_receipt' => $this->startReceipt(),
            'record_execution' => true,
        ];

        $first = $this->service()->project($input);
        $second = $this->service()->project($input);

        $this->assertSame(StewardshipOwnerRuntimeExecutionAdapterService::STATUS_RECORDED, $first['status']);
        $this->assertSame('recorded', $first['execution_storage_status']);
        $this->assertSame('existing', $second['execution_storage_status']);
        $this->assertSame($first['owner_execution_id'], $second['owner_execution_id']);
        $this->assertFileExists($this->service()->executionFilePath('agentic_engineering_os'));
        $this->assertCount(1, file($this->service()->executionFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function consumption(array $overrides = []): array
    {
        $worktree = $this->tmp.'/worktrees/afsb_fixture';
        File::ensureDirectoryExists($worktree);

        return array_replace_recursive([
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
            'sandbox_binding' => [
                'schema_version' => 'atlas.software_company_stewardship.ap757_sandbox_binding_check.v1',
                'ap_contract' => 'AP-757',
                'ok' => true,
                'sandbox_id' => 'afsb_fixture',
                'branch_name' => 'area-focus/agentic-engineering-os/fixture',
                'worktree_path' => $worktree,
                'worktree_path_hash' => hash('sha256', $worktree),
            ],
            'owner_runtime_input' => [
                'schema_version' => 'atlas.software_company_stewardship.ap749_atlas_dev_owner_runtime_input.v1',
                'target_owner' => 'atlas_dev',
                'target_runtime_schema' => AtlasDevRuntimeService::SCHEMA_VERSION,
                'queue_item_id' => 'afq_fixture',
                'dev_runtime_payload' => [
                    'flow_id' => AtlasDevRuntimeService::FLOW_DEV,
                    'workspace' => 'atlas-server',
                    'expected_files' => ['app/Services/Ai/SoftwareCompanyStewardship'],
                    'artifact_agent_packet' => [
                        'allowed_paths' => ['app/Services/Ai/SoftwareCompanyStewardship'],
                        'forbidden_paths' => ['.env'],
                    ],
                ],
                'branch_sandbox' => [
                    'schema_version' => 'atlas.software_company_stewardship.ap757_owner_runtime_sandbox_binding.v1',
                    'ap_contract' => 'AP-757',
                    'sandbox_id' => 'afsb_fixture',
                    'branch_name' => 'area-focus/agentic-engineering-os/fixture',
                    'worktree_path' => $worktree,
                    'worktree_path_hash' => hash('sha256', $worktree),
                    'handoff_hash' => 'sha256:handoff',
                    'binding_ok' => true,
                ],
            ],
            'claim_policy' => [
                'owner_runtime_start_authorized' => true,
                'ap756_sandbox_bound' => true,
            ],
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeConsumption(): array
    {
        $base = $this->consumption([
            'consumption_id' => 'afcons_forge',
            'queue_item_id' => 'afq_forge',
            'target_owner' => 'forge',
            'target_runtime_schema' => AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION,
        ]);
        $base['owner_runtime_input'] = [
            'schema_version' => 'atlas.software_company_stewardship.ap749_forge_owner_runtime_input.v1',
            'target_owner' => 'forge',
            'target_runtime_schema' => AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION,
            'queue_item_id' => 'afq_forge',
            'forge_ticket' => [
                'ticket_id' => 'afq_forge',
                'locked_paths' => ['app/Services/Ai/SoftwareCompanyStewardship'],
                'priority' => 90,
            ],
            'branch_sandbox' => $base['owner_runtime_input']['branch_sandbox'],
        ];

        return $base;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,string>
     */
    private function startReceipt(array $overrides = []): array
    {
        return array_replace([
            'decision' => 'start_owner_runtime',
            'operator_actor' => 'operator',
            'target_consumption_id' => 'afcons_fixture',
            'target_release_id' => 'afrel_fixture',
            'target_queue_item_id' => 'afq_fixture',
        ], $overrides);
    }
}
