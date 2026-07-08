<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Codex Merge Post-Execution Action PERSISTENCE PAYLOAD — pure, deterministic,
 * READ-ONLY append-only event PAYLOAD TEMPLATE for a FUTURE signed
 * post-execution Codex merge action receipt persistence surface.
 *
 * The doc governs ONLY the read-only payload TEMPLATE: the exact shape an
 * append-only persistence event WOULD take. It defines fields and source-hash
 * slots, but it must NOT write the ledger, accept or validate signatures,
 * persist receipts, record decisions, approve code, merge or dispatch. The
 * template is NOT proof of persistence and NOT a merge authorization
 * (frontmatter decisions).
 *
 * Surfaces:
 *   1. payloadTemplate()      — emits the future event shape: the event type and
 *                               all fifteen required fields, every value held
 *                               NULL ("intentional placeholders until an external
 *                               writer surface is separately authorized"). Writes
 *                               nothing.
 *   2. readiness()            — the template is "ready" ONLY when the post-preflight
 *                               persistence runbook is ready (doc "Readiness Rule").
 *                               Ready means the contract can be INSPECTED; it does
 *                               not permit writing.
 *   3. beforeWriteGate()      — evaluates the six "Before Write Conditions" a future
 *                               writer surface must prove. Fail-closed: empty input
 *                               leaves every condition unmet and `may_write=false`.
 *                               Even when all six are met, the boundary stays
 *                               all-false — the gate never makes a write legal by
 *                               itself.
 *
 * Hard boundary (doc "Boundary") — every result keeps ALL EIGHT keys false,
 * always:
 *   execution_allowed=false, ledger_write_allowed=false, dispatch_allowed=false,
 *   approval_granted=false, merge_allowed=false, signature_valid=false,
 *   receipt_persisted=false, receipt_signed=false.
 *
 * Documented invariants this code ENFORCES (not merely documents):
 *   - "must keep ...=false" (doc "Boundary") => boundary() returns all eight keys
 *     false; every public result embeds it verbatim; assertBoundaryHeld() proves
 *     no result ever flipped a key, and the assertion is non-vacuous.
 *   - "The template must include" FIFTEEN fields (doc "Required Fields") =>
 *     payloadTemplate() emits exactly those fifteen field names, in documented
 *     order, and "Fields that require future evidence must stay null in this
 *     read-only template" => every field value is null and
 *     all_fields_null=true.
 *   - "The future event type remains
 *     CODEX_REVIEW_MERGE_POST_EXECUTION_ACTION_SIGNED_RECEIPT_PERSISTED" (doc
 *     "Event Type") => pinned exactly; the template writes the event NOT
 *     (writes_event=false).
 *   - "The payload template is ready only when the post-preflight persistence
 *     runbook is ready" (doc "Readiness Rule") => readiness() flips to the
 *     documented READY status ONLY when post_preflight_runbook_ready is exactly
 *     true; otherwise the documented BLOCKED status. "Ready means the payload
 *     contract can be inspected. It does not permit writing." => readiness keeps
 *     permits_write=false even when ready.
 *   - "A future writer surface must prove" SIX conditions (doc "Before Write
 *     Conditions") => beforeWriteGate() evaluates all six, fail-closed; empty
 *     input leaves all unmet and may_write=false; may_write is true ONLY when all
 *     six are met — and even then the boundary stays all-false.
 *
 * Human meaning (doc "Human Meaning"): this surface answers "What exact
 * append-only event payload would a future writer need?" — it explicitly does
 * NOT answer "Can the payload be written now?" (that remains blocked until a
 * separate writer surface exists and is authorized).
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-payload.md
 */
final class AtlasCodexMergePEAPPayloadService
{
    /** Stable evidence schema id this read-only surface family emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_persistence_payload.v1';

    /** Surface labels (closed set). */
    public const SURFACE_PAYLOAD = 'payload_template';
    public const SURFACE_READINESS = 'readiness';
    public const SURFACE_BEFORE_WRITE = 'before_write_gate';

    /** Readiness statuses (doc "Readiness Rule"). */
    public const STATUS_BLOCKED = 'merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_blocked';
    public const STATUS_READY = 'merge_post_execution_action_signed_receipt_persistence_append_only_event_payload_template_ready';

    /** The future append-only event type (doc "Event Type"). */
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
     * Actions the template must NOT perform (doc "Boundary" → "It must not:").
     * Surfaced so the boundary is self-describing; the code performs none.
     *
     * @var list<string>
     */
    public const FORBIDDEN_ACTIONS = [
        'accept_signatures',
        'validate_signatures',
        'write_append_only_events',
        'persist_receipts',
        'record_decisions',
        'approve_code',
        'merge',
        'dispatch_work',
    ];

    /**
     * The fifteen fields the payload template must include (doc "Required
     * Fields"). Order preserved exactly as documented. Every value stays null in
     * this read-only template (doc: "Fields that require future evidence must stay
     * null").
     *
     * @var list<string>
     */
    public const REQUIRED_FIELDS = [
        'event_id',
        'event_type',
        'signed_action_receipt_id',
        'signed_action_receipt_hash',
        'source_persistence_post_preflight_runbook_hash',
        'source_persistence_preflight_hash',
        'source_persistence_receipt_draft_hash',
        'append_only_event_hash',
        'ledger_sequence_number',
        'persistence_actor_identity',
        'persistence_timestamp',
        'human_persistence_confirmation_hash',
        'source_hash_match_report_hash',
        'hot_scope_recheck_report_hash',
        'unreviewed_diff_absence_report_hash',
    ];

    /**
     * The six conditions a future writer surface must PROVE before any write (doc
     * "Before Write Conditions"). Order preserved exactly; each maps to a predicate
     * in beforeWriteGate().
     *
     * @var list<string>
     */
    public const BEFORE_WRITE_CONDITIONS = [
        'post_preflight_runbook_ready',
        'all_runbook_steps_have_evidence',
        'all_preflight_blockers_resolved',
        'all_required_payload_fields_non_null',
        'payload_hash_recomputed_by_writer',
        'writer_surface_separately_authorized',
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
     * Surface 1 — the read-only append-only event PAYLOAD TEMPLATE.
     *
     * Emits the future event shape (doc "Event Type" + "Required Fields"): the
     * event type and all fifteen required field names. Every field VALUE is null —
     * "Null payload fields are intentional placeholders until an external writer
     * surface is separately authorized" (frontmatter decision) — and the template
     * writes nothing. It is NOT proof of persistence and NOT a merge authorization.
     *
     * @return array{
     *   surface:string, schema:string,
     *   future_event_type:string,
     *   required_fields:list<string>,
     *   fields:array<string,null>,
     *   all_fields_null:true,
     *   forbidden_actions:list<string>,
     *   writes_event:false,
     *   is_proof_of_persistence:false,
     *   is_merge_authorization:false,
     *   describes_future_event_payload:true,
     *   answers_can_payload_be_written_now:false,
     *   boundary:array<string,false>
     * }
     */
    public function payloadTemplate(): array
    {
        // Doc "Required Fields": list exactly the fifteen names; every value null.
        $fields = [];
        foreach (self::REQUIRED_FIELDS as $name) {
            $fields[$name] = null;
        }

        return [
            'surface' => self::SURFACE_PAYLOAD,
            'schema' => self::SCHEMA,
            'future_event_type' => self::FUTURE_EVENT_TYPE,
            'required_fields' => self::REQUIRED_FIELDS,
            'fields' => $fields,
            'all_fields_null' => true,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            // Doc "Boundary": the template defines the shape but does NOT write it.
            'writes_event' => false,
            // Frontmatter decisions: the template is not proof of persistence and
            // not a merge authorization.
            'is_proof_of_persistence' => false,
            'is_merge_authorization' => false,
            'describes_future_event_payload' => true,
            // Doc "Human Meaning": it does not answer "Can the payload be written now?".
            'answers_can_payload_be_written_now' => false,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Surface 2 — READINESS (doc "Readiness Rule").
     *
     * The payload template is "ready" ONLY when the post-preflight persistence
     * runbook is ready. "Ready means the payload contract can be inspected. It does
     * not permit writing." — so permits_write stays false even when ready.
     *
     * @param array<string,mixed> $input recognised key (fail-closed):
     *   post_preflight_runbook_ready (bool).
     * @return array{
     *   surface:string, schema:string, status:string,
     *   post_preflight_runbook_ready:bool, template_ready:bool,
     *   permits_inspection:bool, permits_write:false,
     *   boundary:array<string,false>
     * }
     */
    public function readiness(array $input = []): array
    {
        $runbookReady = $this->flag($input, 'post_preflight_runbook_ready');

        return [
            'surface' => self::SURFACE_READINESS,
            'schema' => self::SCHEMA,
            // Ready ONLY when the post-preflight persistence runbook is ready.
            'status' => $runbookReady ? self::STATUS_READY : self::STATUS_BLOCKED,
            'post_preflight_runbook_ready' => $runbookReady,
            'template_ready' => $runbookReady,
            // "Ready means the payload contract can be inspected."
            'permits_inspection' => $runbookReady,
            // "It does not permit writing." — never, regardless of readiness.
            'permits_write' => false,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Surface 3 — the read-only BEFORE-WRITE GATE (doc "Before Write Conditions").
     *
     * Evaluates the six conditions a FUTURE writer surface must prove before any
     * append-only write. Fail-closed: anything missing leaves the condition unmet.
     * `may_write` is true ONLY when all six conditions are met — and even then the
     * boundary stays all-false: this read-only gate never makes a write legal by
     * itself.
     *
     * @param array<string,mixed> $input recognised keys (all fail-closed, each bool):
     *   post_preflight_runbook_ready,
     *   all_runbook_steps_have_evidence,
     *   all_preflight_blockers_resolved,
     *   all_required_payload_fields_non_null,
     *   payload_hash_recomputed_by_writer,
     *   writer_surface_separately_authorized.
     * @return array{
     *   surface:string, schema:string,
     *   conditions:array<string,bool>, met_conditions:list<string>,
     *   unmet_conditions:list<string>, unmet_count:int,
     *   may_write:bool, makes_write_legal:false,
     *   forbidden_actions:list<string>, read_only:true,
     *   boundary:array<string,false>
     * }
     */
    public function beforeWriteGate(array $input = []): array
    {
        // Each entry is TRUE only when the future writer has PROVEN that condition.
        $conditions = [];
        foreach (self::BEFORE_WRITE_CONDITIONS as $name) {
            $conditions[$name] = $this->flag($input, $name);
        }

        $met = [];
        $unmet = [];
        foreach (self::BEFORE_WRITE_CONDITIONS as $name) {
            if (($conditions[$name] ?? false) === true) {
                $met[] = $name;
            } else {
                $unmet[] = $name;
            }
        }

        // The strongest thing this read-only gate can say: every condition proven.
        // It is never a signature, a persistence, an approval or a merge.
        $mayWrite = $unmet === [];

        return [
            'surface' => self::SURFACE_BEFORE_WRITE,
            'schema' => self::SCHEMA,
            'conditions' => $conditions,
            'met_conditions' => $met,
            'unmet_conditions' => $unmet,
            'unmet_count' => count($unmet),
            'may_write' => $mayWrite,
            // The gate does not make a write legal by itself.
            'makes_write_legal' => false,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'read_only' => true,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Composite entrypoint: build the payload template, evaluate readiness and the
     * before-write gate under a single input, and prove the boundary held across
     * every result.
     *
     * With safe (empty) defaults the post-preflight runbook is not ready (so the
     * template is blocked), every before-write condition is unmet (so a write may
     * not proceed), and nothing is signed, validated, persisted, recorded, approved
     * or merged.
     *
     * @param array{
     *   readiness?:array<string,mixed>,
     *   before_write?:array<string,mixed>
     * } $input
     * @return array{
     *   schema:string,
     *   payload_template:array<string,mixed>,
     *   readiness:array<string,mixed>,
     *   before_write_gate:array<string,mixed>,
     *   template_ready:bool, may_write:bool,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(array $input = []): array
    {
        $payload = $this->payloadTemplate();
        $readiness = $this->readiness($input['readiness'] ?? []);
        $gate = $this->beforeWriteGate($input['before_write'] ?? []);

        $violations = $this->assertBoundaryHeld([$payload, $readiness, $gate]);

        return [
            'schema' => self::SCHEMA,
            'payload_template' => $payload,
            'readiness' => $readiness,
            'before_write_gate' => $gate,
            'template_ready' => ($readiness['template_ready'] ?? false) === true,
            'may_write' => ($gate['may_write'] ?? false) === true,
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
