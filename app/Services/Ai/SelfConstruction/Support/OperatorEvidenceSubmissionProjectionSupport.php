<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\OperatorEvidence\OperatorEvidenceCanonicalizer;
use App\Services\Ai\SelfConstruction\OperatorEvidence\TerminalLoopOperationalProofCommandFactory;

/**
 * Pure projection helpers peeled from
 * {@see \App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService}.
 *
 * No FS, DI, clock, Storage, Artisan, provider, or ledger I/O — only deterministic
 * string/array projection of readiness diagnostics, closure graphs, and claim policy.
 * The readiness host keeps verification I/O, payload loading, and envelope composition.
 */
final class OperatorEvidenceSubmissionProjectionSupport
{
    private function __construct()
    {
    }

    /**
     * @param  array<string, array<string, mixed>>  $diagnostics
     * @param  array<string, mixed>  $canonicalSubmissionPersistencePlan
     * @param  array<string, mixed>  $persistedEvidenceState
     * @return array<string, mixed>
     */
    public static function operatorEvidenceSequenceIntegrity(
        array $diagnostics,
        array $canonicalSubmissionPersistencePlan,
        array $persistedEvidenceState,
        string $nextRequired,
    ): array {
        $artifactOrder = [
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'human_completion_receipt',
        ];
        $stepIndexes = array_flip($artifactOrder);
        $currentStepIndex = $nextRequired === 'rerun_completion_audit'
            ? count($artifactOrder)
            : (int) ($stepIndexes[$nextRequired] ?? 0);

        $artifactStates = [];
        $violations = [];

        foreach ($artifactOrder as $index => $artifact) {
            $diagnostic = (array) ($diagnostics[$artifact] ?? []);
            $persisted = (array) data_get($persistedEvidenceState, $artifact, []);
            $previousArtifacts = array_slice($artifactOrder, 0, $index);
            $previousArtifactsGreen = true;
            $previousArtifactsPersisted = true;

            foreach ($previousArtifacts as $previousArtifact) {
                $previousDiagnostic = (array) ($diagnostics[$previousArtifact] ?? []);
                $previousPersisted = (array) data_get($persistedEvidenceState, $previousArtifact, []);
                $previousArtifactsGreen = $previousArtifactsGreen
                    && ((bool) data_get($previousDiagnostic, 'ready', false) || (bool) data_get($previousPersisted, 'persisted_green', false));
                $previousArtifactsPersisted = $previousArtifactsPersisted
                    && (bool) data_get($previousPersisted, 'persisted_green', false);
            }

            $supplied = (bool) data_get($diagnostic, 'supplied', false);
            $ready = (bool) data_get($diagnostic, 'ready', false);
            $persistedGreen = (bool) data_get($persisted, 'persisted_green', false);
            $outOfOrder = $supplied && ! $previousArtifactsGreen;

            if ($outOfOrder) {
                $violations[] = $artifact.'_supplied_before_previous_artifacts_green';
            }

            $artifactStates[] = [
                'order' => $index + 1,
                'artifact' => $artifact,
                'is_current_required_artifact' => $artifact === $nextRequired,
                'supplied_for_review' => $supplied,
                'diagnostic_ready' => $ready,
                'persisted_green' => $persistedGreen,
                'previous_artifacts' => $previousArtifacts,
                'previous_artifacts_green' => $previousArtifactsGreen,
                'previous_artifacts_persisted_green' => $previousArtifactsPersisted,
                'out_of_order_submission_detected' => $outOfOrder,
                'future_step_blocked_until_prior_green' => $index > $currentStepIndex && ! $previousArtifactsGreen,
            ];
        }

        $canonicalSteps = (array) data_get($canonicalSubmissionPersistencePlan, 'steps', []);
        $persistenceViolations = array_values(array_filter(array_map(
            static function (array $step): string {
                $status = (string) ($step['status'] ?? '');
                $blocker = (string) ($step['blocker'] ?? '');
                if (str_starts_with($status, 'ready') && $blocker !== '') {
                    return 'canonical_persistence_step_ready_with_blocker:'.(string) ($step['id'] ?? '');
                }

                return '';
            },
            $canonicalSteps,
        )));
        $violations = array_values(array_unique(array_merge($violations, $persistenceViolations)));

        $integrity = [
            'schema_version' => 'atlas.self_construction.operator_evidence_sequence_integrity.v1',
            'mode' => 'read_only_operator_evidence_sequence_integrity',
            'status' => $violations === [] ? 'sequence_integrity_ok' : 'sequence_integrity_blocked',
            'artifact_order' => $artifactOrder,
            'current_required_artifact' => $nextRequired,
            'current_step_index' => $currentStepIndex,
            'sequence_valid' => $violations === [],
            'sequence_violation_count' => count($violations),
            'sequence_violations' => $violations,
            'artifact_states' => $artifactStates,
            'canonical_persistence_plan_sequence_ordered' => (bool) data_get($canonicalSubmissionPersistencePlan, 'sequence_ordered', false),
            'canonical_persistence_next_step_id' => (string) data_get($canonicalSubmissionPersistencePlan, 'next_step_id', ''),
            'parallel_submission_allowed' => false,
            'can_skip_steps' => false,
            'can_persist_from_sequence_integrity' => false,
            'requires_fresh_readiness_after_each_persist' => true,
            'requires_fresh_completion_audit_after_each_persist' => true,
            'final_success_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'non_execution_guarantees' => [
                'operator_evidence_sequence_integrity_does_not_persist_receipts',
                'operator_evidence_sequence_integrity_does_not_sign_for_operator',
                'operator_evidence_sequence_integrity_does_not_call_provider',
                'operator_evidence_sequence_integrity_does_not_spend_tokens',
                'operator_evidence_sequence_integrity_does_not_dispatch',
                'operator_evidence_sequence_integrity_does_not_enable_runtime',
                'operator_evidence_sequence_integrity_does_not_promote_completion',
            ],
        ];
        $integrity['sequence_integrity_hash'] = OperatorEvidenceCanonicalizer::stableHash($integrity);

        return $integrity;
    }

    /**
     * @param  array<string, mixed>  $workspaceInput
     * @param  list<string>  $staleContextHashes
     * @param  array<string, mixed>  $runtimeGapMatrix
     * @return array<string, mixed>
     */
    public static function draftWorkspaceRefreshDecision(
        array $workspaceInput,
        array $staleContextHashes,
        array $runtimeGapMatrix,
    ): array {
        $workspaceLoaded = (string) data_get($workspaceInput, 'status') === 'loaded_for_read_only_submission_readiness';
        $reasons = [];

        if ($workspaceLoaded && $staleContextHashes !== []) {
            $reasons[] = 'runtime_promotion_receipt_context_hashes_are_stale';
        }
        if ($workspaceLoaded && (int) data_get($workspaceInput, 'warning_count', 0) > 0) {
            $reasons[] = 'draft_workspace_inspector_reported_warnings';
        }
        if ($workspaceLoaded && (int) data_get($workspaceInput, 'violation_count', 0) > 0) {
            $reasons[] = 'draft_workspace_inspector_reported_violations';
        }

        $required = $reasons !== [];

        return [
            'schema_version' => 'atlas.self_construction.operator_evidence_draft_workspace_refresh_decision.v1',
            'status' => $required ? 'refresh_recommended_before_operator_signature' : ($workspaceLoaded ? 'current_enough_for_readiness_review' : 'not_applicable'),
            'required' => $required,
            'reasons' => array_values(array_unique($reasons)),
            'stale_context_hashes' => $staleContextHashes,
            'current_hashes_for_new_workspace' => [
                'runtime_gap_matrix_hash' => (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($runtimeGapMatrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($runtimeGapMatrix, 'runtime_promotion_closure_basis_hash', ''),
            ],
            'refresh_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-artifact-template-pack-status --persist-operator-draft-workspace --json',
            'post_refresh_review_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --operator-draft-workspace-path=<new_workspace_path> --json',
            'can_refresh_from_readiness' => false,
            'can_persist_evidence_from_refresh' => false,
            'non_execution_guarantees' => [
                'refresh_decision_does_not_write_workspace',
                'refresh_decision_does_not_persist_evidence',
                'refresh_decision_does_not_sign_for_operator',
                'refresh_decision_does_not_call_provider',
                'refresh_decision_does_not_spend_tokens',
                'refresh_decision_does_not_dispatch',
                'refresh_decision_does_not_promote_completion',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $explicit
     * @param  array<string, mixed>  $workspacePayloads
     * @param  array<string, mixed>  $canonicalSubmissionPayloads
     * @return array<string, mixed>
     */
    public static function payloadOrWorkspaceOrCanonicalSubmission(
        array $explicit,
        array $workspacePayloads,
        array $canonicalSubmissionPayloads,
        string $workspaceKey,
    ): array {
        if ($explicit !== []) {
            return $explicit;
        }
        $workspacePayload = (array) data_get($workspacePayloads, $workspaceKey, []);
        if ($workspacePayload !== []) {
            return $workspacePayload;
        }

        return (array) data_get($canonicalSubmissionPayloads, $workspaceKey, []);
    }

    /** @param list<string> $violationCodes */
    public static function canonicalSubmissionStaleContextHashes(array $violationCodes): array
    {
        $stale = [];
        $codeToField = [
            'runtime_gap_matrix_hash_mismatch' => 'runtime_gap_matrix_hash',
            'runtime_promotion_basis_hash_mismatch' => 'runtime_promotion_basis_hash',
            'runtime_promotion_closure_basis_hash_mismatch' => 'runtime_promotion_closure_basis_hash',
            'graduation_hash_mismatch' => 'graduation_evidence_hashes',
        ];
        foreach ($codeToField as $code => $field) {
            if (in_array($code, $violationCodes, true)) {
                $stale[] = $field;
            }
        }

        return $stale;
    }

    /** @param array<string, mixed> $diagnostic */
    public static function envelopeStatus(string $artifact, array $diagnostic): string
    {
        if (($diagnostic['supplied'] ?? false) !== true) {
            return match ($artifact) {
                'runtime_promotion_receipt' => 'blocked_until_operator_runtime_promotion_receipt_exists',
                'real_provider_smoke' => 'blocked_until_operator_real_provider_smoke_payload_exists',
                'human_completion_receipt' => 'blocked_until_operator_human_completion_receipt_exists',
                default => 'blocked_until_operator_payload_exists',
            };
        }
        if (($diagnostic['ready'] ?? false) === true) {
            return 'ready_for_explicit_operator_persistence';
        }

        return match ($artifact) {
            'human_completion_receipt' => in_array('human_completion_receipt_supplied_before_runtime_and_smoke_green', (array) ($diagnostic['errors'] ?? []), true)
                ? 'blocked_until_runtime_and_smoke_envelopes_are_green'
                : 'blocked_until_human_completion_receipt_verifier_passes',
            'real_provider_smoke' => 'blocked_until_real_provider_smoke_verifier_passes',
            'runtime_promotion_receipt' => 'blocked_until_runtime_promotion_receipt_verifier_passes',
            default => 'blocked_until_verifier_passes',
        };
    }

    /**
     * @param  array<string, mixed>  $verification
     * @param  list<string>  $placeholders
     * @param  list<string>  $forbiddenFlagsTrue
     * @param  list<string>  $forbiddenFlagList
     * @return array<string, mixed>
     */
    public static function diagnosticRow(
        bool $supplied,
        array $verification,
        string $composedHash,
        array $placeholders,
        array $forbiddenFlagsTrue,
        array $forbiddenFlagList,
    ): array {
        $status = (string) ($verification['status'] ?? 'not_supplied');
        $ready = $supplied && $status === 'passed' && $placeholders === [] && $forbiddenFlagsTrue === [];
        $errors = [];
        if (! $supplied) {
            $errors[] = 'not_supplied';
        } else {
            if ($status !== 'passed') {
                $errors[] = 'verifier_status_'.$status;
            }
            foreach ((array) data_get($verification, 'violations', []) as $violation) {
                $code = (string) data_get($violation, 'code', '');
                if ($code !== '') {
                    $errors[] = 'violation_code_'.$code;
                }
            }
            foreach ($placeholders as $field) {
                $errors[] = 'placeholder_field_'.$field;
            }
            foreach ($forbiddenFlagsTrue as $flag) {
                $errors[] = 'forbidden_flag_true_'.$flag;
            }
        }

        return [
            'supplied' => $supplied,
            'status' => $status,
            'ready' => $ready,
            'composed_hash' => $composedHash,
            'placeholders' => $placeholders,
            'forbidden_flags_true' => $forbiddenFlagsTrue,
            'forbidden_flag_list' => $forbiddenFlagList,
            'violations' => (array) data_get($verification, 'violations', []),
            'violation_codes' => array_values(array_filter(array_map(
                static fn (mixed $violation): string => (string) data_get($violation, 'code', ''),
                (array) data_get($verification, 'violations', []),
            ))),
            'violation_count' => (int) data_get($verification, 'violation_count', 0),
            'errors' => array_values(array_unique($errors)),
        ];
    }

    public static function nextRequiredCommand(string $nextRequired): string
    {
        return match ($nextRequired) {
            'runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
            'human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
            'rerun_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            default => '',
        };
    }

    /** @return list<array<string, mixed>> */
    public static function closureArtifactSequence(
        bool $runtimePassed,
        bool $smokePassed,
        bool $humanPassed,
        bool $completionAuditReady,
    ): array {
        return [
            [
                'order' => 1,
                'artifact' => 'runtime_promotion_receipt',
                'requirement' => 'runtime_gap_matrix_all_runtime_y',
                'blocker_type' => 'human',
                'status' => $runtimePassed ? 'passed' : 'blocked',
                'passed' => $runtimePassed,
                'expected_receipt_schema' => 'atlas.self_construction.runtime_promotion_receipt.v1',
                'draft_command' => self::nextRequiredCommand('runtime_promotion_receipt'),
                'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json --persist-runtime-promotion-receipt --json',
                'evidence_source' => 'runtime_gap_matrix',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'order' => 2,
                'artifact' => 'real_provider_smoke',
                'requirement' => 'end_to_end_real_provider_smoke_green',
                'blocker_type' => 'real_provider',
                'status' => $smokePassed ? 'passed' : 'blocked',
                'passed' => $smokePassed,
                'expected_receipt_schema' => 'atlas.self_construction.real_provider_smoke_certification.v1',
                'draft_command' => self::nextRequiredCommand('real_provider_smoke'),
                'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json --persist-completion-evidence --json',
                'evidence_source' => 'real_provider_smoke',
                'requires_operator_signature' => false,
                'requires_provider_call' => true,
            ],
            [
                'order' => 3,
                'artifact' => 'human_completion_receipt',
                'requirement' => 'human_signed_os_complete_receipt_present',
                'blocker_type' => 'human',
                'status' => $humanPassed ? 'passed' : 'blocked',
                'passed' => $humanPassed,
                'expected_receipt_schema' => 'atlas.self_construction.human_signed_completion_receipt.v1',
                'draft_command' => self::nextRequiredCommand('human_completion_receipt'),
                'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json --persist-completion-evidence --json',
                'evidence_source' => 'human_completion_receipt',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'order' => 4,
                'artifact' => 'final_completion_audit',
                'requirement' => 'completion_audit_authorizes_completion_claim',
                'blocker_type' => $completionAuditReady ? 'none' : 'derived',
                'status' => $completionAuditReady ? 'passed' : 'blocked_until_operator_evidence_green',
                'passed' => $completionAuditReady,
                'expected_receipt_schema' => 'atlas.self_construction.os_completion_audit.v1',
                'draft_command' => TerminalLoopOperationalProofCommandFactory::completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
                'persist_command' => '',
                'evidence_source' => 'completion_audit',
                'requires_operator_signature' => false,
                'requires_provider_call' => false,
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $diagnostics
     * @return array<string, mixed>
     */
    public static function nextActionGraph(
        bool $runtimePassed,
        bool $smokePassed,
        bool $humanPassed,
        array $diagnostics,
    ): array {
        $completionAuditReady = $runtimePassed && $smokePassed && $humanPassed;

        $nodes = [
            [
                'id' => 'runtime_promotion_receipt',
                'order' => 1,
                'depends_on' => [],
                'ready' => $runtimePassed,
                'blocking_reasons' => $runtimePassed ? [] : (array) data_get($diagnostics, 'runtime_promotion_receipt.errors', ['runtime_promotion_receipt_not_ready']),
                'canonical_command' => self::nextRequiredCommand('runtime_promotion_receipt'),
                'worker_or_operator_owner' => 'operator',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'id' => 'real_provider_smoke',
                'order' => 2,
                'depends_on' => ['runtime_promotion_receipt'],
                'ready' => $smokePassed,
                'blocking_reasons' => $smokePassed ? [] : (array) data_get($diagnostics, 'real_provider_smoke.errors', ['real_provider_smoke_not_ready']),
                'canonical_command' => self::nextRequiredCommand('real_provider_smoke'),
                'worker_or_operator_owner' => 'provider',
                'requires_operator_signature' => false,
                'requires_provider_call' => true,
            ],
            [
                'id' => 'human_completion_receipt',
                'order' => 3,
                'depends_on' => ['runtime_promotion_receipt', 'real_provider_smoke'],
                'ready' => $humanPassed,
                'blocking_reasons' => $humanPassed ? [] : (array) data_get($diagnostics, 'human_completion_receipt.errors', ['human_completion_receipt_not_ready']),
                'canonical_command' => self::nextRequiredCommand('human_completion_receipt'),
                'worker_or_operator_owner' => 'operator',
                'requires_operator_signature' => true,
                'requires_provider_call' => false,
            ],
            [
                'id' => 'rerun_completion_audit',
                'order' => 4,
                'depends_on' => ['runtime_promotion_receipt', 'real_provider_smoke', 'human_completion_receipt'],
                'ready' => $completionAuditReady,
                'blocking_reasons' => $completionAuditReady ? [] : ['prior_closure_steps_not_all_green'],
                'canonical_command' => self::nextRequiredCommand('rerun_completion_audit'),
                'worker_or_operator_owner' => 'worker',
                'requires_operator_signature' => false,
                'requires_provider_call' => false,
            ],
        ];

        $graph = [
            'schema_version' => 'atlas.self_construction.operator_evidence_next_action_graph.v1',
            'mode' => 'read_only_operator_evidence_next_action_graph',
            'status' => $completionAuditReady ? 'ready_for_completion_audit_rerun' : 'blocked_on_operator_or_provider_owned_steps',
            'nodes' => $nodes,
            'node_count' => count($nodes),
            'can_run_from_graph' => false,
            'non_execution_guarantees' => [
                'next_action_graph_does_not_execute_command',
                'next_action_graph_does_not_persist_receipts',
                'next_action_graph_does_not_call_provider',
                'next_action_graph_does_not_spend_tokens',
                'next_action_graph_does_not_sign_for_operator',
                'next_action_graph_does_not_promote_completion',
            ],
        ];
        $graph['next_action_graph_hash'] = OperatorEvidenceCanonicalizer::stableHash($graph);

        return $graph;
    }

    /**
     * @param  list<array<string, mixed>>  $closureArtifactSequence
     * @return list<array<string, mixed>>
     */
    public static function promptToArtifactChecklist(array $closureArtifactSequence): array
    {
        return array_map(static fn (array $row): array => [
            'requirement' => (string) $row['requirement'],
            'artifact' => (string) $row['artifact'],
            'status' => (string) $row['status'],
            'passed' => (bool) $row['passed'],
            'blocker_type' => (string) $row['blocker_type'],
            'expected_receipt_schema' => (string) $row['expected_receipt_schema'],
            'evidence_source' => (string) $row['evidence_source'],
            'command' => (string) $row['draft_command'],
            'persist_command' => (string) $row['persist_command'],
        ], $closureArtifactSequence);
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  list<array<string, mixed>>  $closureArtifactSequence
     * @return array<string, mixed>
     */
    public static function externalCompletionClaimPolicy(
        array $completionAudit,
        array $closureArtifactSequence,
        string $nextRequired,
    ): array {
        $auditComplete = (string) data_get($completionAudit, 'status') === 'complete'
            && (bool) data_get($completionAudit, 'completion_allowed', false)
            && (int) data_get($completionAudit, 'failed_count', count((array) data_get($completionAudit, 'failed_criteria', []))) === 0;
        $missingArtifacts = array_values(array_map(
            static fn (array $row): array => [
                'artifact' => (string) $row['artifact'],
                'requirement' => (string) $row['requirement'],
                'status' => (string) $row['status'],
                'blocker_type' => (string) $row['blocker_type'],
            ],
            array_filter(
                $closureArtifactSequence,
                static fn (array $row): bool => ! (bool) $row['passed'],
            ),
        ));

        $policy = [
            'schema_version' => 'atlas.self_construction.external_completion_claim_policy.v1',
            'mode' => 'read_only_external_completion_claim_policy',
            'status' => $auditComplete ? 'audit_authorizes_completion_claim' : 'reject_external_completion_claim',
            'completion_authority' => 'atlas_self_construction_os_completion_audit',
            'external_agent_claim_accepted' => false,
            'external_agent_claim_can_mark_os_complete' => false,
            'external_agent_claim_can_override_audit' => false,
            'completion_claim_allowed_by_audit' => $auditComplete,
            'required_completion_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'current_completion_audit_status' => (string) data_get($completionAudit, 'status', 'unknown'),
            'current_completion_allowed' => (bool) data_get($completionAudit, 'completion_allowed', false),
            'current_failed_count' => (int) data_get($completionAudit, 'failed_count', count((array) data_get($completionAudit, 'failed_criteria', []))),
            'current_failed_criteria' => (array) data_get($completionAudit, 'failed_criteria', []),
            'current_required_operator_artifact' => $nextRequired,
            'missing_required_evidence_artifacts' => $missingArtifacts,
            'missing_required_evidence_artifact_count' => count($missingArtifacts),
            'operator_verification_commands' => [
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'operator_evidence_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'completion_audit_with_canonical_terminal_loop_operational_proof' => TerminalLoopOperationalProofCommandFactory::completionAuditWithCanonicalTerminalLoopOperationalProofCommand(),
            ],
            'failure_policy' => [
                'ignore_external_agent_completion_claim_until_completion_audit_complete',
                'stop_if_failed_criteria_is_not_empty',
                'stop_if_completion_allowed_is_false',
                'stop_if_human_receipt_or_real_provider_smoke_is_missing',
                'rerun_operator_evidence_readiness_after_every_persisted_artifact',
            ],
            'non_execution_guarantees' => [
                'external_completion_claim_policy_does_not_persist_receipts',
                'external_completion_claim_policy_does_not_sign_for_operator',
                'external_completion_claim_policy_does_not_call_provider',
                'external_completion_claim_policy_does_not_spend_tokens',
                'external_completion_claim_policy_does_not_dispatch',
                'external_completion_claim_policy_does_not_enable_runtime',
                'external_completion_claim_policy_does_not_promote_completion',
            ],
        ];
        $policy['external_completion_claim_policy_hash'] = OperatorEvidenceCanonicalizer::stableHash($policy);

        return $policy;
    }
}
