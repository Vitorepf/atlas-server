<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipPromotionReadinessService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AreaStewardshipActiveHandoffServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_ap743_'.uniqid('', true);
        @mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AreaStewardshipActiveHandoffService
    {
        $service = app(AreaStewardshipActiveHandoffService::class);
        $service->setStorageRootForTesting($this->tmp.'/handoffs');

        return $service;
    }

    public function test_waits_for_operator_acceptance_when_ap732_is_ready_for_review(): void
    {
        $report = $this->service()->project([
            'area_id' => 'agentic_engineering_os',
            'readiness_report' => $this->readiness(AreaStewardshipPromotionReadinessService::STATUS_READY_FOR_OPERATOR_REVIEW, [
                'operator_accept_decision_missing',
            ]),
        ]);

        $this->assertSame(AreaStewardshipActiveHandoffService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(AreaStewardshipActiveHandoffService::STATUS_AWAITING_OPERATOR_ACCEPTANCE, $report['status']);
        $this->assertSame('AP-743', $report['ap_contract']);
        $this->assertSame(['AP-730', 'AP-731', 'AP-732'], $report['source_ap_contracts']);
        $this->assertSame(['operator_accept_decision_missing'], $report['blockers']);
        $this->assertSame([], $report['active_handoff_packets']);
        $this->assertFalse($report['claim_policy']['dev_invoked']);
        $this->assertFalse($report['claim_policy']['forge_invoked']);
    }

    public function test_blocks_when_ap732_readiness_is_blocked(): void
    {
        $report = $this->service()->project([
            'readiness_report' => $this->readiness(AreaStewardshipPromotionReadinessService::STATUS_BLOCKED, [
                'health_model_present_missing_or_failed',
            ]),
        ]);

        $this->assertSame(AreaStewardshipActiveHandoffService::STATUS_BLOCKED, $report['status']);
        $this->assertSame(['health_model_present_missing_or_failed'], $report['blockers']);
        $this->assertSame(0, $report['active_handoff_count']);
        $this->assertFalse($report['claim_policy']['opens_branch']);
    }

    public function test_builds_ready_active_handoff_packet_after_ap732_ready(): void
    {
        $report = $this->service()->project([
            'area_id' => 'agentic_engineering_os',
            'readiness_report' => $this->readiness(AreaStewardshipPromotionReadinessService::STATUS_READY_FOR_ACTIVE_HANDOFF),
        ]);

        $this->assertSame(AreaStewardshipActiveHandoffService::STATUS_READY, $report['status']);
        $this->assertSame(1, $report['active_handoff_count']);
        $packet = $report['active_handoff_packets'][0];

        $this->assertSame(AreaStewardshipActiveHandoffService::PACKET_SCHEMA, $packet['schema_version']);
        $this->assertSame('ready_for_area_stewardship_active_mode', $packet['handoff_status']);
        $this->assertSame('agentic_engineering_os', $packet['area_id']);
        $this->assertSame('active', $packet['active_mode_envelope']['stewardship_mode']);
        $this->assertSame('atlas_dev', $packet['active_mode_envelope']['routing_policy']['small_local_work']);
        $this->assertSame('forge', $packet['active_mode_envelope']['routing_policy']['cross_system_or_long_horizon_work']);
        $this->assertFalse($packet['handoff_boundary']['starts_active_loop']);
        $this->assertFalse($packet['claim_policy']['dev_invoked']);
        $this->assertFalse($packet['claim_policy']['forge_invoked']);
        $this->assertFalse($packet['claim_policy']['branch_created']);
        $this->assertStringStartsWith('sha256:', $packet['packet_hash']);
    }

    public function test_record_active_handoff_is_append_only_and_idempotent(): void
    {
        $input = [
            'area_id' => 'agentic_engineering_os',
            'readiness_report' => $this->readiness(AreaStewardshipPromotionReadinessService::STATUS_READY_FOR_ACTIVE_HANDOFF),
            'record_active_handoff' => true,
        ];

        $first = $this->service()->project($input);
        $second = $this->service()->project($input);

        $this->assertSame('recorded', $first['active_handoff_packets'][0]['handoff_storage_status']);
        $this->assertSame('existing', $second['active_handoff_packets'][0]['handoff_storage_status']);
        $this->assertFileExists($this->service()->packetFilePath('agentic_engineering_os'));
        $this->assertCount(1, file($this->service()->packetFilePath('agentic_engineering_os'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $this->assertTrue($first['claim_policy']['records_active_handoff_packet_when_requested']);
    }

    public function test_handoff_hash_is_deterministic_for_same_input(): void
    {
        $input = [
            'area_id' => 'agentic_engineering_os',
            'readiness_report' => $this->readiness(AreaStewardshipPromotionReadinessService::STATUS_READY_FOR_ACTIVE_HANDOFF),
        ];

        $a = $this->service()->project($input);
        $b = $this->service()->project($input);

        $this->assertSame($a['handoff_hash'], $b['handoff_hash']);
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    private function readiness(string $status, array $blockers = []): array
    {
        return [
            'schema_version' => AreaStewardshipPromotionReadinessService::REPORT_SCHEMA,
            'status' => $status,
            'ap_contract' => 'AP-732',
            'area_id' => 'agentic_engineering_os',
            'target_type' => 'area_stewardship',
            'target_id' => 'agentic_engineering_os',
            'target_hash' => 'sha256:area_stewardship_target',
            'promotion_from' => 'area_focus_loop',
            'promotion_to' => 'area_stewardship_active',
            'area_stewardship' => [
                'schema_version' => 'atlas.area.stewardship.v1',
                'status' => 'ready_proposal_only',
                'mode' => 'read_only',
                'area_id' => 'agentic_engineering_os',
                'area_name' => 'Agentic Engineering OS',
                'health_model' => [
                    'schema_version' => 'atlas.area.health_model.v1',
                    'score' => 91,
                    'band' => 'excellent',
                ],
                'roadmap_candidates' => [[
                    'candidate_id' => 'roadmap_1',
                    'summary' => 'Improve AP handoff',
                    'requires_operator_review' => true,
                ]],
                'dev_forge_policy' => [
                    'small_local_work' => 'atlas_dev',
                    'cross_system_or_long_horizon_work' => 'forge',
                    'gap_or_spec_work' => 'self_directed_evolution',
                    'high_risk_or_sensitive_work' => 'operator_review',
                ],
                'operator_inbox' => [
                    'destination' => 'morning_inbox',
                    'auto_approval' => false,
                ],
            ],
            'operator_acceptance' => $status === AreaStewardshipPromotionReadinessService::STATUS_READY_FOR_ACTIVE_HANDOFF
                ? [
                    'status' => 'accepted',
                    'decision_id' => 'seod_area_accept',
                    'operator_actor' => 'vitor',
                    'recorded_at' => '2026-05-27T00:00:00+00:00',
                ]
                : [
                    'status' => 'missing',
                    'required_decision' => 'AP-731 accept for target_type=area_stewardship',
                ],
            'checks' => [
                'area_schema_present' => true,
                'health_model_present' => true,
                'roadmap_candidates_present' => true,
                'dev_forge_policy_present' => true,
                'operator_inbox_present' => true,
                'area_focus_ready' => true,
                'evidence_refs_present' => true,
                'no_mutation_claim' => true,
            ],
            'blockers' => $blockers,
            'next_actions' => [],
            'claim_policy' => [
                'read_only' => true,
                'promotion_gate_only' => true,
                'mutates_target_repo' => false,
                'provider_invoked' => false,
                'dev_invoked' => false,
                'forge_invoked' => false,
            ],
            'report_hash' => 'sha256:ap732_readiness_'.$status,
        ];
    }
}

