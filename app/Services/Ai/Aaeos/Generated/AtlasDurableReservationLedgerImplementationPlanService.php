<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Atlas Self-Construction Durable Reservation Ledger Implementation Plan — pure,
 * deterministic, READ-ONLY decider that encodes the implementation PLAN doc as
 * runtime law. The doc converts the blocker `durable_reservation_ledger_missing`
 * into a future implementation packet: it tells a later AI what to build, while
 * authorizing NO storage write, migration or dispatch in this phase.
 *
 * This service is the umbrella plan, distinct from the narrower sibling deciders
 * ({@see AtlasReservationLedgerContractService} for the live claim guard,
 * {@see AtlasDurableReservationLeaseLifecycleContractService} for lease/reclaim,
 * {@see AtlasDurableReservationCollisionGuardContractService} for collision).
 * Here we pin the PLAN's own contract so the future storage layer cannot drift.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *
 *   - "Required Storage" => requiredStorage() returns the closed set of the THREE
 *     tables (projection, append-only event ledger, packet snapshots) that the
 *     future implementation must create — no more, no fewer.
 *
 *   - "Required States" => the closed SEVEN-state set
 *     (available, claimed, renewed, released, expired, completed, blocked) and
 *     effectiveState() demotes a claimed/renewed row whose lease has passed to
 *     `expired`, so a timed-out owner never reads as active.
 *
 *   - "Atomic Claim Rules" => evaluateClaim() runs the EIGHT ordered steps as one
 *     transaction and fails at the EARLIEST violated step (stale hash before
 *     active-reservation before file overlap before hot scope), returning that
 *     step, its reason and the resulting state. A clean candidate yields
 *     `claimed`; any violation yields `blocked`.
 *
 *   - "Lease Rules" => renewal() proves the packet hash and allowed files did not
 *     change and refuses to renew anything but an active lease; completion()
 *     refuses to complete an expired lease and requires the completing session to
 *     own an active claim.
 *
 *   - "Evidence Rules" => evidenceFields() returns the closed list of the TEN
 *     fields every durable event must store, and eventIsComplete() fails closed
 *     when any is missing — the foundation of the append-only chain.
 *
 *   - "Promotion Gate" => promotionGate() is NOT ready until ALL SEVEN documented
 *     invariants are proven; a single missing proof keeps it `not_ready`.
 *
 * Every decision keeps the doc's Non Goals: deciding is never starting a session,
 * dispatching work, executing a change, touching Voice/Kernel/routes/config/
 * migrations, or bypassing Decision Receipt / packet completion gates.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-ledger-implementation-plan.md
 */
final class AtlasDurableReservationLedgerImplementationPlanService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_ledger_implementation_plan.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_ledger_implementation_plan';

    // ---- Doc "Required States" — closed seven-state set, in documented order ----
    public const STATE_AVAILABLE = 'available';

    public const STATE_CLAIMED = 'claimed';

    public const STATE_RENEWED = 'renewed';

    public const STATE_RELEASED = 'released';

    public const STATE_EXPIRED = 'expired';

    public const STATE_COMPLETED = 'completed';

    public const STATE_BLOCKED = 'blocked';

    /** @var list<string> */
    public const STATES = [
        self::STATE_AVAILABLE,
        self::STATE_CLAIMED,
        self::STATE_RENEWED,
        self::STATE_RELEASED,
        self::STATE_EXPIRED,
        self::STATE_COMPLETED,
        self::STATE_BLOCKED,
    ];

    /**
     * The two states in which an owner actively holds a packet (before lease
     * expiry demotes them). A `renewed` row is just a `claimed` row whose lease
     * was extended.
     *
     * @var list<string>
     */
    public const ACTIVE_STATES = [self::STATE_CLAIMED, self::STATE_RENEWED];

    /** Decision: candidate is clean and may be claimed. */
    public const DECISION_ALLOW_CLAIM = 'allow_claim';

    /** Decision: an atomic claim rule blocks the claim. */
    public const DECISION_BLOCKED = 'blocked';

    /**
     * Doc "Required Storage" — the closed set of the three tables the future
     * implementation must create.
     *
     * @var list<string>
     */
    public const REQUIRED_STORAGE = [
        'atlas_self_construction_reservations',
        'atlas_self_construction_reservation_events',
        'atlas_self_construction_packet_snapshots',
    ];

    /**
     * Doc "Atomic Claim Rules" — the EIGHT ordered steps of the single claim
     * transaction, in documented order. Steps 1/6/7/8 are non-blocking pipeline
     * stages (recompute, append event, write projection, emit receipt); steps
     * 2..5 are the four reject gates.
     *
     * @var list<string>
     */
    public const CLAIM_STEPS = [
        'recompute_hashes',          // 1. recompute packet queue, split hash, packet hash
        'reject_stale_hashes',       // 2. reject stale hashes
        'reject_active_reservation', // 3. reject any active reservation for the same packet
        'reject_allowed_overlap',    // 4. reject allowed-file overlap with active reservations
        'reject_hot_scope',          // 5. reject hot forbidden scopes
        'append_claim_event',        // 6. insert append-only claim_attempted event
        'write_projection',          // 7. insert/update current projection under lock
        'emit_receipt',              // 8. emit reservation hash and claim receipt
    ];

    /**
     * The four REJECT steps (the gates that can block a claim), in the order the
     * transaction evaluates them. Ordering is load-bearing: a stale hash is caught
     * before an active-reservation conflict, which is caught before file overlap,
     * which is caught before a hot scope.
     *
     * @var list<string>
     */
    public const REJECT_STEPS = [
        self::CLAIM_STEPS[1],
        self::CLAIM_STEPS[2],
        self::CLAIM_STEPS[3],
        self::CLAIM_STEPS[4],
    ];

    /**
     * Doc "Evidence Rules" — the closed list of the TEN fields every durable event
     * must store, in documented order. eventIsComplete() fails closed if any is
     * absent or blank.
     *
     * @var list<string>
     */
    public const EVIDENCE_FIELDS = [
        'packet_id',
        'owner_id',
        'packet_hash',
        'split_hash',
        'allowed_files_hash',
        'forbidden_files_hash',
        'scope_validator_hash',
        'gate_hash',
        'prior_event_hash',
        'created_at',
    ];

    /**
     * Doc "Promotion Gate" — the closed list of the SEVEN invariants that tests
     * must prove before durable reservation is ready, in documented order.
     *
     * @var list<string>
     */
    public const PROMOTION_INVARIANTS = [
        'two_sessions_cannot_claim_same_packet',
        'overlapping_allowed_files_blocked',
        'hot_scopes_blocked',
        'stale_packet_hashes_blocked',
        'expired_claims_cannot_complete',
        'released_claims_become_available',
        'append_only_events_cannot_be_rewritten',
    ];

    /**
     * Doc "Non Goals" — the actions this plan must NEVER take in the read-only
     * phase. Every decision forces all of these false.
     *
     * @var list<string>
     */
    public const NON_GOAL_KEYS = [
        'sessions_started',
        'work_dispatched',
        'code_changes_executed',
        'voice_kernel_routes_config_migrations_touched',
        'decision_receipt_bypassed',
        'completion_gate_bypassed',
    ];

    /**
     * Default hot forbidden scope prefixes the plan forbids reserving. A path
     * matches when it equals the prefix or starts with it.
     *
     * @var list<string>
     */
    public const DEFAULT_HOT_SCOPES = [
        'runtimes/python/voice_realtime/',
        'app/Services/Ai/Voice/',
        'app/Services/Ai/Kernel/',
        'routes/',
        'config/',
        'database/migrations/',
    ];

    /**
     * The closed set of three storage tables the future implementation must
     * create.
     *
     * @return list<string>
     */
    public function requiredStorage(): array
    {
        return self::REQUIRED_STORAGE;
    }

    /**
     * The eight ordered atomic-claim steps.
     *
     * @return list<string>
     */
    public function claimSteps(): array
    {
        return self::CLAIM_STEPS;
    }

    /**
     * The ten required evidence fields.
     *
     * @return list<string>
     */
    public function evidenceFields(): array
    {
        return self::EVIDENCE_FIELDS;
    }

    /**
     * Resolve the EFFECTIVE durable state, applying the documented State Rules. A
     * claimed/renewed row whose lease has already passed is demoted to `expired`
     * ("expired: lease passed without renewal"), so a timed-out owner never reads
     * as active. Unknown states fail closed to `blocked`; terminal states are
     * returned untouched.
     *
     * @param array{state?:string,lease_expires_at?:?string} $row
     */
    public function effectiveState(array $row, ?int $nowTs = null): string
    {
        $state = is_string($row['state'] ?? null) ? $row['state'] : self::STATE_AVAILABLE;

        if (! in_array($state, self::STATES, true)) {
            return self::STATE_BLOCKED;
        }

        if (in_array($state, self::ACTIVE_STATES, true) && $this->leaseExpired($row['lease_expires_at'] ?? null, $nowTs)) {
            return self::STATE_EXPIRED;
        }

        return $state;
    }

    /**
     * A reservation actively holds a packet only while its effective state is
     * claimed or renewed (an expired lease has already been demoted away).
     *
     * @param array{state?:string,lease_expires_at?:?string} $row
     */
    public function isActive(array $row, ?int $nowTs = null): bool
    {
        return in_array($this->effectiveState($row, $nowTs), self::ACTIVE_STATES, true);
    }

    /**
     * Primary surface: evaluate one CANDIDATE packet against the live ledger as
     * the single atomic claim transaction. Runs the four reject gates IN ORDER and
     * stops at the EARLIEST violated step (the doc lists them 2..5 and a real
     * transaction aborts on the first failure). Pure and fail-closed — emitting
     * this decision persists no claim.
     *
     * @param array{
     *   candidate_packet_id?:string,
     *   allowed_files?:list<string>,
     *   packet_hash?:string,
     *   split_hash?:string,
     *   assigned_packet_hash?:string,
     *   assigned_split_hash?:string,
     *   hot_scopes?:list<string>,
     *   ledger?:array<int|string,array{packet_id?:string,state?:string,lease_expires_at?:?string,allowed_files?:list<string>}>
     * } $input
     * @return array{
     *   surface:string, schema:string,
     *   candidate_packet_id:?string,
     *   decision:string,
     *   resulting_state:string,
     *   failed_step:?string,
     *   blocking_reason:?string,
     *   overlapping_files:list<string>,
     *   hot_scope_matches:list<string>,
     *   non_goals:array<string,false>,
     *   claim_persisted:false, is_execution:false
     * }
     */
    public function evaluateClaim(array $input = []): array
    {
        $candidateId = is_string($input['candidate_packet_id'] ?? null) ? $input['candidate_packet_id'] : null;
        $allowed = AtlasAaeosStringListNormalizer::nonEmptyArrayStrings($input['allowed_files'] ?? null);
        $hotScopes = AtlasAaeosStringListNormalizer::nonEmptyArrayStrings($input['hot_scopes'] ?? null) ?: self::DEFAULT_HOT_SCOPES;

        $packetHash = is_string($input['packet_hash'] ?? null) ? $input['packet_hash'] : null;
        $splitHash = is_string($input['split_hash'] ?? null) ? $input['split_hash'] : null;
        $assignedPacketHash = is_string($input['assigned_packet_hash'] ?? null) ? $input['assigned_packet_hash'] : null;
        $assignedSplitHash = is_string($input['assigned_split_hash'] ?? null) ? $input['assigned_split_hash'] : null;

        $ledger = is_array($input['ledger'] ?? null) ? $input['ledger'] : [];

        $failedStep = null;
        $reason = null;
        $overlapping = [];
        $hotMatches = [];

        // ---- Step 2: reject stale hashes (packet hash or split hash drifted) ----
        if ($packetHash !== null && $assignedPacketHash !== null && $packetHash !== $assignedPacketHash) {
            $failedStep = self::CLAIM_STEPS[1];
            $reason = 'packet_hash_stale';
        } elseif ($splitHash !== null && $assignedSplitHash !== null && $splitHash !== $assignedSplitHash) {
            $failedStep = self::CLAIM_STEPS[1];
            $reason = 'split_hash_stale';
        }

        // ---- Step 3: reject any ACTIVE reservation for the same packet ----
        if ($failedStep === null) {
            $self = $this->rowForPacket($ledger, $candidateId);
            if ($self !== null && $this->effectiveState($self) === self::STATE_COMPLETED) {
                $failedStep = self::CLAIM_STEPS[2];
                $reason = 'packet_already_completed';
            } elseif ($self !== null && $this->isActive($self)) {
                $failedStep = self::CLAIM_STEPS[2];
                $reason = 'packet_already_claimed';
            }
        }

        // ---- Step 4: reject allowed-file overlap with OTHER active reservations ----
        if ($failedStep === null) {
            foreach ($ledger as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (((string) ($row['packet_id'] ?? '')) === (string) $candidateId) {
                    continue; // not a collision with itself
                }
                if (! $this->isActive($row)) {
                    continue;
                }
                foreach (array_intersect($allowed, AtlasAaeosStringListNormalizer::nonEmptyArrayStrings($row['allowed_files'] ?? null)) as $file) {
                    $overlapping[] = $file;
                }
            }
            $overlapping = array_values(array_unique($overlapping));
            if ($overlapping !== []) {
                $failedStep = self::CLAIM_STEPS[3];
                $reason = 'allowed_files_overlap';
            }
        }

        // ---- Step 5: reject hot forbidden scopes in the allowed set ----
        if ($failedStep === null) {
            $hotMatches = $this->hotScopeMatches($allowed, $hotScopes);
            if ($hotMatches !== []) {
                $failedStep = self::CLAIM_STEPS[4];
                $reason = 'hot_forbidden_scope';
            }
        }

        $blocked = $failedStep !== null;

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'candidate_packet_id' => $candidateId,
            'decision' => $blocked ? self::DECISION_BLOCKED : self::DECISION_ALLOW_CLAIM,
            'resulting_state' => $blocked ? self::STATE_BLOCKED : self::STATE_CLAIMED,
            'failed_step' => $failedStep,
            'blocking_reason' => $reason,
            'overlapping_files' => $overlapping,
            'hot_scope_matches' => $hotMatches,
            'non_goals' => $this->nonGoals(),
            'claim_persisted' => false,
            'is_execution' => false,
        ];
    }

    /**
     * Doc "Lease Rules": renewal must prove the packet hash and allowed files did
     * NOT change, and only an actively-held lease can be renewed. Returns the
     * renewal decision; a changed hash or changed file set, or a non-active row,
     * is rejected.
     *
     * @param array{state?:string,lease_expires_at?:?string,packet_hash?:string,allowed_files?:list<string>} $row
     * @param array{packet_hash?:string,allowed_files?:list<string>} $current
     * @return array{
     *   surface:string, accepted:bool, from_state:string, to_state:string,
     *   reason:?string, non_goals:array<string,false>, is_execution:false
     * }
     */
    public function renewal(array $row, array $current): array
    {
        $from = $this->effectiveState($row);
        $reason = null;
        $accepted = true;

        if (! in_array($from, self::ACTIVE_STATES, true)) {
            $accepted = false;
            $reason = 'lease_not_active';
        } elseif ($this->hashChanged($row['packet_hash'] ?? null, $current['packet_hash'] ?? null)) {
            $accepted = false;
            $reason = 'packet_hash_changed';
        } elseif (
            AtlasAaeosStringListNormalizer::nonEmptyArrayStrings($row['allowed_files'] ?? null)
            !== AtlasAaeosStringListNormalizer::nonEmptyArrayStrings($current['allowed_files'] ?? null)
        ) {
            $accepted = false;
            $reason = 'allowed_files_changed';
        }

        return [
            'surface' => self::SURFACE,
            'accepted' => $accepted,
            'from_state' => $from,
            'to_state' => $accepted ? self::STATE_RENEWED : $from,
            'reason' => $reason,
            'non_goals' => $this->nonGoals(),
            'is_execution' => false,
        ];
    }

    /**
     * Doc "Lease Rules": completion must require an ACTIVE claim owned by the
     * completing session, and an expired lease can NEVER be completed. Returns the
     * completion decision; ownership mismatch or an expired/inactive lease is
     * rejected.
     *
     * @param array{state?:string,lease_expires_at?:?string,owner_id?:string} $row
     * @return array{
     *   surface:string, accepted:bool, from_state:string, to_state:string,
     *   reason:?string, non_goals:array<string,false>, is_execution:false
     * }
     */
    public function completion(array $row, string $completingOwnerId, ?int $nowTs = null): array
    {
        $from = $this->effectiveState($row, $nowTs);
        $owner = is_string($row['owner_id'] ?? null) ? $row['owner_id'] : null;

        $reason = null;
        $accepted = true;

        if (! in_array($from, self::ACTIVE_STATES, true)) {
            // Covers expired (demoted), available, released, completed, blocked.
            $accepted = false;
            $reason = $from === self::STATE_EXPIRED ? 'lease_expired' : 'reservation_not_active';
        } elseif ($owner === null || $owner !== $completingOwnerId) {
            $accepted = false;
            $reason = 'owner_mismatch';
        }

        return [
            'surface' => self::SURFACE,
            'accepted' => $accepted,
            'from_state' => $from,
            'to_state' => $accepted ? self::STATE_COMPLETED : $from,
            'reason' => $reason,
            'non_goals' => $this->nonGoals(),
            'is_execution' => false,
        ];
    }

    /**
     * Whether a documented state transition is legal. Encodes the State Rules so
     * the future storage layer cannot, e.g., re-claim a completed packet, complete
     * an expired one, or claim anything but an available row.
     */
    public function transitionAllowed(string $from, string $to): bool
    {
        if (! in_array($from, self::STATES, true) || ! in_array($to, self::STATES, true)) {
            return false;
        }

        return match ($to) {
            // A claim originates only from `available` (a released/expired row
            // must first return to available before being re-claimed).
            self::STATE_CLAIMED => $from === self::STATE_AVAILABLE,
            // Renewal extends an active lease (claimed or already-renewed).
            self::STATE_RENEWED => in_array($from, self::ACTIVE_STATES, true),
            // Release / complete act only on an actively held row.
            self::STATE_RELEASED, self::STATE_COMPLETED => in_array($from, self::ACTIVE_STATES, true),
            // Expiry can hit any active held row.
            self::STATE_EXPIRED => in_array($from, self::ACTIVE_STATES, true),
            // A released or expired row returns to the pool as available.
            self::STATE_AVAILABLE => in_array($from, [self::STATE_RELEASED, self::STATE_EXPIRED], true),
            // A claim attempt that hits a reject gate lands in blocked.
            self::STATE_BLOCKED => $from === self::STATE_AVAILABLE,
            default => false,
        };
    }

    /**
     * Doc "Evidence Rules": whether a durable event row carries ALL TEN required
     * fields with non-blank values. Fails closed (any missing/blank field => not
     * complete), and reports exactly which fields are missing.
     *
     * @param array<string,mixed> $event
     * @return array{complete:bool, missing:list<string>}
     */
    public function eventIsComplete(array $event): array
    {
        $missing = [];
        foreach (self::EVIDENCE_FIELDS as $field) {
            $value = $event[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $field;
            }
        }

        return [
            'complete' => $missing === [],
            'missing' => $missing,
        ];
    }

    /**
     * Doc "Promotion Gate": durable reservation is NOT ready until ALL SEVEN
     * invariants are proven. Accepts a map of invariant => proven(bool); any
     * absent or false invariant keeps the gate `not_ready` and is reported.
     *
     * @param array<string,bool> $provenInvariants
     * @return array{
     *   surface:string, schema:string,
     *   ready:bool, status:string,
     *   required_invariants:list<string>,
     *   missing_invariants:list<string>,
     *   non_goals:array<string,false>
     * }
     */
    public function promotionGate(array $provenInvariants = []): array
    {
        $missing = [];
        foreach (self::PROMOTION_INVARIANTS as $invariant) {
            if (($provenInvariants[$invariant] ?? false) !== true) {
                $missing[] = $invariant;
            }
        }

        $ready = $missing === [];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'not_ready',
            'required_invariants' => self::PROMOTION_INVARIANTS,
            'missing_invariants' => $missing,
            'non_goals' => $this->nonGoals(),
        ];
    }

    /**
     * The six Non Goal keys, all forced false (deciding is read-only and never
     * starts, dispatches, executes, touches hot scope or bypasses a gate).
     *
     * @return array<string,false>
     */
    public function nonGoals(): array
    {
        $out = [];
        foreach (self::NON_GOAL_KEYS as $key) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * Assert the read-only Non Goal guarantee held across a batch of decision
     * results: every `non_goals` flag must be false and no decision may claim it
     * executed. Returns the list of violations (empty => guarantee held).
     *
     * @param list<array<string,mixed>> $results
     * @return list<string>
     */
    public function assertGuaranteeHeld(array $results): array
    {
        $violations = [];
        foreach ($results as $i => $result) {
            if (! is_array($result)) {
                $violations[] = "result_{$i}_not_array";

                continue;
            }
            if (($result['is_execution'] ?? false) !== false) {
                $violations[] = "result_{$i}_is_execution";
            }
            $nonGoals = is_array($result['non_goals'] ?? null) ? $result['non_goals'] : [];
            foreach ($nonGoals as $key => $value) {
                if ($value !== false) {
                    $violations[] = "result_{$i}_non_goal_{$key}";
                }
            }
        }

        return $violations;
    }

    /**
     * Whether a lease timestamp has already passed relative to now.
     */
    private function leaseExpired(mixed $leaseExpiresAt, ?int $nowTs): bool
    {
        if (! is_string($leaseExpiresAt) || $leaseExpiresAt === '') {
            return false;
        }
        $ts = strtotime($leaseExpiresAt);
        if ($ts === false) {
            return false;
        }

        return $ts < ($nowTs ?? time());
    }

    /**
     * Whether a stored hash differs from the current hash (both present).
     */
    private function hashChanged(mixed $stored, mixed $current): bool
    {
        if (! is_string($stored) || ! is_string($current)) {
            return false;
        }

        return $stored !== $current;
    }

    /**
     * The ledger row whose packet_id matches the candidate, or null.
     *
     * @param array<int|string,mixed> $ledger
     * @return array<string,mixed>|null
     */
    private function rowForPacket(array $ledger, ?string $packetId): ?array
    {
        if ($packetId === null) {
            return null;
        }
        foreach ($ledger as $row) {
            if (is_array($row) && ((string) ($row['packet_id'] ?? '')) === $packetId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Allowed files that match any hot forbidden scope (equal to or under it),
     * preserving the order they appear in the allowed set, de-duplicated.
     *
     * @param list<string> $allowed
     * @param list<string> $hotScopes
     * @return list<string>
     */
    private function hotScopeMatches(array $allowed, array $hotScopes): array
    {
        $hits = [];
        foreach ($allowed as $file) {
            foreach ($hotScopes as $scope) {
                if ($scope !== '' && ($file === $scope || str_starts_with($file, $scope) || str_starts_with($file, rtrim($scope, '/').'/'))) {
                    $hits[] = $file;
                    break;
                }
            }
        }

        return array_values(array_unique($hits));
    }

}
