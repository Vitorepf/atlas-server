<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOsCompletionAuditService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalEvidenceBundleService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionAuditBlockerExplainerService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService;
/**
 * GOD-DEBULK extracted stateful OS-completion/final-evidence status family from AtlasSelfConstructionReadinessService (OS completion audit, operator action packet, final evidence bundle, completion audit blocker explainer, completion evidence submission preflight).
 * Bound via setMother(); undefined method calls bridge through __call and undefined
 * property reads bridge through __get (ReflectionMethod / ReflectionProperty on the mother)
 * so the moved bodies stay byte-identical to the god service originals.
 */
final class ReadinessProjectionOsCompletionEvidenceSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException('ReadinessProjectionOsCompletionEvidenceSection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }

    public function __get(string $name): mixed
    {
        $property = new \ReflectionProperty($this->mother, $name);

        return $property->getValue($this->mother);
    }


    public function atlasSelfConstructionOsCompletionAuditStatus(array $options = []): array
    {
        $options = $this->withTerminalLoopOperationalProofPayload($options);

        $result = (new AtlasSelfConstructionOsCompletionAuditService($this->mother))->audit($options);
        $certificationStatusBatchCriterion = [];
        foreach ((array) data_get($result, 'criteria', []) as $criterion) {
            if ((string) data_get($criterion, 'id', '') === 'certification_status_batch_green') {
                $certificationStatusBatchCriterion = (array) $criterion;
                break;
            }
        }
        $certificationStatusBatchCommand = 'php artisan atlas:ai:self-construction --agent-control-plane-certification-status-batch-status --json';
        $terminalLoopOperationalProofCanonicalBindingPath = 'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';
        $completionAuditWithCanonicalTerminalLoopOperationalProofCommand = 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --agent-control-plane-terminal-loop-operational-proof-json=@'.$terminalLoopOperationalProofCanonicalBindingPath.' --json';

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_os_completion_audit',
            label: 'Atlas Self-Construction OS Completion Audit',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'completion_audit_hash' => (string) data_get($result, 'completion_audit_hash'),
                'completion_allowed' => (bool) data_get($result, 'completion_allowed', false),
                'completion_claim_allowed' => (bool) data_get($result, 'completion_claim_allowed', false),
                'self_programming_allowed' => false,
                'criteria_count' => (int) data_get($result, 'criteria_count'),
                'passed_count' => (int) data_get($result, 'passed_count'),
                'failed_count' => (int) data_get($result, 'failed_count'),
                'failed_criteria' => (array) data_get($result, 'failed_criteria', []),
                'human_blocker_count' => (int) data_get($result, 'blocker_classification.human_blocker_count', 0),
                'human_blockers' => (array) data_get($result, 'blocker_classification.human_blockers', []),
                'real_provider_blocker_count' => (int) data_get($result, 'blocker_classification.real_provider_blocker_count', 0),
                'real_provider_blockers' => (array) data_get($result, 'blocker_classification.real_provider_blockers', []),
                'technical_blocker_count' => (int) data_get($result, 'blocker_classification.technical_blocker_count', 0),
                'technical_blockers' => (array) data_get($result, 'blocker_classification.technical_blockers', []),
                'completion_claim_authority_verdict_status' => (string) data_get($result, 'completion_claim_authority_verdict.status', ''),
                'completion_claim_authority' => (string) data_get($result, 'completion_claim_authority_verdict.completion_authority', ''),
                'completion_claim_required_completion_predicate' => (string) data_get($result, 'completion_claim_authority_verdict.required_completion_predicate', ''),
                'completion_claim_external_agent_claim_accepted' => (bool) data_get($result, 'completion_claim_authority_verdict.external_agent_claim_accepted', true),
                'completion_claim_external_agent_claim_can_override_audit' => (bool) data_get($result, 'completion_claim_authority_verdict.external_agent_claim_can_override_audit', true),
                'completion_claim_external_agent_claim_can_mark_os_complete' => (bool) data_get($result, 'completion_claim_authority_verdict.external_agent_claim_can_mark_os_complete', true),
                'completion_claim_missing_evidence_count' => (int) data_get($result, 'completion_claim_authority_verdict.missing_evidence_count', 0),
                'completion_claim_authority_verdict_hash' => (string) data_get($result, 'completion_claim_authority_verdict.completion_claim_authority_verdict_hash', ''),
                'certification_status_batch_green_passed' => (bool) data_get($certificationStatusBatchCriterion, 'passed', false),
                'certification_status_batch_status' => (string) data_get($certificationStatusBatchCriterion, 'evidence.status', ''),
                'certification_status_batch_hash' => (string) data_get($certificationStatusBatchCriterion, 'evidence.hash', ''),
                'certification_status_batch_checked_count' => (int) data_get($certificationStatusBatchCriterion, 'evidence.checked_count', 0),
                'certification_status_batch_failed_count' => (int) data_get($certificationStatusBatchCriterion, 'evidence.failed_count', 0),
                'certification_status_batch_full_batch_required' => (bool) data_get($certificationStatusBatchCriterion, 'evidence.full_batch_required', false),
                'certification_status_batch_refresh_required' => in_array('certification_status_batch_green', (array) data_get($result, 'failed_criteria', []), true),
                'certification_status_batch_command' => $certificationStatusBatchCommand,
                'certification_status_batch_is_current_technical_blocker' => in_array('certification_status_batch_green', (array) data_get($result, 'blocker_classification.technical_blockers', []), true),
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'terminal_loop_operational_proof_canonical_binding_path' => $terminalLoopOperationalProofCanonicalBindingPath,
                'completion_audit_with_canonical_terminal_loop_operational_proof_command' => $completionAuditWithCanonicalTerminalLoopOperationalProofCommand,
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
                'current_required_operator_artifact' => match (true) {
                    in_array('runtime_gap_matrix_all_runtime_y', (array) data_get($result, 'failed_criteria', []), true) => 'runtime_promotion_receipt',
                    in_array('end_to_end_real_provider_smoke_green', (array) data_get($result, 'failed_criteria', []), true) => 'real_provider_smoke',
                    in_array('human_signed_os_complete_receipt_present', (array) data_get($result, 'failed_criteria', []), true) => 'human_completion_receipt',
                    default => 'none',
                },
                'operator_evidence_readiness_command' => (string) data_get($result, 'operator_action_packet.commands.operator_evidence_submission_readiness', ''),
                'final_operator_evidence_closure_corridor_command' => (string) data_get($result, 'operator_action_packet.commands.final_operator_evidence_closure_corridor', ''),
                'current_pointer' => (string) data_get($result, 'current_pointer'),
                'terminal_loop_operational_proof_status' => (string) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.status', ''),
                'terminal_loop_operational_proof_supplied' => (bool) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.supplied', false),
                'terminal_loop_operational_proof_passed' => (bool) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.passed', false),
                'terminal_loop_operational_proof_hash' => (string) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.proof_hash', ''),
                'terminal_loop_operational_proof_validation_violation_count' => (int) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.validation_violation_count', 0),
                'terminal_loop_operational_proof_validation_violations' => (array) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.validation_violations', []),
                'terminal_loop_operational_proof_post_cycle_cycle_supervisor_status' => (string) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_cycle_supervisor_status', ''),
                'terminal_loop_operational_proof_post_cycle_cycle_supervisor_hash' => (string) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_cycle_supervisor_hash', ''),
                'terminal_loop_operational_proof_post_cycle_end_to_end_contract_status' => (string) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_end_to_end_contract_status', ''),
                'terminal_loop_operational_proof_post_cycle_end_to_end_contract_all_required_surfaces_present' => (bool) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_end_to_end_contract_all_required_surfaces_present', false),
                'terminal_loop_operational_proof_post_cycle_end_to_end_contract_covered_capabilities' => (array) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_end_to_end_contract_covered_capabilities', []),
                'terminal_loop_operational_proof_post_cycle_end_to_end_contract_failed_check_ids' => (array) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_end_to_end_contract_failed_check_ids', []),
                'terminal_loop_operational_proof_post_cycle_end_to_end_contract_missing_required_capabilities' => (array) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_end_to_end_contract_missing_required_capabilities', []),
                'terminal_loop_operational_proof_post_cycle_end_to_end_contract_hash' => (string) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_end_to_end_contract_hash', ''),
                'terminal_loop_operational_proof_post_cycle_cleanup_state' => (array) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.post_cycle_cleanup_state', []),
                'terminal_loop_operational_proof_dispatch_allowed' => (bool) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.dispatch_allowed', false),
                'terminal_loop_operational_proof_adapter_execution_allowed' => (bool) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.adapter_execution_allowed', false),
                'terminal_loop_operational_proof_self_programming_allowed' => (bool) data_get($result, 'agent_control_plane_terminal_loop_operational_proof_evidence.self_programming_allowed', false),
            ],
        );
    }

    public function atlasSelfConstructionOsCompletionOperatorActionPacketStatus(array $options = []): array
    {
        $evidence = $this->atlasSelfConstructionOsCompletionEvidenceStatus($options);
        $result = (array) data_get($evidence, 'operator_action_packet', []);
        $humanBlockers = (array) data_get($result, 'blocker_classification.human_blockers', []);
        $realProviderBlockers = (array) data_get($result, 'blocker_classification.real_provider_blockers', []);
        $technicalBlockers = (array) data_get($result, 'blocker_classification.technical_blockers', []);
        $currentRequiredOperatorArtifact = match (true) {
            in_array('runtime_promotion_receipt', (array) data_get($result, 'missing_operator_artifacts', []), true) => 'runtime_promotion_receipt',
            in_array('real_provider_claim_to_completion_smoke', (array) data_get($result, 'missing_operator_artifacts', []), true) => 'real_provider_smoke',
            in_array('human_signed_os_complete_receipt', (array) data_get($result, 'missing_operator_artifacts', []), true) => 'human_completion_receipt',
            default => 'none',
        };
        $nextRequiredCommand = match ($currentRequiredOperatorArtifact) {
            'runtime_promotion_receipt' => (string) data_get($result, 'commands.draft_runtime_promotion_receipt', ''),
            'real_provider_smoke' => (string) data_get($result, 'commands.draft_real_provider_smoke', ''),
            'human_completion_receipt' => (string) data_get($result, 'commands.draft_human_completion_receipt', ''),
            default => (string) data_get($result, 'commands.run_completion_audit_with_canonical_terminal_loop_operational_proof', ''),
        };
        $nextRequiredPersistCommand = match ($currentRequiredOperatorArtifact) {
            'runtime_promotion_receipt' => (string) data_get($result, 'commands.persist_runtime_promotion_receipt', ''),
            'real_provider_smoke' => (string) data_get($result, 'commands.persist_real_provider_smoke', ''),
            'human_completion_receipt' => (string) data_get($result, 'commands.persist_human_completion_receipt', ''),
            default => '',
        };

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_os_completion_operator_action_packet',
            label: 'Atlas Self-Construction OS Completion Operator Action Packet',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'operator_action_packet_hash' => (string) data_get($result, 'operator_action_packet_hash'),
                'missing_operator_artifact_count' => count((array) data_get($result, 'missing_operator_artifacts', [])),
                'missing_operator_artifacts' => (array) data_get($result, 'missing_operator_artifacts', []),
                'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
                'next_required_command' => $nextRequiredCommand,
                'next_required_persist_command' => $nextRequiredPersistCommand,
                'runtime_gap_matrix_hash' => (string) data_get($evidence, 'runtime_gap_matrix.runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($evidence, 'runtime_gap_matrix.expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($evidence, 'runtime_gap_matrix.runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($evidence, 'runtime_gap_matrix.runtime_promotion_closure_basis_hash', ''),
                'closure_artifact_sequence' => (array) data_get($result, 'closure_artifact_sequence', []),
                'closure_artifact_sequence_count' => (int) data_get($result, 'closure_artifact_sequence_count', 0),
                'closure_artifact_sequence_hash' => (string) data_get($result, 'closure_artifact_sequence_hash', ''),
                'prompt_to_artifact_checklist' => (array) data_get($result, 'prompt_to_artifact_checklist', []),
                'prompt_to_artifact_checklist_count' => (int) data_get($result, 'prompt_to_artifact_checklist_count', 0),
                'prompt_to_artifact_checklist_passed_count' => (int) data_get($result, 'prompt_to_artifact_checklist_passed_count', 0),
                'prompt_to_artifact_checklist_hash' => (string) data_get($result, 'prompt_to_artifact_checklist_hash', ''),
                'blocker_count' => (int) data_get($result, 'blocker_count', 0),
                'human_blocker_count' => count($humanBlockers),
                'human_blockers' => $humanBlockers,
                'real_provider_blocker_count' => count($realProviderBlockers),
                'real_provider_blockers' => $realProviderBlockers,
                'technical_blocker_count' => count($technicalBlockers),
                'technical_blockers' => $technicalBlockers,
                'operator_evidence_readiness_command' => (string) data_get($result, 'commands.operator_evidence_submission_readiness', ''),
                'final_operator_evidence_closure_corridor_command' => (string) data_get($result, 'commands.final_operator_evidence_closure_corridor', ''),
                'terminal_loop_operational_proof_binding_persist_command' => (string) data_get($result, 'commands.persist_terminal_loop_operational_proof_binding', ''),
                'completion_audit_with_canonical_terminal_loop_operational_proof_command' => (string) data_get($result, 'commands.run_completion_audit_with_canonical_terminal_loop_operational_proof', ''),
                'commands' => (array) data_get($result, 'commands', []),
                'command_count' => count((array) data_get($result, 'commands', [])),
                'canonical_submission_private_storage_paths' => (array) data_get($result, 'canonical_submission_private_storage_paths', []),
                'terminal_loop_operational_proof_required_before_final_audit' => (bool) data_get($result, 'terminal_loop_operational_proof_required_before_final_audit', false),
                'terminal_loop_operational_proof_expected_binding_schema' => (string) data_get($result, 'terminal_loop_operational_proof_expected_binding_schema', ''),
                'completion_allowed' => false,
                'self_programming_allowed' => false,
                'completion_claim_allowed' => false,
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
            ],
        );
    }

    public function atlasSelfConstructionFinalEvidenceBundleStatus(array $options = []): array
    {
        $liveStatusProjection = $options === [];
        $options = $this->withTerminalLoopOperationalProofPayload($options);
        $result = (new AtlasSelfConstructionFinalEvidenceBundleService($this->mother))->build($options);
        $blockedCriteria = (array) data_get($result, 'final_operator_packet.what_is_blocked', []);
        $humanBlockers = array_values(array_intersect($blockedCriteria, [
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ]));
        $realProviderBlockers = array_values(array_intersect($blockedCriteria, [
            'end_to_end_real_provider_smoke_green',
        ]));
        $technicalBlockers = array_values(array_diff($blockedCriteria, array_merge($humanBlockers, $realProviderBlockers)));
        $releaseDossierRefreshRequired = in_array('release_dossier_green', $technicalBlockers, true);
        $releaseDossierRefreshCommand = 'php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json';
        $certificationStatusBatchRefreshRequired = in_array('certification_status_batch_green', $technicalBlockers, true);
        $certificationStatusBatchCommand = 'php artisan atlas:ai:self-construction --agent-control-plane-certification-status-batch-status --json';
        $currentRequiredOperatorArtifact = match (true) {
            ! (bool) data_get($result, 'final_readiness_map.runtime_promotion_ready', false) => 'runtime_promotion_receipt',
            ! (bool) data_get($result, 'final_readiness_map.real_provider_smoke_ready', false) => 'real_provider_smoke',
            ! (bool) data_get($result, 'final_readiness_map.human_completion_receipt_ready', false) => 'human_completion_receipt',
            ! (bool) data_get($result, 'final_readiness_map.completion_audit_green', false) => 'completion_audit_with_terminal_loop_binding',
            default => 'none',
        };
        $runtimeGapMatrix = (array) data_get($options, 'runtime_gap_matrix', []);
        if ($runtimeGapMatrix === []) {
            $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother))->matrix();
        }
        if ($liveStatusProjection) {
            $completionEvidence = $this->atlasSelfConstructionOsCompletionEvidenceStatus($options);
            $currentRequiredOperatorArtifact = (string) data_get($completionEvidence, 'current_required_operator_artifact', $currentRequiredOperatorArtifact);
            data_set($result, 'final_operator_packet.next_action_shell_packet.exact_command', (string) data_get($completionEvidence, 'next_required_command', data_get($result, 'final_operator_packet.next_action_shell_packet.exact_command', '')));
            data_set($result, 'final_operator_packet.next_action_shell_packet.persist_command', (string) data_get($completionEvidence, 'next_required_persist_command', data_get($result, 'final_operator_packet.next_action_shell_packet.persist_command', '')));
        }
        $completionClaimAuthorityAliases = $this->completionClaimAuthorityAliases(
            failedCriteria: $blockedCriteria,
            currentRequiredOperatorArtifact: $currentRequiredOperatorArtifact,
        );

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_final_evidence_bundle',
            label: 'Atlas Self-Construction Final Evidence Bundle',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'bundle_hash' => (string) data_get($result, 'bundle_identity.bundle_hash'),
                'missing_component_count' => (int) data_get($result, 'machine_status.missing_component_count', 0),
                'blocker_count' => (int) data_get($result, 'machine_status.blocker_count', 0),
                'next_action' => (string) data_get($result, 'machine_status.next_action', ''),
                'what_is_ready' => (array) data_get($result, 'final_operator_packet.what_is_ready', []),
                'what_is_blocked' => (array) data_get($result, 'final_operator_packet.what_is_blocked', []),
                'what_must_be_signed' => (array) data_get($result, 'final_operator_packet.what_must_be_signed', []),
                'what_must_be_run_with_real_provider' => (array) data_get($result, 'final_operator_packet.what_must_be_run_with_real_provider', []),
                'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
                'next_action_shell_packet' => (array) data_get($result, 'final_operator_packet.next_action_shell_packet', []),
                'next_action_shell_packet_status' => (string) data_get($result, 'final_operator_packet.next_action_shell_packet.status', ''),
                'next_action_shell_packet_hash' => (string) data_get($result, 'final_operator_packet.next_action_shell_packet.shell_packet_hash', ''),
                'next_action_exact_command' => (string) data_get($result, 'final_operator_packet.next_action_shell_packet.exact_command', ''),
                'next_action_persist_command' => (string) data_get($result, 'final_operator_packet.next_action_shell_packet.persist_command', ''),
                'next_required_command' => (string) data_get($result, 'final_operator_packet.next_action_shell_packet.exact_command', ''),
                'next_required_persist_command' => (string) data_get($result, 'final_operator_packet.next_action_shell_packet.persist_command', ''),
                ...$completionClaimAuthorityAliases,
                'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
                'next_action_placeholder_count' => (int) data_get($result, 'final_operator_packet.next_action_shell_packet.placeholder_count', 0),
                'next_action_copy_safe' => (bool) data_get($result, 'final_operator_packet.next_action_shell_packet.copy_safe', false),
                'closure_artifact_sequence' => (array) data_get($result, 'closure_artifact_sequence', []),
                'closure_artifact_sequence_count' => (int) data_get($result, 'closure_artifact_sequence_count', 0),
                'closure_artifact_sequence_hash' => (string) data_get($result, 'closure_artifact_sequence_hash', ''),
                'prompt_to_artifact_checklist' => (array) data_get($result, 'prompt_to_artifact_checklist', []),
                'prompt_to_artifact_checklist_count' => (int) data_get($result, 'prompt_to_artifact_checklist_count', 0),
                'prompt_to_artifact_checklist_passed_count' => (int) data_get($result, 'prompt_to_artifact_checklist_passed_count', 0),
                'prompt_to_artifact_checklist_hash' => (string) data_get($result, 'prompt_to_artifact_checklist_hash', ''),
                'human_blocker_count' => count($humanBlockers),
                'human_blockers' => $humanBlockers,
                'real_provider_blocker_count' => count($realProviderBlockers),
                'real_provider_blockers' => $realProviderBlockers,
                'technical_blocker_count' => count($technicalBlockers),
                'technical_blockers' => $technicalBlockers,
                'release_dossier_refresh_required' => $releaseDossierRefreshRequired,
                'release_dossier_refresh_command' => $releaseDossierRefreshCommand,
                'certification_status_batch_refresh_required' => $certificationStatusBatchRefreshRequired,
                'certification_status_batch_command' => $certificationStatusBatchCommand,
                'runtime_promotion_ready' => (bool) data_get($result, 'final_readiness_map.runtime_promotion_ready', false),
                'real_provider_smoke_ready' => (bool) data_get($result, 'final_readiness_map.real_provider_smoke_ready', false),
                'human_completion_receipt_ready' => (bool) data_get($result, 'final_readiness_map.human_completion_receipt_ready', false),
                'release_dossier_ready' => (bool) data_get($result, 'final_readiness_map.release_dossier_ready', false),
                'terminal_loop_operational_proof_ready' => (bool) data_get($result, 'final_readiness_map.terminal_loop_operational_proof_ready', false),
                'completion_audit_green' => (bool) data_get($result, 'final_readiness_map.completion_audit_green', false),
                'completion_evidence_status_command' => (string) data_get($result, 'final_operator_packet.commands_to_rerun.completion_evidence_status', ''),
                'operator_evidence_readiness_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'capture_snapshot_if_stale_command' => (string) data_get($result, 'final_operator_packet.commands_to_rerun.capture_snapshot_if_stale', ''),
                'completion_audit_command_with_terminal_loop_operational_proof' => (string) data_get($result, 'final_operator_packet.commands_to_rerun.completion_audit_with_terminal_loop_operational_proof', ''),
                'completion_audit_command_with_canonical_terminal_loop_operational_proof' => (string) data_get($result, 'final_operator_packet.commands_to_rerun.completion_audit_with_canonical_terminal_loop_operational_proof', ''),
                'terminal_loop_operational_proof_canonical_binding_path' => (string) data_get($result, 'final_operator_packet.terminal_loop_operational_proof_canonical_binding_path', ''),
                'terminal_loop_operational_proof_command' => (string) data_get($result, 'final_operator_packet.commands_to_rerun.terminal_loop_operational_proof', ''),
                'terminal_loop_operational_proof_binding_export_command' => (string) data_get($result, 'final_operator_packet.commands_to_rerun.terminal_loop_operational_proof_binding_export', ''),
                'final_verification_sequence' => (array) data_get($result, 'final_operator_packet.final_verification_sequence', []),
                'final_verification_sequence_step_count' => count((array) data_get($result, 'final_operator_packet.final_verification_sequence', [])),
                'completion_audit_green_requires_current_snapshot_after_terminal_loop_proof' => (bool) data_get($result, 'final_operator_packet.completion_audit_green_requires_current_snapshot_after_terminal_loop_proof', false),
                'completion_claim_allowed' => (bool) data_get($result, 'machine_status.completion_claim_allowed', false),
                'final_completion_allowed' => (bool) data_get($result, 'final_readiness_map.final_completion_allowed', false),
                'completion_allowed' => false,
                'self_programming_allowed' => false,
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
            ],
        );
    }

    public function atlasSelfConstructionCompletionAuditBlockerExplainerStatus(array $options = []): array
    {
        $explicitAuditProvided = array_key_exists('completion_audit', $options);
        $audit = (array) ($options['completion_audit'] ?? (new AtlasSelfConstructionOsCompletionAuditService($this->mother))->audit($options));
        $result = (new AtlasSelfConstructionCompletionAuditBlockerExplainerService)->build($audit);
        $runtimeGapMatrix = (array) data_get($options, 'runtime_gap_matrix', []);
        if ($runtimeGapMatrix === []) {
            $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother))->matrix();
        }
        $failedCriteria = (array) data_get($audit, 'failed_criteria', []);
        $currentRequiredOperatorArtifact = match (true) {
            in_array('runtime_gap_matrix_all_runtime_y', $failedCriteria, true) => 'runtime_promotion_receipt',
            in_array('end_to_end_real_provider_smoke_green', $failedCriteria, true) => 'real_provider_smoke',
            in_array('human_signed_os_complete_receipt_present', $failedCriteria, true) => 'human_completion_receipt',
            default => 'none',
        };
        $nextRequiredCommand = match ($currentRequiredOperatorArtifact) {
            'runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --json',
            'human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            default => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
        };
        $nextRequiredPersistCommand = match ($currentRequiredOperatorArtifact) {
            'runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            'real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            'human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            default => '',
        };
        if (! $explicitAuditProvided) {
            $completionEvidence = $this->atlasSelfConstructionOsCompletionEvidenceStatus($options);
            $currentRequiredOperatorArtifact = (string) data_get($completionEvidence, 'current_required_operator_artifact', $currentRequiredOperatorArtifact);
            $nextRequiredCommand = (string) data_get($completionEvidence, 'next_required_command', $nextRequiredCommand);
            $nextRequiredPersistCommand = (string) data_get($completionEvidence, 'next_required_persist_command', $nextRequiredPersistCommand);
        }
        $completionClaimAuthorityAliases = $this->completionClaimAuthorityAliases(
            failedCriteria: $failedCriteria,
            currentRequiredOperatorArtifact: $currentRequiredOperatorArtifact,
        );

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_completion_audit_blocker_explainer',
            label: 'Atlas Self-Construction Completion Audit Blocker Explainer',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'explainer_hash' => (string) data_get($result, 'explainer_hash'),
                'remaining_blocker_count' => (int) data_get($result, 'remaining_blocker_count', 0),
                'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
                'next_required_command' => $nextRequiredCommand,
                'next_required_persist_command' => $nextRequiredPersistCommand,
                ...$completionClaimAuthorityAliases,
                'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
                'closure_artifact_sequence' => (array) data_get($result, 'closure_artifact_sequence', []),
                'closure_artifact_sequence_count' => (int) data_get($result, 'closure_artifact_sequence_count', 0),
                'closure_artifact_sequence_hash' => (string) data_get($result, 'closure_artifact_sequence_hash', ''),
                'prompt_to_artifact_checklist' => (array) data_get($result, 'prompt_to_artifact_checklist', []),
                'prompt_to_artifact_checklist_count' => (int) data_get($result, 'prompt_to_artifact_checklist_count', 0),
                'prompt_to_artifact_checklist_passed_count' => (int) data_get($result, 'prompt_to_artifact_checklist_passed_count', 0),
                'prompt_to_artifact_checklist_hash' => (string) data_get($result, 'prompt_to_artifact_checklist_hash', ''),
                'human_required' => (bool) data_get($result, 'machine_status.human_required', false),
                'real_provider_required' => (bool) data_get($result, 'machine_status.real_provider_required', false),
                'can_close_automatically' => (bool) data_get($result, 'machine_status.can_close_automatically', false),
                'terminal_loop_operational_proof_command' => (string) data_get($result, 'command_plan.terminal_loop_operational_proof', ''),
                'terminal_loop_operational_proof_binding_persist_command' => (string) data_get($result, 'command_plan.persist_terminal_loop_operational_proof_binding', ''),
                'terminal_loop_operational_proof_canonical_binding_path' => (string) data_get($result, 'command_plan.terminal_loop_operational_proof_canonical_binding_path', ''),
                'completion_audit_command_with_terminal_loop_operational_proof' => (string) data_get($result, 'command_plan.completion_audit_with_terminal_loop_operational_proof', ''),
                'effective_completion_audit_with_canonical_terminal_loop_operational_proof_command' => (string) data_get($result, 'command_plan.effective_completion_audit_with_canonical_terminal_loop_operational_proof', ''),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
                'self_programming_allowed' => false,
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
            ],
        );
    }

    public function atlasSelfConstructionCompletionEvidenceSubmissionPreflightStatus(array $options = []): array
    {
        $options = $this->withTerminalLoopOperationalProofPayload($options);
        $audit = (new AtlasSelfConstructionOsCompletionAuditService($this->mother))->audit($options);
        $completionEvidence = $this->atlasSelfConstructionOsCompletionEvidenceStatus($options);
        $blockerExplainer = (new AtlasSelfConstructionCompletionAuditBlockerExplainerService)->build($audit);
        $result = (new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService)->build($audit, $completionEvidence, $blockerExplainer);
        $runtimeGapMatrix = (array) data_get($options, 'runtime_gap_matrix', []);
        if ($runtimeGapMatrix === []) {
            $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother))->matrix();
        }
        $currentCompletionBlockersClassified = (array) data_get($result, 'operator_handoff_packet.current_completion_blockers_classified', []);
        if ($currentCompletionBlockersClassified === []) {
            $currentCompletionBlockersClassified = array_values(array_map(
                static fn (mixed $criterion): array => [
                    'id' => (string) $criterion,
                    'requirement' => (string) $criterion,
                    'blocker_type' => match ((string) $criterion) {
                        'runtime_gap_matrix_all_runtime_y', 'human_signed_os_complete_receipt_present' => 'human',
                        'end_to_end_real_provider_smoke_green' => 'real_provider',
                        default => 'technical',
                    },
                ],
                (array) data_get($result, 'operator_handoff_packet.current_blocks_completion_criteria', []),
            ));
        }

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_completion_evidence_submission_preflight',
            label: 'Atlas Self-Construction Completion Evidence Submission Preflight',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'submission_preflight_hash' => (string) data_get($result, 'submission_preflight_hash'),
                'step_count' => (int) data_get($result, 'step_count', 0),
                'ready_step_count' => (int) data_get($result, 'ready_step_count', 0),
                'blocked_step_count' => (int) data_get($result, 'blocked_step_count', 0),
                'completion_audit_failed_count' => (int) data_get($result, 'completion_audit_blocker_summary.failed_count', 0),
                'completion_audit_technical_blocker_count' => (int) data_get($result, 'completion_audit_blocker_summary.technical_blocker_count', 0),
                'completion_audit_human_blocker_count' => (int) data_get($result, 'completion_audit_blocker_summary.human_blocker_count', 0),
                'completion_audit_real_provider_blocker_count' => (int) data_get($result, 'completion_audit_blocker_summary.real_provider_blocker_count', 0),
                'completion_audit_failed_criteria' => array_values(array_map('strval', array_keys((array) data_get($result, 'completion_audit_blocker_summary.blockers_by_id', [])))),
                'terminal_loop_operational_proof_supplied_to_completion_audit' => isset($options['agent_control_plane_terminal_loop_operational_proof']),
                'terminal_loop_operational_proof_source' => (string) ($options['agent_control_plane_terminal_loop_operational_proof_source'] ?? (isset($options['agent_control_plane_terminal_loop_operational_proof_json']) ? 'explicit_json_option' : '')),
                'terminal_loop_operational_proof_canonical_path' => (string) ($options['agent_control_plane_terminal_loop_operational_proof_canonical_path'] ?? ''),
                'next_required_submission' => (string) data_get($result, 'next_required_submission'),
                'current_required_operator_artifact' => (string) data_get($result, 'next_required_submission'),
                'current_required_operator_inputs' => (array) data_get($result, 'operator_handoff_packet.required_operator_inputs', []),
                'current_required_operator_input_count' => count((array) data_get($result, 'operator_handoff_packet.required_operator_inputs', [])),
                'current_step_stop_conditions' => (array) data_get($result, 'operator_handoff_packet.handoff_stop_conditions', []),
                'current_step_stop_condition_count' => count((array) data_get($result, 'operator_handoff_packet.handoff_stop_conditions', [])),
                'current_completion_blockers_classified' => $currentCompletionBlockersClassified,
                'current_completion_blocker_classification_count' => count($currentCompletionBlockersClassified),
                'next_required_command' => (string) data_get($result, 'next_required_command', ''),
                'next_required_persist_command' => (string) data_get($result, 'next_required_persist_command', ''),
                'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
                'resumption_checkpoint_hash' => (string) data_get($result, 'operator_resumption_checkpoint.resumption_checkpoint_hash'),
                'resumption_checkpoint_current_step' => (string) data_get($result, 'operator_resumption_checkpoint.current_step'),
                'resumption_checkpoint_can_resume_without_chat_history' => (bool) data_get($result, 'operator_resumption_checkpoint.can_resume_without_chat_history', false),
                'resumption_checkpoint_requires_fresh_preflight_before_persist' => (bool) data_get($result, 'operator_resumption_checkpoint.requires_fresh_preflight_before_persist', false),
                'operator_closure_command_replay_status' => (string) data_get($result, 'operator_closure_command_replay.status', ''),
                'operator_closure_command_replay_hash' => (string) data_get($result, 'operator_closure_command_replay.command_replay_hash', ''),
                'operator_closure_command_replay_current_step' => (string) data_get($result, 'operator_closure_command_replay.current_step', ''),
                'operator_closure_command_replay_step_count' => (int) data_get($result, 'operator_closure_command_replay.replay_step_count', 0),
                'operator_closure_command_replay_effective_completion_audit_command' => (string) data_get($result, 'operator_closure_command_replay.proof_commands_after_each_persist.effective_completion_audit', ''),
                'operator_execution_plan_effective_final_success_command' => (string) data_get($result, 'operator_execution_plan.effective_final_success_command', ''),
                'operator_handoff_packet_effective_final_success_command' => (string) data_get($result, 'operator_handoff_packet.effective_final_success_command', ''),
                'operator_resumption_checkpoint_effective_refresh_completion_audit_command' => (string) data_get($result, 'operator_resumption_checkpoint.resume_commands.effective_refresh_completion_audit', ''),
                'terminal_loop_closure_proof_status' => (string) data_get($result, 'terminal_loop_closure_proof.status', ''),
                'terminal_loop_closure_proof_required_before_final_receipt' => (bool) data_get($result, 'terminal_loop_closure_proof.required_before_final_completion_receipt', false),
                'terminal_loop_closure_proof_binding_persist_command' => (string) data_get($result, 'terminal_loop_closure_proof.proof_binding_persist_command', ''),
                'terminal_loop_closure_proof_canonical_binding_path' => (string) data_get($result, 'terminal_loop_closure_proof.expected_binding_artifact_path', ''),
                'terminal_loop_closure_proof_audit_command_with_canonical_binding' => (string) data_get($result, 'terminal_loop_closure_proof.audit_command_with_canonical_binding', ''),
                'terminal_loop_closure_proof_effective_audit_command_with_binding' => (string) data_get($result, 'terminal_loop_closure_proof.effective_audit_command_with_binding', ''),
                'terminal_loop_closure_proof_packet_hash' => (string) data_get($result, 'terminal_loop_closure_proof.terminal_loop_closure_proof_packet_hash', ''),
                'terminal_loop_closure_proof_required_end_to_end_contract_capability_count' => count((array) data_get($result, 'terminal_loop_closure_proof.required_end_to_end_contract_capabilities', [])),
                'terminal_loop_closure_proof_required_end_to_end_contract_capabilities' => (array) data_get($result, 'terminal_loop_closure_proof.required_end_to_end_contract_capabilities', []),
                'operator_command_surface_status' => (string) data_get($result, 'operator_command_surface.status', ''),
                'operator_command_surface_hash' => (string) data_get($result, 'operator_command_surface.command_surface_hash', ''),
                'operator_command_count' => (int) data_get($result, 'operator_command_surface.command_count', 0),
                'operator_command_missing_option_count' => (int) data_get($result, 'operator_command_surface.missing_option_count', 0),
                'operator_command_legacy_alias_count' => (int) data_get($result, 'operator_command_surface.legacy_alias_count', 0),
                'closure_artifact_sequence' => (array) data_get($result, 'closure_artifact_sequence', []),
                'closure_artifact_sequence_count' => (int) data_get($result, 'closure_artifact_sequence_count', 0),
                'closure_artifact_sequence_hash' => (string) data_get($result, 'closure_artifact_sequence_hash', ''),
                'prompt_to_artifact_checklist' => (array) data_get($result, 'prompt_to_artifact_checklist', []),
                'prompt_to_artifact_checklist_count' => (int) data_get($result, 'prompt_to_artifact_checklist_count', 0),
                'prompt_to_artifact_checklist_passed_count' => (int) data_get($result, 'prompt_to_artifact_checklist_passed_count', 0),
                'prompt_to_artifact_checklist_hash' => (string) data_get($result, 'prompt_to_artifact_checklist_hash', ''),
                'pre_persist_guardrail_sequence' => (array) data_get($result, 'pre_persist_guardrail_sequence', []),
                'pre_persist_guardrail_count' => (int) data_get($result, 'pre_persist_guardrail_count', 0),
                'pre_persist_guardrail_hash' => (string) data_get($result, 'pre_persist_guardrail_hash', ''),
                'operator_required' => (bool) data_get($result, 'operator_required', false),
                'real_provider_required' => (bool) data_get($result, 'real_provider_required', false),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
                'self_programming_allowed' => false,
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
            ],
        );
    }

}
