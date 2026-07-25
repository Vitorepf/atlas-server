<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\ProductMode\Support;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDevForgeReleaseService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaStewardship\AreaStewardshipActiveOperatingService;
use App\Services\Ai\SoftwareCompanyStewardship\AutonomousExecutive\AutonomousExecutiveAllocationHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipLoopService;
use App\Services\Ai\SoftwareCompanyStewardship\ContinuousStewardship\AtlasContinuousStewardshipRecurringSchedulerService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeOperationalControlsReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerRuntimeResultBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipOwnerSandboxRuntimeRunnerService;

/**
 * Pure projection helpers for Product Mode cockpit (AP-739).
 *
 * Extracted from ProductModeCockpitSurfaceService private pure methods:
 * reviewQueue, counters, overallHealth, nextActions, claimPolicy.
 * No I/O, no DI, no time side effects, no provider calls.
 */
final class ProductModeCockpitProjectionSupport
{
    /**
     * @param  array<string,mixed>  $executive
     * @param  array<string,mixed>  $newAreaGate
     * @param  array<string,mixed>  $selfExpanding
     * @param  array<string,mixed>  $outcomeHistory
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $areaActiveHandoff
     * @param  array<string,mixed>  $areaActiveOperation
     * @param  array<string,mixed>  $continuousLoop
     * @param  array<string,mixed>  $continuousScheduler
     * @param  array<string,mixed>  $devForgeRelease
     * @param  array<string,mixed>  $ownerSandboxRuntime
     * @param  array<string,mixed>  $ownerRuntimeResult
     * @param  array<string,mixed>  $executiveAllocationHandoff
     * @param  array<string,mixed>  $productModeControls
     * @return list<array<string,mixed>>
     */
    public static function reviewQueue(array $executive, array $newAreaGate, array $selfExpanding, array $outcomeHistory, array $handoff, array $areaActiveHandoff, array $areaActiveOperation, array $continuousLoop, array $continuousScheduler, array $devForgeRelease, array $ownerSandboxRuntime, array $ownerRuntimeResult, array $executiveAllocationHandoff, array $productModeControls): array
    {
        $queue = [];

        if (in_array((string) ($productModeControls['status'] ?? ''), [
            ProductModeOperationalControlsReadModelService::STATUS_BLOCKED,
            ProductModeOperationalControlsReadModelService::STATUS_REVIEW,
        ], true)) {
            $blockers = array_values(array_filter((array) ($productModeControls['blockers'] ?? []), 'is_string'));
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-754',
                'kind' => 'product_mode_operational_controls',
                'id' => (string) ($productModeControls['controls_hash'] ?? ''),
                'title' => 'Review Product Mode operational controls',
                'status' => (string) ($productModeControls['status'] ?? ''),
                'risk_level' => $blockers === [] ? 'medium' : 'critical',
                'target_area' => (string) ($productModeControls['area_id'] ?? ''),
                'priority_score' => 103,
                'decision_anchor' => [
                    'source_ap' => 'AP-754',
                    'controls_hash' => (string) ($productModeControls['controls_hash'] ?? ''),
                    'repo' => (string) data_get($productModeControls, 'repo_onboarding.repository', ''),
                    'autonomy_tier' => (int) data_get($productModeControls, 'autonomy_tiers.current_tier', 0),
                    'kill_switch_active' => (bool) data_get($productModeControls, 'safety_controls.kill_switch_active', false),
                ],
                'blockers' => $blockers,
                'recommended_operator_action' => $blockers === []
                    ? 'review_product_mode_controls_before_enabling_higher_autonomy'
                    : 'resolve_product_mode_control_blockers_before_any_stewardship_execution',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        foreach (array_values(array_filter((array) ($executive['items'] ?? []), 'is_array')) as $item) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-736',
                'kind' => 'autonomous_executive_recommendation',
                'id' => (string) ($item['inbox_item_id'] ?? ''),
                'title' => (string) ($item['title'] ?? 'Review executive recommendation'),
                'status' => (string) ($item['status'] ?? 'pending_operator_review'),
                'risk_level' => (string) ($item['risk_level'] ?? 'medium'),
                'target_area' => (string) ($item['target_area'] ?? ''),
                'priority_score' => (int) ($item['priority_score'] ?? 0),
                'decision_anchor' => is_array($item['stable_decision_anchor'] ?? null) ? $item['stable_decision_anchor'] : [],
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        foreach (array_values(array_filter((array) ($outcomeHistory['morning_inbox_items'] ?? []), 'is_array')) as $item) {
            $sourceAp = (string) ($item['bridge_ap_contract'] ?? $item['source_ap_contract'] ?? 'AP-740');
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => $sourceAp,
                'kind' => (string) ($item['kind'] ?? 'stewardship_outcome_history'),
                'id' => (string) ($item['dedupe_key'] ?? $item['decision_id'] ?? $item['proposal_id'] ?? ''),
                'title' => (string) ($item['title'] ?? 'Review stewardship outcome history'),
                'status' => (string) ($item['inbox_status'] ?? 'projected'),
                'risk_level' => (string) data_get($item, 'review_signal.severity', 'medium'),
                'target_area' => (string) ($item['candidate_area'] ?? $item['area_id'] ?? ''),
                'priority_score' => 0,
                'decision_anchor' => [
                    'source_ap' => $sourceAp,
                    'recommended_action' => (string) ($item['recommended_action'] ?? ''),
                    'source_refs' => array_values(array_filter((array) ($item['source_refs'] ?? []), 'is_array')),
                ],
                'recommended_operator_action' => (string) ($item['recommended_action'] ?? ''),
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        foreach (array_values(array_filter((array) ($handoff['handoff_packets'] ?? []), 'is_array')) as $packet) {
            $blockers = array_values(array_filter((array) ($packet['blockers'] ?? []), 'is_string'));
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-741',
                'kind' => 'domain_runtime_creation_handoff',
                'id' => (string) ($packet['handoff_packet_id'] ?? ''),
                'title' => 'Review Domain Runtime Creation Gate handoff: '.(string) ($packet['candidate_area'] ?? ''),
                'status' => (string) ($packet['handoff_status'] ?? 'blocked'),
                'risk_level' => $blockers === [] ? 'high' : 'critical',
                'target_area' => (string) ($packet['candidate_area'] ?? ''),
                'priority_score' => $blockers === [] ? 90 : 10,
                'decision_anchor' => [
                    'source_ap' => 'AP-741',
                    'handoff_packet_id' => (string) ($packet['handoff_packet_id'] ?? ''),
                    'packet_hash' => (string) ($packet['packet_hash'] ?? ''),
                    'target_gate' => is_array($packet['target_gate'] ?? null) ? $packet['target_gate'] : [],
                ],
                'blockers' => $blockers,
                'recommended_operator_action' => $blockers === []
                    ? 'submit_recorded_packet_to_domain_runtime_creation_gate_review'
                    : 'resolve_handoff_blockers_before_domain_runtime_creation_gate',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        foreach (array_values(array_filter((array) ($areaActiveHandoff['active_handoff_packets'] ?? []), 'is_array')) as $packet) {
            $blockers = array_values(array_filter((array) ($packet['blockers'] ?? []), 'is_string'));
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-743',
                'kind' => 'area_stewardship_active_handoff',
                'id' => (string) ($packet['handoff_packet_id'] ?? ''),
                'title' => 'Review Area Stewardship active handoff: '.(string) ($packet['area_id'] ?? ''),
                'status' => (string) ($packet['handoff_status'] ?? 'blocked'),
                'risk_level' => $blockers === [] ? 'high' : 'critical',
                'target_area' => (string) ($packet['area_id'] ?? ''),
                'priority_score' => $blockers === [] ? 95 : 10,
                'decision_anchor' => [
                    'source_ap' => 'AP-743',
                    'handoff_packet_id' => (string) ($packet['handoff_packet_id'] ?? ''),
                    'packet_hash' => (string) ($packet['packet_hash'] ?? ''),
                    'target_owner' => (string) ($areaActiveHandoff['target_owner'] ?? 'Atlas Area Stewardship Layer'),
                    'source_readiness_report_hash' => (string) ($packet['source_readiness_report_hash'] ?? ''),
                ],
                'blockers' => $blockers,
                'recommended_operator_action' => $blockers === []
                    ? 'record_or_accept_area_stewardship_active_handoff_before_operating_active_mode'
                    : 'resolve_area_stewardship_active_handoff_blockers',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if (in_array((string) ($areaActiveOperation['status'] ?? ''), [
            AreaStewardshipActiveOperatingService::STATUS_READY,
            AreaStewardshipActiveOperatingService::STATUS_PARTIAL,
        ], true)) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-744',
                'kind' => 'area_stewardship_active_operation',
                'id' => (string) ($areaActiveOperation['operation_id'] ?? ''),
                'title' => 'Review Area Stewardship active operation: '.(string) ($areaActiveOperation['area_id'] ?? ''),
                'status' => (string) ($areaActiveOperation['status'] ?? ''),
                'risk_level' => 'high',
                'target_area' => (string) ($areaActiveOperation['area_id'] ?? ''),
                'priority_score' => 96,
                'decision_anchor' => [
                    'source_ap' => 'AP-744',
                    'operation_id' => (string) ($areaActiveOperation['operation_id'] ?? ''),
                    'operation_hash' => (string) ($areaActiveOperation['operation_hash'] ?? ''),
                    'active_handoff_hash' => (string) ($areaActiveOperation['active_handoff_hash'] ?? ''),
                    'operational_cycle_hash' => (string) ($areaActiveOperation['operational_cycle_hash'] ?? ''),
                ],
                'counts' => is_array($areaActiveOperation['counts'] ?? null) ? $areaActiveOperation['counts'] : [],
                'recommended_operator_action' => 'review_active_operation_queue_and_record_ap724_decisions_before_any_dev_forge_release',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if (in_array((string) ($continuousLoop['status'] ?? ''), [
            AtlasContinuousStewardshipLoopService::STATUS_READY_TO_TICK,
            AtlasContinuousStewardshipLoopService::STATUS_TICK_COMPLETED,
            AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED,
            AtlasContinuousStewardshipLoopService::STATUS_RATE_LIMITED,
            AtlasContinuousStewardshipLoopService::STATUS_LOCKED,
        ], true)) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-745',
                'kind' => 'continuous_stewardship_loop_tick',
                'id' => (string) ($continuousLoop['tick_id'] ?? ''),
                'title' => 'Review Continuous Stewardship Loop: '.(string) ($continuousLoop['area_id'] ?? ''),
                'status' => (string) ($continuousLoop['status'] ?? ''),
                'risk_level' => 'high',
                'target_area' => (string) ($continuousLoop['area_id'] ?? ''),
                'priority_score' => 97,
                'decision_anchor' => [
                    'source_ap' => 'AP-745',
                    'tick_id' => (string) ($continuousLoop['tick_id'] ?? ''),
                    'tick_hash' => (string) ($continuousLoop['tick_hash'] ?? ''),
                    'active_operation_hash' => (string) ($continuousLoop['active_operation_hash'] ?? ''),
                    'next_allowed_at' => $continuousLoop['next_allowed_at'] ?? null,
                ],
                'blockers' => array_values(array_filter((array) ($continuousLoop['blockers'] ?? []), 'is_string')),
                'recommended_operator_action' => 'review_continuous_loop_tick_before_any_dev_forge_release_or_scheduler_promotion',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if (in_array((string) ($continuousScheduler['status'] ?? ''), [
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_SCHEDULED,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_COMPLETED,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_RECORDED,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_NOT_DUE,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RATE_LIMITED,
            AtlasContinuousStewardshipRecurringSchedulerService::STATUS_LOCKED,
        ], true)) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-746',
                'kind' => 'continuous_stewardship_scheduler_run',
                'id' => (string) ($continuousScheduler['scheduler_run_id'] ?? ''),
                'title' => 'Review recurring Continuous Stewardship scheduler: '.(string) ($continuousScheduler['area_id'] ?? ''),
                'status' => (string) ($continuousScheduler['status'] ?? ''),
                'risk_level' => 'high',
                'target_area' => (string) ($continuousScheduler['area_id'] ?? ''),
                'priority_score' => 98,
                'decision_anchor' => [
                    'source_ap' => 'AP-746',
                    'scheduler_id' => (string) ($continuousScheduler['scheduler_id'] ?? ''),
                    'scheduler_run_id' => (string) ($continuousScheduler['scheduler_run_id'] ?? ''),
                    'run_hash' => (string) ($continuousScheduler['run_hash'] ?? ''),
                    'tick_hash' => (string) ($continuousScheduler['tick_hash'] ?? ''),
                    'next_allowed_at' => $continuousScheduler['next_allowed_at'] ?? null,
                ],
                'blockers' => array_values(array_filter((array) ($continuousScheduler['blockers'] ?? []), 'is_string')),
                'recommended_operator_action' => in_array((string) ($continuousScheduler['status'] ?? ''), [
                    AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_COMPLETED,
                    AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_RECORDED,
                ], true)
                    ? 'review_recurring_scheduler_run_before_any_dev_forge_release_or_scheduler_expansion'
                    : 'review_recurring_scheduler_policy_before_external_scheduler_invocation',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if (in_array((string) ($devForgeRelease['status'] ?? ''), [
            AreaFocusDevForgeReleaseService::STATUS_READY,
            AreaFocusDevForgeReleaseService::STATUS_RECORDED,
            AreaFocusDevForgeReleaseService::STATUS_BLOCKED,
        ], true)) {
            $blockers = array_values(array_filter((array) ($devForgeRelease['blockers'] ?? []), 'is_string'));
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-747',
                'kind' => 'area_focus_dev_forge_release',
                'id' => (string) ($devForgeRelease['release_id'] ?? ''),
                'title' => 'Review Area Focus Dev/Forge release: '.(string) ($devForgeRelease['area_id'] ?? ''),
                'status' => (string) ($devForgeRelease['status'] ?? ''),
                'risk_level' => $blockers === [] ? 'high' : 'critical',
                'target_area' => (string) ($devForgeRelease['area_id'] ?? ''),
                'priority_score' => 99,
                'decision_anchor' => [
                    'source_ap' => 'AP-747',
                    'release_id' => (string) ($devForgeRelease['release_id'] ?? ''),
                    'release_hash' => (string) ($devForgeRelease['release_hash'] ?? ''),
                    'target_owner' => (string) ($devForgeRelease['target_owner'] ?? ''),
                    'queue_item_id' => (string) data_get($devForgeRelease, 'queue_item.queue_item_id', ''),
                ],
                'blockers' => $blockers,
                'recommended_operator_action' => $blockers === []
                    ? 'review_owner_queue_item_before_dev_forge_runtime_execution'
                    : 'resolve_ap747_release_blockers_before_any_dev_forge_queue_release',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if ((int) data_get($outcomeHistory, 'release_outcome_summary.release_count', 0) > 0) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-749',
                'kind' => 'owner_queue_consumption_gate',
                'id' => (string) data_get($outcomeHistory, 'release_outcome_summary.queue_item_ids.0', ''),
                'title' => 'Run AP-749 owner queue consumption gate before Dev/Forge runtime input',
                'status' => 'operator_review_required',
                'risk_level' => 'high',
                'target_area' => (string) ($outcomeHistory['area_id'] ?? ''),
                'priority_score' => 100,
                'decision_anchor' => [
                    'source_ap' => 'AP-749',
                    'release_file' => '<ap747.jsonl>',
                    'outcome_file' => '<ap748.json>',
                    'queue_item_ids' => array_values((array) data_get($outcomeHistory, 'release_outcome_summary.queue_item_ids', [])),
                ],
                'recommended_operator_action' => 'run_owner_queue_consumption_gate_before_owner_runtime_input',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if (in_array((string) ($ownerSandboxRuntime['status'] ?? ''), [
            StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED,
            StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY,
            StewardshipOwnerSandboxRuntimeRunnerService::STATUS_RECORDED,
            StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED,
        ], true)) {
            $blockers = array_values(array_filter((array) ($ownerSandboxRuntime['blockers'] ?? []), 'is_string'));
            $commandPlan = is_array($ownerSandboxRuntime['command_plan'] ?? null) ? $ownerSandboxRuntime['command_plan'] : [];
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-759',
                'kind' => 'owner_sandbox_runtime_runner',
                'id' => (string) ($ownerSandboxRuntime['owner_sandbox_run_id'] ?? $commandPlan['run_id'] ?? ''),
                'title' => 'Review AP-759 sandboxed owner runtime command',
                'status' => (string) ($ownerSandboxRuntime['status'] ?? ''),
                'risk_level' => $blockers === [] ? 'high' : 'critical',
                'target_area' => (string) ($ownerSandboxRuntime['area_id'] ?? ''),
                'target_owner' => (string) ($ownerSandboxRuntime['target_owner'] ?? ''),
                'priority_score' => 101,
                'decision_anchor' => [
                    'source_ap' => 'AP-759',
                    'owner_sandbox_run_id' => (string) ($ownerSandboxRuntime['owner_sandbox_run_id'] ?? $commandPlan['run_id'] ?? ''),
                    'owner_execution_id' => (string) ($ownerSandboxRuntime['owner_execution_id'] ?? ''),
                    'command_hash' => (string) ($commandPlan['command_hash'] ?? ''),
                    'command_display' => (string) ($commandPlan['command_display'] ?? ''),
                    'requires_provider_authority' => (bool) ($commandPlan['requires_provider_authority'] ?? false),
                    'worktree_path_hash' => (string) ($commandPlan['worktree_path_hash'] ?? ''),
                    'ap750_owner_result_id' => (string) data_get($ownerSandboxRuntime, 'ap750_bridge_input.owner_result_id', data_get($ownerSandboxRuntime, 'owner_result.result_id', '')),
                ],
                'blockers' => $blockers,
                'recommended_operator_action' => match ((string) ($ownerSandboxRuntime['status'] ?? '')) {
                    StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED => 'review_ap759_owner_runtime_command_plan_before_execute',
                    StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY,
                    StewardshipOwnerSandboxRuntimeRunnerService::STATUS_RECORDED => 'feed_ap759_owner_result_into_ap750_before_merge_deploy_or_followup',
                    default => 'resolve_ap759_owner_sandbox_runtime_blockers_before_execution',
                },
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        if (in_array((string) ($ownerRuntimeResult['status'] ?? ''), [
            StewardshipOwnerRuntimeResultBridgeService::STATUS_READY,
            StewardshipOwnerRuntimeResultBridgeService::STATUS_RECORDED,
            StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED,
        ], true)) {
            $blockers = array_values(array_filter((array) ($ownerRuntimeResult['blockers'] ?? []), 'is_string'));
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-750',
                'kind' => 'owner_runtime_result_bridge',
                'id' => (string) ($ownerRuntimeResult['owner_result_id'] ?? $ownerRuntimeResult['result_bridge_id'] ?? ''),
                'title' => 'Review AP-750 owner runtime result before merge/deploy/follow-up',
                'status' => (string) ($ownerRuntimeResult['status'] ?? ''),
                'risk_level' => $blockers === [] ? 'high' : 'critical',
                'target_area' => (string) ($ownerRuntimeResult['area_id'] ?? ''),
                'priority_score' => 101,
                'decision_anchor' => [
                    'source_ap' => 'AP-750',
                    'consumption_id' => (string) ($ownerRuntimeResult['consumption_id'] ?? ''),
                    'owner_result_id' => (string) ($ownerRuntimeResult['owner_result_id'] ?? ''),
                    'target_owner' => (string) ($ownerRuntimeResult['target_owner'] ?? ''),
                ],
                'blockers' => $blockers,
                'recommended_operator_action' => $blockers === []
                    ? 'review_owner_runtime_result_before_merge_deploy_or_followup'
                    : 'resolve_ap750_result_bridge_blockers_before_any_followup',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        foreach (array_values(array_filter((array) ($executiveAllocationHandoff['allocation_handoff_packets'] ?? []), 'is_array')) as $packet) {
            $blockers = array_values(array_filter((array) ($packet['blockers'] ?? []), 'is_string'));
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-752',
                'kind' => 'executive_allocation_handoff',
                'id' => (string) ($packet['handoff_packet_id'] ?? ''),
                'title' => 'Review Autonomous Executive allocation handoff: '.(string) ($packet['target_area'] ?? ''),
                'status' => (string) ($packet['handoff_status'] ?? $executiveAllocationHandoff['status'] ?? 'blocked'),
                'risk_level' => $blockers === [] ? 'high' : 'critical',
                'target_area' => (string) ($packet['target_area'] ?? $executiveAllocationHandoff['target_area'] ?? ''),
                'priority_score' => 102,
                'decision_anchor' => [
                    'source_ap' => 'AP-752',
                    'handoff_packet_id' => (string) ($packet['handoff_packet_id'] ?? ''),
                    'packet_hash' => (string) ($packet['packet_hash'] ?? ''),
                    'source_pack_id' => (string) ($packet['source_pack_id'] ?? ''),
                    'source_recommendation_id' => (string) ($packet['source_recommendation_id'] ?? ''),
                    'source_decision_id' => (string) ($packet['source_decision_id'] ?? ''),
                    'target_owner' => (string) ($packet['target_owner'] ?? ''),
                    'target_owner_contract' => (string) ($packet['target_owner_contract'] ?? ''),
                    'target_owner_doc' => (string) ($packet['target_owner_doc'] ?? ''),
                ],
                'allowed_next_actions' => array_values(array_filter((array) ($packet['allowed_next_actions'] ?? []), 'is_string')),
                'blockers' => $blockers,
                'recommended_operator_action' => $blockers === []
                    ? 'review_ap752_allocation_handoff_before_routing_owner_work'
                    : 'resolve_ap752_allocation_handoff_blockers_before_owner_routing',
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        foreach (array_values(array_filter((array) ($newAreaGate['gate_items'] ?? []), 'is_array')) as $item) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-737',
                'kind' => is_array($item['known_existing_owner'] ?? null)
                    ? 'existing_capability_handoff'
                    : 'new_area_proposal',
                'id' => (string) ($item['gate_item_id'] ?? ''),
                'title' => 'Review expansion proposal: '.(string) ($item['candidate_area'] ?? ''),
                'status' => (string) ($item['gate_status'] ?? 'awaiting_operator_review'),
                'risk_level' => (string) ($item['risk_level'] ?? 'medium'),
                'target_area' => (string) ($item['candidate_area'] ?? ''),
                'priority_score' => 0,
                'decision_anchor' => is_array($item['operator_decision_anchor'] ?? null) ? $item['operator_decision_anchor'] : [],
                'blockers' => array_values(array_filter((array) ($item['blockers'] ?? []), 'is_string')),
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        $selfItems = array_values(array_filter((array) data_get($selfExpanding, 'operator_inbox.items', []), 'is_array'));
        foreach ($selfItems as $item) {
            $queue[] = [
                'schema_version' => 'atlas.software_company.product_mode_cockpit.review_item.v1',
                'source_ap' => 'AP-738',
                'kind' => 'self_expanding_operator_inbox',
                'id' => (string) ($item['inbox_item_id'] ?? ''),
                'title' => 'Self-expanding review: '.(string) ($item['candidate_area'] ?? ''),
                'status' => (string) ($item['gate_status'] ?? 'awaiting_operator_review'),
                'risk_level' => 'medium',
                'target_area' => (string) ($item['candidate_area'] ?? ''),
                'priority_score' => 0,
                'decision_anchor' => is_array($item['decision_anchor'] ?? null) ? $item['decision_anchor'] : [],
                'recommended_operator_action' => (string) ($item['recommended_operator_action'] ?? ''),
                'irreversible_action_allowed' => false,
                'autoimplementation_allowed' => false,
            ];
        }

        usort($queue, static function (array $a, array $b): int {
            $riskRank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
            $ar = $riskRank[(string) ($a['risk_level'] ?? '')] ?? 0;
            $br = $riskRank[(string) ($b['risk_level'] ?? '')] ?? 0;
            if ($ar !== $br) {
                return $br <=> $ar;
            }

            return ((int) ($b['priority_score'] ?? 0)) <=> ((int) ($a['priority_score'] ?? 0));
        });

        return $queue;
    }

    /**
     * @param  array<string,mixed>  $areaFocus
     * @param  array<string,mixed>  $executive
     * @param  array<string,mixed>  $newAreaGate
     * @param  array<string,mixed>  $selfExpanding
     * @param  array<string,mixed>  $outcomeHistory
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $areaActiveHandoff
     * @param  array<string,mixed>  $areaActiveOperation
     * @param  array<string,mixed>  $continuousLoop
     * @param  array<string,mixed>  $continuousScheduler
     * @param  array<string,mixed>  $devForgeRelease
     * @param  array<string,mixed>  $ownerSandboxRuntime
     * @param  array<string,mixed>  $ownerRuntimeResult
     * @param  array<string,mixed>  $executiveAllocationHandoff
     * @param  array<string,mixed>  $productModeControls
     * @param  list<array<string,mixed>>  $reviewQueue
     * @return array<string,int>
     */
    public static function counters(array $areaFocus, array $executive, array $newAreaGate, array $selfExpanding, array $outcomeHistory, array $handoff, array $areaActiveHandoff, array $areaActiveOperation, array $continuousLoop, array $continuousScheduler, array $devForgeRelease, array $ownerSandboxRuntime, array $ownerRuntimeResult, array $executiveAllocationHandoff, array $productModeControls, array $reviewQueue): array
    {
        return [
            'area_findings' => (int) data_get($areaFocus, 'findings.total', 0),
            'area_inbox_items' => count((array) ($areaFocus['inbox_items'] ?? [])),
            'work_orders' => count((array) ($areaFocus['work_orders'] ?? [])),
            'executive_items' => (int) ($executive['item_count'] ?? 0),
            'executive_pending_review' => (int) data_get($executive, 'decision_summary.pending_operator_review', 0),
            'new_area_gate_items' => (int) ($newAreaGate['gate_item_count'] ?? 0),
            'new_area_blocked_review' => (int) data_get($newAreaGate, 'decision_summary.blocked_awaiting_operator_review', 0),
            'self_expanding_inbox_items' => (int) data_get($selfExpanding, 'operator_inbox.item_count', 0),
            'outcome_evidence_items' => (int) ($outcomeHistory['evidence_item_count'] ?? 0),
            'outcome_morning_inbox_items' => (int) ($outcomeHistory['morning_inbox_item_count'] ?? 0),
            'release_outcome_count' => (int) data_get($outcomeHistory, 'release_outcome_summary.release_count', 0),
            'release_portfolio_feed_areas' => count((array) data_get($outcomeHistory, 'portfolio_feed.areas', [])),
            'domain_handoff_packets' => (int) ($handoff['accepted_candidate_count'] ?? count((array) ($handoff['handoff_packets'] ?? []))),
            'ready_domain_handoffs' => (int) ($handoff['ready_handoff_count'] ?? 0),
            'blocked_domain_handoffs' => (int) ($handoff['blocked_handoff_count'] ?? 0),
            'area_active_handoff_packets' => (int) ($areaActiveHandoff['active_handoff_count'] ?? count((array) ($areaActiveHandoff['active_handoff_packets'] ?? []))),
            'ready_area_active_handoffs' => (int) (($areaActiveHandoff['status'] ?? '') === AreaStewardshipActiveHandoffService::STATUS_READY ? ($areaActiveHandoff['active_handoff_count'] ?? 0) : 0),
            'pending_area_active_acceptance' => (int) (($areaActiveHandoff['status'] ?? '') === AreaStewardshipActiveHandoffService::STATUS_AWAITING_OPERATOR_ACCEPTANCE ? 1 : 0),
            'area_active_operations' => (int) (in_array((string) ($areaActiveOperation['status'] ?? ''), [AreaStewardshipActiveOperatingService::STATUS_READY, AreaStewardshipActiveOperatingService::STATUS_PARTIAL], true) ? 1 : 0),
            'ready_area_active_operations' => (int) (($areaActiveOperation['status'] ?? '') === AreaStewardshipActiveOperatingService::STATUS_READY ? 1 : 0),
            'partial_area_active_operations' => (int) (($areaActiveOperation['status'] ?? '') === AreaStewardshipActiveOperatingService::STATUS_PARTIAL ? 1 : 0),
            'area_active_operation_work_orders' => (int) data_get($areaActiveOperation, 'counts.work_orders', 0),
            'area_active_operation_spec_drafts' => (int) data_get($areaActiveOperation, 'counts.spec_drafts', 0),
            'ready_area_active_operation_handoffs' => (int) data_get($areaActiveOperation, 'counts.ready_branch_handoffs', 0),
            'continuous_loop_paused' => (int) (($continuousLoop['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_PAUSED ? 1 : 0),
            'continuous_loop_ready_to_tick' => (int) (($continuousLoop['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_READY_TO_TICK ? 1 : 0),
            'continuous_loop_ticks' => (int) (in_array((string) ($continuousLoop['status'] ?? ''), [AtlasContinuousStewardshipLoopService::STATUS_TICK_COMPLETED, AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED], true) ? 1 : 0),
            'continuous_loop_recorded_ticks' => (int) (($continuousLoop['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_TICK_RECORDED ? 1 : 0),
            'continuous_loop_rate_limited' => (int) (($continuousLoop['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_RATE_LIMITED ? 1 : 0),
            'continuous_loop_locked' => (int) (($continuousLoop['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_LOCKED ? 1 : 0),
            'continuous_scheduler_paused' => (int) (($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_PAUSED ? 1 : 0),
            'continuous_scheduler_scheduled' => (int) (($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_SCHEDULED ? 1 : 0),
            'continuous_scheduler_runs' => (int) (in_array((string) ($continuousScheduler['status'] ?? ''), [AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_COMPLETED, AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_RECORDED], true) ? 1 : 0),
            'continuous_scheduler_recorded_runs' => (int) (($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RUN_RECORDED ? 1 : 0),
            'continuous_scheduler_not_due' => (int) (($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_NOT_DUE ? 1 : 0),
            'continuous_scheduler_rate_limited' => (int) (($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_RATE_LIMITED ? 1 : 0),
            'continuous_scheduler_locked' => (int) (($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_LOCKED ? 1 : 0),
            'dev_forge_releases' => (int) (in_array((string) ($devForgeRelease['status'] ?? ''), [AreaFocusDevForgeReleaseService::STATUS_READY, AreaFocusDevForgeReleaseService::STATUS_RECORDED], true) ? 1 : 0),
            'dev_forge_recorded_releases' => (int) (($devForgeRelease['status'] ?? '') === AreaFocusDevForgeReleaseService::STATUS_RECORDED ? 1 : 0),
            'dev_forge_release_blocked' => (int) (($devForgeRelease['status'] ?? '') === AreaFocusDevForgeReleaseService::STATUS_BLOCKED ? 1 : 0),
            'owner_sandbox_runtime_runs' => (int) (in_array((string) ($ownerSandboxRuntime['status'] ?? ''), [StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED, StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY, StewardshipOwnerSandboxRuntimeRunnerService::STATUS_RECORDED], true) ? 1 : 0),
            'owner_sandbox_runtime_planned_runs' => (int) (($ownerSandboxRuntime['status'] ?? '') === StewardshipOwnerSandboxRuntimeRunnerService::STATUS_PLANNED ? 1 : 0),
            'owner_sandbox_runtime_ready_results' => (int) (($ownerSandboxRuntime['status'] ?? '') === StewardshipOwnerSandboxRuntimeRunnerService::STATUS_READY ? 1 : 0),
            'owner_sandbox_runtime_recorded_runs' => (int) (($ownerSandboxRuntime['status'] ?? '') === StewardshipOwnerSandboxRuntimeRunnerService::STATUS_RECORDED ? 1 : 0),
            'owner_sandbox_runtime_blocked' => (int) (($ownerSandboxRuntime['status'] ?? '') === StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED ? 1 : 0),
            'owner_sandbox_runtime_changed_files' => count((array) ($ownerSandboxRuntime['changed_files'] ?? [])),
            'owner_runtime_results' => (int) (in_array((string) ($ownerRuntimeResult['status'] ?? ''), [StewardshipOwnerRuntimeResultBridgeService::STATUS_READY, StewardshipOwnerRuntimeResultBridgeService::STATUS_RECORDED], true) ? 1 : 0),
            'owner_runtime_recorded_results' => (int) (($ownerRuntimeResult['status'] ?? '') === StewardshipOwnerRuntimeResultBridgeService::STATUS_RECORDED ? 1 : 0),
            'owner_runtime_result_blocked' => (int) (($ownerRuntimeResult['status'] ?? '') === StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED ? 1 : 0),
            'owner_runtime_result_evidence_items' => count((array) ($ownerRuntimeResult['evidence_items'] ?? [])),
            'owner_runtime_result_inbox_items' => count((array) ($ownerRuntimeResult['morning_inbox_items'] ?? [])),
            'owner_runtime_result_portfolio_feed_areas' => count((array) data_get($ownerRuntimeResult, 'portfolio_feed.areas', [])),
            'executive_allocation_handoff_packets' => (int) ($executiveAllocationHandoff['allocation_handoff_count'] ?? count((array) ($executiveAllocationHandoff['allocation_handoff_packets'] ?? []))),
            'ready_executive_allocation_handoffs' => (int) (($executiveAllocationHandoff['status'] ?? '') === AutonomousExecutiveAllocationHandoffService::STATUS_READY ? ($executiveAllocationHandoff['allocation_handoff_count'] ?? count((array) ($executiveAllocationHandoff['allocation_handoff_packets'] ?? []))) : 0),
            'awaiting_executive_allocation_acceptance' => (int) (($executiveAllocationHandoff['status'] ?? '') === AutonomousExecutiveAllocationHandoffService::STATUS_AWAITING_OPERATOR_ACCEPTANCE ? 1 : 0),
            'blocked_executive_allocation_handoffs' => (int) (($executiveAllocationHandoff['status'] ?? '') === AutonomousExecutiveAllocationHandoffService::STATUS_BLOCKED ? 1 : 0),
            'product_mode_control_blockers' => count((array) ($productModeControls['blockers'] ?? [])),
            'product_mode_control_review_required' => (int) (($productModeControls['status'] ?? '') === ProductModeOperationalControlsReadModelService::STATUS_REVIEW ? 1 : 0),
            'product_mode_control_blocked' => (int) (($productModeControls['status'] ?? '') === ProductModeOperationalControlsReadModelService::STATUS_BLOCKED ? 1 : 0),
            'product_mode_pending_branch_reviews' => (int) data_get($productModeControls, 'branch_review_center.pending_review_count', 0),
            'product_mode_missing_evidence_refs' => count((array) data_get($productModeControls, 'evidence_inspector.missing_refs', [])),
            'review_queue_items' => count($reviewQueue),
            'ready_for_domain_runtime_creation_gate' => (int) data_get($selfExpanding, 'expansion_summary.ready_for_domain_runtime_creation_gate', 0),
        ];
    }

    /**
     * @param  array<string,int>  $counters
     * @param  array<string,mixed>  $executive
     * @param  array<string,mixed>  $newAreaGate
     * @param  array<string,mixed>  $selfExpanding
     * @param  array<string,mixed>  $outcomeHistory
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $areaActiveHandoff
     * @param  array<string,mixed>  $areaActiveOperation
     * @param  array<string,mixed>  $continuousLoop
     * @param  array<string,mixed>  $continuousScheduler
     * @param  array<string,mixed>  $devForgeRelease
     * @param  array<string,mixed>  $ownerSandboxRuntime
     * @param  array<string,mixed>  $ownerRuntimeResult
     * @param  array<string,mixed>  $executiveAllocationHandoff
     * @param  array<string,mixed>  $productModeControls
     */
    public static function overallHealth(array $counters, array $executive, array $newAreaGate, array $selfExpanding, array $outcomeHistory, array $handoff, array $areaActiveHandoff, array $areaActiveOperation, array $continuousLoop, array $continuousScheduler, array $devForgeRelease, array $ownerSandboxRuntime, array $ownerRuntimeResult, array $executiveAllocationHandoff, array $productModeControls): string
    {
        if (($executive['status'] ?? '') === ProductModeCockpitSurfaceService::STATUS_BLOCKED
            || ($newAreaGate['status'] ?? '') === ProductModeCockpitSurfaceService::STATUS_BLOCKED
            || ($selfExpanding['status'] ?? '') === ProductModeCockpitSurfaceService::STATUS_BLOCKED
            || ($outcomeHistory['status'] ?? '') === ProductModeCockpitSurfaceService::STATUS_BLOCKED
            || ($handoff['status'] ?? '') === ProductModeCockpitSurfaceService::STATUS_BLOCKED
            || ($areaActiveHandoff['status'] ?? '') === AreaStewardshipActiveHandoffService::STATUS_BLOCKED
            || ($areaActiveOperation['status'] ?? '') === AreaStewardshipActiveOperatingService::STATUS_BLOCKED
            || ($continuousLoop['status'] ?? '') === AtlasContinuousStewardshipLoopService::STATUS_BLOCKED
            || ($continuousScheduler['status'] ?? '') === AtlasContinuousStewardshipRecurringSchedulerService::STATUS_BLOCKED
            || ($devForgeRelease['status'] ?? '') === AreaFocusDevForgeReleaseService::STATUS_BLOCKED
            || ($ownerSandboxRuntime['status'] ?? '') === StewardshipOwnerSandboxRuntimeRunnerService::STATUS_BLOCKED
            || ($ownerRuntimeResult['status'] ?? '') === StewardshipOwnerRuntimeResultBridgeService::STATUS_BLOCKED
            || ($executiveAllocationHandoff['status'] ?? '') === AutonomousExecutiveAllocationHandoffService::STATUS_BLOCKED
            || ($productModeControls['status'] ?? '') === ProductModeOperationalControlsReadModelService::STATUS_BLOCKED) {
            return ProductModeCockpitSurfaceService::STATUS_BLOCKED;
        }

        if (($counters['new_area_blocked_review'] ?? 0) > 0
            || ($counters['executive_pending_review'] ?? 0) > 0
            || ($counters['outcome_morning_inbox_items'] ?? 0) > 0
            || ($counters['domain_handoff_packets'] ?? 0) > 0
            || ($counters['area_active_handoff_packets'] ?? 0) > 0
            || ($counters['area_active_operations'] ?? 0) > 0
            || ($counters['pending_area_active_acceptance'] ?? 0) > 0
            || ($counters['continuous_loop_ready_to_tick'] ?? 0) > 0
            || ($counters['continuous_loop_ticks'] ?? 0) > 0
            || ($counters['continuous_loop_rate_limited'] ?? 0) > 0
            || ($counters['continuous_loop_locked'] ?? 0) > 0
            || ($counters['continuous_scheduler_scheduled'] ?? 0) > 0
            || ($counters['continuous_scheduler_runs'] ?? 0) > 0
            || ($counters['continuous_scheduler_not_due'] ?? 0) > 0
            || ($counters['continuous_scheduler_rate_limited'] ?? 0) > 0
            || ($counters['continuous_scheduler_locked'] ?? 0) > 0
            || ($counters['dev_forge_releases'] ?? 0) > 0
            || ($counters['dev_forge_release_blocked'] ?? 0) > 0
            || ($counters['owner_sandbox_runtime_runs'] ?? 0) > 0
            || ($counters['owner_sandbox_runtime_blocked'] ?? 0) > 0
            || ($counters['owner_runtime_results'] ?? 0) > 0
            || ($counters['owner_runtime_result_blocked'] ?? 0) > 0
            || ($counters['executive_allocation_handoff_packets'] ?? 0) > 0
            || ($counters['awaiting_executive_allocation_acceptance'] ?? 0) > 0
            || ($counters['product_mode_control_review_required'] ?? 0) > 0
            || ($counters['product_mode_pending_branch_reviews'] ?? 0) > 0
            || ($counters['product_mode_missing_evidence_refs'] ?? 0) > 0) {
            return 'review';
        }

        return ProductModeCockpitSurfaceService::STATUS_READY;
    }

    /**
     * @param  array<string,int>  $counters
     * @return list<string>
     */
    public static function nextActions(array $counters): array
    {
        $actions = [];

        if (($counters['executive_pending_review'] ?? 0) > 0) {
            $actions[] = 'Review AP-736 executive recommendation(s) in Product Mode before allocating governed cycles.';
        }
        if (($counters['new_area_blocked_review'] ?? 0) > 0) {
            $actions[] = 'Review AP-737 existing-capability handoff blockers; route known capabilities to Area Stewardship instead of creating domains.';
        }
        if (($counters['ready_for_domain_runtime_creation_gate'] ?? 0) > 0) {
            $actions[] = 'Review AP-740 outcome history and prepare AP-741 handoff packet for Domain Runtime Creation Gate; do not create a domain directly.';
        }
        if (($counters['outcome_morning_inbox_items'] ?? 0) > 0) {
            $actions[] = 'Record or emit AP-740 outcome evidence only through the existing Evidence Ledger and Morning Inbox owners.';
        }
        if (($counters['release_outcome_count'] ?? 0) > 0) {
            $actions[] = 'Run AP-749 owner queue consumption gate after AP-748 release outcomes are reviewed.';
        }
        if (($counters['ready_domain_handoffs'] ?? 0) > 0) {
            $actions[] = 'Submit AP-741 handoff packet to Domain Runtime Creation Gate review; no domain runtime is created by the cockpit.';
        }
        if (($counters['blocked_domain_handoffs'] ?? 0) > 0) {
            $actions[] = 'Resolve AP-741 handoff blockers before any Domain Runtime Creation Gate review.';
        }
        if (($counters['pending_area_active_acceptance'] ?? 0) > 0) {
            $actions[] = 'Record AP-731 accept for Area Stewardship before AP-743 can create an active handoff packet.';
        }
        if (($counters['ready_area_active_handoffs'] ?? 0) > 0) {
            $actions[] = 'Review AP-743 active handoff packet before AP-744 active operation; no irreversible action starts from the cockpit.';
        }
        if (($counters['ready_area_active_operations'] ?? 0) > 0) {
            $actions[] = 'Review AP-744 active operation queue; release Dev/Forge handoffs only through explicit operator-owned controls.';
        }
        if (($counters['partial_area_active_operations'] ?? 0) > 0) {
            $actions[] = 'Record AP-724 decisions for AP-744 pending work orders before any Dev/Forge handoff can be released.';
        }
        if (($counters['continuous_loop_ready_to_tick'] ?? 0) > 0) {
            $actions[] = 'AP-745 admits one scheduler-safe tick; keep Product Mode kill switch, rate limit and review queue visible.';
        }
        if (($counters['continuous_loop_ticks'] ?? 0) > 0) {
            $actions[] = 'Review AP-745 tick output before promoting any continuous scheduler or releasing AP-726 handoffs.';
        }
        if (($counters['continuous_loop_rate_limited'] ?? 0) > 0) {
            $actions[] = 'Respect AP-745 min interval before scheduling another Continuous Stewardship tick.';
        }
        if (($counters['continuous_loop_locked'] ?? 0) > 0) {
            $actions[] = 'Inspect AP-745 lock lease before retrying a continuous tick.';
        }
        if (($counters['continuous_scheduler_scheduled'] ?? 0) > 0) {
            $actions[] = 'AP-746 scheduler runner is due; invoke at most one AP-745 tick from operator-owned scheduler controls.';
        }
        if (($counters['continuous_scheduler_runs'] ?? 0) > 0) {
            $actions[] = 'Review AP-746 scheduler run evidence before expanding recurring cadence or releasing Dev/Forge handoffs.';
        }
        if (($counters['continuous_scheduler_not_due'] ?? 0) > 0) {
            $actions[] = 'Respect AP-746/AP-745 cadence before the next recurring scheduler invocation.';
        }
        if (($counters['continuous_scheduler_rate_limited'] ?? 0) > 0) {
            $actions[] = 'Treat AP-746 rate limit as a hard stop unless the operator explicitly uses manual verification force.';
        }
        if (($counters['continuous_scheduler_locked'] ?? 0) > 0) {
            $actions[] = 'Inspect AP-746/AP-745 lock state before retrying the recurring scheduler.';
        }
        if (($counters['dev_forge_releases'] ?? 0) > 0) {
            $actions[] = 'Use AP-749 before owner-specific runtime input; no merge/deploy/secrets are authorized by the cockpit.';
        }
        if (($counters['dev_forge_release_blocked'] ?? 0) > 0) {
            $actions[] = 'Resolve AP-747 release blockers before any AP-726 handoff reaches Atlas Dev or Forge.';
        }
        if (($counters['owner_sandbox_runtime_planned_runs'] ?? 0) > 0) {
            $actions[] = 'Review AP-759 sandboxed owner command plan before allowing execution inside the AP-756 worktree.';
        }
        if (($counters['owner_sandbox_runtime_ready_results'] ?? 0) > 0) {
            $actions[] = 'Feed AP-759 owner_result into AP-750 before merge, deploy, follow-up allocation or Portfolio rebalance.';
        }
        if (($counters['owner_sandbox_runtime_recorded_runs'] ?? 0) > 0) {
            $actions[] = 'Review recorded AP-759 owner sandbox run and bridge its owner_result through AP-750.';
        }
        if (($counters['owner_sandbox_runtime_blocked'] ?? 0) > 0) {
            $actions[] = 'Resolve AP-759 sandbox command blockers before owner runtime execution.';
        }
        if (($counters['owner_runtime_results'] ?? 0) > 0) {
            $actions[] = 'Review AP-750 owner runtime result evidence before merge, deploy, follow-up allocation or Portfolio rebalance.';
        }
        if (($counters['owner_runtime_result_blocked'] ?? 0) > 0) {
            $actions[] = 'Resolve AP-750 result bridge blockers before the owner runtime outcome feeds Portfolio or any follow-up.';
        }
        if (($counters['awaiting_executive_allocation_acceptance'] ?? 0) > 0) {
            $actions[] = 'Record AP-731 accept for the selected AP-735 executive recommendation before AP-752 can route allocation to an owner.';
        }
        if (($counters['ready_executive_allocation_handoffs'] ?? 0) > 0) {
            $actions[] = 'Review AP-752 allocation handoff in Product Mode before any owner performs follow-up work.';
        }
        if (($counters['blocked_executive_allocation_handoffs'] ?? 0) > 0) {
            $actions[] = 'Resolve AP-752 blockers before routing accepted executive allocation to Area, Portfolio, Dev or Forge owners.';
        }
        if (($counters['product_mode_control_blocked'] ?? 0) > 0) {
            $actions[] = 'Resolve AP-754 Product Mode control blockers before admitting more stewardship work.';
        }
        if (($counters['product_mode_control_review_required'] ?? 0) > 0) {
            $actions[] = 'Review AP-754 Product Mode controls before raising autonomy, enabling continuous cadence or releasing branches.';
        }
        if (($counters['product_mode_pending_branch_reviews'] ?? 0) > 0) {
            $actions[] = 'Review pending branch items in owner runtimes; Product Mode never merges or deploys directly.';
        }
        if (($counters['product_mode_missing_evidence_refs'] ?? 0) > 0) {
            $actions[] = 'Attach missing AP-754 evidence refs before claiming Product Mode completion.';
        }

        $actions[] = 'Keep this cockpit read-only: decisions go through AP-731 receipts and execution goes through Dev/Forge under branch isolation.';

        return $actions;
    }

    /**
     * @return array<string,bool|string>
     */
    public static function claimPolicy(): array
    {
        return [
            'read_only_over_repo' => true,
            'surface_only' => true,
            'writes_local_state' => false,
            'records_operator_decisions' => false,
            'provider_invoked' => false,
            'dev_invoked' => false,
            'forge_invoked' => false,
            'branch_created' => false,
            'worktree_created' => false,
            'domain_runtime_created' => false,
            'department_created' => false,
            'scheduler_installed' => false,
            'continuous_loop_tick_executed_by_cockpit' => false,
            'recurring_scheduler_executed_by_cockpit' => false,
            'dev_forge_release_executed_by_cockpit' => false,
            'owner_queue_consumption_executed_by_cockpit' => false,
            'owner_sandbox_runtime_runner_executed_by_cockpit' => false,
            'owner_runtime_result_bridge_executed_by_cockpit' => false,
            'executive_allocation_handoff_executed_by_cockpit' => false,
            'product_mode_controls_execute_actions' => false,
            'product_mode_controls_authorize_repository' => false,
            'product_mode_controls_change_autonomy_tier' => false,
            'new_os_created' => false,
            'parallel_runtime_created' => false,
            'merge_without_operator' => false,
            'deploy_without_operator' => false,
            'secret_access' => false,
            'autoapproval_allowed' => false,
            'autoimplementation_allowed' => false,
            'auto_promotion' => false,
            'operator_review_required' => true,
        ];
    }
}
