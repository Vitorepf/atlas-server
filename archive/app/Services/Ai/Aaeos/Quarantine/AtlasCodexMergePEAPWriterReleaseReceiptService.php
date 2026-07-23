<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release RECEIPT — pure,
 * deterministic, READ-ONLY unsigned receipt DRAFT surface.
 *
 * IMPORTANT distinction from its siblings:
 *   - AtlasCodexMergePEAPWriterReleaseAuthReceiptService governs the
 *     "...writer-release-authorization-receipt" doc (the unsigned receipt that
 *     would represent a future writer release AUTHORIZATION decision).
 *   - AtlasCodexMergeReleaseSignatureRequestService /
 *     AtlasCodexMergePEAPWriterReleaseAuthSignatureReqService govern the
 *     "...signature-request" docs (the signable payload a future signature covers).
 *   THIS surface governs the "...writer-release-RECEIPT" doc: the unsigned receipt
 *   DRAFT that answers "what would the writer release receipt need to contain?".
 *   It binds the eleven future receipt fields, inherits the writer release
 *   preflight as a hard upstream blocker, and releases nothing.
 *
 * This surface answers exactly one question (doc "Human Meaning"):
 *
 *     "What would the writer release receipt need to contain?"
 *
 * It deliberately does NOT answer:
 *
 *     "Has the writer been released?"
 *
 * The answer to that second question is, and stays, no. "This draft is an
 * unsigned contract input for a later signature request and execution contract."
 *
 * Hard boundary (doc "Boundary") — every result this service emits keeps all
 * nine keys false, always:
 *   execution_allowed=false, writer_file_creation_allowed=false,
 *   ledger_write_allowed=false, dispatch_allowed=false, approval_granted=false,
 *   merge_allowed=false, signature_valid=false, receipt_signed=false,
 *   receipt_persisted=false.
 *
 * The surface must not, and this code does not (doc "It must not"): create writer
 * files, write ledger events, persist receipts, accept or validate signatures,
 * record decisions, approve code, merge, or dispatch work. It only constructs a
 * deterministic *unsigned receipt draft* naming the eleven fields a future signed
 * receipt would bind.
 *
 * Documented invariants this code ENFORCES (not just documents):
 *   - "Required Upstream Contract": the draft "depends on the writer release
 *     preflight. If the preflight is not ready, this surface must return
 *     blocked_before_writer_release_preflight." => receiptDraft()/evaluate()
 *     return that exact status and bind no fields whenever preflight is not ready;
 *     they only construct the real draft once preflight readiness is asserted.
 *     The all-false boundary holds in BOTH branches.
 *   - "Boundary": boundary() returns all nine keys false and every public result
 *     embeds it verbatim; assertBoundaryHeld() proves no result ever flipped a key
 *     true, and the assertion is non-vacuous.
 *   - "Receipt Fields": the future receipt must bind EXACTLY these eleven fields,
 *     in documented order; receiptFields() lists them and marks each bound=false
 *     (this draft binds none of them).
 *   - "Human Meaning": it does not answer "Has the writer been released?" =>
 *     releaseStatus() always returns released=false with the documented reason,
 *     structurally, regardless of input.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-receipt.md
 */
final class AtlasCodexMergePEAPWriterReleaseReceiptService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_persistence_writer_release_receipt.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'writer_release_receipt_draft';

    /** Status when an unsigned receipt draft was constructed (never signed). */
    public const STATUS_RECEIPT_DRAFT_CONSTRUCTED = 'writer_release_receipt_draft_constructed';

    /**
     * Documented blocked status (doc "Required Upstream Contract"): if the writer
     * release preflight is not ready, this surface must return exactly this.
     */
    public const STATUS_BLOCKED_BEFORE_PREFLIGHT = 'blocked_before_writer_release_preflight';

    /** Documented reason the released question is always answered "no". */
    public const RELEASE_DEFERRED_REASON = 'unsigned_contract_input_for_a_later_signature_request_and_execution_contract';

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
     * The eleven receipt fields a future signed release receipt must bind
     * (doc "Receipt Fields"). This draft only names them; it binds none. Order
     * preserved exactly as documented.
     *
     * @var list<string>
     */
    public const RECEIPT_FIELDS = [
        'writer_release_preflight_hash',
        'writer_release_authorization_signed_receipt_template_hash',
        'validated_writer_release_authorization_signature_hash',
        'release_actor_identity',
        'writer_contract_hash_recheck',
        'hot_scope_recheck',
        'writer_capability_test_run',
        'no_merge_authority_evidence',
        'no_dispatch_authority_evidence',
        'release_decision',
        'release_rationale',
    ];

    /**
     * The eight actions the surface MUST NOT take (doc "It must not"). Held so the
     * surface can publish exactly what it refuses to do. Order preserved exactly
     * as documented.
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
     * The eleven receipt fields a future signed receipt would bind, each marked
     * bound=false (doc "Receipt Fields"): this draft names the fields but binds
     * none of them. A later signature request and execution contract are
     * responsible for actually binding each.
     *
     * @return list<array{field:string,bound:false}>
     */
    public function receiptFields(): array
    {
        $fields = [];
        foreach (self::RECEIPT_FIELDS as $name) {
            $fields[] = [
                'field' => $name,
                'bound' => false,
            ];
        }

        return $fields;
    }

    /**
     * Resolve whether the upstream writer release preflight is ready.
     *
     * Doc "Required Upstream Contract": "The draft depends on the writer release
     * preflight. If the preflight is not ready, this surface must return
     * blocked_before_writer_release_preflight." Fail-closed: readiness must be
     * explicitly asserted as boolean true; anything else (absent, null, truthy
     * non-bool, false) is treated as NOT ready.
     *
     * @param array<string,mixed> $input advisory context; only the strict boolean
     *   `writer_release_preflight_ready === true` marks the upstream ready
     */
    public function preflightReady(array $input): bool
    {
        return array_key_exists('writer_release_preflight_ready', $input)
            && $input['writer_release_preflight_ready'] === true;
    }

    /**
     * Build the deterministic unsigned receipt draft (doc body).
     *
     * Honors the upstream contract first: if the writer release preflight is not
     * ready, returns the documented blocked status with the all-false boundary and
     * NO bound receipt fields (the draft is withheld). Once preflight readiness is
     * asserted, names the eleven future receipt fields (bound=false), embeds the
     * all-false boundary, and computes a stable receipt hash over the canonical
     * draft content. The hash is deterministic (same input => same hash; any
     * documented-input change => different hash) and is explicitly NOT a signature.
     * Nothing is signed, accepted, persisted, approved or merged in either branch.
     *
     * @param array<string,mixed> $input optional advisory context. Only
     *   `writer_release_preflight_ready` (strict bool true) and
     *   `release_actor_identity` (string, advisory; folded into the hash but never
     *   bound) are read. Unknown keys are ignored.
     * @return array{
     *   surface:string, schema:string, status:string,
     *   human_question:string,
     *   preflight_ready:bool, blocked:bool, blocked_reason:?string,
     *   receipt_fields:list<array{field:string,bound:false}>,
     *   forbidden_actions:list<string>,
     *   receipt_hash:?string, receipt_hash_algo:string, is_signature:false,
     *   boundary:array<string,false>,
     *   signature_valid:false, receipt_signed:false, receipt_persisted:false,
     *   accepts_or_validates_signature:false
     * }
     */
    public function receiptDraft(array $input = []): array
    {
        $ready = $this->preflightReady($input);

        // Upstream contract: blocked before the writer release preflight. Withhold
        // the draft entirely — no fields bound, no hash computed — but still keep
        // the hard boundary all-false.
        if (! $ready) {
            return [
                'surface' => self::SURFACE,
                'schema' => self::SCHEMA,
                'status' => self::STATUS_BLOCKED_BEFORE_PREFLIGHT,
                'human_question' => 'what_would_the_writer_release_receipt_need_to_contain',
                'preflight_ready' => false,
                'blocked' => true,
                'blocked_reason' => self::STATUS_BLOCKED_BEFORE_PREFLIGHT,
                'receipt_fields' => [],
                'forbidden_actions' => self::FORBIDDEN_ACTIONS,
                'receipt_hash' => null,
                'receipt_hash_algo' => 'sha256',
                'is_signature' => false,
                'boundary' => $this->boundary(),
                'signature_valid' => false,
                'receipt_signed' => false,
                'receipt_persisted' => false,
                'accepts_or_validates_signature' => false,
            ];
        }

        $actor = array_key_exists('release_actor_identity', $input) && is_string($input['release_actor_identity'])
            ? $input['release_actor_identity']
            : '';

        // Canonical, hashable content: only documented, stable fields. The
        // advisory actor identity is folded in so it changes the hash, but it is
        // NOT bound (the receipt_fields entries all stay bound=false).
        $content = [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'receipt_fields' => self::RECEIPT_FIELDS,
            'release_actor_identity' => $actor,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'boundary' => $this->boundary(),
        ];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => self::STATUS_RECEIPT_DRAFT_CONSTRUCTED,
            'human_question' => 'what_would_the_writer_release_receipt_need_to_contain',
            'preflight_ready' => true,
            'blocked' => false,
            'blocked_reason' => null,
            'receipt_fields' => $this->receiptFields(),
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
            $json = 'unencodable_writer_release_receipt_draft';
        }

        return hash('sha256', $json);
    }

    /**
     * The question this surface explicitly does NOT answer with a yes.
     *
     * Doc "Human Meaning": it does not answer "Has the writer been released?" —
     * "The answer remains no. This draft is an unsigned contract input for a later
     * signature request and execution contract." Structural: regardless of any
     * input, released is always false.
     *
     * @return array{
     *   question:string, released:false,
     *   reason:string, boundary:array<string,false>
     * }
     */
    public function releaseStatus(): array
    {
        return [
            'question' => 'has_the_writer_been_released',
            'released' => false,
            'reason' => self::RELEASE_DEFERRED_REASON,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Convenience composite entrypoint: construct the unsigned receipt draft (or
     * the blocked envelope), attach the (always-no) release status, and prove the
     * boundary held across both — plus a real determinism check on the receipt
     * hash when one was produced.
     *
     * @param array<string,mixed> $input forwarded to receiptDraft()
     * @return array{
     *   schema:string,
     *   receipt_draft:array<string,mixed>,
     *   release_status:array<string,mixed>,
     *   forbidden_actions:list<string>,
     *   blocked:bool,
     *   receipt_hash_deterministic:bool,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(array $input = []): array
    {
        $draft = $this->receiptDraft($input);
        $status = $this->releaseStatus();

        $violations = $this->assertBoundaryHeld([$draft, $status]);

        // Independent determinism guarantee: only meaningful when a draft hash was
        // produced (preflight ready). When blocked, the hash is intentionally null
        // and determinism is vacuously true (a null hash is reproducibly null).
        if ($draft['receipt_hash'] === null) {
            $deterministic = true;
        } else {
            $actor = array_key_exists('release_actor_identity', $input) && is_string($input['release_actor_identity'])
                ? $input['release_actor_identity']
                : '';
            $rehash = $this->receiptHash([
                'surface' => self::SURFACE,
                'schema' => self::SCHEMA,
                'receipt_fields' => self::RECEIPT_FIELDS,
                'release_actor_identity' => $actor,
                'forbidden_actions' => self::FORBIDDEN_ACTIONS,
                'boundary' => $this->boundary(),
            ]);
            $deterministic = $rehash === $draft['receipt_hash'];
        }

        return [
            'schema' => self::SCHEMA,
            'receipt_draft' => $draft,
            'release_status' => $status,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'blocked' => $draft['blocked'],
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

            // The draft restates extra guarantees outside the boundary array; any
            // of them turning truthy is a breach too.
            foreach (['signature_valid', 'receipt_signed', 'receipt_persisted', 'accepts_or_validates_signature'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }
}
