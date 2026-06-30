<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Plans recovery when a local subscription client stalls, closes, loses
 * login, hits quota, or returns malformed output — without losing the task.
 * Pure planner: it never mutates the queue itself, it only classifies the
 * failure and returns the safe next action for the caller to perform.
 *
 * FAILURE TYPE -> DECISION:
 *   stall             -> retry_local
 *   malformed_output  -> retry_local
 *   lost_login        -> fallback_atlas_native
 *   quota_exhausted   -> fallback_atlas_native
 *   ui_brittle        -> fallback_manual_muscle
 *   scope_violation   -> give_back_task
 *   missing_evidence  -> give_back_task
 *   (unknown)         -> give_back_task
 *
 * no_loss_recovery_plan=true only when both task_packet_id and lease_id are
 * preserved (non-empty) in the plan — i.e. the next action keeps Atlas as
 * the queue owner and never lets the failure orphan the lease or duplicate
 * the work.
 *
 * INPUT:
 *   failure_type:   string (stall|lost_login|quota_exhausted|ui_brittle|malformed_output|scope_violation|missing_evidence)
 *   task_packet_id?: string (default '')
 *   lease_id?:       string (default '')
 *   allowed_files?:  list<string> (default [])
 *   evidence_status?: string (default '')
 *
 * OUTPUT:
 *   { schema, decision, task_packet_id, lease_id, allowed_files,
 *     evidence_status, next_safe_action, no_loss_recovery_plan }
 *
 * Pure: no I/O, no queue mutation, no side effects.
 */
final class AtlasExternalBrainLocalClientRecoveryPlanner
{
    public const SCHEMA = 'atlas.external_brain.local_client_recovery_planner.v1';

    private const FAILURE_TYPE_TO_DECISION = [
        'stall' => 'retry_local',
        'malformed_output' => 'retry_local',
        'lost_login' => 'fallback_atlas_native',
        'quota_exhausted' => 'fallback_atlas_native',
        'ui_brittle' => 'fallback_manual_muscle',
        'scope_violation' => 'give_back_task',
        'missing_evidence' => 'give_back_task',
    ];

    private const DECISION_TO_NEXT_SAFE_ACTION = [
        'retry_local' => 'retry the same task locally under the same lease before falling back',
        'fallback_atlas_native' => 'release the local client and resume the task via the Atlas-native execution path under the same lease',
        'fallback_manual_muscle' => 'hand the task off to a human-operated manual muscle session, keep the lease reserved',
        'give_back_task' => 'give back the task packet via atlas:task report --outcome=give_back and release the lease',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $failureType = (string) ($input['failure_type'] ?? '');
        $taskPacketId = (string) ($input['task_packet_id'] ?? '');
        $leaseId = (string) ($input['lease_id'] ?? '');
        $allowedFiles = is_array($input['allowed_files'] ?? null) ? array_values($input['allowed_files']) : [];
        $evidenceStatus = (string) ($input['evidence_status'] ?? '');

        $decision = self::FAILURE_TYPE_TO_DECISION[$failureType] ?? 'give_back_task';
        $noLossRecoveryPlan = $taskPacketId !== '' && $leaseId !== '';

        return [
            'schema' => self::SCHEMA,
            'failure_type' => $failureType,
            'decision' => $decision,
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'allowed_files' => $allowedFiles,
            'evidence_status' => $evidenceStatus,
            'next_safe_action' => self::DECISION_TO_NEXT_SAFE_ACTION[$decision],
            'no_loss_recovery_plan' => $noLossRecoveryPlan,
        ];
    }
}
