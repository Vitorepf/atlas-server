<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Codex Merge Writer Release Signed Receipt — pure, deterministic read-only
 * template logic.
 *
 * Governs the read-only signed receipt *template* for a FUTURE writer release
 * receipt. The whole point of the surface is to answer one human question —
 * "What would a signed writer release receipt need to contain?" — while itself
 * accepting nothing, validating nothing, signing nothing, persisting nothing
 * and releasing nothing. It does NOT answer "has the writer been released or
 * has the receipt been persisted?": that answer stays no.
 *
 * Contract (from the doc "Boundary", "Required Upstream Contract",
 * "Future Execution Preconditions" and "Human Meaning" sections):
 *
 *   Boundary (always, every call) -> boundary()
 *     execution_allowed=false, writer_file_creation_allowed=false,
 *     ledger_write_allowed=false, dispatch_allowed=false, approval_granted=false,
 *     merge_allowed=false, signature_valid=false, receipt_signed=false,
 *     receipt_persisted=false. The template must additionally NOT create writer
 *     files, write ledger events, persist receipts, accept or validate
 *     signatures, record decisions, approve code, merge or dispatch work.
 *
 *   Required Upstream Contract -> template()
 *     The template depends on the writer release post-signature runbook. If the
 *     runbook is not ready, the surface must return the exact status
 *     `blocked_before_writer_release_post_signature_runbook` and stay not-ready.
 *
 *   Future Execution Preconditions -> futureExecutionPreconditions()
 *     Before any writer release execution contract can exist, a LATER surface
 *     must prove eight things (signed receipt template ready; external validated
 *     signature evidence present; selected decision == authorize_writer_release;
 *     writer contract hash still matches the patch; hot scope still clean;
 *     writer capability tests still pass; writer has no merge authority; writer
 *     has no dispatch authority). This method computes which are met / unmet and
 *     a single `execution_contract_unlockable` flag — it unlocks nothing itself.
 *
 * Documented invariants this code enforces (not merely documents):
 *   - "must keep ... =false" boundary => boundary() returns all-false and the
 *     template embeds it verbatim; assertBoundaryHeld() proves no surface flipped
 *     a key true.
 *   - "If the runbook is not ready, this surface must return
 *     blocked_before_writer_release_post_signature_runbook" => template() yields
 *     exactly that status (and ready=false) whenever the runbook is not proven
 *     ready, regardless of any other input.
 *   - "Before any writer release execution contract can exist, a later surface
 *     must prove: ..." (8 items) => futureExecutionPreconditions maps each to a
 *     real predicate; execution_contract_unlockable is true only when all eight
 *     hold, AND it stays a *description of a later surface* — boundary remains
 *     all-false even when unlockable.
 *   - "writer has no merge authority" / "writer has no dispatch authority" are
 *     proven by REQUIRING the writer to be without those authorities: the
 *     precondition is met only when the writer authority is explicitly false.
 *
 * Non-goals honoured (read-only): never creates writer files, never writes
 * ledger, never persists receipts, never accepts/validates signatures, never
 * records decisions, never approves, never merges, never dispatches.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-signed-receipt.md
 */
final class AtlasCodexMergeReleaseSignedReceiptService
{
    /** Stable evidence schema id this read-only template emits. */
    public const SCHEMA_SIGNED_RECEIPT_TEMPLATE = 'atlas.self_construction_codex_review_merge_writer_release_signed_receipt_template.v1';

    /** Surface label (closed set). */
    public const SURFACE_SIGNED_RECEIPT_TEMPLATE = 'writer_release_signed_receipt_template';

    /** Exact status the doc mandates when the upstream runbook is not ready. */
    public const STATUS_BLOCKED_UPSTREAM = 'blocked_before_writer_release_post_signature_runbook';

    /** Status when the runbook is ready and the boundary held. */
    public const STATUS_TEMPLATE_READY = 'signed_receipt_template_ready';

    /** The selected decision a later surface must equal to ever proceed. */
    public const REQUIRED_DECISION = 'authorize_writer_release';

    /**
     * The nine boundary keys (doc "Boundary"): the template must keep all false.
     *
     * @var list<string>
     */
    public const BOUNDARY_KEYS = [
        'execution_allowed',
        'writer_file_creation_allowed',
        'ledger_write_allowed',
        'dispatch_allowed',
        'approval_granted',
        'merge_allowed',
        'signature_valid',
        'receipt_signed',
        'receipt_persisted',
    ];

    /**
     * Actions the template must NOT perform (doc "It must not:"). Surfaced so the
     * boundary is self-describing; the template performs none of them.
     *
     * @var list<string>
     */
    public const FORBIDDEN_ACTIONS = [
        'create_writer_files',
        'write_ledger_events',
        'persist_receipts',
        'accept_or_validate_signatures',
        'record_decisions',
        'approve_code',
        'merge',
        'dispatch_work',
    ];

    /**
     * The eight future execution preconditions (doc "Future Execution
     * Preconditions"). Order preserved; each maps to a predicate below.
     *
     * @var list<string>
     */
    public const FUTURE_EXECUTION_PRECONDITIONS = [
        'signed_receipt_template_ready',
        'external_validated_signature_evidence_present',
        'selected_decision_is_authorize_writer_release',
        'writer_contract_hash_matches_patch',
        'hot_scope_still_clean',
        'writer_capability_tests_pass',
        'writer_has_no_merge_authority',
        'writer_has_no_dispatch_authority',
    ];

    /**
     * The nine documented boundary keys, all forced false.
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
     * Signed Receipt Template surface.
     *
     * Describes the shape a FUTURE signed writer release receipt would need,
     * without accepting/validating any signature, signing, persisting, approving,
     * merging or dispatching. Honours the upstream dependency: when the writer
     * release post-signature runbook is not ready, returns the exact mandated
     * status and stays not-ready. Either way the boundary is all-false.
     *
     * @param array<string,mixed> $input documented signals:
     *   writer_release_post_signature_runbook_ready (bool) — the only gate that
     *     can let the template be considered "ready"; default false (fail-closed).
     * @return array{
     *   surface:string, schema:string, ready:bool, status:string,
     *   blocked:bool, depends_on:string, dependency_ready:bool,
     *   forbidden_actions:list<string>,
     *   describes_required_signed_receipt_contents:bool,
     *   answers_release_or_persistence:false,
     *   boundary:array<string,false>
     * }
     */
    public function template(array $input = []): array
    {
        $runbookReady = $this->signal($input, 'writer_release_post_signature_runbook_ready', false);

        // Doc: if the runbook is not ready, this surface MUST return the exact
        // blocked status — and a blocked template is never "ready".
        $blocked = ! $runbookReady;
        $status = $blocked ? self::STATUS_BLOCKED_UPSTREAM : self::STATUS_TEMPLATE_READY;

        return [
            'surface' => self::SURFACE_SIGNED_RECEIPT_TEMPLATE,
            'schema' => self::SCHEMA_SIGNED_RECEIPT_TEMPLATE,
            // "ready" here means "the template surface itself is ready to be read"
            // (i.e. the runbook dependency cleared). It never means a writer was
            // released or a receipt persisted — those boundary keys stay false.
            'ready' => $runbookReady,
            'status' => $status,
            'blocked' => $blocked,
            'depends_on' => 'writer_release_post_signature_runbook',
            'dependency_ready' => $runbookReady,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            // The doc "Human Meaning": it answers what a receipt must contain...
            'describes_required_signed_receipt_contents' => $runbookReady,
            // ...and explicitly does NOT answer whether release/persistence happened.
            'answers_release_or_persistence' => false,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Future Execution Preconditions surface.
     *
     * Computes which of the eight documented preconditions are met, before any
     * writer release execution contract may exist. Purely descriptive of a LATER
     * surface: it unlocks, releases, signs and persists nothing, and the boundary
     * stays all-false even when every precondition is met.
     *
     * @param array<string,mixed> $input documented signals (all default false /
     *   fail-closed unless explicitly proven):
     *     signed_receipt_template_ready (bool) — typically template()['ready'];
     *     external_validated_signature_evidence_present (bool);
     *     selected_decision (string) — must equal 'authorize_writer_release';
     *     writer_contract_hash_matches_patch (bool);
     *     hot_scope_still_clean (bool);
     *     writer_capability_tests_pass (bool);
     *     writer_has_merge_authority (bool) — precondition met only when FALSE;
     *     writer_has_dispatch_authority (bool) — precondition met only when FALSE.
     * @return array{
     *   surface:string, schema:string,
     *   preconditions:array<string,bool>, met:list<string>, unmet:list<string>,
     *   selected_decision:?string, required_decision:string,
     *   execution_contract_unlockable:bool,
     *   unlocks_execution:false, releases_writer:false,
     *   boundary:array<string,false>
     * }
     */
    public function futureExecutionPreconditions(array $input = []): array
    {
        $selectedDecision = $input['selected_decision'] ?? null;
        $selectedDecision = is_string($selectedDecision) ? $selectedDecision : null;

        $checks = [
            'signed_receipt_template_ready' => $this->signal($input, 'signed_receipt_template_ready', false),
            'external_validated_signature_evidence_present' => $this->signal($input, 'external_validated_signature_evidence_present', false),
            // "selected decision equals authorize_writer_release"
            'selected_decision_is_authorize_writer_release' => $selectedDecision === self::REQUIRED_DECISION,
            'writer_contract_hash_matches_patch' => $this->signal($input, 'writer_contract_hash_matches_patch', false),
            'hot_scope_still_clean' => $this->signal($input, 'hot_scope_still_clean', false),
            'writer_capability_tests_pass' => $this->signal($input, 'writer_capability_tests_pass', false),
            // "writer has no merge authority" — met only when authority is FALSE.
            'writer_has_no_merge_authority' => ! $this->signal($input, 'writer_has_merge_authority', true),
            // "writer has no dispatch authority" — met only when authority is FALSE.
            'writer_has_no_dispatch_authority' => ! $this->signal($input, 'writer_has_dispatch_authority', true),
        ];

        $met = [];
        $unmet = [];
        foreach (self::FUTURE_EXECUTION_PRECONDITIONS as $name) {
            if (($checks[$name] ?? false) === true) {
                $met[] = $name;
            } else {
                $unmet[] = $name;
            }
        }

        return [
            'surface' => self::SURFACE_SIGNED_RECEIPT_TEMPLATE,
            'schema' => self::SCHEMA_SIGNED_RECEIPT_TEMPLATE,
            'preconditions' => $checks,
            'met' => $met,
            'unmet' => $unmet,
            'selected_decision' => $selectedDecision,
            'required_decision' => self::REQUIRED_DECISION,
            // A later EXECUTION CONTRACT could exist only when all eight hold.
            // This flag describes that future possibility; it grants nothing now.
            'execution_contract_unlockable' => $unmet === [],
            'unlocks_execution' => false,
            'releases_writer' => false,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Primary entrypoint: evaluate the template against its upstream dependency
     * and the future execution preconditions (seeded by the template readiness),
     * then prove the boundary held across every surface.
     *
     * @param array{
     *   template?:array<string,mixed>,
     *   future_execution?:array<string,mixed>
     * } $input
     * @return array{
     *   schema:string,
     *   template:array<string,mixed>,
     *   future_execution_preconditions:array<string,mixed>,
     *   boundary_held:bool, boundary_violations:list<string>,
     *   execution_contract_unlockable:bool
     * }
     */
    public function contract(array $input = []): array
    {
        $template = $this->template($input['template'] ?? []);

        // Seed the precondition surface with the real template readiness so the
        // first documented precondition reflects the actual upstream state, while
        // still letting an explicit caller value override it.
        $futureInput = $input['future_execution'] ?? [];
        if (! array_key_exists('signed_receipt_template_ready', $futureInput)) {
            $futureInput['signed_receipt_template_ready'] = ($template['ready'] ?? false) === true;
        }
        $future = $this->futureExecutionPreconditions($futureInput);

        $violations = $this->assertBoundaryHeld([$template, $future]);

        return [
            'schema' => self::SCHEMA_SIGNED_RECEIPT_TEMPLATE,
            'template' => $template,
            'future_execution_preconditions' => $future,
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
            'execution_contract_unlockable' => ($future['execution_contract_unlockable'] ?? false) === true,
        ];
    }

    /**
     * Prove that no surface ever flipped a boundary key to a truthy value.
     * Returns the list of "surface.key" violations (empty = boundary intact).
     *
     * @param list<array<string,mixed>> $surfaces
     * @return list<string>
     */
    public function assertBoundaryHeld(array $surfaces): array
    {
        $violations = [];
        foreach ($surfaces as $surface) {
            $label = is_string($surface['surface'] ?? null) ? $surface['surface'] : 'unknown';
            $boundary = is_array($surface['boundary'] ?? null) ? $surface['boundary'] : [];
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
     * Read a boolean gate signal. A signal is cleared ONLY by an exact boolean
     * true; any other value (or absence) falls back to the supplied default. The
     * defaults are chosen fail-closed at each call site.
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
