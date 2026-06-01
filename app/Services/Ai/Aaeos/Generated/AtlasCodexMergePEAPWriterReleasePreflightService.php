<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Codex Merge Post-Execution Action Persistence Writer RELEASE Preflight — pure,
 * deterministic, READ-ONLY surface.
 *
 * This surface answers exactly one question (doc "Human Meaning"):
 *
 *     "What still blocks releasing a receipt persistence writer?"
 *
 * It deliberately does NOT answer:
 *
 *     "Has the writer been released?"
 *
 * The answer to that second question is, and stays, no. Writer release requires
 * later signed evidence, validation, an execution contract and another governed
 * receipt path — none of which this preflight provides.
 *
 * It is the plain RELEASE preflight, distinct from its three sibling preflights,
 * each of which binds to its own doc:
 *   - the WRITER preflight (...-writer-preflight.md),
 *   - the RELEASE AUTHORIZATION preflight (...-writer-release-authorization-preflight.md),
 *   - the RELEASE EXECUTION CONTRACT preflight (...-writer-release-execution-contract-preflight.md).
 * This one carries a NINE-key boundary and an upstream gate on the writer
 * release authorization SIGNED RECEIPT template.
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
 * decisions, approve code, merge, or dispatch work. It only enumerates what
 * still blocks releasing the receipt persistence writer.
 *
 * Documented invariants this code ENFORCES (not just documents):
 *   - "must keep ... =false" boundary (doc "Boundary") => boundary() returns all
 *     nine keys false and every public result embeds it verbatim;
 *     assertBoundaryHeld() proves no result ever flipped a key true.
 *   - Required Upstream Contract (doc "Required Upstream Contract"): the preflight
 *     depends on the writer release authorization signed receipt template. If that
 *     template is not ready the surface returns the literal
 *     `writer_release_authorization_signed_receipt_template_not_ready` and reports
 *     NOT ready — the upstream gate fails closed and short-circuits the release
 *     checks.
 *   - Release Checks (doc "Release Checks"): preflight() evaluates ALL NINE
 *     documented proofs a later surface must establish and lists every unmet one
 *     as a remaining blocker. Fail-closed: absent the exact boolean-true proof
 *     signal, a check is treated as still blocking.
 *   - "The answer remains no" (doc "Human Meaning") => writer_released is
 *     structurally false on every path, even when (hypothetically) the template is
 *     ready AND every release check passes. Clearing the blockers only flips a
 *     SECONDARY `eligible_for_separate_signed_release_path` signal — it never
 *     releases the writer.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-preflight.md
 */
final class AtlasCodexMergePEAPWriterReleasePreflightService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_codex_review_merge_post_execution_action_signed_receipt_persistence_writer_release_preflight.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'writer_release_preflight';

    /** Status emitted while the upstream template is not ready (hard short-circuit). */
    public const STATUS_UPSTREAM_NOT_READY = 'writer_release_authorization_signed_receipt_template_not_ready';

    /** Status emitted when the template is ready but one or more release checks still block. */
    public const STATUS_BLOCKED = 'writer_release_blocked';

    /**
     * Status emitted when (and only when) the template is ready AND every
     * documented release check passes. It explicitly means "eligible for a
     * SEPARATE later signed-evidence, validated, execution-contract and governed
     * receipt release path" — NOT "released", NOT "authorized".
     */
    public const STATUS_ELIGIBLE = 'writer_release_eligible_for_separate_signed_release_path';

    /**
     * The literal token the doc mandates be returned when the upstream writer
     * release authorization signed receipt template is not ready (doc "Required
     * Upstream Contract").
     */
    public const UPSTREAM_NOT_READY_TOKEN = 'writer_release_authorization_signed_receipt_template_not_ready';

    /**
     * The nine boundary keys (doc "Boundary"): every result keeps all false.
     * Order preserved exactly as documented.
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
     * The NINE documented release checks (doc "Release Checks"), each expressed as
     * the input signal that must be exactly boolean-true to clear it. The blocker
     * names align with the repo-wide self-construction readiness vocabulary. Order
     * preserved exactly as the doc lists them. Absent or non-true => the check
     * still blocks (fail-closed).
     *
     * @var array<string,string>
     */
    public const RELEASE_CHECKS = [
        // "external signed receipt evidence is present"
        'missing_external_signed_writer_release_authorization_evidence' => 'external_signed_receipt_evidence_present',
        // "selected decision equals authorize_writer_release"
        'selected_decision_not_authorize_writer_release' => 'selected_decision_equals_authorize_writer_release',
        // "writer contract hash still matches the patch"
        'writer_contract_hash_not_rechecked_against_patch' => 'writer_contract_hash_still_matches_patch',
        // "hot scope is still clean"
        'hot_scope_recheck_missing' => 'hot_scope_still_clean',
        // "writer capability tests still pass"
        'writer_capability_tests_not_rerun' => 'writer_capability_tests_still_pass',
        // "writer has no merge authority"
        'writer_merge_authority_absence_not_verified' => 'writer_has_no_merge_authority',
        // "writer has no dispatch authority"
        'writer_dispatch_authority_absence_not_verified' => 'writer_has_no_dispatch_authority',
        // "release actor identity is present"
        'writer_release_actor_identity_missing' => 'release_actor_identity_present',
        // "receipt persistence plan exists"
        'writer_release_receipt_persistence_plan_missing' => 'receipt_persistence_plan_exists',
    ];

    /** Documented reason the writer is never released by this surface. */
    public const RELEASE_DEFERRED_REASON = 'writer_release_requires_later_signed_evidence_validation_an_execution_contract_and_another_governed_receipt_path';

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
     * The release-checks surface: declares the nine proofs a later surface must
     * establish before the writer can be released, and reports which are still
     * unmet (standing blockers). Implements/validates none of them.
     *
     * @param array<string,mixed> $input boolean proof signals keyed by the values
     *   of self::RELEASE_CHECKS. Each must be exactly boolean true to clear its
     *   check; missing/non-true => unmet => blocker.
     * @return array{
     *   surface:string, schema:string,
     *   release_checks:list<string>, count:int,
     *   checks:array<string,bool>, unmet_checks:list<string>,
     *   all_checks_pass:bool, boundary:array<string,false>
     * }
     */
    public function releaseChecks(array $input = []): array
    {
        $state = [];
        $unmet = [];
        foreach (self::RELEASE_CHECKS as $blocker => $clearingSignal) {
            // Fail-closed: cleared ONLY by an exact boolean true on its signal.
            $passed = $this->signal($input, $clearingSignal);
            $state[$blocker] = $passed;
            if (! $passed) {
                $unmet[] = $blocker;
            }
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'release_checks' => array_keys(self::RELEASE_CHECKS),
            'count' => count(self::RELEASE_CHECKS),
            'checks' => $state,
            'unmet_checks' => $unmet,
            'all_checks_pass' => $unmet === [],
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Primary surface: the read-only writer RELEASE preflight.
     *
     * First enforces the upstream gate (doc "Required Upstream Contract"): if the
     * writer release authorization signed receipt template is not ready, the
     * surface short-circuits, returns the literal
     * `writer_release_authorization_signed_receipt_template_not_ready`, reports NOT
     * ready, and lists no check results (the upstream blocker is the only one
     * surfaced). When the template IS ready it evaluates all nine documented
     * release checks and lists every unmet one.
     *
     * writer_released is ALWAYS false (the doc's answer "remains no"). When the
     * template is ready and every check passes, status flips to "eligible for a
     * separate signed release path" — still not released, still not authorized.
     * Either way the boundary holds and nothing is created, signed, accepted,
     * validated, persisted, approved, merged or dispatched.
     *
     * @param array<string,mixed> $input recognised keys are
     *   `writer_release_authorization_signed_receipt_template_ready` (the upstream
     *   gate) plus the nine clearing signals named in self::RELEASE_CHECKS values.
     *   Each must be exactly boolean true; anything else (missing, null, "true", 1)
     *   is fail-closed.
     * @return array{
     *   surface:string, schema:string, status:string,
     *   signed_receipt_template_ready:bool,
     *   upstream_not_ready_token:?string,
     *   release_checks:array<string,mixed>,
     *   remaining_blockers:list<string>, remaining_blocker_count:int,
     *   all_release_checks_pass:bool,
     *   writer_released:false,
     *   eligible_for_separate_signed_release_path:bool,
     *   release_deferred_reason:string,
     *   boundary:array<string,false>,
     *   has_the_writer_been_released:false,
     *   human_question:string, does_not_answer:string
     * }
     */
    public function preflight(array $input = []): array
    {
        $templateReady = $this->signal($input, 'writer_release_authorization_signed_receipt_template_ready');

        // Required Upstream Contract: not ready => short-circuit with the literal
        // documented token and NO check evaluation. Fail-closed by default.
        if (! $templateReady) {
            return [
                'surface' => self::SURFACE,
                'schema' => self::SCHEMA,
                'status' => self::STATUS_UPSTREAM_NOT_READY,
                'signed_receipt_template_ready' => false,
                'upstream_not_ready_token' => self::UPSTREAM_NOT_READY_TOKEN,
                'release_checks' => $this->releaseChecksNotEvaluated(),
                'remaining_blockers' => [self::UPSTREAM_NOT_READY_TOKEN],
                'remaining_blocker_count' => 1,
                'all_release_checks_pass' => false,
                // HARD invariant: never releases the writer.
                'writer_released' => false,
                'eligible_for_separate_signed_release_path' => false,
                'release_deferred_reason' => self::RELEASE_DEFERRED_REASON,
                'boundary' => $this->boundary(),
                'has_the_writer_been_released' => false,
                'human_question' => 'What still blocks releasing a receipt persistence writer?',
                'does_not_answer' => 'Has the writer been released?',
            ];
        }

        $checks = $this->releaseChecks($input);
        $allPass = $checks['unmet_checks'] === [];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => $allPass ? self::STATUS_ELIGIBLE : self::STATUS_BLOCKED,
            'signed_receipt_template_ready' => true,
            'upstream_not_ready_token' => null,
            'release_checks' => $checks,
            'remaining_blockers' => $checks['unmet_checks'],
            'remaining_blocker_count' => count($checks['unmet_checks']),
            'all_release_checks_pass' => $allPass,
            // HARD invariant: this preflight never releases the writer.
            'writer_released' => false,
            // Clearing every check + ready template only makes it ELIGIBLE for a
            // separate later signed-evidence / validated / execution-contract /
            // governed-receipt release path.
            'eligible_for_separate_signed_release_path' => $allPass,
            'release_deferred_reason' => self::RELEASE_DEFERRED_REASON,
            'boundary' => $this->boundary(),
            // Doc "Human Meaning": the answer remains no, structurally.
            'has_the_writer_been_released' => false,
            'human_question' => 'What still blocks releasing a receipt persistence writer?',
            'does_not_answer' => 'Has the writer been released?',
        ];
    }

    /**
     * Convenience: the flat list of remaining blockers a future writer release
     * must clear, derived from the same preflight logic.
     *
     * @param array<string,mixed> $input
     * @return list<string>
     */
    public function blockers(array $input = []): array
    {
        return $this->preflight($input)['remaining_blockers'];
    }

    /**
     * Composite entrypoint: run the preflight and prove the boundary held.
     *
     * @param array<string,mixed> $input forwarded to preflight()
     * @return array{
     *   schema:string,
     *   preflight:array<string,mixed>,
     *   writer_released:false,
     *   boundary_held:bool, boundary_violations:list<string>
     * }
     */
    public function evaluate(array $input = []): array
    {
        $preflight = $this->preflight($input);
        $violations = $this->assertBoundaryHeld([$preflight]);

        return [
            'schema' => self::SCHEMA,
            'preflight' => $preflight,
            // Restated at the top level: composite never releases the writer.
            'writer_released' => false,
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
        ];
    }

    /**
     * Prove that no result ever flipped a boundary key (or a restated guarantee)
     * to a truthy value. Returns the list of "surface.key" violations (empty =
     * boundary intact).
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

            // These guarantees are restated outside the boundary array; any of
            // them turning truthy is a breach too.
            foreach (['writer_released', 'has_the_writer_been_released'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }

    /**
     * The release-checks block shape when the upstream gate short-circuits before
     * any check is evaluated.
     *
     * @return array{
     *   surface:string, schema:string,
     *   release_checks:list<string>, count:int,
     *   checks:array<string,bool>, unmet_checks:list<string>,
     *   all_checks_pass:false, evaluated:false, boundary:array<string,false>
     * }
     */
    private function releaseChecksNotEvaluated(): array
    {
        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'release_checks' => array_keys(self::RELEASE_CHECKS),
            'count' => count(self::RELEASE_CHECKS),
            'checks' => [],
            'unmet_checks' => [],
            'all_checks_pass' => false,
            'evaluated' => false,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Read a boolean signal. Only an exact boolean true clears it; anything else
     * (missing key, null, truthy-string, 1) is fail-closed. This keeps every gate
     * impossible to trip accidentally.
     *
     * @param array<string,mixed> $input
     */
    private function signal(array $input, string $key): bool
    {
        return ($input[$key] ?? null) === true;
    }
}
