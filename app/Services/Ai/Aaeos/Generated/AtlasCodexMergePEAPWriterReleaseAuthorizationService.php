<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release AUTHORIZATION
 * (bare template) — pure, deterministic, READ-ONLY surface.
 *
 * IMPORTANT distinction from its three siblings:
 *   - AtlasCodexMergeReleaseAuthPreflightService governs the
 *     "...release-authorization-PREFLIGHT" doc (twelve blocking conditions,
 *     eight boundary keys, "what still blocks").
 *   - AtlasCodexMergePEAPWriterReleaseAuthSignatureReqService governs the
 *     "...release-authorization-SIGNATURE-REQUEST" doc (ten-component signable
 *     payload, nine boundary keys including receipt_signed).
 *   - AtlasCodexMergeReleaseAuthPostSignatureRunbookService governs the
 *     "...release-authorization-POST-SIGNATURE-RUNBOOK" doc (ordered steps).
 * THIS surface governs the bare "...release-authorization" doc: the read-only
 * release-authorization TEMPLATE that names the evidence and checks a FUTURE
 * authorization would require, and the scope only a separately authorized future
 * writer could ever hold.
 *
 * This surface answers exactly one question (doc "Human Meaning"):
 *
 *     "What evidence would be required before releasing a receipt persistence
 *      writer?"
 *
 * It deliberately does NOT answer (doc "Human Meaning"):
 *
 *     "Can the writer be implemented or executed now?"
 *
 * The answer to that second question is, and stays, no — it "remains blocked
 * until a separate implementation and human release authorization exist."
 *
 * Hard boundary (doc "Boundary") — every result this service emits keeps all
 * EIGHT keys false, always:
 *   execution_allowed=false, writer_file_creation_allowed=false,
 *   ledger_write_allowed=false, dispatch_allowed=false, approval_granted=false,
 *   merge_allowed=false, signature_valid=false, receipt_persisted=false.
 *
 * The surface must not, and this code does not (doc "It must not"): create
 * writer files, write ledger events, persist receipts, accept or validate
 * signatures, record decisions, approve code, merge, or dispatch work. It only
 * enumerates the evidence a FUTURE authorization would collect, the checks it
 * would prove, and the scope a SEPARATELY authorized future writer could hold.
 *
 * Documented invariants this code ENFORCES (not just documents):
 *   - "must keep ...=false" boundary (doc "Boundary") => boundary() returns all
 *     eight keys false and every public result embeds it verbatim;
 *     assertBoundaryHeld() proves no result ever flipped a key true, and the
 *     assertion is non-vacuous.
 *   - "A future release authorization must collect: <nine items>" (doc "Required
 *     Evidence") => requiredEvidence() lists exactly those nine evidence item
 *     names, in documented order, each marked collected=false (this TEMPLATE
 *     collects none of them).
 *   - "Before a future writer can be released, the authorization must prove:
 *     <nine checks>" (doc "Required Checks") => requiredChecks() evaluates ALL
 *     nine documented checks. Fail-closed: absent the positive proof signal a
 *     check is treated as NOT proven. authorization_granted is structurally
 *     false on every path — even when all nine checks are (hypothetically)
 *     proven, clearing them only flips a SECONDARY
 *     `eligible_for_separate_authorization` signal; the template never
 *     authorizes (doc: "This template does not grant that authority." /
 *     "Writer release authorization is separate from writer implementation
 *     preflight." / decision: "must not authorize writer creation or ledger
 *     writes").
 *   - "Only a separately authorized future writer may: <six actions> ... This
 *     template does not grant that authority." (doc "Future Authorized Writer
 *     Scope") => futureAuthorizedWriterScope() NAMES exactly those six actions,
 *     in order, each marked granted_to_template=false, including the capped
 *     "write ONE append-only persistence event after all checks pass".
 *   - "It does not answer 'Can the writer be implemented or executed now?'. That
 *     remains blocked..." (doc "Human Meaning") => implementationStatus() always
 *     returns can_writer_be_implemented_or_executed_now=false with the
 *     documented deferral reason, structurally, regardless of input.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization.md
 */
final class AtlasCodexMergePEAPWriterReleaseAuthorizationService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_persistence_writer_release_authorization.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'writer_release_authorization_template';

    /** Status while one or more documented required checks are NOT yet proven. */
    public const STATUS_BLOCKED = 'writer_release_authorization_blocked';

    /**
     * Status emitted when (and only when) every documented required check is
     * proven. It explicitly means "eligible for a SEPARATE implementation and
     * human release authorization" — NOT "authorized" and NOT "released".
     */
    public const STATUS_ELIGIBLE_FOR_SEPARATE_AUTHORIZATION = 'writer_release_eligible_for_separate_authorization';

    /** Documented reason this template never authorizes (doc "Human Meaning"). */
    public const AUTHORIZATION_DEFERRED_REASON = 'writer_release_remains_blocked_until_a_separate_implementation_and_human_release_authorization_exist';

    /**
     * The EIGHT boundary keys (doc "Boundary"): every result keeps all false.
     * Note this template's boundary has no `receipt_signed` key (that belongs to
     * the signature-request sibling); it does have signature_valid AND
     * receipt_persisted.
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
        'receipt_persisted',
    ];

    /**
     * The NINE required-evidence items a FUTURE release authorization must
     * collect (doc "Required Evidence"). This template only NAMES them; it
     * collects none. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_EVIDENCE = [
        'writer_implementation_patch_hash',
        'writer_contract_template_hash',
        'writer_implementation_preflight_hash',
        'writer_capability_test_output_hash',
        'append_only_guard_test_output_hash',
        'merge_authority_absence_test_output_hash',
        'dispatch_authority_absence_test_output_hash',
        'hot_scope_recheck_output_hash',
        'human_writer_release_confirmation_hash',
    ];

    /**
     * The NINE required checks a future authorization must prove before a future
     * writer can be released (doc "Required Checks"), each expressed as the input
     * signal that must be exactly boolean-true to count as proven. Order
     * preserved exactly as documented. Absent or non-true => the check is NOT
     * proven (fail-closed).
     *
     * @var array<string,string>
     */
    public const REQUIRED_CHECKS = [
        'writer_implementation_preflight_ready' => 'writer_implementation_preflight_ready',
        'writer_patch_reviewed_by_principal_integrator' => 'writer_patch_reviewed_by_principal_integrator',
        'writer_contract_hash_matches_patch' => 'writer_contract_hash_matches_patch',
        'required_capability_tests_pass' => 'required_capability_tests_pass',
        'append_only_guard_passes' => 'append_only_guard_passes',
        'merge_authority_absent' => 'merge_authority_absent',
        'dispatch_authority_absent' => 'dispatch_authority_absent',
        'hot_scope_clean_at_release_time' => 'hot_scope_clean_at_release_time',
        'human_writer_release_confirmation_present' => 'human_writer_release_confirmation_present',
    ];

    /**
     * The SIX actions only a SEPARATELY authorized future writer may take (doc
     * "Future Authorized Writer Scope"). This template does NOT grant that
     * authority; each is named with granted_to_template=false. Order preserved
     * exactly as documented. The final action is capped to ONE append-only
     * persistence event, and only "after all checks pass".
     *
     * @var list<string>
     */
    public const FUTURE_AUTHORIZED_WRITER_SCOPE = [
        'validate_non_null_payload_fields',
        'recompute_payload_hash',
        'enforce_source_hash_match',
        'enforce_hot_scope_recheck',
        'require_human_confirmation_hash',
        'write_one_append_only_persistence_event_after_all_checks_pass',
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
     * The nine required-evidence items (doc "Required Evidence"), each marked
     * collected=false: this template collects none of them. A future release
     * authorization is responsible for actually collecting each.
     *
     * @return array{
     *   surface:string, schema:string,
     *   required_evidence:list<string>,
     *   evidence:list<array{item:string, collected:false}>,
     *   evidence_item_count:int, any_evidence_collected:false,
     *   boundary:array<string,false>
     * }
     */
    public function requiredEvidence(): array
    {
        $evidence = [];
        foreach (self::REQUIRED_EVIDENCE as $item) {
            $evidence[] = ['item' => $item, 'collected' => false];
        }

        return [
            'surface' => 'writer_release_authorization_required_evidence',
            'schema' => self::SCHEMA,
            'required_evidence' => self::REQUIRED_EVIDENCE,
            'evidence' => $evidence,
            'evidence_item_count' => count(self::REQUIRED_EVIDENCE),
            // The doc is explicit: this template only NAMES the evidence; it
            // collects none of it.
            'any_evidence_collected' => false,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Primary surface: the read-only writer release authorization TEMPLATE.
     *
     * Evaluates all nine documented required checks and lists every unmet one.
     * authorization_granted is ALWAYS false (the doc forbids this template from
     * authorizing: "This template does not grant that authority."). When every
     * check is proven, status flips to "eligible for a separate authorization" —
     * still not authorized, still not released. Either way the boundary holds and
     * nothing is created, signed, accepted, validated, persisted, recorded,
     * approved, merged or dispatched.
     *
     * @param array<string,mixed> $input recognised keys are the nine proof
     *   signals named in self::REQUIRED_CHECKS values. Each must be exactly
     *   boolean true to count as proven; anything else (missing, null, "true", 1)
     *   is fail-closed and the check stays unproven.
     * @return array{
     *   surface:string, schema:string, status:string,
     *   required_checks:list<string>, unproven_checks:list<string>,
     *   unproven_check_count:int, all_required_checks_proven:bool,
     *   authorization_granted:false,
     *   eligible_for_separate_authorization:bool,
     *   authorization_deferred_reason:string,
     *   required_evidence:array<string,mixed>,
     *   future_authorized_writer_scope:array<string,mixed>,
     *   boundary:array<string,false>,
     *   can_writer_be_implemented_or_executed_now:false
     * }
     */
    public function authorize(array $input = []): array
    {
        $unproven = [];
        foreach (self::REQUIRED_CHECKS as $check => $proofSignal) {
            // Fail-closed: a check counts as proven ONLY on an exact boolean true.
            if (! $this->signal($input, $proofSignal, false)) {
                $unproven[] = $check;
            }
        }

        $allProven = $unproven === [];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => $allProven
                ? self::STATUS_ELIGIBLE_FOR_SEPARATE_AUTHORIZATION
                : self::STATUS_BLOCKED,
            'required_checks' => array_keys(self::REQUIRED_CHECKS),
            'unproven_checks' => $unproven,
            'unproven_check_count' => count($unproven),
            'all_required_checks_proven' => $allProven,
            // HARD invariant: this template never authorizes a writer release.
            'authorization_granted' => false,
            // Proving every check only makes the release ELIGIBLE for a separate
            // implementation + human release authorization path.
            'eligible_for_separate_authorization' => $allProven,
            'authorization_deferred_reason' => self::AUTHORIZATION_DEFERRED_REASON,
            'required_evidence' => $this->requiredEvidence(),
            'future_authorized_writer_scope' => $this->futureAuthorizedWriterScope(),
            'boundary' => $this->boundary(),
            // The question this surface explicitly answers "no" (doc "Human
            // Meaning"). Structural, never data-dependent.
            'can_writer_be_implemented_or_executed_now' => false,
        ];
    }

    /**
     * The six actions only a SEPARATELY authorized future writer may take (doc
     * "Future Authorized Writer Scope"). Each is named with
     * granted_to_template=false; the final one is capped to a single append-only
     * persistence event, only after all checks pass. The doc is explicit: "This
     * template does not grant that authority."
     *
     * @return array{
     *   surface:string, schema:string,
     *   future_authorized_writer_scope:list<string>,
     *   scope:list<array{action:string, granted_to_template:false}>,
     *   append_only_event_cap:int, granted_to_template:false,
     *   note:string, boundary:array<string,false>
     * }
     */
    public function futureAuthorizedWriterScope(): array
    {
        $scope = [];
        foreach (self::FUTURE_AUTHORIZED_WRITER_SCOPE as $action) {
            $scope[] = ['action' => $action, 'granted_to_template' => false];
        }

        return [
            'surface' => 'writer_release_authorization_future_authorized_writer_scope',
            'schema' => self::SCHEMA,
            'future_authorized_writer_scope' => self::FUTURE_AUTHORIZED_WRITER_SCOPE,
            'scope' => $scope,
            // Doc: a future writer may "write ONE append-only persistence event
            // after all checks pass" — the cap is exactly one.
            'append_only_event_cap' => 1,
            // Doc: "This template does not grant that authority."
            'granted_to_template' => false,
            'note' => 'this_template_does_not_grant_that_authority',
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * The question this surface explicitly does NOT answer with a yes.
     *
     * Doc "Human Meaning": it does not answer "Can the writer be implemented or
     * executed now?" — that "remains blocked until a separate implementation and
     * human release authorization exist." Structural: regardless of any input,
     * this is always false.
     *
     * @return array{
     *   question:string,
     *   can_writer_be_implemented_or_executed_now:false,
     *   authorization_granted:false, reason:string,
     *   boundary:array<string,false>
     * }
     */
    public function implementationStatus(): array
    {
        return [
            'question' => 'can_the_writer_be_implemented_or_executed_now',
            'can_writer_be_implemented_or_executed_now' => false,
            'authorization_granted' => false,
            'reason' => self::AUTHORIZATION_DEFERRED_REASON,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Convenience composite entrypoint: evaluate the required checks, the named
     * required evidence, the future-authorized writer scope and the (always-no)
     * implementation status, and prove the boundary held across all of them.
     *
     * @param array<string,mixed> $input forwarded to authorize()
     * @return array{
     *   schema:string,
     *   authorization:array<string,mixed>,
     *   required_evidence:array<string,mixed>,
     *   future_authorized_writer_scope:array<string,mixed>,
     *   implementation_status:array<string,mixed>,
     *   forbidden_actions:list<string>,
     *   authorization_granted:false,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(array $input = []): array
    {
        $authorization = $this->authorize($input);
        $requiredEvidence = $this->requiredEvidence();
        $scope = $this->futureAuthorizedWriterScope();
        $implementationStatus = $this->implementationStatus();

        $violations = $this->assertBoundaryHeld([
            $authorization,
            $requiredEvidence,
            $scope,
            $implementationStatus,
        ]);

        return [
            'schema' => self::SCHEMA,
            'authorization' => $authorization,
            'required_evidence' => $requiredEvidence,
            'future_authorized_writer_scope' => $scope,
            'implementation_status' => $implementationStatus,
            'forbidden_actions' => self::FORBIDDEN_ACTIONS,
            // Restated at the top level: composite never authorizes a release.
            'authorization_granted' => false,
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
        ];
    }

    /**
     * Prove that no result ever flipped a boundary key (or a restated release
     * guarantee) to a truthy value. Returns the list of "label.key" violations
     * (empty = boundary intact).
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

            // These guarantees are restated outside the boundary array; any of
            // them turning truthy is a breach too.
            foreach ([
                'authorization_granted',
                'can_writer_be_implemented_or_executed_now',
                'any_evidence_collected',
                'granted_to_template',
            ] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }

    /**
     * Read a boolean signal. Only an exact boolean true counts; anything else
     * (missing key, null, truthy-string, 1) falls back to the fail-closed
     * default. This keeps every required-check gate impossible to trip
     * accidentally.
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
