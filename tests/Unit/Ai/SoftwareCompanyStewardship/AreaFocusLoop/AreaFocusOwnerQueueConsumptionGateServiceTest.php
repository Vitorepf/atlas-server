<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AreaFocusOwnerQueueConsumptionGateServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap749_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AreaFocusOwnerQueueConsumptionGateService
    {
        $service = app(AreaFocusOwnerQueueConsumptionGateService::class);
        $service->setStorageRootForTesting($this->tmp.'/consumptions');

        return $service;
    }

    public function test_requires_ap748_visibility_before_owner_consumption(): void
    {
        $report = $this->service()->project([
            'release_report' => $this->releaseReport(),
        ]);

        $this->assertSame(AreaFocusOwnerQueueConsumptionGateService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('ap748_outcome_bridge_required', $report['reason']);
        $this->assertFalse($report['claim_policy']['owner_runtime_start_authorized']);
        $this->assertFalse($report['claim_policy']['provider_invoked_by_gate']);
    }

    public function test_projects_review_required_when_ap748_is_ready_but_execution_receipt_missing(): void
    {
        $report = $this->service()->project([
            'release_report' => $this->releaseReport(),
            'outcome_bridge' => $this->outcomeBridge(),
        ]);

        $this->assertSame(AreaFocusOwnerQueueConsumptionGateService::STATUS_REVIEW_REQUIRED, $report['status']);
        $this->assertSame('operator_execution_receipt_required', $report['execution_receipt_blocker']);
        $this->assertTrue($report['outcome_check']['ok']);
        $this->assertTrue($report['branch_isolation']['ok']);
        $this->assertFalse($report['claim_policy']['owner_runtime_start_authorized']);
    }

    public function test_accepts_operator_receipt_and_prepares_existing_atlas_dev_runtime_input(): void
    {
        $report = $this->service()->project([
            'release_report' => $this->releaseReport(),
            'outcome_bridge' => $this->outcomeBridge(),
            'sandbox_record' => $this->sandboxRecord(),
            'execution_receipt' => $this->executionReceipt(),
        ]);

        $this->assertSame(AreaFocusOwnerQueueConsumptionGateService::STATUS_READY, $report['status']);
        $this->assertSame('accepted', $report['execution_receipt_status']);
        $this->assertSame('atlas_dev', $report['target_owner']);
        $this->assertTrue($report['sandbox_binding']['ok']);
        $this->assertSame('afsb_fixture', $report['sandbox_binding']['sandbox_id']);
        $this->assertSame(AtlasDevRuntimeService::SCHEMA_VERSION, $report['owner_runtime_input']['target_runtime_schema']);
        $this->assertSame('programming.dev', $report['owner_runtime_input']['dev_runtime_payload']['flow_id']);
        $this->assertSame('afsb_fixture', $report['owner_runtime_input']['branch_sandbox']['sandbox_id']);
        $this->assertSame('afsb_fixture', $report['owner_runtime_input']['dev_runtime_payload']['artifact_agent_packet']['branch_sandbox']['sandbox_id']);
        $this->assertSame(['app/Services/Ai/SoftwareCompanyStewardship'], $report['branch_isolation']['allowed_paths']);
        $this->assertTrue($report['claim_policy']['owner_runtime_start_authorized']);
        $this->assertTrue($report['claim_policy']['ap756_sandbox_bound']);
        $this->assertFalse($report['claim_policy']['runtime_execution_started_by_gate']);
        $this->assertFalse($report['claim_policy']['merge_performed']);
        $this->assertFalse($report['claim_policy']['secret_access']);
    }

    public function test_blocks_execution_receipt_without_ap756_materialized_sandbox(): void
    {
        $report = $this->service()->project([
            'release_report' => $this->releaseReport(),
            'outcome_bridge' => $this->outcomeBridge(),
            'execution_receipt' => $this->executionReceipt(),
        ]);

        $this->assertSame(AreaFocusOwnerQueueConsumptionGateService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('ap756_materialized_sandbox_required', $report['reason']);
        $this->assertContains('ap756_sandbox_record_required', $report['sandbox_binding']['violations']);
        $this->assertFalse($report['claim_policy']['owner_runtime_start_authorized']);
    }

    public function test_blocks_when_ap756_sandbox_targets_a_different_handoff(): void
    {
        $report = $this->service()->project([
            'release_report' => $this->releaseReport(),
            'outcome_bridge' => $this->outcomeBridge(),
            'sandbox_record' => $this->sandboxRecord([
                'source_refs' => [
                    'handoff_hash' => 'sha256:other-handoff',
                ],
            ]),
            'execution_receipt' => $this->executionReceipt(),
        ]);

        $this->assertSame(AreaFocusOwnerQueueConsumptionGateService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('ap756_materialized_sandbox_required', $report['reason']);
        $this->assertContains('ap756_handoff_hash_mismatch', $report['sandbox_binding']['violations']);
    }

    public function test_blocks_when_outcome_bridge_lacks_morning_inbox_and_portfolio_signal(): void
    {
        $outcome = $this->outcomeBridge();
        $outcome['morning_inbox_items'] = [];
        $outcome['portfolio_feed'] = [
            'schema_version' => 'atlas.software_company.stewardship_release_portfolio_feed.v1',
            'areas' => [],
        ];
        $outcome['release_outcome_summary']['queue_item_ids'] = [];

        $report = $this->service()->project([
            'release_report' => $this->releaseReport(),
            'outcome_bridge' => $outcome,
            'execution_receipt' => $this->executionReceipt(),
        ]);

        $this->assertSame(AreaFocusOwnerQueueConsumptionGateService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('ap748_outcome_bridge_incomplete', $report['reason']);
        $this->assertContains('morning_inbox_item_present', $report['outcome_check']['missing']);
        $this->assertContains('portfolio_feed_present', $report['outcome_check']['missing']);
    }

    public function test_record_consumption_is_append_only_and_idempotent(): void
    {
        $input = [
            'release_report' => $this->releaseReport(),
            'outcome_bridge' => $this->outcomeBridge(),
            'sandbox_record' => $this->sandboxRecord(),
            'execution_receipt' => $this->executionReceipt(),
            'record_consumption' => true,
        ];

        $first = $this->service()->project($input);
        $second = $this->service()->project($input);

        $this->assertSame(AreaFocusOwnerQueueConsumptionGateService::STATUS_RECORDED, $first['status']);
        $this->assertSame('recorded', $first['consumption_storage_status']);
        $this->assertSame('existing', $second['consumption_storage_status']);
        $this->assertSame($first['consumption_id'], $second['consumption_id']);
        $this->assertFileExists($this->service()->consumptionFilePath('agentic_engineering_os'));
        $this->assertCount(1, file($this->service()->consumptionFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function releaseReport(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => AreaFocusDevForgeReleaseService::RECORD_SCHEMA,
            'ap_contract' => 'AP-747',
            'status' => AreaFocusDevForgeReleaseService::STATUS_RECORDED,
            'area_id' => 'agentic_engineering_os',
            'release_id' => 'afrel_fixture',
            'release_hash' => 'sha256:release',
            'target_owner' => 'atlas_dev',
            'target_runtime_schema' => AtlasDevRuntimeService::SCHEMA_VERSION,
            'source_refs' => [
                'handoff_hash' => 'sha256:handoff',
                'work_order_id' => 'awo_1',
            ],
            'queue_item' => [
                'schema_version' => AreaFocusDevForgeReleaseService::DEV_QUEUE_SCHEMA,
                'target_owner' => 'atlas_dev',
                'target_runtime_schema' => AtlasDevRuntimeService::SCHEMA_VERSION,
                'queue_item_id' => 'afq_fixture',
                'area_id' => 'agentic_engineering_os',
                'work_order_id' => 'awo_1',
                'handoff_hash' => 'sha256:handoff',
                'dev_runtime_payload' => [
                    'flow_id' => AtlasDevRuntimeService::FLOW_DEV,
                    'workspace' => 'atlas-server',
                    'expected_files' => ['app/Services/Ai/SoftwareCompanyStewardship'],
                    'artifact_agent_packet' => [
                        'allowed_paths' => ['app/Services/Ai/SoftwareCompanyStewardship'],
                        'forbidden_paths' => ['.env'],
                    ],
                ],
                'queued_for_real_owner' => true,
                'runtime_execution_started' => false,
                'provider_invoked' => false,
                'branch_created' => false,
                'merge_performed' => false,
                'deploy_performed' => false,
                'secret_access' => false,
            ],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function outcomeBridge(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => 'atlas.software_company.stewardship_outcome_bridge.v1',
            'status' => 'ready',
            'ap_contract' => 'AP-740',
            'area_id' => 'agentic_engineering_os',
            'source_ap_contracts' => ['AP-731', 'AP-738', 'AP-747', 'AP-748'],
            'release_outcome_summary' => [
                'schema_version' => 'atlas.software_company.stewardship_release_outcome_summary.v1',
                'bridge_ap_contract' => 'AP-748',
                'release_count' => 1,
                'owner_queue_pending_count' => 1,
                'queue_item_ids' => ['afq_fixture'],
            ],
            'portfolio_feed' => [
                'schema_version' => 'atlas.software_company.stewardship_release_portfolio_feed.v1',
                'areas' => [[
                    'area_id' => 'agentic_engineering_os',
                    'owner_queue_pending_count' => 1,
                ]],
            ],
            'evidence_items' => [[
                'schema_version' => 'atlas.software_company.stewardship_outcome_evidence.v1',
                'event_id' => 'scoev_fixture',
                'source_kind' => 'ap747_owner_queue_release',
                'source_id' => 'afrel_fixture',
                'payload' => [
                    'release_id' => 'afrel_fixture',
                    'queue_item_id' => 'afq_fixture',
                ],
            ]],
            'morning_inbox_items' => [[
                'schema_version' => 'atlas.software_company.stewardship_morning_inbox_item.v1',
                'kind' => 'ap747_owner_queue_release_review',
                'release_id' => 'afrel_fixture',
                'queue_item_id' => 'afq_fixture',
                'dedupe_key' => 'stewardship:ap747:afrel_fixture:owner_queue_recorded',
            ]],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function sandboxRecord(array $overrides = []): array
    {
        $worktree = $this->tmp.'/worktrees/afsb_fixture';
        File::ensureDirectoryExists($worktree);

        return array_replace_recursive([
            'schema_version' => 'atlas.software_company_stewardship.area_focus_branch_sandbox_materializer_record.v1',
            'ap_contract' => 'AP-756',
            'status' => 'materialized',
            'area_id' => 'agentic_engineering_os',
            'sandbox_id' => 'afsb_fixture',
            'source_ap_contracts' => ['AP-724', 'AP-726', 'AP-747', 'AP-756'],
            'source_refs' => [
                'handoff_hash' => 'sha256:handoff',
                'work_order_id' => 'awo_1',
            ],
            'materialization' => [
                'branch_name' => 'area-focus/agentic-engineering-os/fixture',
                'worktree_path' => $worktree,
                'branch_created' => true,
                'worktree_created' => true,
                'target_repo_mutated' => false,
                'provider_invoked' => false,
                'runtime_execution_started' => false,
            ],
            'sandbox_hash' => 'sha256:sandbox',
        ], $overrides);
    }

    /**
     * @return array<string,string>
     */
    private function executionReceipt(): array
    {
        return [
            'decision' => 'consume',
            'operator_actor' => 'operator',
            'target_release_id' => 'afrel_fixture',
            'target_queue_item_id' => 'afq_fixture',
        ];
    }
}
