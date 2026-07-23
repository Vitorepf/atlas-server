<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness\CodexReviewMerge;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * CODEX REVIEW MERGE pipeline sub-section 01 of 11, sub-split from the
 * god {@see \App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionCodexReviewMergeSection}
 * (GOD-DEBULK). Method bodies are byte-identical to the parent Section; the only
 * rewrite is that sibling/upstream calls route through the injected mother
 * ($this->parent->codex*), which re-dispatches to whichever collaborator owns
 * the target stage.
 *
 * Stage range: codexReviewMergeActionTemplate
 *           .. codexReviewMergeFinalReceiptDraft
 */
final class CodexReviewMergePart01SubSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    public function codexReviewMergeActionTemplate(array $options = []): array
    {
        $runbookPayload = $this->parent->codexReviewPostSignatureRunbook($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);
        $runbookReady = data_get($runbookPayload, 'status') === 'post_signature_runbook_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-ACTION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $runbookReady ? 'waiting_for_explicit_signed_merge_action' : 'blocked_before_post_signature_runbook',
            'source_post_signature_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_signature_request_hash' => data_get($runbook, 'source_signature_request_hash'),
            'source_signable_payload_hash' => data_get($runbook, 'source_signable_payload_hash'),
            'explicit_merge_action_required' => true,
            'signature_validated_by_this_template' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'auto_merge_allowed' => false,
            'execution_allowed' => false,
            'required_before_merge' => (array) data_get($runbook, 'required_before_any_merge_action', []),
            'required_inputs' => [
                'signed_receipt_hash',
                'signable_payload_hash',
                'selected_decision',
                'decision_rationale',
                'gate_outputs',
                'scope_integrity_result',
                'evidence_integrity_result',
                'files_to_merge',
                'human_merge_confirmation',
            ],
            'allowed_merge_decisions' => [
                'merge',
                'request_changes',
                'abort',
            ],
            'default_decision' => 'request_changes',
            'merge_boundaries' => [
                'merge_action_template_does_not_merge',
                'merge_action_template_does_not_validate_signature',
                'merge_action_template_does_not_grant_approval',
                'merge_action_template_does_not_record_decision',
                'merge_action_template_does_not_dispatch_work',
                'merge_requires_separate_human_or_governed_action',
                'hot_voice_or_kernel_scope_remains_forbidden',
            ],
            'operator_commands' => [
                'execution_status' => 'php artisan atlas:ai:self-construction --codex-execution-status --json',
                'integration_report' => 'php artisan atlas:ai:self-construction --codex-integration-report --json',
                'merge_readiness' => 'php artisan atlas:ai:self-construction --codex-merge-readiness --json',
                'final_review_packet' => 'php artisan atlas:ai:self-construction --codex-final-review-packet --json',
                'signature_request' => 'php artisan atlas:ai:self-construction --codex-review-signature-request --json',
                'post_signature_runbook' => 'php artisan atlas:ai:self-construction --codex-review-post-signature-runbook --json',
                'focused_tests' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'diff_check' => 'git diff --check',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_action_template.v1',
            'status' => $runbookReady ? 'merge_action_template_ready' : 'blocked_before_merge_action_template',
            'mode' => 'read_only_codex_review_merge_action_template',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'codex_review_merge_action_template_does_not_claim_packets',
                'codex_review_merge_action_template_does_not_complete_packets',
                'codex_review_merge_action_template_does_not_validate_signature',
                'codex_review_merge_action_template_does_not_record_decision',
                'codex_review_merge_action_template_does_not_approve_code',
                'codex_review_merge_action_template_does_not_merge',
                'codex_review_merge_action_template_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Codex review merge action template is ready as a non-executing form. It still requires explicit human/governed merge action.'
                : 'Codex review merge action template is blocked until the post-signature runbook is ready.',
        ];
    }

    public function codexReviewMergePreflight(array $options = []): array
    {
        $executionStatus = $this->parent->codexExecutionStatus($options);
        $integrationReport = $this->parent->codexIntegrationReport($options);
        $mergeReadiness = $this->parent->codexMergeReadiness($options);
        $finalReviewPacket = $this->parent->codexFinalReviewPacket($options);
        $signatureRequest = $this->parent->codexReviewSignatureRequest($options);
        $postSignatureRunbook = $this->parent->codexReviewPostSignatureRunbook($options);
        $mergeActionTemplate = $this->parent->codexReviewMergeActionTemplate($options);

        $checks = [
            [
                'id' => 'no_active_codex_sessions',
                'status' => ((int) data_get($executionStatus, 'monitor.counts.claimed', 0)) === 0 ? 'pass' : 'fail',
                'evidence_hash' => data_get($executionStatus, 'monitor_hash'),
            ],
            [
                'id' => 'integration_report_ready',
                'status' => data_get($integrationReport, 'status') === 'codex_integration_report_ready'
                    && data_get($integrationReport, 'report.integration_status') === 'ready_for_human_integration_review' ? 'pass' : 'fail',
                'evidence_hash' => data_get($integrationReport, 'report_hash'),
            ],
            [
                'id' => 'merge_readiness_ready_for_review',
                'status' => data_get($mergeReadiness, 'status') === 'ready_for_human_merge_review'
                    && data_get($mergeReadiness, 'readiness.merge_review_status') === 'ready_for_human_merge_review' ? 'pass' : 'fail',
                'evidence_hash' => data_get($mergeReadiness, 'readiness_hash'),
            ],
            [
                'id' => 'final_review_packet_ready',
                'status' => data_get($finalReviewPacket, 'status') === 'final_review_packet_ready' ? 'pass' : 'fail',
                'evidence_hash' => data_get($finalReviewPacket, 'packet_hash'),
            ],
            [
                'id' => 'signature_request_pending_not_validated',
                'status' => data_get($signatureRequest, 'status') === 'review_signature_pending'
                    && data_get($signatureRequest, 'signature_request.signature_valid') === false ? 'pass' : 'fail',
                'evidence_hash' => data_get($signatureRequest, 'request_hash'),
            ],
            [
                'id' => 'post_signature_runbook_ready',
                'status' => data_get($postSignatureRunbook, 'status') === 'post_signature_runbook_ready'
                    && data_get($postSignatureRunbook, 'signature_valid') === false ? 'pass' : 'fail',
                'evidence_hash' => data_get($postSignatureRunbook, 'runbook_hash'),
            ],
            [
                'id' => 'merge_action_template_ready_without_authority',
                'status' => data_get($mergeActionTemplate, 'status') === 'merge_action_template_ready'
                    && data_get($mergeActionTemplate, 'merge_allowed') === false ? 'pass' : 'fail',
                'evidence_hash' => data_get($mergeActionTemplate, 'template_hash'),
            ],
        ];

        $failedChecks = array_values(array_filter(
            $checks,
            fn (array $check): bool => $check['status'] !== 'pass'
        ));

        $preflight = [
            'preflight_id' => 'CODEX-REVIEW-MERGE-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => count($failedChecks) === 0 ? 'ready_for_explicit_merge_action_review' : 'blocked_before_explicit_merge_action_review',
            'blocking_count' => count($failedChecks),
            'checks' => $checks,
            'source_hashes' => [
                'execution_status_hash' => data_get($executionStatus, 'monitor_hash'),
                'integration_report_hash' => data_get($integrationReport, 'report_hash'),
                'merge_readiness_hash' => data_get($mergeReadiness, 'readiness_hash'),
                'final_review_packet_hash' => data_get($finalReviewPacket, 'packet_hash'),
                'signature_request_hash' => data_get($signatureRequest, 'request_hash'),
                'post_signature_runbook_hash' => data_get($postSignatureRunbook, 'runbook_hash'),
                'merge_action_template_hash' => data_get($mergeActionTemplate, 'template_hash'),
            ],
            'required_external_evidence_before_merge' => [
                'valid_signature_against_signable_payload_hash',
                'explicit_selected_decision_approve_for_merge',
                'human_merge_confirmation',
                'fresh_gate_outputs_after_signature',
                'clean_scope_integrity_result',
                'verified_evidence_integrity_result',
            ],
            'next_allowed_actions' => count($failedChecks) === 0
                ? [
                    'collect_external_signature_evidence',
                    'rerun_required_review_gates',
                    'prepare_separate_governed_merge_action',
                ]
                : [
                    'resolve_failed_preflight_checks',
                    'rerun_codex_review_merge_preflight',
                ],
            'still_forbidden' => [
                'auto_merge_from_preflight',
                'signature_validation_by_preflight',
                'approval_recording_by_preflight',
                'dispatch_from_preflight',
                'hot_voice_or_kernel_scope_changes',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_preflight.v1',
            'status' => count($failedChecks) === 0 ? 'merge_preflight_ready' : 'merge_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_preflight',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'codex_review_merge_preflight_does_not_claim_packets',
                'codex_review_merge_preflight_does_not_complete_packets',
                'codex_review_merge_preflight_does_not_validate_signature',
                'codex_review_merge_preflight_does_not_record_decision',
                'codex_review_merge_preflight_does_not_approve_code',
                'codex_review_merge_preflight_does_not_merge',
                'codex_review_merge_preflight_does_not_dispatch_work',
            ],
            'human_summary' => count($failedChecks) === 0
                ? 'Codex review merge preflight is ready for a separate explicit governed merge action. It still does not approve or merge.'
                : 'Codex review merge preflight is blocked until review-chain readiness checks pass.',
        ];
    }

    public function codexReviewMergeActionDraft(array $options = []): array
    {
        $preflightPayload = $this->parent->codexReviewMergePreflight($options);
        $preflight = (array) data_get($preflightPayload, 'preflight', []);
        $preflightReady = data_get($preflightPayload, 'status') === 'merge_preflight_ready';

        $draft = [
            'draft_id' => 'CODEX-REVIEW-MERGE-ACTION-DRAFT-SELF-CONSTRUCTION-0001',
            'status' => $preflightReady ? 'waiting_for_external_signature_and_human_confirmation' : 'blocked_before_merge_preflight',
            'source_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_hashes' => (array) data_get($preflight, 'source_hashes', []),
            'default_decision' => 'request_changes',
            'allowed_decisions' => [
                'merge',
                'request_changes',
                'abort',
            ],
            'required_external_evidence_before_merge' => (array) data_get($preflight, 'required_external_evidence_before_merge', []),
            'required_fields' => [
                'operator_name',
                'reviewed_at',
                'selected_decision',
                'decision_rationale',
                'signed_receipt_hash',
                'signable_payload_hash',
                'fresh_gate_outputs',
                'scope_integrity_result',
                'evidence_integrity_result',
                'files_to_merge',
                'human_merge_confirmation',
                'rollback_plan',
                'remaining_risks',
            ],
            'merge_action_guardrails' => [
                'selected_decision_must_be_merge',
                'signature_must_be_validated_by_external_governed_actor',
                'all_preflight_checks_must_still_pass',
                'fresh_gates_must_pass_after_signature',
                'scope_integrity_must_be_clean',
                'evidence_integrity_must_be_verified',
                'hot_voice_or_kernel_scope_must_remain_untouched',
            ],
            'prepared_command_sequence' => [
                'rerun_preflight' => 'php artisan atlas:ai:self-construction --codex-review-merge-preflight --json',
                'rerun_tests' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'diff_check' => 'git diff --check',
            ],
            'explicit_non_authority' => [
                'merge_action_draft_does_not_validate_signature',
                'merge_action_draft_does_not_record_decision',
                'merge_action_draft_does_not_grant_approval',
                'merge_action_draft_does_not_merge',
                'merge_action_draft_does_not_dispatch_work',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_action_draft.v1',
            'status' => $preflightReady ? 'merge_action_draft_ready' : 'merge_action_draft_blocked',
            'mode' => 'read_only_codex_review_merge_action_draft',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'draft' => $draft,
            'draft_hash' => ReadinessHash::stable($draft),
            'non_execution_guarantees' => [
                'codex_review_merge_action_draft_does_not_claim_packets',
                'codex_review_merge_action_draft_does_not_complete_packets',
                'codex_review_merge_action_draft_does_not_validate_signature',
                'codex_review_merge_action_draft_does_not_record_decision',
                'codex_review_merge_action_draft_does_not_approve_code',
                'codex_review_merge_action_draft_does_not_merge',
                'codex_review_merge_action_draft_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Codex review merge action draft is ready as a non-executing governed form. It still requires external signature validation and human confirmation.'
                : 'Codex review merge action draft is blocked until merge preflight is ready.',
        ];
    }

    public function codexReviewMergeReceiptDraft(array $options = []): array
    {
        $draftPayload = $this->parent->codexReviewMergeActionDraft($options);
        $draft = (array) data_get($draftPayload, 'draft', []);
        $draftReady = data_get($draftPayload, 'status') === 'merge_action_draft_ready';

        $receipt = [
            'receipt_id' => 'CODEX-REVIEW-MERGE-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'status' => $draftReady ? 'unsigned_receipt_ready' : 'blocked_before_merge_action_draft',
            'source_merge_action_draft_hash' => data_get($draftPayload, 'draft_hash'),
            'source_preflight_hash' => data_get($draft, 'source_preflight_hash'),
            'source_hashes' => (array) data_get($draft, 'source_hashes', []),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'default_decision' => 'request_changes',
            'allowed_decisions' => (array) data_get($draft, 'allowed_decisions', []),
            'required_signer_roles' => [
                'principal_integrator',
                'repository_owner_or_governed_delegate',
            ],
            'required_receipt_inputs' => [
                ...((array) data_get($draft, 'required_fields', [])),
                'merge_action_draft_hash',
                'receipt_signed_at',
                'receipt_signature',
            ],
            'approval_preconditions' => [
                ...((array) data_get($draft, 'required_external_evidence_before_merge', [])),
                ...((array) data_get($draft, 'merge_action_guardrails', [])),
            ],
            'verification_commands' => (array) data_get($draft, 'prepared_command_sequence', []),
            'non_authorizing_invariants' => [
                'merge_receipt_draft_is_unsigned',
                'merge_receipt_draft_does_not_validate_signature',
                'merge_receipt_draft_does_not_record_decision',
                'merge_receipt_draft_does_not_grant_approval',
                'merge_receipt_draft_does_not_merge',
                'merge_receipt_draft_does_not_dispatch_work',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_receipt_draft.v1',
            'status' => $draftReady ? 'merge_receipt_draft_ready' : 'merge_receipt_draft_blocked',
            'mode' => 'read_only_codex_review_merge_receipt_draft',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt' => $receipt,
            'receipt_hash' => ReadinessHash::stable($receipt),
            'non_execution_guarantees' => [
                'codex_review_merge_receipt_draft_does_not_claim_packets',
                'codex_review_merge_receipt_draft_does_not_complete_packets',
                'codex_review_merge_receipt_draft_does_not_validate_signature',
                'codex_review_merge_receipt_draft_does_not_record_decision',
                'codex_review_merge_receipt_draft_does_not_approve_code',
                'codex_review_merge_receipt_draft_does_not_merge',
                'codex_review_merge_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $draftReady
                ? 'Codex review merge receipt draft is ready as an unsigned non-authorizing receipt. It still requires external signature validation and explicit merge action.'
                : 'Codex review merge receipt draft is blocked until merge action draft is ready.',
        ];
    }

    public function codexReviewMergeSignatureRequest(array $options = []): array
    {
        $receiptPayload = $this->parent->codexReviewMergeReceiptDraft($options);
        $receipt = (array) data_get($receiptPayload, 'receipt', []);
        $receiptReady = data_get($receiptPayload, 'status') === 'merge_receipt_draft_ready';

        $signablePayload = [
            'signature_request_id' => 'CODEX-REVIEW-MERGE-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'receipt_id' => data_get($receipt, 'receipt_id'),
            'receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'source_merge_action_draft_hash' => data_get($receipt, 'source_merge_action_draft_hash'),
            'source_preflight_hash' => data_get($receipt, 'source_preflight_hash'),
            'source_hashes' => (array) data_get($receipt, 'source_hashes', []),
            'requested_signature_type' => 'human_or_governed_merge_receipt_signature',
            'allowed_decisions' => (array) data_get($receipt, 'allowed_decisions', []),
            'default_decision' => data_get($receipt, 'default_decision'),
            'required_signer_roles' => (array) data_get($receipt, 'required_signer_roles', []),
            'required_receipt_inputs' => (array) data_get($receipt, 'required_receipt_inputs', []),
            'approval_preconditions' => (array) data_get($receipt, 'approval_preconditions', []),
            'verification_commands' => (array) data_get($receipt, 'verification_commands', []),
            'still_forbidden_after_signature_request' => [
                'signature_validation_by_signature_request',
                'decision_recording_by_signature_request',
                'approval_from_signature_request',
                'merge_from_signature_request',
                'dispatch_from_signature_request',
            ],
        ];

        $signatureRequest = [
            'request_id' => 'CODEX-REVIEW-MERGE-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'status' => $receiptReady ? 'waiting_for_external_signature' : 'blocked_before_merge_receipt_draft',
            'source_receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'signable_payload_hash' => ReadinessHash::stable($signablePayload),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_signature_request.v1',
            'status' => $receiptReady ? 'merge_signature_request_pending' : 'merge_signature_request_blocked',
            'mode' => 'read_only_codex_review_merge_signature_request',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'signature_request' => $signatureRequest,
            'signable_payload' => $signablePayload,
            'signable_payload_hash' => ReadinessHash::stable($signablePayload),
            'request_hash' => ReadinessHash::stable($signatureRequest),
            'non_execution_guarantees' => [
                'codex_review_merge_signature_request_does_not_claim_packets',
                'codex_review_merge_signature_request_does_not_complete_packets',
                'codex_review_merge_signature_request_does_not_present_signature',
                'codex_review_merge_signature_request_does_not_validate_signature',
                'codex_review_merge_signature_request_does_not_record_decision',
                'codex_review_merge_signature_request_does_not_approve_code',
                'codex_review_merge_signature_request_does_not_merge',
                'codex_review_merge_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Codex review merge signature request is pending as a signable payload. It does not present, validate, approve or merge.'
                : 'Codex review merge signature request is blocked until merge receipt draft is ready.',
        ];
    }

    public function codexReviewMergePostSignatureRunbook(array $options = []): array
    {
        $signaturePayload = $this->parent->codexReviewMergeSignatureRequest($options);
        $runbookReady = data_get($signaturePayload, 'status') === 'merge_signature_request_pending';

        $runbook = [
            'runbook_id' => 'CODEX-REVIEW-MERGE-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'status' => $runbookReady ? 'waiting_for_external_valid_signature_evidence' : 'blocked_before_merge_signature_request',
            'source_signature_request_hash' => data_get($signaturePayload, 'request_hash'),
            'source_signable_payload_hash' => data_get($signaturePayload, 'signable_payload_hash'),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'required_external_inputs' => [
                'external_signature_value',
                'external_signature_validator_identity',
                'external_signature_validation_timestamp',
                'validated_signable_payload_hash',
                'validated_receipt_hash',
                'selected_decision',
                'decision_rationale',
                'fresh_test_output',
                'fresh_docs_health_output',
                'fresh_architecture_validate_output',
                'fresh_diff_check_output',
                'scope_integrity_statement',
                'evidence_integrity_statement',
                'rollback_plan',
                'remaining_risks',
            ],
            'ordered_steps_after_external_signature' => [
                'verify_external_signature_was_validated_outside_this_command',
                'confirm_validated_signable_payload_hash_matches_source',
                'confirm_validated_receipt_hash_matches_source_receipt',
                'rerun_merge_preflight',
                'rerun_fresh_tests_and_quality_gates',
                'verify_scope_integrity_and_hot_scope_exclusions',
                'verify_evidence_integrity_and_completed_packet_hashes',
                'prepare_explicit_merge_action_with_human_confirmation',
                'record_merge_receipt_only_in_the_future_authorizing_surface',
            ],
            'blocking_conditions' => [
                'missing_external_signature',
                'signature_not_validated_by_governed_actor',
                'signable_payload_hash_mismatch',
                'receipt_hash_mismatch',
                'selected_decision_is_not_merge',
                'fresh_gate_failure',
                'scope_integrity_failure',
                'evidence_integrity_failure',
                'hot_voice_or_kernel_scope_touched',
                'missing_human_merge_confirmation',
            ],
            'verification_commands' => [
                'merge_signature_request' => 'php artisan atlas:ai:self-construction --codex-review-merge-signature-request --json',
                'merge_preflight' => 'php artisan atlas:ai:self-construction --codex-review-merge-preflight --json',
                'focused_tests' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'diff_check' => 'git diff --check',
            ],
            'still_forbidden_after_runbook' => [
                'signature_validation_by_runbook',
                'approval_from_runbook',
                'decision_recording_by_runbook',
                'merge_from_runbook',
                'dispatch_from_runbook',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_post_signature_runbook.v1',
            'status' => $runbookReady ? 'merge_post_signature_runbook_ready' : 'merge_post_signature_runbook_blocked',
            'mode' => 'read_only_codex_review_merge_post_signature_runbook',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'runbook' => $runbook,
            'runbook_hash' => ReadinessHash::stable($runbook),
            'non_execution_guarantees' => [
                'codex_review_merge_post_signature_runbook_does_not_claim_packets',
                'codex_review_merge_post_signature_runbook_does_not_complete_packets',
                'codex_review_merge_post_signature_runbook_does_not_present_signature',
                'codex_review_merge_post_signature_runbook_does_not_validate_signature',
                'codex_review_merge_post_signature_runbook_does_not_record_decision',
                'codex_review_merge_post_signature_runbook_does_not_approve_code',
                'codex_review_merge_post_signature_runbook_does_not_merge',
                'codex_review_merge_post_signature_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Codex review merge post-signature runbook is ready as a non-executing checklist. It still requires external signature evidence and explicit merge action.'
                : 'Codex review merge post-signature runbook is blocked until merge signature request is pending.',
        ];
    }

    public function codexReviewMergeExecutionChecklist(array $options = []): array
    {
        $runbookPayload = $this->parent->codexReviewMergePostSignatureRunbook($options);
        $runbook = (array) data_get($runbookPayload, 'runbook', []);
        $checklistReady = data_get($runbookPayload, 'status') === 'merge_post_signature_runbook_ready';

        $checklist = [
            'checklist_id' => 'CODEX-REVIEW-MERGE-EXECUTION-CHECKLIST-SELF-CONSTRUCTION-0001',
            'status' => $checklistReady ? 'ready_for_future_authorizing_surface' : 'blocked_before_post_signature_runbook',
            'source_post_signature_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_signature_request_hash' => data_get($runbook, 'source_signature_request_hash'),
            'source_signable_payload_hash' => data_get($runbook, 'source_signable_payload_hash'),
            'external_authorization_required' => true,
            'signature_valid' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'required_authorizing_surface_inputs' => [
                ...((array) data_get($runbook, 'required_external_inputs', [])),
                'explicit_merge_authorization_receipt_id',
                'repository_state_before_merge',
                'files_changed_summary',
                'human_final_confirmation',
                'post_merge_verification_plan',
            ],
            'required_pre_execution_checks' => [
                'all_five_packets_completed',
                'no_active_packet_claims',
                'external_signature_validated_outside_checklist',
                'selected_decision_is_merge',
                'fresh_gate_outputs_pass',
                'scope_integrity_passes',
                'evidence_integrity_passes',
                'hot_voice_or_kernel_scope_not_touched',
                'rollback_plan_present',
                'human_final_confirmation_present',
            ],
            'future_authorizing_surface_must_record' => [
                'validated_signature_reference',
                'selected_decision',
                'decision_rationale',
                'gate_output_hashes',
                'scope_integrity_hash',
                'evidence_integrity_hash',
                'merge_operator',
                'merge_timestamp',
                'rollback_plan_hash',
                'post_merge_verification_hash',
            ],
            'forbidden_until_future_authorizing_surface' => [
                'merge_execution',
                'decision_recording',
                'approval_recording',
                'signature_validation',
                'ledger_mutation',
                'packet_dispatch',
            ],
            'verification_commands' => [
                'post_signature_runbook' => 'php artisan atlas:ai:self-construction --codex-review-merge-post-signature-runbook --json',
                'merge_preflight' => 'php artisan atlas:ai:self-construction --codex-review-merge-preflight --json',
                'reservation_status' => 'php artisan atlas:ai:self-construction --reservation-status --json',
                'focused_tests' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'diff_check' => 'git diff --check',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_execution_checklist.v1',
            'status' => $checklistReady ? 'merge_execution_checklist_ready' : 'merge_execution_checklist_blocked',
            'mode' => 'read_only_codex_review_merge_execution_checklist',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'checklist' => $checklist,
            'checklist_hash' => ReadinessHash::stable($checklist),
            'non_execution_guarantees' => [
                'codex_review_merge_execution_checklist_does_not_claim_packets',
                'codex_review_merge_execution_checklist_does_not_complete_packets',
                'codex_review_merge_execution_checklist_does_not_validate_signature',
                'codex_review_merge_execution_checklist_does_not_record_decision',
                'codex_review_merge_execution_checklist_does_not_approve_code',
                'codex_review_merge_execution_checklist_does_not_merge',
                'codex_review_merge_execution_checklist_does_not_dispatch_work',
            ],
            'human_summary' => $checklistReady
                ? 'Codex review merge execution checklist is ready for a future authorizing surface. It still does not validate, approve or merge.'
                : 'Codex review merge execution checklist is blocked until the post-signature runbook is ready.',
        ];
    }

    public function codexReviewMergeAuthorizationTemplate(array $options = []): array
    {
        $checklistPayload = $this->parent->codexReviewMergeExecutionChecklist($options);
        $checklist = (array) data_get($checklistPayload, 'checklist', []);
        $templateReady = data_get($checklistPayload, 'status') === 'merge_execution_checklist_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-AUTHORIZATION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'ready_for_external_authorization_values' : 'blocked_before_execution_checklist',
            'source_execution_checklist_hash' => data_get($checklistPayload, 'checklist_hash'),
            'source_post_signature_runbook_hash' => data_get($checklist, 'source_post_signature_runbook_hash'),
            'source_signature_request_hash' => data_get($checklist, 'source_signature_request_hash'),
            'source_signable_payload_hash' => data_get($checklist, 'source_signable_payload_hash'),
            'default_decision' => 'request_changes',
            'allowed_decisions' => [
                'merge',
                'request_changes',
                'abort',
            ],
            'required_authorization_fields' => [
                ...((array) data_get($checklist, 'required_authorizing_surface_inputs', [])),
                ...((array) data_get($checklist, 'future_authorizing_surface_must_record', [])),
                'authorization_decision',
                'authorization_rationale',
                'authorization_signed_at',
                'authorization_signature_reference',
            ],
            'required_preconditions' => (array) data_get($checklist, 'required_pre_execution_checks', []),
            'must_record' => (array) data_get($checklist, 'future_authorizing_surface_must_record', []),
            'rejection_defaults' => [
                'missing_signature_evidence' => 'request_changes',
                'hash_mismatch' => 'abort',
                'fresh_gate_failure' => 'request_changes',
                'scope_or_evidence_integrity_failure' => 'abort',
                'hot_scope_touched' => 'abort',
                'missing_human_confirmation' => 'request_changes',
            ],
            'future_authorizing_surface_contract' => [
                'may_validate_external_signature' => true,
                'may_record_decision' => true,
                'may_grant_merge_approval' => true,
                'may_merge_after_all_preconditions_pass' => true,
                'must_be_a_different_surface' => true,
            ],
            'still_forbidden_here' => [
                'signature_acceptance_by_template',
                'signature_validation_by_template',
                'decision_recording_by_template',
                'approval_from_template',
                'merge_from_template',
                'dispatch_from_template',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_authorization_template.v1',
            'status' => $templateReady ? 'merge_authorization_template_ready' : 'merge_authorization_template_blocked',
            'mode' => 'read_only_codex_review_merge_authorization_template',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'codex_review_merge_authorization_template_does_not_claim_packets',
                'codex_review_merge_authorization_template_does_not_complete_packets',
                'codex_review_merge_authorization_template_does_not_accept_signature',
                'codex_review_merge_authorization_template_does_not_validate_signature',
                'codex_review_merge_authorization_template_does_not_record_decision',
                'codex_review_merge_authorization_template_does_not_approve_code',
                'codex_review_merge_authorization_template_does_not_merge',
                'codex_review_merge_authorization_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review merge authorization template is ready for a future external authorizing surface. It still does not accept signatures, approve or merge.'
                : 'Codex review merge authorization template is blocked until execution checklist is ready.',
        ];
    }

    public function codexReviewMergeAuthorizationReceiptDraft(array $options = []): array
    {
        $templatePayload = $this->parent->codexReviewMergeAuthorizationTemplate($options);
        $template = (array) data_get($templatePayload, 'template', []);
        $receiptReady = data_get($templatePayload, 'status') === 'merge_authorization_template_ready';

        $receipt = [
            'receipt_id' => 'CODEX-REVIEW-MERGE-AUTHORIZATION-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'status' => $receiptReady ? 'unsigned_authorization_receipt_ready' : 'blocked_before_authorization_template',
            'source_authorization_template_hash' => data_get($templatePayload, 'template_hash'),
            'source_execution_checklist_hash' => data_get($template, 'source_execution_checklist_hash'),
            'source_post_signature_runbook_hash' => data_get($template, 'source_post_signature_runbook_hash'),
            'source_signature_request_hash' => data_get($template, 'source_signature_request_hash'),
            'source_signable_payload_hash' => data_get($template, 'source_signable_payload_hash'),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'default_decision' => data_get($template, 'default_decision'),
            'allowed_decisions' => (array) data_get($template, 'allowed_decisions', []),
            'required_authorization_fields' => (array) data_get($template, 'required_authorization_fields', []),
            'required_preconditions' => (array) data_get($template, 'required_preconditions', []),
            'required_receipt_signers' => [
                'principal_integrator',
                'repository_owner_or_governed_delegate',
            ],
            'must_record' => [
                ...((array) data_get($template, 'must_record', [])),
                'authorization_template_hash',
                'authorization_receipt_signed_at',
                'authorization_receipt_signature',
            ],
            'rejection_defaults' => (array) data_get($template, 'rejection_defaults', []),
            'non_authorizing_invariants' => [
                'authorization_receipt_draft_is_unsigned',
                'authorization_receipt_draft_does_not_accept_signature',
                'authorization_receipt_draft_does_not_validate_signature',
                'authorization_receipt_draft_does_not_record_decision',
                'authorization_receipt_draft_does_not_grant_approval',
                'authorization_receipt_draft_does_not_merge',
                'authorization_receipt_draft_does_not_dispatch_work',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_authorization_receipt_draft.v1',
            'status' => $receiptReady ? 'merge_authorization_receipt_draft_ready' : 'merge_authorization_receipt_draft_blocked',
            'mode' => 'read_only_codex_review_merge_authorization_receipt_draft',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt' => $receipt,
            'receipt_hash' => ReadinessHash::stable($receipt),
            'non_execution_guarantees' => [
                'codex_review_merge_authorization_receipt_draft_does_not_claim_packets',
                'codex_review_merge_authorization_receipt_draft_does_not_complete_packets',
                'codex_review_merge_authorization_receipt_draft_does_not_accept_signature',
                'codex_review_merge_authorization_receipt_draft_does_not_validate_signature',
                'codex_review_merge_authorization_receipt_draft_does_not_record_decision',
                'codex_review_merge_authorization_receipt_draft_does_not_approve_code',
                'codex_review_merge_authorization_receipt_draft_does_not_merge',
                'codex_review_merge_authorization_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $receiptReady
                ? 'Codex review merge authorization receipt draft is ready as an unsigned non-authorizing receipt. It still does not accept signatures, approve or merge.'
                : 'Codex review merge authorization receipt draft is blocked until authorization template is ready.',
        ];
    }

    public function codexReviewMergeAuthorizationSignatureRequest(array $options = []): array
    {
        $receiptPayload = $this->parent->codexReviewMergeAuthorizationReceiptDraft($options);
        $receipt = (array) data_get($receiptPayload, 'receipt', []);
        $requestReady = data_get($receiptPayload, 'status') === 'merge_authorization_receipt_draft_ready';

        $signablePayload = [
            'signature_request_id' => 'CODEX-REVIEW-MERGE-AUTHORIZATION-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'receipt_id' => data_get($receipt, 'receipt_id'),
            'receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'source_authorization_template_hash' => data_get($receipt, 'source_authorization_template_hash'),
            'source_execution_checklist_hash' => data_get($receipt, 'source_execution_checklist_hash'),
            'source_post_signature_runbook_hash' => data_get($receipt, 'source_post_signature_runbook_hash'),
            'source_signature_request_hash' => data_get($receipt, 'source_signature_request_hash'),
            'source_signable_payload_hash' => data_get($receipt, 'source_signable_payload_hash'),
            'requested_signature_type' => 'human_or_governed_merge_authorization_receipt_signature',
            'allowed_decisions' => (array) data_get($receipt, 'allowed_decisions', []),
            'default_decision' => data_get($receipt, 'default_decision'),
            'required_authorization_fields' => (array) data_get($receipt, 'required_authorization_fields', []),
            'required_preconditions' => (array) data_get($receipt, 'required_preconditions', []),
            'required_receipt_signers' => (array) data_get($receipt, 'required_receipt_signers', []),
            'must_record' => (array) data_get($receipt, 'must_record', []),
            'rejection_defaults' => (array) data_get($receipt, 'rejection_defaults', []),
            'still_forbidden_after_signature_request' => [
                'signature_acceptance_by_authorization_signature_request',
                'signature_validation_by_authorization_signature_request',
                'decision_recording_by_authorization_signature_request',
                'approval_from_authorization_signature_request',
                'merge_from_authorization_signature_request',
                'dispatch_from_authorization_signature_request',
            ],
        ];

        $signatureRequest = [
            'request_id' => 'CODEX-REVIEW-MERGE-AUTHORIZATION-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'status' => $requestReady ? 'waiting_for_external_authorization_signature' : 'blocked_before_authorization_receipt_draft',
            'source_authorization_receipt_hash' => data_get($receiptPayload, 'receipt_hash'),
            'signable_payload_hash' => ReadinessHash::stable($signablePayload),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_authorization_signature_request.v1',
            'status' => $requestReady ? 'merge_authorization_signature_request_pending' : 'merge_authorization_signature_request_blocked',
            'mode' => 'read_only_codex_review_merge_authorization_signature_request',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'signature_request' => $signatureRequest,
            'signable_payload' => $signablePayload,
            'signable_payload_hash' => ReadinessHash::stable($signablePayload),
            'request_hash' => ReadinessHash::stable($signatureRequest),
            'non_execution_guarantees' => [
                'codex_review_merge_authorization_signature_request_does_not_claim_packets',
                'codex_review_merge_authorization_signature_request_does_not_complete_packets',
                'codex_review_merge_authorization_signature_request_does_not_accept_signature',
                'codex_review_merge_authorization_signature_request_does_not_validate_signature',
                'codex_review_merge_authorization_signature_request_does_not_record_decision',
                'codex_review_merge_authorization_signature_request_does_not_approve_code',
                'codex_review_merge_authorization_signature_request_does_not_merge',
                'codex_review_merge_authorization_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $requestReady
                ? 'Codex review merge authorization signature request is pending as a signable payload. It still does not accept, validate, approve or merge.'
                : 'Codex review merge authorization signature request is blocked until authorization receipt draft is ready.',
        ];
    }

    public function codexReviewMergeAuthorizationPostSignatureRunbook(array $options = []): array
    {
        $signaturePayload = $this->parent->codexReviewMergeAuthorizationSignatureRequest($options);
        $runbookReady = data_get($signaturePayload, 'status') === 'merge_authorization_signature_request_pending';

        $runbook = [
            'runbook_id' => 'CODEX-REVIEW-MERGE-AUTHORIZATION-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'status' => $runbookReady ? 'waiting_for_external_authorization_signature_evidence' : 'blocked_before_authorization_signature_request',
            'source_authorization_signature_request_hash' => data_get($signaturePayload, 'request_hash'),
            'source_authorization_signable_payload_hash' => data_get($signaturePayload, 'signable_payload_hash'),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'required_external_inputs' => [
                'external_authorization_signature_value',
                'external_authorization_signature_validator_identity',
                'external_authorization_signature_validation_timestamp',
                'validated_authorization_signable_payload_hash',
                'validated_authorization_receipt_hash',
                'selected_decision',
                'decision_rationale',
                'fresh_test_output',
                'fresh_docs_health_output',
                'fresh_architecture_validate_output',
                'fresh_diff_check_output',
                'scope_integrity_statement',
                'evidence_integrity_statement',
                'rollback_plan',
                'human_final_merge_confirmation',
            ],
            'ordered_steps_after_external_authorization_signature' => [
                'verify_external_authorization_signature_was_validated_outside_this_command',
                'confirm_validated_authorization_signable_payload_hash_matches_source',
                'confirm_validated_authorization_receipt_hash_matches_source_receipt',
                'rerun_merge_preflight',
                'rerun_fresh_tests_and_quality_gates',
                'verify_scope_integrity_and_hot_scope_exclusions',
                'verify_evidence_integrity_and_completed_packet_hashes',
                'prepare_separate_authorizing_merge_surface_with_human_confirmation',
            ],
            'blocking_conditions' => [
                'missing_external_authorization_signature',
                'authorization_signature_not_validated_by_governed_actor',
                'authorization_signable_payload_hash_mismatch',
                'authorization_receipt_hash_mismatch',
                'selected_decision_is_not_merge',
                'fresh_gate_failure',
                'scope_integrity_failure',
                'evidence_integrity_failure',
                'hot_voice_or_kernel_scope_touched',
                'missing_human_final_merge_confirmation',
            ],
            'verification_commands' => [
                'authorization_signature_request' => 'php artisan atlas:ai:self-construction --codex-review-merge-authorization-signature-request --json',
                'merge_preflight' => 'php artisan atlas:ai:self-construction --codex-review-merge-preflight --json',
                'reservation_status' => 'php artisan atlas:ai:self-construction --reservation-status --json',
                'focused_tests' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'diff_check' => 'git diff --check',
            ],
            'still_forbidden_after_runbook' => [
                'signature_validation_by_authorization_post_signature_runbook',
                'decision_recording_by_authorization_post_signature_runbook',
                'approval_from_authorization_post_signature_runbook',
                'merge_from_authorization_post_signature_runbook',
                'dispatch_from_authorization_post_signature_runbook',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_authorization_post_signature_runbook.v1',
            'status' => $runbookReady ? 'merge_authorization_post_signature_runbook_ready' : 'merge_authorization_post_signature_runbook_blocked',
            'mode' => 'read_only_codex_review_merge_authorization_post_signature_runbook',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'runbook' => $runbook,
            'runbook_hash' => ReadinessHash::stable($runbook),
            'non_execution_guarantees' => [
                'codex_review_merge_authorization_post_signature_runbook_does_not_claim_packets',
                'codex_review_merge_authorization_post_signature_runbook_does_not_complete_packets',
                'codex_review_merge_authorization_post_signature_runbook_does_not_accept_signature',
                'codex_review_merge_authorization_post_signature_runbook_does_not_validate_signature',
                'codex_review_merge_authorization_post_signature_runbook_does_not_record_decision',
                'codex_review_merge_authorization_post_signature_runbook_does_not_approve_code',
                'codex_review_merge_authorization_post_signature_runbook_does_not_merge',
                'codex_review_merge_authorization_post_signature_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $runbookReady
                ? 'Codex review merge authorization post-signature runbook is ready as a non-executing checklist. It still does not validate, approve or merge.'
                : 'Codex review merge authorization post-signature runbook is blocked until authorization signature request is pending.',
        ];
    }

    public function codexReviewMergeFinalAuthorizationPreflight(array $options = []): array
    {
        $runbookPayload = $this->parent->codexReviewMergeAuthorizationPostSignatureRunbook($options);
        $preflightReady = data_get($runbookPayload, 'status') === 'merge_authorization_post_signature_runbook_ready';

        $preflight = [
            'preflight_id' => 'CODEX-REVIEW-MERGE-FINAL-AUTHORIZATION-PREFLIGHT-SELF-CONSTRUCTION-0001',
            'status' => $preflightReady ? 'waiting_for_external_final_authorization_evidence' : 'blocked_before_authorization_post_signature_runbook',
            'source_authorization_post_signature_runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'source_authorization_signature_request_hash' => data_get($runbookPayload, 'runbook.source_authorization_signature_request_hash'),
            'source_authorization_signable_payload_hash' => data_get($runbookPayload, 'runbook.source_authorization_signable_payload_hash'),
            'authorization_ready' => false,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'required_external_evidence' => [
                'validated_authorization_signature_evidence',
                'validated_authorization_signable_payload_hash',
                'validated_authorization_receipt_hash',
                'selected_decision_equals_merge',
                'decision_rationale',
                'fresh_merge_preflight_payload',
                'fresh_merge_preflight_hash',
                'fresh_test_output',
                'fresh_docs_health_output',
                'fresh_architecture_validate_output',
                'fresh_diff_check_output',
                'scope_integrity_statement',
                'hot_scope_exclusion_statement',
                'completed_packet_evidence_integrity_statement',
                'rollback_plan',
                'human_final_merge_confirmation',
            ],
            'required_preflight_checks' => [
                'authorization_signature_was_validated_by_external_governed_actor',
                'validated_authorization_hashes_match_source_hashes',
                'decision_is_explicitly_merge',
                'fresh_merge_preflight_is_ready',
                'fresh_tests_pass',
                'docs_health_passes',
                'architecture_validation_passes',
                'git_diff_check_passes',
                'scope_excludes_hot_voice_and_kernel_files',
                'completed_packet_hashes_are_present_and_stable',
                'rollback_plan_is_present',
                'human_final_merge_confirmation_is_present',
            ],
            'future_authorizing_surface_requirements' => [
                'must_be_separate_command_or_endpoint',
                'must_accept_explicit_external_signature_evidence',
                'must_validate_signature_against_signable_payload_hash',
                'must_persist_authorization_receipt_append_only',
                'must_rerun_or_reference_fresh_quality_gates',
                'must_require_human_final_merge_confirmation',
                'must_emit_final_merge_receipt_before_execution',
                'must_preserve_rollback_plan',
            ],
            'blocking_conditions' => [
                'authorization_post_signature_runbook_not_ready',
                'missing_external_final_authorization_evidence',
                'invalid_or_unverified_authorization_signature',
                'authorization_hash_mismatch',
                'selected_decision_not_merge',
                'fresh_merge_preflight_not_ready',
                'fresh_gate_failure',
                'scope_integrity_failure',
                'hot_voice_or_kernel_scope_touched',
                'packet_evidence_integrity_failure',
                'missing_rollback_plan',
                'missing_human_final_merge_confirmation',
            ],
            'still_forbidden_after_preflight' => [
                'signature_validation_by_final_authorization_preflight',
                'decision_recording_by_final_authorization_preflight',
                'approval_from_final_authorization_preflight',
                'merge_from_final_authorization_preflight',
                'dispatch_from_final_authorization_preflight',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_final_authorization_preflight.v1',
            'status' => $preflightReady ? 'merge_final_authorization_preflight_ready' : 'merge_final_authorization_preflight_blocked',
            'mode' => 'read_only_codex_review_merge_final_authorization_preflight',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'authorization_ready' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'preflight' => $preflight,
            'preflight_hash' => ReadinessHash::stable($preflight),
            'non_execution_guarantees' => [
                'codex_review_merge_final_authorization_preflight_does_not_claim_packets',
                'codex_review_merge_final_authorization_preflight_does_not_complete_packets',
                'codex_review_merge_final_authorization_preflight_does_not_accept_signature',
                'codex_review_merge_final_authorization_preflight_does_not_validate_signature',
                'codex_review_merge_final_authorization_preflight_does_not_record_decision',
                'codex_review_merge_final_authorization_preflight_does_not_approve_code',
                'codex_review_merge_final_authorization_preflight_does_not_merge',
                'codex_review_merge_final_authorization_preflight_does_not_dispatch_work',
            ],
            'human_summary' => $preflightReady
                ? 'Codex review merge final authorization preflight is ready as a non-authorizing evidence contract. It still does not validate, approve or merge.'
                : 'Codex review merge final authorization preflight is blocked until authorization post-signature runbook is ready.',
        ];
    }

    public function codexReviewMergeAuthorizingActionTemplate(array $options = []): array
    {
        $preflightPayload = $this->parent->codexReviewMergeFinalAuthorizationPreflight($options);
        $templateReady = data_get($preflightPayload, 'status') === 'merge_final_authorization_preflight_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-MERGE-AUTHORIZING-ACTION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'waiting_for_external_authorizing_action_evidence' : 'blocked_before_final_authorization_preflight',
            'source_final_authorization_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_authorization_post_signature_runbook_hash' => data_get($preflightPayload, 'preflight.source_authorization_post_signature_runbook_hash'),
            'source_authorization_signature_request_hash' => data_get($preflightPayload, 'preflight.source_authorization_signature_request_hash'),
            'source_authorization_signable_payload_hash' => data_get($preflightPayload, 'preflight.source_authorization_signable_payload_hash'),
            'default_decision' => 'request_changes',
            'allowed_decisions' => [
                'merge',
                'request_changes',
                'abort',
            ],
            'required_inputs_for_future_authorizing_action' => [
                'external_authorization_signature_value',
                'external_authorization_signature_validator_identity',
                'external_authorization_signature_validation_timestamp',
                'validated_authorization_signable_payload_hash',
                'validated_authorization_receipt_hash',
                'selected_decision',
                'decision_rationale',
                'fresh_merge_preflight_hash',
                'fresh_test_output_hash',
                'fresh_docs_health_output_hash',
                'fresh_architecture_validate_output_hash',
                'fresh_diff_check_output_hash',
                'scope_integrity_statement',
                'hot_scope_exclusion_statement',
                'completed_packet_evidence_integrity_statement',
                'rollback_plan',
                'human_final_merge_confirmation',
            ],
            'required_action_validations' => [
                'selected_decision_must_equal_merge',
                'signature_must_validate_against_source_authorization_signable_payload_hash',
                'authorization_receipt_hash_must_match_source_chain',
                'fresh_merge_preflight_hash_must_be_bound',
                'fresh_tests_must_pass',
                'docs_health_must_pass',
                'architecture_validate_must_pass',
                'diff_check_must_pass',
                'scope_must_exclude_hot_voice_and_kernel_files',
                'packet_evidence_must_match_completed_packet_hashes',
                'rollback_plan_must_be_present',
                'human_final_merge_confirmation_must_be_explicit',
            ],
            'receipt_fields_to_persist_in_future' => [
                'authorizing_action_id',
                'source_final_authorization_preflight_hash',
                'selected_decision',
                'decision_rationale',
                'validated_signature_hash',
                'validated_authorization_receipt_hash',
                'fresh_gate_hashes',
                'scope_integrity_result',
                'evidence_integrity_result',
                'rollback_plan_hash',
                'human_confirmation_hash',
                'authorized_by',
                'authorized_at',
            ],
            'future_execution_boundary' => [
                'authorizing_action_may_record_decision_after_validation',
                'authorizing_action_may_emit_final_merge_receipt_after_validation',
                'authorizing_action_must_not_apply_patch_or_merge_directly',
                'separate_executor_must_consume_final_merge_receipt',
                'executor_must_rerun_last_minute_diff_and_scope_checks',
            ],
            'still_forbidden_by_template' => [
                'signature_acceptance_by_authorizing_action_template',
                'signature_validation_by_authorizing_action_template',
                'decision_recording_by_authorizing_action_template',
                'approval_from_authorizing_action_template',
                'merge_from_authorizing_action_template',
                'dispatch_from_authorizing_action_template',
            ],
            'authorization_ready' => false,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_authorizing_action_template.v1',
            'status' => $templateReady ? 'merge_authorizing_action_template_ready' : 'merge_authorizing_action_template_blocked',
            'mode' => 'read_only_codex_review_merge_authorizing_action_template',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'authorization_ready' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'codex_review_merge_authorizing_action_template_does_not_claim_packets',
                'codex_review_merge_authorizing_action_template_does_not_complete_packets',
                'codex_review_merge_authorizing_action_template_does_not_accept_signature',
                'codex_review_merge_authorizing_action_template_does_not_validate_signature',
                'codex_review_merge_authorizing_action_template_does_not_record_decision',
                'codex_review_merge_authorizing_action_template_does_not_approve_code',
                'codex_review_merge_authorizing_action_template_does_not_merge',
                'codex_review_merge_authorizing_action_template_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review merge authorizing action template is ready as a non-authorizing contract for a future separate action. It still does not accept evidence, validate, approve or merge.'
                : 'Codex review merge authorizing action template is blocked until final authorization preflight is ready.',
        ];
    }

    public function codexReviewMergeFinalReceiptDraft(array $options = []): array
    {
        $templatePayload = $this->parent->codexReviewMergeAuthorizingActionTemplate($options);
        $templateReady = data_get($templatePayload, 'status') === 'merge_authorizing_action_template_ready';

        $receipt = [
            'receipt_id' => 'CODEX-REVIEW-MERGE-FINAL-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'status' => $templateReady ? 'waiting_for_external_authorizing_action_receipt_evidence' : 'blocked_before_authorizing_action_template',
            'source_authorizing_action_template_hash' => data_get($templatePayload, 'template_hash'),
            'source_final_authorization_preflight_hash' => data_get($templatePayload, 'template.source_final_authorization_preflight_hash'),
            'source_authorization_signature_request_hash' => data_get($templatePayload, 'template.source_authorization_signature_request_hash'),
            'source_authorization_signable_payload_hash' => data_get($templatePayload, 'template.source_authorization_signable_payload_hash'),
            'default_decision' => 'request_changes',
            'allowed_decisions' => [
                'merge',
                'request_changes',
                'abort',
            ],
            'drafted_authorization_fields' => [
                'authorizing_action_id',
                'selected_decision',
                'decision_rationale',
                'validated_signature_hash',
                'validated_authorization_receipt_hash',
                'fresh_gate_hashes',
                'scope_integrity_result',
                'evidence_integrity_result',
                'rollback_plan_hash',
                'human_confirmation_hash',
                'authorized_by',
                'authorized_at',
            ],
            'required_before_final_receipt_can_be_signed' => [
                'external_authorization_signature_validated',
                'selected_decision_equals_merge',
                'source_hashes_match',
                'fresh_gate_hashes_present',
                'scope_integrity_passed',
                'hot_scope_exclusion_passed',
                'packet_evidence_integrity_passed',
                'rollback_plan_hash_present',
                'human_confirmation_hash_present',
            ],
            'future_executor_contract' => [
                'executor_must_consume_signed_final_merge_receipt',
                'executor_must_verify_final_receipt_hash',
                'executor_must_rerun_last_minute_diff_check',
                'executor_must_rerun_hot_scope_check',
                'executor_must_emit_execution_evidence',
                'executor_must_not_run_without_signed_final_receipt',
            ],
            'still_forbidden_by_receipt_draft' => [
                'signature_acceptance_by_final_receipt_draft',
                'signature_validation_by_final_receipt_draft',
                'decision_recording_by_final_receipt_draft',
                'approval_from_final_receipt_draft',
                'merge_from_final_receipt_draft',
                'dispatch_from_final_receipt_draft',
            ],
            'authorization_ready' => false,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'receipt_signed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_merge_final_receipt_draft.v1',
            'status' => $templateReady ? 'merge_final_receipt_draft_ready' : 'merge_final_receipt_draft_blocked',
            'mode' => 'read_only_codex_review_merge_final_receipt_draft',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'authorization_ready' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt' => $receipt,
            'receipt_hash' => ReadinessHash::stable($receipt),
            'non_execution_guarantees' => [
                'codex_review_merge_final_receipt_draft_does_not_claim_packets',
                'codex_review_merge_final_receipt_draft_does_not_complete_packets',
                'codex_review_merge_final_receipt_draft_does_not_accept_signature',
                'codex_review_merge_final_receipt_draft_does_not_validate_signature',
                'codex_review_merge_final_receipt_draft_does_not_record_decision',
                'codex_review_merge_final_receipt_draft_does_not_approve_code',
                'codex_review_merge_final_receipt_draft_does_not_merge',
                'codex_review_merge_final_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review merge final receipt draft is ready as an unsigned, non-authorizing receipt shell. It still does not validate, approve or merge.'
                : 'Codex review merge final receipt draft is blocked until authorizing action template is ready.',
        ];
    }
}
