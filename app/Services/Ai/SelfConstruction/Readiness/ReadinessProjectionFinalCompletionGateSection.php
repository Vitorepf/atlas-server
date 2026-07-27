<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionFinalizationGateService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalCompletionDossierExporterService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalCompletionHumanGateService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalCompletionReadinessGateService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\Support\AtlasSelfProgrammingSafetyContractCertificationService;

/**
 * GOD-DEBULK extracted stateful final-completion-gate family from AtlasSelfConstructionReadinessService (human gate, dossier exporter, readiness gate, self-programming safety certification, finalization gate).
 * Bound via setMother(); undefined method calls bridge through __call and undefined
 * property reads bridge through __get (ReflectionMethod / ReflectionProperty on the mother)
 * so the moved bodies stay byte-identical to the god service originals.
 */
final class ReadinessProjectionFinalCompletionGateSection
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
            throw new \RuntimeException('ReadinessProjectionFinalCompletionGateSection mother not bound for '.$name);
        }
        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }

    public function __get(string $name): mixed
    {
        $property = new \ReflectionProperty($this->mother, $name);

        return $property->getValue($this->mother);
    }


    public function atlasSelfConstructionFinalCompletionHumanGateStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionFinalCompletionHumanGateService($this->mother))->build([
            'completion_receipt' => (array) ($options['completion_receipt'] ?? $this->decodeJsonOption($options['completion_receipt_json'] ?? null)),
        ]);
        $runtimeGapMatrix = (array) data_get($options, 'runtime_gap_matrix', []);
        if ($runtimeGapMatrix === []) {
            $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother))->matrix();
        }
        $currentRequiredOperatorArtifact = match ((string) data_get($result, 'status', '')) {
            'blocked_runtime_promotion_required' => 'runtime_promotion_receipt',
            'blocked_real_provider_smoke_required' => 'real_provider_smoke',
            'blocked_missing_operator_receipt' => 'human_completion_receipt',
            default => match ((string) data_get($result, 'blocker_id', '')) {
                'runtime_gap_matrix_all_runtime_y' => 'runtime_promotion_receipt',
                'end_to_end_real_provider_smoke_green' => 'real_provider_smoke',
                'human_signed_os_complete_receipt_present' => 'human_completion_receipt',
                default => 'none',
            },
        };
        $nextRequiredCommand = match ($currentRequiredOperatorArtifact) {
            'runtime_promotion_receipt' => (string) data_get($result, 'exact_commands.draft_runtime_promotion_receipt', ''),
            'real_provider_smoke' => (string) data_get($result, 'exact_commands.draft_real_provider_smoke', ''),
            'human_completion_receipt' => (string) data_get($result, 'exact_commands.draft_human_completion_receipt', ''),
            default => (string) data_get($result, 'exact_commands.final_completion_readiness_gate_status', ''),
        };
        $nextRequiredPersistCommand = match ($currentRequiredOperatorArtifact) {
            'runtime_promotion_receipt' => (string) data_get($result, 'exact_commands.persist_runtime_promotion_receipt', ''),
            'real_provider_smoke' => (string) data_get($result, 'exact_commands.persist_real_provider_smoke', ''),
            'human_completion_receipt' => (string) data_get($result, 'exact_commands.persist_human_completion_receipt', ''),
            default => '',
        };

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_final_completion_human_gate',
            label: 'Atlas Self-Construction Final Completion Human Gate',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'final_completion_human_gate_status' => (string) data_get($result, 'status', ''),
                'human_gate_hash' => (string) data_get($result, 'human_gate_hash', ''),
                'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
                'next_required_command' => $nextRequiredCommand,
                'next_required_persist_command' => $nextRequiredPersistCommand,
                'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
                'human_receipt_verification_status' => (string) data_get($result, 'human_receipt_verification.status', ''),
                'human_receipt_verification_diagnostic_count' => (int) data_get($result, 'human_receipt_verification.diagnostic_count', 0),
                'human_receipt_verification_diagnostic_codes' => (array) data_get($result, 'human_receipt_verification.diagnostic_codes', []),
                'human_receipt_verification_can_persist' => (bool) data_get($result, 'human_receipt_verification.can_persist', false),
                'human_receipt_verification_persistence_blocker' => (string) data_get($result, 'human_receipt_verification.persistence_blocker', ''),
                'persistence_preflight_can_persist' => (bool) data_get($result, 'persistence_preflight.can_persist', false),
                'persistence_preflight_blockers' => (array) data_get($result, 'persistence_preflight.blockers', []),
                'persistence_preflight_blocker_count' => (int) data_get($result, 'persistence_preflight.blocker_count', 0),
                'persistence_preflight_persistence_blocker' => (string) data_get($result, 'persistence_preflight.persistence_blocker', ''),
                'terminal_loop_operational_proof_required_before_final_audit' => (bool) data_get($result, 'terminal_loop_operational_proof_required_before_final_audit', false),
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
                'terminal_loop_operational_proof_command' => (string) data_get($result, 'persistence_preflight.terminal_loop_operational_proof_command', ''),
                'terminal_loop_operational_proof_binding_persist_command' => (string) data_get($result, 'persistence_preflight.terminal_loop_operational_proof_binding_persist_command', ''),
                'rerun_audit_with_terminal_loop_operational_proof_command' => (string) data_get($result, 'persistence_preflight.rerun_audit_with_terminal_loop_operational_proof_command', ''),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'execution_allowed' => (bool) data_get($result, 'execution_allowed', false),
                'dispatch_allowed' => (bool) data_get($result, 'dispatch_allowed', false),
                'provider_call_allowed' => (bool) data_get($result, 'provider_call_allowed', false),
                'token_spend_allowed' => (bool) data_get($result, 'token_spend_allowed', false),
                'adapter_execution_allowed' => (bool) data_get($result, 'adapter_execution_allowed', false),
                'self_programming_allowed' => (bool) data_get($result, 'self_programming_allowed', false),
            ],
        );
    }

    public function atlasSelfConstructionFinalCompletionDossierExporterStatus(array $options = []): array
    {
        $liveStatusProjection = $options === [];
        $buildOptions = [
            'persist_export' => (bool) ($options['persist_export'] ?? false),
        ];
        foreach (['final_completion_human_gate', 'completion_audit', 'completion_evidence', 'completion_receipt', 'runtime_promotion_receipt', 'real_provider_smoke'] as $key) {
            if (isset($options[$key]) && is_array($options[$key]) && $options[$key] !== []) {
                $buildOptions[$key] = $options[$key];
            }
        }

        $result = (new AtlasSelfConstructionFinalCompletionDossierExporterService($this->mother))->build($buildOptions);
        $runtimeGapMatrix = (array) data_get($options, 'runtime_gap_matrix', []);
        if ($runtimeGapMatrix === []) {
            $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother))->matrix();
        }
        $failedBlockers = (array) data_get($result, 'failed_blockers', []);
        $currentRequiredOperatorArtifact = match (true) {
            in_array('runtime_gap_matrix_all_runtime_y', $failedBlockers, true) => 'runtime_promotion_receipt',
            in_array('end_to_end_real_provider_smoke_green', $failedBlockers, true) => 'real_provider_smoke',
            in_array('human_signed_os_complete_receipt_present', $failedBlockers, true) => 'human_completion_receipt',
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
        if ($liveStatusProjection) {
            $completionEvidence = $this->atlasSelfConstructionOsCompletionEvidenceStatus($options);
            $currentRequiredOperatorArtifact = (string) data_get($completionEvidence, 'current_required_operator_artifact', $currentRequiredOperatorArtifact);
            $nextRequiredCommand = (string) data_get($completionEvidence, 'next_required_command', $nextRequiredCommand);
            $nextRequiredPersistCommand = (string) data_get($completionEvidence, 'next_required_persist_command', $nextRequiredPersistCommand);
        }
        $completionClaimAuthorityAliases = $this->completionClaimAuthorityAliases(
            failedCriteria: $failedBlockers,
            currentRequiredOperatorArtifact: $currentRequiredOperatorArtifact,
        );

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_final_completion_dossier_exporter',
            label: 'Atlas Self-Construction Final Completion Dossier Exporter',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'final_audit_status' => (string) data_get($result, 'final_audit_status', ''),
                'final_audit_complete' => (bool) data_get($result, 'final_audit_complete', false),
                'final_completion_human_gate_status' => (string) data_get($result, 'final_completion_human_gate_status', ''),
                'failed_blockers' => $failedBlockers,
                'failed_blocker_count' => count($failedBlockers),
                'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
                'next_required_command' => $nextRequiredCommand,
                'next_required_persist_command' => $nextRequiredPersistCommand,
                ...$completionClaimAuthorityAliases,
                'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
                'next_commands' => (array) data_get($result, 'next_commands', []),
                'next_command_count' => count((array) data_get($result, 'next_commands', [])),
                'markdown_byte_size' => (int) data_get($result, 'markdown_byte_size', 0),
                'export_persisted' => (bool) data_get($result, 'export_persisted', false),
                'export_path' => (string) data_get($result, 'export_path', ''),
                'export_persistence_blocker' => (string) data_get($result, 'export_persistence_blocker', ''),
                'exporter_hash' => (string) data_get($result, 'exporter_hash', ''),
                'dossier_hash' => (string) data_get($result, 'exporter_hash', ''),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
                'self_programming_allowed' => false,
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
            ],
        );
    }

    public function atlasSelfConstructionFinalCompletionReadinessGateStatus(array $options = []): array
    {
        $liveStatusProjection = $options === [];
        $options = $this->withTerminalLoopOperationalProofPayload($options);

        $result = (new AtlasSelfConstructionFinalCompletionReadinessGateService($this->mother))->evaluate($options);
        $failedCriteria = (array) data_get($result, 'completion_audit_failed_criteria', []);
        $humanBlockers = array_values(array_intersect($failedCriteria, [
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ]));
        $realProviderBlockers = array_values(array_intersect($failedCriteria, [
            'end_to_end_real_provider_smoke_green',
        ]));
        $technicalBlockers = array_values(array_diff($failedCriteria, array_merge($humanBlockers, $realProviderBlockers)));
        $releaseDossierRefreshRequired = in_array('release_dossier_green', $technicalBlockers, true);
        $releaseDossierRefreshCommand = 'php artisan atlas:ai:self-construction --agent-control-plane-replay-snapshot-store-capture --json';
        $certificationStatusBatchRefreshRequired = in_array('certification_status_batch_green', $technicalBlockers, true);
        $certificationStatusBatchCommand = 'php artisan atlas:ai:self-construction --agent-control-plane-certification-status-batch-status --json';
        $currentRequiredOperatorArtifact = match (true) {
            in_array('runtime_gap_matrix_all_runtime_y', $failedCriteria, true) => 'runtime_promotion_receipt',
            in_array('end_to_end_real_provider_smoke_green', $failedCriteria, true) => 'real_provider_smoke',
            in_array('human_signed_os_complete_receipt_present', $failedCriteria, true) => 'human_completion_receipt',
            default => 'none',
        };
        $runtimeGapMatrix = (array) data_get($options, 'runtime_gap_matrix', []);
        if ($runtimeGapMatrix === []) {
            $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother))->matrix();
        }
        $nextRequiredCommand = match ($currentRequiredOperatorArtifact) {
            'runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --json',
            'human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            default => (string) data_get($result, 'command_to_rerun_audit_with_terminal_loop_operational_proof', ''),
        };
        $nextRequiredPersistCommand = match ($currentRequiredOperatorArtifact) {
            'runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json',
            'real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            'human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            default => '',
        };
        if ($liveStatusProjection) {
            $completionEvidence = $this->atlasSelfConstructionOsCompletionEvidenceStatus($options);
            $currentRequiredOperatorArtifact = (string) data_get($completionEvidence, 'current_required_operator_artifact', $currentRequiredOperatorArtifact);
            $nextRequiredCommand = (string) data_get($completionEvidence, 'next_required_command', $nextRequiredCommand);
            $nextRequiredPersistCommand = (string) data_get($completionEvidence, 'next_required_persist_command', $nextRequiredPersistCommand);
        }

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_final_completion_readiness_gate',
            label: 'Atlas Self-Construction Final Completion Readiness Gate',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'completion_allowed' => (bool) data_get($result, 'completion_allowed', false),
                'completion_claim_allowed' => (bool) data_get($result, 'completion_claim_allowed', false),
                'next_stage_allowed' => (bool) data_get($result, 'next_stage_allowed', false),
                'failed_criteria' => $failedCriteria,
                'failed_count' => count($failedCriteria),
                'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
                'next_required_artifact' => $currentRequiredOperatorArtifact,
                'next_required_command' => $nextRequiredCommand,
                'next_required_persist_command' => $nextRequiredPersistCommand,
                'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
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
                'next_stage_blocked_by' => (array) data_get($result, 'next_stage_blocked_by', []),
                'terminal_loop_operational_proof_required_before_completion_claim' => (bool) data_get($result, 'terminal_loop_operational_proof_required_before_completion_claim', false),
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
                'terminal_loop_operational_proof_expected_binding_schema' => (string) data_get($result, 'terminal_loop_operational_proof_expected_binding_schema', ''),
                'terminal_loop_operational_proof_green' => (bool) data_get($result, 'terminal_loop_operational_proof_green', false),
                'terminal_loop_operational_proof_status' => (string) data_get($result, 'terminal_loop_operational_proof_evidence.status', ''),
                'terminal_loop_operational_proof_hash' => (string) data_get($result, 'terminal_loop_operational_proof_evidence.proof_hash', ''),
                'terminal_loop_operational_proof_supplied_to_final_readiness_gate' => isset($options['agent_control_plane_terminal_loop_operational_proof']),
                'terminal_loop_operational_proof_source' => (string) ($options['agent_control_plane_terminal_loop_operational_proof_source'] ?? (isset($options['agent_control_plane_terminal_loop_operational_proof_json']) ? 'explicit_json_option' : '')),
                'command_to_refresh_terminal_loop_operational_proof' => (string) data_get($result, 'command_to_refresh_terminal_loop_operational_proof', ''),
                'command_to_persist_terminal_loop_operational_proof_binding' => (string) data_get($result, 'command_to_persist_terminal_loop_operational_proof_binding', ''),
                'command_to_capture_snapshot_after_terminal_loop_operational_proof' => (string) data_get($result, 'command_to_capture_snapshot_after_terminal_loop_operational_proof', ''),
                'command_to_rerun_audit_with_terminal_loop_operational_proof' => (string) data_get($result, 'command_to_rerun_audit_with_terminal_loop_operational_proof', ''),
                'terminal_loop_operational_proof_json_reference' => (string) data_get($result, 'terminal_loop_operational_proof_json_reference', ''),
                'final_verification_sequence' => (array) data_get($result, 'final_verification_sequence', []),
                'final_verification_sequence_step_count' => count((array) data_get($result, 'final_verification_sequence', [])),
                'completion_audit_green_requires_current_snapshot_after_terminal_loop_proof' => (bool) data_get($result, 'completion_audit_green_requires_current_snapshot_after_terminal_loop_proof', false),
                'closure_artifact_sequence' => (array) data_get($result, 'closure_artifact_sequence', []),
                'closure_artifact_sequence_count' => (int) data_get($result, 'closure_artifact_sequence_count', 0),
                'closure_artifact_sequence_hash' => (string) data_get($result, 'closure_artifact_sequence_hash', ''),
                'prompt_to_artifact_checklist' => (array) data_get($result, 'prompt_to_artifact_checklist', []),
                'prompt_to_artifact_checklist_count' => (int) data_get($result, 'prompt_to_artifact_checklist_count', 0),
                'prompt_to_artifact_checklist_passed_count' => (int) data_get($result, 'prompt_to_artifact_checklist_passed_count', 0),
                'prompt_to_artifact_checklist_hash' => (string) data_get($result, 'prompt_to_artifact_checklist_hash', ''),
                'self_programming_os_transition_status' => (string) data_get($result, 'self_programming_os_transition_status', ''),
                'self_programming_os_transition_blockers' => (array) data_get($result, 'self_programming_os_transition_blockers', []),
                'transition_status' => (string) data_get($result, 'transition_status', ''),
                'transition_blockers' => (array) data_get($result, 'transition_blockers', []),
                'self_programming_safety_contract_hash' => (string) data_get($result, 'self_programming_safety_contract_hash', ''),
                'self_programming_runtime_activation_allowed' => (bool) data_get($result, 'self_programming_os_transition_readiness.runtime_activation_allowed', false),
                'self_programming_allowed' => (bool) data_get($result, 'self_programming_os_transition_readiness.self_programming_allowed', false),
            ],
        );
    }

    public function atlasSelfProgrammingSafetyContractCertificationStatus(array $options = []): array
    {
        $options = $this->withTerminalLoopOperationalProofPayload($options);

        $payload = (new AtlasSelfProgrammingSafetyContractCertificationService($this->mother))->certify($options);
        $operatorOnlyFailedCriteria = (array) data_get($payload, 'worker_task_eligibility_operator_only_failed_criteria', []);
        $operatorOnlyHumanBlockers = array_values(array_intersect($operatorOnlyFailedCriteria, [
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ]));
        $operatorOnlyRealProviderBlockers = array_values(array_intersect($operatorOnlyFailedCriteria, [
            'end_to_end_real_provider_smoke_green',
        ]));

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_programming_safety_contract_certification',
            label: 'Atlas Self-Programming Safety Contract Certification',
            payload: $payload,
            statusKey: 'status',
            extraStatusFields: [
                'transition_status' => (string) data_get($payload, 'transition_status', ''),
                'finalization_gate_status' => (string) data_get($payload, 'finalization_gate_status', ''),
                'finalization_gate_evaluation_mode' => (string) data_get($payload, 'finalization_gate_evaluation_mode', ''),
                'finalization_gate_hash' => (string) data_get($payload, 'finalization_gate_hash', ''),
                'finalization_gate_terminal_loop_green' => (bool) data_get($payload, 'finalization_gate_terminal_loop_green', false),
                'finalization_gate_terminal_loop_required_before_completion_claim' => (bool) data_get($payload, 'finalization_gate_terminal_loop_required_before_completion_claim', false),
                'finalization_gate_completion_claim_allowed' => (bool) data_get($payload, 'finalization_gate_completion_claim_allowed', false),
                'finalization_gate_next_stage_allowed' => (bool) data_get($payload, 'finalization_gate_next_stage_allowed', false),
                'finalization_gate_next_stage_blockers' => (array) data_get($payload, 'finalization_gate_next_stage_blockers', []),
                'finalization_gate_operator_handoff_status' => (string) data_get($payload, 'finalization_gate_operator_handoff_status', ''),
                'finalization_gate_operator_handoff_hash' => (string) data_get($payload, 'finalization_gate_operator_handoff_hash', ''),
                'finalization_gate_current_required_operator_artifact' => (string) data_get($payload, 'finalization_gate_current_required_operator_artifact', ''),
                'finalization_gate_operator_evidence_readiness_command' => (string) data_get($payload, 'finalization_gate_operator_evidence_readiness_command', ''),
                'finalization_gate_final_verification_sequence_count' => (int) data_get($payload, 'finalization_gate_final_verification_sequence_count', 0),
                'terminal_loop_operational_proof_json_reference' => (string) data_get($payload, 'terminal_loop_operational_proof_json_reference', ''),
                'terminal_loop_operational_proof_supplied_to_safety_certification' => isset($options['agent_control_plane_terminal_loop_operational_proof']),
                'terminal_loop_operational_proof_source' => (string) ($options['agent_control_plane_terminal_loop_operational_proof_source'] ?? (isset($options['agent_control_plane_terminal_loop_operational_proof_json']) ? 'explicit_json_option' : '')),
                'live_finalization_gate_available' => (bool) data_get($payload, 'live_finalization_gate_available', false),
                'live_finalization_gate_status' => (string) data_get($payload, 'live_finalization_gate_status', ''),
                'live_finalization_gate_hash' => (string) data_get($payload, 'live_finalization_gate_hash', ''),
                'live_finalization_gate_terminal_loop_green' => (bool) data_get($payload, 'live_finalization_gate_terminal_loop_green', false),
                'live_finalization_gate_terminal_loop_proof_status' => (string) data_get($payload, 'live_finalization_gate_terminal_loop_proof_status', ''),
                'live_finalization_gate_terminal_loop_proof_hash' => (string) data_get($payload, 'live_finalization_gate_terminal_loop_proof_hash', ''),
                'live_finalization_gate_failed_check_ids' => (array) data_get($payload, 'live_finalization_gate_failed_check_ids', []),
                'live_finalization_gate_completion_audit_failed_criteria' => (array) data_get($payload, 'live_finalization_gate_completion_audit_failed_criteria', []),
                'live_finalization_gate_command' => (string) data_get($payload, 'live_finalization_gate_command', ''),
                'live_finalization_gate_audit_with_binding_command' => (string) data_get($payload, 'live_finalization_gate_audit_with_binding_command', ''),
                'live_finalization_gate_operator_evidence_readiness_command' => (string) data_get($payload, 'live_finalization_gate_operator_evidence_readiness_command', ''),
                'live_finalization_gate_final_verification_sequence_count' => (int) data_get($payload, 'live_finalization_gate_final_verification_sequence_count', 0),
                'worker_task_eligibility_status' => (string) data_get($payload, 'worker_task_eligibility_status', ''),
                'worker_task_eligibility_certification_hash' => (string) data_get($payload, 'worker_task_eligibility_certification_hash', ''),
                'worker_task_eligibility_violation_count' => (int) data_get($payload, 'worker_task_eligibility_violation_count', 0),
                'worker_task_eligibility_operator_only_failed_criteria' => (array) data_get($payload, 'worker_task_eligibility_operator_only_failed_criteria', []),
                'worker_task_eligibility_missing_operator_handoff_criteria' => (array) data_get($payload, 'worker_task_eligibility_missing_operator_handoff_criteria', []),
                'worker_task_eligibility_operator_handoff_seed_count' => (int) data_get($payload, 'worker_task_eligibility_operator_handoff_seed_count', 0),
                'next_stage_operator_only_blocker_count' => count($operatorOnlyFailedCriteria),
                'next_stage_operator_only_human_blocker_count' => count($operatorOnlyHumanBlockers),
                'next_stage_operator_only_human_blockers' => $operatorOnlyHumanBlockers,
                'next_stage_operator_only_real_provider_blocker_count' => count($operatorOnlyRealProviderBlockers),
                'next_stage_operator_only_real_provider_blockers' => $operatorOnlyRealProviderBlockers,
                'next_stage_current_required_closure_artifact' => (string) data_get($payload, 'finalization_gate_current_required_operator_artifact', ''),
                'next_stage_terminal_loop_binding_green' => (bool) data_get($payload, 'live_finalization_gate_terminal_loop_green', data_get($payload, 'self_programming_bootstrap_plan.finalization_gate_terminal_loop_green', false)),
                'next_stage_first_self_programming_task_allowed' => false,
                'self_programming_bootstrap_plan_status' => (string) data_get($payload, 'self_programming_bootstrap_plan_status', ''),
                'self_programming_bootstrap_plan_phase_count' => (int) data_get($payload, 'self_programming_bootstrap_plan_phase_count', 0),
                'self_programming_bootstrap_plan_hash' => (string) data_get($payload, 'self_programming_bootstrap_plan_hash', ''),
                'external_completion_claim_policy_status' => (string) data_get($payload, 'external_completion_claim_policy_status', data_get($payload, 'external_completion_claim_policy.status', '')),
                'external_completion_claim_policy_completion_authority' => (string) data_get($payload, 'external_completion_claim_policy_completion_authority', data_get($payload, 'external_completion_claim_policy.completion_authority', '')),
                'external_completion_claim_policy_required_completion_predicate' => (string) data_get($payload, 'external_completion_claim_policy_required_completion_predicate', data_get($payload, 'external_completion_claim_policy.required_completion_predicate', '')),
                'external_completion_claim_policy_external_agent_claim_accepted' => (bool) data_get($payload, 'external_completion_claim_policy_external_agent_claim_accepted', data_get($payload, 'external_completion_claim_policy.external_agent_claim_accepted', true)),
                'external_completion_claim_policy_external_agent_claim_can_mark_os_complete' => (bool) data_get($payload, 'external_completion_claim_policy_external_agent_claim_can_mark_os_complete', data_get($payload, 'external_completion_claim_policy.external_agent_claim_can_mark_os_complete', true)),
                'external_completion_claim_policy_external_agent_claim_can_override_audit' => (bool) data_get($payload, 'external_completion_claim_policy_external_agent_claim_can_override_audit', data_get($payload, 'external_completion_claim_policy.external_agent_claim_can_override_audit', true)),
                'external_completion_claim_policy_transition_allowed_from_external_claim' => (bool) data_get($payload, 'external_completion_claim_policy_transition_allowed_from_external_claim', data_get($payload, 'external_completion_claim_policy.transition_allowed_from_external_claim', true)),
                'external_completion_claim_policy_self_programming_allowed_from_external_claim' => (bool) data_get($payload, 'external_completion_claim_policy_self_programming_allowed_from_external_claim', data_get($payload, 'external_completion_claim_policy.self_programming_allowed_from_external_claim', true)),
                'external_completion_claim_policy_current_required_operator_artifact' => (string) data_get($payload, 'external_completion_claim_policy_current_required_operator_artifact', data_get($payload, 'external_completion_claim_policy.current_required_operator_artifact', '')),
                'external_completion_claim_policy_current_failed_count' => (int) data_get($payload, 'external_completion_claim_policy_current_failed_count', data_get($payload, 'external_completion_claim_policy.current_failed_count', 0)),
                'external_completion_claim_policy_hash' => (string) data_get($payload, 'external_completion_claim_policy_hash', data_get($payload, 'external_completion_claim_policy.external_completion_claim_policy_hash', '')),
                'next_stage_prompt_to_artifact_checklist_count' => (int) data_get($payload, 'next_stage_prompt_to_artifact_checklist_count', 0),
                'next_stage_prompt_to_artifact_checklist_passed_count' => (int) data_get($payload, 'next_stage_prompt_to_artifact_checklist_passed_count', 0),
                'closure_artifact_sequence' => (array) data_get($payload, 'closure_artifact_sequence', []),
                'closure_artifact_sequence_count' => (int) data_get($payload, 'closure_artifact_sequence_count', 0),
                'closure_artifact_sequence_hash' => (string) data_get($payload, 'closure_artifact_sequence_hash', ''),
                'prompt_to_artifact_checklist' => (array) data_get($payload, 'prompt_to_artifact_checklist', []),
                'prompt_to_artifact_checklist_count' => (int) data_get($payload, 'prompt_to_artifact_checklist_count', 0),
                'prompt_to_artifact_checklist_passed_count' => (int) data_get($payload, 'prompt_to_artifact_checklist_passed_count', 0),
                'prompt_to_artifact_checklist_hash' => (string) data_get($payload, 'prompt_to_artifact_checklist_hash', ''),
                'self_programming_bootstrap_plan_blockers' => (array) data_get($payload, 'self_programming_bootstrap_plan.blockers', []),
                'self_programming_bootstrap_plan_finalization_gate_source' => (string) data_get($payload, 'self_programming_bootstrap_plan.finalization_gate_source', ''),
                'self_programming_bootstrap_plan_finalization_gate_terminal_loop_green' => (bool) data_get($payload, 'self_programming_bootstrap_plan.finalization_gate_terminal_loop_green', false),
                'self_programming_bootstrap_plan_finalization_gate_failed_check_ids' => (array) data_get($payload, 'self_programming_bootstrap_plan.finalization_gate_failed_check_ids', []),
                'self_programming_bootstrap_plan_finalization_gate_next_stage_blockers' => (array) data_get($payload, 'self_programming_bootstrap_plan.finalization_gate_next_stage_blockers', []),
                'self_programming_bootstrap_plan_required_before_first_task' => (array) data_get($payload, 'self_programming_bootstrap_plan.required_before_first_self_programming_task', []),
                'self_programming_bootstrap_plan_can_create_mutation_tasks' => (bool) data_get($payload, 'self_programming_bootstrap_plan.can_create_mutation_tasks', false),
                'self_programming_bootstrap_plan_can_apply_patches' => (bool) data_get($payload, 'self_programming_bootstrap_plan.can_apply_patches', false),
                'self_programming_bootstrap_plan_can_call_provider' => (bool) data_get($payload, 'self_programming_bootstrap_plan.can_call_provider', false),
                'self_programming_bootstrap_plan_can_spend_tokens' => (bool) data_get($payload, 'self_programming_bootstrap_plan.can_spend_tokens', false),
                'self_programming_bootstrap_plan_can_dispatch_work' => (bool) data_get($payload, 'self_programming_bootstrap_plan.can_dispatch_work', false),
                'self_programming_bootstrap_plan_can_enable_self_programming_runtime' => (bool) data_get($payload, 'self_programming_bootstrap_plan.can_enable_self_programming_runtime', false),
                'self_construction_complete' => (bool) data_get($payload, 'self_construction_complete', false),
                'contract_design_allowed' => (bool) data_get($payload, 'contract_design_allowed', false),
                'runtime_activation_allowed' => (bool) data_get($payload, 'runtime_activation_allowed', false),
                'self_programming_allowed' => (bool) data_get($payload, 'self_programming_allowed', false),
                'provider_call_allowed' => (bool) data_get($payload, 'provider_call_allowed', false),
                'token_spend_allowed' => (bool) data_get($payload, 'token_spend_allowed', false),
                'transition_blockers' => (array) data_get($payload, 'transition_blockers', []),
                'safety_contract_path' => (string) data_get($payload, 'safety_contract_path', ''),
                'safety_contract_hash' => (string) data_get($payload, 'safety_contract_hash', ''),
                'terminal_loop_operational_proof_canonical_binding_path' => (string) data_get($payload, 'terminal_loop_operational_proof_canonical_binding_path', ''),
                'transition_readiness_command_with_canonical_terminal_loop_binding' => (string) data_get($payload, 'transition_readiness_command_with_canonical_terminal_loop_binding', ''),
                'safety_contract_certification_command_with_canonical_terminal_loop_binding' => (string) data_get($payload, 'safety_contract_certification_command_with_canonical_terminal_loop_binding', ''),
                'completion_audit_command_with_canonical_terminal_loop_binding' => (string) data_get($payload, 'completion_audit_command_with_canonical_terminal_loop_binding', ''),
                'certification_hash' => (string) data_get($payload, 'certification_hash', ''),
                'failed_check_ids' => (array) data_get($payload, 'failed_check_ids', []),
            ],
        );
    }

    public function atlasSelfConstructionCompletionFinalizationGateStatus(array $options = []): array
    {
        $liveStatusProjection = $options === [];
        $options = $this->withTerminalLoopOperationalProofPayload($options);

        $result = (new AtlasSelfConstructionCompletionFinalizationGateService($this->mother))->evaluate($options);
        $handoff = (array) data_get($result, 'completion_finalization_operator_handoff', []);
        $blockerClassification = (array) data_get($handoff, 'blocker_classification', []);
        $runtimeGapMatrix = (array) data_get($options, 'runtime_gap_matrix', []);
        if ($runtimeGapMatrix === []) {
            $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother))->matrix();
        }
        $currentRequiredOperatorArtifact = (string) data_get($handoff, 'current_required_operator_artifact', '');
        if ($liveStatusProjection) {
            $completionEvidence = $this->atlasSelfConstructionOsCompletionEvidenceStatus($options);
            $currentRequiredOperatorArtifact = (string) data_get($completionEvidence, 'current_required_operator_artifact', $currentRequiredOperatorArtifact);
        }

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_completion_finalization_gate',
            label: 'Atlas Self-Construction Completion Finalization Gate',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'completion_allowed' => false,
                'completion_claim_allowed' => (bool) data_get($result, 'completion_claim_allowed', false),
                'next_stage_allowed' => (bool) data_get($result, 'next_stage_allowed', false),
                'terminal_loop_operational_proof_supplied_to_completion_finalization_gate' => isset($options['agent_control_plane_terminal_loop_operational_proof']),
                'terminal_loop_operational_proof_source' => (string) ($options['agent_control_plane_terminal_loop_operational_proof_source'] ?? (isset($options['agent_control_plane_terminal_loop_operational_proof_json']) ? 'explicit_json_option' : '')),
                'completion_finalization_operator_handoff_status' => (string) data_get($result, 'completion_finalization_operator_handoff.status', ''),
                'completion_finalization_operator_handoff_current_required_artifact' => (string) data_get($result, 'completion_finalization_operator_handoff.current_required_operator_artifact', ''),
                'completion_finalization_operator_handoff_failed_check_count' => (int) data_get($result, 'completion_finalization_operator_handoff.failed_check_count', 0),
                'completion_finalization_operator_handoff_failed_check_ids' => (array) data_get($result, 'completion_finalization_operator_handoff.failed_check_ids', []),
                'completion_audit_failed_criteria' => (array) data_get($handoff, 'completion_audit_failed_criteria', []),
                'completion_audit_failed_count' => (int) data_get($handoff, 'completion_audit_failed_count', 0),
                'failed_criteria' => (array) data_get($handoff, 'completion_audit_failed_criteria', []),
                'failed_count' => (int) data_get($handoff, 'completion_audit_failed_count', 0),
                'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
                'human_blocker_count' => (int) data_get($blockerClassification, 'human_blocker_count', 0),
                'human_blockers' => (array) data_get($blockerClassification, 'human_blockers', []),
                'real_provider_blocker_count' => (int) data_get($blockerClassification, 'real_provider_blocker_count', 0),
                'real_provider_blockers' => (array) data_get($blockerClassification, 'real_provider_blockers', []),
                'technical_blocker_count' => (int) data_get($blockerClassification, 'technical_blocker_count', 0),
                'technical_blockers' => (array) data_get($blockerClassification, 'technical_blockers', []),
                'next_stage_blockers' => (array) data_get($handoff, 'next_stage_blockers', []),
                'next_stage_blocker_count' => (int) data_get($handoff, 'next_stage_blocker_count', 0),
                'completion_finalization_operator_handoff_can_execute' => (bool) data_get($result, 'completion_finalization_operator_handoff.can_execute_from_handoff', false),
                'completion_finalization_operator_handoff_can_persist' => (bool) data_get($result, 'completion_finalization_operator_handoff.can_persist_from_handoff', false),
                'completion_finalization_operator_handoff_can_promote_completion' => (bool) data_get($result, 'completion_finalization_operator_handoff.can_promote_completion_from_handoff', false),
                'completion_finalization_operator_handoff_operator_evidence_readiness_command' => (string) data_get($result, 'completion_finalization_operator_handoff.operator_evidence_readiness_command', ''),
                'completion_finalization_operator_handoff_ordered_next_command_count' => count((array) data_get($result, 'completion_finalization_operator_handoff.ordered_next_commands', [])),
                'completion_finalization_operator_handoff_final_verification_sequence_count' => count((array) data_get($result, 'completion_finalization_operator_handoff.final_verification_sequence', [])),
                'completion_finalization_operator_handoff_hash' => (string) data_get($result, 'completion_finalization_operator_handoff.completion_finalization_operator_handoff_hash', ''),
                'external_completion_claim_policy_status' => (string) data_get($result, 'external_completion_claim_policy.status', ''),
                'external_completion_claim_policy_completion_authority' => (string) data_get($result, 'external_completion_claim_policy.completion_authority', ''),
                'external_completion_claim_policy_required_completion_predicate' => (string) data_get($result, 'external_completion_claim_policy.required_completion_predicate', ''),
                'external_completion_claim_policy_external_agent_claim_accepted' => (bool) data_get($result, 'external_completion_claim_policy.external_agent_claim_accepted', true),
                'external_completion_claim_policy_external_agent_claim_can_mark_os_complete' => (bool) data_get($result, 'external_completion_claim_policy.external_agent_claim_can_mark_os_complete', true),
                'external_completion_claim_policy_external_agent_claim_can_override_audit' => (bool) data_get($result, 'external_completion_claim_policy.external_agent_claim_can_override_audit', true),
                'external_completion_claim_policy_next_stage_allowed_from_external_claim' => (bool) data_get($result, 'external_completion_claim_policy.next_stage_allowed_from_external_claim', true),
                'external_completion_claim_policy_self_programming_allowed_from_external_claim' => (bool) data_get($result, 'external_completion_claim_policy.self_programming_allowed_from_external_claim', true),
                'external_completion_claim_policy_completion_claim_allowed_by_gate' => (bool) data_get($result, 'external_completion_claim_policy.completion_claim_allowed_by_gate', false),
                'external_completion_claim_policy_current_required_operator_artifact' => (string) data_get($result, 'external_completion_claim_policy.current_required_operator_artifact', ''),
                'external_completion_claim_policy_current_failed_count' => (int) data_get($result, 'external_completion_claim_policy.current_failed_count', 0),
                'external_completion_claim_policy_hash' => (string) data_get($result, 'external_completion_claim_policy.external_completion_claim_policy_hash', ''),
                'completion_finalization_next_action_shell_packet' => (array) data_get($result, 'completion_finalization_operator_handoff.next_action_shell_packet', []),
                'completion_finalization_next_action_shell_packet_status' => (string) data_get($result, 'completion_finalization_operator_handoff.next_action_shell_packet.status', ''),
                'completion_finalization_next_action_shell_packet_hash' => (string) data_get($result, 'completion_finalization_operator_handoff.next_action_shell_packet.shell_packet_hash', ''),
                'completion_finalization_next_action_exact_command' => (string) data_get($result, 'completion_finalization_operator_handoff.next_action_shell_packet.exact_command', ''),
                'next_required_command' => (string) data_get($result, 'completion_finalization_operator_handoff.next_action_shell_packet.exact_command', ''),
                'next_required_persist_command' => (string) data_get($result, 'completion_finalization_operator_handoff.next_action_shell_packet.persist_command', ''),
                'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
                'completion_finalization_next_action_placeholder_count' => (int) data_get($result, 'completion_finalization_operator_handoff.next_action_shell_packet.placeholder_count', 0),
                'completion_finalization_next_action_copy_safe' => (bool) data_get($result, 'completion_finalization_operator_handoff.next_action_shell_packet.copy_safe', false),
                'closure_artifact_sequence' => (array) data_get($result, 'closure_artifact_sequence', []),
                'closure_artifact_sequence_count' => (int) data_get($result, 'closure_artifact_sequence_count', 0),
                'closure_artifact_sequence_hash' => (string) data_get($result, 'closure_artifact_sequence_hash', ''),
                'prompt_to_artifact_checklist' => (array) data_get($result, 'prompt_to_artifact_checklist', []),
                'prompt_to_artifact_checklist_count' => (int) data_get($result, 'prompt_to_artifact_checklist_count', 0),
                'prompt_to_artifact_checklist_passed_count' => (int) data_get($result, 'prompt_to_artifact_checklist_passed_count', 0),
                'prompt_to_artifact_checklist_hash' => (string) data_get($result, 'prompt_to_artifact_checklist_hash', ''),
                'terminal_loop_operational_proof_required_before_completion_claim' => (bool) data_get($result, 'terminal_loop_operational_proof_required_before_completion_claim', false),
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
                'terminal_loop_operational_proof_expected_binding_schema' => (string) data_get($result, 'terminal_loop_operational_proof_expected_binding_schema', ''),
                'terminal_loop_operational_proof_green' => (bool) data_get($result, 'terminal_loop_green', false),
                'terminal_loop_operational_proof_status' => (string) data_get($result, 'checks.terminal_loop_green.evidence.operational_proof_status', ''),
                'terminal_loop_operational_proof_hash' => (string) data_get($result, 'checks.terminal_loop_green.evidence.operational_proof_hash', ''),
                'terminal_loop_operational_proof_post_cycle_end_to_end_contract_status' => (string) data_get($result, 'checks.terminal_loop_green.evidence.post_cycle_end_to_end_contract_status', ''),
                'terminal_loop_operational_proof_post_cycle_end_to_end_contract_all_required_surfaces_present' => (bool) data_get($result, 'checks.terminal_loop_green.evidence.post_cycle_end_to_end_contract_all_required_surfaces_present', false),
                'terminal_loop_operational_proof_post_cycle_end_to_end_contract_missing_required_capabilities' => (array) data_get($result, 'checks.terminal_loop_green.evidence.post_cycle_end_to_end_contract_missing_required_capabilities', []),
                'terminal_loop_operational_proof_post_cycle_end_to_end_contract_hash' => (string) data_get($result, 'checks.terminal_loop_green.evidence.post_cycle_end_to_end_contract_hash', ''),
                'command_to_refresh_terminal_loop_operational_proof' => (string) data_get($result, 'command_to_refresh_terminal_loop_operational_proof', ''),
                'command_to_persist_terminal_loop_operational_proof_binding' => (string) data_get($result, 'command_to_persist_terminal_loop_operational_proof_binding', ''),
                'command_to_capture_snapshot_after_terminal_loop_operational_proof' => (string) data_get($result, 'command_to_capture_snapshot_after_terminal_loop_operational_proof', ''),
                'command_to_rerun_audit_with_terminal_loop_operational_proof' => (string) data_get($result, 'command_to_rerun_audit_with_terminal_loop_operational_proof', ''),
                'terminal_loop_operational_proof_json_reference' => (string) data_get($result, 'terminal_loop_operational_proof_json_reference', ''),
                'final_verification_sequence' => (array) data_get($result, 'final_verification_sequence', []),
                'final_verification_sequence_step_count' => count((array) data_get($result, 'final_verification_sequence', [])),
                'completion_audit_green_requires_current_snapshot_after_terminal_loop_proof' => (bool) data_get($result, 'completion_audit_green_requires_current_snapshot_after_terminal_loop_proof', false),
                'self_programming_allowed' => false,
            ],
        );
    }

}
