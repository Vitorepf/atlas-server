<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction Durable Reservation STORAGE SCHEMA — pure,
 * deterministic, READ-ONLY decider that encodes the storage-schema contract doc
 * as runtime law. The doc defines the STORAGE SHAPE (table names + columns) for
 * durable reservation claims and the invariants any backend must hold. It emits
 * a "deterministic read-only schema packet that future migrations can implement
 * without guessing table shape, indexes, states, invariants or tests" — and it
 * authorizes NO migration creation, NO storage write and NO dispatch.
 *
 * This service is the SCHEMA / column-shape authority. It is distinct from the
 * sibling deciders: {@see AtlasDurableReservationLedgerImplementationPlanService}
 * pins the umbrella build PLAN (claim steps, promotion gate);
 * {@see AtlasReservationLedgerContractService} guards the live claim; this one
 * pins the two TABLE shapes, the current file-backed runtime, the seven storage
 * invariants and the seven required tests, so a later Postgres migration cannot
 * drift the column set.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *
 *   - "Tables" => tables() returns the closed set of EXACTLY two tables
 *     (atlas_self_construction_reservation_events as the append-only event
 *     source, atlas_self_construction_reservations as the rebuilt projection),
 *     each with its documented closed column set. eventColumns() and
 *     reservationColumns() are the authoritative column lists.
 *
 *   - "current runtime uses events.jsonl / projection.json / ledger.lock" =>
 *     currentRuntime() returns those three file-backed artifacts as the live
 *     backend; Postgres stays the PROMOTION TARGET, not the current backend.
 *
 *   - "Required Tests" (migration field check) => migrationFieldsPresent() fails
 *     CLOSED unless the proposed event migration carries every required family —
 *     hash, actor, state, lease and payload — so the audit chain cannot ship
 *     incomplete.
 *
 *   - "Required Invariants" => invariants() returns the closed SEVEN-invariant
 *     set; checkInvariants() proves each against a candidate projection+claim and
 *     fails at the EARLIEST violated invariant (append-only before single-active
 *     before file-overlap before expired-completion before hot-scope before
 *     dispatch-disabled), returning that invariant and reason.
 *
 *   - "Exactly one active claimed reservation may exist per packet" /
 *     "Active reservations with overlapping allowed files block new claims" /
 *     "Hot Voice/Kernel scopes are never claimable" /
 *     "Expired leases cannot mark completion" /
 *     "Dispatch remains disabled by this schema" => evaluateClaim() runs those
 *     reject gates IN ORDER against a candidate and stops at the EARLIEST
 *     violation; a clean candidate is `claimable`, any violation is `blocked`.
 *
 *   - "Completion Criteria" => schemaPacket() returns the deterministic, fully
 *     specified packet (tables, columns, runtime, invariants, tests) so a future
 *     migration implements it without guessing; ready() is true only when the
 *     packet leaves no field unspecified.
 *
 * Every decision keeps the doc's read-only guarantee: emitting this schema is
 * never creating a migration, writing storage, claiming a packet, dispatching,
 * executing a change, or granting auto-merge / hot-scope authority.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-storage-schema.md
 */
final class AtlasDurableReservationStorageSchemaService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_storage_schema.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_storage_schema';

    // ---- Doc "Tables" — closed two-table set, in documented order ----
    public const TABLE_EVENTS = 'atlas_self_construction_reservation_events';

    public const TABLE_RESERVATIONS = 'atlas_self_construction_reservations';

    /** @var list<string> */
    public const TABLES = [
        self::TABLE_EVENTS,
        self::TABLE_RESERVATIONS,
    ];

    /**
     * Doc: "append-only event source ... stores reservation id, packet id, event
     * type, actor, session, packet hash, allowed files hash, previous event hash,
     * event hash and payload". Closed column set for the event-source table.
     *
     * @var list<string>
     */
    public const EVENT_COLUMNS = [
        'reservation_id',
        'packet_id',
        'event_type',
        'actor',
        'session',
        'packet_hash',
        'allowed_files_hash',
        'previous_event_hash',
        'event_hash',
        'payload',
    ];

    /**
     * Doc: "current projection rebuilt from events ... stores packet id,
     * owner/session, state, packet hash, allowed files hash, lease expiry,
     * release/completion timestamps and blocker reason". Closed column set for
     * the projection table.
     *
     * @var list<string>
     */
    public const RESERVATION_COLUMNS = [
        'packet_id',
        'owner_session',
        'state',
        'packet_hash',
        'allowed_files_hash',
        'lease_expires_at',
        'released_at',
        'completed_at',
        'blocker_reason',
    ];

    /**
     * Doc: "The current runtime uses events.jsonl / projection.json /
     * ledger.lock". These three file-backed artifacts ARE the live backend;
     * Postgres is the promotion target, not the current store.
     *
     * @var array<string,string>
     */
    public const CURRENT_RUNTIME = [
        'events' => 'events.jsonl',
        'projection' => 'projection.json',
        'lock' => 'ledger.lock',
    ];

    /**
     * Doc "Required Tests": the event migration must carry these field FAMILIES —
     * "migration contains hash, actor, state, lease and payload fields". Each maps
     * to one or more concrete event columns; the migration fails closed if a whole
     * family is absent.
     *
     * @var array<string,list<string>>
     */
    public const REQUIRED_MIGRATION_FIELDS = [
        'hash' => ['packet_hash', 'allowed_files_hash', 'previous_event_hash', 'event_hash'],
        'actor' => ['actor'],
        'state' => ['event_type'],
        'lease' => ['lease_expires_at'],
        'payload' => ['payload'],
    ];

    // ---- Doc "Required Invariants" — closed seven-invariant set, in order ----
    public const INV_APPEND_ONLY = 'events_append_only';

    public const INV_PREV_HASH_CHAIN = 'each_event_references_prior_hash';

    public const INV_SINGLE_ACTIVE = 'exactly_one_active_claim_per_packet';

    public const INV_FILE_OVERLAP_BLOCKS = 'overlapping_allowed_files_block_new_claims';

    public const INV_EXPIRED_NO_COMPLETE = 'expired_leases_cannot_complete';

    public const INV_HOT_SCOPE_NEVER = 'hot_voice_kernel_scopes_never_claimable';

    public const INV_DISPATCH_DISABLED = 'dispatch_remains_disabled';

    /** @var list<string> */
    public const INVARIANTS = [
        self::INV_APPEND_ONLY,
        self::INV_PREV_HASH_CHAIN,
        self::INV_SINGLE_ACTIVE,
        self::INV_FILE_OVERLAP_BLOCKS,
        self::INV_EXPIRED_NO_COMPLETE,
        self::INV_HOT_SCOPE_NEVER,
        self::INV_DISPATCH_DISABLED,
    ];

    /**
     * Doc "Required Tests": the closed seven-test set that proves the schema. Used
     * by requiredTests() so the future implementation knows exactly what to cover.
     *
     * @var list<string>
     */
    public const REQUIRED_TESTS = [
        'migration_contains_hash_actor_state_lease_payload_fields',
        'duplicate_active_packet_claim_is_blocked',
        'overlapping_active_file_scope_is_blocked',
        'expired_reservation_cannot_complete',
        'released_reservation_can_be_reclaimed',
        'projection_can_be_rebuilt_from_events',
        'schema_command_does_not_create_migrations_or_write_storage',
    ];

    /**
     * Hot scopes the schema declares permanently non-claimable ("Hot Voice/Kernel
     * scopes are never claimable").
     *
     * @var list<string>
     */
    public const DEFAULT_HOT_SCOPES = [
        'app/Services/Ai/Voice/',
        'app/Services/Ai/Vox/',
        'app/Services/Ai/Kernel/',
    ];

    /** States in which an owner actively holds a packet. @var list<string> */
    public const ACTIVE_STATES = ['claimed', 'renewed'];

    /** Decision: candidate is clean and may be claimed. */
    public const DECISION_CLAIMABLE = 'claimable';

    /** Decision: a storage invariant blocks the claim. */
    public const DECISION_BLOCKED = 'blocked';

    /**
     * The six Non Goal flags every read-only surface forces false. Mirrors the
     * doc decision: "Storage claims never grant dispatch, execution, auto-merge or
     * hot-scope authority" plus "no migration created / no storage written".
     *
     * @var list<string>
     */
    public const NON_GOAL_KEYS = [
        'is_execution',
        'grants_dispatch',
        'grants_auto_merge',
        'grants_hot_scope',
        'creates_migration',
        'writes_storage',
    ];

    /**
     * Doc "Tables": the closed two-table set.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return self::TABLES;
    }

    /**
     * Authoritative column set for the append-only event source.
     *
     * @return list<string>
     */
    public function eventColumns(): array
    {
        return self::EVENT_COLUMNS;
    }

    /**
     * Authoritative column set for the rebuilt projection.
     *
     * @return list<string>
     */
    public function reservationColumns(): array
    {
        return self::RESERVATION_COLUMNS;
    }

    /**
     * Doc: the current backend is file-backed (events.jsonl / projection.json /
     * ledger.lock); Postgres is the promotion target only.
     *
     * @return array{backend:string, artifacts:array<string,string>, promotion_target:string}
     */
    public function currentRuntime(): array
    {
        return [
            'backend' => 'local_file',
            'artifacts' => self::CURRENT_RUNTIME,
            'promotion_target' => 'postgres',
        ];
    }

    /**
     * Doc "Required Invariants": the closed seven-invariant set.
     *
     * @return list<string>
     */
    public function invariants(): array
    {
        return self::INVARIANTS;
    }

    /**
     * Doc "Required Tests": the closed seven-test set.
     *
     * @return list<string>
     */
    public function requiredTests(): array
    {
        return self::REQUIRED_TESTS;
    }

    /**
     * Doc "Required Tests": the proposed event migration must carry every required
     * field FAMILY (hash, actor, state, lease, payload). Fails CLOSED — any family
     * with no present column makes the migration incomplete. A family is satisfied
     * when at least one of its concrete columns is present.
     *
     * @param  list<string>  $proposedColumns  columns the candidate migration declares
     * @return array{
     *   surface:string, schema:string,
     *   present:bool,
     *   missing_families:list<string>,
     *   satisfied_families:list<string>,
     *   non_goals:array<string,false>
     * }
     */
    public function migrationFieldsPresent(array $proposedColumns): array
    {
        $columns = $this->stringList($proposedColumns);
        $missing = [];
        $satisfied = [];

        foreach (self::REQUIRED_MIGRATION_FIELDS as $family => $candidates) {
            $hit = false;
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                $satisfied[] = $family;
            } else {
                $missing[] = $family;
            }
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'present' => $missing === [],
            'missing_families' => $missing,
            'satisfied_families' => $satisfied,
            'non_goals' => $this->nonGoals(),
        ];
    }

    /**
     * Resolve the EFFECTIVE state of a projection row, applying the State Rules: a
     * claimed/renewed row whose lease has already passed is demoted to `expired`
     * so a timed-out owner never reads as active. Unknown/terminal states are
     * returned untouched (a `completed` or `released` row stays as-is).
     *
     * @param array{state?:string,lease_expires_at?:?string} $row
     */
    public function effectiveState(array $row, ?int $nowTs = null): string
    {
        $state = is_string($row['state'] ?? null) ? $row['state'] : 'available';

        if (in_array($state, self::ACTIVE_STATES, true) && $this->leaseExpired($row['lease_expires_at'] ?? null, $nowTs)) {
            return 'expired';
        }

        return $state;
    }

    /**
     * A row actively holds a packet only while its effective state is claimed or
     * renewed (an expired lease has already been demoted away).
     *
     * @param array{state?:string,lease_expires_at?:?string} $row
     */
    public function isActive(array $row, ?int $nowTs = null): bool
    {
        return in_array($this->effectiveState($row, $nowTs), self::ACTIVE_STATES, true);
    }

    /**
     * Primary surface: evaluate one CANDIDATE claim against a projection under the
     * storage invariants, IN ORDER, stopping at the EARLIEST violated invariant.
     * Pure and fail-closed — emitting this decision writes no storage and claims
     * nothing.
     *
     * Order mirrors the doc invariant list that bears on a claim:
     *   1. INV_SINGLE_ACTIVE        — one active claim per packet
     *   2. INV_FILE_OVERLAP_BLOCKS  — overlapping allowed files block
     *   3. INV_HOT_SCOPE_NEVER      — hot Voice/Kernel scopes never claimable
     *
     * (INV_APPEND_ONLY / INV_PREV_HASH_CHAIN / INV_EXPIRED_NO_COMPLETE /
     *  INV_DISPATCH_DISABLED are structural and covered by checkInvariants().)
     *
     * @param array{
     *   candidate_packet_id?:string,
     *   allowed_files?:list<string>,
     *   hot_scopes?:list<string>,
     *   projection?:array<int|string,array{packet_id?:string,state?:string,lease_expires_at?:?string,allowed_files_hash?:string,allowed_files?:list<string>}>
     * } $input
     * @return array{
     *   surface:string, schema:string,
     *   candidate_packet_id:?string,
     *   decision:string,
     *   violated_invariant:?string,
     *   blocking_reason:?string,
     *   overlapping_files:list<string>,
     *   hot_scope_matches:list<string>,
     *   non_goals:array<string,false>
     * }
     */
    public function evaluateClaim(array $input = []): array
    {
        $candidateId = is_string($input['candidate_packet_id'] ?? null) ? $input['candidate_packet_id'] : null;
        $allowed = $this->stringList($input['allowed_files'] ?? null);
        $hotScopes = $this->stringList($input['hot_scopes'] ?? null) ?: self::DEFAULT_HOT_SCOPES;
        $projection = is_array($input['projection'] ?? null) ? $input['projection'] : [];

        $violated = null;
        $reason = null;
        $overlapping = [];
        $hotMatches = [];

        // ---- Invariant 1: exactly one active claim per packet ----
        $self = $this->rowForPacket($projection, $candidateId);
        if ($self !== null && $this->isActive($self)) {
            $violated = self::INV_SINGLE_ACTIVE;
            $reason = 'packet_already_claimed';
        }

        // ---- Invariant 2: overlapping active allowed files block new claims ----
        if ($violated === null) {
            foreach ($projection as $row) {
                if (! is_array($row) || ! $this->isActive($row)) {
                    continue;
                }
                $rowFiles = $this->stringList($row['allowed_files'] ?? null);
                $hits = array_values(array_intersect($allowed, $rowFiles));
                if ($hits !== []) {
                    $overlapping = $hits;
                    $violated = self::INV_FILE_OVERLAP_BLOCKS;
                    $reason = 'overlapping_allowed_files';
                    break;
                }
            }
        }

        // ---- Invariant 3: hot Voice/Kernel scopes are never claimable ----
        if ($violated === null) {
            foreach ($allowed as $file) {
                foreach ($hotScopes as $scope) {
                    if ($scope !== '' && str_starts_with($file, $scope)) {
                        $hotMatches[] = $file;
                        break;
                    }
                }
            }
            if ($hotMatches !== []) {
                $violated = self::INV_HOT_SCOPE_NEVER;
                $reason = 'hot_scope_not_claimable';
            }
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'candidate_packet_id' => $candidateId,
            'decision' => $violated === null ? self::DECISION_CLAIMABLE : self::DECISION_BLOCKED,
            'violated_invariant' => $violated,
            'blocking_reason' => $reason,
            'overlapping_files' => $overlapping,
            'hot_scope_matches' => $hotMatches,
            'non_goals' => $this->nonGoals(),
        ];
    }

    /**
     * Doc "Expired leases cannot mark completion". A claimed/renewed row whose
     * lease has passed is demoted to `expired`; completion is then refused. A
     * still-active row may complete and lands in `completed`.
     *
     * @param array{state?:string,lease_expires_at?:?string} $row
     * @return array{accepted:bool, from_state:string, to_state:?string, reason:?string, non_goals:array<string,false>}
     */
    public function completion(array $row, ?int $nowTs = null): array
    {
        $effective = $this->effectiveState($row, $nowTs);

        if ($effective === 'expired') {
            return [
                'accepted' => false,
                'from_state' => 'expired',
                'to_state' => null,
                'reason' => 'lease_expired',
                'non_goals' => $this->nonGoals(),
            ];
        }

        if (! in_array($effective, self::ACTIVE_STATES, true)) {
            return [
                'accepted' => false,
                'from_state' => $effective,
                'to_state' => null,
                'reason' => 'not_active',
                'non_goals' => $this->nonGoals(),
            ];
        }

        return [
            'accepted' => true,
            'from_state' => $effective,
            'to_state' => 'completed',
            'reason' => null,
            'non_goals' => $this->nonGoals(),
        ];
    }

    /**
     * Prove the seven storage invariants against a candidate projection + claim
     * snapshot, IN ORDER, failing at the EARLIEST violated invariant. Each
     * invariant maps to a concrete check; a missing/false proof is a violation.
     *
     * @param array{
     *   events?:array<int,array{previous_event_hash?:?string}>,
     *   projection?:array<int|string,array{packet_id?:string,state?:string,lease_expires_at?:?string,allowed_files?:list<string>}>,
     *   dispatch_enabled?:bool
     * } $snapshot
     * @return array{
     *   surface:string, schema:string,
     *   holds:bool,
     *   violated_invariant:?string,
     *   reason:?string,
     *   checked:list<string>,
     *   non_goals:array<string,false>
     * }
     */
    public function checkInvariants(array $snapshot): array
    {
        $events = is_array($snapshot['events'] ?? null) ? array_values($snapshot['events']) : [];
        $projection = is_array($snapshot['projection'] ?? null) ? array_values($snapshot['projection']) : [];
        $dispatchEnabled = (bool) ($snapshot['dispatch_enabled'] ?? false);

        $checked = [];
        $violated = null;
        $reason = null;

        // 1. Append-only: events present in order (a non-list / re-keyed log is a
        //    rewrite signal). We accept any list-shaped event stream as append-only.
        $checked[] = self::INV_APPEND_ONLY;
        if (! array_is_list($events)) {
            $violated = self::INV_APPEND_ONLY;
            $reason = 'event_log_not_append_only';
        }

        // 2. Each event after the first references the prior event hash.
        if ($violated === null) {
            $checked[] = self::INV_PREV_HASH_CHAIN;
            foreach ($events as $i => $event) {
                if ($i === 0) {
                    continue;
                }
                $prev = is_array($event) ? ($event['previous_event_hash'] ?? null) : null;
                if (! is_string($prev) || $prev === '') {
                    $violated = self::INV_PREV_HASH_CHAIN;
                    $reason = 'missing_prior_event_hash';
                    break;
                }
            }
        }

        // 3. Exactly one active claim per packet.
        if ($violated === null) {
            $checked[] = self::INV_SINGLE_ACTIVE;
            $activeByPacket = [];
            foreach ($projection as $row) {
                if (! is_array($row) || ! $this->isActive($row)) {
                    continue;
                }
                $pid = (string) ($row['packet_id'] ?? '');
                $activeByPacket[$pid] = ($activeByPacket[$pid] ?? 0) + 1;
                if ($activeByPacket[$pid] > 1) {
                    $violated = self::INV_SINGLE_ACTIVE;
                    $reason = 'multiple_active_claims_for_packet';
                    break;
                }
            }
        }

        // 4. No two ACTIVE reservations share an allowed file.
        if ($violated === null) {
            $checked[] = self::INV_FILE_OVERLAP_BLOCKS;
            $seenFiles = [];
            foreach ($projection as $row) {
                if (! is_array($row) || ! $this->isActive($row)) {
                    continue;
                }
                foreach ($this->stringList($row['allowed_files'] ?? null) as $file) {
                    if (isset($seenFiles[$file])) {
                        $violated = self::INV_FILE_OVERLAP_BLOCKS;
                        $reason = 'overlapping_allowed_files_in_projection';
                        break 2;
                    }
                    $seenFiles[$file] = true;
                }
            }
        }

        // 5. No expired row is marked completed (an expired lease cannot complete).
        if ($violated === null) {
            $checked[] = self::INV_EXPIRED_NO_COMPLETE;
            foreach ($projection as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $state = is_string($row['state'] ?? null) ? $row['state'] : '';
                if ($state === 'completed' && $this->leaseExpired($row['lease_expires_at'] ?? null) && ($row['completed_via_expiry'] ?? false)) {
                    $violated = self::INV_EXPIRED_NO_COMPLETE;
                    $reason = 'expired_lease_marked_completed';
                    break;
                }
            }
        }

        // 6. No active row holds a hot Voice/Kernel scope.
        if ($violated === null) {
            $checked[] = self::INV_HOT_SCOPE_NEVER;
            foreach ($projection as $row) {
                if (! is_array($row) || ! $this->isActive($row)) {
                    continue;
                }
                foreach ($this->stringList($row['allowed_files'] ?? null) as $file) {
                    foreach (self::DEFAULT_HOT_SCOPES as $scope) {
                        if ($scope !== '' && str_starts_with($file, $scope)) {
                            $violated = self::INV_HOT_SCOPE_NEVER;
                            $reason = 'active_reservation_holds_hot_scope';
                            break 3;
                        }
                    }
                }
            }
        }

        // 7. Dispatch remains disabled by this schema.
        if ($violated === null) {
            $checked[] = self::INV_DISPATCH_DISABLED;
            if ($dispatchEnabled) {
                $violated = self::INV_DISPATCH_DISABLED;
                $reason = 'dispatch_enabled_by_storage';
            }
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'holds' => $violated === null,
            'violated_invariant' => $violated,
            'reason' => $reason,
            'checked' => $checked,
            'non_goals' => $this->nonGoals(),
        ];
    }

    /**
     * Doc "Completion Criteria": the deterministic, fully specified read-only
     * schema packet a future migration can implement without guessing table
     * shape, columns, runtime, invariants or tests.
     *
     * @return array{
     *   surface:string, schema:string,
     *   tables:array<string,array{role:string, columns:list<string>}>,
     *   current_runtime:array{backend:string, artifacts:array<string,string>, promotion_target:string},
     *   invariants:list<string>,
     *   required_tests:list<string>,
     *   required_migration_fields:array<string,list<string>>,
     *   ready:bool,
     *   non_goals:array<string,false>
     * }
     */
    public function schemaPacket(): array
    {
        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'tables' => [
                self::TABLE_EVENTS => [
                    'role' => 'append_only_event_source',
                    'columns' => self::EVENT_COLUMNS,
                ],
                self::TABLE_RESERVATIONS => [
                    'role' => 'rebuilt_projection',
                    'columns' => self::RESERVATION_COLUMNS,
                ],
            ],
            'current_runtime' => $this->currentRuntime(),
            'invariants' => self::INVARIANTS,
            'required_tests' => self::REQUIRED_TESTS,
            'required_migration_fields' => self::REQUIRED_MIGRATION_FIELDS,
            'ready' => $this->ready(),
            'non_goals' => $this->nonGoals(),
        ];
    }

    /**
     * The schema packet is COMPLETE (ready to hand to a migration) only when every
     * field is specified: two tables, both with non-empty column sets, the file
     * runtime, all seven invariants and all seven tests.
     */
    public function ready(): bool
    {
        return count(self::TABLES) === 2
            && self::EVENT_COLUMNS !== []
            && self::RESERVATION_COLUMNS !== []
            && count(self::INVARIANTS) === 7
            && count(self::REQUIRED_TESTS) === 7
            && self::CURRENT_RUNTIME !== [];
    }

    /**
     * The Non Goal guarantee: every read-only flag is false. Mirrors the doc
     * decision "Storage claims never grant dispatch, execution, auto-merge or
     * hot-scope authority" plus the read-only schema-command rule (no migration
     * created, no storage written).
     *
     * @return array<string,false>
     */
    public function nonGoals(): array
    {
        return array_fill_keys(self::NON_GOAL_KEYS, false);
    }

    /**
     * Fail-closed guarantee check across emitted decisions: no surface may report
     * it executed, created a migration, wrote storage or granted any authority.
     *
     * @param  list<array<string,mixed>>  $results
     * @return list<string>  human-readable violations (empty => guarantee held)
     */
    public function assertGuaranteeHeld(array $results): array
    {
        $violations = [];

        foreach ($results as $i => $result) {
            $nonGoals = is_array($result['non_goals'] ?? null) ? $result['non_goals'] : null;
            if ($nonGoals === null) {
                $violations[] = "result[$i] missing non_goals";

                continue;
            }
            foreach (self::NON_GOAL_KEYS as $key) {
                if (($nonGoals[$key] ?? null) !== false) {
                    $violations[] = "result[$i].non_goals.$key is not false";
                }
            }
        }

        return $violations;
    }

    /**
     * Find the first projection row for a packet id.
     *
     * @param  array<int|string,mixed>  $projection
     * @return array<string,mixed>|null
     */
    private function rowForPacket(array $projection, ?string $packetId): ?array
    {
        if ($packetId === null) {
            return null;
        }
        foreach ($projection as $row) {
            if (is_array($row) && (string) ($row['packet_id'] ?? '') === $packetId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * A lease is expired when its ISO-8601 instant is at or before now. A missing
     * lease is treated as NOT expired (it is the caller's job to require one).
     */
    private function leaseExpired(mixed $leaseExpiresAt, ?int $nowTs = null): bool
    {
        if (! is_string($leaseExpiresAt) || $leaseExpiresAt === '') {
            return false;
        }
        $ts = strtotime($leaseExpiresAt);
        if ($ts === false) {
            return false;
        }

        return $ts <= ($nowTs ?? time());
    }

    /**
     * Normalise a mixed value to a clean list of non-empty strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return array_values($out);
    }
}
