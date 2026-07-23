<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Codex Merge Authorization Contract — pure, deterministic, non-authorizing
 * gate logic for the six FINAL pre-authorizing surfaces.
 *
 * This contract starts after the post-signature merge runbook of the action
 * draft contract and defines only the final pre-authorizing surfaces. None of
 * these surfaces may validate a signature, approve, record a decision, dispatch
 * work or merge. Every method here keeps the hard boundary verbatim.
 *
 * Documented dependency chain (each surface gated by the previous):
 *
 *   1. mergeExecutionChecklist()         — binds the 8 final prerequisites a
 *      future authorizing surface must satisfy and the 6 fields it must record;
 *      keeps signature_valid / approval_granted / merge_allowed false.
 *
 *   2. mergeAuthorizationTemplate()      — ready ONLY after the checklist is
 *      ready; defines fields, allowed decisions and the conservative default.
 *      Default is request_changes; on hash mismatch, evidence failure or hot
 *      scope change it must default to abort.
 *
 *   3. mergeAuthorizationReceiptDraft()  — ready ONLY after the template is
 *      ready; binds template/checklist/runbook/request/payload hashes into an
 *      unsigned receipt shell; keeps signature_present / signature_valid /
 *      decision_recorded / approval_granted / merge_allowed false.
 *
 *   4. mergeAuthorizationSignatureRequest() — becomes pending ONLY after the
 *      receipt draft is ready; prepares a signable payload but accepts /
 *      infers / validates / applies no signature; same five flags stay false.
 *
 *   5. mergeAuthorizationPostSignatureRunbook() — ready ONLY after the
 *      signature request is pending; sequences (does not execute) the future
 *      verification/preflight/gates; keeps signature_valid / decision_recorded
 *      / approval_granted / merge_allowed false.
 *
 *   6. mergeFinalAuthorizationPreflight() — ready ONLY after the runbook is
 *      ready; binds the final non-authorizing evidence contract and asserts the
 *      future authorizing surface will be separate; keeps authorization_ready /
 *      signature_present / signature_valid / decision_recorded /
 *      approval_granted / merge_allowed false.
 *
 * Invariants this code ENFORCES (not merely documents):
 *   - "must keep ... =false" => boundary() / signatureBoundary() /
 *     preflightBoundary() are all-false and every surface embeds them verbatim;
 *     assertBoundaryHeld() proves no surface ever flipped a guarded key true.
 *   - "may become ready only after X is ready" => each surface is ready only
 *     when the surface it is handed is itself ready/pending; a not-ready
 *     predecessor sets a named gated_reason and forces ready=false downstream.
 *   - "The required default is request_changes; hash mismatch, evidence failure
 *     or hot-scope changes must default to abort." => defaultDecision() returns
 *     'abort' the moment any of those three conditions is present, else
 *     'request_changes' — never 'merge'.
 *
 * Non-goals honoured: no signature acceptance, no signature validation, no
 * decision recording, no approval, no merge, no dispatch, no persistence.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-authorization-contract.md
 */
final class AtlasCodexMergeAuthorizationContractService
{
    /**
     * The 8 prerequisites the merge execution checklist must require.
     *
     * @var list<string>
     */
    public const CHECKLIST_REQUIRED_PREREQUISITES = [
        'external_authorization',
        'valid_external_signature_evidence',
        'selected_decision_equals_merge',
        'fresh_gate_outputs',
        'scope_and_evidence_integrity',
        'hot_voice_or_kernel_exclusion',
        'rollback_plan',
        'human_final_confirmation',
    ];

    /**
     * The 6 fields the future authorizing surface must record (per checklist).
     *
     * @var list<string>
     */
    public const FUTURE_AUTHORIZING_RECORD_FIELDS = [
        'signature_reference',
        'selected_decision_and_rationale',
        'gate_output_hashes',
        'scope_and_evidence_hashes',
        'merge_operator_and_timestamp',
        'rollback_and_post_merge_verification_hashes',
    ];

    /**
     * Source hashes the authorization template / receipt draft must bind.
     *
     * @var list<string>
     */
    public const TEMPLATE_SOURCE_HASHES = [
        'execution_checklist_hash',
        'post_signature_runbook_hash',
        'signature_request_hash',
        'signable_payload_hash',
    ];

    /**
     * Allowed decisions a future authorizing surface may select. 'merge' is
     * present as an allowed value but is NEVER the default of any surface here.
     *
     * @var list<string>
     */
    public const ALLOWED_DECISIONS = ['merge', 'request_changes', 'abort'];

    /** Conservative default when nothing is wrong. */
    public const DEFAULT_DECISION = 'request_changes';

    /** Forced default when integrity fails. */
    public const ABORT_DECISION = 'abort';

    /**
     * The 10 items the post-signature runbook must require.
     *
     * @var list<string>
     */
    public const RUNBOOK_REQUIRED_ITEMS = [
        'external_authorization_signature_value',
        'validator_identity_and_timestamp',
        'validated_authorization_signable_payload_hash',
        'validated_authorization_receipt_hash',
        'selected_decision_and_rationale',
        'fresh_test_docs_health_architecture_diff_check_output',
        'scope_and_evidence_integrity_statements',
        'rollback_plan',
        'human_final_merge_confirmation',
        'separate_authorizing_surface_preparation',
    ];

    /**
     * The 10 items the final authorization preflight must require.
     *
     * @var list<string>
     */
    public const PREFLIGHT_REQUIRED_ITEMS = [
        'validated_external_authorization_signature_evidence',
        'validated_authorization_signable_payload_and_receipt_hashes',
        'explicit_selected_decision_equals_merge',
        'decision_rationale',
        'fresh_merge_preflight_payload_and_hash',
        'fresh_test_docs_health_architecture_diff_check_output',
        'scope_integrity_and_hot_scope_exclusion_statements',
        'completed_packet_evidence_integrity_statement',
        'rollback_plan',
        'human_final_merge_confirmation',
    ];

    /**
     * Boundary keys every non-signature surface must keep false.
     *
     * @var list<string>
     */
    public const BOUNDARY_KEYS = [
        'signature_valid',
        'approval_granted',
        'merge_allowed',
    ];

    /**
     * Extra boundary keys the receipt-draft / signature-request must keep false.
     *
     * @var list<string>
     */
    public const SIGNATURE_BOUNDARY_KEYS = [
        'signature_present',
        'signature_valid',
        'decision_recorded',
        'approval_granted',
        'merge_allowed',
    ];

    /**
     * Extra boundary keys the final authorization preflight must keep false.
     *
     * @var list<string>
     */
    public const PREFLIGHT_BOUNDARY_KEYS = [
        'authorization_ready',
        'signature_present',
        'signature_valid',
        'decision_recorded',
        'approval_granted',
        'merge_allowed',
    ];

    /**
     * Run the full six-surface contract in documented dependency order and
     * prove the boundary held. Safe default (empty input) keeps the whole chain
     * not-ready while every surface stays inert.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function contract(array $input = []): array
    {
        $checklist = $this->mergeExecutionChecklist(
            (array) ($input['execution_checklist'] ?? [])
        );

        $template = $this->mergeAuthorizationTemplate(
            $checklist,
            (array) ($input['authorization_template'] ?? [])
        );

        $receiptDraft = $this->mergeAuthorizationReceiptDraft(
            $template,
            (array) ($input['receipt_draft'] ?? [])
        );

        $signatureRequest = $this->mergeAuthorizationSignatureRequest(
            $receiptDraft,
            (array) ($input['signature_request'] ?? [])
        );

        $runbook = $this->mergeAuthorizationPostSignatureRunbook(
            $signatureRequest,
            (array) ($input['post_signature_runbook'] ?? [])
        );

        $preflight = $this->mergeFinalAuthorizationPreflight(
            $runbook,
            (array) ($input['final_authorization_preflight'] ?? [])
        );

        $surfaces = [
            'merge_execution_checklist' => $checklist,
            'merge_authorization_template' => $template,
            'merge_authorization_receipt_draft' => $receiptDraft,
            'merge_authorization_signature_request' => $signatureRequest,
            'merge_authorization_post_signature_runbook' => $runbook,
            'merge_final_authorization_preflight' => $preflight,
        ];

        $violations = $this->boundaryViolations($surfaces);

        // The chain is "ready" only when the terminal preflight is ready, which
        // transitively requires every predecessor ready/pending.
        $chainReady = ($preflight['ready'] ?? false) === true;

        return $surfaces + [
            'chain_ready' => $chainReady,
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
            // Restate the terminal invariant for callers: nothing here merges.
            'merge_allowed' => false,
            'doc' => 'docs/engineering-knowledge-base/self-construction/codex-merge-authorization-contract.md',
        ];
    }

    /**
     * Surface 1 — Merge Execution Checklist. Binds the final prerequisites a
     * future authorizing surface must satisfy; never the authorizing action.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function mergeExecutionChecklist(array $input): array
    {
        $satisfied = [];
        $missing = [];
        foreach (self::CHECKLIST_REQUIRED_PREREQUISITES as $prereq) {
            if (($input[$prereq] ?? false) === true) {
                $satisfied[] = $prereq;
            } else {
                $missing[] = $prereq;
            }
        }

        // Ready means "all final prerequisites are present so a SEPARATE future
        // surface could act" — it does NOT mean this surface may act.
        $ready = $missing === [];

        return [
            'surface' => 'merge_execution_checklist',
            'required_prerequisites' => self::CHECKLIST_REQUIRED_PREREQUISITES,
            'satisfied_prerequisites' => $satisfied,
            'missing_prerequisites' => $missing,
            'future_authorizing_record_fields' => self::FUTURE_AUTHORIZING_RECORD_FIELDS,
            'ready' => $ready,
            'boundary' => $this->boundary(),
        ] + $this->boundary();
    }

    /**
     * Surface 2 — Merge Authorization Template. Ready only after the checklist
     * is ready. Defines fields, allowed decisions and the conservative default.
     *
     * @param  array<string,mixed>  $checklist
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function mergeAuthorizationTemplate(array $checklist, array $input): array
    {
        $gatedReason = ($checklist['ready'] ?? false) === true
            ? null
            : 'merge_execution_checklist_not_ready';

        $defaultDecision = $this->defaultDecision($input);

        return [
            'surface' => 'merge_authorization_template',
            'source_hashes' => self::TEMPLATE_SOURCE_HASHES,
            'allowed_decisions' => self::ALLOWED_DECISIONS,
            'default_decision' => $defaultDecision,
            'required_authorization_fields' => self::FUTURE_AUTHORIZING_RECORD_FIELDS,
            'required_preconditions' => self::CHECKLIST_REQUIRED_PREREQUISITES,
            'rejection_defaults' => [
                'on_clean' => self::DEFAULT_DECISION,
                'on_integrity_failure' => self::ABORT_DECISION,
            ],
            'future_authorizing_record_fields' => self::FUTURE_AUTHORIZING_RECORD_FIELDS,
            'ready' => $gatedReason === null,
            'gated_reason' => $gatedReason,
            'boundary' => $this->boundary(),
        ] + $this->boundary();
    }

    /**
     * Surface 3 — Merge Authorization Receipt Draft. Ready only after the
     * template is ready. An unsigned, inert audit shell.
     *
     * @param  array<string,mixed>  $template
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function mergeAuthorizationReceiptDraft(array $template, array $input): array
    {
        $gatedReason = ($template['ready'] ?? false) === true
            ? null
            : 'merge_authorization_template_not_ready';

        return [
            'surface' => 'merge_authorization_receipt_draft',
            'bound_hashes' => [
                'authorization_template_hash',
                'execution_checklist_hash',
                'post_signature_runbook_hash',
                'signature_request_hash',
                'signable_payload_hash',
            ],
            'required_signers' => (array) ($input['required_signers'] ?? ['external_authorizer']),
            'required_authorization_fields' => self::FUTURE_AUTHORIZING_RECORD_FIELDS,
            'required_preconditions' => self::CHECKLIST_REQUIRED_PREREQUISITES,
            'rejection_defaults' => [
                'on_clean' => self::DEFAULT_DECISION,
                'on_integrity_failure' => self::ABORT_DECISION,
            ],
            'ready' => $gatedReason === null,
            'gated_reason' => $gatedReason,
            'boundary' => $this->signatureBoundary(),
        ] + $this->signatureBoundary();
    }

    /**
     * Surface 4 — Merge Authorization Signature Request. Becomes pending only
     * after the receipt draft is ready. Prepares a signable payload but accepts,
     * infers, validates or applies no signature.
     *
     * @param  array<string,mixed>  $receiptDraft
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function mergeAuthorizationSignatureRequest(array $receiptDraft, array $input): array
    {
        $gatedReason = ($receiptDraft['ready'] ?? false) === true
            ? null
            : 'merge_authorization_receipt_draft_not_ready';

        $pending = $gatedReason === null;

        return [
            'surface' => 'merge_authorization_signature_request',
            'signable_payload' => [
                'authorization_receipt_id',
                'authorization_receipt_hash',
                'authorization_template_hash',
                'execution_checklist_hash',
                'post_signature_runbook_hash',
                'prior_signature_request_hash',
                'prior_signable_payload_hash',
                'requested_signature_type',
                'allowed_decisions',
                'default_decision',
                'required_authorization_fields',
                'required_preconditions',
                'required_receipt_signers',
                'future_authorizing_record_fields',
                'rejection_defaults',
            ],
            'requested_signature_type' => (string) ($input['requested_signature_type'] ?? 'external_authorization'),
            'allowed_decisions' => self::ALLOWED_DECISIONS,
            'default_decision' => self::DEFAULT_DECISION,
            // Documented status: pending (signable prepared) vs gated (not yet).
            'status' => $pending ? 'pending' : 'gated',
            'ready' => $pending,
            'gated_reason' => $gatedReason,
            'boundary' => $this->signatureBoundary(),
        ] + $this->signatureBoundary();
    }

    /**
     * Surface 5 — Merge Authorization Post-Signature Runbook. Ready only after
     * the signature request is pending. Sequences but does not execute.
     *
     * @param  array<string,mixed>  $signatureRequest
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function mergeAuthorizationPostSignatureRunbook(array $signatureRequest, array $input): array
    {
        $requestPending = ($signatureRequest['status'] ?? null) === 'pending'
            && ($signatureRequest['ready'] ?? false) === true;

        $gatedReason = $requestPending
            ? null
            : 'merge_authorization_signature_request_not_pending';

        return [
            'surface' => 'merge_authorization_post_signature_runbook',
            'required_items' => self::RUNBOOK_REQUIRED_ITEMS,
            'sequenced_not_executed' => [
                'external_authorization_signature_verification',
                'source_hash_matching',
                'merge_preflight_rerun',
                'fresh_gates',
                'hot_voice_or_kernel_exclusion_checks',
                'preparation_of_a_separate_authorizing_merge_surface',
            ],
            'ready' => $gatedReason === null,
            'gated_reason' => $gatedReason,
            'boundary' => $this->boundary(),
        ] + $this->boundary();
    }

    /**
     * Surface 6 — Merge Final Authorization Preflight. Ready only after the
     * post-signature runbook is ready. The final non-authorizing evidence
     * contract; still not the authorizing action.
     *
     * @param  array<string,mixed>  $runbook
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function mergeFinalAuthorizationPreflight(array $runbook, array $input): array
    {
        $gatedReason = ($runbook['ready'] ?? false) === true
            ? null
            : 'merge_authorization_post_signature_runbook_not_ready';

        return [
            'surface' => 'merge_final_authorization_preflight',
            'required_items' => self::PREFLIGHT_REQUIRED_ITEMS,
            // The future authorizing surface must satisfy ALL of these.
            'future_authorizing_surface_requirements' => [
                'will_be_separate',
                'will_validate_signature_again',
                'will_persist_append_only_authorization_receipt',
                'will_reference_fresh_gates',
                'will_require_final_human_confirmation',
                'will_emit_final_merge_receipt_before_execution',
            ],
            'ready' => $gatedReason === null,
            'gated_reason' => $gatedReason,
            'boundary' => $this->preflightBoundary(),
        ] + $this->preflightBoundary();
    }

    /**
     * Conservative default decision. request_changes is the documented default;
     * any of (hash mismatch, evidence failure, hot-scope change) forces abort.
     * Never returns 'merge'.
     *
     * @param  array<string,mixed>  $input
     */
    public function defaultDecision(array $input): string
    {
        $hashMismatch = ($input['hash_mismatch'] ?? false) === true;
        $evidenceFailure = ($input['evidence_failure'] ?? false) === true;
        $hotScopeChange = ($input['hot_scope_change'] ?? false) === true;

        if ($hashMismatch || $evidenceFailure || $hotScopeChange) {
            return self::ABORT_DECISION;
        }

        return self::DEFAULT_DECISION;
    }

    /**
     * Boundary for non-signature surfaces (checklist, template, runbook).
     *
     * @return array<string,false>
     */
    private function boundary(): array
    {
        return [
            'signature_valid' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];
    }

    /**
     * Boundary for the receipt draft and signature request surfaces.
     *
     * @return array<string,false>
     */
    private function signatureBoundary(): array
    {
        return [
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];
    }

    /**
     * Boundary for the final authorization preflight surface.
     *
     * @return array<string,false>
     */
    private function preflightBoundary(): array
    {
        return [
            'authorization_ready' => false,
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];
    }

    /**
     * Prove no surface flipped a guarded boundary key true. Returns a list of
     * "surface.key" strings for any violation (empty list == boundary held).
     *
     * @param  array<string,array<string,mixed>>  $surfaces
     * @return list<string>
     */
    private function boundaryViolations(array $surfaces): array
    {
        $violations = [];
        foreach ($surfaces as $name => $surface) {
            foreach (self::PREFLIGHT_BOUNDARY_KEYS as $key) {
                // Only assert on keys the surface actually exposes; every key it
                // exposes from the guarded set MUST be exactly false.
                if (array_key_exists($key, $surface) && $surface[$key] !== false) {
                    $violations[] = $name.'.'.$key;
                }
            }
        }

        return $violations;
    }
}
