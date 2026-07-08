<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release AUTHORIZATION
 * Receipt — pure, deterministic, READ-ONLY unsigned receipt DRAFT surface.
 *
 * IMPORTANT distinction from its siblings:
 *   - AtlasCodexMergePEAPWriterReleaseAuthSignatureReqService governs the
 *     "...writer-release-authorization-signature-request" doc (the ten-component
 *     signable payload a future external signature must cover).
 *   - AtlasCodexMergeReleaseAuthPreflightService governs the
 *     "...writer-release-authorization-preflight" doc.
 *   THIS surface governs the "...writer-release-authorization-RECEIPT" doc: the
 *   unsigned receipt DRAFT that describes what receipt would represent a future
 *   writer release authorization decision. It selects no authorization; its
 *   default decision deliberately requests external evidence instead.
 *
 * This surface answers exactly one question (doc "Human Meaning"):
 *
 *     "What unsigned receipt would represent a future writer release
 *      authorization decision?"
 *
 * It deliberately does NOT answer:
 *
 *     "Has the writer been authorized or released?"
 *
 * The answer to that second question is, and stays, no — it "remains blocked
 * until the receipt is signed, validated and consumed by a separate release
 * path." Signing, validation, signed-receipt persistence, ledger writes, writer
 * file creation, approval and any merge belong to LATER governed surfaces.
 *
 * Hard boundary (doc "Boundary") — every result this service emits keeps all
 * nine keys false, always:
 *   execution_allowed=false, writer_file_creation_allowed=false,
 *   ledger_write_allowed=false, dispatch_allowed=false, approval_granted=false,
 *   merge_allowed=false, signature_valid=false, receipt_signed=false,
 *   receipt_persisted=false.
 *
 * The surface must not, and this code does not (doc "It must not"): create
 * writer files, write ledger events, persist receipts, accept or validate
 * signatures, record decisions, approve code, merge, or dispatch work. It only
 * constructs a deterministic *unsigned receipt draft* that names the allowed
 * future decisions, pins the default to request-external-evidence, and names
 * the future signature inputs a later signature request would require.
 *
 * Documented invariants this code ENFORCES (not just documents):
 *   - "must keep ...=false" boundary => boundary() returns all nine keys false
 *     and every public result embeds it verbatim; assertBoundaryHeld() proves no
 *     result ever flipped a key true, and the assertion is non-vacuous.
 *   - "Allowed future decisions are: <four>" => allowedDecisions() returns
 *     exactly those four tokens, in documented order; isAllowedDecision()
 *     accepts only them.
 *   - "The default selected decision is request_external_writer_release_evidence"
 *     => the receipt draft's selected_decision is ALWAYS forced to that default,
 *     regardless of any caller-supplied value. A caller that tries to inject
 *     `authorize_writer_release` is overridden back to the safe default, and the
 *     override is reported. This is the doc's core safety rule:
 *     "prevents a missing-evidence receipt from being mistaken for writer
 *     release authorization."
 *   - "A later signature request may require: <six inputs>" =>
 *     futureSignatureInputs() lists exactly those six input names, in order, and
 *     marks each collected=false (this draft collects none of them).
 *   - "It does not answer 'Has the writer been authorized or released?'" =>
 *     authorizationStatus() always returns authorized=false / released=false
 *     with the documented deferral reason, structurally, regardless of input.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-receipt.md
 */
final class AtlasCodexMergePEAPWriterReleaseAuthReceiptService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_persistence_writer_release_authorization_receipt.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'writer_release_authorization_receipt_draft';

    /** Status when an unsigned receipt draft was constructed (never signed). */
    public const STATUS_RECEIPT_DRAFT_CONSTRUCTED = 'writer_release_authorization_receipt_draft_constructed';

    /**
     * The documented default selected decision (doc "Decisions").
     *
     * "That default prevents a missing-evidence receipt from being mistaken for
     * writer release authorization." This is forced on every draft.
     */
    public const DEFAULT_DECISION = 'request_external_writer_release_evidence';

    /** Documented reason the authorized/released question is always answered "no". */
    public const AUTHORIZATION_DEFERRED_REASON = 'writer_release_remains_blocked_until_the_receipt_is_signed_validated_and_consumed_by_a_separate_release_path';

    /**
     * The nine boundary keys (doc "Boundary"): every result keeps all false.
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
     * The four allowed future decisions (doc "Decisions"). The receipt draft may
     * only ever select from these; the default is the second one. Order
     * preserved exactly as documented.
     *
     * @var list<string>
     */
    public const ALLOWED_DECISIONS = [
        'authorize_writer_release',
        'request_external_writer_release_evidence',
        'request_changes',
        'abort',
    ];

    /**
     * The six future signature inputs (doc "Future Signature Inputs"). A LATER
     * signature request may require these — this draft only describes them and
     * collects none. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const FUTURE_SIGNATURE_INPUTS = [
        'receipt_hash',
        'selected_decision',
        'writer_release_authorization_preflight_hash',
        'writer_implementation_patch_hash',
        'human_writer_release_confirmation_hash',
        'principal_integrator_identity',
    ];

    /**
     * The eight actions the surface MUST NOT take (doc "It must not"). Held so
     * the surface can publish exactly what it refuses to do. Order preserved
     * exactly as documented.
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
     * The four allowed future decisions, in documented order.
     *
     * @return list<string>
     */
    public function allowedDecisions(): array
    {
        return self::ALLOWED_DECISIONS;
    }

    /**
     * True only for the four documented decision tokens.
     */
    public function isAllowedDecision(string $decision): bool
    {
        return in_array($decision, self::ALLOWED_DECISIONS, true);
    }

    /**
     * Resolve the selected decision for the draft.
     *
     * The doc fixes the default to `request_external_writer_release_evidence`
     * and explains why: it "prevents a missing-evidence receipt from being
     * mistaken for writer release authorization." This unsigned draft therefore
     * ALWAYS selects that default and never selects `authorize_writer_release`
     * (which would require signed, validated evidence this surface refuses to
     * collect). Any caller-supplied decision is ignored for selection; the
     * method reports whether an override was requested and what it was.
     *
     * @param string|null $requested optional caller-supplied decision (advisory
     *   only; never selected by this draft)
     * @return array{
     *   selected_decision:string,
     *   is_default:true,
     *   requested_decision:?string,
     *   requested_was_allowed:bool,
     *   requested_overridden:bool,
     *   override_reason:?string,
     *   allowed_decisions:list<string>
     * }
     */
    public function selectDecision(?string $requested = null): array
    {
        $requestedWasAllowed = $requested !== null && $this->isAllowedDecision($requested);
        // An override happened whenever the caller asked for any decision other
        // than the forced default — including (especially) authorize_writer_release.
        $overridden = $requested !== null && $requested !== self::DEFAULT_DECISION;

        return [
            'selected_decision' => self::DEFAULT_DECISION,
            'is_default' => true,
            'requested_decision' => $requested,
            'requested_was_allowed' => $requestedWasAllowed,
            'requested_overridden' => $overridden,
            'override_reason' => $overridden
                ? 'unsigned_receipt_draft_cannot_select_writer_release_authorization_default_forced_to_request_external_evidence'
                : null,
            'allowed_decisions' => self::ALLOWED_DECISIONS,
        ];
    }

    /**
     * Build the deterministic unsigned receipt draft (doc body).
     *
     * Names the allowed future decisions, forces the selected decision to the
     * documented default, lists the future signature inputs (collected=false),
     * embeds the all-false boundary, and computes a stable receipt hash over the
     * canonical draft content. The hash is deterministic (same input => same
     * hash; any documented-input change => different hash) and is explicitly NOT
     * a signature. Nothing is signed, accepted, persisted, approved or merged.
     *
     * @param array<string,mixed> $input optional advisory context. Only
     *   `requested_decision` (string) influences the reported override; it never
     *   changes the selected decision. Unknown keys are ignored.
     * @return array{
     *   surface:string, schema:string, status:string,
     *   human_question:string,
     *   allowed_decisions:list<string>,
     *   decision:array<string,mixed>,
     *   selected_decision:string,
     *   future_signature_inputs:list<array{input:string,collected:false}>,
     *   forbidden_actions:list<string>,
     *   receipt_hash:string, receipt_hash_algo:string, is_signature:false,
     *   boundary:array<string,false>,
     *   signature_valid:false, receipt_signed:false, receipt_persisted:false,
     *   accepts_or_validates_signature:false
     * }
     */
    public function receiptDraft(array $input = []): array
    {
        $requested = array_key_exists('requested_decision', $input) && is_string($input['requested_decision'])
            ? $input['requested_decision']
            : null;

        $decision = $this->selectDecision($requested);
        $futureInputs = $this->futureSignatureInputs();

        // Canonical, hashable content: only documented, stable fields. The
        // requested decision is folded in so an advisory override changes the
        // hash, but the SELECTED decision is always the forced default.
        $content = [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'allowed_decisions' => self::ALLOWED_DECISIONS,
            'selected_decision' => $decision['selected_decision'],
            'requested_decision' => $requested ?? '',
            'future_signature_inputs' => self::FUTURE_SIGNATURE_INPUTS,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'boundary' => $this->boundary(),
        ];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => self::STATUS_RECEIPT_DRAFT_CONSTRUCTED,
            'human_question' => 'what_unsigned_receipt_would_represent_a_future_writer_release_authorization_decision',
            'allowed_decisions' => self::ALLOWED_DECISIONS,
            'decision' => $decision,
            'selected_decision' => $decision['selected_decision'],
            'future_signature_inputs' => $futureInputs,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'receipt_hash' => $this->receiptHash($content),
            'receipt_hash_algo' => 'sha256',
            // The doc is explicit: this is an UNSIGNED draft. The hash is not a
            // signature; it never authorizes or releases anything.
            'is_signature' => false,
            'boundary' => $this->boundary(),
            // Restated per the doc's hard non-acceptance guarantee.
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'accepts_or_validates_signature' => false,
        ];
    }

    /**
     * Deterministic hash over the canonical receipt-draft content.
     *
     * Uses a canonical, key-sorted JSON encoding so the hash depends only on the
     * content values, not on insertion order, and is stable across runs.
     *
     * @param array<string,mixed> $content
     */
    public function receiptHash(array $content): string
    {
        $canonical = $content;
        ksort($canonical);

        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Fail-closed to a stable sentinel; never throw from a read-only
            // surface. A constant input still yields a constant hash.
            $json = 'unencodable_writer_release_authorization_receipt_draft';
        }

        return hash('sha256', $json);
    }

    /**
     * The six future signature inputs (doc "Future Signature Inputs"), each
     * marked collected=false: this draft "only describes those inputs. It does
     * not collect or sign them." A later signature request is responsible for
     * actually requiring each.
     *
     * @return list<array{input:string,collected:false}>
     */
    public function futureSignatureInputs(): array
    {
        $inputs = [];
        foreach (self::FUTURE_SIGNATURE_INPUTS as $name) {
            $inputs[] = [
                'input' => $name,
                'collected' => false,
            ];
        }

        return $inputs;
    }

    /**
     * The question this surface explicitly does NOT answer with a yes.
     *
     * Doc "Human Meaning": it does not answer "Has the writer been authorized or
     * released?" — that "remains blocked until the receipt is signed, validated
     * and consumed by a separate release path." Structural: regardless of any
     * input, authorized and released are always false.
     *
     * @return array{
     *   question:string, authorized:false, released:false,
     *   reason:string, boundary:array<string,false>
     * }
     */
    public function authorizationStatus(): array
    {
        return [
            'question' => 'has_the_writer_been_authorized_or_released',
            'authorized' => false,
            'released' => false,
            'reason' => self::AUTHORIZATION_DEFERRED_REASON,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Convenience composite entrypoint: construct the unsigned receipt draft,
     * attach the (always-no) authorization status, and prove the boundary held
     * across both — plus a real determinism check on the receipt hash and an
     * assertion that the selected decision is the forced default.
     *
     * @param array<string,mixed> $input forwarded to receiptDraft()
     * @return array{
     *   schema:string,
     *   receipt_draft:array<string,mixed>,
     *   authorization_status:array<string,mixed>,
     *   forbidden_actions:list<string>,
     *   selected_decision_is_default:bool,
     *   receipt_hash_deterministic:bool,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(array $input = []): array
    {
        $draft = $this->receiptDraft($input);
        $status = $this->authorizationStatus();

        $violations = $this->assertBoundaryHeld([$draft, $status]);

        // Independent determinism guarantee: re-deriving the canonical content
        // and re-hashing must reproduce the exact hash the draft published.
        $requested = array_key_exists('requested_decision', $input) && is_string($input['requested_decision'])
            ? $input['requested_decision']
            : null;
        $rehash = $this->receiptHash([
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'allowed_decisions' => self::ALLOWED_DECISIONS,
            'selected_decision' => self::DEFAULT_DECISION,
            'requested_decision' => $requested ?? '',
            'future_signature_inputs' => self::FUTURE_SIGNATURE_INPUTS,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'boundary' => $this->boundary(),
        ]);
        $deterministic = $rehash === $draft['receipt_hash'];

        return [
            'schema' => self::SCHEMA,
            'receipt_draft' => $draft,
            'authorization_status' => $status,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            // The doc's safety core: the draft never selects authorize_writer_release.
            'selected_decision_is_default' => $draft['selected_decision'] === self::DEFAULT_DECISION,
            'receipt_hash_deterministic' => $deterministic,
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
        ];
    }

    /**
     * Prove that no result ever flipped a boundary key to a truthy value.
     * Returns the list of "label.key" violations (empty = boundary intact).
     *
     * @param list<array<string,mixed>> $results
     * @return list<string>
     */
    public function assertBoundaryHeld(array $results): array
    {
        $violations = [];
        foreach ($results as $result) {
            $label = is_string($result['surface'] ?? null)
                ? $result['surface']
                : (is_string($result['question'] ?? null) ? $result['question'] : 'unknown');

            $boundary = is_array($result['boundary'] ?? null) ? $result['boundary'] : [];
            foreach (self::BOUNDARY_KEYS as $key) {
                // Missing key OR truthy value both count as a breach.
                if (! array_key_exists($key, $boundary) || $boundary[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }

            // The draft restates extra guarantees outside the boundary array;
            // any of them turning truthy is a breach too.
            foreach (['signature_valid', 'receipt_signed', 'receipt_persisted', 'accepts_or_validates_signature'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }
}
