<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Atlas Self-Construction Reservation Ledger Contract — pure, deterministic,
 * READ-ONLY decider for the durable local packet reservation ledger.
 *
 * The Reservation Ledger is the durable local record that prevents two AI
 * sessions from owning the same packet at the same time. This service does NOT
 * store anything (durable storage is the file-backed
 * {@see \App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository});
 * it encodes the DOC's contract as pure decision logic so the storage layer,
 * commands and future Postgres promotion never have to guess the rules.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *
 *   - "State Rules" (the six-state machine) => effectiveState() resolves the
 *     durable state, demoting a `claimed` row whose lease has passed to
 *     `expired`, so an expired owner never reads as active. The states are the
 *     closed set: preview, claimed, released, expired, completed, blocked.
 *
 *   - "Collision Rules" (the SEVEN documented blockers) => evaluateClaim()
 *     actually computes every one from the inputs and fails CLOSED:
 *       * packet_already_claimed     — packet is claimed AND its lease is active;
 *       * packet_already_completed   — packet reached the terminal completed state;
 *       * allowed_files_overlap      — allowed files intersect ANOTHER active
 *                                      reservation's allowed files;
 *       * packet_hash_changed        — packet hash != the hash recorded at
 *                                      assignment;
 *       * split_hash_changed         — split hash != the hash recorded at
 *                                      assignment;
 *       * hot_external_scope         — a hot external scope path appears in the
 *                                      allowed files;
 *       * completion_gate_blocked    — the completion gate is not green.
 *     Any blocker => decision `blocked`; none => `allow_claim`.
 *
 *   - "Completion Criteria" / `--complete-packet` boundary => completion()
 *     returns a state transition whose authority flags are ALL false:
 *     completing a packet never approves code, merges changes, dispatches work,
 *     enables execution or marks the whole Self-Construction OS complete.
 *
 *   - "Local Durable Commands" => commands() returns the closed set of the five
 *     documented commands, and transitionAllowed() encodes which state changes
 *     are legal (e.g. a completed/released/expired row cannot be re-claimed
 *     without a fresh assignment; a non-active row cannot be completed).
 *
 * Every decision keeps the non-execution guarantee: deciding is never the act of
 * claiming, persisting, migrating or dispatching.
 *
 * @see docs/engineering-knowledge-base/self-construction/reservation-ledger-contract.md
 */
final class AtlasReservationLedgerContractService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_reservation_ledger_contract.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'reservation_ledger_contract';

    /** Doc "State Rules" — the closed six-state set, in documented order. */
    public const STATE_PREVIEW = 'preview';

    public const STATE_CLAIMED = 'claimed';

    public const STATE_RELEASED = 'released';

    public const STATE_EXPIRED = 'expired';

    public const STATE_COMPLETED = 'completed';

    public const STATE_BLOCKED = 'blocked';

    /**
     * The closed six-state set, in documented order.
     *
     * @var list<string>
     */
    public const STATES = [
        self::STATE_PREVIEW,
        self::STATE_CLAIMED,
        self::STATE_RELEASED,
        self::STATE_EXPIRED,
        self::STATE_COMPLETED,
        self::STATE_BLOCKED,
    ];

    /** Decision: candidate is clean and may be claimed. */
    public const DECISION_ALLOW_CLAIM = 'allow_claim';

    /** Decision: a collision rule blocks the claim. */
    public const DECISION_BLOCKED = 'blocked';

    /**
     * Doc "Collision Rules" — the seven blocker tokens, in documented order.
     *
     * @var list<string>
     */
    public const COLLISION_RULES = [
        'packet_already_claimed',
        'packet_already_completed',
        'allowed_files_overlap',
        'packet_hash_changed',
        'split_hash_changed',
        'hot_external_scope',
        'completion_gate_blocked',
    ];

    /**
     * Doc "Local Durable Commands" — the closed set of five durable commands.
     *
     * @var list<string>
     */
    public const COMMANDS = [
        '--reservation-status',
        '--claim-packet',
        '--claim-next-packet',
        '--complete-packet',
        '--release-packet',
    ];

    /** The only completion gate status that does not block a claim. */
    public const COMPLETION_GATE_GREEN = 'green';

    /**
     * Default hot external scope prefixes the doc forbids reserving ("Do not
     * reserve hot external scopes"). A path matches when it equals the prefix or
     * starts with it.
     *
     * @var list<string>
     */
    public const DEFAULT_HOT_SCOPES = [
        'runtimes/python/voice_realtime/',
        'app/Services/Ai/Voice/',
    ];

    /**
     * Authority flags that a `--complete-packet` transition must NEVER flip. Per
     * the doc, completion "persists packet state only. It does not grant
     * approval, dispatch work, enable execution or mark the whole
     * Self-Construction OS complete."
     *
     * @var list<string>
     */
    public const COMPLETION_AUTHORITY_KEYS = [
        'approval_granted',
        'changes_merged',
        'work_dispatched',
        'execution_enabled',
        'self_construction_os_complete',
    ];

    /**
     * The closed list of the five documented durable commands.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return self::COMMANDS;
    }

    /**
     * Resolve the EFFECTIVE durable state of a reservation row, applying the
     * documented State Rules. A `claimed` row whose lease has already passed is
     * demoted to `expired` ("expired means owner timed out"), so a timed-out
     * owner never reads as active. Unknown states fall back to `blocked`
     * (fail-closed). Terminal states are returned untouched.
     *
     * @param array{state?:string,lease_expires_at?:?string} $row
     */
    public function effectiveState(array $row, ?int $nowTs = null): string
    {
        $state = is_string($row['state'] ?? null) ? $row['state'] : self::STATE_PREVIEW;

        if (! in_array($state, self::STATES, true)) {
            return self::STATE_BLOCKED;
        }

        if ($state === self::STATE_CLAIMED && $this->leaseExpired($row['lease_expires_at'] ?? null, $nowTs)) {
            return self::STATE_EXPIRED;
        }

        return $state;
    }

    /**
     * A reservation actively holds a packet only while its effective state is
     * `claimed` (an expired lease has already been demoted away from claimed).
     *
     * @param array{state?:string,lease_expires_at?:?string} $row
     */
    public function isActive(array $row, ?int $nowTs = null): bool
    {
        return $this->effectiveState($row, $nowTs) === self::STATE_CLAIMED;
    }

    /**
     * Primary surface: evaluate whether a CANDIDATE packet may be claimed against
     * the live ledger, computing all seven documented Collision Rules. Pure and
     * fail-closed — emitting this decision never persists a claim.
     *
     * @param array{
     *   candidate_packet_id?:string,
     *   allowed_files?:list<string>,
     *   packet_hash?:string,
     *   split_hash?:string,
     *   assigned_packet_hash?:string,
     *   assigned_split_hash?:string,
     *   completion_gate_status?:string,
     *   hot_scopes?:list<string>,
     *   ledger?:array<int|string,array{packet_id?:string,state?:string,lease_expires_at?:?string,allowed_files?:list<string>}>
     * } $input
     * @return array{
     *   surface:string, schema:string,
     *   candidate_packet_id:?string,
     *   decision:string,
     *   blocking_reasons:list<string>,
     *   overlapping_files:list<string>,
     *   hot_scope_matches:list<string>,
     *   guarantee:array<string,false>,
     *   claim_persisted:false, is_execution:false
     * }
     */
    public function evaluateClaim(array $input = []): array
    {
        $candidateId = is_string($input['candidate_packet_id'] ?? null) ? $input['candidate_packet_id'] : null;
        $allowed = AtlasAaeosStringListNormalizer::nonEmptyStrings($input['allowed_files'] ?? null);
        $hotScopes = AtlasAaeosStringListNormalizer::nonEmptyStrings($input['hot_scopes'] ?? null) ?: self::DEFAULT_HOT_SCOPES;

        $packetHash = is_string($input['packet_hash'] ?? null) ? $input['packet_hash'] : null;
        $splitHash = is_string($input['split_hash'] ?? null) ? $input['split_hash'] : null;
        $assignedPacketHash = is_string($input['assigned_packet_hash'] ?? null) ? $input['assigned_packet_hash'] : null;
        $assignedSplitHash = is_string($input['assigned_split_hash'] ?? null) ? $input['assigned_split_hash'] : null;
        $gate = is_string($input['completion_gate_status'] ?? null) ? $input['completion_gate_status'] : null;

        $ledger = is_array($input['ledger'] ?? null) ? $input['ledger'] : [];

        $reasons = [];
        $overlapping = [];

        // Rule 1 + 2: the candidate's OWN row in the ledger — already claimed
        // (active lease) or already completed.
        $self = $this->rowForPacket($ledger, $candidateId);
        if ($self !== null && $this->isActive($self)) {
            $reasons[] = 'packet_already_claimed';
        }
        if ($self !== null && $this->effectiveState($self) === self::STATE_COMPLETED) {
            $reasons[] = 'packet_already_completed';
        }

        // Rule 3: allowed files overlap ANOTHER active reservation's allowed set
        // (disjoint write sets must be preserved).
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
            $hit = array_values(array_intersect($allowed, AtlasAaeosStringListNormalizer::nonEmptyStrings($row['allowed_files'] ?? null)));
            foreach ($hit as $file) {
                $overlapping[] = $file;
            }
        }
        $overlapping = array_values(array_unique($overlapping));
        if ($overlapping !== []) {
            $reasons[] = 'allowed_files_overlap';
        }

        // Rule 4: packet hash changed after assignment.
        if ($packetHash !== null && $assignedPacketHash !== null && $packetHash !== $assignedPacketHash) {
            $reasons[] = 'packet_hash_changed';
        }

        // Rule 5: split hash changed after assignment.
        if ($splitHash !== null && $assignedSplitHash !== null && $splitHash !== $assignedSplitHash) {
            $reasons[] = 'split_hash_changed';
        }

        // Rule 6: hot external scope appears in allowed files.
        $hotMatches = $this->hotScopeMatches($allowed, $hotScopes);
        if ($hotMatches !== []) {
            $reasons[] = 'hot_external_scope';
        }

        // Rule 7: completion gate is blocked (anything other than green).
        if ($gate !== null && $gate !== self::COMPLETION_GATE_GREEN) {
            $reasons[] = 'completion_gate_blocked';
        }

        // Keep reasons in documented order and de-duplicated.
        $reasons = array_values(array_filter(self::COLLISION_RULES, static fn (string $r): bool => in_array($r, $reasons, true)));

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'candidate_packet_id' => $candidateId,
            'decision' => $reasons === [] ? self::DECISION_ALLOW_CLAIM : self::DECISION_BLOCKED,
            'blocking_reasons' => $reasons,
            'overlapping_files' => $overlapping,
            'hot_scope_matches' => $hotMatches,
            'guarantee' => $this->guarantee(),
            'claim_persisted' => false,
            'is_execution' => false,
        ];
    }

    /**
     * The `--complete-packet` transition. Per the doc it "persists packet state
     * only" — so every governance authority flag is forced false. Completing a
     * row that is not actively claimed is rejected (you cannot complete a packet
     * you do not actively hold).
     *
     * @param array{state?:string,lease_expires_at?:?string} $row
     * @return array{
     *   surface:string,
     *   accepted:bool,
     *   from_state:string,
     *   to_state:string,
     *   reason:?string,
     *   evidence_hash:?string,
     *   authority:array<string,false>,
     *   is_execution:false
     * }
     */
    public function completion(array $row, string $reason = '', ?string $evidenceHash = null): array
    {
        $from = $this->effectiveState($row);
        $accepted = $from === self::STATE_CLAIMED;

        return [
            'surface' => self::SURFACE,
            'accepted' => $accepted,
            'from_state' => $from,
            'to_state' => $accepted ? self::STATE_COMPLETED : $from,
            'reason' => $accepted ? ($reason !== '' ? $reason : null) : 'reservation_not_active',
            'evidence_hash' => $accepted ? $evidenceHash : null,
            // Per the doc — completion grants NONE of these.
            'authority' => $this->completionAuthority(),
            'is_execution' => false,
        ];
    }

    /**
     * Whether a state transition driven by a documented command is legal. Encodes
     * the State Rules so the storage layer cannot, e.g., re-claim a completed
     * packet or complete one that is not active.
     */
    public function transitionAllowed(string $from, string $to): bool
    {
        if (! in_array($from, self::STATES, true) || ! in_array($to, self::STATES, true)) {
            return false;
        }

        return match ($to) {
            // A claim may originate only from an unheld state — a fresh preview,
            // or a row previously released/expired (then re-assigned). A
            // completed packet can never be reclaimed.
            self::STATE_CLAIMED => in_array($from, [self::STATE_PREVIEW, self::STATE_RELEASED, self::STATE_EXPIRED], true),
            // Release / complete / expire act only on an actively claimed row.
            self::STATE_RELEASED, self::STATE_COMPLETED, self::STATE_EXPIRED => $from === self::STATE_CLAIMED,
            // A claim attempt that hits a collision rule lands in blocked.
            self::STATE_BLOCKED => $from === self::STATE_PREVIEW,
            default => false,
        };
    }

    /**
     * The five completion authority keys, all forced false (doc boundary).
     *
     * @return array<string,false>
     */
    public function completionAuthority(): array
    {
        $out = [];
        foreach (self::COMPLETION_AUTHORITY_KEYS as $key) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * The non-execution guarantee keys, all forced false (deciding is read-only).
     *
     * @return array<string,false>
     */
    public function guarantee(): array
    {
        return [
            'claims_persisted' => false,
            'storage_writes_performed' => false,
            'migrations_created' => false,
            'dispatch_enabled' => false,
        ];
    }

    /**
     * Whether a lease timestamp has already passed relative to now.
     */
    private function leaseExpired(mixed $leaseExpiresAt, ?int $nowTs): bool
    {
        if (! is_string($leaseExpiresAt) || $leaseExpiresAt === '') {
            // No lease recorded => treat as expired (fail-closed: an unbounded
            // claim must not read as active forever).
            return true;
        }

        $ts = strtotime($leaseExpiresAt);
        if ($ts === false) {
            return true;
        }

        return $ts <= ($nowTs ?? time());
    }

    /**
     * Find the ledger row for a packet id (or null).
     *
     * @param array<int|string,mixed> $ledger
     * @return array<string,mixed>|null
     */
    private function rowForPacket(array $ledger, ?string $packetId): ?array
    {
        if ($packetId === null || $packetId === '') {
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
     * Allowed-file paths that fall inside a hot external scope.
     *
     * @param list<string> $files
     * @param list<string> $hotScopes
     * @return list<string>
     */
    private function hotScopeMatches(array $files, array $hotScopes): array
    {
        $matches = [];
        foreach ($files as $file) {
            foreach ($hotScopes as $scope) {
                if ($scope === '') {
                    continue;
                }
                if ($file === $scope || $file === rtrim($scope, '/') || str_starts_with($file, $scope)) {
                    $matches[] = $file;
                    break;
                }
            }
        }

        return array_values(array_unique($matches));
    }

}
