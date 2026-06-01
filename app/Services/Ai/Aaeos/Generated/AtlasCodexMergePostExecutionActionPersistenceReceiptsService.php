<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Codex Merge Post-Execution Action PERSISTENCE RECEIPTS — pure, deterministic,
 * READ-ONLY draft logic for a FUTURE, separately governed append-only persistence
 * surface.
 *
 * The doc governs a read-only DRAFT of a future append-only persistence event for
 * a signed post-execution Codex merge action receipt, plus a read-only
 * PERSISTENCE PREFLIGHT that lists the blockers for that future surface. It
 * governs NOTHING that can sign, validate, persist, record, approve, merge or
 * dispatch.
 *
 *   1. persistenceReceiptDraft() — defines the shape of a FUTURE persistence
 *                                  receipt (its bound source hashes, the future
 *                                  event type and fields, the required evidence,
 *                                  the forbidden authorities) while writing
 *                                  nothing. It is NOT proof a receipt was persisted.
 *   2. readiness()               — the draft is "ready" ONLY when the signed
 *                                  action receipt persistence template is ready;
 *                                  ready still means read-only.
 *   3. persistencePreflight()    — inspects the draft and lists every blocker for
 *                                  a future append-only persistence surface;
 *                                  fail-closed; ready only as a blocker report.
 *
 * Hard boundary (doc "Boundary") — the draft must keep ALL EIGHT keys false,
 * always:
 *   execution_allowed=false, ledger_write_allowed=false, dispatch_allowed=false,
 *   approval_granted=false, merge_allowed=false, signature_valid=false,
 *   receipt_persisted=false, receipt_signed=false.
 *
 * And it must not, and this code does not: accept signatures, validate
 * signatures, write the ledger, persist receipts, record decisions, approve code,
 * merge, or dispatch work.
 *
 * Documented invariants this code ENFORCES (not merely documents):
 *   - "must keep ...=false" (doc "Boundary") => boundary() returns all eight keys
 *     false and every public result embeds it verbatim; assertBoundaryHeld()
 *     proves no result ever flipped a key true.
 *   - "The draft must be bound to" 6 source hashes (doc "Required Source Hashes")
 *     => persistenceReceiptDraft() always lists exactly those six bound source
 *     hashes, in documented order.
 *   - "The future append-only event type is
 *     CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED" and "may
 *     list the future event fields, including" 5 fields (doc "Future Event") =>
 *     the draft pins that exact event type and those five fields and writes the
 *     event NOT (writes_event=false).
 *   - "A future persistence receipt must provide" 8 evidence items (doc "Required
 *     Evidence") => surfaced exactly, in order.
 *   - "The draft must explicitly keep these authorities forbidden" 8 authorities
 *     (doc "Forbidden Authority") => surfaced exactly, in order.
 *   - "The receipt draft is ready only when the signed action receipt persistence
 *     template is ready" (doc "Readiness Rule") => readiness() flips to the
 *     documented READY status ONLY when the template-ready flag is exactly true;
 *     otherwise the documented BLOCKED status. Ready still keeps read_only=true.
 *   - "It must remain read-only and must report blockers such as" 10 conditions
 *     (doc "Persistence Preflight") => persistencePreflight() evaluates ALL ten
 *     blockers against supplied evidence; fail-closed, an empty input trips every
 *     blocker and the future persistence stays NOT approachable.
 *     `may_approach_persistence` is true ONLY when zero blockers remain — and even
 *     then the boundary stays all-false (the preflight does not make persistence
 *     legal by itself).
 *
 * Human meaning (doc "Human Meaning"): this surface answers "IF a future governed
 * surface persisted the signed action receipt, what exact receipt, hashes,
 * evidence and event shape would prove it?" — and explicitly does NOT answer "was
 * the signed receipt actually persisted?" (that stays blocked until a later
 * append-only persistence implementation exists). A persistence receipt draft is
 * NOT proof that a receipt was persisted (doc frontmatter decision).
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-receipts.md
 */
final class AtlasCodexMergePostExecutionActionPersistenceReceiptsService
{
    /** Stable evidence schema id this read-only surface family emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_persistence_receipts.v1';

    /** Surface labels (closed set). */
    public const SURFACE_DRAFT = 'persistence_receipt_draft';
    public const SURFACE_PREFLIGHT = 'persistence_preflight';

    /** Readiness statuses (doc "Readiness Rule"). */
    public const STATUS_DRAFT_BLOCKED = 'merge_post_execution_action_signed_receipt_persistence_receipt_draft_blocked';
    public const STATUS_DRAFT_READY = 'merge_post_execution_action_signed_receipt_persistence_receipt_draft_ready';

    /** Preflight statuses (read-only; "ready" means a complete blocker report). */
    public const STATUS_PREFLIGHT_BLOCKED = 'persistence_preflight_blocked';
    public const STATUS_PREFLIGHT_CLEAR = 'persistence_preflight_ready';

    /** The future append-only event type (doc "Future Event"). */
    public const FUTURE_EVENT_TYPE = 'CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED';

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
        'signature_valid',
        'receipt_persisted',
        'receipt_signed',
    ];

    /**
     * Actions the draft must NOT perform (doc "Boundary" → "It must not:").
     * Surfaced so the boundary is self-describing; the code performs none.
     *
     * @var list<string>
     */
    public const FORBIDDEN_ACTIONS = [
        'accept_signatures',
        'validate_signatures',
        'write_the_ledger',
        'persist_receipts',
        'record_decisions',
        'approve_code',
        'merge',
        'dispatch_work',
    ];

    /**
     * The six source hashes the draft must be BOUND to (doc "Required Source
     * Hashes"). Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_SOURCE_HASHES = [
        'source_signed_action_receipt_persistence_template_hash',
        'source_signed_action_receipt_preflight_hash',
        'source_signed_action_receipt_template_hash',
        'source_action_signature_request_hash',
        'source_action_signable_payload_hash',
        'source_action_receipt_hash',
    ];

    /**
     * The five future append-only event fields the draft may list (doc "Future
     * Event" → "including"). Order preserved exactly.
     *
     * @var list<string>
     */
    public const FUTURE_EVENT_FIELDS = [
        'signed_action_receipt_hash',
        'append_only_event_hash',
        'ledger_sequence_number',
        'persistence_actor_identity',
        'persistence_timestamp',
    ];

    /**
     * The eight evidence items a future persistence receipt must PROVIDE (doc
     * "Required Evidence"). Order preserved exactly.
     *
     * @var list<string>
     */
    public const REQUIRED_EVIDENCE = [
        'signed_action_receipt_hash',
        'append_only_event_hash',
        'ledger_sequence_number',
        'persistence_actor_identity',
        'persistence_timestamp',
        'source_hash_match_report',
        'hot_scope_recheck_report',
        'unreviewed_diff_absence_report',
    ];

    /**
     * The eight authorities the draft must explicitly keep FORBIDDEN (doc
     * "Forbidden Authority"). Order preserved exactly.
     *
     * @var list<string>
     */
    public const FORBIDDEN_AUTHORITIES = [
        'ledger_write_by_post_execution_action_signed_receipt_persistence_receipt_draft',
        'signature_acceptance_by_post_execution_action_signed_receipt_persistence_receipt_draft',
        'signature_validation_by_post_execution_action_signed_receipt_persistence_receipt_draft',
        'receipt_persistence_by_post_execution_action_signed_receipt_persistence_receipt_draft',
        'decision_recording_by_post_execution_action_signed_receipt_persistence_receipt_draft',
        'approval_from_post_execution_action_signed_receipt_persistence_receipt_draft',
        'merge_from_post_execution_action_signed_receipt_persistence_receipt_draft',
        'dispatch_from_post_execution_action_signed_receipt_persistence_receipt_draft',
    ];

    /**
     * The ten blockers the persistence preflight must REPORT (doc "Persistence
     * Preflight" → "must report blockers such as"). Order preserved exactly; each
     * maps to a predicate in persistencePreflight().
     *
     * @var list<string>
     */
    public const PREFLIGHT_BLOCKERS = [
        'missing_persistence_actor_identity',
        'missing_persistence_timestamp',
        'missing_signed_action_receipt_hash',
        'missing_append_only_event_hash',
        'missing_ledger_sequence_number',
        'missing_source_hash_match_report',
        'missing_hot_scope_recheck_report',
        'missing_unreviewed_diff_absence_report',
        'missing_human_persistence_confirmation',
        'missing_append_only_ledger_write_surface',
    ];

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
     * Surface 1 — the read-only PERSISTENCE RECEIPT DRAFT.
     *
     * Defines the shape of a FUTURE append-only persistence receipt: the six
     * source hashes it would be bound to, the future event type and fields, the
     * eight evidence items a future persistence receipt must provide, and the
     * eight authorities it keeps forbidden — while writing nothing, persisting
     * nothing, signing nothing, recording nothing, approving nothing and merging
     * nothing. The boundary is always all-false. It is NOT proof a receipt was
     * persisted.
     *
     * @return array{
     *   surface:string, schema:string,
     *   future_event_type:string, future_event_fields:list<string>,
     *   required_source_hashes:list<string>,
     *   required_evidence:list<string>,
     *   forbidden_authorities:list<string>,
     *   forbidden_actions:list<string>,
     *   writes_event:false, is_proof_of_persistence:false,
     *   describes_future_persistence_receipt:true,
     *   answers_was_receipt_persisted:false,
     *   boundary:array<string,false>
     * }
     */
    public function persistenceReceiptDraft(): array
    {
        return [
            'surface' => self::SURFACE_DRAFT,
            'schema' => self::SCHEMA,
            'future_event_type' => self::FUTURE_EVENT_TYPE,
            'future_event_fields' => self::FUTURE_EVENT_FIELDS,
            'required_source_hashes' => self::REQUIRED_SOURCE_HASHES,
            'required_evidence' => self::REQUIRED_EVIDENCE,
            'forbidden_authorities' => self::FORBIDDEN_AUTHORITIES,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            // Doc "Future Event": the draft lists the event but does NOT write it.
            'writes_event' => false,
            // Doc frontmatter decision: a draft is NOT proof a receipt was persisted.
            'is_proof_of_persistence' => false,
            'describes_future_persistence_receipt' => true,
            // Doc "Human Meaning": it does not answer "was it persisted?".
            'answers_was_receipt_persisted' => false,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Surface 2 — READINESS (doc "Readiness Rule").
     *
     * The receipt draft is "ready" ONLY when the signed action receipt persistence
     * template is ready. Ready still means READ-ONLY: it means the receipt can be
     * reviewed as a contract, not that persistence happened.
     *
     * @param array<string,mixed> $input recognised key (fail-closed):
     *   signed_action_receipt_persistence_template_ready (bool).
     * @return array{
     *   surface:string, schema:string, status:string,
     *   template_ready:bool, draft_ready:bool, read_only:true,
     *   means_persistence_happened:false, boundary:array<string,false>
     * }
     */
    public function readiness(array $input = []): array
    {
        $templateReady = $this->flag($input, 'signed_action_receipt_persistence_template_ready');

        return [
            'surface' => self::SURFACE_DRAFT,
            'schema' => self::SCHEMA,
            // Ready ONLY when the persistence template is ready.
            'status' => $templateReady ? self::STATUS_DRAFT_READY : self::STATUS_DRAFT_BLOCKED,
            'template_ready' => $templateReady,
            'draft_ready' => $templateReady,
            // "The ready state still means read-only."
            'read_only' => true,
            'means_persistence_happened' => false,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Surface 3 — the read-only PERSISTENCE PREFLIGHT.
     *
     * Inspects the draft and lists the blockers for a FUTURE append-only
     * persistence surface. It evaluates all ten documented blockers against the
     * evidence supplied. Fail-closed: anything missing keeps the corresponding
     * blocker active, and the future persistence may be approached ONLY when zero
     * blockers remain. The preflight can become "ready" only as a blocker report;
     * it does NOT make the future persistence legal by itself, and the boundary
     * stays all-false even when zero blockers remain.
     *
     * @param array<string,mixed> $input recognised keys (all fail-closed):
     *   persistence_actor_identity (non-empty string),
     *   persistence_timestamp (non-empty string),
     *   signed_action_receipt_hash (non-empty string),
     *   append_only_event_hash (non-empty string),
     *   ledger_sequence_number (non-empty string or int),
     *   source_hash_match_report (non-empty string),
     *   hot_scope_recheck_report (non-empty string),
     *   unreviewed_diff_absence_report (non-empty string),
     *   human_persistence_confirmation (bool),
     *   append_only_ledger_write_surface (bool).
     * @return array{
     *   surface:string, schema:string, status:string,
     *   blockers:array<string,bool>, active_blockers:list<string>,
     *   cleared_blockers:list<string>, blocker_count:int,
     *   may_approach_persistence:bool, makes_persistence_legal:false,
     *   forbidden_authorities:list<string>, read_only:true,
     *   boundary:array<string,false>
     * }
     */
    public function persistencePreflight(array $input = []): array
    {
        // Each entry is TRUE when the blocker is ACTIVE (i.e. persistence blocked).
        $blockers = [
            'missing_persistence_actor_identity' => ! $this->present($input, 'persistence_actor_identity'),
            'missing_persistence_timestamp' => ! $this->present($input, 'persistence_timestamp'),
            'missing_signed_action_receipt_hash' => ! $this->present($input, 'signed_action_receipt_hash'),
            'missing_append_only_event_hash' => ! $this->present($input, 'append_only_event_hash'),
            // Ledger sequence number may legitimately arrive as an int.
            'missing_ledger_sequence_number' => ! $this->presentScalar($input, 'ledger_sequence_number'),
            'missing_source_hash_match_report' => ! $this->present($input, 'source_hash_match_report'),
            'missing_hot_scope_recheck_report' => ! $this->present($input, 'hot_scope_recheck_report'),
            'missing_unreviewed_diff_absence_report' => ! $this->present($input, 'unreviewed_diff_absence_report'),
            'missing_human_persistence_confirmation' => ! $this->flag($input, 'human_persistence_confirmation'),
            'missing_append_only_ledger_write_surface' => ! $this->flag($input, 'append_only_ledger_write_surface'),
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
            // The strongest thing this read-only surface can say. It is never a
            // signature, a persistence, an approval or a merge.
            'may_approach_persistence' => $mayApproach,
            // "It does not make the future persistence legal by itself."
            'makes_persistence_legal' => false,
            'forbidden_authorities' => self::FORBIDDEN_AUTHORITIES,
            'read_only' => true,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Composite entrypoint: evaluate the draft, readiness and preflight under a
     * single input and prove the boundary held across every result.
     *
     * With safe (empty) defaults the template is not ready (so the draft is
     * blocked), every persistence blocker is active, and nothing is signed,
     * validated, persisted, recorded, approved or merged.
     *
     * @param array{
     *   readiness?:array<string,mixed>,
     *   preflight?:array<string,mixed>
     * } $input
     * @return array{
     *   schema:string,
     *   persistence_receipt_draft:array<string,mixed>,
     *   readiness:array<string,mixed>,
     *   persistence_preflight:array<string,mixed>,
     *   draft_ready:bool, may_approach_persistence:bool,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(array $input = []): array
    {
        $draft = $this->persistenceReceiptDraft();
        $readiness = $this->readiness($input['readiness'] ?? []);
        $preflight = $this->persistencePreflight($input['preflight'] ?? []);

        $violations = $this->assertBoundaryHeld([$draft, $readiness, $preflight]);

        return [
            'schema' => self::SCHEMA,
            'persistence_receipt_draft' => $draft,
            'readiness' => $readiness,
            'persistence_preflight' => $preflight,
            'draft_ready' => ($readiness['draft_ready'] ?? false) === true,
            'may_approach_persistence' => ($preflight['may_approach_persistence'] ?? false) === true,
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
     * A scalar field (e.g. a ledger sequence number) is "present" when it is a
     * non-empty string after trimming, OR an integer, OR a float. Anything else
     * (missing, null, bool, array, blank string) is absent — fail-closed.
     *
     * @param array<string,mixed> $input
     */
    private function presentScalar(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return true;
        }

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
}
