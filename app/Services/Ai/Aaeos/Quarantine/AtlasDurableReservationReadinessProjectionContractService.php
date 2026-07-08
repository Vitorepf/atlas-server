<?php

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Atlas Self-Construction Durable Reservation Readiness
 * Projection Contract.
 *
 * The doc defines a DETERMINISTIC, READ-ONLY projection that consumes durable
 * reservation state and derives, per packet, a single queue state plus the
 * readiness outputs that future queue / collision / multi-session gates consume.
 * It is explicitly NOT an actor: it may mark a packet `claimed` (because a local
 * lease already exists), but it must never dispatch work, persist a claim or
 * write storage.
 *
 * Load-bearing rules enforced here, straight from the doc (Derived Queue States):
 *
 *   - `available`             : no active reservation, dependencies complete and
 *                               no hot scope.
 *   - `claimed`               : an active local lease exists for the packet.
 *   - `blocked_by_collision`  : the packet's allowed files overlap an active
 *                               claim owned by a different reservation.
 *   - `blocked_by_dependency` : a dependency packet is incomplete.
 *   - `blocked_by_hot_scope`  : the packet touches forbidden hot files.
 *   - `blocked_by_stale_hash` : the packet hash changed after assignment.
 *   - `completed`             : a durable completion exists for the packet.
 *
 * Precedence note: the doc lists `completed` and `claimed` as terminal facts
 * (a durable completion / active lease for THIS packet), while the four
 * `blocked_by_*` states describe why a still-open packet cannot be claimed.
 * Completion is the strongest fact, then an active own-lease (claimed), then a
 * stale hash (the assignment itself is invalid), then hot scope, then a live
 * collision with another owner, then an incomplete dependency. `available` is
 * only reached when none of the blockers apply.
 *
 * Readiness Outputs (doc, Readiness Outputs section):
 *   queue summary, claimable packet ids, blocked packet ids and reasons, active
 *   reservation owners, dependency unlock hints, multi-session decision and a
 *   safe single-session fallback instruction.
 *
 * Multi-session decision (doc, Required Tests): the projection may report
 * `ready_for_multi_session_preview` only when five cold-lane packets are
 * available AND the local durable ledger exists. Otherwise it falls back to a
 * safe single-session instruction, which always remains available when parallel
 * dispatch is blocked.
 *
 * Everything here is PURE and deterministic. No DB, no I/O, no clock.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-readiness-projection-contract.md
 */
final class AtlasDurableReservationReadinessProjectionContractService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.durable_reservation_readiness_projection_contract.v1';

    /** The projection is read-only and never an actor. */
    public const MODE = 'deterministic_read_only_durable_reservation_readiness_projection';

    /** Derived queue states, exactly the seven the doc lists. */
    public const STATE_AVAILABLE = 'available';

    public const STATE_CLAIMED = 'claimed';

    public const STATE_BLOCKED_BY_COLLISION = 'blocked_by_collision';

    public const STATE_BLOCKED_BY_DEPENDENCY = 'blocked_by_dependency';

    public const STATE_BLOCKED_BY_HOT_SCOPE = 'blocked_by_hot_scope';

    public const STATE_BLOCKED_BY_STALE_HASH = 'blocked_by_stale_hash';

    public const STATE_COMPLETED = 'completed';

    public const QUEUE_STATES = [
        self::STATE_AVAILABLE,
        self::STATE_CLAIMED,
        self::STATE_BLOCKED_BY_COLLISION,
        self::STATE_BLOCKED_BY_DEPENDENCY,
        self::STATE_BLOCKED_BY_HOT_SCOPE,
        self::STATE_BLOCKED_BY_STALE_HASH,
        self::STATE_COMPLETED,
    ];

    /** Multi-session decision values. */
    public const MULTI_SESSION_READY = 'ready_for_multi_session_preview';

    public const MULTI_SESSION_FALLBACK = 'single_session_fallback_only';

    /**
     * The doc names five available cold-lane packets (plus a durable ledger) as
     * the threshold for `ready_for_multi_session_preview`.
     */
    public const REQUIRED_AVAILABLE_COLD_LANE_PACKETS = 5;

    /**
     * The safe instruction that is ALWAYS available when parallel dispatch is
     * blocked (doc: "safe single-session fallback instruction").
     */
    public const SINGLE_SESSION_FALLBACK_INSTRUCTION =
        'Continue with a single read-only session: pick one available packet, validate scope, and do not open a second slot or dispatch work.';

    /**
     * Things this projection is forbidden to do. Always echoed so a caller can
     * never read the projection as an action grant.
     */
    public const NON_GOALS = [
        'dispatch_work',
        'enable_autonomous_execution',
        'persist_claims',
        'write_storage',
    ];

    /**
     * Project a set of packets onto durable-reservation queue states and emit the
     * readiness outputs.
     *
     * @param  array<int, array<string,mixed>>  $packets  each: {
     *     id:string,
     *     lane?:string ('cold'|'hot'),
     *     allowed_files?:array<int,string>,
     *     depends_on?:array<int,string>,
     *     assigned_hash?:string|null,    hash captured when the packet was assigned
     *     current_hash?:string|null,     hash of the packet's scope right now
     *     owner?:string|null             reservation owner already claiming THIS packet
     *   }
     * @param  array{
     *     active_reservations?:array<int,array{packet_id?:string,owner?:string,allowed_files?:array<int,string>}>,
     *     completed_packets?:array<int,string>,
     *     hot_forbidden_scopes?:array<int,string>,
     *     reservation_ledger_exists?:bool
     *   }  $state
     * @return array<string,mixed>
     */
    public function project(array $packets, array $state = []): array
    {
        $hotScopes = $this->normalizePaths($state['hot_forbidden_scopes'] ?? []);
        $completed = $this->normalizeIds($state['completed_packets'] ?? []);
        $ledgerExists = (bool) ($state['reservation_ledger_exists'] ?? false);
        $activeReservations = $this->normalizeReservations($state['active_reservations'] ?? []);

        // Map of packet_id => owner for packets with an active lease.
        $claimedOwners = [];
        // List of {owner, files} claims used to detect file collisions.
        $activeClaimFootprints = [];
        foreach ($activeReservations as $reservation) {
            $claimedOwners[$reservation['packet_id']] = $reservation['owner'];
            $activeClaimFootprints[] = $reservation;
        }

        $entries = [];
        foreach ($this->normalizePackets($packets) as $packet) {
            $entries[] = $this->projectPacket(
                $packet,
                $completed,
                $claimedOwners,
                $activeClaimFootprints,
                $hotScopes
            );
        }

        return $this->buildReadiness($entries, $activeReservations, $ledgerExists);
    }

    /**
     * Derive the single queue state for one packet, applying the documented
     * precedence. Returns the per-packet projection entry.
     *
     * @param  array{id:string,lane:string,allowed_files:array<int,string>,depends_on:array<int,string>,assigned_hash:?string,current_hash:?string,owner:?string}  $packet
     * @param  array<int,string>  $completed
     * @param  array<string,string>  $claimedOwners
     * @param  array<int,array{packet_id:string,owner:string,allowed_files:array<int,string>}>  $activeClaims
     * @param  array<int,string>  $hotScopes
     * @return array<string,mixed>
     */
    private function projectPacket(
        array $packet,
        array $completed,
        array $claimedOwners,
        array $activeClaims,
        array $hotScopes
    ): array {
        $id = $packet['id'];
        $reason = null;

        // 1. completed: a durable completion exists (terminal, strongest fact).
        if (in_array($id, $completed, true)) {
            $state = self::STATE_COMPLETED;
        }
        // 2. claimed: an active local lease exists for THIS packet.
        elseif (isset($claimedOwners[$id])) {
            $state = self::STATE_CLAIMED;
        }
        // 3. blocked_by_stale_hash: the packet hash changed after assignment.
        elseif ($this->hashIsStale($packet['assigned_hash'], $packet['current_hash'])) {
            $state = self::STATE_BLOCKED_BY_STALE_HASH;
            $reason = 'packet_hash_changed_after_assignment';
        }
        // 4. blocked_by_hot_scope: the packet touches forbidden hot files.
        elseif ($this->intersects($packet['allowed_files'], $hotScopes)) {
            $state = self::STATE_BLOCKED_BY_HOT_SCOPE;
            $reason = 'packet_touches_forbidden_hot_scope';
        }
        // 5. blocked_by_collision: allowed files overlap an active claim owned by
        //    a DIFFERENT reservation.
        elseif (($collidesWith = $this->collidingOwner($id, $packet['allowed_files'], $activeClaims)) !== null) {
            $state = self::STATE_BLOCKED_BY_COLLISION;
            $reason = 'allowed_files_overlap_active_claim_owned_by:'.$collidesWith;
        }
        // 6. blocked_by_dependency: a dependency packet is incomplete.
        elseif (($pending = $this->firstIncompleteDependency($packet['depends_on'], $completed)) !== null) {
            $state = self::STATE_BLOCKED_BY_DEPENDENCY;
            $reason = 'dependency_incomplete:'.$pending;
        }
        // 7. available: no active reservation, dependencies complete, no hot scope.
        else {
            $state = self::STATE_AVAILABLE;
        }

        return [
            'packet_id' => $id,
            'lane' => $packet['lane'],
            'queue_state' => $state,
            'reason' => $reason,
            'claim_owner' => $claimedOwners[$id] ?? null,
        ];
    }

    /**
     * Assemble the readiness outputs from per-packet entries.
     *
     * @param  array<int,array<string,mixed>>  $entries
     * @param  array<int,array{packet_id:string,owner:string,allowed_files:array<int,string>}>  $activeReservations
     * @return array<string,mixed>
     */
    private function buildReadiness(array $entries, array $activeReservations, bool $ledgerExists): array
    {
        $queueSummary = array_fill_keys(self::QUEUE_STATES, 0);
        $claimable = [];
        $blocked = [];
        $dependencyHints = [];
        $availableColdLane = [];

        foreach ($entries as $entry) {
            $queueSummary[$entry['queue_state']]++;

            if ($entry['queue_state'] === self::STATE_AVAILABLE) {
                $claimable[] = $entry['packet_id'];
                if ($entry['lane'] === 'cold') {
                    $availableColdLane[] = $entry['packet_id'];
                }
            }

            if ($this->isBlocked($entry['queue_state'])) {
                $blocked[] = [
                    'packet_id' => $entry['packet_id'],
                    'queue_state' => $entry['queue_state'],
                    'reason' => $entry['reason'],
                ];
            }

            if ($entry['queue_state'] === self::STATE_BLOCKED_BY_DEPENDENCY) {
                $dependencyHints[] = [
                    'packet_id' => $entry['packet_id'],
                    // Unlock hint: the named pending dependency must complete first.
                    'unlock_when' => $entry['reason'],
                ];
            }
        }

        $owners = [];
        foreach ($activeReservations as $reservation) {
            $owners[] = [
                'packet_id' => $reservation['packet_id'],
                'owner' => $reservation['owner'],
            ];
        }

        $availableColdLaneCount = count($availableColdLane);
        $multiSessionReady = $availableColdLaneCount >= self::REQUIRED_AVAILABLE_COLD_LANE_PACKETS
            && $ledgerExists;

        $multiSessionDecision = $multiSessionReady
            ? self::MULTI_SESSION_READY
            : self::MULTI_SESSION_FALLBACK;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'non_goals' => self::NON_GOALS,
            // Read-only guarantees: the projection never acts.
            'dispatch_allowed' => false,
            'claim_persisted' => false,
            'storage_write_allowed' => false,
            'queue_summary' => $queueSummary,
            'claimable_packet_ids' => $claimable,
            'blocked_packets' => $blocked,
            'active_reservation_owners' => $owners,
            'dependency_unlock_hints' => $dependencyHints,
            'inputs' => [
                'packet_count' => count($entries),
                'available_cold_lane_packet_count' => $availableColdLaneCount,
                'reservation_ledger_exists' => $ledgerExists,
            ],
            'multi_session_decision' => $multiSessionDecision,
            // The single-session fallback is ALWAYS available when parallel
            // dispatch is not ready (doc requirement).
            'single_session_fallback_available' => true,
            'safe_single_session_fallback_instruction' => self::SINGLE_SESSION_FALLBACK_INSTRUCTION,
            'projection' => $entries,
        ];
    }

    private function isBlocked(string $state): bool
    {
        return in_array($state, [
            self::STATE_BLOCKED_BY_COLLISION,
            self::STATE_BLOCKED_BY_DEPENDENCY,
            self::STATE_BLOCKED_BY_HOT_SCOPE,
            self::STATE_BLOCKED_BY_STALE_HASH,
        ], true);
    }

    /**
     * A hash is stale only when BOTH hashes are known and they differ. An unknown
     * (null/empty) assigned or current hash cannot prove staleness, so it does
     * not block.
     */
    private function hashIsStale(?string $assigned, ?string $current): bool
    {
        if ($assigned === null || $assigned === '' || $current === null || $current === '') {
            return false;
        }

        return $assigned !== $current;
    }

    /**
     * Return the owner of an active claim whose allowed files overlap this
     * packet's allowed files, ignoring a claim owned by the packet itself.
     *
     * @param  array<int,string>  $allowedFiles
     * @param  array<int,array{packet_id:string,owner:string,allowed_files:array<int,string>}>  $activeClaims
     */
    private function collidingOwner(string $packetId, array $allowedFiles, array $activeClaims): ?string
    {
        foreach ($activeClaims as $claim) {
            if ($claim['packet_id'] === $packetId) {
                continue;
            }

            if ($this->intersects($allowedFiles, $claim['allowed_files'])) {
                return $claim['owner'];
            }
        }

        return null;
    }

    /**
     * Return the first dependency id that is not in the completed set, or null
     * when every dependency is complete.
     *
     * @param  array<int,string>  $dependsOn
     * @param  array<int,string>  $completed
     */
    private function firstIncompleteDependency(array $dependsOn, array $completed): ?string
    {
        foreach ($dependsOn as $dependency) {
            if (! in_array($dependency, $completed, true)) {
                return $dependency;
            }
        }

        return null;
    }

    /**
     * @param  array<int,string>  $a
     * @param  array<int,string>  $b
     */
    private function intersects(array $a, array $b): bool
    {
        return array_intersect($a, $b) !== [];
    }

    /**
     * @param  array<int,array<string,mixed>>  $packets
     * @return array<int,array{id:string,lane:string,allowed_files:array<int,string>,depends_on:array<int,string>,assigned_hash:?string,current_hash:?string,owner:?string}>
     */
    private function normalizePackets(array $packets): array
    {
        $normalized = [];
        foreach ($packets as $packet) {
            if (! is_array($packet)) {
                continue;
            }

            $id = isset($packet['id']) ? (string) $packet['id'] : '';
            if ($id === '') {
                continue;
            }

            $lane = isset($packet['lane']) ? (string) $packet['lane'] : 'cold';

            $normalized[] = [
                'id' => $id,
                'lane' => $lane === 'hot' ? 'hot' : 'cold',
                'allowed_files' => $this->normalizePaths($packet['allowed_files'] ?? []),
                'depends_on' => $this->normalizeIds($packet['depends_on'] ?? []),
                'assigned_hash' => $this->nullableString($packet['assigned_hash'] ?? null),
                'current_hash' => $this->nullableString($packet['current_hash'] ?? null),
                'owner' => $this->nullableString($packet['owner'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param  mixed  $reservations
     * @return array<int,array{packet_id:string,owner:string,allowed_files:array<int,string>}>
     */
    private function normalizeReservations(mixed $reservations): array
    {
        if (! is_array($reservations)) {
            return [];
        }

        $normalized = [];
        foreach ($reservations as $reservation) {
            if (! is_array($reservation)) {
                continue;
            }

            $packetId = isset($reservation['packet_id']) ? (string) $reservation['packet_id'] : '';
            if ($packetId === '') {
                continue;
            }

            $normalized[] = [
                'packet_id' => $packetId,
                'owner' => isset($reservation['owner']) ? (string) $reservation['owner'] : 'unknown',
                'allowed_files' => $this->normalizePaths($reservation['allowed_files'] ?? []),
            ];
        }

        return $normalized;
    }

    /**
     * @param  mixed  $values
     * @return array<int,string>
     */
    private function normalizePaths(mixed $values): array
    {
        return $this->normalizeIds($values);
    }

    /**
     * @param  mixed  $values
     * @return array<int,string>
     */
    private function normalizeIds(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $out[$value] = true;
            }
        }

        return array_keys($out);
    }

    private function nullableString(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
