<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AreaStewardshipActiveOperatingServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap744_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AreaStewardshipActiveOperatingService
    {
        $service = app(AreaStewardshipActiveOperatingService::class);
        $service->setStorageRootForTesting($this->tmp.'/operations');

        return $service;
    }

    public function test_awaits_active_handoff_before_operating(): void
    {
        $report = $this->service()->operate([
            'area_id' => 'agentic_engineering_os',
            'active_handoff_report' => [
                'schema_version' => AreaStewardshipActiveHandoffService::REPORT_SCHEMA,
                'status' => AreaStewardshipActiveHandoffService::STATUS_AWAITING_OPERATOR_ACCEPTANCE,
                'blockers' => ['operator_accept_decision_missing'],
                'active_handoff_packets' => [],
            ],
        ]);

        $this->assertSame(AreaStewardshipActiveOperatingService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(AreaStewardshipActiveOperatingService::STATUS_AWAITING_HANDOFF, $report['status']);
        $this->assertSame('AP-744', $report['ap_contract']);
        $this->assertSame(['operator_accept_decision_missing'], $report['blockers']);
        $this->assertFalse($report['claim_policy']['dev_invoked']);
        $this->assertFalse($report['claim_policy']['forge_invoked']);
        $this->assertFalse($report['claim_policy']['branch_created']);
    }

    public function test_ready_handoff_runs_active_cycle_and_prepares_owner_queue(): void
    {
        $report = $this->service()->operate([
            'active_handoff_report' => $this->handoff(),
            'operational_cycle' => $this->cycle(),
            'operator_receipts' => [$this->receipt('dev1', 'accept', 'awo_dev1')],
            'gate_report' => $this->allowGate(),
        ]);

        $this->assertSame(AreaStewardshipActiveOperatingService::STATUS_READY, $report['status']);
        $this->assertSame(['AP-743', 'AP-722', 'AP-718', 'AP-719', 'AP-720', 'AP-726'], $report['source_ap_contracts']);
        $this->assertSame('ashp_fixture', $report['active_handoff_packet']['handoff_packet_id']);
        $this->assertSame('afoc_fixture', $report['operational_cycle_id']);
        $this->assertSame(2, $report['counts']['work_orders']);
        $this->assertSame(1, $report['counts']['spec_drafts']);
        $this->assertSame(1, $report['counts']['ready_branch_handoffs']);

        $this->assertSame('atlas.area_stewardship.active_operation_queue.v1', $report['operation_queue']['schema_version']);
        $this->assertSame(1, $report['operation_queue']['by_route']['self_directed_evolution']);
        $this->assertSame(1, $report['operation_queue']['by_route']['atlas_dev']);
        $this->assertSame(2, $report['operation_queue']['operator_decision_count']);

        $this->assertSame(AreaFocusBranchSandboxHandoffService::STATUS_READY, $report['branch_sandbox_handoff']['status']);
        $ready = array_values(array_filter(
            $report['branch_sandbox_handoff']['handoffs'],
            static fn (array $h): bool => ($h['handoff_status'] ?? '') === AreaFocusBranchSandboxHandoffService::HO_READY
        ));
        $this->assertCount(1, $ready);
        $this->assertSame('atlas_dev', $ready[0]['target_owner']);
        $this->assertFalse($ready[0]['branch_plan']['branch_created']);
        $this->assertFalse($ready[0]['execution_performed']);

        $this->assertSame('AP-718', $report['spec_drafts'][0]['ap_contract']);
        $this->assertFalse($report['claim_policy']['provider_invoked']);
        $this->assertFalse($report['claim_policy']['work_dispatched']);
        $this->assertFalse($report['claim_policy']['mutates_target_repo']);
    }

    public function test_missing_work_order_receipt_keeps_active_operation_partial(): void
    {
        $report = $this->service()->operate([
            'active_handoff_report' => $this->handoff(),
            'operational_cycle' => $this->cycle(),
            'operator_receipts' => [],
            'gate_report' => $this->allowGate(),
        ]);

        $this->assertSame(AreaStewardshipActiveOperatingService::STATUS_PARTIAL, $report['status']);
        $this->assertSame(1, $report['counts']['awaiting_operator_handoffs']);
        $this->assertSame(AreaFocusBranchSandboxHandoffService::STATUS_PARTIAL, $report['branch_sandbox_handoff']['status']);
        $this->assertContains('Record AP-724 accept/reject/defer/request_changes receipts for awaiting Dev/Forge handoffs.', $report['next_actions']);
    }

    public function test_record_active_operation_is_append_only_and_idempotent(): void
    {
        $input = [
            'active_handoff_report' => $this->handoff(),
            'operational_cycle' => $this->cycle(),
            'operator_receipts' => [$this->receipt('dev1', 'accept', 'awo_dev1')],
            'gate_report' => $this->allowGate(),
            'record_active_operation' => true,
        ];

        $first = $this->service()->operate($input);
        $second = $this->service()->operate($input);

        $this->assertSame('recorded', $first['operation_storage_status']);
        $this->assertSame('existing', $second['operation_storage_status']);
        $this->assertSame($first['operation_id'], $second['operation_id']);
        $this->assertFileExists($this->service()->operationFilePath('agentic_engineering_os'));
        $this->assertCount(1, file($this->service()->operationFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertTrue($first['claim_policy']['records_active_operation_when_requested']);
    }

    public function test_operation_hash_is_deterministic_for_same_input(): void
    {
        $input = [
            'active_handoff_report' => $this->handoff(),
            'operational_cycle' => $this->cycle(),
            'operator_receipts' => [$this->receipt('dev1', 'accept', 'awo_dev1')],
            'gate_report' => $this->allowGate(),
        ];

        $a = $this->service()->operate($input);
        $b = $this->service()->operate($input);

        $this->assertSame($a['operation_id'], $b['operation_id']);
        $this->assertSame($a['operation_hash'], $b['operation_hash']);
        $this->assertStringStartsWith('sha256:', $a['operation_hash']);
    }

    /**
     * @return array<string,mixed>
     */
    private function handoff(): array
    {
        return [
            'schema_version' => AreaStewardshipActiveHandoffService::REPORT_SCHEMA,
            'status' => AreaStewardshipActiveHandoffService::STATUS_READY,
            'handoff_hash' => 'sha256:handoff_fixture',
            'active_handoff_packets' => [[
                'schema_version' => AreaStewardshipActiveHandoffService::PACKET_SCHEMA,
                'handoff_packet_id' => 'ashp_fixture',
                'handoff_status' => 'ready_for_area_stewardship_active_mode',
                'area_id' => 'agentic_engineering_os',
                'target_id' => 'agentic_engineering_os',
                'packet_hash' => 'sha256:packet_fixture',
                'operator_acceptance' => [
                    'status' => 'accepted',
                    'decision_id' => 'seod_area_accept',
                ],
            ]],
            'blockers' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function cycle(): array
    {
        $sde = $this->finding('sde1', 'self_directed_evolution', 'self_directed_spec_gap');
        $dev = $this->finding('dev1', 'atlas_dev', 'missing_test');

        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_operational_cycle.v1',
            'status' => 'ready',
            'ap_contract' => 'AP-722',
            'area_id' => 'agentic_engineering_os',
            'cycle_id' => 'afoc_fixture',
            'report_hash' => 'sha256:cycle_fixture',
            'stage_status' => [
                'core' => 'ready',
                'scan' => 'ready',
                'inbox' => 'ready',
                'work_orders' => 'ready',
                'evidence_pack' => 'complete',
            ],
            'area_contract' => [
                'area_id' => 'agentic_engineering_os',
                'area_owner_docs' => ['docs/engineering-knowledge-base/atlas-area-stewardship-layer.md'],
                'dev_budget' => ['max_concurrent_work_orders' => 2],
                'forge_budget' => ['max_concurrent_obras' => 1],
                'wip_limit' => 3,
                'risk_policy' => ['inbox_only_domains' => []],
                'repo_scope' => [
                    'repos' => ['atlas-server'],
                    'allowed_paths' => ['app/Services/Ai/SoftwareCompanyStewardship'],
                    'forbidden_paths' => ['.env'],
                ],
            ],
            'stages' => [
                'scan' => [
                    'status' => 'ready',
                    'findings' => [$sde, $dev],
                ],
                'work_orders' => [
                    'schema_version' => 'atlas.software_company_stewardship.area_work_order_plan.v1',
                    'status' => 'ready',
                    'report_hash' => 'sha256:work_order_plan_fixture',
                    'work_orders' => [
                        $this->workOrder('sde1', 'self_directed_evolution', 'self_directed_spec_gap', 'awo_sde1'),
                        $this->workOrder('dev1', 'atlas_dev', 'missing_test', 'awo_dev1'),
                    ],
                    'counts' => ['by_route' => ['self_directed_evolution' => 1, 'atlas_dev' => 1]],
                    'budget_state' => ['dev_cap' => 2, 'dev_used' => 1, 'forge_cap' => 1, 'forge_used' => 0, 'wip_cap' => 3, 'wip_used' => 2],
                ],
                'evidence_pack' => [
                    'pack_hash' => 'sha256:evidence_fixture',
                    'completeness' => ['complete' => true],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function finding(string $hash, string $route, string $type): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.area_finding.v1',
            'area_id' => 'agentic_engineering_os',
            'finding_type' => $type,
            'title' => 'finding '.$hash,
            'detail' => 'detail '.$hash,
            'severity' => 'medium',
            'risk_level' => 'medium',
            'confidence' => 'high',
            'route_hint' => $route,
            'evidence_refs' => ['ev:'.$hash],
            'recommended_action' => 'review '.$hash,
            'finding_id' => 'aef_'.$hash,
            'finding_hash' => 'sha256:'.$hash,
            'priority_score' => 250,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workOrder(string $hash, string $route, string $type, string $workOrderId): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.area_work_order.v1',
            'work_order_id' => $workOrderId,
            'work_order_hash' => 'sha256:wo_'.$hash,
            'area_id' => 'agentic_engineering_os',
            'source' => 'finding',
            'source_ref' => 'sha256:'.$hash,
            'source_id' => 'aef_'.$hash,
            'finding_type' => $type,
            'title' => 'work '.$hash,
            'rationale' => 'detail '.$hash,
            'route' => $route,
            'lane' => $route === 'atlas_dev' ? 'dev' : 'sde',
            'risk_level' => 'medium',
            'severity' => 'medium',
            'confidence' => 'high',
            'blast_radius' => 'local',
            'priority_score' => 250,
            'evidence_refs' => ['ev:'.$hash],
            'status' => 'emitted',
            'requires_operator_review' => true,
            'execution_performed' => false,
            'dispatched' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function receipt(string $hash, string $decision, ?string $workOrderId = null): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_operator_decision_receipt.v1',
            'finding_hash' => 'sha256:'.$hash,
            'work_order_id' => $workOrderId,
            'decision' => $decision,
            'decision_id' => 'afod_'.$hash,
            'decision_hash' => 'sha256:dec_'.$hash,
            'operator_actor' => 'vitor',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function allowGate(): array
    {
        return ['decision' => 'allow', 'blocking_gates' => []];
    }
}
