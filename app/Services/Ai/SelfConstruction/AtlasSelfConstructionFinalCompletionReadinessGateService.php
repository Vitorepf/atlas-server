<?php

namespace App\Services\Ai\SelfConstruction;


use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Carbon\CarbonImmutable;

/**
 * Read-only final readiness gate for Atlas Self-Construction OS.
 *
 * It is the single authority that may emit `status=complete` AND only when
 * the canonical completion audit is `complete` with zero failed criteria
 * and a human-signed receipt is present.
 *
 * It NEVER:
 *   - signs receipts;
 *   - persists state;
 *   - promotes completion (it only reports `completion_claim_allowed`);
 *   - declares the next stage (Atlas Self-Programming OS) ready unless
 *     the current OS is fully complete in real evidence.
 */
final class AtlasSelfConstructionFinalCompletionReadinessGateService
{
    use RecursivelyKsortsArrays { recursivelyKsort as ksortRecursive; }

    public const SCHEMA_VERSION = 'atlas.self_construction.final_completion_readiness_gate.v1';

    public const MODE = 'read_only_final_completion_readiness_gate';

    public const NEXT_STAGE_NAME = 'Atlas Self-Programming OS';

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

        $criteria = (array) data_get($completionAudit, 'criteria', []);
        $criteriaMatrix = [];
        $blockers = [];
        foreach ($criteria as $criterion) {
            $id = (string) ($criterion['id'] ?? '');
            $passed = (bool) ($criterion['passed'] ?? false);
            $criteriaMatrix[$id] = [
                'passed' => $passed,
                'status' => $passed ? 'green' : 'blocked',
                'requirement' => (string) ($criterion['requirement'] ?? ''),
                'evidence' => (array) ($criterion['evidence'] ?? []),
            ];
            if (! $passed) {
                $blockers[] = $id;
            }
        }

        $auditComplete = (string) data_get($completionAudit, 'status') === 'complete'
            && (int) data_get($completionAudit, 'failed_count', 0) === 0
            && (array) data_get($completionAudit, 'failed_criteria', []) === []
            && (bool) data_get($completionAudit, 'completion_allowed', false) === true
            && (bool) data_get($completionAudit, 'completion_claim_allowed', false) === true;
        $humanReceiptGreen = (bool) data_get($criteriaMatrix, 'human_signed_os_complete_receipt_present.passed', false);
        $runtimeGreen = (bool) data_get($criteriaMatrix, 'runtime_gap_matrix_all_runtime_y.passed', false);
        $smokeGreen = (bool) data_get($criteriaMatrix, 'end_to_end_real_provider_smoke_green.passed', false);
        $materialEvidence = $this->materialEvidence($criteriaMatrix);
        $materialEvidenceGreen = $materialEvidence['all_required_hashes_present'] === true;
        $terminalLoopOperationalProof = $this->terminalLoopOperationalProofEvidence($completionAudit);
        $terminalLoopOperationalProofGreen = $terminalLoopOperationalProof['accepted'] === true;

        if ($auditComplete && $materialEvidenceGreen && $terminalLoopOperationalProofGreen) {
            $status = 'complete';
        } elseif ($blockers === ['human_signed_os_complete_receipt_present']
            && $runtimeGreen
            && $smokeGreen
        ) {
            $status = 'complete_candidate';
        } else {
            $status = 'incomplete';
        }

        $completionAllowed = $status === 'complete';
        $completionClaimAllowed = $completionAllowed && $auditComplete && $materialEvidenceGreen;
        $nextStageAllowed = $completionClaimAllowed && $humanReceiptGreen;
        $nextStageBlockers = $nextStageAllowed
            ? []
            : array_values(array_unique(array_merge(
                $blockers,
                $auditComplete ? [] : ['completion_audit_not_status_complete'],
                $materialEvidenceGreen ? [] : ['material_completion_evidence_hashes_missing_or_invalid'],
                $terminalLoopOperationalProofGreen ? [] : ['terminal_loop_operational_proof_binding_missing_or_invalid'],
            )));
        $selfProgrammingTransitionReadiness = $this->selfProgrammingTransitionReadiness($nextStageAllowed, $nextStageBlockers);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'completion_allowed' => $completionAllowed,
            'completion_claim_allowed' => $completionClaimAllowed,
            'next_stage_allowed' => $nextStageAllowed,
            'next_stage_name' => $nextStageAllowed ? self::NEXT_STAGE_NAME : '',
            'next_stage_blocked_by' => $nextStageBlockers,
            'self_programming_os_transition_readiness' => $selfProgrammingTransitionReadiness,
            'self_programming_os_transition_status' => (string) $selfProgrammingTransitionReadiness['status'],
            'self_programming_os_transition_blockers' => (array) $selfProgrammingTransitionReadiness['blockers'],
            'transition_status' => (string) $selfProgrammingTransitionReadiness['status'],
            'transition_blockers' => (array) $selfProgrammingTransitionReadiness['blockers'],
            'self_programming_safety_contract_hash' => (string) $selfProgrammingTransitionReadiness['safety_contract_hash'],
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'audit_complete' => $auditComplete,
            'human_receipt_green' => $humanReceiptGreen,
            'runtime_green' => $runtimeGreen,
            'smoke_green' => $smokeGreen,
            'material_completion_evidence_green' => $materialEvidenceGreen,
            'material_completion_evidence' => $materialEvidence,
            'terminal_loop_operational_proof_green' => $terminalLoopOperationalProofGreen,
            'terminal_loop_operational_proof_evidence' => $terminalLoopOperationalProof,
            'criteria_matrix' => $criteriaMatrix,
            'completion_audit_status' => (string) data_get($completionAudit, 'status'),
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'completion_audit_failed_criteria' => (array) data_get($completionAudit, 'failed_criteria', []),
            'command_to_rerun_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
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
            'terminal_loop_operational_proof_required_before_completion_claim' => true,
            'terminal_loop_operational_proof_expected_binding_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'non_execution_guarantees' => [
                'final_completion_readiness_gate_does_not_sign_receipts',
                'final_completion_readiness_gate_does_not_persist_receipts',
                'final_completion_readiness_gate_does_not_persist_evidence',
                'final_completion_readiness_gate_does_not_promote_completion',
                'final_completion_readiness_gate_does_not_call_provider',
                'final_completion_readiness_gate_does_not_spend_tokens',
                'final_completion_readiness_gate_does_not_dispatch_work',
                'final_completion_readiness_gate_does_not_enable_runtime',
                'final_completion_readiness_gate_does_not_promote_next_stage_without_audit_complete',
            ],
            'safety_invariants' => [
                'completion_claim_requires_audit_status_complete' => true,
                'completion_claim_requires_human_signed_receipt' => true,
                'completion_claim_requires_runtime_and_smoke_green' => true,
                'completion_claim_requires_material_evidence_hashes' => true,
                'completion_claim_requires_terminal_loop_operational_proof_binding' => true,
                'completion_claim_requires_current_snapshot_after_terminal_loop_operational_proof' => true,
                'next_stage_requires_completion_claim_allowed' => true,
                'self_programming_transition_requires_self_construction_complete' => true,
                'self_programming_transition_requires_safety_contract' => true,
                'self_programming_transition_does_not_enable_runtime' => true,
            ],
        ];
        $payload['gate_hash'] = $this->stableHash($payload);

        return $payload;
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
        $canonicalReference = trim((string) ($options['agent_control_plane_terminal_loop_operational_proof_canonical_path'] ?? ''));

        if ($canonicalReference !== '') {
            return str_starts_with($canonicalReference, '@') ? $canonicalReference : '@'.$canonicalReference;
        }

        return str_starts_with($reference, '@') ? $reference : '';
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @return array<string, mixed>
     */
    private function terminalLoopOperationalProofEvidence(array $completionAudit): array
    {
        $evidence = (array) data_get($completionAudit, 'agent_control_plane_terminal_loop_operational_proof_evidence', []);
        $proofHash = (string) data_get($evidence, 'proof_hash', '');
        $validationViolationCount = (int) data_get($evidence, 'validation_violation_count', 0);
        $accepted = (string) data_get($evidence, 'status', '') === 'passed'
            && (bool) data_get($evidence, 'supplied', false)
            && (bool) data_get($evidence, 'passed', false)
            && $validationViolationCount === 0
            && preg_match('/^[a-f0-9]{64}$/', $proofHash) === 1
            && (bool) data_get($evidence, 'dispatch_allowed', true) === false
            && (bool) data_get($evidence, 'adapter_execution_allowed', true) === false
            && (bool) data_get($evidence, 'self_programming_allowed', true) === false
            && (int) data_get($evidence, 'post_cycle_cleanup_state.claimed_task_count', 1) === 0
            && (int) data_get($evidence, 'post_cycle_cleanup_state.active_lease_count', 1) === 0
            && (int) data_get($evidence, 'post_cycle_cleanup_state.recoverable_lease_count', 1) === 0;

        return [
            'status' => (string) data_get($evidence, 'status', ''),
            'supplied' => (bool) data_get($evidence, 'supplied', false),
            'passed' => (bool) data_get($evidence, 'passed', false),
            'accepted' => $accepted,
            'proof_hash' => $proofHash,
            'validation_violation_count' => $validationViolationCount,
            'post_cycle_cleanup_state' => (array) data_get($evidence, 'post_cycle_cleanup_state', []),
            'dispatch_allowed' => (bool) data_get($evidence, 'dispatch_allowed', false),
            'adapter_execution_allowed' => (bool) data_get($evidence, 'adapter_execution_allowed', false),
            'self_programming_allowed' => (bool) data_get($evidence, 'self_programming_allowed', false),
        ];
    }

    /** @param array<string, array<string, mixed>> $criteriaMatrix */
    private function materialEvidence(array $criteriaMatrix): array
    {
        $runtimeEvidence = (array) data_get($criteriaMatrix, 'runtime_gap_matrix_all_runtime_y.evidence', []);
        $humanEvidence = (array) data_get($criteriaMatrix, 'human_signed_os_complete_receipt_present.evidence', []);
        $smokeEvidence = (array) data_get($criteriaMatrix, 'end_to_end_real_provider_smoke_green.evidence', []);
        $releaseEvidence = (array) data_get($criteriaMatrix, 'release_dossier_green.evidence', []);
        $replayEvidence = (array) data_get($criteriaMatrix, 'replay_diff_against_completion_snapshot_green.evidence', []);
        $batchEvidence = (array) data_get($criteriaMatrix, 'certification_status_batch_green.evidence', []);

        $hashes = [
            'runtime_gap_matrix_hash' => (string) ($runtimeEvidence['runtime_gap_matrix_hash'] ?? ''),
            'runtime_promotion_receipt_hash' => (string) ($runtimeEvidence['runtime_promotion_receipt_hash'] ?? ''),
            'human_completion_receipt_hash' => (string) ($humanEvidence['receipt_hash'] ?? ''),
            'real_provider_smoke_hash' => (string) ($smokeEvidence['smoke_hash'] ?? ''),
            'release_dossier_hash' => (string) ($releaseEvidence['hash'] ?? ''),
            'replay_diff_hash' => (string) ($replayEvidence['diff_hash'] ?? ''),
            'certification_status_batch_hash' => (string) ($batchEvidence['hash'] ?? ''),
        ];
        $invalid = array_keys(array_filter(
            $hashes,
            static fn (string $hash): bool => preg_match('/^[a-f0-9]{64}$/', $hash) !== 1,
        ));

        return [
            'all_required_hashes_present' => $invalid === [],
            'invalid_or_missing_hash_fields' => $invalid,
            'hashes' => $hashes,
        ];
    }

    /**
     * @param  list<string>  $nextStageBlockers
     * @return array<string, mixed>
     */
    private function selfProgrammingTransitionReadiness(bool $selfConstructionComplete, array $nextStageBlockers): array
    {
        $safetyContractPath = 'docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md';
        $absolutePath = base_path($safetyContractPath);
        $safetyContractExists = is_file($absolutePath);
        $safetyContractHash = $safetyContractExists ? hash_file('sha256', $absolutePath) : '';
        $blockers = $nextStageBlockers;
        if (! $selfConstructionComplete) {
            $blockers[] = 'self_construction_os_not_complete';
        }
        if (! $safetyContractExists || preg_match('/^[a-f0-9]{64}$/', $safetyContractHash) !== 1) {
            $blockers[] = 'self_programming_safety_contract_missing_or_unhashable';
        }
        $blockers = array_values(array_unique($blockers));

        return [
            'schema_version' => 'atlas.self_programming.transition_readiness.v1',
            'status' => $blockers === [] ? 'ready_for_safety_contract_design' : 'blocked',
            'next_stage_name' => self::NEXT_STAGE_NAME,
            'self_construction_complete' => $selfConstructionComplete,
            'safety_contract_path' => $safetyContractPath,
            'safety_contract_exists' => $safetyContractExists,
            'safety_contract_hash' => $safetyContractHash,
            'blockers' => $blockers,
            'contract_design_allowed' => $blockers === [],
            'runtime_activation_allowed' => false,
            'self_programming_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'non_execution_guarantees' => [
                'self_programming_transition_readiness_does_not_enable_self_programming',
                'self_programming_transition_readiness_does_not_call_provider',
                'self_programming_transition_readiness_does_not_spend_tokens',
                'self_programming_transition_readiness_does_not_dispatch_work',
                'self_programming_transition_readiness_does_not_persist_receipts',
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        unset($payload['generated_at'], $payload['gate_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $value */
}
