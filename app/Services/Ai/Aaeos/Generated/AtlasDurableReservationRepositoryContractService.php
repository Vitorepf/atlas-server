<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction Durable Reservation Repository Contract —
 * pure, deterministic, READ-ONLY enforcement surface.
 *
 * Where {@see AtlasDurableReservationRepositoryBlueprintContractService} fixes the
 * SHAPE of the future repository (its class names, method signatures and
 * write-ORDERING invariants), and where AtlasSelfConstructionReadinessService just
 * EMITS the contract envelope as static data, this decider ENFORCES the actual
 * decision contract of the doc: given a candidate claim/release/complete attempt
 * and the current projection, it classifies the attempt against the nine
 * documented "Required Errors" and returns accept|reject with the exact error
 * codes — it does not merely echo them.
 *
 * Documented rules this code ENFORCES (not merely echoes):
 *   - "Required Methods" (8): claim, renew, release, expire, complete, current,
 *     activeCollisions, rebuildProjection — exposed as data; the write methods
 *     (claim/renew/release/expire/complete) are separated from the read methods.
 *   - "Required Errors" (9): decideClaim() fires packet_already_claimed,
 *     packet_already_completed, allowed_files_overlap_active_reservation,
 *     hot_scope_forbidden and packet_hash_stale; decideRelease()/decideComplete()
 *     fire actor_not_owner and lease_expired; decideComplete() fires
 *     completion_gate_missing; validateEventChain() fires event_chain_mismatch.
 *   - Transaction Rules:
 *       * "Acquire packet and allowed-file-scope lock before claim" — only claim
 *         acquires the packet+scope lock (requiresPacketScopeLock()).
 *       * "Append event before projection update" — methodWritesEvents() marks the
 *         five write methods, and decideProjectionUpdate() rejects a projection
 *         write whose preceding event was not appended.
 *       * "Reject projection update when event hash chain is broken" —
 *         validateEventChain() walks previous_event_hash links and fails closed
 *         (event_chain_mismatch) on the first break.
 *       * "Treat release and completion as terminal for the current lease" —
 *         decideRelease()/decideComplete() reject a second action on a state that
 *         is already released or completed.
 *       * "Never enable dispatch from repository methods" — every result carries
 *         dispatch_enabled=false and assertNoDispatch() proves no result flipped
 *         it.
 *   - Hot-scope ban: a claim that touches a documented hot Voice/Kernel scope is
 *     rejected (hot_scope_forbidden), since claims "must not mutate hot
 *     Voice/Kernel scopes".
 *
 * The decider is fail-CLOSED: a structurally legal attempt that violates any
 * documented rule is rejected with explicit error codes rather than allowed.
 * It produces no claim, no event, no storage write and no dispatch — it DECIDES.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-repository-contract.md
 */
final class AtlasDurableReservationRepositoryContractService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_repository_contract.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_repository_contract';

    /** Decision verdicts. */
    public const VERDICT_ACCEPT = 'accept';
    public const VERDICT_REJECT = 'reject';

    // --- Doc "Required Methods" (8, exact doc order) -------------------------
    public const METHOD_CLAIM = 'claim';
    public const METHOD_RENEW = 'renew';
    public const METHOD_RELEASE = 'release';
    public const METHOD_EXPIRE = 'expire';
    public const METHOD_COMPLETE = 'complete';
    public const METHOD_CURRENT = 'current';
    public const METHOD_ACTIVE_COLLISIONS = 'activeCollisions';
    public const METHOD_REBUILD_PROJECTION = 'rebuildProjection';

    /**
     * The eight required methods, name => documented signature, in doc order.
     *
     * @var array<string,string>
     */
    public const REQUIRED_METHODS = [
        self::METHOD_CLAIM => 'claim(packet, actor, scope, lease)',
        self::METHOD_RENEW => 'renew(reservation, actor, lease)',
        self::METHOD_RELEASE => 'release(reservation, actor, reason)',
        self::METHOD_EXPIRE => 'expire(now)',
        self::METHOD_COMPLETE => 'complete(reservation, actor, evidence)',
        self::METHOD_CURRENT => 'current(packet)',
        self::METHOD_ACTIVE_COLLISIONS => 'activeCollisions(scope)',
        self::METHOD_REBUILD_PROJECTION => 'rebuildProjection(reservation)',
    ];

    /**
     * The write methods — they append an event and update the projection.
     * Read methods (current, activeCollisions, rebuildProjection) never append.
     *
     * @var list<string>
     */
    public const WRITE_METHODS = [
        self::METHOD_CLAIM,
        self::METHOD_RENEW,
        self::METHOD_RELEASE,
        self::METHOD_EXPIRE,
        self::METHOD_COMPLETE,
    ];

    // --- Doc "Required Errors" (9, exact closed set) ------------------------
    public const ERR_PACKET_ALREADY_CLAIMED = 'packet_already_claimed';
    public const ERR_PACKET_ALREADY_COMPLETED = 'packet_already_completed';
    public const ERR_ALLOWED_FILES_OVERLAP = 'allowed_files_overlap_active_reservation';
    public const ERR_HOT_SCOPE_FORBIDDEN = 'hot_scope_forbidden';
    public const ERR_PACKET_HASH_STALE = 'packet_hash_stale';
    public const ERR_LEASE_EXPIRED = 'lease_expired';
    public const ERR_COMPLETION_GATE_MISSING = 'completion_gate_missing';
    public const ERR_ACTOR_NOT_OWNER = 'actor_not_owner';
    public const ERR_EVENT_CHAIN_MISMATCH = 'event_chain_mismatch';

    /**
     * The nine documented required error states, in doc order.
     *
     * @var list<string>
     */
    public const REQUIRED_ERRORS = [
        self::ERR_PACKET_ALREADY_CLAIMED,
        self::ERR_PACKET_ALREADY_COMPLETED,
        self::ERR_ALLOWED_FILES_OVERLAP,
        self::ERR_HOT_SCOPE_FORBIDDEN,
        self::ERR_PACKET_HASH_STALE,
        self::ERR_LEASE_EXPIRED,
        self::ERR_COMPLETION_GATE_MISSING,
        self::ERR_ACTOR_NOT_OWNER,
        self::ERR_EVENT_CHAIN_MISMATCH,
    ];

    /**
     * Active reservation states (a claim that holds the packet). Anything else
     * (released, completed, expired) is terminal and frees the packet.
     *
     * @var list<string>
     */
    public const ACTIVE_STATES = ['claimed', 'renewed'];

    /**
     * Documented hot Voice/Kernel scopes a claim may never touch (claims "must
     * not mutate hot Voice/Kernel scopes"). Prefix match for the directory globs.
     *
     * @var list<string>
     */
    public const HOT_SCOPE_PREFIXES = [
        'runtimes/python/voice_realtime/',
        'app/Services/Ai/Voice/',
    ];

    /**
     * Exact hot files that are also forbidden as claim scope.
     *
     * @var list<string>
     */
    public const HOT_SCOPE_FILES = [
        'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php',
        'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
    ];

    /**
     * The eight documented required tests, as machine ids (the contract is
     * complete when these are pinned). Emitted by contract() as data.
     *
     * @var list<string>
     */
    public const REQUIRED_TESTS = [
        'claim_writes_claim_event_then_projection',
        'duplicate_active_claim_is_rejected',
        'overlapping_allowed_files_are_rejected',
        'non_owner_cannot_release_or_complete',
        'expired_lease_cannot_complete',
        'stale_packet_hash_is_rejected',
        'projection_rebuild_matches_current_projection',
        'repository_contract_command_does_not_persist_claims_or_write_storage',
    ];

    /**
     * Does this method append an event (and therefore update the projection)?
     * True for claim/renew/release/expire/complete; false for the read methods
     * and any unknown method.
     */
    public function methodWritesEvents(string $method): bool
    {
        return in_array($method, self::WRITE_METHODS, true);
    }

    /**
     * Transaction Rule: "Acquire packet and allowed-file-scope lock before
     * claim." Only claim acquires the packet + allowed-file-scope lock.
     */
    public function requiresPacketScopeLock(string $method): bool
    {
        return $method === self::METHOD_CLAIM;
    }

    /**
     * Decide ONE claim attempt against the documented Required Errors:
     * packet_already_claimed, packet_already_completed,
     * allowed_files_overlap_active_reservation, hot_scope_forbidden and
     * packet_hash_stale. Fail-closed: all firing errors are returned; an empty
     * error list means the claim is accepted. No event/claim/storage is written.
     *
     * @param array{
     *   packet_id?:string,
     *   allowed_files?:list<string>,
     *   packet_hash?:string
     * } $packet            the candidate packet (allowed_files = its file scope)
     * @param array<string,array{
     *   packet_id?:string,
     *   state?:string,
     *   allowed_files?:list<string>,
     *   packet_hash?:string
     * }> $projection       current projection keyed by packet_id
     * @param string|null $expectedPacketHash  the hash the packet must still match
     *                                         (stale => packet_hash_stale)
     * @return array{
     *   surface:string, schema:string, method:string,
     *   verdict:string, errors:list<string>,
     *   dispatch_enabled:false, claim_persisted:false, is_execution:false
     * }
     */
    public function decideClaim(array $packet, array $projection = [], ?string $expectedPacketHash = null): array
    {
        $errors = [];

        $packetId = (string) ($packet['packet_id'] ?? '');
        $allowedFiles = $this->normalizeFiles($packet['allowed_files'] ?? []);

        // "must not mutate hot Voice/Kernel scopes".
        if ($this->touchesHotScope($allowedFiles)) {
            $errors[] = self::ERR_HOT_SCOPE_FORBIDDEN;
        }

        // packet_hash_stale: the expected hash no longer matches the packet hash.
        $packetHash = $packet['packet_hash'] ?? null;
        if ($expectedPacketHash !== null && $packetHash !== null && $packetHash !== $expectedPacketHash) {
            $errors[] = self::ERR_PACKET_HASH_STALE;
        }

        // Current projection row for this packet.
        $current = $packetId !== '' ? ($projection[$packetId] ?? null) : null;
        if (is_array($current)) {
            $state = (string) ($current['state'] ?? '');
            if ($state === 'completed') {
                $errors[] = self::ERR_PACKET_ALREADY_COMPLETED;
            } elseif ($this->isActiveState($state)) {
                $errors[] = self::ERR_PACKET_ALREADY_CLAIMED;
            }
        }

        // allowed_files_overlap_active_reservation: any OTHER active reservation
        // whose allowed files intersect this candidate's scope.
        if ($this->overlapsActiveScope($packetId, $allowedFiles, $projection)) {
            $errors[] = self::ERR_ALLOWED_FILES_OVERLAP;
        }

        return $this->decision(self::METHOD_CLAIM, $errors);
    }

    /**
     * Decide a release attempt. Documented rules: "non-owner cannot release"
     * (actor_not_owner) and "release ... terminal for the current lease" (a
     * reservation that is not active can no longer be released). A blank/missing
     * reservation is treated as not-active.
     *
     * @param array{state?:string, actor?:string} $reservation current projection row
     * @return array{
     *   surface:string, schema:string, method:string,
     *   verdict:string, errors:list<string>,
     *   dispatch_enabled:false, claim_persisted:false, is_execution:false
     * }
     */
    public function decideRelease(array $reservation, string $actor): array
    {
        $errors = $this->ownerAndActiveErrors($reservation, $actor, requireLease: false);

        return $this->decision(self::METHOD_RELEASE, $errors);
    }

    /**
     * Decide a completion attempt. Documented rules add to release's checks:
     * "expired lease cannot complete" (lease_expired) and completion may only be
     * recorded "after gates have been run" (completion_gate_missing when no
     * completion-gate evidence is provided). Completion is terminal: a reservation
     * already completed/released cannot complete again (actor_not_owner is checked
     * first; a non-active state yields lease_expired/terminal rejection).
     *
     * @param array{state?:string, actor?:string, lease_expired?:bool} $reservation
     * @return array{
     *   surface:string, schema:string, method:string,
     *   verdict:string, errors:list<string>,
     *   dispatch_enabled:false, claim_persisted:false, is_execution:false
     * }
     */
    public function decideComplete(array $reservation, string $actor, bool $completionGatePassed): array
    {
        $errors = $this->ownerAndActiveErrors($reservation, $actor, requireLease: true);

        // "complete ... only after gates have been run by the session".
        if (! $completionGatePassed) {
            $errors[] = self::ERR_COMPLETION_GATE_MISSING;
        }

        return $this->decision(self::METHOD_COMPLETE, $errors);
    }

    /**
     * Transaction Rule: "Append event before projection update." A projection
     * update is admissible only when its event was appended first. A read method
     * never writes a projection from an event, so passing one is rejected as a
     * misuse. Returns accept (event_appended true for a write method) or reject
     * with event_chain_mismatch when the ordering is violated.
     *
     * @return array{
     *   surface:string, schema:string, method:string,
     *   verdict:string, errors:list<string>,
     *   dispatch_enabled:false, claim_persisted:false, is_execution:false
     * }
     */
    public function decideProjectionUpdate(string $method, bool $eventAppended): array
    {
        $errors = [];

        if (! $this->methodWritesEvents($method)) {
            // A read method cannot drive an event-sourced projection write.
            $errors[] = self::ERR_EVENT_CHAIN_MISMATCH;
        } elseif (! $eventAppended) {
            $errors[] = self::ERR_EVENT_CHAIN_MISMATCH;
        }

        return $this->decision($method, $errors);
    }

    /**
     * Transaction Rule: "Reject projection update when event hash chain is
     * broken." Walks the ordered event list and confirms each event's
     * previous_event_hash equals the prior event's event_hash (the first event's
     * previous link must be null/empty). Fail-closed: the first break returns
     * event_chain_mismatch with the broken index. Empty stream is a valid chain.
     *
     * @param list<array{event_hash?:string, previous_event_hash?:string|null}> $events
     * @return array{
     *   surface:string, schema:string, method:string,
     *   verdict:string, errors:list<string>, broken_at:int|null,
     *   dispatch_enabled:false, claim_persisted:false, is_execution:false
     * }
     */
    public function validateEventChain(array $events): array
    {
        $events = array_values($events);
        $previousHash = null;
        $brokenAt = null;

        foreach ($events as $index => $event) {
            $link = $event['previous_event_hash'] ?? null;
            $link = ($link === '' ) ? null : $link;
            $expected = ($previousHash === '' ) ? null : $previousHash;

            if ($link !== $expected) {
                $brokenAt = $index;
                break;
            }

            $previousHash = $event['event_hash'] ?? null;
        }

        $errors = $brokenAt === null ? [] : [self::ERR_EVENT_CHAIN_MISMATCH];

        $result = $this->decision(self::METHOD_REBUILD_PROJECTION, $errors);
        $result['broken_at'] = $brokenAt;

        return $result;
    }

    /**
     * The read-only repository CONTRACT, emitted as checkable data: the eight
     * required methods, the nine required errors, the transaction rules and the
     * required tests. Nothing here is persisted or dispatched.
     *
     * @return array{
     *   surface:string, schema:string,
     *   required_methods:array<string,string>,
     *   write_methods:list<string>,
     *   required_errors:list<string>,
     *   transaction_rules:list<string>,
     *   required_tests:list<string>,
     *   dispatch_enabled:false, claim_persisted:false, is_execution:false
     * }
     */
    public function contract(): array
    {
        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'required_methods' => self::REQUIRED_METHODS,
            'write_methods' => self::WRITE_METHODS,
            'required_errors' => self::REQUIRED_ERRORS,
            'transaction_rules' => [
                'acquire_packet_and_allowed_file_scope_lock_before_claim',
                'append_event_before_projection_update',
                'reject_projection_update_when_event_hash_chain_is_broken',
                'release_and_completion_are_terminal_for_current_lease',
                'never_enable_dispatch_from_repository_methods',
            ],
            'required_tests' => self::REQUIRED_TESTS,
            'dispatch_enabled' => false,
            'claim_persisted' => false,
            'is_execution' => false,
        ];
    }

    /**
     * Prove that "Never enable dispatch from repository methods" held: no result
     * flipped dispatch_enabled / claim_persisted / is_execution to a truthy
     * value. Returns "surface.key" violations (empty = intact).
     *
     * @param list<array<string,mixed>> $results
     * @return list<string>
     */
    public function assertNoDispatch(array $results): array
    {
        $violations = [];
        foreach ($results as $result) {
            $label = is_string($result['surface'] ?? null) ? $result['surface'] : 'unknown';
            foreach (['dispatch_enabled', 'claim_persisted', 'is_execution'] as $key) {
                if (! array_key_exists($key, $result) || $result[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }
        }

        return $violations;
    }

    /**
     * Shared owner + active-lease classification for release and complete.
     * Order: actor_not_owner is reported first; a non-active (terminal/expired)
     * state yields lease_expired. With requireLease=true an explicit
     * lease_expired flag on an otherwise active row also fires.
     *
     * @param array{state?:string, actor?:string, lease_expired?:bool} $reservation
     * @return list<string>
     */
    private function ownerAndActiveErrors(array $reservation, string $actor, bool $requireLease): array
    {
        $errors = [];

        $owner = (string) ($reservation['actor'] ?? '');
        if ($owner === '' || $owner !== $actor) {
            $errors[] = self::ERR_ACTOR_NOT_OWNER;
        }

        $state = (string) ($reservation['state'] ?? '');
        $active = $this->isActiveState($state);

        if (! $active) {
            // Terminal (released/completed) or unknown: the lease no longer holds.
            $errors[] = self::ERR_LEASE_EXPIRED;
        } elseif ($requireLease && ($reservation['lease_expired'] ?? false) === true) {
            $errors[] = self::ERR_LEASE_EXPIRED;
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param array{state?:string} $reservation
     */
    private function isActiveState(string $state): bool
    {
        return in_array($state, self::ACTIVE_STATES, true);
    }

    /**
     * @param list<string> $files
     */
    private function touchesHotScope(array $files): bool
    {
        foreach ($files as $file) {
            if (in_array($file, self::HOT_SCOPE_FILES, true)) {
                return true;
            }
            foreach (self::HOT_SCOPE_PREFIXES as $prefix) {
                if ($file === rtrim($prefix, '/').'/**' || str_starts_with($file, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<string> $allowedFiles
     * @param array<string,array{state?:string, allowed_files?:list<string>}> $projection
     */
    private function overlapsActiveScope(string $packetId, array $allowedFiles, array $projection): bool
    {
        if ($allowedFiles === []) {
            return false;
        }

        foreach ($projection as $key => $reservation) {
            if ((string) $key === $packetId) {
                continue; // the packet's own row is handled by already_claimed.
            }
            if (! is_array($reservation) || ! $this->isActiveState((string) ($reservation['state'] ?? ''))) {
                continue;
            }

            $other = $this->normalizeFiles($reservation['allowed_files'] ?? []);
            if (array_intersect($allowedFiles, $other) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $files
     * @return list<string>
     */
    private function normalizeFiles(mixed $files): array
    {
        if (! is_array($files)) {
            return [];
        }

        $out = [];
        foreach ($files as $file) {
            if (is_string($file) && $file !== '') {
                $out[] = $file;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Assemble a uniform decision result. dispatch_enabled / claim_persisted /
     * is_execution are always false — repository decisions never dispatch, never
     * persist a claim and are never execution.
     *
     * @param list<string> $errors
     * @return array{
     *   surface:string, schema:string, method:string,
     *   verdict:string, errors:list<string>,
     *   dispatch_enabled:false, claim_persisted:false, is_execution:false
     * }
     */
    private function decision(string $method, array $errors): array
    {
        $errors = array_values(array_unique($errors));

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'method' => $method,
            'verdict' => $errors === [] ? self::VERDICT_ACCEPT : self::VERDICT_REJECT,
            'errors' => $errors,
            'dispatch_enabled' => false,
            'claim_persisted' => false,
            'is_execution' => false,
        ];
    }
}
