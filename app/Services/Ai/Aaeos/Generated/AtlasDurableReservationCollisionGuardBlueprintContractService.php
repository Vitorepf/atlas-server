<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Self-Construction Durable Reservation Collision Guard BLUEPRINT Contract
 * — pure, deterministic, READ-ONLY surface.
 *
 * This is the read-only blueprint EMITTER for the future durable-reservation
 * collision guard. Per the doc, generating this blueprint "is not runtime code
 * and must not create PHP files or persist claims": the contract is satisfied
 * when Atlas emits a deterministic read-only blueprint that future
 * implementation can convert into a guard service and tests "without guessing
 * blockers, outputs or decision states". So this service has two faithful jobs:
 *
 *   1. blueprint() — emit the documented shape verbatim: the eight Required
 *      Inputs, the six Required Blockers, the four Decision States, the seven
 *      Required Outputs and the seven Required Tests, fingerprinted by a stable
 *      blueprint hash so a regeneration can be diffed.
 *   2. evaluate() — the deterministic decision the future guard will run. Unlike
 *      its sibling {@see AtlasDurableReservationCollisionGuardContractService}
 *      (which only emits three decisions: allow_preview / block_claim /
 *      require_human_review), THIS blueprint contract carries the fourth
 *      documented Decision State `allow_claim`, and emits the doc's distinct
 *      seven-field Required Output shape (decision state, blocker code,
 *      human-readable reason, conflicting reservation ids, conflicting file
 *      paths, packet hash used for decision, guard hash).
 *
 * Documented rules this code ENFORCES (not merely documents):
 *   - "Required Blockers" => evaluate() computes all six documented blockers
 *     from the inputs, in documented order:
 *       * hot_scope_forbidden    — a candidate allowed file is in the hot
 *                                  forbidden scope (Voice/Kernel);
 *       * active_file_overlap    — a candidate allowed file collides with a file
 *                                  currently changed by an active reservation;
 *       * packet_hash_stale      — the candidate packet hash differs from the
 *                                  hash recorded at assignment;
 *       * dependency_incomplete  — packet dependency status is not `complete`;
 *       * completion_gate_blocked— packet completion gate status is not `green`;
 *       * owner_conflict         — an overlapping active lease is owned by a
 *                                  DIFFERENT session.
 *   - "Decision States" => evaluate() returns exactly one of the four documented
 *     states, fail-CLOSED:
 *       * any HARD blocker (hot scope / file overlap / stale hash / dependency)
 *         => `block_claim` (dominates everything);
 *       * else, if ONLY a soft/contested blocker fired (owner conflict and/or a
 *         non-green completion gate) => `require_human_review`;
 *       * else (no blocker) the candidate is clean — and we distinguish the two
 *         clean states the doc names:
 *           - if claim evidence is COMPLETE (packet hash known & matches the
 *             assigned hash, dependency status `complete`, completion gate
 *             `green`) => `allow_claim`;
 *           - otherwise the candidate is non-colliding but not yet provably
 *             claimable (e.g. the assigned hash is unknown) => `allow_preview`.
 *     A hard blocker always wins; allow_claim is only ever reached with zero
 *     blockers AND complete positive evidence.
 *   - "Required Outputs" => every evaluate() result carries the documented seven
 *     fields: decision, blocker_code (the single primary/most-severe blocker, or
 *     null), reason (human-readable), conflicting_reservation_ids,
 *     conflicting_file_paths, packet_hash_used and guard_hash.
 *   - Completion Criteria => blueprint() is deterministic and read-only, and
 *     assertGuaranteeHeld() proves no result flipped a non-execution guarantee.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-blueprint-contract.md
 */
final class AtlasDurableReservationCollisionGuardBlueprintContractService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_collision_guard_blueprint_contract.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_collision_guard_blueprint_contract';

    // --- Doc "Decision States" (exactly four) -------------------------------
    public const DECISION_ALLOW_PREVIEW = 'allow_preview';
    public const DECISION_ALLOW_CLAIM = 'allow_claim';
    public const DECISION_BLOCK_CLAIM = 'block_claim';
    public const DECISION_REQUIRE_HUMAN_REVIEW = 'require_human_review';

    /**
     * Doc "Decision States" — the four states, in documented order.
     *
     * @var list<string>
     */
    public const DECISION_STATES = [
        self::DECISION_ALLOW_PREVIEW,
        self::DECISION_ALLOW_CLAIM,
        self::DECISION_BLOCK_CLAIM,
        self::DECISION_REQUIRE_HUMAN_REVIEW,
    ];

    /**
     * Doc "Required Inputs" — the eight inputs, in documented order. Emitted by
     * the blueprint so future implementation never invents an input.
     *
     * @var list<string>
     */
    public const REQUIRED_INPUTS = [
        'candidate_packet_id',
        'candidate_packet_hash',
        'candidate_allowed_files',
        'current_active_reservations',
        'current_changed_files',
        'hot_forbidden_scopes',
        'packet_dependency_status',
        'packet_completion_gate_status',
    ];

    /**
     * Doc "Required Blockers" — the six blocker codes, in documented order.
     *
     * @var list<string>
     */
    public const REQUIRED_BLOCKERS = [
        'hot_scope_forbidden',
        'active_file_overlap',
        'packet_hash_stale',
        'dependency_incomplete',
        'completion_gate_blocked',
        'owner_conflict',
    ];

    /**
     * The blockers that force an outright `block_claim` — mechanical collisions
     * with no judgement call.
     *
     * @var list<string>
     */
    public const HARD_BLOCKERS = [
        'hot_scope_forbidden',
        'active_file_overlap',
        'packet_hash_stale',
        'dependency_incomplete',
    ];

    /**
     * The blockers that, firing ALONE (no hard blocker), route to
     * `require_human_review` — contested ownership or completion evidence.
     *
     * @var list<string>
     */
    public const SOFT_BLOCKERS = [
        'completion_gate_blocked',
        'owner_conflict',
    ];

    /**
     * Doc "Required Outputs" — the seven output field names, in documented
     * order. Emitted by the blueprint so future implementation never drops a
     * required field.
     *
     * @var list<string>
     */
    public const REQUIRED_OUTPUTS = [
        'decision',
        'blocker_code',
        'reason',
        'conflicting_reservation_ids',
        'conflicting_file_paths',
        'packet_hash_used',
        'guard_hash',
    ];

    /**
     * Doc "Required Tests" — the seven test obligations, verbatim intent. The
     * blueprint emits these so a future test suite can be generated 1:1.
     *
     * @var list<string>
     */
    public const REQUIRED_TESTS = [
        'hot_voice_kernel_scope_is_blocked',
        'overlapping_active_file_scope_is_blocked',
        'stale_packet_hash_is_blocked',
        'incomplete_dependency_is_blocked',
        'clean_disjoint_packet_can_be_claimable',
        'guard_explains_conflicting_reservation_ids_and_file_paths',
        'blueprint_command_does_not_create_php_files_or_write_storage',
    ];

    /** The only dependency status that does not block. */
    public const DEPENDENCY_COMPLETE = 'complete';

    /** The only completion gate status that does not block. */
    public const COMPLETION_GATE_GREEN = 'green';

    /**
     * Non-execution guarantee keys (doc: blueprint generation is read-only and
     * "cannot create runtime files, write storage, persist claims or dispatch
     * work"). Every result keeps all of these false.
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
     * Primary surface: the read-only collision-guard BLUEPRINT. Emits the
     * documented inputs, blockers, decision states, outputs and tests verbatim,
     * plus a deterministic blueprint hash. Produces no PHP file, no storage
     * write, no claim and no dispatch — the non-execution guarantee holds.
     *
     * @return array{
     *   surface:string, schema:string,
     *   required_inputs:list<string>,
     *   required_blockers:list<string>,
     *   hard_blockers:list<string>,
     *   soft_blockers:list<string>,
     *   decision_states:list<string>,
     *   required_outputs:list<string>,
     *   required_tests:list<string>,
     *   dependency_complete_value:string,
     *   completion_gate_green_value:string,
     *   blueprint_hash:string,
     *   guarantee:array<string,false>,
     *   claim_persisted:false, is_execution:false
     * }
     */
    public function blueprint(): array
    {
        $body = [
            'required_inputs' => self::REQUIRED_INPUTS,
            'required_blockers' => self::REQUIRED_BLOCKERS,
            'hard_blockers' => self::HARD_BLOCKERS,
            'soft_blockers' => self::SOFT_BLOCKERS,
            'decision_states' => self::DECISION_STATES,
            'required_outputs' => self::REQUIRED_OUTPUTS,
            'required_tests' => self::REQUIRED_TESTS,
            'dependency_complete_value' => self::DEPENDENCY_COMPLETE,
            'completion_gate_green_value' => self::COMPLETION_GATE_GREEN,
        ];

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            ...$body,
            'blueprint_hash' => $this->stableHash($body),
            'guarantee' => $this->guarantee(),
            // Restated per the doc's hard non-execution guarantee.
            'claim_persisted' => false,
            'is_execution' => false,
        ];
    }

    /**
     * The deterministic decision the future guard will run for ONE candidate.
     * Computes all six documented blockers and emits the documented seven-field
     * Required Output. Read-only: no claim, storage write, file or dispatch.
     *
     * @param array{
     *   candidate_packet_id?:string,
     *   candidate_packet_hash?:string,
     *   assigned_packet_hash?:string,
     *   candidate_allowed_files?:list<string>,
     *   active_reservations?:list<array{reservation_id?:string,owner_session_id?:string,changed_files?:list<string>}>,
     *   hot_forbidden_scopes?:list<string>,
     *   current_session_id?:string,
     *   packet_dependency_status?:string,
     *   packet_completion_gate_status?:string
     * } $input
     * @return array{
     *   surface:string, schema:string,
     *   candidate_packet_id:?string,
     *   decision:string,
     *   blocker_code:?string,
     *   reason:string,
     *   blocking_reasons:list<string>,
     *   conflicting_reservation_ids:list<string>,
     *   conflicting_file_paths:list<string>,
     *   packet_hash_used:?string,
     *   guard_hash:string,
     *   guarantee:array<string,false>,
     *   claim_persisted:false, is_execution:false
     * }
     */
    public function evaluate(array $input = []): array
    {
        $candidateId = $this->str($input['candidate_packet_id'] ?? null);
        $allowed = $this->stringList($input['candidate_allowed_files'] ?? null);
        $hotScope = $this->stringList($input['hot_forbidden_scopes'] ?? null);

        $candidateHash = $this->str($input['candidate_packet_hash'] ?? null);
        $assignedHash = $this->str($input['assigned_packet_hash'] ?? null);

        $currentSession = $this->str($input['current_session_id'] ?? null);
        $dependencyStatus = $this->str($input['packet_dependency_status'] ?? null);
        $gateStatus = $this->str($input['packet_completion_gate_status'] ?? null);

        // --- hot_scope_forbidden -------------------------------------------
        $hotMatches = array_values(array_intersect($allowed, $hotScope));

        // --- active_file_overlap + owner_conflict + conflicting ids --------
        $conflictingFiles = [];
        $conflictingReservationIds = [];
        $ownerConflict = false;
        foreach ($this->reservations($input['active_reservations'] ?? null) as $reservation) {
            $changed = $this->stringList($reservation['changed_files'] ?? null);
            $hit = array_values(array_intersect($allowed, $changed));
            if ($hit === []) {
                continue;
            }

            // This reservation's changed files collide with the candidate.
            $resId = $this->str($reservation['reservation_id'] ?? null);
            if ($resId !== null) {
                $conflictingReservationIds[] = $resId;
            }
            foreach ($hit as $file) {
                $conflictingFiles[] = $file;
            }

            // Owner conflict: the colliding lease is owned by ANOTHER session.
            $owner = $this->str($reservation['owner_session_id'] ?? null);
            if ($owner !== null && $currentSession !== null && $owner !== $currentSession) {
                $ownerConflict = true;
            }
        }
        $conflictingFiles = array_values(array_unique($conflictingFiles));
        $conflictingReservationIds = array_values(array_unique($conflictingReservationIds));

        // --- packet_hash_stale ---------------------------------------------
        // Stale only when both hashes are known and they differ.
        $hashStale = $candidateHash !== null && $assignedHash !== null && $candidateHash !== $assignedHash;
        $hashFresh = $candidateHash !== null && $assignedHash !== null && $candidateHash === $assignedHash;

        // --- dependency_incomplete -----------------------------------------
        // Fail-closed: anything other than the explicit complete status blocks.
        $dependencyIncomplete = $dependencyStatus !== self::DEPENDENCY_COMPLETE;

        // --- completion_gate_blocked ---------------------------------------
        // Fail-closed: anything other than the explicit green status blocks.
        $gateBlocked = $gateStatus !== self::COMPLETION_GATE_GREEN;

        // --- assemble fired blockers in documented order -------------------
        $fired = [];
        if ($hotMatches !== []) {
            $fired[] = 'hot_scope_forbidden';
        }
        if ($conflictingFiles !== []) {
            $fired[] = 'active_file_overlap';
        }
        if ($hashStale) {
            $fired[] = 'packet_hash_stale';
        }
        if ($dependencyIncomplete) {
            $fired[] = 'dependency_incomplete';
        }
        if ($gateBlocked) {
            $fired[] = 'completion_gate_blocked';
        }
        if ($ownerConflict) {
            $fired[] = 'owner_conflict';
        }

        // Claim evidence is COMPLETE only when there is positive proof on every
        // axis: hash known & matching, dependency complete, gate green.
        $claimEvidenceComplete = $hashFresh && ! $dependencyIncomplete && ! $gateBlocked;

        $decision = $this->decide($fired, $claimEvidenceComplete);
        $blockerCode = $this->primaryBlocker($fired);

        // Output: the single packet hash the decision was actually taken on.
        $packetHashUsed = $candidateHash;

        $output = [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'candidate_packet_id' => $candidateId,
            'decision' => $decision,
            'blocker_code' => $blockerCode,
            'reason' => $this->reason($decision, $blockerCode),
            'blocking_reasons' => $fired,
            'conflicting_reservation_ids' => $conflictingReservationIds,
            'conflicting_file_paths' => $conflictingFiles,
            'packet_hash_used' => $packetHashUsed,
            'guarantee' => $this->guarantee(),
            'claim_persisted' => false,
            'is_execution' => false,
        ];

        // Guard hash binds the decision to the full evaluated packet scope
        // (doc Required Output "guard hash"). Computed last over the decision
        // body so identical inputs always yield an identical, diffable hash.
        $output['guard_hash'] = $this->stableHash([
            'candidate_packet_id' => $candidateId,
            'decision' => $decision,
            'blocker_code' => $blockerCode,
            'blocking_reasons' => $fired,
            'conflicting_reservation_ids' => $conflictingReservationIds,
            'conflicting_file_paths' => $conflictingFiles,
            'packet_hash_used' => $packetHashUsed,
        ]);

        return $output;
    }

    /**
     * Deterministic decision from the fired blockers (documented rule):
     *   - any HARD blocker fired                 => block_claim (dominates);
     *   - ONLY soft blocker(s) fired             => require_human_review;
     *   - none fired AND claim evidence complete => allow_claim;
     *   - none fired but evidence incomplete     => allow_preview.
     *
     * @param list<string> $fired
     */
    public function decide(array $fired, bool $claimEvidenceComplete): string
    {
        foreach ($fired as $blocker) {
            if (in_array($blocker, self::HARD_BLOCKERS, true)) {
                return self::DECISION_BLOCK_CLAIM;
            }
        }

        if ($fired !== []) {
            // Only soft blockers remain (completion gate / owner conflict).
            return self::DECISION_REQUIRE_HUMAN_REVIEW;
        }

        // No blocker fired: clean. Claimable only with complete positive proof.
        return $claimEvidenceComplete
            ? self::DECISION_ALLOW_CLAIM
            : self::DECISION_ALLOW_PREVIEW;
    }

    /**
     * The single primary blocker code for the Required Output `blocker_code`:
     * the most-severe fired blocker in documented order (hard blockers first,
     * then soft), or null when none fired.
     *
     * @param list<string> $fired
     */
    public function primaryBlocker(array $fired): ?string
    {
        foreach (self::REQUIRED_BLOCKERS as $blocker) {
            if (in_array($blocker, $fired, true)) {
                return $blocker;
            }
        }

        return null;
    }

    /**
     * Human-readable reason for the Required Output `reason`.
     */
    public function reason(string $decision, ?string $blockerCode): string
    {
        return match ($decision) {
            self::DECISION_ALLOW_CLAIM => 'candidate is disjoint and claim evidence is complete; claim allowed',
            self::DECISION_ALLOW_PREVIEW => 'candidate is disjoint but claim evidence is incomplete; preview only',
            self::DECISION_BLOCK_CLAIM => 'hard collision ('.($blockerCode ?? 'unknown').') blocks the claim',
            self::DECISION_REQUIRE_HUMAN_REVIEW => 'contested blocker ('.($blockerCode ?? 'unknown').') needs human adjudication',
            default => 'unknown decision',
        };
    }

    /**
     * Prove that no result flipped a non-execution guarantee key (or the two
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
                if (! array_key_exists($key, $guarantee) || $guarantee[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }

            foreach (['claim_persisted', 'is_execution'] as $extra) {
                if (array_key_exists($extra, $result) && $result[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }

    /**
     * Deterministic content hash over a body (stable key order).
     *
     * @param array<string,mixed> $body
     */
    private function stableHash(array $body): string
    {
        ksort($body);
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '';
        }

        return hash('sha256', $json);
    }

    /**
     * Normalise a value into a non-empty string or null.
     */
    private function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Normalise a value into a list of non-empty strings.
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

        return $out;
    }

    /**
     * Normalise the active reservation projections into a list of arrays.
     *
     * @return list<array<string,mixed>>
     */
    private function reservations(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }
}
