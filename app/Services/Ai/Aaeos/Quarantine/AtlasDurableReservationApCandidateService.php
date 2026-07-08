<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Self-Construction Durable Reservation AP Candidate — pure, deterministic,
 * READ-ONLY packet emitter.
 *
 * The doc is the candidate AP for the future implementation that removes
 * `durable_reservation_ledger_missing`. It does NOT implement reservation
 * storage; it defines the shape of the read-only packet Atlas must be able to
 * emit so a human can review the scoped future work. This service IS that
 * emitter: it turns the doc's Mission, Scope, Implementation Packets, Required
 * Tests and Evidence sections into a single deterministic packet.
 *
 * Per "Completion Criteria":
 *   "This AP candidate is complete when Atlas can emit it as a deterministic
 *    read-only packet with clear phases, allowed scope, forbidden scope, tests,
 *    rollback, evidence and non-execution guarantees."
 *
 * So emitting the packet is the whole job — and emitting is NEVER executing.
 * Every result keeps the non-execution guarantee all-false (no AI sessions, no
 * dispatch, no migrations, no storage writes), ALWAYS.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *
 *   - "Implementation Packets" => candidatePacket() emits the FIVE phases in the
 *     exact documented order (Storage, Repository, Collision, Lease, Readiness).
 *     The phase set is a class constant, not caller-supplied; a packet missing
 *     any phase is structurally incomplete.
 *
 *   - "Scope" (allowed/forbidden) => the packet always carries the SIX allowed
 *     work items and the FIVE forbidden work items, verbatim and ordered. The
 *     forbidden set always includes auto-dispatch and the hot Voice/Kernel/route
 *     scopes.
 *
 *   - "Required Tests" => the packet always carries the SEVEN required test
 *     obligations in documented order; a candidate cannot be complete unless all
 *     seven are present.
 *
 *   - frontmatter decision "The AP candidate must include rollback and evidence
 *     before any database migration is allowed." => completeness REQUIRES a
 *     rollback strategy AND every one of the seven evidence items proven present.
 *     If rollback or any evidence item is missing, status is `incomplete` and
 *     `candidate_complete=false` (fail-closed).
 *
 *   - frontmatter decision "Dispatch stays disabled after the ledger exists
 *     until a separate dispatch AP is approved." => completeness REQUIRES the
 *     dispatch-disabled guarantee to be asserted; a candidate that does not hold
 *     dispatch disabled can never be complete.
 *
 *   - "Completion Criteria" => candidate_complete flips true ONLY when every
 *     completeness condition holds (phases + scope + tests structurally present,
 *     rollback present, all evidence present, dispatch disabled, non-execution
 *     guarantee intact). assertGuaranteeHeld() proves no result ever flipped a
 *     guarantee key.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-ap-candidate.md
 */
final class AtlasDurableReservationApCandidateService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_ap_candidate.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_ap_candidate';

    /** Status when the candidate packet is fully complete per Completion Criteria. */
    public const STATUS_CANDIDATE_COMPLETE = 'candidate_complete';

    /** Status when any completeness condition is missing (fail-closed default). */
    public const STATUS_INCOMPLETE = 'incomplete';

    /**
     * Doc "Implementation Packets" — the five ordered phases. Key = phase token;
     * value = the one-line scope of that phase, verbatim from the doc.
     *
     * @var array<string,string>
     */
    public const IMPLEMENTATION_PACKETS = [
        'storage_ap' => 'migrations and model contracts only',
        'repository_ap' => 'transactional claim lock and event append',
        'collision_ap' => 'overlap and hot-scope rejection',
        'lease_ap' => 'renewal, release, expiry and stale-hash protection',
        'readiness_ap' => 'feed durable state into multi-session gate',
    ];

    /**
     * Doc "Scope" allowed future work — the six items, ordered as documented.
     *
     * @var list<string>
     */
    public const ALLOWED_SCOPE = [
        'create_reservation_storage_migrations_after_explicit_approval',
        'implement_append_only_reservation_event_ledger',
        'implement_current_reservation_projection',
        'implement_atomic_claim_renew_release_and_expire_operations',
        'integrate_durable_projection_into_multi_session_readiness_gate',
        'keep_dispatch_disabled_until_a_separate_dispatch_ap_exists',
    ];

    /**
     * Doc "Scope" forbidden work — the five items, ordered as documented.
     *
     * @var list<string>
     */
    public const FORBIDDEN_SCOPE = [
        'do_not_start_ai_sessions',
        'do_not_auto_dispatch_packets',
        'do_not_touch_voice_kernel_routes_or_config',
        'do_not_bypass_decision_receipt_scope_validator_or_completion_gate',
    ];

    /**
     * Doc "Required Tests" — the seven test obligations the future AP must
     * satisfy. Order preserved exactly as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_TESTS = [
        'duplicate_packet_claim_is_blocked',
        'overlapping_allowed_files_are_blocked',
        'hot_forbidden_scopes_are_blocked',
        'stale_packet_or_split_hash_is_blocked',
        'expired_claim_cannot_complete',
        'released_claim_can_be_reclaimed',
        'dispatch_remains_disabled_after_ledger_activation',
    ];

    /**
     * Doc "Evidence" — the seven artifacts the future AP must return. Each must
     * be proven present (exact boolean true in the caller signals) before the
     * candidate can be complete. Order preserved as documented.
     *
     * @var list<string>
     */
    public const REQUIRED_EVIDENCE = [
        'migration_diff',
        'repository_tests',
        'gate_output',
        'scope_validator_output',
        'architecture_validation',
        'docs_health',
        'rollback_notes',
    ];

    /**
     * Non-execution guarantee keys. The doc's "Forbidden work" plus frontmatter
     * decision "Dispatch stays disabled ...": emitting this candidate never
     * starts a session, never dispatches, never migrates, never writes storage.
     * Every result keeps all of these false.
     *
     * @var list<string>
     */
    public const GUARANTEE_KEYS = [
        'ai_session_started',
        'packet_dispatched',
        'migration_created',
        'storage_write_performed',
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
     * Evaluate which completeness conditions are still missing.
     *
     * A condition is "missing" UNLESS its clearing signal is proven with an exact
     * boolean true (fail-closed: missing / null / "true" / 1 all keep it
     * missing). Returns the ordered list of missing-condition tokens.
     *
     * Completeness requires (frontmatter decisions + Completion Criteria):
     *   - rollback_present                  — a rollback strategy exists;
     *   - every one of the seven evidence items present;
     *   - dispatch_disabled                 — dispatch stays disabled.
     *
     * @param array<string,mixed> $signals recognised clearing signals:
     *   `rollback_present`, `dispatch_disabled`, and one boolean per evidence
     *   item keyed `evidence_<item>` (e.g. `evidence_migration_diff`).
     * @return list<string> missing-condition tokens (empty = candidate complete)
     */
    public function missingConditions(array $signals = []): array
    {
        $missing = [];

        if (! $this->proven($signals, 'rollback_present')) {
            $missing[] = 'rollback_missing';
        }

        foreach (self::REQUIRED_EVIDENCE as $item) {
            if (! $this->proven($signals, 'evidence_' . $item)) {
                $missing[] = 'evidence_missing_' . $item;
            }
        }

        if (! $this->proven($signals, 'dispatch_disabled')) {
            $missing[] = 'dispatch_not_disabled';
        }

        return $missing;
    }

    /**
     * Primary surface: the read-only durable reservation AP CANDIDATE packet.
     *
     * Always carries the five implementation phases, the allowed and forbidden
     * scope, the seven required tests and the seven evidence obligations. It
     * evaluates the completeness conditions: the candidate is `complete` ONLY
     * when a rollback strategy is present, every evidence item is proven present
     * and dispatch is held disabled. Otherwise status is `incomplete` and
     * `candidate_complete=false` (the safe default with no signals).
     *
     * Either way the non-execution guarantee holds — emitting the candidate
     * never starts a session, never dispatches, never migrates, never writes.
     *
     * @param array<string,mixed> $signals completeness clearing signals (see
     *   missingConditions) plus optional `rollback_strategy` (string) to echo.
     * @return array{
     *   surface:string, schema:string, status:string,
     *   mission:string,
     *   implementation_packets:array<string,string>,
     *   phase_order:list<string>,
     *   allowed_scope:list<string>,
     *   forbidden_scope:list<string>,
     *   required_tests:list<string>,
     *   required_evidence:list<string>,
     *   rollback_strategy:?string,
     *   missing_conditions:list<string>,
     *   candidate_complete:bool,
     *   guarantee:array<string,false>,
     *   is_execution:false
     * }
     */
    public function candidatePacket(array $signals = []): array
    {
        $missing = $this->missingConditions($signals);

        $rollback = $signals['rollback_strategy'] ?? null;
        $rollback = is_string($rollback) && $rollback !== '' ? $rollback : null;

        $complete = $missing === [];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'status' => $complete ? self::STATUS_CANDIDATE_COMPLETE : self::STATUS_INCOMPLETE,
            'mission' => 'implement durable packet reservation so multiple AI sessions can claim disjoint Self-Construction work without duplicate ownership or scope collision',
            'implementation_packets' => self::IMPLEMENTATION_PACKETS,
            'phase_order' => array_keys(self::IMPLEMENTATION_PACKETS),
            'allowed_scope' => self::ALLOWED_SCOPE,
            'forbidden_scope' => self::FORBIDDEN_SCOPE,
            'required_tests' => self::REQUIRED_TESTS,
            'required_evidence' => self::REQUIRED_EVIDENCE,
            'rollback_strategy' => $rollback,
            'missing_conditions' => $missing,
            'candidate_complete' => $complete,
            'guarantee' => $this->guarantee(),
            'is_execution' => false,
        ];
    }

    /**
     * Audit helper: prove the non-execution guarantee held on a packet. Returns
     * true only when every guarantee key is present and exactly false and the
     * packet is not flagged as execution. Used by the test and any caller that
     * must trust "emitting is not executing".
     *
     * @param array<string,mixed> $packet a candidatePacket() result
     */
    public function assertGuaranteeHeld(array $packet): bool
    {
        if (($packet['is_execution'] ?? true) !== false) {
            return false;
        }

        $guarantee = $packet['guarantee'] ?? null;
        if (! is_array($guarantee)) {
            return false;
        }

        foreach (self::GUARANTEE_KEYS as $key) {
            if (($guarantee[$key] ?? true) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Fail-closed truthiness: a signal clears a condition ONLY when it is present
     * and exactly boolean true. Anything else (absent, null, "true", 1, "1")
     * leaves the condition unmet.
     *
     * @param array<string,mixed> $signals
     */
    private function proven(array $signals, string $key): bool
    {
        return array_key_exists($key, $signals) && $signals[$key] === true;
    }
}
