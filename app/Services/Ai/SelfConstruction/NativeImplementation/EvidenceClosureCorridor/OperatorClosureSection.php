<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation\EvidenceClosureCorridor;

/**
 * GOD-DEBULK section: operator-closure / execution-runbook / progress-meter /
 * failure-recovery / external-completion-claim projections.
 *
 * Extracted verbatim from AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService.
 * The only edits are `$this->stableHash(`/`$this->placeholderFields(`/the two
 * completion-audit command helpers rewritten to `$this->support->...`; every
 * intra-section call stays `$this->`. Read-only projection: writes nothing,
 * calls no provider.
 */
final class OperatorClosureSection
{
    public function __construct(
        private readonly EvidenceClosureCorridorSupport $support,
    ) {}

    /**
     * @param  array<string, mixed>  $completionAudit
     * @return array<string, mixed>
     */
    public function externalCompletionClaimPolicy(array $completionAudit, bool $completionAuditComplete): array
    {
        $verdict = (array) data_get($completionAudit, 'completion_claim_authority_verdict', []);
        $failedCriteria = (array) data_get($completionAudit, 'failed_criteria', []);

        $policy = [
            'schema_version' => 'atlas.self_construction.final_operator_evidence_closure_external_completion_claim_policy.v1',
            'mode' => 'read_only_final_operator_evidence_closure_external_completion_claim_policy',
            'status' => $completionAuditComplete ? 'completion_claim_delegated_to_completion_audit' : 'reject_external_completion_claim',
            'completion_authority' => (string) data_get($verdict, 'completion_authority', 'atlas_self_construction_os_completion_audit'),
            'required_completion_predicate' => (string) data_get($verdict, 'required_completion_predicate', 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0'),
            'source_completion_audit_status' => (string) data_get($completionAudit, 'status', 'incomplete'),
            'source_completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'source_completion_claim_authority_verdict_hash' => (string) data_get($verdict, 'completion_claim_authority_verdict_hash', ''),
            'external_agent_claim_accepted' => false,
            'external_agent_claim_can_mark_os_complete' => false,
            'external_agent_claim_can_override_audit' => false,
            'current_failed_count' => count($failedCriteria),
            'current_failed_criteria' => $failedCriteria,
            'missing_required_evidence_artifact_count' => (int) data_get($verdict, 'missing_evidence_count', count($failedCriteria)),
            'failure_policy' => [
                'reject_external_agent_completion_claim',
                'require_completion_audit_status_complete',
                'require_completion_allowed_true',
                'require_failed_count_zero',
                'require_runtime_promotion_receipt',
                'require_real_provider_smoke',
                'require_human_completion_receipt',
            ],
            'non_execution_guarantees' => [
                'external_completion_claim_policy_does_not_execute_commands',
                'external_completion_claim_policy_does_not_persist_receipts',
                'external_completion_claim_policy_does_not_sign_for_operator',
                'external_completion_claim_policy_does_not_call_provider',
                'external_completion_claim_policy_does_not_spend_tokens',
                'external_completion_claim_policy_does_not_dispatch',
                'external_completion_claim_policy_does_not_promote_completion',
            ],
        ];
        $policy['external_completion_claim_policy_hash'] = $this->support->stableHash($policy);

        return $policy;
    }

    /**
     * @param  array<string, mixed>  $operatorNextActionShellPacket
     * @return array<string, mixed>
     */
    public function operatorFailureRecoveryMatrix(array $operatorNextActionShellPacket): array
    {
        $resumeCommand = (string) data_get(
            $operatorNextActionShellPacket,
            'resume_after_interruption.resume_command',
            'php artisan atlas:ai:self-construction --atlas-self-construction-final-operator-evidence-closure-corridor-status --json',
        );
        $verificationCommands = array_map(
            static fn (array $row): string => (string) ($row['command'] ?? ''),
            (array) data_get($operatorNextActionShellPacket, 'post_action_verification_bundle.verification_commands', []),
        );

        $rows = [
            [
                'failure_id' => 'placeholder_command_detected',
                'classification' => 'operator_input_required',
                'trigger' => 'exact command still contains <operator>, <operator reason...> or @/path/to placeholders',
                'safe_recovery_command' => $resumeCommand,
                'required_operator_action' => 'Replace placeholders with reviewed operator identity, reason or file path, then rerun the shell packet preflight.',
                'do_not_do' => ['do_not_execute_placeholder_command', 'do_not_persist_placeholder_receipt'],
            ],
            [
                'failure_id' => 'post_action_verifier_not_green',
                'classification' => 'verification_failed',
                'trigger' => 'post_action_verification_bundle success check fails or reports a blocked verifier',
                'safe_recovery_command' => $resumeCommand,
                'required_operator_action' => 'Inspect the failed verifier payload, keep the current artifact as the active step, and do not advance to the next artifact.',
                'do_not_do' => ['do_not_advance_to_next_artifact', 'do_not_persist_until_verifier_green'],
            ],
            [
                'failure_id' => 'hash_mismatch_detected',
                'classification' => 'evidence_integrity_failed',
                'trigger' => 'receipt_hash, smoke_hash, command hash or draft hash does not match the normalized payload',
                'safe_recovery_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --completion-receipt-json=@/path/to/completion-receipt.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --json',
                'required_operator_action' => 'Recompute the canonical hash with the composer, update only the reviewed draft hash field, then rerun readiness.',
                'do_not_do' => ['do_not_edit_hash_by_hand', 'do_not_persist_mismatched_hash'],
            ],
            [
                'failure_id' => 'stale_replay_snapshot_or_runtime_basis',
                'classification' => 'stale_context',
                'trigger' => 'completion audit, release dossier, runtime gap matrix, promotion basis or closure basis hash changed after a draft was prepared',
                'safe_recovery_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json',
                'required_operator_action' => 'Refresh the replay snapshot if required, regenerate the affected draft against current hashes, then rerun the closure corridor.',
                'do_not_do' => ['do_not_sign_stale_hashes', 'do_not_reuse_old_draft_after_basis_drift'],
            ],
            [
                'failure_id' => 'real_provider_smoke_aborted_or_incomplete',
                'classification' => 'real_provider_operator_stop',
                'trigger' => 'operator aborts the external smoke, kill switch fires, or provider/cost/work-product evidence is incomplete',
                'safe_recovery_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-offline-harness-status --json',
                'required_operator_action' => 'Record the aborted smoke as non-passing operator evidence, inspect kill-switch expectations, and rerun a bounded smoke only after operator approval.',
                'do_not_do' => ['do_not_mark_aborted_smoke_green', 'do_not_fabricate_provider_run_or_cost_event'],
            ],
        ];

        foreach ($rows as $index => &$row) {
            $row['order'] = $index + 1;
            $row['rerun_verification_commands'] = $verificationCommands;
            $row['can_recover_automatically'] = false;
            $row['requires_operator_review'] = true;
            $row['recovery_row_hash'] = $this->support->stableHash($row);
        }
        unset($row);

        $matrix = [
            'schema_version' => 'atlas.self_construction.final_operator_failure_recovery_matrix.v1',
            'mode' => 'read_only_operator_failure_recovery_matrix',
            'status' => 'operator_recovery_guidance_available',
            'row_count' => count($rows),
            'rows' => $rows,
            'recovery_requires_fresh_corridor_status' => true,
            'can_recover_from_matrix' => false,
            'can_execute_from_matrix' => false,
            'can_persist_from_matrix' => false,
            'can_call_provider_from_matrix' => false,
            'can_sign_for_operator_from_matrix' => false,
        ];
        $matrix['failure_recovery_matrix_hash'] = $this->support->stableHash($matrix);

        return $matrix;
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $artifactVerificationMatrix
     * @param  array<string, mixed>  $operatorNextAction
     * @return array<string, mixed>
     */
    public function operatorCompletionProgressMeter(
        array $completionAudit,
        array $artifactVerificationMatrix,
        array $operatorNextAction,
        bool $completionAuditComplete,
    ): array {
        $artifactOrder = [
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
            'final_completion_audit',
        ];
        $rows = [];
        foreach ($artifactOrder as $index => $artifact) {
            $matrixRow = (array) ($artifactVerificationMatrix[$artifact] ?? []);
            $blockerCount = (int) ($matrixRow['blocker_count'] ?? 0);
            $missingCount = (int) ($matrixRow['missing_count'] ?? 0);
            $green = $blockerCount === 0 && $missingCount === 0;
            $rows[] = [
                'order' => $index + 1,
                'artifact' => $artifact,
                'status' => $green ? 'green' : 'blocked',
                'current_status' => (string) ($matrixRow['current_status'] ?? ''),
                'current_hash' => (string) ($matrixRow['current_hash'] ?? ''),
                'blocker_count' => $blockerCount,
                'missing_count' => $missingCount,
                'verifying_service' => (string) ($matrixRow['verifying_service'] ?? ''),
            ];
        }

        $greenRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) ($row['status'] ?? '') === 'green',
        ));
        $blockedRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => (string) ($row['status'] ?? '') !== 'green',
        ));
        $blockedArtifactIds = array_values(array_map(
            static fn (array $row): string => (string) ($row['artifact'] ?? ''),
            $blockedRows,
        ));
        $operatorEvidenceBlockedArtifactIds = array_values(array_filter(
            $blockedArtifactIds,
            static fn (string $artifact): bool => $artifact !== 'final_completion_audit',
        ));
        $blockerClassification = (array) data_get($completionAudit, 'blocker_classification', []);

        $progress = [
            'schema_version' => 'atlas.self_construction.final_operator_completion_progress_meter.v1',
            'mode' => 'read_only_final_operator_completion_progress_meter',
            'status' => $completionAuditComplete ? 'completion_audit_complete' : 'operator_evidence_remaining',
            'artifact_count' => count($rows),
            'green_artifact_count' => count($greenRows),
            'blocked_artifact_ids' => $blockedArtifactIds,
            'blocked_artifact_count' => count($blockedRows),
            'blocked_artifact_count_includes_final_completion_audit' => true,
            'operator_evidence_artifact_count' => max(0, count($rows) - 1),
            'operator_evidence_blocked_artifact_ids' => $operatorEvidenceBlockedArtifactIds,
            'operator_evidence_blocked_artifact_count' => count($operatorEvidenceBlockedArtifactIds),
            'final_completion_audit_blocked' => in_array('final_completion_audit', $blockedArtifactIds, true),
            'progress_percent' => (int) floor((count($greenRows) / max(1, count($rows))) * 100),
            'current_required_artifact' => (string) data_get($operatorNextAction, 'next_required_submission', ''),
            'current_step_id' => (string) data_get($operatorNextAction, 'next_step_id', ''),
            'exact_next_command' => (string) data_get($operatorNextAction, 'exact_command', ''),
            'exact_next_persist_command' => (string) data_get($operatorNextAction, 'exact_persist_command', ''),
            'artifact_rows' => $rows,
            'technical_blockers_clear' => (array) data_get($blockerClassification, 'technical_blockers', []) === [],
            'human_blocker_count' => count((array) data_get($blockerClassification, 'human_blockers', [])),
            'real_provider_blocker_count' => count((array) data_get($blockerClassification, 'real_provider_blockers', [])),
            'completion_allowed' => $completionAuditComplete,
            'completion_claim_allowed' => $completionAuditComplete,
            'can_finish_without_operator' => false,
            'can_finish_without_real_provider_smoke' => false,
            'can_self_promote_completion' => false,
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'non_execution_guarantees' => [
                'completion_progress_meter_does_not_execute_commands',
                'completion_progress_meter_does_not_persist_receipts',
                'completion_progress_meter_does_not_call_provider',
                'completion_progress_meter_does_not_spend_tokens',
                'completion_progress_meter_does_not_sign_for_operator',
                'completion_progress_meter_does_not_promote_completion',
            ],
        ];
        $progress['progress_meter_hash'] = $this->support->stableHash($progress);

        return $progress;
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  list<string>  $blockingArtifacts
     * @param  array<string, mixed>  $operatorNextAction
     * @return array<string, mixed>
     */
    public function closureReadinessSummary(
        array $completionAudit,
        array $blockingArtifacts,
        array $operatorNextAction,
        bool $completionAuditComplete,
    ): array {
        $blockerClassification = (array) data_get($completionAudit, 'blocker_classification', []);
        $technicalBlockers = (array) data_get($blockerClassification, 'technical_blockers', []);
        $humanBlockers = (array) data_get($blockerClassification, 'human_blockers', []);
        $realProviderBlockers = (array) data_get($blockerClassification, 'real_provider_blockers', []);
        $releaseDossierGreen = $this->criterionPassed($completionAudit, 'release_dossier_green');
        $replayDiffGreen = $this->criterionPassed($completionAudit, 'replay_diff_against_completion_snapshot_green');
        $promotionGateGreen = $this->criterionPassed($completionAudit, 'promotion_gate_green');
        $mutationGuardGreen = $this->criterionPassed($completionAudit, 'mutation_guard_green');
        $certificationBatchGreen = $this->criterionPassed($completionAudit, 'certification_status_batch_green');
        $terminalLoopGreen = $this->criterionPassed($completionAudit, 'agent_control_plane_terminal_loop_certification_green');
        $technicalClosureGreen = $technicalBlockers === []
            && $releaseDossierGreen
            && $replayDiffGreen
            && $promotionGateGreen
            && $mutationGuardGreen
            && $certificationBatchGreen
            && $terminalLoopGreen;
        $promptToArtifactChecklist = (array) data_get($completionAudit, 'prompt_to_artifact_checklist', []);
        $loopObjectiveEvidenceRows = array_values(array_filter(
            $promptToArtifactChecklist,
            static fn (array $row): bool => str_starts_with((string) ($row['requirement'] ?? ''), 'loop '),
        ));
        $loopObjectiveEvidencePassedCount = count(array_filter(
            $loopObjectiveEvidenceRows,
            static fn (array $row): bool => (string) ($row['evidence_status'] ?? '') === 'passed',
        ));

        $summary = [
            'schema_version' => 'atlas.self_construction.final_operator_closure_readiness_summary.v1',
            'status' => $completionAuditComplete
                ? 'completion_audit_complete'
                : ($technicalClosureGreen ? 'technical_closure_green_operator_evidence_remaining' : 'technical_closure_blocked'),
            'completion_audit_status' => (string) data_get($completionAudit, 'status', 'incomplete'),
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'completion_allowed' => $completionAuditComplete,
            'completion_claim_allowed' => $completionAuditComplete,
            'passed_count' => (int) data_get($completionAudit, 'passed_count', 0),
            'failed_count' => (int) data_get($completionAudit, 'failed_count', 0),
            'technical_closure_green' => $technicalClosureGreen,
            'technical_blocker_count' => count($technicalBlockers),
            'technical_blocker_ids' => array_values($technicalBlockers),
            'human_blocker_count' => count($humanBlockers),
            'human_blocker_ids' => array_values($humanBlockers),
            'real_provider_blocker_count' => count($realProviderBlockers),
            'real_provider_blocker_ids' => array_values($realProviderBlockers),
            'operator_evidence_blocking_artifacts' => $blockingArtifacts,
            'operator_evidence_blocking_artifact_count' => count($blockingArtifacts),
            'release_dossier_green' => $releaseDossierGreen,
            'replay_diff_green' => $replayDiffGreen,
            'promotion_gate_green' => $promotionGateGreen,
            'mutation_guard_green' => $mutationGuardGreen,
            'certification_status_batch_green' => $certificationBatchGreen,
            'terminal_loop_certification_green' => $terminalLoopGreen,
            'prompt_to_artifact_checklist_count' => count($promptToArtifactChecklist),
            'loop_objective_evidence_rows' => $loopObjectiveEvidenceRows,
            'loop_objective_evidence_row_count' => count($loopObjectiveEvidenceRows),
            'loop_objective_evidence_passed_count' => $loopObjectiveEvidencePassedCount,
            'loop_objective_evidence_all_passed' => $loopObjectiveEvidenceRows !== [] && $loopObjectiveEvidencePassedCount === count($loopObjectiveEvidenceRows),
            'can_finish_without_operator' => false,
            'can_finish_without_real_provider_smoke' => false,
            'can_self_promote_completion' => false,
            'next_required_submission' => (string) data_get($operatorNextAction, 'next_required_submission', ''),
            'next_step_id' => (string) data_get($operatorNextAction, 'next_step_id', ''),
            'exact_next_command' => (string) data_get($operatorNextAction, 'exact_command', ''),
            'exact_next_persist_command' => (string) data_get($operatorNextAction, 'exact_persist_command', ''),
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'non_execution_guarantees' => [
                'closure_readiness_summary_does_not_execute_commands',
                'closure_readiness_summary_does_not_persist_receipts',
                'closure_readiness_summary_does_not_call_provider',
                'closure_readiness_summary_does_not_spend_tokens',
                'closure_readiness_summary_does_not_sign_for_operator',
                'closure_readiness_summary_does_not_promote_completion',
            ],
        ];
        $summary['closure_readiness_summary_hash'] = $this->support->stableHash($summary);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $operatorNextAction
     * @param  array<string, mixed>  $operatorClosureHandoff
     * @param  list<array<string, mixed>>  $orderedOperatorPath
     * @param  array<string, string>  $operatorCommandPlan
     * @param  array<string, mixed>  $artifactVerificationMatrix
     * @return array<string, mixed>
     */
    public function operatorExecutionRunbook(
        array $operatorNextAction,
        array $operatorClosureHandoff,
        array $orderedOperatorPath,
        array $operatorCommandPlan,
        array $artifactVerificationMatrix,
        array $operatorClosureCommandReplay,
        string $closureCorridorStatusCommand,
        bool $completionAuditComplete,
    ): array {
        $phases = [];
        $stepCards = [];
        foreach ($orderedOperatorPath as $index => $step) {
            $phase = (string) ($step['phase'] ?? '');
            if ($phase !== '' && ! in_array($phase, $phases, true)) {
                $phases[] = $phase;
            }
            $command = (string) ($step['command'] ?? '');
            $persistCommand = (string) ($step['persist_command'] ?? '');
            $stepCards[] = [
                'order' => $index + 1,
                'id' => (string) ($step['id'] ?? ''),
                'phase' => $phase,
                'status' => (string) ($step['status'] ?? ''),
                'command' => $command,
                'persist_command' => $persistCommand,
                'command_hash' => $command === '' ? '' : hash('sha256', $command),
                'persist_command_hash' => $persistCommand === '' ? '' : hash('sha256', $persistCommand),
                'placeholder_fields_to_replace' => $this->support->placeholderFields($command.' '.$persistCommand),
                'required_inputs' => (array) ($step['required_inputs'] ?? []),
                'missing_inputs' => (array) ($step['missing_inputs'] ?? []),
                'produced_artifacts' => (array) ($step['produced_artifacts'] ?? []),
                'stop_condition' => (string) ($step['stop_condition'] ?? ''),
                'forbidden_shortcuts' => (array) ($step['forbidden_shortcuts'] ?? []),
                'can_run_automatically' => false,
                'can_persist_from_runbook' => false,
            ];
        }

        $blockedArtifactIds = array_values(array_filter(
            array_keys($artifactVerificationMatrix),
            static fn (string $artifact): bool => (int) data_get($artifactVerificationMatrix, $artifact.'.blocker_count', 0) > 0
                || (int) data_get($artifactVerificationMatrix, $artifact.'.missing_count', 0) > 0,
        ));
        $operatorEvidenceBlockedArtifactIds = array_values(array_filter(
            $blockedArtifactIds,
            static fn (string $artifact): bool => $artifact !== 'final_completion_audit',
        ));
        $runbook = [
            'schema_version' => 'atlas.self_construction.final_operator_execution_runbook.v1',
            'mode' => 'read_only_operator_execution_runbook',
            'status' => $completionAuditComplete ? 'completion_audit_complete' : 'blocked_operator_driven_steps_remaining',
            'current_step_id' => (string) data_get($operatorNextAction, 'next_step_id', ''),
            'current_step_phase' => (string) data_get($operatorNextAction, 'next_step_phase', ''),
            'current_step_command' => (string) data_get($operatorNextAction, 'exact_command', ''),
            'current_step_persist_command' => (string) data_get($operatorNextAction, 'exact_persist_command', ''),
            'step_count' => count($stepCards),
            'phase_count' => count($phases),
            'phases' => $phases,
            'blocked_artifact_ids' => $blockedArtifactIds,
            'blocked_artifact_count' => count($blockedArtifactIds),
            'blocked_artifact_count_includes_final_completion_audit' => true,
            'operator_evidence_blocked_artifact_ids' => $operatorEvidenceBlockedArtifactIds,
            'operator_evidence_blocked_artifact_count' => count($operatorEvidenceBlockedArtifactIds),
            'final_completion_audit_blocked' => in_array('final_completion_audit', $blockedArtifactIds, true),
            'step_cards' => $stepCards,
            'command_plan_hash' => $this->support->stableHash($operatorCommandPlan),
            'artifact_verification_matrix_hash' => $this->support->stableHash($artifactVerificationMatrix),
            'operator_closure_handoff_hash' => (string) data_get($operatorClosureHandoff, 'operator_closure_handoff_hash', ''),
            'operator_closure_command_replay_hash' => (string) data_get($operatorClosureCommandReplay, 'command_replay_hash', ''),
            'operator_closure_command_replay_current_step' => (string) data_get($operatorClosureCommandReplay, 'current_step', ''),
            'operator_closure_command_replay_step_count' => (int) data_get($operatorClosureCommandReplay, 'replay_step_count', 0),
            'proof_commands_after_each_step' => [
                'closure_corridor' => $closureCorridorStatusCommand,
                'submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'completion_evidence' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'terminal_loop_operational_proof' => (string) ($operatorCommandPlan['refresh_terminal_loop_operational_proof'] ?? 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json'),
                'completion_audit' => (string) ($operatorCommandPlan['rerun_completion_audit_with_terminal_loop_operational_proof'] ?? $this->support->completionAuditWithTerminalLoopOperationalProofCommand()),
                'completion_audit_with_terminal_loop_operational_proof' => (string) ($operatorCommandPlan['rerun_completion_audit_with_terminal_loop_operational_proof'] ?? 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json'),
                'completion_audit_with_canonical_terminal_loop_operational_proof' => (string) ($operatorCommandPlan['rerun_completion_audit_with_canonical_terminal_loop_operational_proof'] ?? $this->support->completionAuditWithCanonicalTerminalLoopOperationalProofCommand()),
                'effective_completion_audit_with_terminal_loop_operational_proof' => (string) ($operatorCommandPlan['effective_rerun_completion_audit_with_terminal_loop_operational_proof'] ?? $this->support->completionAuditWithCanonicalTerminalLoopOperationalProofCommand()),
            ],
            'resume_without_chat_history' => [
                'can_resume_without_chat_history' => (bool) data_get($operatorClosureHandoff, 'can_resume_without_chat_history', false),
                'resume_command' => $closureCorridorStatusCommand,
                'resumption_checkpoint_hash' => (string) data_get($operatorClosureHandoff, 'resumption_checkpoint_hash', ''),
                'requires_fresh_preflight_before_persist' => (bool) data_get($operatorClosureHandoff, 'requires_fresh_preflight_before_persist', false),
            ],
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'can_run_automatically' => false,
            'can_persist_from_runbook' => false,
            'can_call_provider_from_runbook' => false,
            'can_sign_for_operator_from_runbook' => false,
            'non_execution_guarantees' => [
                'operator_execution_runbook_does_not_execute_commands',
                'operator_execution_runbook_does_not_persist_receipts',
                'operator_execution_runbook_does_not_call_provider',
                'operator_execution_runbook_does_not_spend_tokens',
                'operator_execution_runbook_does_not_dispatch_work',
                'operator_execution_runbook_does_not_sign_for_operator',
                'operator_execution_runbook_does_not_promote_completion',
            ],
        ];
        $runbook['operator_execution_runbook_hash'] = $this->support->stableHash($runbook);

        return $runbook;
    }

    /**
     * @param  array<string, mixed>  $operatorNextAction
     * @param  array<string, string>  $operatorCommandPlan
     * @param  list<array<string, mixed>>  $orderedOperatorPath
     * @param  list<string>  $blockingArtifacts
     * @return array<string, mixed>
     */
    public function operatorClosureHandoff(
        array $operatorNextAction,
        array $operatorCommandPlan,
        array $orderedOperatorPath,
        array $blockingArtifacts,
        array $completionAuditBlockerSummary,
        array $resumptionCheckpoint,
        array $operatorClosureCommandReplay,
        bool $completionAuditComplete,
    ): array {
        $commandSequence = array_values(array_map(
            static fn (array $step): array => [
                'id' => (string) ($step['id'] ?? ''),
                'phase' => (string) ($step['phase'] ?? ''),
                'status' => (string) ($step['status'] ?? ''),
                'command' => (string) ($step['command'] ?? ''),
                'persist_command' => (string) ($step['persist_command'] ?? ''),
                'can_run_automatically' => (bool) ($step['can_run_automatically'] ?? false),
                'missing_inputs' => (array) ($step['missing_inputs'] ?? []),
                'stop_condition' => (string) ($step['stop_condition'] ?? ''),
            ],
            $orderedOperatorPath,
        ));

        $handoff = [
            'schema_version' => 'atlas.self_construction.final_operator_closure_handoff.v1',
            'mode' => 'read_only_operator_closure_handoff',
            'status' => $completionAuditComplete ? 'complete_candidate_ready_for_next_stage' : 'blocked_operator_action_required',
            'next_required_submission' => (string) ($operatorNextAction['next_required_submission'] ?? ''),
            'next_step_id' => (string) ($operatorNextAction['next_step_id'] ?? ''),
            'next_step_phase' => (string) ($operatorNextAction['next_step_phase'] ?? ''),
            'immediate_command' => (string) ($operatorNextAction['exact_command'] ?? ''),
            'immediate_persist_command' => (string) ($operatorNextAction['exact_persist_command'] ?? ''),
            'command_contains_placeholders' => (bool) ($operatorNextAction['command_contains_placeholders'] ?? false),
            'placeholder_fields_to_replace' => (array) ($operatorNextAction['placeholder_fields_to_replace'] ?? []),
            'can_run_automatically' => false,
            'why_not_automatic' => (string) ($operatorNextAction['why_not_automatic'] ?? 'requires_operator_or_provider_evidence'),
            'blocking_artifacts' => $blockingArtifacts,
            'blocking_artifact_count' => count($blockingArtifacts),
            'completion_audit_blocker_summary' => $completionAuditBlockerSummary,
            'resumption_checkpoint_hash' => (string) data_get($resumptionCheckpoint, 'resumption_checkpoint_hash', ''),
            'resumption_checkpoint_current_step' => (string) data_get($resumptionCheckpoint, 'current_step', ''),
            'resumption_checkpoint_exact_next_command' => (string) data_get($resumptionCheckpoint, 'exact_next_command', ''),
            'operator_closure_command_replay_hash' => (string) data_get($operatorClosureCommandReplay, 'command_replay_hash', ''),
            'operator_closure_command_replay_current_step' => (string) data_get($operatorClosureCommandReplay, 'current_step', ''),
            'operator_closure_command_replay_step_count' => (int) data_get($operatorClosureCommandReplay, 'replay_step_count', 0),
            'can_resume_without_chat_history' => (bool) data_get($resumptionCheckpoint, 'can_resume_without_chat_history', false),
            'requires_fresh_preflight_before_persist' => (bool) data_get($resumptionCheckpoint, 'requires_fresh_preflight_before_persist', false),
            'requires_human_operator' => $blockingArtifacts !== [],
            'requires_real_provider_smoke' => in_array('real_provider_smoke', $blockingArtifacts, true),
            'full_ordered_command_sequence' => $commandSequence,
            'workspace_flow' => [
                'template_pack_command' => (string) ($operatorCommandPlan['operator_evidence_artifact_template_pack'] ?? ''),
                'submission_readiness_command' => (string) ($operatorCommandPlan['operator_evidence_submission_readiness'] ?? ''),
                'finalize_hashes_command' => (string) ($operatorCommandPlan['finalize_operator_draft_workspace_hashes'] ?? ''),
                'publish_workspace_command' => (string) ($operatorCommandPlan['publish_finalized_operator_draft_workspace'] ?? ''),
                'requires_explicit_operator_publish' => true,
                'requires_explicit_operator_persistence' => true,
            ],
            'proof_commands_after_action' => (array) ($operatorNextAction['proof_commands_after_action'] ?? []),
            'success_predicate_after_all_actions' => (string) ($operatorNextAction['success_predicate_after_all_actions'] ?? 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0'),
            'forbidden_shortcuts' => (array) ($operatorNextAction['forbidden_shortcuts'] ?? []),
            'non_execution_guarantees' => [
                'operator_closure_handoff_does_not_execute_command',
                'operator_closure_handoff_does_not_persist_receipts',
                'operator_closure_handoff_does_not_call_provider',
                'operator_closure_handoff_does_not_spend_tokens',
                'operator_closure_handoff_does_not_dispatch_work',
                'operator_closure_handoff_does_not_sign_for_operator',
                'operator_closure_handoff_does_not_promote_completion',
            ],
        ];
        $handoff['operator_closure_handoff_hash'] = $this->support->stableHash($handoff);

        return $handoff;
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     */
    public function criterionPassed(array $completionAudit, string $criterionId): bool
    {
        foreach ((array) data_get($completionAudit, 'criteria', []) as $criterion) {
            if ((string) data_get($criterion, 'id') === $criterionId) {
                return (bool) data_get($criterion, 'passed', false);
            }
        }

        return false;
    }

}
