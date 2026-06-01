<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release Signature
 * Request — pure, deterministic, READ-ONLY surface.
 *
 * This surface answers exactly one question:
 *
 *     "What exactly must be signed before a writer release can continue?"
 *
 * It deliberately does NOT answer:
 *
 *     "Was the signature accepted or validated?"
 *
 * The answer to that second question is, and stays, no. Signature acceptance,
 * validation, signed-receipt templating and any writer-release execution
 * contract belong to LATER governed surfaces — not this one.
 *
 * Hard boundary (doc "Boundary") — every result this service emits keeps all
 * nine keys false, always:
 *   execution_allowed=false, writer_file_creation_allowed=false,
 *   ledger_write_allowed=false, dispatch_allowed=false, approval_granted=false,
 *   merge_allowed=false, signature_valid=false, receipt_signed=false,
 *   receipt_persisted=false.
 *
 * The surface must not, and this code does not: create writer files, write
 * ledger events, persist receipts, accept or validate signatures, record
 * decisions, approve code, merge, or dispatch work. It only describes what a
 * future signature must cover.
 *
 * Documented invariants this code ENFORCES (not just documents):
 *   - "must keep ... =false" boundary => boundary() returns all nine keys false
 *     and every public result embeds it verbatim; assertBoundaryHeld() proves no
 *     result ever flipped a key true.
 *   - "If the receipt draft is not ready, this surface must return
 *     blocked_before_writer_release_receipt_draft" => signatureRequest() returns
 *     that EXACT status token and an empty signable spec whenever the upstream
 *     writer release receipt draft is not ready. Fail-closed: absent the
 *     positive signal, the draft is treated as not ready.
 *   - "A later post-signature flow must require: <7 fields>" => when (and only
 *     when) the upstream draft is ready, signatureRequest() emits the seven
 *     required signature-evidence field names as the spec of what must be signed
 *     — while keeping signature_valid / receipt_signed false (it lists, it never
 *     accepts).
 *   - "It does not answer 'Was the signature accepted or validated?'. The answer
 *     remains no." => signatureAccepted() always returns false with the documented
 *     reason; this is structural, not data-dependent.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-signature-request.md
 */
final class AtlasCodexMergeReleaseSignatureRequestService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_persistence_writer_release_signature_request.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'writer_release_signature_request';

    /**
     * The EXACT blocked status the doc requires when the upstream writer release
     * receipt draft is not ready ("Required Upstream Contract").
     */
    public const STATUS_BLOCKED_BEFORE_RECEIPT_DRAFT = 'blocked_before_writer_release_receipt_draft';

    /** Status emitted when the upstream draft is ready and the spec is listed. */
    public const STATUS_SIGNATURE_REQUESTED = 'writer_release_signature_requested';

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
     * Required signature evidence (doc "Required Signature Evidence"). These are
     * the seven things a LATER post-signature flow must require. This surface
     * only names them; it never collects, accepts or validates any of them.
     * Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_SIGNATURE_EVIDENCE = [
        'external_writer_release_signature_value',
        'signer_identity',
        'signature_timestamp',
        'signature_algorithm',
        'signature_scope',
        'writer_release_receipt_hash_signed',
        'writer_release_signable_payload_hash_signed',
    ];

    /** Documented reason the acceptance question is always answered "no". */
    public const ACCEPTANCE_DEFERRED_REASON = 'signature_acceptance_and_validation_belong_to_a_later_governed_surface';

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
     * Primary surface: the read-only writer release signature REQUEST.
     *
     * If the upstream writer release receipt draft is not ready, returns the
     * exact documented blocked status and an empty signable spec. Otherwise it
     * lists the seven evidence fields a future signature must cover. Either way
     * the boundary holds and nothing is signed, accepted, validated, persisted,
     * approved, merged or dispatched.
     *
     * @param array<string,mixed> $input recognised key:
     *   writer_release_receipt_draft_ready (bool) — the ONLY positive signal that
     *   the upstream receipt draft this request depends on is ready. Absent or
     *   non-true => fail-closed to the blocked status.
     * @return array{
     *   surface:string, schema:string,
     *   upstream_receipt_draft_ready:bool, status:string,
     *   required_signature_evidence:list<string>,
     *   signable_request_ready:bool,
     *   boundary:array<string,false>,
     *   signature_valid:false, receipt_signed:false, receipt_persisted:false,
     *   accepts_or_validates_signature:false
     * }
     */
    public function signatureRequest(array $input = []): array
    {
        $draftReady = $this->signal($input, 'writer_release_receipt_draft_ready', false);

        // Fail-closed upstream gate: no draft ready => the doc-mandated block,
        // and we publish NO signable spec at all (the request itself is blocked).
        $status = $draftReady
            ? self::STATUS_SIGNATURE_REQUESTED
            : self::STATUS_BLOCKED_BEFORE_RECEIPT_DRAFT;

        $evidence = $draftReady ? self::REQUIRED_SIGNATURE_EVIDENCE : [];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'upstream_receipt_draft_ready' => $draftReady,
            'status' => $status,
            'required_signature_evidence' => $evidence,
            // The request is "ready" only as a *request*: it is ready to be
            // signed by a later flow. It is NEVER a signature, an acceptance or
            // a release.
            'signable_request_ready' => $draftReady,
            'boundary' => $this->boundary(),
            // Restated per the doc's hard non-acceptance guarantee.
            'signature_valid' => false,
            'receipt_signed' => false,
            'receipt_persisted' => false,
            'accepts_or_validates_signature' => false,
        ];
    }

    /**
     * The question this surface explicitly does NOT answer with a yes.
     *
     * Doc "Human Meaning": it does not answer "Was the signature accepted or
     * validated?" — "The answer remains no." This is structural: regardless of
     * any input, acceptance is always false and validation is always deferred.
     *
     * @return array{
     *   question:string, signature_accepted:false, signature_valid:false,
     *   reason:string, boundary:array<string,false>
     * }
     */
    public function signatureAccepted(): array
    {
        return [
            'question' => 'was_the_signature_accepted_or_validated',
            'signature_accepted' => false,
            'signature_valid' => false,
            'reason' => self::ACCEPTANCE_DEFERRED_REASON,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Convenience composite entrypoint: evaluate the request and the (always-no)
     * acceptance answer, and prove the boundary held across both.
     *
     * @param array<string,mixed> $input forwarded to signatureRequest()
     * @return array{
     *   schema:string,
     *   signature_request:array<string,mixed>,
     *   signature_acceptance:array<string,mixed>,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(array $input = []): array
    {
        $request = $this->signatureRequest($input);
        $acceptance = $this->signatureAccepted();

        $violations = $this->assertBoundaryHeld([$request, $acceptance]);

        return [
            'schema' => self::SCHEMA,
            'signature_request' => $request,
            'signature_acceptance' => $acceptance,
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

            // The request restates four guarantees outside the boundary array;
            // any of them turning truthy is a breach too.
            foreach (['signature_valid', 'receipt_signed', 'receipt_persisted', 'accepts_or_validates_signature'] as $extra) {
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
     * default. This keeps the upstream gate impossible to trip accidentally.
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
