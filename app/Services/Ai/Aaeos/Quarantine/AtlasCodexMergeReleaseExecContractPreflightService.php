<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Codex Merge Post-Execution Action Persistence Writer Release EXECUTION CONTRACT
 * Preflight — pure, deterministic, READ-ONLY surface.
 *
 * This surface answers exactly one question (doc "Human Meaning"):
 *
 *     "What blocks a future writer release execution contract?"
 *
 * It deliberately does NOT answer:
 *
 *     "Can Atlas release or execute the writer now?"
 *
 * The answer to that second question is, and stays, no. A future execution
 * contract must still be created, reviewed and gated by a SEPARATE governed
 * surface — this preflight is a blocker report, never an execution contract.
 *
 * It is distinct from its two sibling preflights, which bind to their own docs:
 *   - the WRITER preflight (...-writer-preflight.md), and
 *   - the RELEASE AUTHORIZATION preflight (...-writer-release-authorization-preflight.md).
 * This one is the preflight for the EXECUTION CONTRACT itself, and uniquely
 * carries a NINE-key boundary (it adds receipt_signed on top of the eight) plus
 * an upstream signed-receipt-template readiness gate.
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
 * blocks a FUTURE writer release execution contract.
 *
 * Documented invariants this code ENFORCES (not just documents):
 *   - "must keep ... =false" boundary (doc "Boundary") => boundary() returns all
 *     nine keys false and every public result embeds it verbatim;
 *     assertBoundaryHeld() proves no result ever flipped a key true.
 *   - Required Upstream Contract (doc "Required Upstream Contract"): the preflight
 *     depends on the writer release signed receipt template. If that template is
 *     not ready the surface returns the literal
 *     `writer_release_signed_receipt_template_not_ready` and reports NOT ready —
 *     the upstream gate fails closed and short-circuits the required checks.
 *   - Required Checks (doc "Required Checks"): preflight() evaluates ALL NINE
 *     documented proofs a later surface must establish and lists every unmet one
 *     as a remaining blocker. Fail-closed: absent the exact boolean-true proof
 *     signal, a check is treated as still blocking.
 *   - "Execution contract preflight is a blocker report, not an execution
 *     contract" / "The answer remains no" (doc "Human Meaning") =>
 *     execution_contract_allowed is structurally false on every path, even when
 *     (hypothetically) the template is ready AND every required check passes.
 *     Clearing the blockers only flips a SECONDARY
 *     `execution_contract_eligible_for_separate_governed_surface` signal — it
 *     never authorizes and never creates the contract.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-execution-contract-preflight.md
 */
final class AtlasCodexMergeReleaseExecContractPreflightService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_post_execution_action_persistence_writer_release_execution_contract_preflight.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'writer_release_execution_contract_preflight';

    /** Status emitted while the upstream template is not ready (hard short-circuit). */
    public const STATUS_UPSTREAM_NOT_READY = 'writer_release_signed_receipt_template_not_ready';

    /** Status emitted when the template is ready but one or more checks still block. */
    public const STATUS_BLOCKED = 'writer_release_execution_contract_blocked';

    /**
     * Status emitted when (and only when) the template is ready AND every
     * documented required check passes. It explicitly means "eligible for a
     * SEPARATE governed surface to create, review and gate an execution contract"
     * — NOT "allowed", NOT "authorized", NOT "executed".
     */
    public const STATUS_ELIGIBLE = 'writer_release_execution_contract_eligible_for_separate_governed_surface';

    /**
     * The literal token the doc mandates be returned when the upstream signed
     * receipt template is not ready (doc "Required Upstream Contract").
     */
    public const UPSTREAM_NOT_READY_TOKEN = 'writer_release_signed_receipt_template_not_ready';

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
     * The NINE documented required checks (doc "Required Checks"), each expressed
     * as the input signal that must be exactly boolean-true to clear it. Order
     * preserved exactly as documented. Absent or non-true => the check still
     * blocks (fail-closed).
     *
     * @var array<string,string>
     */
    public const REQUIRED_CHECKS = [
        'external_validated_signature_evidence_missing' => 'external_validated_signature_evidence_present',
        'selected_decision_not_authorize_writer_release' => 'selected_decision_equals_authorize_writer_release',
        'writer_contract_hash_not_matching_patch' => 'writer_contract_hash_matches_patch',
        'hot_scope_not_clean' => 'hot_scope_clean',
        'writer_capability_tests_not_passing' => 'writer_capability_tests_pass',
        'writer_has_merge_authority' => 'writer_has_no_merge_authority',
        'writer_has_dispatch_authority' => 'writer_has_no_dispatch_authority',
        'execution_scope_not_writer_release_only' => 'execution_scope_is_writer_release_only',
        'rollback_and_disable_path_not_defined' => 'rollback_and_disable_path_defined',
    ];

    /** Documented reason an execution contract is never granted by this surface. */
    public const CONTRACT_DEFERRED_REASON = 'a_future_execution_contract_must_still_be_created_reviewed_and_gated_by_a_separate_governed_surface';

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
     * The required-checks surface: declares the nine proofs a later surface must
     * establish before an execution contract can exist, and reports which are
     * still unmet (standing blockers). Implements/validates none of them.
     *
     * @param array<string,mixed> $input boolean proof signals keyed by the values
     *   of self::REQUIRED_CHECKS. Each must be exactly boolean true to clear its
     *   check; missing/non-true => unmet => blocker.
     * @return array{
     *   surface:string, schema:string,
     *   required_checks:list<string>, count:int,
     *   checks:array<string,bool>, unmet_checks:list<string>,
     *   all_checks_pass:bool, boundary:array<string,false>
     * }
     */
    public function requiredChecks(array $input = []): array
    {
        $state = [];
        $unmet = [];
        foreach (self::REQUIRED_CHECKS as $blocker => $clearingSignal) {
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
            'required_checks' => array_keys(self::REQUIRED_CHECKS),
            'count' => count(self::REQUIRED_CHECKS),
            'checks' => $state,
            'unmet_checks' => $unmet,
            'all_checks_pass' => $unmet === [],
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Primary surface: the read-only writer release EXECUTION CONTRACT preflight.
     *
     * First enforces the upstream gate (doc "Required Upstream Contract"): if the
     * signed receipt template is not ready, the surface short-circuits, returns
     * the literal `writer_release_signed_receipt_template_not_ready`, reports NOT
     * ready, and lists no check results (the upstream blocker is the only one
     * surfaced). When the template IS ready it evaluates all nine documented
     * required checks and lists every unmet one.
     *
     * execution_contract_allowed is ALWAYS false (the doc forbids this preflight
     * from being or authorizing an execution contract). When the template is
     * ready and every check passes, status flips to "eligible for a separate
     * governed surface" — still not allowed, still not created. Either way the
     * boundary holds and nothing is created, signed, accepted, validated,
     * persisted, approved, merged or dispatched.
     *
     * @param array<string,mixed> $input recognised keys are
     *   `signed_receipt_template_ready` (the upstream gate) plus the nine clearing
     *   signals named in self::REQUIRED_CHECKS values. Each must be exactly
     *   boolean true; anything else (missing, null, "true", 1) is fail-closed.
     * @return array{
     *   surface:string, schema:string, status:string,
     *   signed_receipt_template_ready:bool,
     *   upstream_not_ready_token:?string,
     *   required_checks:array<string,mixed>,
     *   remaining_blockers:list<string>, remaining_blocker_count:int,
     *   all_required_checks_pass:bool,
     *   execution_contract_allowed:false,
     *   execution_contract_eligible_for_separate_governed_surface:bool,
     *   contract_deferred_reason:string,
     *   boundary:array<string,false>,
     *   can_release_or_execute_writer_now:false,
     *   human_question:string, does_not_answer:string
     * }
     */
    public function preflight(array $input = []): array
    {
        $templateReady = $this->signal($input, 'signed_receipt_template_ready');

        // Required Upstream Contract: not ready => short-circuit with the literal
        // documented token and NO check evaluation. Fail-closed by default.
        if (! $templateReady) {
            return [
                'surface' => self::SURFACE,
                'schema' => self::SCHEMA,
                'status' => self::STATUS_UPSTREAM_NOT_READY,
                'signed_receipt_template_ready' => false,
                'upstream_not_ready_token' => self::UPSTREAM_NOT_READY_TOKEN,
                'required_checks' => $this->requiredChecksNotEvaluated(),
                'remaining_blockers' => [self::UPSTREAM_NOT_READY_TOKEN],
                'remaining_blocker_count' => 1,
                'all_required_checks_pass' => false,
                // HARD invariant: never allows / is an execution contract.
                'execution_contract_allowed' => false,
                'execution_contract_eligible_for_separate_governed_surface' => false,
                'contract_deferred_reason' => self::CONTRACT_DEFERRED_REASON,
                'boundary' => $this->boundary(),
                'can_release_or_execute_writer_now' => false,
                'human_question' => 'What blocks a future writer release execution contract?',
                'does_not_answer' => 'Can Atlas release or execute the writer now?',
            ];
        }

        $checks = $this->requiredChecks($input);
        $allPass = $checks['unmet_checks'] === [];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => $allPass ? self::STATUS_ELIGIBLE : self::STATUS_BLOCKED,
            'signed_receipt_template_ready' => true,
            'upstream_not_ready_token' => null,
            'required_checks' => $checks,
            'remaining_blockers' => $checks['unmet_checks'],
            'remaining_blocker_count' => count($checks['unmet_checks']),
            'all_required_checks_pass' => $allPass,
            // HARD invariant: this preflight never allows / is an execution contract.
            'execution_contract_allowed' => false,
            // Clearing every check + ready template only makes it ELIGIBLE for a
            // separate governed surface to create/review/gate the contract.
            'execution_contract_eligible_for_separate_governed_surface' => $allPass,
            'contract_deferred_reason' => self::CONTRACT_DEFERRED_REASON,
            'boundary' => $this->boundary(),
            // Doc "Human Meaning": the answer remains no, structurally.
            'can_release_or_execute_writer_now' => false,
            'human_question' => 'What blocks a future writer release execution contract?',
            'does_not_answer' => 'Can Atlas release or execute the writer now?',
        ];
    }

    /**
     * Convenience: the flat list of remaining blockers a future execution
     * contract must clear, derived from the same preflight logic.
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
     *   execution_contract_allowed:false,
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
            // Restated at the top level: composite never allows an execution contract.
            'execution_contract_allowed' => false,
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
            foreach (['execution_contract_allowed', 'can_release_or_execute_writer_now'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }

    /**
     * The required-checks block shape when the upstream gate short-circuits
     * before any check is evaluated.
     *
     * @return array{
     *   surface:string, schema:string,
     *   required_checks:list<string>, count:int,
     *   checks:array<string,bool>, unmet_checks:list<string>,
     *   all_checks_pass:false, evaluated:false, boundary:array<string,false>
     * }
     */
    private function requiredChecksNotEvaluated(): array
    {
        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'required_checks' => array_keys(self::REQUIRED_CHECKS),
            'count' => count(self::REQUIRED_CHECKS),
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
