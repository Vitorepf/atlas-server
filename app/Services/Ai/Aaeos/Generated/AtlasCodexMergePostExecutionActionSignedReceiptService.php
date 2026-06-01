<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Codex Merge Post-Execution Action Signed Receipt — pure, deterministic,
 * READ-ONLY template logic for a FUTURE, separately governed persistence surface.
 *
 * The doc governs three read-only surfaces and NOTHING that can sign, validate,
 * persist or merge:
 *
 *   1. signedReceiptTemplate()  — describes the future signed receipt fields and
 *                                 the sources it would be bound to; signs nothing;
 *   2. persistencePreflight()   — checks whether a future persistence surface may
 *                                 be APPROACHED; lists every persistence blocker;
 *   3. persistenceTemplate()    — defines the future append-only event WITHOUT
 *                                 writing it.
 *
 * Hard boundary (doc "Boundary") — the template must keep ALL SEVEN keys false,
 * always:
 *   execution_allowed=false, ledger_write_allowed=false, dispatch_allowed=false,
 *   approval_granted=false, merge_allowed=false, signature_valid=false,
 *   receipt_persisted=false.
 *
 * And it must not, and this code does not: accept signatures, validate
 * signatures, record decisions, persist receipts, approve code, merge, or
 * dispatch work.
 *
 * Documented invariants this code ENFORCES (not merely documents):
 *   - "must keep ...=false" (doc "Boundary") => boundary() returns all seven keys
 *     false and every public result embeds it verbatim; assertBoundaryHeld()
 *     proves no result ever flipped a key true.
 *   - "The signed action receipt template must be bound to" 4 sources (doc
 *     "Required Sources") => signedReceiptTemplate() always lists exactly those
 *     four bound sources, in documented order.
 *   - "A future persisting surface must provide" 9 evidence items (doc "Future
 *     External Evidence") and "A future signed action receipt must persist" 13
 *     fields (doc "Future Persisted Fields") => surfaced exactly, in order.
 *   - "It must remain read-only and must block on" 14 conditions (doc
 *     "Persistence Preflight") => persistencePreflight() evaluates ALL fourteen
 *     blockers against external evidence; fail-closed, an empty input trips every
 *     blocker and persistence stays NOT approachable. `may_approach_persistence`
 *     is true ONLY when zero blockers remain.
 *   - "selected decision not equal to merge" is a blocker => the selected-decision
 *     blocker clears only when the decision is EXACTLY 'merge'.
 *   - "A later merge surface may only proceed after" 5 conditions (doc "Release
 *     Conditions") => releaseConditions() maps each to a real predicate and
 *     `merge_surface_may_proceed` is true only when all five hold — while the
 *     boundary stays all-false even then (it grants nothing).
 *   - "The future event type is CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED"
 *     and "must include" 14 event fields (doc "Persistence Template") =>
 *     persistenceTemplate() pins that exact event type and those fields, and
 *     defines the event WITHOUT writing it (writes_event=false).
 *
 * Principle (doc "Principle"): this template is still NOT the signed receipt. It
 * is only the contract for a future, separate, explicitly governed persistence
 * surface.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-signed-receipt.md
 */
final class AtlasCodexMergePostExecutionActionSignedReceiptService
{
    /** Stable evidence schema id this read-only surface family emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_signed_receipt.v1';

    /** Surface labels (closed set). */
    public const SURFACE_TEMPLATE = 'signed_action_receipt_template';
    public const SURFACE_PREFLIGHT = 'signed_action_receipt_persistence_preflight';
    public const SURFACE_PERSISTENCE_TEMPLATE = 'signed_action_receipt_persistence_template';

    /** The only selected decision a persistence approach may carry (doc blocker). */
    public const REQUIRED_DECISION = 'merge';

    /** Preflight statuses. */
    public const STATUS_PREFLIGHT_BLOCKED = 'persistence_blocked';
    public const STATUS_PREFLIGHT_CLEAR = 'persistence_may_be_approached';

    /** The future append-only event type (doc "Persistence Template"). */
    public const PERSISTENCE_EVENT_TYPE = 'CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED';

    /**
     * The seven boundary keys (doc "Boundary"): every result keeps all false.
     *
     * @var list<string>
     */
    public const BOUNDARY_KEYS = [
        'execution_allowed',
        'ledger_write_allowed',
        'dispatch_allowed',
        'approval_granted',
        'merge_allowed',
        'signature_valid',
        'receipt_persisted',
    ];

    /**
     * Actions every surface must NOT perform (doc "Boundary" → "It must not:").
     * Surfaced so the boundary is self-describing; the code performs none.
     *
     * @var list<string>
     */
    public const FORBIDDEN_ACTIONS = [
        'accept_signatures',
        'validate_signatures',
        'record_decisions',
        'persist_receipts',
        'approve_code',
        'merge',
        'dispatch_work',
    ];

    /**
     * The four sources the template must be BOUND to (doc "Required Sources").
     * Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const BOUND_SOURCES = [
        'action_post_signature_runbook_hash',
        'action_signature_request_hash',
        'action_signable_payload_hash',
        'action_receipt_hash',
    ];

    /**
     * The nine evidence items a future persisting surface must PROVIDE (doc
     * "Future External Evidence"). Order preserved exactly.
     *
     * @var list<string>
     */
    public const FUTURE_EXTERNAL_EVIDENCE = [
        'final_merge_action_signature_value',
        'signature_validator_identity',
        'signature_validation_timestamp',
        'validated_action_signable_payload_hash',
        'validated_action_receipt_hash',
        'validated_selected_decision',
        'validated_authority_inputs',
        'validated_action_validations',
        'signed_action_receipt_persistence_event_hash',
    ];

    /**
     * The thirteen fields a future signed action receipt must PERSIST (doc
     * "Future Persisted Fields"). Order preserved exactly.
     *
     * @var list<string>
     */
    public const FUTURE_PERSISTED_FIELDS = [
        'signed_action_receipt_id',
        'source_action_receipt_hash',
        'source_action_signable_payload_hash',
        'source_post_signature_runbook_hash',
        'signature_hash',
        'signature_validator_identity',
        'signature_validation_timestamp',
        'selected_decision_and_rationale',
        'merge_candidate_hash',
        'persisted_execution_receipt_hash',
        'post_execution_gate_report_hash',
        'human_post_execution_confirmation_hash',
        'merge_operator_identity',
    ];

    /**
     * The five release conditions a later merge surface must satisfy (doc
     * "Release Conditions"). Order preserved; each maps to a predicate below.
     *
     * @var list<string>
     */
    public const RELEASE_CONDITIONS = [
        'signed_action_receipt_persisted_append_only',
        'signed_action_receipt_hash_verified',
        'merge_surface_consumes_only_signed_action_receipt',
        'last_minute_diff_and_hot_scope_checks_pass',
        'final_merge_evidence_can_be_emitted',
    ];

    /**
     * The fourteen blockers the persistence preflight must BLOCK ON (doc
     * "Persistence Preflight" → "must block on"). Order preserved exactly; each
     * maps to a predicate in persistencePreflight().
     *
     * @var list<string>
     */
    public const PREFLIGHT_BLOCKERS = [
        'missing_external_final_merge_action_signature_value',
        'missing_signature_validator_identity',
        'missing_signature_validation_timestamp',
        'missing_validated_action_signable_payload_hash',
        'missing_validated_action_receipt_hash',
        'missing_selected_decision',
        'missing_authority_input_validation',
        'missing_action_validation_proof',
        'missing_signed_receipt_persistence_event_hash',
        'selected_decision_not_equal_to_merge',
        'action_receipt_hash_mismatch',
        'action_signable_payload_hash_mismatch',
        'hot_scope_drift_since_action_signature_request',
        'unreviewed_diff_since_action_signature_request',
    ];

    /**
     * The fourteen fields the future append-only event must INCLUDE (doc
     * "Persistence Template" → "The future event must include"). Order preserved.
     *
     * @var list<string>
     */
    public const PERSISTENCE_EVENT_FIELDS = [
        'event_id',
        'event_type',
        'signed_action_receipt_id',
        'signed_action_receipt_hash',
        'source_signed_receipt_preflight_hash',
        'source_signed_receipt_template_hash',
        'source_action_signature_request_hash',
        'source_action_signable_payload_hash',
        'source_action_receipt_hash',
        'signature_hash',
        'signature_validator_identity',
        'selected_decision',
        'merge_candidate_hash',
        'persisted_execution_receipt_hash',
        'human_post_execution_confirmation_hash',
        'persistence_timestamp',
    ];

    /**
     * The seven documented boundary keys, all forced false.
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
     * Surface 1 — the read-only SIGNED ACTION RECEIPT TEMPLATE.
     *
     * Describes the fields a FUTURE signed post-execution merge action receipt
     * would need, and the sources it would be bound to, while signing nothing,
     * validating nothing, persisting nothing, approving nothing and merging
     * nothing. The boundary is always all-false.
     *
     * @return array{
     *   surface:string, schema:string,
     *   bound_sources:list<string>,
     *   future_external_evidence:list<string>,
     *   future_persisted_fields:list<string>,
     *   forbidden_actions:list<string>,
     *   is_the_signed_receipt:false,
     *   describes_future_signed_receipt:true,
     *   boundary:array<string,false>
     * }
     */
    public function signedReceiptTemplate(): array
    {
        return [
            'surface' => self::SURFACE_TEMPLATE,
            'schema' => self::SCHEMA,
            'bound_sources' => self::BOUND_SOURCES,
            'future_external_evidence' => self::FUTURE_EXTERNAL_EVIDENCE,
            'future_persisted_fields' => self::FUTURE_PERSISTED_FIELDS,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            // Doc "Principle": this template is still NOT the signed receipt.
            'is_the_signed_receipt' => false,
            'describes_future_signed_receipt' => true,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Surface 2 — the read-only PERSISTENCE PREFLIGHT.
     *
     * Checks whether a future signed action receipt persistence surface may be
     * APPROACHED. It evaluates all fourteen documented blockers against the
     * external evidence supplied. Fail-closed: anything missing / mismatched /
     * drifted keeps the corresponding blocker active, and persistence may be
     * approached ONLY when zero blockers remain. It still forbids signature
     * acceptance, signature validation, receipt persistence, decision recording,
     * approval, merge and dispatch.
     *
     * @param array<string,mixed> $input recognised keys (all fail-closed):
     *   final_merge_action_signature_value (non-empty string),
     *   signature_validator_identity (non-empty string),
     *   signature_validation_timestamp (non-empty string),
     *   validated_action_signable_payload_hash (non-empty string),
     *   validated_action_receipt_hash (non-empty string),
     *   selected_decision (string; must equal 'merge'),
     *   authority_input_validation_present (bool),
     *   action_validation_proof_present (bool),
     *   signed_receipt_persistence_event_hash (non-empty string),
     *   expected_action_receipt_hash + bound_action_receipt_hash (must match),
     *   expected_action_signable_payload_hash + bound_action_signable_payload_hash (must match),
     *   hot_scope_unchanged_since_action_signature_request (bool),
     *   diff_reviewed_since_action_signature_request (bool).
     * @return array{
     *   surface:string, schema:string, status:string,
     *   blockers:array<string,bool>, active_blockers:list<string>,
     *   cleared_blockers:list<string>, blocker_count:int,
     *   may_approach_persistence:bool, selected_decision:?string,
     *   required_decision:string, forbidden_actions:list<string>,
     *   read_only:true, boundary:array<string,false>
     * }
     */
    public function persistencePreflight(array $input = []): array
    {
        $selectedDecision = $input['selected_decision'] ?? null;
        $selectedDecision = is_string($selectedDecision) ? $selectedDecision : null;

        // Each entry is TRUE when the blocker is ACTIVE (i.e. persistence blocked).
        $blockers = [
            'missing_external_final_merge_action_signature_value' => ! $this->present($input, 'final_merge_action_signature_value'),
            'missing_signature_validator_identity' => ! $this->present($input, 'signature_validator_identity'),
            'missing_signature_validation_timestamp' => ! $this->present($input, 'signature_validation_timestamp'),
            'missing_validated_action_signable_payload_hash' => ! $this->present($input, 'validated_action_signable_payload_hash'),
            'missing_validated_action_receipt_hash' => ! $this->present($input, 'validated_action_receipt_hash'),
            'missing_selected_decision' => $selectedDecision === null || $selectedDecision === '',
            'missing_authority_input_validation' => ! $this->flag($input, 'authority_input_validation_present'),
            'missing_action_validation_proof' => ! $this->flag($input, 'action_validation_proof_present'),
            'missing_signed_receipt_persistence_event_hash' => ! $this->present($input, 'signed_receipt_persistence_event_hash'),
            // "selected decision not equal to merge" — active unless EXACTLY merge.
            'selected_decision_not_equal_to_merge' => $selectedDecision !== self::REQUIRED_DECISION,
            // Hash equality blockers: active unless both sides present AND equal.
            'action_receipt_hash_mismatch' => ! $this->hashesMatch($input, 'expected_action_receipt_hash', 'bound_action_receipt_hash'),
            'action_signable_payload_hash_mismatch' => ! $this->hashesMatch($input, 'expected_action_signable_payload_hash', 'bound_action_signable_payload_hash'),
            // Drift / review blockers: active unless explicitly proven clean.
            'hot_scope_drift_since_action_signature_request' => ! $this->flag($input, 'hot_scope_unchanged_since_action_signature_request'),
            'unreviewed_diff_since_action_signature_request' => ! $this->flag($input, 'diff_reviewed_since_action_signature_request'),
        ];

        $active = [];
        $cleared = [];
        foreach (self::PREFLIGHT_BLOCKERS as $name) {
            if (($blockers[$name] ?? true) === true) {
                $active[] = $name;
            } else {
                $cleared[] = $name;
            }
        }

        $mayApproach = $active === [];

        return [
            'surface' => self::SURFACE_PREFLIGHT,
            'schema' => self::SCHEMA,
            'status' => $mayApproach ? self::STATUS_PREFLIGHT_CLEAR : self::STATUS_PREFLIGHT_BLOCKED,
            'blockers' => $blockers,
            'active_blockers' => $active,
            'cleared_blockers' => $cleared,
            'blocker_count' => count($active),
            // "may be approached" is the strongest thing this read-only surface
            // can say. It is never a signature, an approval or a merge.
            'may_approach_persistence' => $mayApproach,
            'selected_decision' => $selectedDecision,
            'required_decision' => self::REQUIRED_DECISION,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'read_only' => true,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Release-condition view (doc "Release Conditions"): a later merge surface may
     * only proceed after all five conditions hold. Purely descriptive of a LATER
     * surface — it proceeds with nothing and the boundary stays all-false even
     * when every condition is met.
     *
     * @param array<string,mixed> $input recognised keys (all fail-closed bools):
     *   signed_action_receipt_persisted_append_only,
     *   signed_action_receipt_hash_verified,
     *   merge_surface_consumes_only_signed_action_receipt,
     *   last_minute_diff_and_hot_scope_checks_pass,
     *   final_merge_evidence_can_be_emitted.
     * @return array{
     *   surface:string, schema:string,
     *   conditions:array<string,bool>, met:list<string>, unmet:list<string>,
     *   merge_surface_may_proceed:bool, proceeds_with_merge:false,
     *   boundary:array<string,false>
     * }
     */
    public function releaseConditions(array $input = []): array
    {
        $conditions = [
            'signed_action_receipt_persisted_append_only' => $this->flag($input, 'signed_action_receipt_persisted_append_only'),
            'signed_action_receipt_hash_verified' => $this->flag($input, 'signed_action_receipt_hash_verified'),
            'merge_surface_consumes_only_signed_action_receipt' => $this->flag($input, 'merge_surface_consumes_only_signed_action_receipt'),
            'last_minute_diff_and_hot_scope_checks_pass' => $this->flag($input, 'last_minute_diff_and_hot_scope_checks_pass'),
            'final_merge_evidence_can_be_emitted' => $this->flag($input, 'final_merge_evidence_can_be_emitted'),
        ];

        $met = [];
        $unmet = [];
        foreach (self::RELEASE_CONDITIONS as $name) {
            if (($conditions[$name] ?? false) === true) {
                $met[] = $name;
            } else {
                $unmet[] = $name;
            }
        }

        return [
            'surface' => self::SURFACE_PREFLIGHT,
            'schema' => self::SCHEMA,
            'conditions' => $conditions,
            'met' => $met,
            'unmet' => $unmet,
            // A later MERGE SURFACE could proceed only when all five hold. This
            // flag describes that future possibility; it proceeds with nothing.
            'merge_surface_may_proceed' => $unmet === [],
            'proceeds_with_merge' => false,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Surface 3 — the read-only PERSISTENCE TEMPLATE.
     *
     * Defines the future append-only event WITHOUT writing it: pins the exact
     * event type and the fourteen fields it must include. It forbids ledger
     * writes, signature acceptance, signature validation, receipt persistence,
     * decision recording, approval, merge and dispatch.
     *
     * @return array{
     *   surface:string, schema:string,
     *   event_type:string, event_fields:list<string>,
     *   writes_event:false, forbids_ledger_write:true,
     *   forbidden_actions:list<string>, boundary:array<string,false>
     * }
     */
    public function persistenceTemplate(): array
    {
        return [
            'surface' => self::SURFACE_PERSISTENCE_TEMPLATE,
            'schema' => self::SCHEMA,
            'event_type' => self::PERSISTENCE_EVENT_TYPE,
            'event_fields' => self::PERSISTENCE_EVENT_FIELDS,
            // "defines the future append-only event without writing it."
            'writes_event' => false,
            'forbids_ledger_write' => true,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Composite entrypoint: evaluate all three surfaces (plus the release-condition
     * view) under a single input and prove the boundary held across every result.
     *
     * With safe (empty) defaults every persistence blocker is active, no release
     * condition is met, and nothing is signed, validated, persisted or merged.
     *
     * @param array{
     *   preflight?:array<string,mixed>,
     *   release?:array<string,mixed>
     * } $input
     * @return array{
     *   schema:string,
     *   signed_receipt_template:array<string,mixed>,
     *   persistence_preflight:array<string,mixed>,
     *   release_conditions:array<string,mixed>,
     *   persistence_template:array<string,mixed>,
     *   may_approach_persistence:bool, merge_surface_may_proceed:bool,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(array $input = []): array
    {
        $template = $this->signedReceiptTemplate();
        $preflight = $this->persistencePreflight($input['preflight'] ?? []);
        $release = $this->releaseConditions($input['release'] ?? []);
        $persistence = $this->persistenceTemplate();

        $violations = $this->assertBoundaryHeld([$template, $preflight, $release, $persistence]);

        return [
            'schema' => self::SCHEMA,
            'signed_receipt_template' => $template,
            'persistence_preflight' => $preflight,
            'release_conditions' => $release,
            'persistence_template' => $persistence,
            'may_approach_persistence' => ($preflight['may_approach_persistence'] ?? false) === true,
            'merge_surface_may_proceed' => ($release['merge_surface_may_proceed'] ?? false) === true,
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
        }

        return $violations;
    }

    /**
     * A string field is "present" only when it exists and is a non-empty string
     * after trimming. Anything else (missing, null, non-string, blank) is treated
     * as absent — fail-closed.
     *
     * @param array<string,mixed> $input
     */
    private function present(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;

        return is_string($value) && trim($value) !== '';
    }

    /**
     * A boolean flag is cleared ONLY by an exact boolean true; any other value
     * (or absence) is false — fail-closed.
     *
     * @param array<string,mixed> $input
     */
    private function flag(array $input, string $key): bool
    {
        return ($input[$key] ?? null) === true;
    }

    /**
     * Two hash fields "match" only when BOTH are present non-empty strings AND
     * equal. Absence on either side is a mismatch — fail-closed.
     *
     * @param array<string,mixed> $input
     */
    private function hashesMatch(array $input, string $expectedKey, string $actualKey): bool
    {
        if (! $this->present($input, $expectedKey) || ! $this->present($input, $actualKey)) {
            return false;
        }

        return $input[$expectedKey] === $input[$actualKey];
    }
}
