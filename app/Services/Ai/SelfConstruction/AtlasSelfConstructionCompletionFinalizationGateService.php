<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only final gate that decides whether Atlas Self-Construction OS may
 * claim completion AND whether the next stage may be promoted.
 *
 * It NEVER mutates state, NEVER promotes completion, NEVER signs receipts.
 * In the current real state (audit incomplete) it MUST return
 * completion_claim_allowed=false.
 */
final class AtlasSelfConstructionCompletionFinalizationGateService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.completion_finalization_gate.v1';

    public const MODE = 'read_only_completion_finalization_gate';

    private const CANONICAL_TERMINAL_LOOP_OPERATIONAL_PROOF_BINDING_PATH = 'atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';

    private const CANONICAL_TERMINAL_LOOP_OPERATIONAL_PROOF_BINDING_REFERENCE = '@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function evaluate(array $options = []): array
    {
        $terminalLoopProofJsonReference = $this->terminalLoopOperationalProofJsonReference($options);
        $completionAudit = (array) ($options['completion_audit']
            ?? (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit([
                'completion_receipt' => (array) ($options['completion_receipt'] ?? []),
                'real_provider_smoke' => (array) ($options['real_provider_smoke'] ?? []),
                'forge_self_improvement_smoke' => (array) ($options['forge_self_improvement_smoke'] ?? []),
                'agent_control_plane_terminal_loop_operational_proof' => (array) ($options['agent_control_plane_terminal_loop_operational_proof'] ?? []),
            ]));
        $completionEvidence = (array) ($options['completion_evidence']
            ?? $this->readiness->atlasSelfConstructionOsCompletionEvidenceStatus([
                'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? []),
                'real_provider_smoke' => (array) ($options['real_provider_smoke'] ?? []),
                'completion_receipt' => (array) ($options['completion_receipt'] ?? []),
                'forge_self_improvement_smoke' => (array) ($options['forge_self_improvement_smoke'] ?? []),
                'persist_completion_evidence' => false,
                'persist_runtime_promotion_receipt' => false,
            ]));
        $closureArtifactSequence = (array) data_get($completionEvidence, 'closure_artifact_sequence', []);
        $promptToArtifactChecklist = (array) data_get($completionEvidence, 'prompt_to_artifact_checklist', []);

        $checks = [
            'completion_audit_complete' => $this->check(
                $this->completionAuditComplete($completionAudit),
                'completion_audit.status_must_be_complete_failed_count_zero_and_completion_allowed',
                [
                    'completion_audit_status' => (string) data_get($completionAudit, 'status'),
                    'failed_count' => (int) data_get($completionAudit, 'failed_count', 0),
                    'failed_criteria' => (array) data_get($completionAudit, 'failed_criteria', []),
                    'completion_allowed' => (bool) data_get($completionAudit, 'completion_allowed', false),
                    'completion_claim_allowed' => (bool) data_get($completionAudit, 'completion_claim_allowed', false),
                ],
            ),
            'runtime_all_y' => $this->check(
                $this->criterionGreen($completionAudit, 'runtime_gap_matrix_all_runtime_y')
                    && (string) data_get($completionEvidence, 'runtime_gap_matrix.status') === 'passed'
                    && (bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false) === true
                    && (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status') === 'passed',
                'completion_audit.criteria.runtime_gap_matrix_all_runtime_y_must_be_passed_and_completion_evidence_runtime_matrix_must_be_green',
                [
                    'runtime_gap_matrix_status' => (string) data_get($completionEvidence, 'runtime_gap_matrix.status', ''),
                    'runtime_gap_matrix_all_runtime_y' => (bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false),
                    'runtime_promotion_receipt_status' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', ''),
                ],
            ),
            'smoke_green' => $this->check(
                $this->criterionGreen($completionAudit, 'end_to_end_real_provider_smoke_green')
                    && (string) data_get($completionEvidence, 'real_provider_smoke.status') === 'passed',
                'completion_audit.criteria.end_to_end_real_provider_smoke_green_must_be_passed_and_completion_evidence_smoke_must_be_green',
                [
                    'real_provider_smoke_status' => (string) data_get($completionEvidence, 'real_provider_smoke.status', ''),
                ],
            ),
            'human_receipt_green' => $this->check(
                $this->criterionGreen($completionAudit, 'human_signed_os_complete_receipt_present')
                    && (string) data_get($completionEvidence, 'human_signed_completion_receipt.status') === 'passed'
                    && (bool) data_get($completionEvidence, 'human_signed_completion_receipt.completion_claim_allowed', false) === true,
                'completion_audit.criteria.human_signed_os_complete_receipt_present_must_be_passed_and_completion_evidence_human_receipt_must_be_green',
                [
                    'human_signed_completion_receipt_status' => (string) data_get($completionEvidence, 'human_signed_completion_receipt.status', ''),
                    'human_signed_completion_receipt_completion_claim_allowed' => (bool) data_get($completionEvidence, 'human_signed_completion_receipt.completion_claim_allowed', false),
                ],
            ),
            'replay_green' => $this->check(
                $this->criterionGreen($completionAudit, 'replay_diff_against_completion_snapshot_green'),
                'completion_audit.criteria.replay_diff_against_completion_snapshot_green_must_be_passed',
                [],
            ),
            'promotion_gate_green' => $this->check(
                $this->criterionGreen($completionAudit, 'promotion_gate_green'),
                'completion_audit.criteria.promotion_gate_green_must_be_passed',
                [],
            ),
            'dossier_green' => $this->check(
                $this->criterionGreen($completionAudit, 'release_dossier_green'),
                'completion_audit.criteria.release_dossier_green_must_be_passed',
                [],
            ),
            'batch_green' => $this->check(
                $this->criterionGreen($completionAudit, 'certification_status_batch_green'),
                'completion_audit.criteria.certification_status_batch_green_must_be_passed',
                [],
            ),
            'mutation_guard_green' => $this->check(
                $this->criterionGreen($completionAudit, 'mutation_guard_green'),
                'completion_audit.criteria.mutation_guard_green_must_be_passed',
                [],
            ),
            'forge_self_improvement_integration_smoke_green' => $this->check(
                $this->criterionGreen($completionAudit, 'forge_self_improvement_integration_smoke_green'),
                'completion_audit.criteria.forge_self_improvement_integration_smoke_green_must_be_passed',
                [],
            ),
            'terminal_loop_green' => $this->check(
                $this->criterionGreen($completionAudit, 'agent_control_plane_terminal_loop_certification_green')
                    && $this->terminalLoopOperationalProofAccepted($completionAudit),
                'completion_audit.criteria.agent_control_plane_terminal_loop_certification_green_must_be_passed_with_terminal_loop_operational_proof_binding',
                [
                    'operational_proof_status' => (string) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.status', ''),
                    'operational_proof_supplied' => (bool) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.supplied', false),
                    'operational_proof_passed' => (bool) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.passed', false),
                    'operational_proof_hash' => (string) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.proof_hash', ''),
                    'operational_proof_validation_violation_count' => (int) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.validation_violation_count', 0),
                    'post_cycle_cycle_supervisor_status' => (string) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_cycle_supervisor_status', ''),
                    'post_cycle_end_to_end_contract_status' => (string) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_end_to_end_contract_status', ''),
                    'post_cycle_end_to_end_contract_all_required_surfaces_present' => (bool) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_end_to_end_contract_all_required_surfaces_present', false),
                    'post_cycle_end_to_end_contract_missing_required_capabilities' => (array) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_end_to_end_contract_missing_required_capabilities', []),
                    'post_cycle_end_to_end_contract_hash' => (string) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_end_to_end_contract_hash', ''),
                    'post_cycle_cleanup_state' => (array) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_cleanup_state', []),
                    'dispatch_allowed' => (bool) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.dispatch_allowed', false),
                    'adapter_execution_allowed' => (bool) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.adapter_execution_allowed', false),
                    'self_programming_allowed' => (bool) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence.self_programming_allowed', false),
                    'expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
                ],
            ),
            'evidence_hashes_match_completion_audit' => $this->check(
                $this->evidenceHashesMatchAudit($completionAudit, $completionEvidence),
                'completion_evidence_hashes_must_match_completion_audit_criterion_evidence',
                $this->evidenceHashComparison($completionAudit, $completionEvidence),
            ),
        ];

        $failed = array_values(array_filter(
            array_keys($checks),
            static fn (string $key): bool => (bool) ($checks[$key]['passed'] ?? false) !== true,
        ));

        $completionClaimAllowed = $failed === [];
        $nextStageAllowed = $completionClaimAllowed;
        $nextStageBlockers = array_values(array_map(
            static fn (string $key): string => 'finalization_gate_blocked_by_'.$key,
            $failed,
        ));

        $status = $completionClaimAllowed ? 'passed' : 'blocked';
        $operatorHandoff = $this->completionFinalizationOperatorHandoff(
            completionAudit: $completionAudit,
            completionEvidence: $completionEvidence,
            checks: $checks,
            failed: $failed,
            nextStageBlockers: $nextStageBlockers,
            completionClaimAllowed: $completionClaimAllowed,
            terminalLoopProofJsonReference: $terminalLoopProofJsonReference,
        );

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_audit_complete' => $checks['completion_audit_complete']['passed'],
            'runtime_all_y' => $checks['runtime_all_y']['passed'],
            'smoke_green' => $checks['smoke_green']['passed'],
            'human_receipt_green' => $checks['human_receipt_green']['passed'],
            'replay_green' => $checks['replay_green']['passed'],
            'promotion_gate_green' => $checks['promotion_gate_green']['passed'],
            'dossier_green' => $checks['dossier_green']['passed'],
            'batch_green' => $checks['batch_green']['passed'],
            'mutation_guard_green' => $checks['mutation_guard_green']['passed'],
            'forge_self_improvement_integration_smoke_green' => $checks['forge_self_improvement_integration_smoke_green']['passed'],
            'terminal_loop_green' => $checks['terminal_loop_green']['passed'],
            'evidence_hashes_match_completion_audit' => $checks['evidence_hashes_match_completion_audit']['passed'],
            'completion_claim_allowed' => $completionClaimAllowed,
            'next_stage_allowed' => $nextStageAllowed,
            'next_stage_blockers' => $nextStageBlockers,
            'next_stage_blocker_count' => count($nextStageBlockers),
            'checks' => $checks,
            'failed_check_ids' => $failed,
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'terminal_loop_operational_proof_required_before_completion_claim' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'command_to_refresh_terminal_loop_operational_proof' => $this->terminalLoopOperationalProofCommand(),
            'command_to_persist_terminal_loop_operational_proof_binding' => $this->terminalLoopOperationalProofBindingPersistCommand(),
            'command_to_capture_snapshot_after_terminal_loop_operational_proof' => $this->captureSnapshotAfterTerminalLoopOperationalProofCommand(),
            'command_to_rerun_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand($terminalLoopProofJsonReference),
            'terminal_loop_operational_proof_json_reference' => $terminalLoopProofJsonReference,
            'final_verification_sequence' => $this->finalVerificationSequence($terminalLoopProofJsonReference),
            'completion_audit_green_requires_current_snapshot_after_terminal_loop_proof' => true,
            'completion_evidence_status_hash' => (string) data_get($completionEvidence, 'completion_evidence_status_hash', ''),
            'closure_artifact_sequence' => $closureArtifactSequence,
            'closure_artifact_sequence_count' => count($closureArtifactSequence),
            'closure_artifact_sequence_hash' => (string) data_get($completionEvidence, 'closure_artifact_sequence_hash', ''),
            'prompt_to_artifact_checklist' => $promptToArtifactChecklist,
            'prompt_to_artifact_checklist_count' => count($promptToArtifactChecklist),
            'prompt_to_artifact_checklist_passed_count' => (int) data_get($completionEvidence, 'prompt_to_artifact_checklist_passed_count', 0),
            'prompt_to_artifact_checklist_hash' => (string) data_get($completionEvidence, 'prompt_to_artifact_checklist_hash', ''),
            'completion_audit_status' => (string) data_get($completionAudit, 'status'),
            'completion_audit_failed_criteria' => (array) data_get($completionAudit, 'failed_criteria', []),
            'completion_finalization_operator_handoff' => $operatorHandoff,
            'completion_finalization_operator_handoff_hash' => $operatorHandoff['completion_finalization_operator_handoff_hash'],
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                'completion_finalization_gate_does_not_promote_completion',
                'completion_finalization_gate_does_not_sign_receipts',
                'completion_finalization_gate_does_not_persist_receipts',
                'completion_finalization_gate_does_not_call_provider',
                'completion_finalization_gate_does_not_spend_tokens',
                'completion_finalization_gate_does_not_dispatch_work',
                'completion_finalization_gate_does_not_enable_runtime',
            ],
        ];
        $payload['completion_finalization_gate_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $completionEvidence
     * @param  array<string, array<string, mixed>>  $checks
     * @param  list<string>  $failed
     * @param  list<string>  $nextStageBlockers
     * @return array<string, mixed>
     */
    private function completionFinalizationOperatorHandoff(
        array $completionAudit,
        array $completionEvidence,
        array $checks,
        array $failed,
        array $nextStageBlockers,
        bool $completionClaimAllowed,
        string $terminalLoopProofJsonReference = '',
    ): array {
        $failedCriteria = (array) data_get($completionAudit, 'failed_criteria', []);
        $blockerClassification = (array) data_get($completionAudit, 'blocker_classification', []);
        $currentRequiredArtifact = $this->currentRequiredOperatorArtifact($failedCriteria, $failed, $blockerClassification);
        $operatorEvidenceReadinessCommand = 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json';
        $orderedNextCommands = [
            $operatorEvidenceReadinessCommand,
            $this->terminalLoopOperationalProofCommand(),
            $this->terminalLoopOperationalProofBindingPersistCommand(),
            $this->captureSnapshotAfterTerminalLoopOperationalProofCommand(),
            $this->completionAuditWithTerminalLoopOperationalProofCommand($terminalLoopProofJsonReference),
            $this->completionFinalizationGateCommand($terminalLoopProofJsonReference),
        ];
        $nextActionShellPacket = $this->nextActionShellPacket(
            currentRequiredArtifact: $currentRequiredArtifact,
            completionClaimAllowed: $completionClaimAllowed,
            orderedNextCommands: $orderedNextCommands,
            terminalLoopProofJsonReference: $terminalLoopProofJsonReference,
        );

        $handoff = [
            'schema_version' => 'atlas.self_construction.completion_finalization_operator_handoff.v1',
            'mode' => 'read_only_completion_finalization_operator_handoff',
            'status' => $completionClaimAllowed ? 'ready_for_final_operator_review' : 'blocked_operator_or_provider_evidence_required',
            'completion_claim_allowed_after_handoff' => $completionClaimAllowed,
            'next_stage_allowed_after_handoff' => $completionClaimAllowed,
            'current_required_operator_artifact' => $currentRequiredArtifact,
            'failed_check_ids' => $failed,
            'failed_check_count' => count($failed),
            'next_stage_blockers' => $nextStageBlockers,
            'next_stage_blocker_count' => count($nextStageBlockers),
            'completion_audit_status' => (string) data_get($completionAudit, 'status'),
            'completion_audit_failed_criteria' => $failedCriteria,
            'completion_audit_failed_count' => (int) data_get($completionAudit, 'failed_count', count($failedCriteria)),
            'blocker_classification' => $blockerClassification,
            'completion_evidence_status_hash' => (string) data_get($completionEvidence, 'completion_evidence_status_hash', ''),
            'runtime_gap_matrix_status' => (string) data_get($completionEvidence, 'runtime_gap_matrix.status', ''),
            'real_provider_smoke_status' => (string) data_get($completionEvidence, 'real_provider_smoke.status', ''),
            'human_signed_completion_receipt_status' => (string) data_get($completionEvidence, 'human_signed_completion_receipt.status', ''),
            'operator_evidence_readiness_command' => $operatorEvidenceReadinessCommand,
            'terminal_loop_operational_proof_command' => $this->terminalLoopOperationalProofCommand(),
            'terminal_loop_operational_proof_binding_persist_command' => $this->terminalLoopOperationalProofBindingPersistCommand(),
            'capture_snapshot_after_terminal_loop_operational_proof_command' => $this->captureSnapshotAfterTerminalLoopOperationalProofCommand(),
            'completion_audit_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            'completion_audit_with_terminal_loop_operational_proof_command' => $this->completionAuditWithTerminalLoopOperationalProofCommand($terminalLoopProofJsonReference),
            'terminal_loop_operational_proof_json_reference' => $terminalLoopProofJsonReference,
            'finalization_gate_command' => $this->completionFinalizationGateCommand($terminalLoopProofJsonReference),
            'ordered_next_commands' => $orderedNextCommands,
            'next_action_shell_packet' => $nextActionShellPacket,
            'final_verification_sequence' => $this->finalVerificationSequence($terminalLoopProofJsonReference),
            'required_success_predicate' => [
                'completion_audit_status_must_be_complete' => (string) data_get($completionAudit, 'status') === 'complete',
                'completion_audit_failed_criteria_must_be_empty' => $failedCriteria === [],
                'terminal_loop_operational_proof_must_be_bound_and_passed' => $this->terminalLoopOperationalProofAccepted($completionAudit),
                'release_snapshot_must_be_current_after_terminal_loop_operational_proof' => true,
                'completion_evidence_hashes_must_match_audit' => (bool) data_get($checks, 'evidence_hashes_match_completion_audit.passed', false),
                'finalization_gate_failed_checks_must_be_empty' => $failed === [],
                'completion_claim_allowed_must_be_true' => $completionClaimAllowed,
            ],
            'can_execute_from_handoff' => false,
            'can_persist_from_handoff' => false,
            'can_sign_from_handoff' => false,
            'can_call_provider_from_handoff' => false,
            'can_dispatch_from_handoff' => false,
            'can_promote_completion_from_handoff' => false,
            'non_execution_guarantees' => [
                'handoff_is_read_only',
                'handoff_does_not_persist_operator_evidence',
                'handoff_does_not_sign_receipts',
                'handoff_does_not_call_provider',
                'handoff_does_not_spend_tokens',
                'handoff_does_not_dispatch_work',
                'handoff_does_not_promote_os_completion',
            ],
        ];
        $handoff['completion_finalization_operator_handoff_hash'] = $this->stableHash($handoff);

        return $handoff;
    }

    /**
     * @param  list<string>  $orderedNextCommands
     * @return array<string, mixed>
     */
    private function nextActionShellPacket(
        string $currentRequiredArtifact,
        bool $completionClaimAllowed,
        array $orderedNextCommands,
        string $terminalLoopProofJsonReference,
    ): array {
        $exactCommand = $completionClaimAllowed
            ? $this->completionFinalizationGateCommand($terminalLoopProofJsonReference)
            : ($orderedNextCommands[0] ?? '');
        $placeholders = $this->commandPlaceholders($exactCommand);
        $packet = [
            'schema_version' => 'atlas.self_construction.completion_finalization_next_action_shell_packet.v1',
            'mode' => 'read_only_completion_finalization_next_action_shell_packet',
            'status' => $placeholders === [] ? 'copy_ready_after_fresh_status_review' : 'blocked_placeholder_replacement_required',
            'current_required_operator_artifact' => $currentRequiredArtifact,
            'exact_command' => $exactCommand,
            'exact_command_hash' => $exactCommand === '' ? '' : hash('sha256', $exactCommand),
            'placeholder_count' => count($placeholders),
            'placeholders' => $placeholders,
            'copy_safe' => $placeholders === [] && $exactCommand !== '',
            'requires_fresh_finalization_gate_status_before_copy' => true,
            'requires_fresh_operator_evidence_readiness_before_persist' => ! $completionClaimAllowed,
            'can_resume_without_chat_history' => true,
            'post_action_proof_commands' => [
                'operator_evidence_readiness' => $orderedNextCommands[0] ?? '',
                'terminal_loop_operational_proof_binding' => $this->terminalLoopOperationalProofBindingPersistCommand(),
                'capture_snapshot_after_terminal_loop_operational_proof' => $this->captureSnapshotAfterTerminalLoopOperationalProofCommand(),
                'completion_audit_with_terminal_loop_operational_proof' => $this->completionAuditWithTerminalLoopOperationalProofCommand($terminalLoopProofJsonReference),
                'completion_finalization_gate' => $this->completionFinalizationGateCommand($terminalLoopProofJsonReference),
            ],
            'success_check' => $completionClaimAllowed
                ? 'completion_finalization_gate.status=passed AND completion_claim_allowed=true AND next_stage_allowed=true'
                : 'operator_evidence_submission_readiness.current_required_operator_artifact advances or completion_audit failed_count decreases',
            'failure_policy' => [
                'stop_if_placeholder_remains',
                'stop_if_operator_evidence_readiness_still_reports_same_missing_required_artifact_after_persist',
                'stop_if_completion_audit_reports_technical_blocker',
                'stop_if_terminal_loop_operational_proof_binding_is_missing_before_final_claim',
            ],
            'non_execution_guarantees' => [
                'shell_packet_does_not_execute_commands',
                'shell_packet_does_not_persist_receipts',
                'shell_packet_does_not_call_provider',
                'shell_packet_does_not_spend_tokens',
                'shell_packet_does_not_dispatch_work',
                'shell_packet_does_not_sign_for_operator',
                'shell_packet_does_not_promote_completion',
            ],
        ];
        $packet['shell_packet_hash'] = $this->stableHash($packet);

        return $packet;
    }

    /** @return list<string> */
    private function commandPlaceholders(string $command): array
    {
        preg_match_all('/<[^>]+>|@\/path\/to\/[^\s]+/', $command, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function terminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --json';
    }

    private function terminalLoopOperationalProofBindingPersistCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-terminal-loop-operational-proof-status --persist-terminal-loop-operational-proof-binding --json';
    }

    private function captureSnapshotAfterTerminalLoopOperationalProofCommand(): string
    {
        return 'php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json';
    }

    private function completionAuditWithTerminalLoopOperationalProofCommand(string $proofJsonReference = ''): string
    {
        $reference = $proofJsonReference !== '' ? $proofJsonReference : '@/path/to/terminal-loop-operational-proof-binding.json';

        return 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json='.$reference.' --json';
    }

    private function completionFinalizationGateCommand(string $proofJsonReference = ''): string
    {
        $suffix = $proofJsonReference !== ''
            ? ' --agent-control-plane-terminal-loop-operational-proof-json='.$proofJsonReference
            : '';

        return 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-finalization-gate-status'.$suffix.' --json';
    }

    /** @return list<array<string, mixed>> */
    private function finalVerificationSequence(string $proofJsonReference = ''): array
    {
        return [
            [
                'id' => 'refresh_terminal_loop_operational_proof_and_export_binding',
                'command' => $this->terminalLoopOperationalProofBindingPersistCommand(),
                'may_make_release_snapshot_stale' => true,
            ],
            [
                'id' => 'capture_replay_snapshot_after_terminal_loop_operational_proof',
                'command' => $this->captureSnapshotAfterTerminalLoopOperationalProofCommand(),
                'must_run_after' => 'refresh_terminal_loop_operational_proof_and_export_binding',
            ],
            [
                'id' => 'rerun_completion_audit_with_terminal_loop_operational_proof_binding',
                'command' => $this->completionAuditWithTerminalLoopOperationalProofCommand($proofJsonReference),
                'must_run_after' => 'capture_replay_snapshot_after_terminal_loop_operational_proof',
                'success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            ],
        ];
    }

    /** @param array<string, mixed> $options */
    private function terminalLoopOperationalProofJsonReference(array $options): string
    {
        $reference = trim((string) ($options['agent_control_plane_terminal_loop_operational_proof_json'] ?? ''));

        if (str_starts_with($reference, '@')) {
            return $reference;
        }

        return Storage::disk('local')->exists(self::CANONICAL_TERMINAL_LOOP_OPERATIONAL_PROOF_BINDING_PATH)
            ? self::CANONICAL_TERMINAL_LOOP_OPERATIONAL_PROOF_BINDING_REFERENCE
            : '';
    }

    /** @param array<string, mixed> $completionAudit */
    private function terminalLoopOperationalProofAccepted(array $completionAudit): bool
    {
        $proof = (array) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence', []);
        $proofHash = (string) data_get($proof, 'proof_hash', '');
        $endToEndContractHash = (string) data_get($proof, 'post_cycle_end_to_end_contract_hash', '');

        return (string) data_get($proof, 'status', '') === 'passed'
            && (bool) data_get($proof, 'supplied', false) === true
            && (bool) data_get($proof, 'passed', false) === true
            && preg_match('/^[a-f0-9]{64}$/', $proofHash) === 1
            && (int) data_get($proof, 'validation_violation_count', 1) === 0
            && (string) data_get($proof, 'post_cycle_end_to_end_contract_status', '') === 'terminal_loop_end_to_end_contract_available'
            && (bool) data_get($proof, 'post_cycle_end_to_end_contract_all_required_surfaces_present', false) === true
            && (array) data_get($proof, 'post_cycle_end_to_end_contract_failed_check_ids', []) === []
            && (array) data_get($proof, 'post_cycle_end_to_end_contract_missing_required_capabilities', []) === []
            && preg_match('/^[a-f0-9]{64}$/', $endToEndContractHash) === 1
            && (int) data_get($proof, 'post_cycle_cleanup_state.claimed_task_count', 1) === 0
            && (int) data_get($proof, 'post_cycle_cleanup_state.active_lease_count', 1) === 0
            && (int) data_get($proof, 'post_cycle_cleanup_state.recoverable_lease_count', 1) === 0
            && (bool) data_get($proof, 'dispatch_allowed', true) === false
            && (bool) data_get($proof, 'adapter_execution_allowed', true) === false
            && (bool) data_get($proof, 'self_programming_allowed', true) === false;
    }

    /**
     * @param  list<string>  $failedCriteria
     * @param  list<string>  $failedChecks
     * @param  array<string, mixed>  $blockerClassification
     */
    private function currentRequiredOperatorArtifact(array $failedCriteria, array $failedChecks, array $blockerClassification): string
    {
        if (in_array('runtime_gap_matrix_all_runtime_y', $failedCriteria, true)) {
            return 'runtime_promotion_receipt';
        }

        if (in_array('end_to_end_real_provider_smoke_green', $failedCriteria, true)) {
            return 'real_provider_smoke_certification';
        }

        if (in_array('human_signed_os_complete_receipt_present', $failedCriteria, true)) {
            return 'human_signed_completion_receipt';
        }

        if ((int) ($blockerClassification['technical_blocker_count'] ?? 0) > 0) {
            return 'technical_completion_audit_repair';
        }

        if ($failedChecks !== []) {
            return 'completion_finalization_gate_repair';
        }

        return 'final_operator_review';
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function check(bool $passed, string $evidenceSource, array $evidence): array
    {
        return [
            'passed' => $passed,
            'evidence_source' => $evidenceSource,
            'evidence' => $evidence,
        ];
    }

    /** @param array<string, mixed> $completionAudit */
    private function completionAuditComplete(array $completionAudit): bool
    {
        return (string) data_get($completionAudit, 'status') === 'complete'
            && (int) data_get($completionAudit, 'failed_count', 0) === 0
            && (array) data_get($completionAudit, 'failed_criteria', []) === []
            && (bool) data_get($completionAudit, 'completion_allowed', false) === true
            && (bool) data_get($completionAudit, 'completion_claim_allowed', false) === true;
    }

    /** @param array<string, mixed> $completionAudit */
    private function criterionGreen(array $completionAudit, string $id): bool
    {
        foreach ((array) data_get($completionAudit, 'criteria', []) as $criterion) {
            if ((string) ($criterion['id'] ?? '') === $id) {
                return (bool) ($criterion['passed'] ?? false);
            }
        }

        return false;
    }

    /** @param array<string, mixed> $completionAudit */
    private function criterionEvidence(array $completionAudit, string $id): array
    {
        foreach ((array) data_get($completionAudit, 'criteria', []) as $criterion) {
            if ((string) ($criterion['id'] ?? '') === $id) {
                return (array) ($criterion['evidence'] ?? []);
            }
        }

        return [];
    }

    /** @param array<string, mixed> $completionAudit */
    private function evidenceHashesMatchAudit(array $completionAudit, array $completionEvidence): bool
    {
        $comparison = $this->evidenceHashComparison($completionAudit, $completionEvidence);

        return $comparison['all_required_hashes_present'] === true
            && $comparison['runtime_gap_matrix_hash_matches'] === true
            && $comparison['runtime_promotion_receipt_hash_matches'] === true
            && $comparison['real_provider_smoke_hash_matches'] === true
            && $comparison['human_completion_receipt_hash_matches'] === true;
    }

    /** @param array<string, mixed> $completionAudit */
    private function evidenceHashComparison(array $completionAudit, array $completionEvidence): array
    {
        $runtimeEvidence = $this->criterionEvidence($completionAudit, 'runtime_gap_matrix_all_runtime_y');
        $smokeEvidence = $this->criterionEvidence($completionAudit, 'end_to_end_real_provider_smoke_green');
        $humanEvidence = $this->criterionEvidence($completionAudit, 'human_signed_os_complete_receipt_present');

        $expectedRuntimeGapMatrixHash = (string) data_get($runtimeEvidence, 'runtime_gap_matrix_hash', '');
        $actualRuntimeGapMatrixHash = (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_gap_matrix_hash', '');
        $expectedRuntimePromotionReceiptHash = (string) data_get($runtimeEvidence, 'runtime_promotion_receipt_hash', '');
        $actualRuntimePromotionReceiptHash = (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', '');
        $expectedSmokeHash = (string) data_get($smokeEvidence, 'smoke_hash', '');
        $actualSmokeHash = (string) data_get($completionEvidence, 'real_provider_smoke.smoke_hash', '');
        $expectedHumanReceiptHash = (string) data_get($humanEvidence, 'receipt_hash', '');
        $actualHumanReceiptHash = (string) data_get($completionEvidence, 'human_signed_completion_receipt.receipt_hash', '');

        $hashes = [
            $expectedRuntimeGapMatrixHash,
            $actualRuntimeGapMatrixHash,
            $expectedRuntimePromotionReceiptHash,
            $actualRuntimePromotionReceiptHash,
            $expectedSmokeHash,
            $actualSmokeHash,
            $expectedHumanReceiptHash,
            $actualHumanReceiptHash,
        ];
        $allPresent = ! in_array('', $hashes, true)
            && count(array_filter($hashes, static fn (string $hash): bool => preg_match('/^[a-f0-9]{64}$/', $hash) === 1)) === count($hashes);

        return [
            'all_required_hashes_present' => $allPresent,
            'expected_runtime_gap_matrix_hash' => $expectedRuntimeGapMatrixHash,
            'actual_runtime_gap_matrix_hash' => $actualRuntimeGapMatrixHash,
            'runtime_gap_matrix_hash_matches' => $expectedRuntimeGapMatrixHash !== '' && $expectedRuntimeGapMatrixHash === $actualRuntimeGapMatrixHash,
            'expected_runtime_promotion_receipt_hash' => $expectedRuntimePromotionReceiptHash,
            'actual_runtime_promotion_receipt_hash' => $actualRuntimePromotionReceiptHash,
            'runtime_promotion_receipt_hash_matches' => $expectedRuntimePromotionReceiptHash !== '' && $expectedRuntimePromotionReceiptHash === $actualRuntimePromotionReceiptHash,
            'expected_real_provider_smoke_hash' => $expectedSmokeHash,
            'actual_real_provider_smoke_hash' => $actualSmokeHash,
            'real_provider_smoke_hash_matches' => $expectedSmokeHash !== '' && $expectedSmokeHash === $actualSmokeHash,
            'expected_human_completion_receipt_hash' => $expectedHumanReceiptHash,
            'actual_human_completion_receipt_hash' => $actualHumanReceiptHash,
            'human_completion_receipt_hash_matches' => $expectedHumanReceiptHash !== '' && $expectedHumanReceiptHash === $actualHumanReceiptHash,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['completion_finalization_gate_hash']);

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
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
