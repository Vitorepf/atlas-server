<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Codex Merge Post-Execution Action Receipts — pure, deterministic, READ-ONLY
 * surfaces for a FUTURE Codex merge action once execution evidence exists.
 *
 * This contract exposes exactly three surfaces and NOTHING that can authorize a
 * merge:
 *
 *   1. action receipt draft        — binds hashes + decision fields, stays UNSIGNED;
 *   2. action signature request    — prepares the signable payload only;
 *   3. action post-signature runbook — sequences the checks a future validator
 *                                      runs, then STOPS before acceptance.
 *
 * Hard boundary (doc "Boundary") — every result this service emits keeps all
 * EIGHT keys false, always:
 *   execution_allowed=false, ledger_write_allowed=false, dispatch_allowed=false,
 *   approval_granted=false, merge_allowed=false, receipt_signed=false,
 *   signature_valid=false, receipt_persisted=false.
 *
 * The surfaces must not, and this code does not: accept signatures, validate
 * signatures, approve code, persist receipts, merge, or dispatch work.
 *
 * Documented invariants this code ENFORCES (not merely documents):
 *   - "must keep ...=false" boundary => boundary() returns all eight keys false and
 *     every public result embeds it verbatim; assertBoundaryHeld() proves no result
 *     ever flipped a key true.
 *   - "[draft] may become ready only after the post-execution action template is
 *     ready" => actionReceiptDraft() returns status not_ready (and binds NO hashes,
 *     lists NO decision fields) unless the template is proven ready. Fail-closed:
 *     absent an exact boolean true, the template is treated as not ready.
 *   - "[draft] must default to do_not_merge" + "must require signature before any
 *     future merge" => the draft's selected decision defaults to 'do_not_merge'
 *     and signature_required is always true.
 *   - "[draft] must bind <9 hashes>" + "must require decision fields for <7 fields>"
 *     => when ready, the draft emits exactly those nine bound-hash slots and seven
 *     decision-field slots, in documented order.
 *   - "[signature request] may become pending only after the action receipt draft
 *     is ready" => actionSignatureRequest() returns blocked_before_action_receipt_draft
 *     (empty signable payload) unless the draft is proven ready; otherwise it lists
 *     the signable payload + the four required EXTERNAL signature fields, while
 *     keeping signature_valid / receipt_signed false.
 *   - "[runbook] may become ready only after the action signature request is pending"
 *     => actionPostSignatureRunbook() returns blocked_before_action_signature_request
 *     (empty sequence) unless the request is proven pending; otherwise it emits the
 *     SEVEN ordered steps ending in stop_before_signature_acceptance_or_merge.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-receipts.md
 */
final class AtlasCodexMergePostExecutionActionReceiptsService
{
    /** Stable evidence schema id this read-only surface family emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_receipts.v1';

    /** Surface labels (closed set). */
    public const SURFACE_DRAFT = 'post_execution_action_receipt_draft';
    public const SURFACE_SIGNATURE_REQUEST = 'post_execution_action_signature_request';
    public const SURFACE_RUNBOOK = 'post_execution_action_post_signature_runbook';

    /** Default (and only safe) selected decision for an unsigned draft. */
    public const DEFAULT_DECISION = 'do_not_merge';

    /** Draft statuses. */
    public const STATUS_DRAFT_NOT_READY = 'blocked_before_post_execution_action_template';
    public const STATUS_DRAFT_READY = 'action_receipt_draft_ready';

    /** Signature-request statuses. */
    public const STATUS_REQUEST_BLOCKED = 'blocked_before_action_receipt_draft';
    public const STATUS_REQUEST_PENDING = 'action_signature_request_pending';

    /** Runbook statuses. */
    public const STATUS_RUNBOOK_BLOCKED = 'blocked_before_action_signature_request';
    public const STATUS_RUNBOOK_READY = 'action_post_signature_runbook_ready';

    /**
     * The eight boundary keys (doc "Boundary"): every result keeps all false.
     *
     * @var list<string>
     */
    public const BOUNDARY_KEYS = [
        'execution_allowed',
        'ledger_write_allowed',
        'dispatch_allowed',
        'approval_granted',
        'merge_allowed',
        'receipt_signed',
        'signature_valid',
        'receipt_persisted',
    ];

    /**
     * Hashes the action receipt draft must BIND (doc "Action Receipt Draft" →
     * "It must bind"). Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const BOUND_HASHES = [
        'post_execution_action_template_hash',
        'post_execution_preflight_hash',
        'execution_receipt_template_hash',
        'executor_contract_template_hash',
        'final_receipt_hash',
        'selected_decision',
        'merge_candidate_hash',
        'persisted_execution_receipt_hash',
        'human_post_execution_confirmation_hash',
    ];

    /**
     * Decision fields the draft must REQUIRE (doc "Action Receipt Draft" →
     * "It must require decision fields for"). Order preserved exactly.
     *
     * @var list<string>
     */
    public const REQUIRED_DECISION_FIELDS = [
        'selected_decision',
        'decision_rationale',
        'merge_candidate_hash',
        'persisted_execution_receipt_hash',
        'post_execution_gate_report_hash',
        'human_post_execution_confirmation_hash',
        'merge_operator_identity',
    ];

    /**
     * Signable payload the signature request must INCLUDE (doc "Action Signature
     * Request" → "It must include"). Order preserved exactly.
     *
     * @var list<string>
     */
    public const SIGNABLE_PAYLOAD = [
        'action_receipt_hash',
        'post_execution_action_template_hash',
        'post_execution_preflight_hash',
        'execution_receipt_template_hash',
        'executor_contract_template_hash',
        'final_receipt_hash',
        'required_authority_inputs',
        'required_action_validations',
        'required_decision_fields',
    ];

    /**
     * External fields the signature request must REQUIRE (doc "Action Signature
     * Request" → "It must require external fields"). Order preserved exactly.
     *
     * @var list<string>
     */
    public const REQUIRED_EXTERNAL_FIELDS = [
        'final_merge_action_signature_value',
        'signature_validator_identity',
        'signature_validation_timestamp',
        'append_only_signed_action_receipt_persistence_proof',
    ];

    /**
     * External evidence the runbook must REQUIRE (doc "Action Post-Signature
     * Runbook" → "It must require external evidence for"). Order preserved.
     *
     * @var list<string>
     */
    public const RUNBOOK_REQUIRED_EVIDENCE = [
        'final_merge_action_signature_value',
        'signature_validator_identity',
        'signature_validation_timestamp',
        'signed_action_receipt_persistence_event_hash',
    ];

    /**
     * The exact ordered steps the runbook must SEQUENCE (doc "Action
     * Post-Signature Runbook" → "It must sequence"). The final step is the hard
     * stop the doc demands. Order is load-bearing.
     *
     * @var list<string>
     */
    public const RUNBOOK_STEPS = [
        'collect_external_signature_evidence',
        'verify_signature_request_hash_matches_signable_payload',
        'verify_action_receipt_hash_matches_signed_payload',
        'verify_required_authority_inputs_are_present',
        'verify_required_action_validations_are_present',
        'prepare_signed_action_receipt_persistence_candidate',
        'stop_before_signature_acceptance_or_merge',
    ];

    /** The mandatory terminal runbook step (the hard stop). */
    public const RUNBOOK_TERMINAL_STEP = 'stop_before_signature_acceptance_or_merge';

    /**
     * The eight documented boundary keys, all forced false.
     *
     * @return array<string,false>
     */
    public function boundary(): array
    {
        $out = [];
        foreach (self::BOUNDARY_KEYS as $key) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * Surface 1 — the read-only ACTION RECEIPT DRAFT.
     *
     * Binds the future merge action to hashes and decision fields while staying
     * unsigned. It may become ready ONLY after the post-execution action template
     * is ready; otherwise it binds nothing and lists no decision fields. The
     * selected decision always defaults to `do_not_merge` and a signature is
     * always required before any future merge. Nothing is signed, accepted,
     * validated, approved, persisted, merged or dispatched.
     *
     * @param array<string,mixed> $input recognised key:
     *   post_execution_action_template_ready (bool) — the ONLY positive signal
     *   that the upstream template is ready. Absent / non-true => fail-closed.
     * @return array{
     *   surface:string, schema:string,
     *   upstream_template_ready:bool, status:string, draft_ready:bool,
     *   selected_decision:string, signature_required:true,
     *   bound_hashes:list<string>, required_decision_fields:list<string>,
     *   boundary:array<string,false>,
     *   receipt_signed:false, signature_valid:false, receipt_persisted:false,
     *   approval_granted:false, merge_allowed:false
     * }
     */
    public function actionReceiptDraft(array $input = []): array
    {
        $templateReady = $this->signal($input, 'post_execution_action_template_ready', false);

        $status = $templateReady ? self::STATUS_DRAFT_READY : self::STATUS_DRAFT_NOT_READY;

        // Fail-closed: until the template is proven ready, bind NOTHING and list
        // NO decision fields — there is nothing to draft yet.
        $boundHashes = $templateReady ? self::BOUND_HASHES : [];
        $decisionFields = $templateReady ? self::REQUIRED_DECISION_FIELDS : [];

        return [
            'surface' => self::SURFACE_DRAFT,
            'schema' => self::SCHEMA,
            'upstream_template_ready' => $templateReady,
            'status' => $status,
            'draft_ready' => $templateReady,
            // Doc: "It must default to do_not_merge." The draft never selects a
            // merging decision on its own.
            'selected_decision' => self::DEFAULT_DECISION,
            // Doc: "It must require signature before any future merge can proceed."
            'signature_required' => true,
            'bound_hashes' => $boundHashes,
            'required_decision_fields' => $decisionFields,
            'boundary' => $this->boundary(),
            // Restated per the doc's hard non-authorizing guarantee.
            'receipt_signed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
        ];
    }

    /**
     * Surface 2 — the read-only ACTION SIGNATURE REQUEST.
     *
     * Prepares the signable payload for the future post-execution merge action
     * receipt. It may become pending ONLY after the action receipt draft is
     * ready; otherwise it publishes no signable payload. It lists the external
     * fields a later flow must supply, but accepts / validates none of them.
     *
     * @param array<string,mixed> $input recognised key:
     *   action_receipt_draft_ready (bool) — the ONLY positive signal that the
     *   upstream draft is ready. Absent / non-true => fail-closed to blocked.
     * @return array{
     *   surface:string, schema:string,
     *   upstream_draft_ready:bool, status:string, signature_request_pending:bool,
     *   signable_payload:list<string>, required_external_fields:list<string>,
     *   boundary:array<string,false>,
     *   signature_valid:false, receipt_signed:false, receipt_persisted:false,
     *   accepts_or_validates_signature:false
     * }
     */
    public function actionSignatureRequest(array $input = []): array
    {
        $draftReady = $this->signal($input, 'action_receipt_draft_ready', false);

        $status = $draftReady ? self::STATUS_REQUEST_PENDING : self::STATUS_REQUEST_BLOCKED;

        // Fail-closed: no draft ready => publish NO signable payload and require
        // NO external fields yet. The request itself is blocked.
        $payload = $draftReady ? self::SIGNABLE_PAYLOAD : [];
        $externalFields = $draftReady ? self::REQUIRED_EXTERNAL_FIELDS : [];

        return [
            'surface' => self::SURFACE_SIGNATURE_REQUEST,
            'schema' => self::SCHEMA,
            'upstream_draft_ready' => $draftReady,
            'status' => $status,
            // "Pending" means ready to be signed by a LATER flow. It is never a
            // signature, an acceptance or a merge.
            'signature_request_pending' => $draftReady,
            'signable_payload' => $payload,
            'required_external_fields' => $externalFields,
            'boundary' => $this->boundary(),
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'accepts_or_validates_signature' => false,
        ];
    }

    /**
     * Surface 3 — the read-only ACTION POST-SIGNATURE RUNBOOK.
     *
     * Sequences the evidence checks a future validator must perform after
     * external signature evidence exists. It may become ready ONLY after the
     * action signature request is pending; otherwise it sequences nothing. The
     * sequence always ENDS at the hard stop and never records a decision,
     * approves, persists, merges or dispatches.
     *
     * @param array<string,mixed> $input recognised key:
     *   action_signature_request_pending (bool) — the ONLY positive signal that
     *   the upstream request is pending. Absent / non-true => fail-closed.
     * @return array{
     *   surface:string, schema:string,
     *   upstream_request_pending:bool, status:string, runbook_ready:bool,
     *   required_external_evidence:list<string>, steps:list<string>,
     *   terminal_step:string, stops_before_acceptance:bool,
     *   boundary:array<string,false>,
     *   signature_valid:false, receipt_signed:false, receipt_persisted:false,
     *   decision_recorded:false
     * }
     */
    public function actionPostSignatureRunbook(array $input = []): array
    {
        $requestPending = $this->signal($input, 'action_signature_request_pending', false);

        $status = $requestPending ? self::STATUS_RUNBOOK_READY : self::STATUS_RUNBOOK_BLOCKED;

        // Fail-closed: no pending request => sequence NOTHING and require no
        // evidence yet.
        $evidence = $requestPending ? self::RUNBOOK_REQUIRED_EVIDENCE : [];
        $steps = $requestPending ? self::RUNBOOK_STEPS : [];

        // When the runbook is sequenced at all, the LAST step is, by construction,
        // the documented hard stop — never anything past it.
        $stopsBeforeAcceptance = $steps === []
            ? true
            : ($steps[count($steps) - 1] === self::RUNBOOK_TERMINAL_STEP);

        return [
            'surface' => self::SURFACE_RUNBOOK,
            'schema' => self::SCHEMA,
            'upstream_request_pending' => $requestPending,
            'status' => $status,
            'runbook_ready' => $requestPending,
            'required_external_evidence' => $evidence,
            'steps' => $steps,
            'terminal_step' => self::RUNBOOK_TERMINAL_STEP,
            'stops_before_acceptance' => $stopsBeforeAcceptance,
            'boundary' => $this->boundary(),
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'decision_recorded' => false,
        ];
    }

    /**
     * Composite entrypoint: evaluate all three surfaces under a single input and
     * prove the boundary held across every result.
     *
     * The progressive gate chain is respected by passing the same operator-supplied
     * readiness signals through. With safe (empty) defaults every stage is blocked
     * and nothing is bound, signed, requested or sequenced.
     *
     * @param array<string,mixed> $input forwarded to each surface
     * @return array{
     *   schema:string,
     *   action_receipt_draft:array<string,mixed>,
     *   action_signature_request:array<string,mixed>,
     *   action_post_signature_runbook:array<string,mixed>,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(array $input = []): array
    {
        $draft = $this->actionReceiptDraft($input);
        $request = $this->actionSignatureRequest($input);
        $runbook = $this->actionPostSignatureRunbook($input);

        $violations = $this->assertBoundaryHeld([$draft, $request, $runbook]);

        return [
            'schema' => self::SCHEMA,
            'action_receipt_draft' => $draft,
            'action_signature_request' => $request,
            'action_post_signature_runbook' => $runbook,
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
        ];
    }

    /**
     * Prove that no result ever flipped a boundary key to a truthy value.
     * Returns the list of "surface.key" violations (empty = boundary intact).
     *
     * @param list<array<string,mixed>> $results
     * @return list<string>
     */
    public function assertBoundaryHeld(array $results): array
    {
        $violations = [];
        foreach ($results as $result) {
            $label = is_string($result['surface'] ?? null) ? $result['surface'] : 'unknown';

            $boundary = is_array($result['boundary'] ?? null) ? $result['boundary'] : [];
            foreach (self::BOUNDARY_KEYS as $key) {
                // Missing key OR truthy value both count as a breach.
                if (! array_key_exists($key, $boundary) || $boundary[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }

            // Any restated top-level guarantee turning truthy is a breach too.
            foreach ([
                'receipt_signed',
                'signature_valid',
                'receipt_persisted',
                'approval_granted',
                'merge_allowed',
                'accepts_or_validates_signature',
                'decision_recorded',
            ] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }

    /**
     * Read a boolean signal. Only an exact boolean true clears it; anything else
     * (missing key, null, truthy-string, 1) falls back to the fail-closed
     * default. This keeps every upstream gate impossible to trip accidentally.
     *
     * @param array<string,mixed> $input
     */
    private function signal(array $input, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $input)) {
            return $default;
        }

        return $input[$key] === true;
    }
}
