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
 *   timeout           -> retry_local
 *   malformed_output  -> retry_local
 *   crash             -> retry_local
 *   lost_login        -> fallback_atlas_native
 *   quota_exhausted   -> fallback_atlas_native
 *   context_loss      -> fallback_atlas_native
 *   ui_brittle        -> fallback_manual_muscle
 *   scope_violation   -> give_back_task
 *   missing_evidence  -> give_back_task
 *   stale_lease       -> give_back_task
 *   (unknown)         -> give_back_task
 *
 * AC4: a retry_local decision is downgraded to verify_before_retry whenever
 * possible_duplicate_commit, stale_allowed_files, or missing_proof_risk is set — a blind
 * retry under those conditions could duplicate a commit, write outside the leased scope, or
 * resolve success without real proof, so it must verify state before touching anything.
 *
 * no_loss_recovery_plan=true only when both task_packet_id and lease_id are
 * preserved (non-empty) in the plan — i.e. the next action keeps Atlas as
 * the queue owner and never lets the failure orphan the lease or duplicate
 * the work.
 *
 * INPUT:
 *   failure_type:   string (stall|timeout|lost_login|quota_exhausted|context_loss|ui_brittle|
 *                     malformed_output|crash|scope_violation|missing_evidence|stale_lease)
 *   task_packet_id?: string (default '')
 *   lease_id?:       string (default '')
 *   client_id?:      string (default '')
 *   allowed_files?:  list<string> (default [])
 *   evidence_status?: string (default '')
 *   possible_duplicate_commit?: bool (default false)
 *   stale_allowed_files?:       bool (default false)
 *   missing_proof_risk?:        bool (default false)
 *
 * OUTPUT:
 *   { schema, decision, task_packet_id, lease_id, allowed_files, evidence_status,
 *     last_verified_evidence, next_safe_action, no_loss_recovery_plan, report_command,
 *     decision_boundary, blind_retry_refused, blind_retry_reasons }
 *
 * Pure: no I/O, no queue mutation, no side effects.
 */
final class AtlasExternalBrainLocalClientRecoveryPlanner
{
    public const SCHEMA = 'atlas.external_brain.local_client_recovery_planner.v1';

    private const FAILURE_TYPE_TO_DECISION = [
        'stall' => 'retry_local',
        'timeout' => 'retry_local',
        'malformed_output' => 'retry_local',
        'crash' => 'retry_local',
        'lost_login' => 'fallback_atlas_native',
        'quota_exhausted' => 'fallback_atlas_native',
        'context_loss' => 'fallback_atlas_native',
        'ui_brittle' => 'fallback_manual_muscle',
        'scope_violation' => 'give_back_task',
        'missing_evidence' => 'give_back_task',
        'stale_lease' => 'give_back_task',
    ];

    private const DECISION_TO_NEXT_SAFE_ACTION = [
        'retry_local' => 'retry the same task locally under the same lease before falling back',
        'verify_before_retry' => 'verify no duplicate commit exists, allowed_files still match the lease, and required proof is present before retrying — do not blindly retry',
        'fallback_atlas_native' => 'release the local client and resume the task via the Atlas-native execution path under the same lease',
        'fallback_manual_muscle' => 'hand the task off to a human-operated manual muscle session, keep the lease reserved',
        'give_back_task' => 'give back the task packet via atlas:task report --outcome=give_back and release the lease',
    ];

    private const DECISION_TO_BOUNDARY = [
        'retry_local' => 'success_if_tests_or_gates_pass_after_retry_else_give_back',
        'verify_before_retry' => 'success_only_after_verification_confirms_no_duplicate_or_scope_drift_else_give_back',
        'fallback_atlas_native' => 'success_if_atlas_native_path_completes_and_proves_else_give_back',
        'fallback_manual_muscle' => 'success_if_manual_muscle_session_completes_and_proves_else_give_back',
        'give_back_task' => 'give_back_only_no_success_path_release_lease_immediately',
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
        $clientId = (string) ($input['client_id'] ?? '');
        $allowedFiles = is_array($input['allowed_files'] ?? null) ? array_values($input['allowed_files']) : [];
        $evidenceStatus = (string) ($input['evidence_status'] ?? '');

        $possibleDuplicateCommit = (bool) ($input['possible_duplicate_commit'] ?? false);
        $staleAllowedFiles = (bool) ($input['stale_allowed_files'] ?? false);
        $missingProofRisk = (bool) ($input['missing_proof_risk'] ?? false);

        $decision = self::FAILURE_TYPE_TO_DECISION[$failureType] ?? 'give_back_task';

        // AC4: refuse a blind retry_local when duplicate-commit, stale-scope, or missing-proof
        // risk is present — none of those risks change the underlying failure classification,
        // they only forbid retrying blindly.
        $blindRetryReasons = [];
        if ($decision === 'retry_local') {
            if ($possibleDuplicateCommit) {
                $blindRetryReasons[] = 'possible_duplicate_commit';
            }
            if ($staleAllowedFiles) {
                $blindRetryReasons[] = 'stale_allowed_files';
            }
            if ($missingProofRisk) {
                $blindRetryReasons[] = 'missing_proof_risk';
            }
            if ($blindRetryReasons !== []) {
                $decision = 'verify_before_retry';
            }
        }

        $noLossRecoveryPlan = $taskPacketId !== '' && $leaseId !== '';

        $reportOutcome = $decision === 'give_back_task' ? 'give_back' : 'success';
        $reportCommand = sprintf(
            'php artisan atlas:task report --client=%s --task=%s --lease=%s --outcome=%s%s',
            $clientId !== '' ? $clientId : '<client_id>',
            $taskPacketId !== '' ? $taskPacketId : '<task_packet_id>',
            $leaseId !== '' ? $leaseId : '<lease_id>',
            $reportOutcome,
            $decision === 'give_back_task' ? '' : ' --commit',
        );

        return [
            'schema' => self::SCHEMA,
            'failure_type' => $failureType,
            'decision' => $decision,
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'allowed_files' => $allowedFiles,
            'evidence_status' => $evidenceStatus,
            'last_verified_evidence' => $evidenceStatus,
            'next_safe_action' => self::DECISION_TO_NEXT_SAFE_ACTION[$decision],
            'no_loss_recovery_plan' => $noLossRecoveryPlan,
            'report_command' => $reportCommand,
            'decision_boundary' => self::DECISION_TO_BOUNDARY[$decision],
            'blind_retry_refused' => $blindRetryReasons !== [],
            'blind_retry_reasons' => $blindRetryReasons,
        ];
    }
}
