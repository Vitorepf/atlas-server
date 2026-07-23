<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release AUTHORIZATION
 * Signature Request — pure, deterministic, READ-ONLY surface.
 *
 * IMPORTANT distinction from its sibling
 * AtlasCodexMergeReleaseSignatureRequestService: that surface governs the
 * non-authorization "...writer-release-signature-request" doc. THIS surface
 * governs the "...writer-release-AUTHORIZATION-signature-request" doc, whose
 * Signable Payload is richer (ten components, including four release/writer
 * preflight & template hashes, the required authorization checks and the
 * blocking conditions) and whose Required External Signature Evidence is a
 * different, five-field set.
 *
 * This surface answers exactly one question (doc "Human Meaning"):
 *
 *     "What exactly would a human or external validator need to sign?"
 *
 * It deliberately does NOT answer:
 *
 *     "Has the writer release been signed or authorized?"
 *
 * The answer to that second question is, and stays, no — it "remains blocked
 * until external signature evidence is validated by a separate post-signature
 * path." Signature acceptance, validation, signed-receipt persistence, writer
 * file creation and any merge belong to LATER governed surfaces, not this one.
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
 * constructs a deterministic *signable payload* describing what a future
 * external signature must cover, and names the evidence a later runbook will
 * require. The payload HASH it exposes "is an input to a future external
 * signature process. It is not a signature."
 *
 * Documented invariants this code ENFORCES (not just documents):
 *   - "must keep ...=false" boundary => boundary() returns all nine keys false
 *     and every public result embeds it verbatim; assertBoundaryHeld() proves no
 *     result ever flipped a key true, and the assertion is non-vacuous.
 *   - "The request may expose a deterministic signable payload containing: <ten
 *     components>" => signablePayload() emits exactly those ten component keys,
 *     in the documented order, and is deterministic: the same input always
 *     yields the same payload_hash, and any change to any component changes the
 *     hash. The hash is labelled is_signature=false.
 *   - "A later post-signature runbook must require: <five fields>" =>
 *     requiredExternalSignatureEvidence() lists exactly those five field names,
 *     in order, and marks signature_present=false / validated=false for each
 *     (this surface collects none of them).
 *   - "It does not answer 'Has the writer release been signed or authorized?'.
 *     That remains blocked..." => authorizationStatus() always returns
 *     signed=false / authorized=false with the documented deferral reason,
 *     structurally, regardless of input.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-signature-request.md
 */
final class AtlasCodexMergePEAPWriterReleaseAuthSignatureReqService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_persistence_writer_release_authorization_signature_request.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'writer_release_authorization_signature_request';

    /** Status when a signable payload was constructed (never signed). */
    public const STATUS_SIGNABLE_PAYLOAD_CONSTRUCTED = 'authorization_signature_request_signable_payload_constructed';

    /** Documented reason the signed/authorized question is always answered "no". */
    public const AUTHORIZATION_DEFERRED_REASON = 'writer_release_remains_blocked_until_external_signature_evidence_is_validated_by_a_separate_post_signature_path';

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
     * The ten components of the deterministic signable payload (doc "Signable
     * Payload"). The request MAY expose exactly these — no more, no fewer — and
     * the payload hash over them is an input to a *future* external signature,
     * never a signature itself. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const SIGNABLE_PAYLOAD_COMPONENTS = [
        'receipt_hash',
        'receipt_id',
        'selected_decision',
        'writer_release_authorization_preflight_hash',
        'writer_release_authorization_template_hash',
        'writer_implementation_preflight_hash',
        'writer_contract_template_hash',
        'required_external_evidence',
        'required_authorization_checks',
        'blocking_conditions',
    ];

    /**
     * Required external signature evidence (doc "Required External Signature
     * Evidence"). These are the FIVE things a later post-signature runbook must
     * require. This surface only names them; it never collects, accepts or
     * validates any of them. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_EXTERNAL_SIGNATURE_EVIDENCE = [
        'external_writer_release_signature_value',
        'signature_validator_identity',
        'signature_validation_timestamp',
        'validated_receipt_hash',
        'validated_signable_payload_hash',
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
     * Build the deterministic signable payload (doc "Signable Payload").
     *
     * Exposes exactly the ten documented components, filling each from the input
     * (missing components default to empty) and computing a stable payload hash
     * over the canonical, sorted JSON encoding of those components. The hash is
     * deterministic (same input => same hash; any component change => different
     * hash) and is explicitly NOT a signature.
     *
     * No component is ever accepted as a signature, and the boundary is embedded
     * unchanged.
     *
     * @param array<string,mixed> $input optional values for any of the ten
     *   documented components (see SIGNABLE_PAYLOAD_COMPONENTS). Unknown keys are
     *   ignored; the payload only ever contains the documented components.
     * @return array{
     *   surface:string, schema:string, status:string,
     *   components:array<string,mixed>, component_keys:list<string>,
     *   payload_hash:string, payload_hash_algo:string, is_signature:false,
     *   required_external_signature_evidence:list<array{field:string,signature_present:false,validated:false}>,
     *   boundary:array<string,false>,
     *   signature_valid:false, receipt_signed:false, receipt_persisted:false,
     *   accepts_or_validates_signature:false
     * }
     */
    public function signablePayload(array $input = []): array
    {
        // Collect ONLY the documented components, in documented order.
        $components = [];
        foreach (self::SIGNABLE_PAYLOAD_COMPONENTS as $component) {
            $components[$component] = array_key_exists($component, $input)
                ? $input[$component]
                : '';
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => self::STATUS_SIGNABLE_PAYLOAD_CONSTRUCTED,
            'components' => $components,
            'component_keys' => self::SIGNABLE_PAYLOAD_COMPONENTS,
            'payload_hash' => $this->payloadHash($components),
            'payload_hash_algo' => 'sha256',
            // The doc is explicit: the payload hash is an input to a future
            // external signature process — it is NOT a signature.
            'is_signature' => false,
            'required_external_signature_evidence' => $this->requiredExternalSignatureEvidence(),
            'boundary' => $this->boundary(),
            // Restated per the doc's hard non-acceptance guarantee.
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'accepts_or_validates_signature' => false,
        ];
    }

    /**
     * Deterministic hash over the ten signable-payload components.
     *
     * Uses a canonical, key-sorted JSON encoding so the hash depends only on the
     * component values, not on their insertion order, and is stable across runs.
     *
     * @param array<string,mixed> $components
     */
    public function payloadHash(array $components): string
    {
        $canonical = $components;
        ksort($canonical);

        $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Fail-closed to a stable sentinel; never throw from a read-only
            // surface. A constant input still yields a constant hash.
            $json = 'unencodable_signable_payload';
        }

        return hash('sha256', $json);
    }

    /**
     * The five required external signature evidence fields (doc "Required
     * External Signature Evidence"), each marked signature_present=false /
     * validated=false: this surface collects and validates none of them. A later
     * post-signature runbook is responsible for actually requiring each.
     *
     * @return list<array{field:string,signature_present:false,validated:false}>
     */
    public function requiredExternalSignatureEvidence(): array
    {
        $fields = [];
        foreach (self::REQUIRED_EXTERNAL_SIGNATURE_EVIDENCE as $field) {
            $fields[] = [
                'field' => $field,
                'signature_present' => false,
                'validated' => false,
            ];
        }

        return $fields;
    }

    /**
     * The question this surface explicitly does NOT answer with a yes.
     *
     * Doc "Human Meaning": it does not answer "Has the writer release been
     * signed or authorized?" — that "remains blocked until external signature
     * evidence is validated by a separate post-signature path." Structural:
     * regardless of any input, signed and authorized are always false.
     *
     * @return array{
     *   question:string, signed:false, authorized:false,
     *   reason:string, boundary:array<string,false>
     * }
     */
    public function authorizationStatus(): array
    {
        return [
            'question' => 'has_the_writer_release_been_signed_or_authorized',
            'signed' => false,
            'authorized' => false,
            'reason' => self::AUTHORIZATION_DEFERRED_REASON,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Convenience composite entrypoint: construct the signable payload, attach
     * the (always-no) authorization status, and prove the boundary held across
     * both — plus a real determinism check on the payload hash.
     *
     * @param array<string,mixed> $input forwarded to signablePayload()
     * @return array{
     *   schema:string,
     *   signable_payload:array<string,mixed>,
     *   authorization_status:array<string,mixed>,
     *   forbidden_actions:list<string>,
     *   payload_hash_deterministic:bool,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(array $input = []): array
    {
        $payload = $this->signablePayload($input);
        $status = $this->authorizationStatus();

        $violations = $this->assertBoundaryHeld([$payload, $status]);

        // Independent determinism guarantee: re-hashing the same components must
        // reproduce the exact same hash the payload published.
        $rehash = $this->payloadHash($payload['components']);
        $deterministic = $rehash === $payload['payload_hash'];

        return [
            'schema' => self::SCHEMA,
            'signable_payload' => $payload,
            'authorization_status' => $status,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            'payload_hash_deterministic' => $deterministic,
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

            // The payload restates extra guarantees outside the boundary array;
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
