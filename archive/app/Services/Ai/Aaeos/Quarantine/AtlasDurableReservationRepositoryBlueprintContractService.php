<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\Generated\Concerns\AssertGuaranteeHeld;

/**
 * Atlas Self-Construction Durable Reservation Repository Blueprint Contract —
 * pure, deterministic, READ-ONLY surface.
 *
 * This is the future repository decider that future runtime can convert into
 * actual PHP services and tests "without guessing class names, methods, errors
 * or transaction boundaries" (Completion Criteria). It is the repository sibling
 * of {@see AtlasDurableReservationLeaseLifecycleBlueprintContractService} (which
 * governs lease state transitions) and of
 * {@see AtlasDurableReservationCollisionGuardContractService} (which decides if
 * one candidate may be CLAIMED). Here we govern the SHAPE of the repository that
 * persists reservations: its classes, DTOs, errors, required methods and the
 * transaction/write-ordering invariants.
 *
 * Per the doc frontmatter decisions, generating this blueprint is itself
 * read-only and "cannot create PHP runtime files, write storage, persist claims
 * or dispatch work". Every result therefore keeps the four non-execution
 * guarantee keys false — emitting the blueprint is never the act of building it.
 *
 * Documented rules this code ENFORCES (not merely echoes):
 *   - "Future Classes" => the seven canonical classes (repository, collision
 *     guard, event hasher, projection builder, two DTOs, one exception), with
 *     their exact fully-qualified names.
 *   - "Required Repository Methods" => the nine method contracts with their
 *     documented signatures, exposed as data.
 *   - "Transaction Rules" (enforced, not described):
 *       * claim, renew, release, expire and complete run inside a transaction —
 *         requiresTransaction() returns true for exactly those, false for the
 *         read-only methods (preview, current, activeCollisions, rebuildProjection);
 *       * claim must lock packet scope BEFORE appending the event —
 *         requiresPacketLock() is true only for claim;
 *       * the event hash must include previous-hash + actor + packet-hash +
 *         payload — eventHashInputs() returns exactly those four components and
 *         validateEventHashInputs() fail-closes if any is missing;
 *       * "writes must append events BEFORE updating projections and must never
 *         bypass the collision guard" + "Projection updates only happen after
 *         accepted events" => validateWriteOrder() admits a write step ONLY when
 *         (a) the collision guard ran for a guarded write, (b) the event was
 *         accepted and appended before the projection update, and (c) the
 *         projection update is not attempted on a rejected event;
 *       * "Completion requires packet completion gate evidence" =>
 *         validateWriteOrder() blocks a complete step with no completion-gate
 *         evidence;
 *       * "Repository methods never dispatch work" => any write step that flags
 *         work dispatch is rejected.
 *   - Completion Criteria => a deterministic read-only repository blueprint plus
 *     assertGuaranteeHeld(), which proves no result flipped a guarantee key.
 *
 * The decider is fail-CLOSED: a structurally legal write step that violates a
 * transaction/ordering/gate rule is rejected (verdict=reject) with explicit
 * reasons rather than silently allowed.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-repository-blueprint-contract.md
 */
final class AtlasDurableReservationRepositoryBlueprintContractService
{
    use AssertGuaranteeHeld;

    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_repository_blueprint_contract.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_repository_blueprint_contract';

    /** Decision verdicts. */
    public const VERDICT_ALLOW = 'allow';
    public const VERDICT_REJECT = 'reject';

    // --- Doc "Future Classes" (exact fully-qualified names) ------------------
    private const NS = 'App\\Services\\Ai\\SelfConstruction\\Reservations';

    /**
     * The seven canonical future classes, role => FQCN, in documented order.
     *
     * @var array<string,string>
     */
    public const FUTURE_CLASSES = [
        'repository' => self::NS.'\\DurableReservationRepository',
        'collision_guard' => self::NS.'\\DurableReservationCollisionGuard',
        'event_hasher' => self::NS.'\\ReservationEventHasher',
        'projection_builder' => self::NS.'\\ReservationProjectionBuilder',
        'claim_request_dto' => self::NS.'\\Data\\ReservationClaimRequest',
        'claim_result_dto' => self::NS.'\\Data\\ReservationClaimResult',
        'rejected_exception' => self::NS.'\\Exceptions\\ReservationRejectedException',
    ];

    // --- Doc "Required Repository Methods" -----------------------------------
    public const METHOD_PREVIEW = 'preview';
    public const METHOD_CLAIM = 'claim';
    public const METHOD_RENEW = 'renew';
    public const METHOD_RELEASE = 'release';
    public const METHOD_EXPIRE = 'expire';
    public const METHOD_COMPLETE = 'complete';
    public const METHOD_CURRENT = 'current';
    public const METHOD_ACTIVE_COLLISIONS = 'activeCollisions';
    public const METHOD_REBUILD_PROJECTION = 'rebuildProjection';

    /**
     * The nine required methods, name => documented signature, in doc order.
     *
     * @var array<string,string>
     */
    public const REQUIRED_METHODS = [
        self::METHOD_PREVIEW => 'preview(ReservationClaimRequest $request): ReservationClaimResult',
        self::METHOD_CLAIM => 'claim(ReservationClaimRequest $request): ReservationClaimResult',
        self::METHOD_RENEW => 'renew(string $reservationId, string $actorId, DateTimeInterface $leaseExpiresAt): ReservationClaimResult',
        self::METHOD_RELEASE => 'release(string $reservationId, string $actorId, string $reason): ReservationClaimResult',
        self::METHOD_EXPIRE => 'expire(DateTimeInterface $now): int',
        self::METHOD_COMPLETE => 'complete(string $reservationId, string $actorId, array $evidence): ReservationClaimResult',
        self::METHOD_CURRENT => 'current(string $packetId): ?ReservationClaimResult',
        self::METHOD_ACTIVE_COLLISIONS => 'activeCollisions(array $allowedFiles): array',
        self::METHOD_REBUILD_PROJECTION => 'rebuildProjection(string $reservationId): ReservationClaimResult',
    ];

    /**
     * Transaction Rule 1: "Claim, renew, release, expire and complete run inside
     * database transactions." These five (and only these) require a transaction;
     * the read-only methods do not.
     *
     * @var list<string>
     */
    public const TRANSACTIONAL_METHODS = [
        self::METHOD_CLAIM,
        self::METHOD_RENEW,
        self::METHOD_RELEASE,
        self::METHOD_EXPIRE,
        self::METHOD_COMPLETE,
    ];

    /**
     * Read-only methods: no transaction, no event append, no projection write.
     *
     * @var list<string>
     */
    public const READ_ONLY_METHODS = [
        self::METHOD_PREVIEW,
        self::METHOD_CURRENT,
        self::METHOD_ACTIVE_COLLISIONS,
        self::METHOD_REBUILD_PROJECTION,
    ];

    /**
     * Transaction Rule 3: "Event hash must include previous event hash, actor,
     * packet hash and payload." The four mandatory hash-input components.
     *
     * @var list<string>
     */
    public const EVENT_HASH_INPUTS = [
        'previous_event_hash',
        'actor',
        'packet_hash',
        'payload',
    ];

    /** Reject reasons (closed set) used when a write step is rejected. */
    public const REASON_UNKNOWN_METHOD = 'unknown_method';
    public const REASON_COLLISION_GUARD_BYPASSED = 'collision_guard_bypassed';
    public const REASON_PROJECTION_BEFORE_EVENT = 'projection_updated_before_event_appended';
    public const REASON_PROJECTION_ON_REJECTED_EVENT = 'projection_updated_on_rejected_event';
    public const REASON_MISSING_TRANSACTION = 'transaction_not_opened';
    public const REASON_PACKET_NOT_LOCKED = 'packet_scope_not_locked_before_event';
    public const REASON_MISSING_COMPLETION_GATE = 'completion_gate_evidence_missing';
    public const REASON_WORK_DISPATCHED = 'repository_method_dispatched_work';
    public const REASON_MISSING_HASH_INPUT = 'event_hash_input_missing';

    /**
     * Non-execution guarantee keys (doc decisions: blueprint generation is
     * read-only and cannot create runtime files, write storage, persist claims
     * or dispatch work). Every result keeps all of these false.
     *
     * @var list<string>
     */
    public const GUARANTEE_KEYS = [
        'runtime_files_created',
        'storage_writes_performed',
        'claims_persisted',
        'work_dispatched',
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
     * Transaction Rule 1: does this method run inside a database transaction?
     * True for claim/renew/release/expire/complete, false for everything else
     * (including the four read-only methods and any unknown method).
     */
    public function requiresTransaction(string $method): bool
    {
        return in_array($method, self::TRANSACTIONAL_METHODS, true);
    }

    /**
     * Transaction Rule 2: "Claim must lock packet scope before appending the
     * event." Only claim acquires the packet-scope lock.
     */
    public function requiresPacketLock(string $method): bool
    {
        return $method === self::METHOD_CLAIM;
    }

    /**
     * Whether the method appends events / mutates the projection at all (the
     * write methods). The read-only methods never do.
     */
    public function isWriteMethod(string $method): bool
    {
        return in_array($method, self::TRANSACTIONAL_METHODS, true);
    }

    /**
     * Transaction Rule 3: the four mandatory event-hash input components.
     *
     * @return list<string>
     */
    public function eventHashInputs(): array
    {
        return self::EVENT_HASH_INPUTS;
    }

    /**
     * Transaction Rule 3 enforcement: an event hash is valid only when ALL four
     * documented components (previous_event_hash, actor, packet_hash, payload)
     * are present and non-empty. Returns the missing components (empty = valid).
     *
     * @param array<string,mixed> $components
     * @return list<string>
     */
    public function validateEventHashInputs(array $components): array
    {
        $missing = [];
        foreach (self::EVENT_HASH_INPUTS as $key) {
            if (! array_key_exists($key, $components) || $components[$key] === null || $components[$key] === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Primary surface: validate ONE repository write step against the documented
     * Transaction Rules and the write-ordering invariant
     * ("append events before updating projections and must never bypass the
     * collision guard"; "Projection updates only happen after accepted events";
     * "Completion requires packet completion gate evidence"; "Repository methods
     * never dispatch work").
     *
     * A read-only method that performs no write is trivially allowed. A write
     * step is allowed only when every ordering/transaction/gate rule holds; any
     * violation is fail-closed to verdict=reject with explicit reasons. No claim,
     * storage write, migration or dispatch is produced — this only DECIDES.
     *
     * @param array{
     *   method?:string,
     *   transaction_opened?:bool,
     *   collision_guard_ran?:bool,
     *   event_accepted?:bool,
     *   event_appended?:bool,
     *   projection_updated?:bool,
     *   packet_scope_locked?:bool,
     *   completion_gate_evidence?:bool,
     *   work_dispatched?:bool
     * } $step
     * @return array{
     *   surface:string, schema:string,
     *   method:?string,
     *   verdict:string,
     *   requires_transaction:bool,
     *   requires_packet_lock:bool,
     *   reasons:list<string>,
     *   guarantee:array<string,false>,
     *   claim_persisted:false, is_execution:false
     * }
     */
    public function validateWriteOrder(array $step = []): array
    {
        $method = is_string($step['method'] ?? null) ? $step['method'] : null;

        // Unknown method => reject (cannot reason about its ordering).
        if ($method === null || ! array_key_exists($method, self::REQUIRED_METHODS)) {
            return $this->result($method, self::VERDICT_REJECT, false, false, [self::REASON_UNKNOWN_METHOD]);
        }

        $requiresTx = $this->requiresTransaction($method);
        $requiresLock = $this->requiresPacketLock($method);

        // A read-only method that does not write is fine by definition.
        if (! $this->isWriteMethod($method)) {
            return $this->result($method, self::VERDICT_ALLOW, $requiresTx, $requiresLock, []);
        }

        $reasons = [];

        $txOpened = ($step['transaction_opened'] ?? false) === true;
        $guardRan = ($step['collision_guard_ran'] ?? false) === true;
        $eventAccepted = ($step['event_accepted'] ?? false) === true;
        $eventAppended = ($step['event_appended'] ?? false) === true;
        $projectionUpdated = ($step['projection_updated'] ?? false) === true;
        $scopeLocked = ($step['packet_scope_locked'] ?? false) === true;
        $gateEvidence = ($step['completion_gate_evidence'] ?? false) === true;
        $dispatched = ($step['work_dispatched'] ?? false) === true;

        // Transaction Rule 1: write methods must run inside a transaction.
        if (! $txOpened) {
            $reasons[] = self::REASON_MISSING_TRANSACTION;
        }

        // Transaction Rule 2: claim must lock packet scope before appending.
        if ($requiresLock && ! $scopeLocked) {
            $reasons[] = self::REASON_PACKET_NOT_LOCKED;
        }

        // "must never bypass the collision guard" — the guard must have run for
        // any write that mutates reservation scope.
        if (! $guardRan) {
            $reasons[] = self::REASON_COLLISION_GUARD_BYPASSED;
        }

        // "Projection updates only happen after accepted events." If the
        // projection was updated, the event must have been accepted AND appended
        // first.
        if ($projectionUpdated && ! $eventAccepted) {
            $reasons[] = self::REASON_PROJECTION_ON_REJECTED_EVENT;
        }
        if ($projectionUpdated && ! $eventAppended) {
            $reasons[] = self::REASON_PROJECTION_BEFORE_EVENT;
        }

        // "Completion requires packet completion gate evidence."
        if ($method === self::METHOD_COMPLETE && ! $gateEvidence) {
            $reasons[] = self::REASON_MISSING_COMPLETION_GATE;
        }

        // "Repository methods never dispatch work."
        if ($dispatched) {
            $reasons[] = self::REASON_WORK_DISPATCHED;
        }

        $verdict = $reasons === [] ? self::VERDICT_ALLOW : self::VERDICT_REJECT;

        return $this->result($method, $verdict, $requiresTx, $requiresLock, $reasons);
    }

    /**
     * The read-only repository BLUEPRINT: the full class/method/error/transaction
     * specification future runtime converts into PHP — emitted as data so it is
     * checkable and never guessed.
     *
     * @return array{
     *   surface:string, schema:string,
     *   future_classes:array<string,string>,
     *   required_methods:array<string,string>,
     *   transactional_methods:list<string>,
     *   read_only_methods:list<string>,
     *   packet_lock_methods:list<string>,
     *   event_hash_inputs:list<string>,
     *   transaction_rules:list<string>,
     *   required_tests:list<string>,
     *   guarantee:array<string,false>,
     *   claim_persisted:false, is_execution:false
     * }
     */
    public function blueprint(): array
    {
        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'future_classes' => self::FUTURE_CLASSES,
            'required_methods' => self::REQUIRED_METHODS,
            'transactional_methods' => self::TRANSACTIONAL_METHODS,
            'read_only_methods' => self::READ_ONLY_METHODS,
            'packet_lock_methods' => [self::METHOD_CLAIM],
            'event_hash_inputs' => self::EVENT_HASH_INPUTS,
            'transaction_rules' => [
                'claim_renew_release_expire_complete_run_inside_transactions',
                'claim_locks_packet_scope_before_appending_event',
                'event_hash_includes_previous_hash_actor_packet_hash_and_payload',
                'projection_updates_only_after_accepted_events',
                'completion_requires_packet_completion_gate_evidence',
                'repository_methods_never_dispatch_work',
            ],
            'required_tests' => [
                'preview_reports_rejection_without_writing_events',
                'claim_appends_event_and_updates_projection_atomically',
                'duplicate_active_packet_claim_is_rejected',
                'overlapping_active_file_scope_is_rejected',
                'stale_packet_hash_is_rejected',
                'non_owner_cannot_renew_release_or_complete',
                'event_chain_mismatch_blocks_projection_update',
                'repository_blueprint_command_does_not_create_php_files_or_write_storage',
            ],
            'guarantee' => $this->guarantee(),
            'claim_persisted' => false,
            'is_execution' => false,
        ];
    }

    /**
     * Assemble a uniform decision result.
     *
     * @param list<string> $reasons
     * @return array{
     *   surface:string, schema:string,
     *   method:?string,
     *   verdict:string,
     *   requires_transaction:bool,
     *   requires_packet_lock:bool,
     *   reasons:list<string>,
     *   guarantee:array<string,false>,
     *   claim_persisted:false, is_execution:false
     * }
     */
    private function result(
        ?string $method,
        string $verdict,
        bool $requiresTransaction,
        bool $requiresPacketLock,
        array $reasons,
    ): array {
        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'method' => $method,
            'verdict' => $verdict,
            'requires_transaction' => $requiresTransaction,
            'requires_packet_lock' => $requiresPacketLock,
            'reasons' => $reasons,
            'guarantee' => $this->guarantee(),
            // Restated per the doc's hard non-execution guarantee.
            'claim_persisted' => false,
            'is_execution' => false,
        ];
    }
}
