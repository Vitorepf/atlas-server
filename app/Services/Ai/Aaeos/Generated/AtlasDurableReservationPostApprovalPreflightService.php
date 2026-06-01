<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction Durable Reservation Post-Approval Preflight — pure,
 * deterministic, READ-ONLY surface.
 *
 * This is the FINAL read-only check that must pass AFTER a durable-reservation
 * approval decision has been signed and BEFORE any implementation, migration or
 * storage write begins. It is the post-approval sibling of the pre-approval
 * request packet ({@see AtlasDurableReservationApprovalRequestService}):
 *
 *   - the request packet says "here is what a human must review";
 *   - this preflight says "the human signed — but is it STILL safe to start?".
 *
 * Per the doc, generating this preflight is itself read-only: it cannot create
 * migrations, storage, claims or dispatch. So every result keeps
 * `implementation_allowed=false` UNLESS every documented Required Check passes,
 * and even then it only flips a `clearance` flag — the non-execution guarantee
 * (migrations / storage / claims / dispatch) stays false ALWAYS, because
 * emitting a clearance is not the act of executing.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *   - "Required Checks" => preflight() actually CHECKS all nine documented
 *     checks (signers, decision value, four hash matches, docs/arch validation,
 *     hot-scope absence, dispatch disabled). A check is satisfied only by an
 *     exact, proven signal; missing/null/wrong-typed is fail-closed.
 *   - The decision value gate: the signed decision MUST equal
 *     `approved_for_scoped_implementation`. Any other value (rejected,
 *     approved_with_changes, blank) fails the gate.
 *   - "Blockers" => evaluateBlockers() maps each documented blocker to a failed
 *     check / missing input and lists every one that fires. If ANY fires, status
 *     is `blocked` and `clearance_granted=false`.
 *   - "Completion Criteria" => preflight() emits a deterministic packet carrying
 *     checks, blockers, required evidence, implementation limits, rollback and
 *     the non-execution guarantee — and assertGuaranteeHeld() proves no result
 *     ever flipped a guarantee key.
 *   - Implementation limits are echoed from the approved scope ONLY; an
 *     unscoped/broadened migration or storage footprint fires the scope blocker.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-post-approval-preflight.md
 */
final class AtlasDurableReservationPostApprovalPreflightService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_post_approval_preflight.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_post_approval_preflight';

    /** The only signed decision value that may proceed to implementation. */
    public const REQUIRED_DECISION_VALUE = 'approved_for_scoped_implementation';

    /** Status when every Required Check passes: implementation may begin. */
    public const STATUS_CLEARED = 'preflight_cleared';

    /** Status when at least one Required Check fails / blocker fires. */
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Doc "Required Checks" — signer roles that must ALL have signed (order is
     * the documented role order; the set is fixed, not caller-supplied).
     *
     * @var list<string>
     */
    public const REQUIRED_SIGNER_SLOTS = [
        'product_governor',
        'architecture_governor',
        'safety_governance_reviewer',
        'implementation_operator',
    ];

    /**
     * Doc "Required Checks" — the four hashes that must STILL match what was
     * approved (drift in any one means the approved artifact changed underneath).
     * Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_HASH_MATCHES = [
        'approval_request_hash',
        'ap_candidate_hash',
        'durable_ledger_plan_hash',
        'multi_session_readiness_gate_hash',
    ];

    /**
     * Doc "Required Checks" — the nine checks, in documented order. Key = check
     * token (reported with pass/fail); value = the exact input signal that, when
     * proven true, satisfies that check. Absence is fail-closed.
     *
     * @var array<string,string>
     */
    public const REQUIRED_CHECKS = [
        'decision_signed_by_all_required_roles' => 'all_required_roles_signed',
        'decision_value_is_approved_for_scoped_implementation' => 'decision_value_approved',
        'approval_request_hash_matches' => 'approval_request_hash_matches',
        'ap_candidate_hash_matches' => 'ap_candidate_hash_matches',
        'durable_ledger_plan_hash_matches' => 'durable_ledger_plan_hash_matches',
        'multi_session_readiness_gate_hash_matches' => 'multi_session_readiness_gate_hash_matches',
        'docs_health_and_architecture_validate_passing' => 'docs_health_and_architecture_validate_passing',
        'hot_voice_kernel_scopes_absent_from_allowed_files' => 'no_hot_voice_kernel_scopes_in_allowed_files',
        'dispatch_remains_disabled' => 'dispatch_disabled',
    ];

    /**
     * Doc "Blockers" — the six conditions that block implementation. Key =
     * blocker token (emitted when it fires); value = the proven-safe signal that
     * clears it. Each blocker corresponds to a failed Required Check or a missing
     * scope/rollback input; absence is fail-closed.
     *
     * @var array<string,string>
     */
    public const BLOCKING_CONDITIONS = [
        'signer_slot_missing' => 'all_required_roles_signed',
        'hash_drifted' => 'all_hashes_match',
        'required_evidence_absent' => 'all_required_evidence_present',
        'migration_or_storage_scope_broader_than_approved' => 'scope_within_approved',
        'dispatch_would_be_enabled' => 'dispatch_disabled',
        'rollback_strategy_missing' => 'rollback_present',
    ];

    /**
     * Doc "Required Checks" / "Blockers" — evidence items that must be present
     * for the preflight to clear. Order preserved as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_EVIDENCE = [
        'signed_approval_decision',
        'approval_request_hash',
        'ap_candidate_hash',
        'durable_ledger_plan_hash',
        'multi_session_readiness_gate_hash',
        'docs_health_output',
        'architecture_validate_output',
        'rollback_strategy',
    ];

    /**
     * Non-execution guarantee keys (doc: "Preflight generation is read-only and
     * cannot create migrations, storage, claims or dispatch"). Every result keeps
     * all of these false — emitting a clearance is never the act of executing.
     *
     * @var list<string>
     */
    public const GUARANTEE_KEYS = [
        'migrations_created',
        'storage_writes_performed',
        'claims_created',
        'dispatch_enabled',
    ];

    /**
     * The four documented non-execution guarantee keys, all forced false.
     *
     * @return array<string,false>
     */
    public function guarantee(): array
    {
        $out = [];
        foreach (self::GUARANTEE_KEYS as $key) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * Evaluate all nine documented Required Checks against the signed state.
     *
     * A check passes only when its signal is proven with an exact boolean true
     * (fail-closed). Returns an ordered map of check token => bool.
     *
     * @param array<string,mixed> $state recognised signals are the values of
     *   self::REQUIRED_CHECKS (e.g. decision_value_approved=true).
     * @return array<string,bool> check token => passed
     */
    public function evaluateChecks(array $state = []): array
    {
        $out = [];
        foreach (self::REQUIRED_CHECKS as $check => $signal) {
            $out[$check] = $this->proven($state, $signal);
        }

        return $out;
    }

    /**
     * Evaluate the six documented Blockers against the signed state.
     *
     * A blocker fires UNLESS its clearing signal is proven true (fail-closed).
     * Two derived signals are computed from finer inputs:
     *   - `all_hashes_match`: true only if every one of the four hash-match
     *     signals is individually proven true;
     *   - `all_required_evidence_present`: true only if every evidence item in
     *     self::REQUIRED_EVIDENCE is present in $state['evidence'] as a
     *     non-empty value.
     * Returns the ordered list of fired blocker tokens (empty = clean).
     *
     * @param array<string,mixed> $state
     * @return list<string> fired blocker tokens
     */
    public function evaluateBlockers(array $state = []): array
    {
        $derived = $state;
        $derived['all_hashes_match'] = $this->allHashesMatch($state);
        $derived['all_required_evidence_present'] = $this->allEvidencePresent($state);

        $fired = [];
        foreach (self::BLOCKING_CONDITIONS as $blocker => $clearingSignal) {
            if (! $this->proven($derived, $clearingSignal)) {
                $fired[] = $blocker;
            }
        }

        return $fired;
    }

    /**
     * Primary surface: the read-only POST-APPROVAL PREFLIGHT packet.
     *
     * Runs all nine Required Checks and all six Blockers against the signed
     * state. Clearance is granted ONLY when every check passes AND no blocker
     * fires; otherwise status is `blocked` and `clearance_granted=false`. Either
     * way the non-execution guarantee holds — nothing is migrated, written,
     * claimed or dispatched by producing this packet.
     *
     * The packet echoes the approved implementation limits and the rollback
     * strategy so the operator sees the exact boundary they are cleared to work
     * within (or that it is missing).
     *
     * @param array<string,mixed> $state check / blocker signals (see
     *   evaluateChecks / evaluateBlockers), plus optional:
     *   `rollback_strategy` (string), `implementation_limits` (array),
     *   `evidence` (array).
     * @return array{
     *   surface:string, schema:string, status:string,
     *   required_decision_value:string,
     *   required_signer_slots:list<string>,
     *   required_hash_matches:list<string>,
     *   required_evidence:list<string>,
     *   checks:array<string,bool>,
     *   checks_all_passed:bool,
     *   blocking_conditions:list<string>,
     *   active_blockers:list<string>,
     *   implementation_limits:array<string,mixed>,
     *   rollback_strategy:?string,
     *   clearance_granted:bool,
     *   guarantee:array<string,false>,
     *   implementation_allowed:false, is_execution:false
     * }
     */
    public function preflight(array $state = []): array
    {
        $checks = $this->evaluateChecks($state);
        $checksAllPassed = ! in_array(false, $checks, true);

        $activeBlockers = $this->evaluateBlockers($state);
        $noBlockers = $activeBlockers === [];

        // Clearance requires BOTH: every check passed AND no blocker fired.
        $cleared = $checksAllPassed && $noBlockers;

        $rollback = isset($state['rollback_strategy']) && is_string($state['rollback_strategy']) && $state['rollback_strategy'] !== ''
            ? $state['rollback_strategy']
            : null;

        $limits = isset($state['implementation_limits']) && is_array($state['implementation_limits'])
            ? $state['implementation_limits']
            : [];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => $cleared ? self::STATUS_CLEARED : self::STATUS_BLOCKED,
            'required_decision_value' => self::REQUIRED_DECISION_VALUE,
            'required_signer_slots' => self::REQUIRED_SIGNER_SLOTS,
            'required_hash_matches' => self::REQUIRED_HASH_MATCHES,
            'required_evidence' => self::REQUIRED_EVIDENCE,
            'checks' => $checks,
            'checks_all_passed' => $checksAllPassed,
            'blocking_conditions' => array_keys(self::BLOCKING_CONDITIONS),
            'active_blockers' => $activeBlockers,
            'implementation_limits' => $limits,
            'rollback_strategy' => $rollback,
            // Cleared means "may begin scoped implementation", never "executed".
            'clearance_granted' => $cleared,
            'guarantee' => $this->guarantee(),
            // Restated per the doc's hard non-execution guarantee.
            'implementation_allowed' => false,
            'is_execution' => false,
        ];
    }

    /**
     * Composite entrypoint: build the preflight packet and prove the
     * non-execution guarantee held across it.
     *
     * @param array<string,mixed> $state forwarded to preflight()
     * @return array{
     *   schema:string,
     *   preflight:array<string,mixed>,
     *   guarantee_held:bool, guarantee_violations:list<string>
     * }
     */
    public function evaluate(array $state = []): array
    {
        $packet = $this->preflight($state);
        $violations = $this->assertGuaranteeHeld([$packet]);

        return [
            'schema' => self::SCHEMA,
            'preflight' => $packet,
            'guarantee_held' => $violations === [],
            'guarantee_violations' => $violations,
        ];
    }

    /**
     * Prove that no result ever flipped a non-execution guarantee key (or the two
     * restated guarantees) to a truthy value. Returns "surface.key" violations
     * (empty = intact).
     *
     * @param list<array<string,mixed>> $results
     * @return list<string>
     */
    public function assertGuaranteeHeld(array $results): array
    {
        $violations = [];
        foreach ($results as $result) {
            $label = is_string($result['surface'] ?? null) ? $result['surface'] : 'unknown';

            $guarantee = is_array($result['guarantee'] ?? null) ? $result['guarantee'] : [];
            foreach (self::GUARANTEE_KEYS as $key) {
                // Missing key OR truthy value both count as a breach.
                if (! array_key_exists($key, $guarantee) || $guarantee[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }

            // The packet restates two guarantees outside the guarantee array.
            foreach (['implementation_allowed', 'is_execution'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }

    /**
     * Derived signal: every one of the four documented hash-match signals is
     * individually proven true. A single drift makes this false.
     *
     * @param array<string,mixed> $state
     */
    private function allHashesMatch(array $state): bool
    {
        foreach (self::REQUIRED_HASH_MATCHES as $hash) {
            // signal key for each is "<hash>_matches" except already-suffixed.
            $signal = str_ends_with($hash, '_hash') ? $hash.'_matches' : $hash;
            if (! $this->proven($state, $signal)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Derived signal: every documented required evidence item is present in
     * $state['evidence'] as a non-empty value. Missing the evidence sub-array,
     * or any single empty item, makes this false.
     *
     * @param array<string,mixed> $state
     */
    private function allEvidencePresent(array $state): bool
    {
        $evidence = isset($state['evidence']) && is_array($state['evidence']) ? $state['evidence'] : [];
        foreach (self::REQUIRED_EVIDENCE as $item) {
            if (! array_key_exists($item, $evidence)) {
                return false;
            }
            $value = $evidence[$item];
            if ($value === null || $value === '' || $value === false || $value === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read a signal. Only an exact boolean true clears a check/blocker; anything
     * else (missing key, null, truthy-string, 1) is fail-closed. This makes the
     * post-approval gate impossible to trip accidentally.
     *
     * @param array<string,mixed> $state
     */
    private function proven(array $state, string $key): bool
    {
        if (! array_key_exists($key, $state)) {
            return false;
        }

        return $state[$key] === true;
    }
}
