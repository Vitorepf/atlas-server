<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation\EvidenceClosureCorridor;

/**
 * GOD-DEBULK section: operator-next-action + post-action + placeholder helpers.
 *
 * Extracted verbatim from AtlasSelfConstructionFinalOperatorEvidenceClosureCorridorService.
 * The only edits are `$this->stableHash(`/`$this->placeholderFields(`/the two
 * completion-audit command helpers rewritten to `$this->support->...`; every
 * intra-section call stays `$this->`. Read-only projection: writes nothing,
 * calls no provider.
 */
final class OperatorNextActionSection
{
    public function __construct(
        private readonly EvidenceClosureCorridorSupport $support,
    ) {}

    /**
     * @param  array<string, mixed>  $operatorNextAction
     * @param  array<string, mixed>  $operatorCompletionProgressMeter
     * @return array<string, mixed>
     */
    public function operatorNextActionShellPacket(
        array $operatorNextAction,
        array $operatorCompletionProgressMeter,
        string $closureCorridorStatusCommand,
    ): array {
        $exactCommand = (string) data_get($operatorNextAction, 'exact_command', '');
        $exactPersistCommand = (string) data_get($operatorNextAction, 'exact_persist_command', '');
        $proofCommands = (array) data_get($operatorNextAction, 'proof_commands_after_action', []);
        $commands = array_values(array_filter(array_merge(
            [
                $closureCorridorStatusCommand,
                $exactCommand,
                $exactPersistCommand,
            ],
            $proofCommands,
        )));
        $placeholderFields = (array) data_get($operatorNextAction, 'placeholder_fields_to_replace', []);
        $placeholderReplacementContract = $this->placeholderReplacementContract($placeholderFields);
        $commandRows = [];
        foreach ($commands as $index => $command) {
            $commandRows[] = [
                'order' => $index + 1,
                'command' => $command,
                'command_hash' => hash('sha256', (string) $command),
                'contains_placeholders' => $this->support->placeholderFields((string) $command) !== [],
            ];
        }

        $packet = [
            'schema_version' => 'atlas.self_construction.final_operator_next_action_shell_packet.v1',
            'mode' => 'read_only_final_operator_next_action_shell_packet',
            'status' => $placeholderFields === [] ? 'ready_after_operator_review' : 'blocked_replace_placeholders_before_copy',
            'current_required_artifact' => (string) data_get($operatorCompletionProgressMeter, 'current_required_artifact', ''),
            'current_step_id' => (string) data_get($operatorNextAction, 'next_step_id', ''),
            'exact_command' => $exactCommand,
            'exact_persist_command' => $exactPersistCommand,
            'command_contains_placeholders' => $placeholderFields !== [],
            'placeholder_fields_to_replace' => $placeholderFields,
            'placeholder_replacement_contract' => $placeholderReplacementContract,
            'placeholder_replacement_contract_count' => count($placeholderReplacementContract),
            'safe_to_copy_after_operator_review' => $placeholderFields === [],
            'operator_must_replace_placeholders' => $placeholderFields !== [],
            'preflight_command' => $closureCorridorStatusCommand,
            'post_action_proof_commands' => $proofCommands,
            'post_action_success_checks' => $this->postActionSuccessChecks((string) data_get($operatorNextAction, 'next_required_submission', '')),
            'post_action_verification_bundle' => $this->postActionVerificationBundle(
                (string) data_get($operatorNextAction, 'next_required_submission', ''),
                $proofCommands,
                $closureCorridorStatusCommand,
            ),
            'resume_after_interruption' => $this->operatorNextActionResumeAfterInterruption(
                $operatorNextAction,
                $operatorCompletionProgressMeter,
                $proofCommands,
                $closureCorridorStatusCommand,
            ),
            'ordered_shell_commands' => $commandRows,
            'ordered_shell_command_count' => count($commandRows),
            'stop_condition' => (string) data_get($operatorNextAction, 'stop_condition', ''),
            'forbidden_shortcuts' => (array) data_get($operatorNextAction, 'forbidden_shortcuts', []),
            'success_predicate_after_all_actions' => (string) data_get($operatorNextAction, 'success_predicate_after_all_actions', ''),
            'can_execute_from_packet' => false,
            'can_persist_from_packet' => false,
            'can_call_provider_from_packet' => false,
            'can_sign_for_operator_from_packet' => false,
            'non_execution_guarantees' => [
                'next_action_shell_packet_does_not_execute_commands',
                'next_action_shell_packet_does_not_persist_receipts',
                'next_action_shell_packet_does_not_call_provider',
                'next_action_shell_packet_does_not_spend_tokens',
                'next_action_shell_packet_does_not_sign_for_operator',
                'next_action_shell_packet_does_not_promote_completion',
            ],
        ];
        $packet['shell_packet_hash'] = $this->support->stableHash($packet);

        return $packet;
    }

    /**
     * @param  array<string, mixed>  $operatorNextActionShellPacket
     * @param  array<string, mixed>  $operatorFailureRecoveryMatrix
     * @param  array<string, mixed>  $operatorCompletionProgressMeter
     * @return array<string, mixed>
     */
    public function operatorNextActionReadinessGate(
        array $operatorNextActionShellPacket,
        array $operatorFailureRecoveryMatrix,
        array $operatorCompletionProgressMeter,
    ): array {
        $blockingReasons = [];
        if ((bool) data_get($operatorNextActionShellPacket, 'command_contains_placeholders', false)) {
            $blockingReasons[] = 'operator_must_replace_placeholders_before_copy';
        }
        if ((string) data_get($operatorNextActionShellPacket, 'post_action_verification_bundle.status', '') !== 'verify_before_next_artifact_or_persist') {
            $blockingReasons[] = 'post_action_verification_bundle_missing_or_unexpected';
        }
        if ((int) data_get($operatorFailureRecoveryMatrix, 'row_count', 0) < 5) {
            $blockingReasons[] = 'operator_failure_recovery_matrix_incomplete';
        }
        if (! (bool) data_get($operatorFailureRecoveryMatrix, 'recovery_requires_fresh_corridor_status', false)) {
            $blockingReasons[] = 'fresh_corridor_status_not_required_by_recovery_matrix';
        }
        if ((string) data_get($operatorCompletionProgressMeter, 'current_required_artifact', '') === '') {
            $blockingReasons[] = 'current_required_artifact_missing';
        }

        $gate = [
            'schema_version' => 'atlas.self_construction.final_operator_next_action_readiness_gate.v1',
            'mode' => 'read_only_operator_next_action_readiness_gate',
            'status' => $blockingReasons === [] ? 'ready_for_operator_review' : 'blocked_operator_review_required',
            'ready_for_operator_review' => $blockingReasons === [],
            'safe_to_copy_after_operator_review' => (bool) data_get($operatorNextActionShellPacket, 'safe_to_copy_after_operator_review', false),
            'current_required_artifact' => (string) data_get($operatorCompletionProgressMeter, 'current_required_artifact', ''),
            'current_step_id' => (string) data_get($operatorNextActionShellPacket, 'current_step_id', ''),
            'blocking_reasons' => $blockingReasons,
            'blocking_reason_count' => count($blockingReasons),
            'required_before_copy' => [
                'replace_all_placeholders',
                'rerun_final_operator_evidence_closure_corridor_status',
                'review_post_action_verification_bundle',
                'review_operator_failure_recovery_matrix',
            ],
            'required_after_action' => [
                'run_post_action_verification_commands',
                'stop_if_any_verifier_is_not_green',
                'rerun_completion_audit_before_claiming_completion',
            ],
            'resume_command' => (string) data_get($operatorNextActionShellPacket, 'resume_after_interruption.resume_command', ''),
            'post_action_verification_hash' => (string) data_get($operatorNextActionShellPacket, 'post_action_verification_bundle.verification_bundle_hash', ''),
            'failure_recovery_matrix_hash' => (string) data_get($operatorFailureRecoveryMatrix, 'failure_recovery_matrix_hash', ''),
            'can_execute_from_gate' => false,
            'can_persist_from_gate' => false,
            'can_call_provider_from_gate' => false,
            'can_sign_for_operator_from_gate' => false,
            'can_mark_completion_from_gate' => false,
        ];
        $gate['readiness_gate_hash'] = $this->support->stableHash($gate);

        return $gate;
    }

    /**
     * @param  array<string, mixed>  $operatorNextAction
     * @param  array<string, mixed>  $operatorCompletionProgressMeter
     * @param  list<string>  $proofCommands
     * @return array<string, mixed>
     */
    public function operatorNextActionResumeAfterInterruption(
        array $operatorNextAction,
        array $operatorCompletionProgressMeter,
        array $proofCommands,
        string $closureCorridorStatusCommand,
    ): array {
        $resumeCommand = $closureCorridorStatusCommand;
        $resume = [
            'schema_version' => 'atlas.self_construction.final_operator_next_action_shell_packet_resume.v1',
            'mode' => 'read_only_resume_after_interruption_contract',
            'status' => 'resume_by_rerunning_closure_corridor_status',
            'current_required_artifact' => (string) data_get($operatorCompletionProgressMeter, 'current_required_artifact', ''),
            'current_step_id' => (string) data_get($operatorNextAction, 'next_step_id', ''),
            'resume_command' => $resumeCommand,
            'resumption_checkpoint_command' => $resumeCommand,
            'exact_command_to_recompare_after_resume' => (string) data_get($operatorNextAction, 'exact_command', ''),
            'exact_persist_command_to_recompare_after_resume' => (string) data_get($operatorNextAction, 'exact_persist_command', ''),
            'proof_commands_to_rerun' => $proofCommands,
            'requires_fresh_preflight_before_persist' => true,
            'do_not_run_persist_command_until_verifier_green' => true,
            'can_resume_without_chat_history' => true,
            'if_command_failed_next_action' => 'Rerun the closure corridor status, inspect the reported blocker, do not persist evidence, and do not skip to the next artifact.',
            'if_chat_context_was_lost_next_action' => 'Rerun resume_command and continue only from current_required_artifact/current_step_id reported by the JSON payload.',
            'forbidden_resume_shortcuts' => [
                'do_not_persist_from_stale_chat_memory',
                'do_not_skip_preflight_after_interruption',
                'do_not_reuse_placeholder_commands',
                'do_not_mark_completion_without_fresh_audit_green',
                'do_not_fabricate_operator_or_provider_evidence',
            ],
            'can_execute_from_resume_contract' => false,
            'can_persist_from_resume_contract' => false,
            'can_call_provider_from_resume_contract' => false,
            'can_sign_for_operator_from_resume_contract' => false,
        ];
        $resume['resume_contract_hash'] = $this->support->stableHash($resume);

        return $resume;
    }

    /**
     * @param  list<string>  $placeholderFields
     * @return list<array<string, mixed>>
     */
    public function placeholderReplacementContract(array $placeholderFields): array
    {
        $rows = [];
        foreach ($placeholderFields as $placeholder) {
            $placeholder = (string) $placeholder;
            $rows[] = match (true) {
                $placeholder === '<operator>' => [
                    'placeholder' => $placeholder,
                    'replacement_kind' => 'operator_identity',
                    'required' => true,
                    'minimum_length' => 3,
                    'must_not_equal' => ['<operator>', 'operator', 'codex', 'claude', 'assistant', 'system', 'atlas'],
                    'validation_hint' => 'Use the real human/operator signer identity.',
                ],
                str_contains($placeholder, 'reason') => [
                    'placeholder' => $placeholder,
                    'replacement_kind' => 'operator_reason',
                    'required' => true,
                    'minimum_length' => 32,
                    'must_not_contain' => ['todo', 'placeholder', 'autosigned', 'lorem'],
                    'validation_hint' => 'Explain the operator judgment in at least 32 characters.',
                ],
                str_starts_with($placeholder, '@/path/to/') => [
                    'placeholder' => $placeholder,
                    'replacement_kind' => 'operator_file_path',
                    'required' => true,
                    'minimum_length' => 2,
                    'must_point_to_existing_operator_reviewed_json' => true,
                    'validation_hint' => 'Replace with an operator-reviewed JSON artifact path.',
                ],
                default => [
                    'placeholder' => $placeholder,
                    'replacement_kind' => 'generic_operator_input',
                    'required' => true,
                    'minimum_length' => 1,
                    'validation_hint' => 'Replace before running; never execute a command with placeholders.',
                ],
            };
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    public function postActionSuccessChecks(string $nextRequiredSubmission): array
    {
        return match ($nextRequiredSubmission) {
            'runtime_promotion_receipt' => [
                ['surface' => 'runtime_promotion_receipt_draft', 'expected' => 'status=ready_for_operator_persistence'],
                ['surface' => 'operator_evidence_submission_readiness', 'expected' => 'next_required remains runtime_promotion_receipt until explicit persist succeeds'],
                ['surface' => 'completion_audit', 'expected' => 'runtime_gap_matrix_all_runtime_y remains blocked until persistence'],
            ],
            'real_provider_smoke' => [
                ['surface' => 'real_provider_smoke_offline_harness', 'expected' => 'operator has a bounded external smoke procedure'],
                ['surface' => 'real_provider_smoke_endgame', 'expected' => 'status becomes verifier_passed_ready_for_explicit_persistence only after real evidence'],
                ['surface' => 'completion_audit', 'expected' => 'end_to_end_real_provider_smoke_green remains blocked until persistence'],
            ],
            'human_completion_receipt' => [
                ['surface' => 'human_completion_receipt_draft', 'expected' => 'status=ready_for_operator_persistence only after runtime and smoke are green'],
                ['surface' => 'human_completion_receipt_endgame_verifier', 'expected' => 'can_persist=true before explicit persistence'],
                ['surface' => 'completion_audit', 'expected' => 'human_signed_os_complete_receipt_present remains blocked until persistence'],
            ],
            default => [
                ['surface' => 'completion_audit', 'expected' => 'status=complete AND completion_allowed=true AND failed_count=0'],
            ],
        };
    }

    /**
     * @param  list<string>  $proofCommands
     * @return array<string, mixed>
     */
    public function postActionVerificationBundle(
        string $nextRequiredSubmission,
        array $proofCommands,
        string $closureCorridorStatusCommand,
    ): array {
        $commands = array_values(array_unique(array_filter(array_merge(
            $proofCommands,
            [
                $closureCorridorStatusCommand,
                'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
        ))));
        $commandRows = [];
        foreach ($commands as $index => $command) {
            $commandRows[] = [
                'order' => $index + 1,
                'command' => $command,
                'command_hash' => hash('sha256', (string) $command),
            ];
        }

        $bundle = [
            'schema_version' => 'atlas.self_construction.final_operator_next_action_post_action_verification_bundle.v1',
            'mode' => 'read_only_post_action_verification_bundle',
            'status' => 'verify_before_next_artifact_or_persist',
            'next_required_submission' => $nextRequiredSubmission,
            'verification_commands' => $commandRows,
            'verification_command_count' => count($commandRows),
            'success_checks' => $this->postActionSuccessChecks($nextRequiredSubmission),
            'success_check_count' => count($this->postActionSuccessChecks($nextRequiredSubmission)),
            'failure_policy' => [
                'if_any_check_fails' => 'stop_and_rerun_final_operator_evidence_closure_corridor_status',
                'do_not_advance_to_next_artifact' => true,
                'do_not_persist_receipt_until_verifier_green' => true,
                'do_not_mark_os_complete_from_partial_verification' => true,
            ],
            'expected_completion_audit_after_action' => match ($nextRequiredSubmission) {
                'runtime_promotion_receipt' => 'runtime_gap_matrix_all_runtime_y remains blocked until explicit runtime promotion persistence succeeds',
                'real_provider_smoke' => 'end_to_end_real_provider_smoke_green remains blocked until operator-approved smoke evidence is persisted',
                'human_completion_receipt' => 'human_signed_os_complete_receipt_present remains blocked until explicit human receipt persistence succeeds',
                default => 'status=complete only when completion_allowed=true and failed_count=0',
            },
            'can_verify_from_bundle' => false,
            'can_execute_from_bundle' => false,
            'can_persist_from_bundle' => false,
            'can_call_provider_from_bundle' => false,
            'can_sign_for_operator_from_bundle' => false,
        ];
        $bundle['verification_bundle_hash'] = $this->support->stableHash($bundle);

        return $bundle;
    }

    /**
     * @param  list<array<string, mixed>>  $orderedOperatorPath
     * @param  array<string, mixed>  $submissionPreflight
     * @param  array<string, string>  $operatorCommandPlan
     * @return array<string, mixed>
     */
    public function operatorNextAction(
        array $orderedOperatorPath,
        array $submissionPreflight,
        array $operatorCommandPlan,
        string $closureCorridorStatusCommand,
        bool $completionAuditComplete,
    ): array {
        $nextRequiredSubmission = (string) data_get($submissionPreflight, 'next_required_submission', '');
        $stepId = match ($nextRequiredSubmission) {
            'runtime_promotion_receipt' => 'draft_runtime_promotion_receipt',
            'real_provider_smoke' => 'prepare_real_provider_smoke_offline_harness',
            'human_completion_receipt' => 'draft_human_completion_receipt',
            'final_completion_audit', 'rerun_completion_audit' => 'rerun_completion_audit',
            default => $completionAuditComplete ? 'promote_next_stage_only_after_completion_audit_complete' : 'rerun_completion_audit',
        };

        $step = collect($orderedOperatorPath)->firstWhere('id', $stepId) ?? [];
        $exactCommand = (string) data_get($step, 'command', '');
        $exactPersistCommand = (string) data_get($step, 'persist_command', '');
        if ($exactCommand === '' && isset($operatorCommandPlan[$stepId])) {
            $exactCommand = $operatorCommandPlan[$stepId];
        }

        $placeholderFields = $this->support->placeholderFields($exactCommand.' '.$exactPersistCommand);
        $automatic = (bool) data_get($step, 'can_run_automatically', false);
        $status = $completionAuditComplete
            ? 'completion_audit_complete_operator_may_review_next_stage'
            : ($automatic ? 'ready_for_safe_read_only_check' : 'blocked_operator_action_required');

        $payload = [
            'schema_version' => 'atlas.self_construction.final_operator_next_action.v1',
            'status' => $status,
            'next_required_submission' => $nextRequiredSubmission,
            'next_step_id' => $stepId,
            'next_step_phase' => (string) data_get($step, 'phase', ''),
            'next_step_status' => (string) data_get($step, 'status', ''),
            'exact_command' => $exactCommand,
            'exact_persist_command' => $exactPersistCommand,
            'command_contains_placeholders' => $placeholderFields !== [],
            'placeholder_fields_to_replace' => $placeholderFields,
            'can_run_automatically' => $automatic,
            'why_not_automatic' => $automatic ? '' : $this->whyNextActionIsNotAutomatic($stepId),
            'required_inputs' => (array) data_get($step, 'required_inputs', []),
            'missing_inputs' => (array) data_get($step, 'missing_inputs', []),
            'produced_artifacts' => (array) data_get($step, 'produced_artifacts', []),
            'verifier_service' => (string) data_get($step, 'verifier_service', ''),
            'stop_condition' => (string) data_get($step, 'stop_condition', ''),
            'forbidden_shortcuts' => (array) data_get($step, 'forbidden_shortcuts', []),
            'proof_commands_after_action' => [
                $closureCorridorStatusCommand,
                'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-submission-preflight-status --json',
                (string) ($operatorCommandPlan['refresh_terminal_loop_operational_proof'] ?? 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json'),
                (string) ($operatorCommandPlan['persist_terminal_loop_operational_proof_binding'] ?? 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --persist-terminal-loop-operational-proof-binding --json'),
                (string) ($operatorCommandPlan['rerun_completion_audit_with_terminal_loop_operational_proof'] ?? 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json --json'),
                (string) ($operatorCommandPlan['rerun_completion_audit_with_canonical_terminal_loop_operational_proof'] ?? $this->support->completionAuditWithCanonicalTerminalLoopOperationalProofCommand()),
                (string) ($operatorCommandPlan['effective_rerun_completion_audit_with_terminal_loop_operational_proof'] ?? $this->support->completionAuditWithCanonicalTerminalLoopOperationalProofCommand()),
            ],
            'success_predicate_after_all_actions' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'non_execution_guarantees' => [
                'next_action_projection_does_not_execute_command',
                'next_action_projection_does_not_persist_receipts',
                'next_action_projection_does_not_call_provider',
                'next_action_projection_does_not_spend_tokens',
                'next_action_projection_does_not_promote_completion',
            ],
        ];
        $payload['operator_next_action_hash'] = $this->support->stableHash($payload);

        return $payload;
    }

    public function whyNextActionIsNotAutomatic(string $stepId): string
    {
        return match ($stepId) {
            'draft_runtime_promotion_receipt',
            'persist_runtime_promotion_receipt_after_verifier_passes' => 'requires_operator_signature_and_runtime_promotion_judgment',
            'prepare_real_provider_smoke_offline_harness' => 'operator_must_review_before_any_real_provider_activity',
            'draft_real_provider_smoke_payload',
            'persist_real_provider_smoke_after_verifier_passes' => 'requires_operator_observed_real_provider_evidence',
            'draft_human_completion_receipt',
            'persist_human_completion_receipt_after_prerequisites_green',
            'promote_next_stage_only_after_completion_audit_complete' => 'requires_human_completion_judgment_and_signed_receipt',
            default => 'requires_operator_review_or_external_evidence',
        };
    }

}
