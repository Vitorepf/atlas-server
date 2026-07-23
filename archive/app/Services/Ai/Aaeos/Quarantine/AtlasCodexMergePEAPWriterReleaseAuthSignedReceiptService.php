<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release AUTHORIZATION
 * SIGNED RECEIPT — pure, deterministic, READ-ONLY signed-receipt TEMPLATE surface.
 *
 * IMPORTANT distinction from its siblings (each governs its own doc):
 *   - AtlasCodexMergePEAPWriterReleaseAuthReceiptService governs the
 *     "...writer-release-authorization-receipt" doc: the UNSIGNED receipt DRAFT.
 *   - AtlasCodexMergePEAPWriterReleaseAuthSignatureReqService governs the
 *     "...writer-release-authorization-signature-request" doc.
 *   - AtlasCodexMergeReleaseAuthPreflightService governs the
 *     "...writer-release-authorization-preflight" doc.
 *   THIS surface governs the "...writer-release-authorization-SIGNED-RECEIPT"
 *   doc: the read-only SIGNED RECEIPT TEMPLATE that answers what a signed writer
 *   release authorization receipt would need to contain — WITHOUT accepting,
 *   validating, signing or persisting anything. It is, per the doc frontmatter,
 *   "non-persisting and non-authorizing by itself."
 *
 * This surface answers exactly one question (doc "Human Meaning"):
 *
 *     "What would a signed writer release authorization receipt need to contain?"
 *
 * It deliberately does NOT answer (and always answers "no"):
 *
 *     "Has the writer been released or persisted?"
 *
 * That stays blocked "until a separate release preflight and persistence path
 * exist." Signing, validation, signed-receipt persistence, ledger writes, writer
 * file creation, decision recording, approval and any merge belong to LATER
 * governed surfaces — never this template.
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
 * constructs a deterministic signed-receipt TEMPLATE that names which evidence
 * fields a future signed path would require and which preconditions a future
 * release preflight would have to prove — every one of them marked unmet.
 *
 * Documented invariants this code ENFORCES (not just documents):
 *   - "must keep ...=false" boundary => boundary() returns all nine keys false
 *     and every public result embeds it verbatim; assertBoundaryHeld() proves no
 *     result ever flipped a key true, and the assertion is non-vacuous.
 *   - "A later signed receipt path must require: <six fields>" =>
 *     requiredFutureEvidence() lists exactly those six field names, in documented
 *     order, each marked present=false (this template carries none of them).
 *   - "Before any writer release can move forward, a later preflight must prove:
 *     <six preconditions>" => futureReleasePreconditions() lists exactly those
 *     six preconditions, in documented order, each marked proven=false; and
 *     releaseReady() is structurally false until ALL six are proven AND all six
 *     evidence fields are present — which this read-only template can never make
 *     true. The doc's pivotal precondition "selected decision equals
 *     authorize_writer_release" is encoded as its own precondition token.
 *   - "It does not answer 'Has the writer been released or persisted?'" =>
 *     releaseStatus() always returns released=false / persisted=false with the
 *     documented deferral reason, structurally, regardless of input.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-signed-receipt.md
 */
final class AtlasCodexMergePEAPWriterReleaseAuthSignedReceiptService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_persistence_writer_release_authorization_signed_receipt.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'writer_release_authorization_signed_receipt_template';

    /** Status when a signed-receipt template was constructed (never signed). */
    public const STATUS_SIGNED_RECEIPT_TEMPLATE_CONSTRUCTED = 'writer_release_authorization_signed_receipt_template_constructed';

    /**
     * The decision a future release preflight must observe (doc "Future Release
     * Preconditions"): the selected decision must equal authorize_writer_release.
     * This template never selects it — it only names it as the future target.
     */
    public const REQUIRED_SELECTED_DECISION = 'authorize_writer_release';

    /** Documented reason the released/persisted question is always answered "no". */
    public const RELEASE_DEFERRED_REASON = 'writer_release_remains_blocked_until_a_separate_release_preflight_and_persistence_path_exist';

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
     * The six evidence fields a LATER signed receipt path MUST require (doc
     * "Future Evidence"). This template only NAMES them; it carries none. Order
     * preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_FUTURE_EVIDENCE = [
        'external_writer_release_signature_value',
        'signature_validator_identity',
        'signature_validation_timestamp',
        'validated_writer_release_authorization_signature_hash',
        'validated_receipt_hash',
        'validated_signable_payload_hash',
    ];

    /**
     * The six preconditions a LATER release preflight MUST prove before any
     * writer release can move forward (doc "Future Release Preconditions"). This
     * template marks every one unproven. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const FUTURE_RELEASE_PRECONDITIONS = [
        'signed_receipt_template_is_ready',
        'external_signed_receipt_evidence_is_present',
        'selected_decision_equals_authorize_writer_release',
        'writer_contract_hash_still_matches_the_patch',
        'hot_scope_is_still_clean',
        'writer_capability_tests_still_pass',
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
     * The six required future evidence fields (doc "Future Evidence"), each
     * marked present=false: a later signed receipt path "must require" them, but
     * this read-only template carries none of them. Order preserved exactly as
     * documented.
     *
     * @return list<array{field:string,present:false}>
     */
    public function requiredFutureEvidence(): array
    {
        $fields = [];
        foreach (self::REQUIRED_FUTURE_EVIDENCE as $name) {
            $fields[] = [
                'field' => $name,
                'present' => false,
            ];
        }

        return $fields;
    }

    /**
     * The six future release preconditions (doc "Future Release Preconditions"),
     * each marked proven=false: this template proves none of them. Order
     * preserved exactly as documented.
     *
     * @return list<array{precondition:string,proven:false}>
     */
    public function futureReleasePreconditions(): array
    {
        $conditions = [];
        foreach (self::FUTURE_RELEASE_PRECONDITIONS as $name) {
            $conditions[] = [
                'precondition' => $name,
                'proven' => false,
            ];
        }

        return $conditions;
    }

    /**
     * Whether a writer release is ready to move forward.
     *
     * Per the doc this template is "non-authorizing by itself": release readiness
     * requires ALL six future evidence fields present AND ALL six future
     * preconditions proven. This read-only template carries no evidence and
     * proves no precondition, so ready is structurally false and the unmet sets
     * enumerate every field/precondition. A later preflight + persistence path is
     * the only thing that can flip these — never this surface.
     *
     * @return array{
     *   ready:false,
     *   required_selected_decision:string,
     *   evidence_present_count:int, evidence_required_count:int,
     *   preconditions_proven_count:int, preconditions_required_count:int,
     *   missing_evidence:list<string>,
     *   unproven_preconditions:list<string>,
     *   reason:string
     * }
     */
    public function releaseReady(): array
    {
        $missingEvidence = [];
        foreach ($this->requiredFutureEvidence() as $field) {
            if ($field['present'] !== true) {
                $missingEvidence[] = $field['field'];
            }
        }

        $unproven = [];
        foreach ($this->futureReleasePreconditions() as $condition) {
            if ($condition['proven'] !== true) {
                $unproven[] = $condition['precondition'];
            }
        }

        $evidencePresent = count(self::REQUIRED_FUTURE_EVIDENCE) - count($missingEvidence);
        $proven = count(self::FUTURE_RELEASE_PRECONDITIONS) - count($unproven);

        // Structural: a read-only template can never satisfy both gates. The
        // false is hard-typed because no input path exists to make it true.
        return [
            'ready' => false,
            'required_selected_decision' => self::REQUIRED_SELECTED_DECISION,
            'evidence_present_count' => $evidencePresent,
            'evidence_required_count' => count(self::REQUIRED_FUTURE_EVIDENCE),
            'preconditions_proven_count' => $proven,
            'preconditions_required_count' => count(self::FUTURE_RELEASE_PRECONDITIONS),
            'missing_evidence' => $missingEvidence,
            'unproven_preconditions' => $unproven,
            'reason' => self::RELEASE_DEFERRED_REASON,
        ];
    }

    /**
     * Build the deterministic signed-receipt TEMPLATE (doc body).
     *
     * Names the six required future evidence fields (present=false), the six
     * future release preconditions (proven=false) and the required selected
     * decision, embeds the all-false boundary, and computes a stable template
     * hash over the canonical content. The hash is deterministic (same input =>
     * same hash) and is explicitly NOT a signature: nothing here is signed,
     * accepted, validated, persisted, approved or merged.
     *
     * @return array{
     *   surface:string, schema:string, status:string,
     *   human_question:string,
     *   required_selected_decision:string,
     *   required_future_evidence:list<array{field:string,present:false}>,
     *   future_release_preconditions:list<array{precondition:string,proven:false}>,
     *   forbidden_actions:list<string>,
     *   template_hash:string, template_hash_algo:string, is_signature:false,
     *   boundary:array<string,false>,
     *   signature_valid:false, receipt_signed:false, receipt_persisted:false,
     *   accepts_or_validates_signature:false
     * }
     */
    public function template(): array
    {
        // Canonical, hashable content: only documented, stable fields.
        $content = [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'required_selected_decision' => self::REQUIRED_SELECTED_DECISION,
            'required_future_evidence' => self::REQUIRED_FUTURE_EVIDENCE,
            'future_release_preconditions' => self::FUTURE_RELEASE_PRECONDITIONS,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'boundary' => $this->boundary(),
        ];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => self::STATUS_SIGNED_RECEIPT_TEMPLATE_CONSTRUCTED,
            'human_question' => 'what_would_a_signed_writer_release_authorization_receipt_need_to_contain',
            'required_selected_decision' => self::REQUIRED_SELECTED_DECISION,
            'required_future_evidence' => $this->requiredFutureEvidence(),
            'future_release_preconditions' => $this->futureReleasePreconditions(),
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'template_hash' => $this->templateHash($content),
            'template_hash_algo' => 'sha256',
            // The doc is explicit: this is a TEMPLATE. The hash is not a
            // signature; it never authorizes, releases or persists anything.
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
     * Deterministic hash over the canonical signed-receipt-template content.
     *
     * Uses a canonical, key-sorted JSON encoding so the hash depends only on the
     * content values, not on insertion order, and is stable across runs.
     *
     * @param array<string,mixed> $content
     */
    public function templateHash(array $content): string
    {
        $canonical = $content;
        ksort($canonical);

        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Fail-closed to a stable sentinel; never throw from a read-only
            // surface. A constant input still yields a constant hash.
            $json = 'unencodable_writer_release_authorization_signed_receipt_template';
        }

        return hash('sha256', $json);
    }

    /**
     * The question this surface explicitly does NOT answer with a yes.
     *
     * Doc "Human Meaning": it does not answer "Has the writer been released or
     * persisted?" — that "remains blocked until a separate release preflight and
     * persistence path exist." Structural: regardless of any input, released and
     * persisted are always false.
     *
     * @return array{
     *   question:string, released:false, persisted:false,
     *   reason:string, boundary:array<string,false>
     * }
     */
    public function releaseStatus(): array
    {
        return [
            'question' => 'has_the_writer_been_released_or_persisted',
            'released' => false,
            'persisted' => false,
            'reason' => self::RELEASE_DEFERRED_REASON,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Convenience composite entrypoint: construct the signed-receipt template,
     * attach release readiness (always not-ready) and the (always-no)
     * released/persisted status, and prove the boundary held across all of them —
     * plus a real determinism check on the template hash.
     *
     * @return array{
     *   schema:string,
     *   template:array<string,mixed>,
     *   release_ready:array<string,mixed>,
     *   release_status:array<string,mixed>,
     *   forbidden_actions:list<string>,
     *   release_ready_flag:false,
     *   template_hash_deterministic:bool,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(): array
    {
        $template = $this->template();
        $ready = $this->releaseReady();
        $status = $this->releaseStatus();

        $violations = $this->assertBoundaryHeld([$template, $status]);

        // Independent determinism guarantee: re-deriving the canonical content
        // and re-hashing must reproduce the exact hash the template published.
        $rehash = $this->templateHash([
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'required_selected_decision' => self::REQUIRED_SELECTED_DECISION,
            'required_future_evidence' => self::REQUIRED_FUTURE_EVIDENCE,
            'future_release_preconditions' => self::FUTURE_RELEASE_PRECONDITIONS,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'boundary' => $this->boundary(),
        ]);
        $deterministic = $rehash === $template['template_hash'];

        return [
            'schema' => self::SCHEMA,
            'template' => $template,
            'release_ready' => $ready,
            'release_status' => $status,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            // The doc's safety core: the template is non-authorizing by itself.
            'release_ready_flag' => $ready['ready'],
            'template_hash_deterministic' => $deterministic,
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

            // The template restates extra guarantees outside the boundary array;
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
