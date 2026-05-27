<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use App\Services\Ai\Programming\AtlasDevRuntimeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AreaFocusDevForgeReleaseServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap747_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AreaFocusDevForgeReleaseService
    {
        $service = app(AreaFocusDevForgeReleaseService::class);
        $service->setStorageRootForTesting($this->tmp.'/releases');

        return $service;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function preflight(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => 'atlas.software_company_stewardship.area_focus_branch_sandbox_preflight.v1',
            'ap_contract' => 'AP-726',
            'status' => 'ready',
            'area_id' => 'agentic_engineering_os',
            'preflight_hash' => 'sha256:ap726_preflight_hash',
            'branch_plan' => [
                'branch_name' => 'atlas/area-focus/agentic-engineering-os/atlas_dev/abc123',
                'repo' => 'atlas-server',
                'allowed_paths' => ['app/Services/Ai/SoftwareCompanyStewardship'],
                'forbidden_paths' => ['.env'],
                'branch_created' => false,
                'worktree_created' => false,
                'target_code_touched' => false,
            ],
            'handoff_packet' => [
                'schema_version' => 'atlas.software_company_stewardship.area_focus_handoff_packet.v1',
                'route' => 'atlas_dev',
                'target_owner' => 'atlas_dev',
                'work_order_id' => 'awo_1',
                'work_order_hash' => 'sha256:work_order',
                'finding_hash' => 'sha256:finding',
                'decision_id' => 'afod_1',
                'decision_hash' => 'sha256:decision',
                'title' => 'Fix missing stewardship regression test',
                'risk_level' => 'medium',
                'recommended_action' => 'Add focused unit coverage.',
                'evidence_refs' => ['ev:1'],
                'required_validations' => ['phpunit', 'docs-health', 'architecture-validate'],
                'dispatched' => false,
                'execution_performed' => false,
            ],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function receipt(array $overrides = []): array
    {
        return array_merge([
            'decision' => 'release',
            'operator_actor' => 'operator',
            'target_handoff_hash' => 'sha256:ap726_preflight_hash',
            'release_id' => 'afrel_fixture',
            'release_hash' => 'sha256:release_receipt',
        ], $overrides);
    }

    public function test_releases_ap726_dev_handoff_to_atlas_dev_queue_item(): void
    {
        $report = $this->service()->release([
            'preflight_report' => $this->preflight(),
            'release_receipt' => $this->receipt(),
        ]);

        $this->assertSame(AreaFocusDevForgeReleaseService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(AreaFocusDevForgeReleaseService::STATUS_READY, $report['status']);
        $this->assertSame('AP-747', $report['ap_contract']);
        $this->assertSame('atlas_dev', $report['target_owner']);
        $this->assertSame(AtlasDevRuntimeService::SCHEMA_VERSION, $report['target_runtime_schema']);
        $this->assertSame(AreaFocusDevForgeReleaseService::DEV_QUEUE_SCHEMA, $report['queue_item']['schema_version']);
        $this->assertTrue($report['queue_item']['queued_for_real_owner']);
        $this->assertFalse($report['queue_item']['runtime_execution_started']);
        $this->assertFalse($report['queue_item']['provider_invoked']);
        $this->assertSame('programming.dev', $report['queue_item']['dev_runtime_payload']['flow_id']);
        $this->assertSame(['app/Services/Ai/SoftwareCompanyStewardship'], $report['queue_item']['dev_runtime_payload']['expected_files']);
        $this->assertStringStartsWith('sha256:', $report['release_hash']);
    }

    public function test_releases_forge_handoff_through_real_parallel_durable_coordinator_projection(): void
    {
        $report = $this->service()->release([
            'preflight_report' => $this->preflight([
                'branch_plan' => [
                    'branch_name' => 'atlas/area-focus/agentic-engineering-os/forge/abc123',
                    'repo' => 'atlas-server',
                    'allowed_paths' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship'],
                    'forbidden_paths' => ['.env'],
                ],
                'handoff_packet' => [
                    'route' => 'forge',
                    'target_owner' => 'forge',
                    'risk_level' => 'high',
                ],
            ]),
            'release_receipt' => $this->receipt(),
            'forge_agents' => [['agent_id' => 'forge_1', 'available' => true]],
        ]);

        $this->assertSame('forge', $report['target_owner']);
        $this->assertSame(AtlasForgeParallelDurableCoordinatorService::SCHEMA_VERSION, $report['target_runtime_schema']);
        $this->assertSame(AreaFocusDevForgeReleaseService::FORGE_QUEUE_SCHEMA, $report['queue_item']['schema_version']);
        $this->assertSame(1, $report['queue_item']['forge_parallel_durable_proposal']['counts']['assignments']);
        $this->assertSame('forge_1', $report['queue_item']['forge_parallel_durable_proposal']['assignments'][0]['agent_id']);
        $this->assertFalse($report['queue_item']['provider_invoked']);
        $this->assertFalse($report['queue_item']['branch_created']);
    }

    public function test_accepts_ap726_handoff_list_shape(): void
    {
        $report = $this->service()->release([
            'preflight_report' => [
                'schema_version' => AreaFocusBranchSandboxHandoffService::REPORT_SCHEMA,
                'status' => 'ready',
                'area_id' => 'agentic_engineering_os',
                'report_hash' => 'sha256:handoff_report',
                'handoffs' => [[
                    'handoff_hash' => 'sha256:list_handoff',
                    'handoff_status' => AreaFocusBranchSandboxHandoffService::HO_READY,
                    'route' => 'atlas_dev',
                    'target_owner' => 'atlas_dev',
                    'work_order_id' => 'awo_list',
                    'title' => 'List handoff',
                    'branch_plan' => [
                        'proposed_branch_name' => 'area-focus/agentic/atlas_dev/list',
                        'allowed_paths' => ['tests/Unit/Ai/SoftwareCompanyStewardship'],
                        'forbidden_paths' => ['.env'],
                    ],
                ]],
            ],
            'release_receipt' => $this->receipt(['target_handoff_hash' => 'sha256:list_handoff']),
        ]);

        $this->assertSame(AreaFocusDevForgeReleaseService::STATUS_READY, $report['status']);
        $this->assertSame('awo_list', $report['queue_item']['work_order_id']);
        $this->assertSame('sha256:list_handoff', $report['source_refs']['handoff_hash']);
    }

    public function test_blocks_without_explicit_release_receipt(): void
    {
        $report = $this->service()->release(['preflight_report' => $this->preflight()]);

        $this->assertSame(AreaFocusDevForgeReleaseService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('release_receipt_required', $report['reason']);
        $this->assertFalse($report['claim_policy']['provider_invoked']);
        $this->assertFalse($report['claim_policy']['branch_created']);
    }

    public function test_blocks_target_mismatch(): void
    {
        $report = $this->service()->release([
            'preflight_report' => $this->preflight(),
            'release_receipt' => $this->receipt(['target_handoff_hash' => 'sha256:other']),
        ]);

        $this->assertSame(AreaFocusDevForgeReleaseService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('release_target_not_ready', $report['reason']);
        $this->assertContains('sha256:ap726_preflight_hash', $report['ready_handoff_hashes']);
    }

    public function test_kill_switch_blocks_release(): void
    {
        $report = $this->service()->release([
            'preflight_report' => $this->preflight(),
            'release_receipt' => $this->receipt(),
            'kill_switch' => true,
        ]);

        $this->assertSame(AreaFocusDevForgeReleaseService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('kill_switch_active', $report['reason']);
    }

    public function test_record_release_is_append_only_and_idempotent(): void
    {
        $input = [
            'preflight_report' => $this->preflight(),
            'release_receipt' => $this->receipt(),
            'record_release' => true,
        ];

        $first = $this->service()->release($input);
        $second = $this->service()->release($input);

        $this->assertSame(AreaFocusDevForgeReleaseService::STATUS_RECORDED, $first['status']);
        $this->assertSame('recorded', $first['release_storage_status']);
        $this->assertSame('existing', $second['release_storage_status']);
        $this->assertSame($first['release_id'], $second['release_id']);
        $this->assertFileExists($this->service()->releaseFilePath('agentic_engineering_os'));
        $this->assertCount(1, file($this->service()->releaseFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_claim_policy_allows_queue_release_but_forbids_runtime_mutation(): void
    {
        $policy = $this->service()->release([
            'preflight_report' => $this->preflight(),
            'release_receipt' => $this->receipt(),
        ])['claim_policy'];

        $this->assertTrue($policy['requires_operator_release_receipt']);
        $this->assertTrue($policy['released_to_dev_or_forge_queue']);
        foreach (['runtime_execution_started', 'provider_invoked', 'branch_created', 'worktree_created', 'target_repo_mutated', 'merge_performed', 'deploy_performed', 'pushed_external', 'secret_access', 'destructive_change', 'auto_approved', 'parallel_runtime_created', 'new_os_created'] as $key) {
            $this->assertFalse($policy[$key], "claim_policy.{$key} must be false");
        }
    }
}
