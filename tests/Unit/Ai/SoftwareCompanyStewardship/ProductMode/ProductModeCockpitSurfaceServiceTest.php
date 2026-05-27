<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\ProductMode;

use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService;
use Tests\TestCase;

/**
 * Product Mode cockpit aggregate tests (AP-739).
 *
     * The service composes AP-721/AP-736/AP-737/AP-738/AP-740/AP-741/AP-743/AP-744/AP-745/AP-746/AP-747/AP-748/AP-749/AP-750/AP-752/AP-754
     * into one read-only cockpit. It must not become an executor, a decision
     * writer or a new runtime.
 */
final class ProductModeCockpitSurfaceServiceTest extends TestCase
{
    private function service(): ProductModeCockpitSurfaceService
    {
        return app(ProductModeCockpitSurfaceService::class);
    }

    public function test_projects_canonical_product_mode_cockpit(): void
    {
        $cockpit = $this->service()->project('atlas_software_company', [
            'area_id' => 'agentic_engineering_os',
        ]);

        $this->assertSame(ProductModeCockpitSurfaceService::SURFACE_SCHEMA, $cockpit['schema_version']);
        $this->assertSame(ProductModeCockpitSurfaceService::STATUS_READY, $cockpit['status']);
        $this->assertSame('AP-739', $cockpit['ap_contract']);
        $this->assertSame('agentic_engineering_os', $cockpit['area_id']);
        $this->assertSame('atlas_software_company', $cockpit['portfolio_id']);
        $this->assertSame(
            ['AP-721', 'AP-736', 'AP-737', 'AP-738', 'AP-740', 'AP-741', 'AP-742', 'AP-743', 'AP-744', 'AP-745', 'AP-746', 'AP-747', 'AP-748', 'AP-749', 'AP-750', 'AP-752', 'AP-754'],
            $cockpit['source_ap_contracts'],
        );
        $this->assertSame('Atlas Software Company Stewardship Stack', $cockpit['stack']['name']);
        $this->assertTrue($cockpit['stack']['not_a_new_os']);
        $this->assertStringStartsWith('sha256:', $cockpit['surface_hash']);
    }

    public function test_contains_executive_new_area_and_self_expanding_review_sections(): void
    {
        $cockpit = $this->service()->project();

        $this->assertSame('atlas.autonomous_executive.decision_inbox_surface.v1', $cockpit['executive_decision_inbox']['schema_version']);
        $this->assertSame('atlas.software_company.new_area_proposal_gate.v1', $cockpit['new_area_proposal_gate']['schema_version']);
        $this->assertSame('atlas.software_company.self_expanding.v0', $cockpit['self_expanding_company']['schema_version']);
        $this->assertSame('atlas.software_company.stewardship_outcome_bridge.v1', $cockpit['stewardship_outcome_history']['schema_version']);
        $this->assertSame('atlas.software_company.self_expanding.domain_runtime_creation_handoff.v1', $cockpit['domain_runtime_creation_handoff']['schema_version']);
        $this->assertSame('atlas.area_stewardship.active_handoff.v1', $cockpit['area_stewardship_active_handoff']['schema_version']);
        $this->assertSame('atlas.area_stewardship.active_operation.v1', $cockpit['area_stewardship_active_operation']['schema_version']);
        $this->assertSame('atlas.continuous_stewardship.loop_state.v1', $cockpit['continuous_stewardship_loop']['schema_version']);
        $this->assertSame('atlas.continuous_stewardship.recurring_scheduler.v1', $cockpit['continuous_stewardship_scheduler']['schema_version']);
        $this->assertSame('atlas.software_company_stewardship.area_focus_dev_forge_release.v1', $cockpit['dev_forge_release']['schema_version']);
        $this->assertSame('atlas.software_company_stewardship.owner_runtime_result_bridge.v1', $cockpit['owner_runtime_result_bridge']['schema_version']);
        $this->assertSame('atlas.autonomous_executive.allocation_handoff.v1', $cockpit['executive_allocation_handoff']['schema_version']);
        $this->assertSame('atlas.software_company.product_mode_operational_controls.v1', $cockpit['product_mode_operational_controls']['schema_version']);

        $this->assertGreaterThanOrEqual(1, $cockpit['counters']['executive_items']);
        $this->assertGreaterThanOrEqual(1, $cockpit['counters']['new_area_gate_items']);
        $this->assertGreaterThanOrEqual(1, $cockpit['counters']['self_expanding_inbox_items']);
        $this->assertArrayHasKey('outcome_evidence_items', $cockpit['counters']);
        $this->assertArrayHasKey('outcome_morning_inbox_items', $cockpit['counters']);
        $this->assertArrayHasKey('release_outcome_count', $cockpit['counters']);
        $this->assertArrayHasKey('release_portfolio_feed_areas', $cockpit['counters']);
        $this->assertArrayHasKey('domain_handoff_packets', $cockpit['counters']);
        $this->assertArrayHasKey('ready_domain_handoffs', $cockpit['counters']);
        $this->assertArrayHasKey('area_active_handoff_packets', $cockpit['counters']);
        $this->assertArrayHasKey('area_active_operations', $cockpit['counters']);
        $this->assertArrayHasKey('continuous_loop_paused', $cockpit['counters']);
        $this->assertArrayHasKey('dev_forge_releases', $cockpit['counters']);
        $this->assertArrayHasKey('executive_allocation_handoff_packets', $cockpit['counters']);
        $this->assertArrayHasKey('awaiting_executive_allocation_acceptance', $cockpit['counters']);
        $this->assertArrayHasKey('product_mode_control_review_required', $cockpit['counters']);
        $this->assertArrayHasKey('product_mode_missing_evidence_refs', $cockpit['counters']);
        $this->assertSame(1, $cockpit['counters']['continuous_scheduler_paused']);
        $this->assertSame(1, $cockpit['counters']['pending_area_active_acceptance']);
        $this->assertGreaterThanOrEqual(3, $cockpit['counters']['review_queue_items']);

        $sourceAps = array_values(array_unique(array_column($cockpit['review_queue'], 'source_ap')));
        sort($sourceAps);
        $this->assertContains('AP-736', $sourceAps);
        $this->assertContains('AP-737', $sourceAps);
        $this->assertContains('AP-738', $sourceAps);
        $this->assertContains('AP-754', $sourceAps);
    }

    public function test_review_queue_includes_outcome_history_and_domain_handoff_packets_when_projected(): void
    {
        $cockpit = $this->service()->project('atlas_software_company', [
            'area_id' => 'agentic_engineering_os',
            'stewardship_outcome_history' => $this->sampleOutcomeHistory(),
            'domain_runtime_creation_handoff' => $this->sampleDomainHandoff(),
            'area_stewardship_active_handoff' => $this->sampleAreaActiveHandoff(),
            'area_stewardship_active_operation' => $this->sampleAreaActiveOperation(),
            'continuous_stewardship_loop' => $this->sampleContinuousLoop(),
            'continuous_stewardship_scheduler' => $this->sampleContinuousScheduler(),
            'dev_forge_release' => $this->sampleDevForgeRelease(),
            'owner_runtime_result_bridge' => $this->sampleOwnerRuntimeResultBridge(),
            'executive_allocation_handoff' => $this->sampleExecutiveAllocationHandoff(),
        ]);

        $sourceAps = array_values(array_unique(array_column($cockpit['review_queue'], 'source_ap')));
        sort($sourceAps);

        $this->assertContains('AP-740', $sourceAps);
        $this->assertContains('AP-741', $sourceAps);
        $this->assertContains('AP-743', $sourceAps);
        $this->assertContains('AP-744', $sourceAps);
        $this->assertContains('AP-745', $sourceAps);
        $this->assertContains('AP-746', $sourceAps);
        $this->assertContains('AP-747', $sourceAps);
        $this->assertContains('AP-748', $sourceAps);
        $this->assertContains('AP-749', $sourceAps);
        $this->assertContains('AP-750', $sourceAps);
        $this->assertContains('AP-752', $sourceAps);
        $this->assertContains('AP-754', $sourceAps);
        $this->assertSame(3, $cockpit['counters']['outcome_evidence_items']);
        $this->assertSame(2, $cockpit['counters']['outcome_morning_inbox_items']);
        $this->assertSame(1, $cockpit['counters']['release_outcome_count']);
        $this->assertSame(1, $cockpit['counters']['release_portfolio_feed_areas']);
        $this->assertSame(1, $cockpit['counters']['domain_handoff_packets']);
        $this->assertSame(1, $cockpit['counters']['ready_domain_handoffs']);
        $this->assertSame(1, $cockpit['counters']['area_active_handoff_packets']);
        $this->assertSame(1, $cockpit['counters']['ready_area_active_handoffs']);
        $this->assertSame(1, $cockpit['counters']['area_active_operations']);
        $this->assertSame(1, $cockpit['counters']['ready_area_active_operations']);
        $this->assertSame(2, $cockpit['counters']['area_active_operation_work_orders']);
        $this->assertSame(1, $cockpit['counters']['area_active_operation_spec_drafts']);
        $this->assertSame(1, $cockpit['counters']['continuous_loop_ticks']);
        $this->assertSame(1, $cockpit['counters']['continuous_loop_recorded_ticks']);
        $this->assertSame(1, $cockpit['counters']['continuous_scheduler_runs']);
        $this->assertSame(1, $cockpit['counters']['continuous_scheduler_recorded_runs']);
        $this->assertSame(1, $cockpit['counters']['dev_forge_releases']);
        $this->assertSame(1, $cockpit['counters']['dev_forge_recorded_releases']);
        $this->assertSame(1, $cockpit['counters']['owner_runtime_results']);
        $this->assertSame(1, $cockpit['counters']['owner_runtime_recorded_results']);
        $this->assertSame(1, $cockpit['counters']['owner_runtime_result_evidence_items']);
        $this->assertSame(1, $cockpit['counters']['owner_runtime_result_inbox_items']);
        $this->assertSame(1, $cockpit['counters']['executive_allocation_handoff_packets']);
        $this->assertSame(1, $cockpit['counters']['ready_executive_allocation_handoffs']);
        $this->assertSame(1, $cockpit['counters']['product_mode_control_review_required']);
        $this->assertGreaterThanOrEqual(1, $cockpit['counters']['product_mode_missing_evidence_refs']);
        $this->assertSame('ready', $cockpit['health']['outcome_history_status']);
        $this->assertSame('ready', $cockpit['health']['domain_runtime_creation_handoff_status']);
        $this->assertSame('ready', $cockpit['health']['area_stewardship_active_handoff_status']);
        $this->assertSame('active_cycle_ready', $cockpit['health']['area_stewardship_active_operation_status']);
        $this->assertSame('tick_recorded', $cockpit['health']['continuous_stewardship_loop_status']);
        $this->assertSame('run_recorded', $cockpit['health']['continuous_stewardship_scheduler_status']);
        $this->assertSame('owner_queue_recorded', $cockpit['health']['dev_forge_release_status']);
        $this->assertSame('owner_runtime_result_recorded', $cockpit['health']['owner_runtime_result_bridge_status']);
        $this->assertSame('ready', $cockpit['health']['executive_allocation_handoff_status']);
        $this->assertSame('review_required', $cockpit['health']['product_mode_operational_controls_status']);

        $handoffItems = array_values(array_filter(
            $cockpit['review_queue'],
            static fn (array $item): bool => ($item['source_ap'] ?? null) === 'AP-741',
        ));

        $this->assertCount(1, $handoffItems);
        $this->assertSame('domain_runtime_creation_handoff', $handoffItems[0]['kind']);
        $this->assertSame('submit_recorded_packet_to_domain_runtime_creation_gate_review', $handoffItems[0]['recommended_operator_action']);
        $this->assertFalse($handoffItems[0]['irreversible_action_allowed']);
        $this->assertFalse($handoffItems[0]['autoimplementation_allowed']);

        $releaseItems = array_values(array_filter(
            $cockpit['review_queue'],
            static fn (array $item): bool => ($item['source_ap'] ?? null) === 'AP-747',
        ));

        $this->assertCount(1, $releaseItems);
        $this->assertSame('area_focus_dev_forge_release', $releaseItems[0]['kind']);
        $this->assertSame('review_owner_queue_item_before_dev_forge_runtime_execution', $releaseItems[0]['recommended_operator_action']);

        $areaHandoffItems = array_values(array_filter(
            $cockpit['review_queue'],
            static fn (array $item): bool => ($item['source_ap'] ?? null) === 'AP-743',
        ));

        $this->assertCount(1, $areaHandoffItems);
        $this->assertSame('area_stewardship_active_handoff', $areaHandoffItems[0]['kind']);
        $this->assertSame('record_or_accept_area_stewardship_active_handoff_before_operating_active_mode', $areaHandoffItems[0]['recommended_operator_action']);
        $this->assertFalse($areaHandoffItems[0]['irreversible_action_allowed']);
        $this->assertFalse($areaHandoffItems[0]['autoimplementation_allowed']);

        $areaOperationItems = array_values(array_filter(
            $cockpit['review_queue'],
            static fn (array $item): bool => ($item['source_ap'] ?? null) === 'AP-744',
        ));

        $this->assertCount(1, $areaOperationItems);
        $this->assertSame('area_stewardship_active_operation', $areaOperationItems[0]['kind']);
        $this->assertSame('review_active_operation_queue_and_record_ap724_decisions_before_any_dev_forge_release', $areaOperationItems[0]['recommended_operator_action']);
        $this->assertFalse($areaOperationItems[0]['irreversible_action_allowed']);
        $this->assertFalse($areaOperationItems[0]['autoimplementation_allowed']);

        $continuousItems = array_values(array_filter(
            $cockpit['review_queue'],
            static fn (array $item): bool => ($item['source_ap'] ?? null) === 'AP-745',
        ));

        $this->assertCount(1, $continuousItems);
        $this->assertSame('continuous_stewardship_loop_tick', $continuousItems[0]['kind']);
        $this->assertSame('review_continuous_loop_tick_before_any_dev_forge_release_or_scheduler_promotion', $continuousItems[0]['recommended_operator_action']);
        $this->assertFalse($continuousItems[0]['irreversible_action_allowed']);
        $this->assertFalse($continuousItems[0]['autoimplementation_allowed']);

        $schedulerItems = array_values(array_filter(
            $cockpit['review_queue'],
            static fn (array $item): bool => ($item['source_ap'] ?? null) === 'AP-746',
        ));

        $this->assertCount(1, $schedulerItems);
        $this->assertSame('continuous_stewardship_scheduler_run', $schedulerItems[0]['kind']);
        $this->assertSame('review_recurring_scheduler_run_before_any_dev_forge_release_or_scheduler_expansion', $schedulerItems[0]['recommended_operator_action']);
        $this->assertFalse($schedulerItems[0]['irreversible_action_allowed']);
        $this->assertFalse($schedulerItems[0]['autoimplementation_allowed']);

        $resultItems = array_values(array_filter(
            $cockpit['review_queue'],
            static fn (array $item): bool => ($item['source_ap'] ?? null) === 'AP-750',
        ));

        $this->assertCount(1, $resultItems);
        $this->assertSame('owner_runtime_result_bridge', $resultItems[0]['kind']);
        $this->assertSame('review_owner_runtime_result_before_merge_deploy_or_followup', $resultItems[0]['recommended_operator_action']);
        $this->assertFalse($resultItems[0]['irreversible_action_allowed']);
        $this->assertFalse($resultItems[0]['autoimplementation_allowed']);

        $allocationItems = array_values(array_filter(
            $cockpit['review_queue'],
            static fn (array $item): bool => ($item['source_ap'] ?? null) === 'AP-752',
        ));

        $this->assertCount(1, $allocationItems);
        $this->assertSame('executive_allocation_handoff', $allocationItems[0]['kind']);
        $this->assertSame('review_ap752_allocation_handoff_before_routing_owner_work', $allocationItems[0]['recommended_operator_action']);
        $this->assertFalse($allocationItems[0]['irreversible_action_allowed']);
        $this->assertFalse($allocationItems[0]['autoimplementation_allowed']);

        $controlItems = array_values(array_filter(
            $cockpit['review_queue'],
            static fn (array $item): bool => ($item['source_ap'] ?? null) === 'AP-754',
        ));

        $this->assertCount(1, $controlItems);
        $this->assertSame('product_mode_operational_controls', $controlItems[0]['kind']);
        $this->assertSame('review_product_mode_controls_before_enabling_higher_autonomy', $controlItems[0]['recommended_operator_action']);
        $this->assertFalse($controlItems[0]['irreversible_action_allowed']);
        $this->assertFalse($controlItems[0]['autoimplementation_allowed']);
    }

    public function test_review_items_are_non_executing_and_operator_gated(): void
    {
        $cockpit = $this->service()->project();

        $this->assertNotEmpty($cockpit['review_queue']);
        foreach ($cockpit['review_queue'] as $item) {
            $this->assertFalse($item['irreversible_action_allowed']);
            $this->assertFalse($item['autoimplementation_allowed']);
            $this->assertArrayHasKey('decision_anchor', $item);
        }

        $this->assertFalse($cockpit['operator_controls']['irreversible_action_allowed']);
        $this->assertFalse($cockpit['operator_controls']['autoimplementation_allowed']);
        $this->assertStringContainsString('outcome-evidence', $cockpit['operator_controls']['outcome_evidence_command']);
        $this->assertStringContainsString('--release-file=<ap747.jsonl>', $cockpit['operator_controls']['release_outcome_evidence_command']);
        $this->assertStringContainsString('owner-queue-consumption-gate', $cockpit['operator_controls']['owner_queue_consumption_gate_command']);
        $this->assertStringContainsString('record-consumption', $cockpit['operator_controls']['owner_queue_consumption_record_command']);
        $this->assertStringContainsString('owner-runtime-result-bridge', $cockpit['operator_controls']['owner_runtime_result_bridge_command']);
        $this->assertStringContainsString('record-result', $cockpit['operator_controls']['owner_runtime_result_record_command']);
        $this->assertStringContainsString('executive-allocation-handoff', $cockpit['operator_controls']['executive_allocation_handoff_command']);
        $this->assertStringContainsString('record-allocation-handoff', $cockpit['operator_controls']['executive_allocation_handoff_record_command']);
        $this->assertStringContainsString('product-mode-controls', $cockpit['operator_controls']['product_mode_controls_command']);
        $this->assertStringContainsString('domain-runtime-creation-handoff', $cockpit['operator_controls']['domain_handoff_record_command']);
        $this->assertStringContainsString('area-stewardship-active-handoff', $cockpit['operator_controls']['area_active_handoff_record_command']);
        $this->assertStringContainsString('area-stewardship-active-operate', $cockpit['operator_controls']['area_active_operation_record_command']);
        $this->assertStringContainsString('continuous-stewardship-loop', $cockpit['operator_controls']['continuous_loop_tick_command']);
        $this->assertStringContainsString('record-continuous-cycle', $cockpit['operator_controls']['continuous_loop_record_command']);
        $this->assertStringContainsString('continuous-stewardship-scheduler', $cockpit['operator_controls']['continuous_scheduler_run_command']);
        $this->assertStringContainsString('record-scheduler-run', $cockpit['operator_controls']['continuous_scheduler_record_command']);
        $this->assertStringContainsString('area-focus-dev-forge-release', $cockpit['operator_controls']['dev_forge_release_command']);
        $this->assertStringContainsString('record-release', $cockpit['operator_controls']['dev_forge_release_record_command']);
        $this->assertFalse($cockpit['claim_policy']['records_operator_decisions']);
        $this->assertFalse($cockpit['claim_policy']['dev_invoked']);
        $this->assertFalse($cockpit['claim_policy']['forge_invoked']);
        $this->assertFalse($cockpit['claim_policy']['branch_created']);
        $this->assertFalse($cockpit['claim_policy']['domain_runtime_created']);
        $this->assertFalse($cockpit['claim_policy']['scheduler_installed']);
        $this->assertFalse($cockpit['claim_policy']['continuous_loop_tick_executed_by_cockpit']);
        $this->assertFalse($cockpit['claim_policy']['recurring_scheduler_executed_by_cockpit']);
        $this->assertFalse($cockpit['claim_policy']['dev_forge_release_executed_by_cockpit']);
        $this->assertFalse($cockpit['claim_policy']['owner_queue_consumption_executed_by_cockpit']);
        $this->assertFalse($cockpit['claim_policy']['owner_runtime_result_bridge_executed_by_cockpit']);
        $this->assertFalse($cockpit['claim_policy']['executive_allocation_handoff_executed_by_cockpit']);
        $this->assertFalse($cockpit['claim_policy']['product_mode_controls_execute_actions']);
        $this->assertFalse($cockpit['claim_policy']['product_mode_controls_authorize_repository']);
        $this->assertFalse($cockpit['claim_policy']['product_mode_controls_change_autonomy_tier']);
    }

    public function test_unknown_area_returns_blocked_cockpit_without_masking_reason(): void
    {
        $cockpit = $this->service()->project('atlas_software_company', [
            'area_id' => 'unknown_area',
        ]);

        $this->assertSame(ProductModeCockpitSurfaceService::STATUS_BLOCKED, $cockpit['status']);
        $this->assertSame('area_focus_product_mode_blocked', $cockpit['reason']);
        $this->assertSame('unknown_area', $cockpit['blockers'][0]);
        $this->assertFalse($cockpit['claim_policy']['provider_invoked']);
        $this->assertFalse($cockpit['claim_policy']['new_os_created']);
    }

    public function test_surface_hash_is_stable_across_repeated_projection(): void
    {
        $first = $this->service()->project();
        $second = $this->service()->project();

        $this->assertSame($first['surface_hash'], $second['surface_hash']);
        $this->assertSame($first['counters'], $second['counters']);
        $this->assertSame($first['review_queue'], $second['review_queue']);
    }

    /**
     * @return array<string,mixed>
     */
    private function sampleOutcomeHistory(): array
    {
        return [
            'schema_version' => 'atlas.software_company.stewardship_outcome_bridge.v1',
            'status' => 'ready',
            'ap_contract' => 'AP-740',
            'decision_count' => 1,
            'self_expanding_status' => 'ready',
            'evidence_item_count' => 3,
            'morning_inbox_item_count' => 2,
            'release_outcome_summary' => [
                'schema_version' => 'atlas.software_company.stewardship_release_outcome_summary.v1',
                'release_count' => 1,
                'owner_queue_pending_count' => 1,
            ],
            'portfolio_feed' => [
                'schema_version' => 'atlas.software_company.stewardship_release_portfolio_feed.v1',
                'areas' => [[
                    'area_id' => 'agentic_engineering_os',
                    'owner_queue_pending_count' => 1,
                ]],
            ],
            'record_evidence_requested' => false,
            'emit_inbox_requested' => false,
            'next_handoff_boundary' => [
                'ready_for_domain_runtime_creation_gate' => 1,
                'domain_runtime_creation_gate_required' => true,
            ],
            'evidence_items' => [[
                'event_id' => 'scoev_operator_accept',
                'ledger_status' => 'recorded',
            ], [
                'event_id' => 'scoev_self_expanding_report',
                'ledger_status' => 'recorded',
            ], [
                'event_id' => 'scoev_ap747_release',
                'source_kind' => 'ap747_owner_queue_release',
                'ledger_status' => 'projected',
            ]],
            'morning_inbox_items' => [[
                'schema_version' => 'atlas.software_company.stewardship_morning_inbox_item.v1',
                'kind' => 'ap731_operator_decision_outcome',
                'dedupe_key' => 'stewardship:ap731:new_area_proposal:accept',
                'title' => 'Stewardship decision needs follow-up: prop_trading_research',
                'area_id' => 'agentic_engineering_os',
                'decision_id' => 'dec_trading_research',
                'target_id' => 'prop_trading_research',
                'recommended_action' => 'prepare_domain_runtime_creation_gate_handoff',
                'review_signal' => [
                    'severity' => 'high',
                ],
                'source_refs' => [[
                    'type' => 'stewardship_decision',
                    'id' => 'dec_trading_research',
                ]],
                'inbox_status' => 'projected',
            ], [
                'schema_version' => 'atlas.software_company.stewardship_morning_inbox_item.v1',
                'kind' => 'ap747_owner_queue_release_review',
                'bridge_ap_contract' => 'AP-748',
                'dedupe_key' => 'stewardship:ap747:afrel_sample:owner_queue_recorded',
                'title' => 'Review AP-747 atlas_dev queue release before runtime execution',
                'area_id' => 'agentic_engineering_os',
                'release_id' => 'afrel_sample',
                'queue_item_id' => 'afq_sample',
                'target_owner' => 'atlas_dev',
                'recommended_action' => 'review_owner_queue_item_before_dev_forge_runtime_execution',
                'review_signal' => [
                    'severity' => 'medium',
                ],
                'source_refs' => [[
                    'type' => 'area_focus_dev_forge_release',
                    'id' => 'afrel_sample',
                ]],
                'inbox_status' => 'projected',
            ]],
            'bridge_hash' => 'sha256:sample_outcome_history',
            'claim_policy' => [
                'writes_evidence_ledger_when_requested' => false,
                'emits_morning_inbox_when_requested' => false,
                'provider_invoked' => false,
                'dev_invoked' => false,
                'forge_invoked' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sampleDomainHandoff(): array
    {
        return [
            'schema_version' => 'atlas.software_company.self_expanding.domain_runtime_creation_handoff.v1',
            'status' => 'ready',
            'ap_contract' => 'AP-741',
            'target_owner' => 'Atlas Domain Runtime Creation Gate',
            'target_owner_doc' => 'docs/engineering-knowledge-base/atlas-domain-runtime-creation-gate.md',
            'accepted_candidate_count' => 1,
            'ready_handoff_count' => 1,
            'blocked_handoff_count' => 0,
            'record_handoff_requested' => false,
            'require_recorded_evidence' => true,
            'handoff_packets' => [[
                'schema_version' => 'atlas.domain.creation_handoff_packet.v1',
                'handoff_packet_id' => 'drch_trading_research',
                'handoff_status' => 'ready_for_domain_runtime_creation_gate',
                'candidate_area' => 'trading_research',
                'packet_hash' => 'sha256:sample_domain_handoff_packet',
                'target_gate' => [
                    'owner' => 'domain_runtime_creation_gate',
                    'operator_approval_required' => true,
                ],
                'blockers' => [],
            ]],
            'next_actions' => [
                'submit_recorded_packet_to_Atlas_Domain_Runtime_Creation_Gate_review',
            ],
            'handoff_hash' => 'sha256:sample_domain_handoff',
            'claim_policy' => [
                'records_handoff_packet_when_requested' => false,
                'provider_invoked' => false,
                'dev_invoked' => false,
                'forge_invoked' => false,
                'creates_domain_runtime' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sampleAreaActiveHandoff(): array
    {
        return [
            'schema_version' => 'atlas.area_stewardship.active_handoff.v1',
            'status' => 'ready',
            'ap_contract' => 'AP-743',
            'target_owner' => 'Atlas Area Stewardship Layer',
            'target_owner_doc' => 'docs/engineering-knowledge-base/atlas-area-stewardship-layer.md',
            'readiness_status' => 'ready_for_active_handoff',
            'record_active_handoff_requested' => false,
            'active_handoff_count' => 1,
            'active_handoff_packets' => [[
                'schema_version' => 'atlas.area_stewardship.active_handoff_packet.v1',
                'handoff_packet_id' => 'ashp_agentic_engineering_os',
                'handoff_status' => 'ready_for_area_stewardship_active_mode',
                'area_id' => 'agentic_engineering_os',
                'source_readiness_report_hash' => 'sha256:sample_ap732_readiness',
                'packet_hash' => 'sha256:sample_area_active_handoff_packet',
                'blockers' => [],
            ]],
            'blockers' => [],
            'next_actions' => [
                'Review the AP-743 active handoff packet in Product Mode/Cockpit before operating the area as active stewardship.',
            ],
            'handoff_hash' => 'sha256:sample_area_active_handoff',
            'claim_policy' => [
                'records_active_handoff_packet_when_requested' => false,
                'provider_invoked' => false,
                'dev_invoked' => false,
                'forge_invoked' => false,
                'opens_branch' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sampleAreaActiveOperation(): array
    {
        return [
            'schema_version' => 'atlas.area_stewardship.active_operation.v1',
            'status' => 'active_cycle_ready',
            'ap_contract' => 'AP-744',
            'area_id' => 'agentic_engineering_os',
            'operation_id' => 'asop_agentic_engineering_os',
            'operation_hash' => 'sha256:sample_area_active_operation',
            'active_handoff_status' => 'ready',
            'active_handoff_hash' => 'sha256:sample_area_active_handoff',
            'operational_cycle_id' => 'afoc_sample',
            'operational_cycle_hash' => 'sha256:sample_area_focus_cycle',
            'record_active_operation_requested' => false,
            'counts' => [
                'findings' => 2,
                'work_orders' => 2,
                'spec_drafts' => 1,
                'branch_handoffs' => 1,
                'ready_branch_handoffs' => 1,
                'awaiting_operator_handoffs' => 0,
            ],
            'operation_queue' => [
                'schema_version' => 'atlas.area_stewardship.active_operation_queue.v1',
                'by_route' => [
                    'self_directed_evolution' => 1,
                    'atlas_dev' => 1,
                ],
                'operator_decision_count' => 2,
                'operator_decisions' => [[
                    'kind' => 'area_focus_work_order_decision',
                    'ap_contract' => 'AP-724',
                    'work_order_id' => 'awo_sample',
                    'finding_hash' => 'sha256:sample_finding',
                    'route' => 'atlas_dev',
                ]],
                'self_directed_spec_draft_count' => 1,
                'dev_forge_handoff_count' => 1,
                'ready_dev_forge_handoff_count' => 1,
                'awaiting_operator_handoff_count' => 0,
            ],
            'blockers' => [],
            'next_actions' => [
                'Review AP-724 operator decisions for emitted Area Focus work orders.',
            ],
            'claim_policy' => [
                'active_stewardship_cycle_runs' => true,
                'records_active_operation_when_requested' => false,
                'provider_invoked' => false,
                'dev_invoked' => false,
                'forge_invoked' => false,
                'work_dispatched' => false,
                'branch_created' => false,
                'mutates_target_repo' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sampleContinuousLoop(): array
    {
        return [
            'schema_version' => 'atlas.continuous_stewardship.loop_tick.v1',
            'status' => 'tick_recorded',
            'ap_contract' => 'AP-745',
            'area_id' => 'agentic_engineering_os',
            'tick_id' => 'csl_agentic_engineering_os',
            'tick_hash' => 'sha256:sample_continuous_tick',
            'mode' => 'scheduler_safe_tick',
            'active_operation_status' => 'active_cycle_ready',
            'active_operation_hash' => 'sha256:sample_area_active_operation',
            'record_continuous_cycle_requested' => true,
            'policy' => [
                'enabled' => true,
                'kill_switch_active' => false,
                'min_interval_seconds' => 900,
                'max_cycles_per_tick' => 1,
            ],
            'next_allowed_at' => '2026-05-27T07:00:00+00:00',
            'blockers' => [],
            'next_actions' => [
                'Review AP-745 tick result in Product Mode before releasing any AP-726 Dev/Forge handoff.',
            ],
            'claim_policy' => [
                'scheduler_safe' => true,
                'provider_invoked' => false,
                'dev_invoked' => false,
                'forge_invoked' => false,
                'branch_created' => false,
                'mutates_target_repo' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sampleContinuousScheduler(): array
    {
        return [
            'schema_version' => 'atlas.continuous_stewardship.recurring_scheduler_run.v1',
            'status' => 'run_recorded',
            'ap_contract' => 'AP-746',
            'area_id' => 'agentic_engineering_os',
            'scheduler_id' => 'atlas_continuous_stewardship_loop',
            'scheduler_run_id' => 'csls_agentic_engineering_os',
            'run_hash' => 'sha256:sample_continuous_scheduler_run',
            'mode' => 'recurring_scheduler_safe_run',
            'tick_status' => 'tick_recorded',
            'tick_id' => 'csl_agentic_engineering_os',
            'tick_hash' => 'sha256:sample_continuous_tick',
            'record_scheduler_run_requested' => true,
            'record_continuous_cycle_requested' => true,
            'policy' => [
                'scheduler_enabled' => true,
                'continuous_loop_enabled' => true,
                'kill_switch_active' => false,
                'min_interval_seconds' => 900,
                'max_ticks_per_run' => 1,
            ],
            'continuous_loop' => [
                'schema_version' => 'atlas.continuous_stewardship.loop_tick.v1',
                'status' => 'tick_recorded',
                'tick_id' => 'csl_agentic_engineering_os',
                'tick_hash' => 'sha256:sample_continuous_tick',
                'blockers' => [],
            ],
            'next_allowed_at' => '2026-05-27T07:00:00+00:00',
            'blockers' => [],
            'next_actions' => [
                'Review AP-746 scheduler run in Product Mode before releasing any Dev/Forge handoff.',
            ],
            'claim_policy' => [
                'recurring_scheduler_safe' => true,
                'installs_scheduler' => false,
                'provider_invoked' => false,
                'dev_invoked' => false,
                'forge_invoked' => false,
                'branch_created' => false,
                'mutates_target_repo' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sampleDevForgeRelease(): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.area_focus_dev_forge_release.v1',
            'status' => 'owner_queue_recorded',
            'ap_contract' => 'AP-747',
            'mode' => 'operator_owned_release',
            'area_id' => 'agentic_engineering_os',
            'release_id' => 'afrel_sample',
            'release_hash' => 'sha256:sample_release',
            'target_owner' => 'atlas_dev',
            'target_runtime_schema' => 'atlas.dev_runtime.v1',
            'record_release_requested' => true,
            'queue_item' => [
                'schema_version' => 'atlas.software_company_stewardship.area_focus_atlas_dev_queue_item.v1',
                'queue_item_id' => 'afq_sample',
                'target_owner' => 'atlas_dev',
                'target_runtime_schema' => 'atlas.dev_runtime.v1',
                'queued_for_real_owner' => true,
                'runtime_execution_started' => false,
                'provider_invoked' => false,
                'branch_created' => false,
            ],
            'blockers' => [],
            'next_actions' => [
                'Review the recorded atlas_dev queue item in Product Mode before starting runtime execution.',
            ],
            'claim_policy' => [
                'requires_operator_release_receipt' => true,
                'released_to_dev_or_forge_queue' => true,
                'runtime_execution_started' => false,
                'provider_invoked' => false,
                'branch_created' => false,
                'target_repo_mutated' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sampleOwnerRuntimeResultBridge(): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.owner_runtime_result_bridge.v1',
            'status' => 'owner_runtime_result_recorded',
            'ap_contract' => 'AP-750',
            'mode' => 'owner_runtime_result_bridge',
            'area_id' => 'agentic_engineering_os',
            'portfolio_id' => 'atlas_software_company',
            'consumption_id' => 'afcons_sample',
            'release_id' => 'afrel_sample',
            'queue_item_id' => 'afq_sample',
            'target_owner' => 'atlas_dev',
            'owner_result_id' => 'afres_sample',
            'owner_result_status' => 'completed',
            'record_result_requested' => true,
            'evidence_items' => [[
                'schema_version' => 'atlas.software_company.stewardship_outcome_evidence.v1',
                'source_kind' => 'ap750_owner_runtime_result',
                'source_id' => 'afres_sample',
                'ledger_status' => 'projected',
            ]],
            'morning_inbox_items' => [[
                'schema_version' => 'atlas.software_company.stewardship_morning_inbox_item.v1',
                'kind' => 'ap750_owner_runtime_result_review',
                'result_id' => 'afres_sample',
                'dedupe_key' => 'stewardship:ap750:afres_sample',
                'recommended_action' => 'review_owner_runtime_result_before_merge_deploy_or_followup',
                'inbox_status' => 'projected',
            ]],
            'portfolio_feed' => [
                'schema_version' => 'atlas.software_company.stewardship_owner_runtime_result_portfolio_feed.v1',
                'areas' => [[
                    'area_id' => 'agentic_engineering_os',
                    'owner_runtime_result_count' => 1,
                    'completed_result_count' => 1,
                ]],
            ],
            'blockers' => [],
            'next_actions' => [
                'Review AP-750 Evidence and Morning Inbox item before any merge, deploy or external push.',
            ],
            'claim_policy' => [
                'owner_runtime_invoked_by_bridge' => false,
                'provider_invoked_by_bridge' => false,
                'merge_performed_by_bridge' => false,
                'deploy_performed_by_bridge' => false,
                'secret_access_by_bridge' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sampleExecutiveAllocationHandoff(): array
    {
        return [
            'schema_version' => 'atlas.autonomous_executive.allocation_handoff.v1',
            'status' => 'ready',
            'ap_contract' => 'AP-752',
            'portfolio_id' => 'atlas_software_company',
            'area_id' => 'agentic_engineering_os',
            'source_pack_id' => 'aer_sample_pack',
            'source_pack_hash' => 'sha256:sample_executive_pack',
            'source_recommendation_id' => 'aer_review_owner_runtime_result',
            'source_decision_id' => 'sdec_accept_executive_allocation',
            'target_id' => 'aer_review_owner_runtime_result',
            'target_hash' => 'sha256:sample_executive_recommendation',
            'recommended_action' => 'review_owner_runtime_result',
            'target_area' => 'agentic_engineering_os',
            'record_allocation_handoff_requested' => false,
            'allocation_handoff_count' => 1,
            'allocation_handoff_packets' => [[
                'schema_version' => 'atlas.autonomous_executive.allocation_handoff_packet.v1',
                'handoff_packet_id' => 'aeah_sample',
                'handoff_status' => 'ready_for_owner_allocation_review',
                'handoff_storage_status' => 'projected',
                'portfolio_id' => 'atlas_software_company',
                'area_id' => 'agentic_engineering_os',
                'target_area' => 'agentic_engineering_os',
                'target_owner' => 'Portfolio/Area owner-runtime result review',
                'target_owner_doc' => 'docs/ap/AP-750-owner-runtime-result-bridge-contract.md',
                'target_owner_contract' => 'AP-750/AP-751',
                'source_pack_id' => 'aer_sample_pack',
                'source_pack_hash' => 'sha256:sample_executive_pack',
                'source_recommendation_id' => 'aer_review_owner_runtime_result',
                'source_decision_id' => 'sdec_accept_executive_allocation',
                'target_id' => 'aer_review_owner_runtime_result',
                'target_hash' => 'sha256:sample_executive_recommendation',
                'recommended_action' => 'review_owner_runtime_result',
                'required_gate_sequence' => [
                    'ap735_recommendation_pack_recorded_or_supplied',
                    'ap731_operator_accept_receipt_present',
                    'ap752_allocation_handoff_packet_review',
                    'target_owner_replays_source_anchors',
                ],
                'allowed_next_actions' => [
                    'review_owner_runtime_result',
                    'request_owner_followup',
                    'defer',
                    'request_changes',
                ],
                'forbidden_actions' => [
                    'auto_execute_recommendation',
                    'invoke_provider',
                    'invoke_atlas_dev',
                    'invoke_forge',
                    'create_branch',
                    'merge',
                    'deploy',
                ],
                'handoff_boundary' => [
                    'handoff_packet_only' => true,
                    'starts_execution' => false,
                    'operator_review_required_before_execution' => true,
                ],
                'blockers' => [],
                'packet_hash' => 'sha256:sample_executive_allocation_packet',
                'claim_policy' => [
                    'handoff_packet_only' => true,
                    'provider_invoked' => false,
                    'dev_invoked' => false,
                    'forge_invoked' => false,
                    'branch_created' => false,
                    'merges' => false,
                    'deploys' => false,
                ],
            ]],
            'blockers' => [],
            'next_actions' => [
                'Review the AP-752 allocation handoff packet before routing any owner work.',
            ],
            'handoff_hash' => 'sha256:sample_executive_allocation_handoff',
            'claim_policy' => [
                'handoff_gate_only' => true,
                'writes_local_state' => false,
                'provider_invoked' => false,
                'dev_invoked' => false,
                'forge_invoked' => false,
                'opens_branch' => false,
                'opens_worktree' => false,
                'merges' => false,
                'deploys' => false,
                'autoimplementation_allowed' => false,
            ],
        ];
    }
}
