<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Executes one bounded local terminal-loop proof:
 * auto-replenish -> claim/lease -> one-shot worker packet -> structured
 * evidence -> complete_dry_run -> post-cycle digest. It never executes
 * provider work or marks real completion; it only mutates the local task
 * queue/lease registries with dry-run proof receipts.
 */
final class AgentControlPlaneTerminalLoopOperationalProofService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof.v1';

    public const MODE = 'local_agent_control_plane_terminal_loop_operational_proof';

    public function __construct(
        private readonly ?AgentControlPlaneTaskQueueOrchestrator $orchestrator = null,
        private readonly ?AgentControlPlaneTerminalLoopHealthDigestService $healthDigest = null,
        private readonly ?AgentControlPlaneTerminalWorkerBootstrapService $bootstrap = null,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function prove(array $options = []): array
    {
        $requestedProofId = trim((string) ($options['proof_id'] ?? ''));
        $proofId = $this->safeToken($requestedProofId !== '' ? $requestedProofId : (string) Str::ulid(), 'proof');
        $actor = $this->safeToken((string) ($options['actor'] ?? 'terminal-loop-proof'), 'terminal-loop-proof');
        $tag = 'terminal_loop_operational_proof_'.$proofId;

        $orchestrator = $this->orchestrator();
        $beforeDigest = $this->healthDigest()->digest([
            'actor' => $actor,
            'queue_tags' => [$tag],
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
        ]);
        $bootstrap = $this->bootstrap()->bootstrap([
            'terminal_bootstrap_probe' => [
                'enabled' => true,
                'namespace' => $tag,
                'target_task_count' => 1,
            ],
        ], [
            'actor' => $actor,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$tag],
            'reason' => 'terminal_loop_operational_proof',
            'lease_minutes' => 10,
        ]);
        $taskPacketId = (string) data_get($bootstrap, 'task_packet_id', '');
        $leaseId = (string) data_get($bootstrap, 'lease_id', '');
        $allowedFiles = $this->stringList((array) data_get($bootstrap, 'one_shot_worker_packet.lease.write_set', []));
        $completionEvidence = [];
        $completion = [
            'event' => 'complete_dry_run_not_attempted',
        ];

        if ((string) data_get($bootstrap, 'status') === 'ready_for_worker' && $taskPacketId !== '' && $leaseId !== '') {
            $completionEvidence = [
                'packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'actor' => $actor,
                'files_changed' => $allowedFiles,
                'commands_run' => [
                    'terminal_loop_operational_proof: terminal_worker_bootstrap_auto_replenish',
                    'terminal_loop_operational_proof: complete_dry_run',
                ],
                'tests_or_gates_result' => 'passed',
                'git_status_short' => 'storage-only terminal loop operational proof',
                'git_diff_check_result' => 'clean',
            ];
            $completionEvidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($completionEvidence);
            $completion = $orchestrator->completeDryRun(
                $taskPacketId,
                $leaseId,
                $completionEvidence,
            );
        }

        $recoveryResumeProof = $this->proveRecoveryResumePath($actor, $tag, $orchestrator);
        $recoveryResumeProofHash = $this->stableHash($recoveryResumeProof);
        $validationRejectionProof = $this->proveValidationRejectionPath($actor, $tag, $orchestrator);
        $validationRejectionProofHash = $this->stableHash($validationRejectionProof);
        $fleetConcurrencyProof = $this->proveFleetConcurrencyPath($orchestrator);
        $fleetConcurrencyProofHash = $this->stableHash($fleetConcurrencyProof);

        $afterDigest = $this->healthDigest()->digest([
            'actor' => $actor,
            'queue_tags' => [$tag],
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
        ]);
        $resumePacket = $this->resumePacket($actor, $proofId, $tag, $afterDigest);
        $resumePacketHash = $this->stableHash($resumePacket);
        $invariants = [
            'before_digest_started_with_empty_lane' => (int) data_get($beforeDigest, 'queue_health.claimable_task_count', -1) === 0
                && (int) data_get($beforeDigest, 'queue_health.claimed_task_count', -1) === 0,
            'auto_replenishment_generated_task' => (string) data_get($bootstrap, 'auto_replenishment_status') === 'available'
                && (int) data_get($bootstrap, 'generated_task_count', 0) >= 1,
            'bootstrap_ready_for_worker' => (string) data_get($bootstrap, 'status') === 'ready_for_worker',
            'one_shot_worker_packet_ready' => (bool) data_get($bootstrap, 'one_shot_worker_packet_ready', false),
            'claim_acquired_active_lease' => (string) data_get($bootstrap, 'claim_event') === 'claimed' && $leaseId !== '',
            'completion_recorded_as_dry_run' => (string) ($completion['event'] ?? '') === 'completed_dry_run',
            'structured_completion_evidence_valid' => (bool) data_get($completion, 'evidence_validation.structured_completion_evidence_valid', false),
            'completion_evidence_hash_matches_payload' => (bool) data_get($completion, 'evidence_validation.evidence_hash_matches_payload', false),
            'completion_evidence_files_within_scope' => (bool) data_get($completion, 'evidence_validation.files_changed_within_allowed_scope', false)
                && data_get($completion, 'evidence_validation.files_changed_outside_allowed_scope', []) === [],
            'recovery_release_recorded' => (string) data_get($recoveryResumeProof, 'release_event') === 'lease_release'
                && (string) data_get($recoveryResumeProof, 'release_status') === 'ok',
            'recovery_resume_packet_ready' => (string) data_get($recoveryResumeProof, 'resume_packet_event') === 'build_resume_packet_ready'
                && (string) data_get($recoveryResumeProof, 'resume_safe_next_action') === 'requeue_released_task_via_recovery',
            'recovery_requeued_task_to_claimable' => (string) data_get($recoveryResumeProof, 'recover_released_event') === 'recover_released_tasks'
                && (int) data_get($recoveryResumeProof, 'recover_released_count', 0) === 1,
            'recovery_reclaimed_with_fresh_lease' => (string) data_get($recoveryResumeProof, 'resume_claim_event') === 'claimed'
                && (string) data_get($recoveryResumeProof, 'resume_lease_id', '') !== ''
                && (string) data_get($recoveryResumeProof, 'resume_lease_id') !== (string) data_get($recoveryResumeProof, 'interrupted_lease_id'),
            'recovery_completed_resumed_task_as_dry_run' => (string) data_get($recoveryResumeProof, 'resume_completion_event') === 'completed_dry_run'
                && (bool) data_get($recoveryResumeProof, 'resume_completion_evidence_valid', false),
            'invalid_completion_evidence_rejected' => (string) data_get($validationRejectionProof, 'invalid_completion_event') === 'complete_dry_run_blocked'
                && (bool) data_get($validationRejectionProof, 'invalid_completion_blocked_by_scope', false)
                && (bool) data_get($validationRejectionProof, 'invalid_completion_real_allowed', true) === false,
            'hash_mismatch_completion_evidence_rejected' => (string) data_get($validationRejectionProof, 'hash_mismatch_completion_event') === 'complete_dry_run_blocked'
                && (bool) data_get($validationRejectionProof, 'hash_mismatch_blocked_by_hash', false)
                && (bool) data_get($validationRejectionProof, 'hash_mismatch_real_allowed', true) === false,
            'valid_completion_after_rejection_recorded' => (string) data_get($validationRejectionProof, 'valid_completion_event') === 'completed_dry_run'
                && (bool) data_get($validationRejectionProof, 'valid_completion_evidence_valid', false),
            'fleet_concurrency_certification_available' => (string) data_get($fleetConcurrencyProof, 'status') === 'available'
                && (bool) data_get($fleetConcurrencyProof, 'invariants_all_true', false),
            'fleet_concurrency_claims_distinct' => (int) data_get($fleetConcurrencyProof, 'distinct_task_total', 0) === 2
                && (int) data_get($fleetConcurrencyProof, 'distinct_lease_total', 0) === 2,
            'fleet_concurrency_write_sets_disjoint' => (int) data_get($fleetConcurrencyProof, 'write_set_collision_count', 1) === 0,
            'fleet_concurrency_cleanup_green' => (bool) data_get($fleetConcurrencyProof, 'cleanup_left_no_recoverable_artifacts', false),
            'lease_closed_after_completion' => (int) data_get($afterDigest, 'lease_health.active_lease_count', 1) === 0,
            'no_claimed_task_after_completion' => (int) data_get($afterDigest, 'queue_health.claimed_task_count', 1) === 0,
            'no_recoverable_lease_after_completion' => (int) data_get($afterDigest, 'lease_health.recoverable_lease_count', 1) === 0,
            'post_cycle_evidence_rollup_green' => (string) data_get($afterDigest, 'terminal_loop_fleet_evidence_rollup.status') === 'fleet_evidence_rollup_green',
            'post_cycle_cycle_supervisor_reviews_evidence' => (string) data_get($afterDigest, 'terminal_loop_cycle_supervisor.schema_version') === AgentControlPlaneTerminalLoopHealthDigestService::CYCLE_SUPERVISOR_SCHEMA_VERSION
                && (string) data_get($afterDigest, 'terminal_loop_cycle_supervisor.status') === 'cycle_evidence_review_ready'
                && (string) data_get($afterDigest, 'terminal_loop_cycle_supervisor.cycle_state') === 'review_evidence'
                && (string) data_get($afterDigest, 'terminal_loop_cycle_supervisor.next_command_purpose') === 'review_completed_dry_run_evidence_and_rerun_digest'
                && (bool) data_get($afterDigest, 'terminal_loop_cycle_supervisor.next_command_is_lane_bound') === true
                && (bool) data_get($afterDigest, 'terminal_loop_cycle_supervisor.can_execute_next_command') === false
                && (bool) data_get($afterDigest, 'terminal_loop_cycle_supervisor.can_complete_from_supervisor') === false
                && (bool) data_get($afterDigest, 'terminal_loop_cycle_supervisor.can_call_provider_from_supervisor') === false
                && (bool) data_get($afterDigest, 'terminal_loop_cycle_supervisor.can_spend_tokens_from_supervisor') === false
                && (string) data_get($afterDigest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash') !== '',
            'post_cycle_digest_can_resume_without_chat_history' => (bool) data_get($afterDigest, 'loop_decision.can_loop_without_chat_history', false),
            'resume_packet_ready_for_next_terminal' => (bool) data_get($resumePacket, 'can_resume_without_chat_history', false)
                && (string) data_get($resumePacket, 'recommended_action', '') !== ''
                && (bool) data_get($resumePacket, 'resume_attention_required', true) === false
                && (bool) data_get($resumePacket, 'next_cycle_certificate.all_commands_lane_bound', false)
                && (string) data_get($resumePacket, 'resume_rollup_hash', '') !== ''
                && (string) data_get($resumePacket, 'operator_handoff_hash', '') !== ''
                && (string) data_get($resumePacket, 'health_digest_hash', '') !== '',
            'completion_real_allowed_false' => (bool) data_get($completion, 'completion_real_allowed', true) === false,
            'runtime_safety_all_false' => $this->runtimeSafetyAllFalse($completion, $afterDigest),
        ];
        $operationalReadinessMatrix = $this->operationalReadinessMatrix($invariants, [
            'auto_replenishment_hash' => (string) data_get($bootstrap, 'auto_replenishment_hash', ''),
            'one_shot_packet_hash' => (string) data_get($bootstrap, 'one_shot_packet_hash', ''),
            'completion_evidence_validation_hash' => (string) data_get($completion, 'evidence_validation.evidence_validation_hash', ''),
            'recovery_resume_proof_hash' => $recoveryResumeProofHash,
            'validation_rejection_proof_hash' => $validationRejectionProofHash,
            'fleet_concurrency_proof_hash' => $fleetConcurrencyProofHash,
            'resume_packet_hash' => $resumePacketHash,
            'post_cycle_health_digest_hash' => (string) data_get($afterDigest, 'terminal_loop_health_digest_hash', ''),
            'post_cycle_cycle_supervisor_hash' => (string) data_get($afterDigest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash', ''),
        ]);
        $invariants['operational_readiness_matrix_all_true'] = (bool) $operationalReadinessMatrix['all_true'];
        $violations = array_keys(array_filter($invariants, static fn (bool $passed): bool => ! $passed));
        $status = $violations === [] ? 'passed' : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'proof_id' => $proofId,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'actor' => $actor,
            'queue_tags' => [$tag],
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'allowed_files' => $allowedFiles,
            'auto_replenishment_status' => (string) data_get($bootstrap, 'auto_replenishment_status', ''),
            'auto_replenishment_generated_task_count' => (int) data_get($bootstrap, 'generated_task_count', 0),
            'auto_replenishment_hash' => (string) data_get($bootstrap, 'auto_replenishment_hash', ''),
            'bootstrap_status' => (string) data_get($bootstrap, 'status', ''),
            'one_shot_worker_packet_ready' => (bool) data_get($bootstrap, 'one_shot_worker_packet_ready', false),
            'one_shot_packet_hash' => (string) data_get($bootstrap, 'one_shot_packet_hash', ''),
            'prepare_event' => (string) data_get($bootstrap, 'auto_replenishment.event', ''),
            'claim_event' => (string) data_get($bootstrap, 'claim_event', ''),
            'completion_event' => (string) ($completion['event'] ?? ''),
            'completion_evidence_hash' => (string) ($completionEvidence['evidence_hash'] ?? ''),
            'completion_evidence_validation_hash' => (string) data_get($completion, 'evidence_validation.evidence_validation_hash', ''),
            'post_cycle_health_digest_hash' => (string) data_get($afterDigest, 'terminal_loop_health_digest_hash', ''),
            'post_cycle_evidence_rollup_status' => (string) data_get($afterDigest, 'terminal_loop_fleet_evidence_rollup.status', ''),
            'post_cycle_completed_dry_run_task_count' => (int) data_get($afterDigest, 'terminal_loop_fleet_evidence_rollup.completed_dry_run_task_count', 0),
            'post_cycle_valid_completion_evidence_count' => (int) data_get($afterDigest, 'terminal_loop_fleet_evidence_rollup.valid_completion_evidence_count', 0),
            'post_cycle_claimed_task_count' => (int) data_get($afterDigest, 'queue_health.claimed_task_count', 0),
            'post_cycle_active_lease_count' => (int) data_get($afterDigest, 'lease_health.active_lease_count', 0),
            'post_cycle_recoverable_lease_count' => (int) data_get($afterDigest, 'lease_health.recoverable_lease_count', 0),
            'post_cycle_cleanup_state' => [
                'claimed_task_count' => (int) data_get($afterDigest, 'queue_health.claimed_task_count', 0),
                'active_lease_count' => (int) data_get($afterDigest, 'lease_health.active_lease_count', 0),
                'recoverable_lease_count' => (int) data_get($afterDigest, 'lease_health.recoverable_lease_count', 0),
                'completed_dry_run_task_count' => (int) data_get($afterDigest, 'terminal_loop_fleet_evidence_rollup.completed_dry_run_task_count', 0),
                'valid_completion_evidence_count' => (int) data_get($afterDigest, 'terminal_loop_fleet_evidence_rollup.valid_completion_evidence_count', 0),
            ],
            'post_cycle_cycle_supervisor_status' => (string) data_get($afterDigest, 'terminal_loop_cycle_supervisor.status', ''),
            'post_cycle_cycle_supervisor_cycle_state' => (string) data_get($afterDigest, 'terminal_loop_cycle_supervisor.cycle_state', ''),
            'post_cycle_cycle_supervisor_next_command_purpose' => (string) data_get($afterDigest, 'terminal_loop_cycle_supervisor.next_command_purpose', ''),
            'post_cycle_cycle_supervisor_hash' => (string) data_get($afterDigest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash', ''),
            'invariants' => $invariants,
            'invariants_all_true' => $violations === [],
            'violations' => $violations,
            'violation_count' => count($violations),
            'operational_readiness_matrix' => $operationalReadinessMatrix,
            'operational_readiness_matrix_hash' => $this->stableHash($operationalReadinessMatrix),
            'before_digest' => $this->digestSummary($beforeDigest),
            'after_digest' => $this->digestSummary($afterDigest),
            'resume_packet' => $resumePacket,
            'resume_packet_hash' => $resumePacketHash,
            'recovery_resume_proof' => $recoveryResumeProof,
            'recovery_resume_proof_hash' => $recoveryResumeProofHash,
            'validation_rejection_proof' => $validationRejectionProof,
            'validation_rejection_proof_hash' => $validationRejectionProofHash,
            'fleet_concurrency_proof' => $fleetConcurrencyProof,
            'fleet_concurrency_proof_hash' => $fleetConcurrencyProofHash,
            'completion_real_allowed' => false,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                'terminal_loop_operational_proof_does_not_call_provider',
                'terminal_loop_operational_proof_does_not_spend_tokens',
                'terminal_loop_operational_proof_does_not_dispatch_work',
                'terminal_loop_operational_proof_does_not_start_codex',
                'terminal_loop_operational_proof_does_not_mark_real_completion',
                'terminal_loop_operational_proof_records_only_local_dry_run_evidence',
            ],
        ];
        $payload['terminal_loop_operational_proof_hash'] = $this->stableHash($payload);
        $payload['completion_audit_binding_packet'] = $this->completionAuditBindingPacket($payload);
        $payload['completion_audit_binding_packet_hash'] = $this->stableHash((array) $payload['completion_audit_binding_packet']);

        return $payload;
    }

    /**
     * Build a compact, non-executing packet that can be supplied to the
     * read-only OS completion audit without replaying the whole local proof.
     *
     * @param  array<string, mixed>  $proof
     * @return array<string, mixed>
     */
    private function completionAuditBindingPacket(array $proof): array
    {
        $proofPayload = [
            'status' => (string) data_get($proof, 'status', ''),
            'invariants_all_true' => (bool) data_get($proof, 'invariants_all_true', false),
            'operational_readiness_matrix' => [
                'all_true' => (bool) data_get($proof, 'operational_readiness_matrix.all_true', false),
            ],
            'post_cycle_cycle_supervisor' => [
                'status' => (string) data_get($proof, 'post_cycle_cycle_supervisor_status', ''),
                'cycle_state' => (string) data_get($proof, 'post_cycle_cycle_supervisor_cycle_state', ''),
                'next_command_purpose' => (string) data_get($proof, 'post_cycle_cycle_supervisor_next_command_purpose', ''),
                'hash' => (string) data_get($proof, 'post_cycle_cycle_supervisor_hash', ''),
            ],
            'post_cycle_cleanup_state' => [
                'claimed_task_count' => (int) data_get($proof, 'post_cycle_cleanup_state.claimed_task_count', data_get($proof, 'post_cycle_claimed_task_count', 0)),
                'active_lease_count' => (int) data_get($proof, 'post_cycle_cleanup_state.active_lease_count', data_get($proof, 'post_cycle_active_lease_count', 0)),
                'recoverable_lease_count' => (int) data_get($proof, 'post_cycle_cleanup_state.recoverable_lease_count', data_get($proof, 'post_cycle_recoverable_lease_count', 0)),
                'completed_dry_run_task_count' => (int) data_get($proof, 'post_cycle_cleanup_state.completed_dry_run_task_count', data_get($proof, 'post_cycle_completed_dry_run_task_count', 0)),
                'valid_completion_evidence_count' => (int) data_get($proof, 'post_cycle_cleanup_state.valid_completion_evidence_count', data_get($proof, 'post_cycle_valid_completion_evidence_count', 0)),
            ],
            'completion_real_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'terminal_loop_operational_proof_hash' => (string) data_get($proof, 'terminal_loop_operational_proof_hash', ''),
        ];

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'status' => (string) data_get($proof, 'status') === 'passed' ? 'ready_for_read_only_completion_audit' : 'blocked',
            'audit_option_key' => 'agent_control_plane_terminal_loop_operational_proof',
            'expected_audit_binding' => [
                'service' => AtlasSelfConstructionOsCompletionAuditService::class,
                'method' => 'audit',
                'option_key' => 'agent_control_plane_terminal_loop_operational_proof',
                'proof_payload_path' => 'completion_audit_binding_packet.proof_payload',
            ],
            'proof_payload' => $proofPayload,
            'proof_payload_hash' => $this->stableHash($proofPayload),
            'source_proof_hash' => (string) data_get($proof, 'terminal_loop_operational_proof_hash', ''),
            'source_operational_readiness_matrix_hash' => (string) data_get($proof, 'operational_readiness_matrix_hash', ''),
            'expected_proof_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json',
            'expected_binding_artifact_path' => '/path/to/terminal-loop-operational-proof-binding.json',
            'expected_completion_audit_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json',
            'diagnostic_completion_audit_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            'can_mark_completion_from_binding' => false,
            'can_execute_from_binding' => false,
            'can_dispatch_from_binding' => false,
            'can_call_provider_from_binding' => false,
            'can_spend_tokens_from_binding' => false,
            'self_programming_allowed_from_binding' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proveValidationRejectionPath(string $actor, string $tag, AgentControlPlaneTaskQueueOrchestrator $orchestrator): array
    {
        $validationActor = $actor.'-validation';
        $bootstrap = $this->bootstrap()->bootstrap([
            'terminal_bootstrap_probe' => [
                'enabled' => true,
                'namespace' => $tag.'_validation',
                'target_task_count' => 1,
            ],
        ], [
            'actor' => $validationActor,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$tag],
            'reason' => 'terminal_loop_operational_validation_rejection_proof',
            'lease_minutes' => 10,
        ]);
        $taskPacketId = (string) data_get($bootstrap, 'task_packet_id', '');
        $leaseId = (string) data_get($bootstrap, 'lease_id', '');
        $allowedFiles = $this->stringList((array) data_get($bootstrap, 'one_shot_worker_packet.lease.write_set', []));
        $hashMismatchCompletion = [];
        $hashMismatchEvidence = [];
        $invalidCompletion = [];
        $validCompletion = [];
        $invalidEvidence = [];
        $validEvidence = [];

        if ((string) data_get($bootstrap, 'status') === 'ready_for_worker' && $taskPacketId !== '' && $leaseId !== '') {
            $hashMismatchEvidence = [
                'packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'actor' => $validationActor,
                'files_changed' => $allowedFiles,
                'commands_run' => [
                    'terminal_loop_operational_proof: submit_hash_mismatch_completion_evidence',
                ],
                'tests_or_gates_result' => 'passed',
                'git_status_short' => 'intentional hash mismatch evidence probe',
                'git_diff_check_result' => 'clean',
                'evidence_hash' => str_repeat('b', 64),
            ];
            $hashMismatchCompletion = $orchestrator->completeDryRun($taskPacketId, $leaseId, $hashMismatchEvidence);

            $invalidEvidence = [
                'packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'actor' => $validationActor,
                'files_changed' => array_merge($allowedFiles, ['outside/scope/forbidden.txt']),
                'commands_run' => [
                    'terminal_loop_operational_proof: submit_invalid_completion_evidence',
                ],
                'tests_or_gates_result' => 'passed',
                'git_status_short' => 'intentional invalid evidence probe',
                'git_diff_check_result' => 'clean',
            ];
            $invalidEvidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($invalidEvidence);
            $invalidCompletion = $orchestrator->completeDryRun($taskPacketId, $leaseId, $invalidEvidence);

            $validEvidence = [
                'packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'actor' => $validationActor,
                'files_changed' => $allowedFiles,
                'commands_run' => [
                    'terminal_loop_operational_proof: invalid_completion_evidence_rejected',
                    'terminal_loop_operational_proof: submit_corrected_completion_evidence',
                ],
                'tests_or_gates_result' => 'passed',
                'git_status_short' => 'storage-only terminal loop validation proof',
                'git_diff_check_result' => 'clean',
            ];
            $validEvidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($validEvidence);
            $validCompletion = $orchestrator->completeDryRun($taskPacketId, $leaseId, $validEvidence);
        }

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_validation_rejection_proof.v1',
            'status' => (string) data_get($hashMismatchCompletion, 'event') === 'complete_dry_run_blocked'
                && (string) data_get($invalidCompletion, 'event') === 'complete_dry_run_blocked'
                && (string) data_get($validCompletion, 'event') === 'completed_dry_run'
                    ? 'passed'
                    : 'blocked',
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'actor' => $validationActor,
            'hash_mismatch_completion_event' => (string) data_get($hashMismatchCompletion, 'event', ''),
            'hash_mismatch_completion_reason' => (string) data_get($hashMismatchCompletion, 'reason', ''),
            'hash_mismatch_completion_blockers' => (array) data_get($hashMismatchCompletion, 'evidence_validation.blockers', []),
            'hash_mismatch_blocked_by_hash' => in_array('evidence_hash_mismatch', (array) data_get($hashMismatchCompletion, 'evidence_validation.blockers', []), true),
            'hash_mismatch_operator_hash' => (string) ($hashMismatchEvidence['evidence_hash'] ?? ''),
            'hash_mismatch_computed_hash' => (string) data_get($hashMismatchCompletion, 'evidence_validation.computed_evidence_hash', ''),
            'hash_mismatch_real_allowed' => (bool) data_get($hashMismatchCompletion, 'completion_real_allowed', false),
            'invalid_completion_event' => (string) data_get($invalidCompletion, 'event', ''),
            'invalid_completion_reason' => (string) data_get($invalidCompletion, 'reason', ''),
            'invalid_completion_blockers' => (array) data_get($invalidCompletion, 'evidence_validation.blockers', []),
            'invalid_completion_blocked_by_scope' => in_array('files_changed_outside_allowed_scope', (array) data_get($invalidCompletion, 'evidence_validation.blockers', []), true),
            'invalid_completion_evidence_hash' => (string) ($invalidEvidence['evidence_hash'] ?? ''),
            'invalid_completion_real_allowed' => (bool) data_get($invalidCompletion, 'completion_real_allowed', false),
            'valid_completion_event' => (string) data_get($validCompletion, 'event', ''),
            'valid_completion_evidence_hash' => (string) ($validEvidence['evidence_hash'] ?? ''),
            'valid_completion_evidence_valid' => (bool) data_get($validCompletion, 'evidence_validation.structured_completion_evidence_valid', false),
            'valid_completion_real_allowed' => (bool) data_get($validCompletion, 'completion_real_allowed', false),
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
        ];
    }

    /**
     * @param  array<string, bool>  $invariants
     * @param  array<string, string>  $hashes
     * @return array<string, mixed>
     */
    private function operationalReadinessMatrix(array $invariants, array $hashes): array
    {
        $rows = [
            $this->matrixRow(
                'auto_replenishment_claim_and_one_shot_packet',
                [
                    'before_digest_started_with_empty_lane',
                    'auto_replenishment_generated_task',
                    'bootstrap_ready_for_worker',
                    'one_shot_worker_packet_ready',
                    'claim_acquired_active_lease',
                ],
                $invariants,
                [$hashes['auto_replenishment_hash'] ?? '', $hashes['one_shot_packet_hash'] ?? ''],
            ),
            $this->matrixRow(
                'structured_completion_evidence_acceptance',
                [
                    'completion_recorded_as_dry_run',
                    'structured_completion_evidence_valid',
                    'completion_evidence_hash_matches_payload',
                    'completion_evidence_files_within_scope',
                ],
                $invariants,
                [$hashes['completion_evidence_validation_hash'] ?? ''],
            ),
            $this->matrixRow(
                'interruption_recovery_resume',
                [
                    'recovery_release_recorded',
                    'recovery_resume_packet_ready',
                    'recovery_requeued_task_to_claimable',
                    'recovery_reclaimed_with_fresh_lease',
                    'recovery_completed_resumed_task_as_dry_run',
                ],
                $invariants,
                [$hashes['recovery_resume_proof_hash'] ?? ''],
            ),
            $this->matrixRow(
                'invalid_evidence_rejection_then_correction',
                [
                    'hash_mismatch_completion_evidence_rejected',
                    'invalid_completion_evidence_rejected',
                    'valid_completion_after_rejection_recorded',
                ],
                $invariants,
                [$hashes['validation_rejection_proof_hash'] ?? ''],
            ),
            $this->matrixRow(
                'fleet_concurrency_and_cleanup',
                [
                    'fleet_concurrency_certification_available',
                    'fleet_concurrency_claims_distinct',
                    'fleet_concurrency_write_sets_disjoint',
                    'fleet_concurrency_cleanup_green',
                ],
                $invariants,
                [$hashes['fleet_concurrency_proof_hash'] ?? ''],
            ),
            $this->matrixRow(
                'next_cycle_resume_packet',
                [
                    'post_cycle_digest_can_resume_without_chat_history',
                    'resume_packet_ready_for_next_terminal',
                ],
                $invariants,
                [$hashes['resume_packet_hash'] ?? ''],
            ),
            $this->matrixRow(
                'post_cycle_cycle_supervisor_evidence_review',
                [
                    'post_cycle_cycle_supervisor_reviews_evidence',
                ],
                $invariants,
                [$hashes['post_cycle_cycle_supervisor_hash'] ?? ''],
            ),
            $this->matrixRow(
                'post_cycle_health_and_runtime_safety',
                [
                    'lease_closed_after_completion',
                    'no_claimed_task_after_completion',
                    'no_recoverable_lease_after_completion',
                    'post_cycle_evidence_rollup_green',
                    'completion_real_allowed_false',
                    'runtime_safety_all_false',
                ],
                $invariants,
                [$hashes['post_cycle_health_digest_hash'] ?? ''],
            ),
        ];
        $failedRows = array_values(array_filter($rows, static fn (array $row): bool => ! (bool) ($row['passed'] ?? false)));

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_readiness_matrix.v1',
            'row_count' => count($rows),
            'passed_row_count' => count($rows) - count($failedRows),
            'failed_row_count' => count($failedRows),
            'all_true' => $failedRows === [],
            'rows' => $rows,
            'failed_rows' => array_map(static fn (array $row): string => (string) $row['id'], $failedRows),
        ];
    }

    /**
     * @param  list<string>  $requiredInvariants
     * @param  array<string, bool>  $invariants
     * @param  list<string>  $evidenceHashes
     * @return array<string, mixed>
     */
    private function matrixRow(string $id, array $requiredInvariants, array $invariants, array $evidenceHashes): array
    {
        $missing = array_values(array_filter(
            $requiredInvariants,
            static fn (string $key): bool => ! (bool) ($invariants[$key] ?? false),
        ));
        $hashes = array_values(array_filter(
            $evidenceHashes,
            static fn (string $hash): bool => preg_match('/^[a-f0-9]{64}$/', $hash) === 1,
        ));

        return [
            'id' => $id,
            'passed' => $missing === [] && $hashes !== [],
            'required_invariants' => $requiredInvariants,
            'missing_invariants' => $missing,
            'evidence_hashes' => $hashes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proveFleetConcurrencyPath(AgentControlPlaneTaskQueueOrchestrator $orchestrator): array
    {
        $certification = (new AgentControlPlaneMultiAgentLoopCertificationService(
            $orchestrator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
        ))->certify([
            'agent_count' => 2,
            'cycles' => 1,
            'target_min_claimable_tasks' => 2,
        ]);

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_fleet_concurrency_proof.v1',
            'status' => (string) data_get($certification, 'status', ''),
            'agent_count' => (int) data_get($certification, 'agent_count', 0),
            'cycles' => (int) data_get($certification, 'cycles', 0),
            'invariants_all_true' => (bool) data_get($certification, 'invariants_all_true', false),
            'violation_count' => (int) data_get($certification, 'violation_count', 0),
            'distinct_task_total' => (int) data_get($certification, 'distinct_task_total', 0),
            'distinct_lease_total' => (int) data_get($certification, 'distinct_lease_total', 0),
            'completed_total' => (int) data_get($certification, 'completed_total', 0),
            'write_set_collision_count' => (int) data_get($certification, 'cycle_evidence.0.write_set_collision_count', 0),
            'cleanup_left_no_recoverable_artifacts' => (bool) data_get($certification, 'post_cleanup_health_digest_probe.cleanup_left_no_recoverable_artifacts', false),
            'runtime_safety_all_false' => (bool) data_get($certification, 'runtime_safety.runtime_safety_all_false', false),
            'certification_hash' => (string) data_get($certification, 'certification_hash', ''),
            'completion_real_allowed' => false,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function proveRecoveryResumePath(string $actor, string $tag, AgentControlPlaneTaskQueueOrchestrator $orchestrator): array
    {
        $interruptedActor = $actor.'-interrupted';
        $resumedActor = $actor.'-resumed';
        $bootstrap = $this->bootstrap()->bootstrap([
            'terminal_bootstrap_probe' => [
                'enabled' => true,
                'namespace' => $tag.'_recovery',
                'target_task_count' => 1,
            ],
        ], [
            'actor' => $interruptedActor,
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => [$tag],
            'reason' => 'terminal_loop_operational_recovery_proof',
            'lease_minutes' => 10,
        ]);
        $taskPacketId = (string) data_get($bootstrap, 'task_packet_id', '');
        $interruptedLeaseId = (string) data_get($bootstrap, 'lease_id', '');
        $allowedFiles = $this->stringList((array) data_get($bootstrap, 'one_shot_worker_packet.lease.write_set', []));
        $release = [];
        $inspectBeforeRecovery = [];
        $resumePacketBeforeRecovery = [];
        $recoverReleased = [];
        $resumeClaim = [];
        $resumeCompletion = [];
        $resumeCompletionEvidence = [];

        $recovery = new AgentControlPlaneTaskLeaseRecoveryService;
        if ((string) data_get($bootstrap, 'status') === 'ready_for_worker' && $taskPacketId !== '' && $interruptedLeaseId !== '') {
            $release = $orchestrator->releaseLease($interruptedLeaseId, $interruptedActor, [
                'reason' => 'operator_interrupted_terminal',
            ]);
            $inspectBeforeRecovery = $recovery->inspectRecoverability(['packet' => $taskPacketId]);
            $resumePacketBeforeRecovery = $recovery->buildResumePacket($taskPacketId);
            $recoverReleased = $recovery->recoverReleasedTasks([
                'actor' => $actor.'-recovery',
                'packet' => $taskPacketId,
                'reason' => 'terminal_loop_operational_recovery_resume_proof',
            ]);
            $resumeClaim = $orchestrator->claimNext($resumedActor, [
                'ttl_seconds' => 600,
                'tag' => $tag,
            ]);
            if ((string) data_get($resumeClaim, 'event') === 'claimed') {
                $resumeLeaseId = (string) data_get($resumeClaim, 'lease_id', '');
                $resumeCompletionEvidence = [
                    'packet_id' => $taskPacketId,
                    'lease_id' => $resumeLeaseId,
                    'actor' => $resumedActor,
                    'files_changed' => $allowedFiles,
                    'commands_run' => [
                        'terminal_loop_operational_proof: release_interrupted_worker',
                        'terminal_loop_operational_proof: recover_released_task',
                        'terminal_loop_operational_proof: reclaim_recovered_task',
                        'terminal_loop_operational_proof: complete_recovered_task_dry_run',
                    ],
                    'tests_or_gates_result' => 'passed',
                    'git_status_short' => 'storage-only terminal loop recovery proof',
                    'git_diff_check_result' => 'clean',
                ];
                $resumeCompletionEvidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($resumeCompletionEvidence);
                $resumeCompletion = $orchestrator->completeDryRun($taskPacketId, $resumeLeaseId, $resumeCompletionEvidence);
            }
        }

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_recovery_resume_proof.v1',
            'status' => (string) data_get($resumeCompletion, 'event') === 'completed_dry_run' ? 'passed' : 'blocked',
            'task_packet_id' => $taskPacketId,
            'interrupted_actor' => $interruptedActor,
            'resumed_actor' => $resumedActor,
            'interrupted_lease_id' => $interruptedLeaseId,
            'release_event' => (string) data_get($release, 'event', ''),
            'release_status' => (string) data_get($release, 'release.status', ''),
            'recoverability_before_recovery' => (string) data_get($inspectBeforeRecovery, 'classifications.0.classification', ''),
            'resume_packet_event' => (string) data_get($resumePacketBeforeRecovery, 'event', ''),
            'resume_safe_next_action' => (string) data_get($resumePacketBeforeRecovery, 'resume_packet.safe_next_action', ''),
            'resume_contract_hash' => $this->stableHash((array) data_get($resumePacketBeforeRecovery, 'resume_packet.resume_contract', [])),
            'recover_released_event' => (string) data_get($recoverReleased, 'event', ''),
            'recover_released_count' => (int) data_get($recoverReleased, 'recovered_count', 0),
            'resume_claim_event' => (string) data_get($resumeClaim, 'event', ''),
            'resume_lease_id' => (string) data_get($resumeClaim, 'lease_id', ''),
            'resume_completion_event' => (string) data_get($resumeCompletion, 'event', ''),
            'resume_completion_evidence_hash' => (string) ($resumeCompletionEvidence['evidence_hash'] ?? ''),
            'resume_completion_evidence_valid' => (bool) data_get($resumeCompletion, 'evidence_validation.structured_completion_evidence_valid', false),
            'completion_real_allowed' => false,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $digest
     * @return array<string, mixed>
     */
    private function resumePacket(string $actor, string $proofId, string $tag, array $digest): array
    {
        $proofIdArg = '--proof-id='.$this->safeToken($proofId, 'proof');
        $tagArg = '--queue-tag='.$this->safeToken($tag, 'terminal-loop');
        $commonArgs = '--actor='.$actor.' --target-min-claimable-tasks=1 --max-new-tasks=1 '.$tagArg;
        $resumeCommands = [
            'inspect_health' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-health-digest-status '.$commonArgs.' --json',
            'preview_next_worker_bootstrap' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-worker-bootstrap-status --terminal-worker-bootstrap-preview '.$commonArgs.' --json',
            'replenish_and_claim_next_worker' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-worker-bootstrap-status '.$commonArgs.' --json',
            'run_bounded_operational_proof' => 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --actor='.$actor.' '.$proofIdArg.' --json',
        ];
        $nextCycleCertificate = $this->nextCycleCertificate($tagArg, $proofIdArg, $resumeCommands, $digest);

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_resume_packet.v1',
            'mode' => 'read_only_terminal_loop_resume_packet',
            'actor' => $actor,
            'proof_id' => $proofId,
            'queue_tags' => [$tag],
            'can_resume_without_chat_history' => (bool) data_get($digest, 'loop_decision.can_loop_without_chat_history', false),
            'safe_to_start_new_worker' => (bool) data_get($digest, 'loop_decision.safe_to_start_new_worker', false),
            'recommended_action' => (string) data_get($digest, 'loop_decision.recommended_action', ''),
            'resume_rollup_status' => (string) data_get($digest, 'terminal_loop_fleet_resume_rollup.status', ''),
            'resume_attention_required' => (bool) data_get($digest, 'terminal_loop_fleet_resume_rollup.resume_attention_required', true),
            'resume_can_claim_from_rollup' => (bool) data_get($digest, 'terminal_loop_fleet_resume_rollup.can_claim_from_rollup', false),
            'resume_can_recover_from_rollup' => (bool) data_get($digest, 'terminal_loop_fleet_resume_rollup.can_recover_from_rollup', false),
            'resume_rollup_hash' => (string) data_get($digest, 'terminal_loop_fleet_resume_rollup.terminal_loop_fleet_resume_rollup_hash', ''),
            'operator_handoff_status' => (string) data_get($digest, 'terminal_loop_fleet_operator_handoff.status', ''),
            'operator_handoff_next_action' => (string) data_get($digest, 'terminal_loop_fleet_operator_handoff.next_operator_action', ''),
            'operator_handoff_hash' => (string) data_get($digest, 'terminal_loop_fleet_operator_handoff.terminal_loop_fleet_operator_handoff_hash', ''),
            'health_digest_hash' => (string) data_get($digest, 'terminal_loop_health_digest_hash', ''),
            'resume_commands' => $resumeCommands,
            'next_cycle_certificate' => $nextCycleCertificate,
            'forbidden_actions' => [
                'do_not_call_provider',
                'do_not_spend_tokens',
                'do_not_dispatch_real_work',
                'do_not_mark_real_completion',
                'do_not_depend_on_chat_history',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $resumeCommands
     * @param  array<string, mixed>  $digest
     * @return array<string, mixed>
     */
    private function nextCycleCertificate(string $tagArg, string $proofIdArg, array $resumeCommands, array $digest): array
    {
        $commandsToCheck = [
            'inspect_health' => $resumeCommands['inspect_health'] ?? '',
            'preview_next_worker_bootstrap' => $resumeCommands['preview_next_worker_bootstrap'] ?? '',
            'replenish_and_claim_next_worker' => $resumeCommands['replenish_and_claim_next_worker'] ?? '',
            'run_bounded_operational_proof' => $resumeCommands['run_bounded_operational_proof'] ?? '',
        ];
        $commandChecks = [];
        foreach ($commandsToCheck as $name => $command) {
            $expectedLaneArgument = $name === 'run_bounded_operational_proof' ? $proofIdArg : $tagArg;
            $commandChecks[] = [
                'name' => $name,
                'command_present' => $command !== '',
                'lane_bound' => $command !== '' && str_contains($command, $expectedLaneArgument),
                'provider_safe' => $command !== ''
                    && ! str_contains($command, 'codex cli')
                    && ! str_contains($command, 'codex-cli')
                    && ! str_contains($command, 'provider-call')
                    && ! str_contains($command, 'provider_call'),
            ];
        }
        $allCommandsLaneBound = count(array_filter(
            $commandChecks,
            static fn (array $check): bool => (bool) ($check['command_present'] ?? false)
                && (bool) ($check['lane_bound'] ?? false)
                && (bool) ($check['provider_safe'] ?? false),
        )) === count($commandChecks);
        $safeToStartNewWorker = (bool) data_get($digest, 'loop_decision.safe_to_start_new_worker', false);
        $recommendedAction = (string) data_get($digest, 'loop_decision.recommended_action', '');

        return [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_next_cycle_certificate.v1',
            'status' => $safeToStartNewWorker ? 'next_cycle_worker_start_ready' : 'next_cycle_replenishment_required',
            'recommended_action' => $recommendedAction,
            'next_safe_command_key' => $safeToStartNewWorker ? 'preview_next_worker_bootstrap' : 'replenish_and_claim_next_worker',
            'all_commands_lane_bound' => $allCommandsLaneBound,
            'command_checks' => $commandChecks,
            'can_resume_without_chat_history' => (bool) data_get($digest, 'loop_decision.can_loop_without_chat_history', false),
            'requires_provider' => false,
            'requires_token_spend' => false,
            'dispatch_allowed' => false,
            'completion_real_allowed' => false,
        ];
    }

    private function orchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        return $this->orchestrator ?? new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    private function healthDigest(): AgentControlPlaneTerminalLoopHealthDigestService
    {
        return $this->healthDigest ?? new AgentControlPlaneTerminalLoopHealthDigestService;
    }

    private function bootstrap(): AgentControlPlaneTerminalWorkerBootstrapService
    {
        return $this->bootstrap ?? new AgentControlPlaneTerminalWorkerBootstrapService(
            new AgentControlPlaneTaskAutoReplenishmentService($this->orchestrator(), new AgentControlPlaneTaskPacketQueueRepository),
            $this->orchestrator(),
            new AgentControlPlaneOneShotWorkerPacketService(new AgentControlPlaneClaimLeaseRepository, new AgentControlPlaneTaskPacketQueueRepository),
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
        );
    }

    /** @param array<string, mixed> $digest */
    private function digestSummary(array $digest): array
    {
        return [
            'status' => (string) ($digest['status'] ?? ''),
            'claimable_task_count' => (int) data_get($digest, 'queue_health.claimable_task_count', 0),
            'claimed_task_count' => (int) data_get($digest, 'queue_health.claimed_task_count', 0),
            'active_lease_count' => (int) data_get($digest, 'lease_health.active_lease_count', 0),
            'recoverable_lease_count' => (int) data_get($digest, 'lease_health.recoverable_lease_count', 0),
            'evidence_rollup_status' => (string) data_get($digest, 'terminal_loop_fleet_evidence_rollup.status', ''),
            'completed_dry_run_task_count' => (int) data_get($digest, 'terminal_loop_fleet_evidence_rollup.completed_dry_run_task_count', 0),
            'valid_completion_evidence_count' => (int) data_get($digest, 'terminal_loop_fleet_evidence_rollup.valid_completion_evidence_count', 0),
            'cycle_supervisor_status' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.status', ''),
            'cycle_supervisor_cycle_state' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state', ''),
            'cycle_supervisor_next_command_purpose' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.next_command_purpose', ''),
            'cycle_supervisor_hash' => (string) data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash', ''),
            'digest_hash' => (string) data_get($digest, 'terminal_loop_health_digest_hash', ''),
        ];
    }

    /** @param array<string, mixed> $completion */
    private function runtimeSafetyAllFalse(array $completion, array $digest): bool
    {
        return (bool) data_get($completion, 'completion_real_allowed', true) === false
            && (bool) data_get($completion, 'runtime_execution_allowed', true) === false
            && (bool) data_get($completion, 'dispatch_allowed', true) === false
            && (bool) data_get($completion, 'provider_call_allowed', true) === false
            && (bool) data_get($completion, 'token_spend_allowed', true) === false
            && (bool) data_get($completion, 'self_programming_allowed', true) === false
            && (bool) data_get($digest, 'runtime_safety.runtime_execution_allowed', true) === false
            && (bool) data_get($digest, 'runtime_safety.dispatch_allowed', true) === false
            && (bool) data_get($digest, 'runtime_safety.provider_call_allowed', true) === false
            && (bool) data_get($digest, 'runtime_safety.token_spend_allowed', true) === false
            && (bool) data_get($digest, 'runtime_safety.self_programming_allowed', true) === false;
    }

    private function safeToken(string $value, string $fallback): string
    {
        $token = Str::of($value)->lower()->replaceMatches('/[^a-z0-9_-]+/', '-')->trim('-')->toString();

        return $token !== '' ? $token : $fallback;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['terminal_loop_operational_proof_hash'], $payload['completion_audit_binding_packet'], $payload['completion_audit_binding_packet_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && ! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
