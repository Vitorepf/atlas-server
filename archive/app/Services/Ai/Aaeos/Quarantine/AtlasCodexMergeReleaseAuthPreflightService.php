<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release Authorization
 * Preflight — pure, deterministic, READ-ONLY surface.
 *
 * This surface answers exactly one question (doc "Human Meaning"):
 *
 *     "What still blocks a future release authorization for the persistence
 *      writer?"
 *
 * It deliberately does NOT answer:
 *
 *     "Can the writer be released now?"
 *
 * The answer to that second question is, and stays, no. Writer release stays
 * blocked until external evidence, human confirmation and a SEPARATE signed
 * release receipt path exist — none of which this preflight provides.
 *
 * Hard boundary (doc "Boundary") — every result this service emits keeps all
 * eight keys false, always:
 *   execution_allowed=false, writer_file_creation_allowed=false,
 *   ledger_write_allowed=false, dispatch_allowed=false, approval_granted=false,
 *   merge_allowed=false, signature_valid=false, receipt_persisted=false.
 *
 * The surface must not, and this code does not: create writer files, write
 * ledger events, persist receipts, accept or validate signatures, record
 * decisions, approve code, merge, or dispatch work. It only enumerates what
 * still blocks a FUTURE release authorization.
 *
 * Documented invariants this code ENFORCES (not just documents):
 *   - "must keep ... =false" boundary => boundary() returns all eight keys false
 *     and every public result embeds it verbatim; assertBoundaryHeld() proves no
 *     result ever flipped a key true.
 *   - "this preflight still blocks on: <12 conditions>" => preflight() evaluates
 *     ALL twelve documented blocking conditions and lists every unmet one as a
 *     remaining blocker. Fail-closed: absent the positive evidence/verification
 *     signal, a condition is treated as still blocking.
 *   - "this preflight is not writer implementation, not ledger write and not
 *     merge authorization" / "must not authorize writer release" =>
 *     release_authorization_granted is structurally false on every path, even
 *     when (hypothetically) every blocking condition is cleared. Clearing the
 *     blockers only flips a SECONDARY `release_authorization_eligible_for_review`
 *     signal — it never authorizes. The doc requires external evidence, human
 *     confirmation and a separate signed receipt path that this surface cannot
 *     stand in for.
 *   - "Future Outputs ... This preflight only defines those outputs. It does not
 *     create them." => futureOutputs() NAMES the four later-flow outputs and
 *     marks each created=false; the preflight emits the names, never the
 *     artifacts.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-preflight.md
 */
final class AtlasCodexMergeReleaseAuthPreflightService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_persistence_writer_release_authorization_preflight.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'writer_release_authorization_preflight';

    /** Status emitted while one or more documented conditions still block. */
    public const STATUS_BLOCKED = 'writer_release_authorization_blocked';

    /**
     * Status emitted when (and only when) every documented blocking condition is
     * cleared. It explicitly means "eligible for a SEPARATE human-confirmed,
     * signed release-receipt review" — NOT "authorized" and NOT "released".
     */
    public const STATUS_ELIGIBLE_FOR_REVIEW = 'writer_release_authorization_eligible_for_separate_review';

    /**
     * The eight boundary keys (doc "Boundary"): every result keeps all false.
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
     * The TWELVE documented blocking conditions (doc "Blocking Conditions"),
     * each expressed as the input signal that must be exactly boolean-true to
     * clear it. Order preserved exactly as documented. The first nine are
     * "missing <hash/output> present?" gates; the last three are verification
     * gates. Absent or non-true => the condition still blocks (fail-closed).
     *
     * @var array<string,string>
     */
    public const BLOCKING_CONDITIONS = [
        'missing_writer_implementation_patch_hash' => 'writer_implementation_patch_hash_present',
        'missing_writer_contract_template_hash' => 'writer_contract_template_hash_present',
        'missing_writer_implementation_preflight_hash' => 'writer_implementation_preflight_hash_present',
        'missing_writer_capability_test_output_hash' => 'writer_capability_test_output_hash_present',
        'missing_append_only_guard_test_output_hash' => 'append_only_guard_test_output_hash_present',
        'missing_merge_authority_absence_test_output_hash' => 'merge_authority_absence_test_output_hash_present',
        'missing_dispatch_authority_absence_test_output_hash' => 'dispatch_authority_absence_test_output_hash_present',
        'missing_hot_scope_recheck_output_hash' => 'hot_scope_recheck_output_hash_present',
        'missing_human_writer_release_confirmation_hash' => 'human_writer_release_confirmation_hash_present',
        'writer_patch_not_reviewed_by_principal_integrator' => 'writer_patch_reviewed_by_principal_integrator',
        'writer_contract_hash_not_verified_against_patch' => 'writer_contract_hash_verified_against_patch',
        'writer_release_not_separately_authorized' => 'writer_release_separately_authorized',
    ];

    /**
     * The FOUR later-flow outputs this preflight DEFINES but never CREATES
     * (doc "Future Outputs"). Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const FUTURE_OUTPUTS = [
        'writer_release_authorization_receipt_hash',
        'writer_release_authorization_signature_request_hash',
        'writer_release_signable_payload_hash',
        'writer_release_runbook_hash',
    ];

    /** Documented reason release authorization is never granted by this surface. */
    public const AUTHORIZATION_DEFERRED_REASON = 'writer_release_requires_external_evidence_human_confirmation_and_a_separate_signed_release_receipt_path';

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
     * Primary surface: the read-only writer release authorization PREFLIGHT.
     *
     * Evaluates all twelve documented blocking conditions and lists every unmet
     * one. release_authorization_granted is ALWAYS false (the doc forbids this
     * preflight from authorizing). When every condition is cleared, status flips
     * to "eligible for a separate review" — still not authorized, still not
     * released. Either way the boundary holds and nothing is created, signed,
     * accepted, validated, persisted, approved, merged or dispatched.
     *
     * @param array<string,mixed> $input recognised keys are the twelve clearing
     *   signals named in self::BLOCKING_CONDITIONS values. Each must be exactly
     *   boolean true to clear its condition; anything else (missing, null,
     *   "true", 1) is fail-closed and the condition keeps blocking.
     * @return array{
     *   surface:string, schema:string, status:string,
     *   remaining_blockers:list<string>, remaining_blocker_count:int,
     *   all_blocking_conditions_cleared:bool,
     *   release_authorization_granted:false,
     *   release_authorization_eligible_for_review:bool,
     *   authorization_deferred_reason:string,
     *   boundary:array<string,false>,
     *   can_writer_be_released_now:false
     * }
     */
    public function preflight(array $input = []): array
    {
        $remaining = [];
        foreach (self::BLOCKING_CONDITIONS as $blocker => $clearingSignal) {
            // Fail-closed: the condition is cleared ONLY by an exact boolean true
            // on its clearing signal. Otherwise it is still a blocker.
            if (! $this->signal($input, $clearingSignal, false)) {
                $remaining[] = $blocker;
            }
        }

        $allCleared = $remaining === [];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => $allCleared
                ? self::STATUS_ELIGIBLE_FOR_REVIEW
                : self::STATUS_BLOCKED,
            'remaining_blockers' => $remaining,
            'remaining_blocker_count' => count($remaining),
            'all_blocking_conditions_cleared' => $allCleared,
            // HARD invariant: this preflight never authorizes a writer release.
            'release_authorization_granted' => false,
            // Clearing every blocker only makes the release ELIGIBLE for a
            // separate, human-confirmed, signed-receipt review path.
            'release_authorization_eligible_for_review' => $allCleared,
            'authorization_deferred_reason' => self::AUTHORIZATION_DEFERRED_REASON,
            'boundary' => $this->boundary(),
            // The question this surface explicitly answers "no" (doc "Human
            // Meaning"). Structural, never data-dependent.
            'can_writer_be_released_now' => false,
        ];
    }

    /**
     * The four later-flow outputs this preflight DEFINES but never CREATES
     * (doc "Future Outputs"). Each is named with created=false.
     *
     * @return array{
     *   surface:string, schema:string,
     *   defines_outputs:list<string>,
     *   outputs:list<array{name:string, created:false}>,
     *   any_output_created:false,
     *   boundary:array<string,false>
     * }
     */
    public function futureOutputs(): array
    {
        $outputs = [];
        foreach (self::FUTURE_OUTPUTS as $name) {
            $outputs[] = ['name' => $name, 'created' => false];
        }

        return [
            'surface' => 'writer_release_authorization_future_outputs',
            'schema' => self::SCHEMA,
            'defines_outputs' => self::FUTURE_OUTPUTS,
            'outputs' => $outputs,
            // The doc is explicit: this preflight only DEFINES these; it creates
            // none of them.
            'any_output_created' => false,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * The question this surface explicitly does NOT answer with a yes.
     *
     * Doc "Human Meaning": it does not answer "Can the writer be released now?".
     * The answer stays no until external evidence, human confirmation and a
     * separate signed release receipt path exist. Structural: regardless of any
     * input, this is always false.
     *
     * @return array{
     *   question:string, can_writer_be_released_now:false,
     *   release_authorization_granted:false, reason:string,
     *   boundary:array<string,false>
     * }
     */
    public function releaseDecision(): array
    {
        return [
            'question' => 'can_the_writer_be_released_now',
            'can_writer_be_released_now' => false,
            'release_authorization_granted' => false,
            'reason' => self::AUTHORIZATION_DEFERRED_REASON,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Convenience composite entrypoint: evaluate the preflight, the defined
     * future outputs and the (always-no) release decision, and prove the
     * boundary held across all three.
     *
     * @param array<string,mixed> $input forwarded to preflight()
     * @return array{
     *   schema:string,
     *   preflight:array<string,mixed>,
     *   future_outputs:array<string,mixed>,
     *   release_decision:array<string,mixed>,
     *   release_authorization_granted:false,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(array $input = []): array
    {
        $preflight = $this->preflight($input);
        $futureOutputs = $this->futureOutputs();
        $releaseDecision = $this->releaseDecision();

        $violations = $this->assertBoundaryHeld([$preflight, $futureOutputs, $releaseDecision]);

        return [
            'schema' => self::SCHEMA,
            'preflight' => $preflight,
            'future_outputs' => $futureOutputs,
            'release_decision' => $releaseDecision,
            // Restated at the top level: composite never authorizes a release.
            'release_authorization_granted' => false,
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
        ];
    }

    /**
     * Prove that no result ever flipped a boundary key (or a restated release
     * guarantee) to a truthy value. Returns the list of "surface.key"
     * violations (empty = boundary intact).
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
            foreach (['release_authorization_granted', 'can_writer_be_released_now', 'any_output_created'] as $extra) {
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
     * default. This keeps every blocking-condition gate impossible to trip
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
