<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

/** Single construction seam for the v2 order shared by all engineering modes. */
final class EngineeringModeExecutionOrderFactory
{
    /** @param array<string,mixed> $input */
    public function make(array $input): ExecutionOrder
    {
        $mode = (string) ($input['mode'] ?? '');
        $risk = (string) ($input['risk_class'] ?? '');
        $topology = (string) ($input['work_topology'] ?? '');
        if (! in_array($mode, EngineeringRoleRoster::MODES, true)) {
            throw new InvalidArgumentException('engineering_order_mode_invalid');
        }
        if (! in_array($risk, array_keys(EngineeringRoleRoster::DEPTH_PROFILES), true)) {
            throw new InvalidArgumentException('engineering_order_risk_class_invalid');
        }
        if (! in_array($topology, EngineeringRoleRoster::TOPOLOGIES, true)) {
            throw new InvalidArgumentException('engineering_order_topology_invalid');
        }

        $runHash = (string) ($input['run_hash'] ?? '');
        // P1b.1: never synthesize mode-decision-* fallbacks. Caller must bind a real decision id.
        $decisionEventId = trim((string) ($input['decision_event_id']
            ?? data_get($input, 'decision_receipt.decision_event_id')
            ?? ''));
        if ($decisionEventId === '') {
            throw new InvalidArgumentException('engineering_order_decision_event_id_required');
        }
        $roles = [];
        $roleEvents = [];
        foreach (EngineeringRoleRoster::OFFICIAL_ROLES as $role) {
            $roles[$role] = [
                'depth' => EngineeringRoleRoster::depthProfile($risk),
                'risk_band' => $risk,
                'independent_context' => in_array($role, ['qa_testing', 'evidence_audit', 'final_certification'], true),
            ];
            $roleEvents[$role] = $mode.'-role-'.$runHash.'-'.$role;
        }

        $mutate = (bool) ($input['mutate'] ?? false);
        $order = [
            'schema_version' => 'atlas.execution_order.v2', 'run_id' => (string) ($input['run_id'] ?? ''),
            'delivery_id' => (string) ($input['delivery_id'] ?? ''), 'mode' => $mode, 'risk_class' => $risk,
            'complexity_band' => (string) ($input['complexity_band'] ?? 'C1'),
            'duration_regime' => (string) ($input['duration_regime'] ?? ''), 'work_topology' => $topology,
            'product_intent_verdict_hash' => (string) ($input['product_intent_verdict_hash'] ?? ''),
            'spec_hash' => (string) ($input['spec_hash'] ?? ''), 'world_model_snapshot_hash' => (string) ($input['world_model_snapshot_hash'] ?? ''),
            'workspace' => (string) ($input['workspace'] ?? ''), 'base_commit' => (string) ($input['base_commit'] ?? ''),
            'allowed_scope' => array_values(array_map('strval', (array) ($input['allowed_scope'] ?? []))),
            'forbidden_scope' => array_values(array_map('strval', (array) ($input['forbidden_scope'] ?? []))),
            'authority_envelope' => array_merge(['kind' => 'atlas_shared_engineering_mode'], (array) ($input['authority_envelope'] ?? [])),
            'decision_receipt' => ['decision_event_id' => $decisionEventId],
            'operator_contract' => array_merge(['presence' => (string) ($input['operator_presence'] ?? 'confirmed')], (array) ($input['operator_contract'] ?? [])),
            'role_roster' => $roles,
            'provider_route' => array_merge(['provider' => 'atlas_kernel', 'model' => 'shared_quality_foundry'], (array) ($input['provider_route'] ?? [])),
            'tool_permissions' => ['read' => true, 'mutate' => $mutate],
            'evidence_policy' => ['acceptance_event_id' => $mode.'-acceptance-'.$runHash, 'role_disposition_event_ids' => $roleEvents],
            'release_policy' => ['kind' => (string) ($input['release_kind'] ?? ($mutate ? 'canonical_commit_with_canary' : 'no_release_read_only'))],
            'rollback_policy' => ['kind' => (string) ($input['rollback_kind'] ?? ($mutate ? 'canonical_revert_with_settlement' : 'not_applicable_read_only'))],
            'outcome_policy' => ['windows' => EngineeringOutcome::WINDOWS],
            'experiment_ref' => (string) ($input['experiment_ref'] ?? ($mode.'/'.$runHash)),
            'idempotency_key' => (string) ($input['idempotency_key'] ?? ($mode.':'.$runHash)),
            'budget_posture' => 'unbounded_quality_first',
        ];
        if (array_key_exists('market_decision_hash', $input) && $input['market_decision_hash'] !== null) {
            $order['market_decision_hash'] = (string) $input['market_decision_hash'];
        }

        return ExecutionOrder::fromArray($order);
    }
}
