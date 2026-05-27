<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOwnerQueueConsumptionGateService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class StewardshipOwnerRuntimeResultBridgeServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap750_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): StewardshipOwnerRuntimeResultBridgeService
    {
        $service = app(StewardshipOwnerRuntimeResultBridgeService::class);
        $service->setStorageRootForTesting($this->tmp.'/results');

        return $service;
    }

    public function test_requires_ap749_consumption_before_owner_result_bridge(): void
    {
        $report = $this->service()->project([
            'owner_result' => $this->ownerResult(),
        ]);

        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('ap749_consumption_required', $report['reason']);
        $this->assertFalse($report['claim_policy']['owner_runtime_invoked_by_bridge']);
        $this->assertFalse($report['claim_policy']['merge_performed_by_bridge']);
    }

    public function test_requires_owner_runtime_result_receipt(): void
    {
        $report = $this->service()->project([
            'consumption_report' => $this->consumption(),
        ]);

        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('owner_runtime_result_required', $report['reason']);
    }

    public function test_projects_owner_runtime_result_into_evidence_inbox_and_portfolio(): void
    {
        $report = $this->service()->project([
            'consumption_report' => $this->consumption(),
            'owner_result' => $this->ownerResult(),
        ]);

        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_READY, $report['status']);
        $this->assertSame('AP-750', $report['ap_contract']);
        $this->assertContains('AP-749', $report['source_ap_contracts']);
        $this->assertContains('AP-750', $report['source_ap_contracts']);
        $this->assertTrue($report['identity_check']['ok']);
        $this->assertTrue($report['evidence_check']['ok']);
        $this->assertTrue($report['isolation_check']['ok']);
        $this->assertTrue($report['irreversible_check']['ok']);
        $this->assertSame('ap750_owner_runtime_result', $report['evidence_items'][0]['source_kind']);
        $this->assertSame('ap750_owner_runtime_result_review', $report['morning_inbox_items'][0]['kind']);
        $this->assertSame(1, $report['portfolio_feed']['areas'][0]['owner_runtime_result_count']);
        $this->assertSame(1, $report['portfolio_feed']['areas'][0]['completed_result_count']);
        $this->assertFalse($report['claim_policy']['provider_invoked_by_bridge']);
        $this->assertFalse($report['claim_policy']['target_repo_mutated_by_bridge']);
    }

    public function test_blocks_owner_runtime_result_with_missing_identity_fields(): void
    {
        $report = $this->service()->project([
            'consumption_report' => $this->consumption(),
            'owner_result' => $this->ownerResult([
                'schema_version' => '',
                'consumption_id' => '',
                'release_id' => '',
                'queue_item_id' => '',
                'target_owner' => '',
            ]),
        ]);

        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('owner_runtime_result_identity_mismatch', $report['reason']);
        $this->assertContains('owner_result_schema_version_invalid', $report['identity_check']['missing']);
        $this->assertContains('owner_result_consumption_id_required', $report['identity_check']['missing']);
        $this->assertContains('owner_result_release_id_required', $report['identity_check']['missing']);
        $this->assertContains('owner_result_queue_item_id_required', $report['identity_check']['missing']);
        $this->assertContains('owner_result_target_owner_required', $report['identity_check']['missing']);
    }

    public function test_blocks_changed_files_outside_ap749_isolation_boundary(): void
    {
        $report = $this->service()->project([
            'consumption_report' => $this->consumption(),
            'owner_result' => $this->ownerResult([
                'changed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/Foo.php', 'routes/api.php'],
                'evidence_pack' => [
                    'evidence_hash' => 'sha256:evidence',
                    'summary' => 'changed an allowed file and one forbidden by AP-749 boundary',
                    'changed_files' => ['routes/api.php'],
                    'tests' => ['php artisan test --filter=AP750'],
                ],
            ]),
        ]);

        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('owner_runtime_result_isolation_violation', $report['reason']);
        $this->assertContains('changed_file_outside_allowed_paths:routes/api.php', $report['isolation_check']['violations']);
    }

    public function test_blocks_irreversible_actions_without_operator_approval(): void
    {
        $report = $this->service()->project([
            'consumption_report' => $this->consumption(),
            'owner_result' => $this->ownerResult([
                'merge_performed' => true,
            ]),
        ]);

        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('irreversible_action_without_operator_approval', $report['reason']);
        $this->assertContains('merge_performed', $report['irreversible_check']['triggered_flags']);
        $this->assertFalse($report['irreversible_check']['operator_approval_present']);
    }

    public function test_irreversible_result_can_be_bridged_only_with_explicit_operator_approval(): void
    {
        $report = $this->service()->project([
            'consumption_report' => $this->consumption(),
            'owner_result' => $this->ownerResult([
                'merge_performed' => true,
            ]),
            'irreversible_approval_receipt' => [
                'decision' => 'approve_irreversible_result',
                'operator_actor' => 'operator',
            ],
        ]);

        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_READY, $report['status']);
        $this->assertTrue($report['irreversible_check']['operator_approval_present']);
        $this->assertTrue($report['irreversible_check']['ok']);
        $this->assertFalse($report['claim_policy']['merge_performed_by_bridge']);
    }

    public function test_record_result_is_append_only_and_idempotent(): void
    {
        $input = [
            'consumption_report' => $this->consumption(),
            'owner_result' => $this->ownerResult(),
            'record_result' => true,
        ];

        $first = $this->service()->project($input);
        $second = $this->service()->project($input);

        $this->assertSame(StewardshipOwnerRuntimeResultBridgeService::STATUS_RECORDED, $first['status']);
        $this->assertSame('recorded', $first['result_storage_status']);
        $this->assertSame('existing', $second['result_storage_status']);
        $this->assertSame($first['result_bridge_id'], $second['result_bridge_id']);
        $this->assertFileExists($this->service()->resultFilePath('agentic_engineering_os'));
        $this->assertCount(1, file($this->service()->resultFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function consumption(array $overrides = []): array
    {
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
            'owner_runtime_input' => [
                'schema_version' => 'atlas.software_company_stewardship.ap749_atlas_dev_owner_runtime_input.v1',
                'target_owner' => 'atlas_dev',
                'target_runtime_schema' => AtlasDevRuntimeService::SCHEMA_VERSION,
                'queue_item_id' => 'afq_fixture',
            ],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function ownerResult(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => StewardshipOwnerRuntimeResultBridgeService::RESULT_SCHEMA,
            'result_id' => 'afres_fixture',
            'consumption_id' => 'afcons_fixture',
            'release_id' => 'afrel_fixture',
            'queue_item_id' => 'afq_fixture',
            'target_owner' => 'atlas_dev',
            'result_status' => 'completed',
            'summary' => 'Atlas Dev completed the owner work under branch isolation.',
            'changed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/Foo.php'],
            'tests' => ['php artisan test --filter=SoftwareCompanyStewardship'],
            'runtime_execution_started' => true,
            'provider_invoked' => true,
            'branch_created' => true,
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
            'evidence_pack' => [
                'evidence_hash' => 'sha256:evidence',
                'summary' => 'Tests passed and result is ready for operator review.',
                'changed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/Foo.php'],
                'tests' => ['php artisan test --filter=SoftwareCompanyStewardship'],
            ],
        ], $overrides);
    }
}
