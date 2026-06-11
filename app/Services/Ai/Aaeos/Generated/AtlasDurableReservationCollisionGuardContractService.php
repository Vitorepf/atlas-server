<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Atlas Self-Construction Durable Reservation Collision Guard Contract — pure,
 * deterministic, READ-ONLY surface.
 *
 * This is the future guard that decides whether a single CANDIDATE packet can be
 * CLAIMED safely against the live reservation ledger. It is the single-candidate
 * sibling of the pairwise {@see AtlasCollisionMatrixContractService} (which
 * compares two packets); here one candidate is checked against every active
 * reservation projection, the hot forbidden scope and the completion gate.
 *
 * Per the doc, generating this guard packet is itself read-only: it cannot
 * persist claims, storage, migrations or dispatch. Every result therefore keeps
 * the four non-execution guarantee keys false — emitting a decision is never the
 * act of claiming.
 *
 * Documented rules this code ENFORCES (not merely documents):
 *   - "Blocking Decisions" => evaluate() actually computes all six documented
 *     blockers from the inputs:
 *       * hot_scope_forbidden    — any candidate file is in the hot forbidden
 *                                  Voice/Kernel scope list;
 *       * active_file_overlap    — any candidate file (allowed ∪ forbidden)
 *                                  intersects a currently-changed file owned by
 *                                  an active reservation;
 *       * packet_hash_stale      — candidate packet hash != the hash recorded at
 *                                  assignment (it changed underneath);
 *       * dependency_incomplete  — a required dependency id is not in the
 *                                  completed-dependency set;
 *       * completion_gate_blocked— completion gate status is not `green`;
 *       * owner_conflict         — an active lease over an overlapping file is
 *                                  owned by a DIFFERENT session.
 *   - "Required Outputs" => the packet carries decision, blocking_reasons,
 *     overlapping_files, active_reservation_ids, stale_hashes,
 *     dependency_blockers, hot_scope_matches and required_next_command.
 *   - The decision is deterministic and fail-CLOSED:
 *       * no blocker            => `allow_preview`;
 *       * any HARD blocker      => `block_claim` (hot scope, file overlap, stale
 *                                  hash, incomplete dependency);
 *       * ONLY soft/ambiguous   => `require_human_review` (owner conflict and/or
 *         blockers fire           completion-gate block, with no hard blocker) —
 *                                  these need a human to adjudicate contested
 *                                  ownership / evidence rather than a flat reject.
 *     A hard blocker always wins over a soft one (block_claim dominates).
 *   - Completion Criteria => a deterministic read-only packet that future claim
 *     code can implement without guessing blocking rules or review states, and
 *     assertGuaranteeHeld() proves no result flipped a guarantee key.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-collision-guard-contract.md
 */
final class AtlasDurableReservationCollisionGuardContractService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_durable_reservation_collision_guard_contract.v1';

    /** Surface label (closed set). */
    public const SURFACE = 'durable_reservation_collision_guard_contract';

    /** Decision: candidate is clean and may be previewed for claim. */
    public const DECISION_ALLOW_PREVIEW = 'allow_preview';

    /** Decision: a hard collision blocks the claim outright. */
    public const DECISION_BLOCK_CLAIM = 'block_claim';

    /** Decision: only ambiguous blockers fired — a human must adjudicate. */
    public const DECISION_REQUIRE_HUMAN_REVIEW = 'require_human_review';

    /**
     * Doc "Blocking Decisions" — the six blocker tokens, in documented order.
     *
     * @var list<string>
     */
    public const BLOCKING_DECISIONS = [
        'hot_scope_forbidden',
        'active_file_overlap',
        'packet_hash_stale',
        'dependency_incomplete',
        'completion_gate_blocked',
        'owner_conflict',
    ];

    /**
     * The blockers that force an outright `block_claim` — purely mechanical
     * collisions with no judgement call.
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
     * The blockers that, when they fire ALONE (no hard blocker), route to
     * `require_human_review` — contested ownership or completion-evidence
     * judgement that a human resolves.
     *
     * @var list<string>
     */
    public const SOFT_BLOCKERS = [
        'completion_gate_blocked',
        'owner_conflict',
    ];

    /** The only completion gate status that does not block. */
    public const COMPLETION_GATE_GREEN = 'green';

    /**
     * Non-execution guarantee keys (doc: collision guard generation is read-only
     * and cannot persist claims, storage, migrations or dispatch). Every result
     * keeps all of these false.
     *
     * @var list<string>
     */
    public const GUARANTEE_KEYS = [
        'claims_persisted',
        'storage_writes_performed',
        'migrations_created',
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
     * Primary surface: the read-only COLLISION GUARD packet for one candidate.
     *
     * Computes all six documented blockers against the supplied inputs, derives
     * the deterministic decision and emits every documented Required Output. No
     * claim, storage write, migration or dispatch is produced — the non-execution
     * guarantee holds on every path.
     *
     * @param array{
     *   candidate_packet_id?:string,
     *   candidate_allowed_files?:list<string>,
     *   candidate_forbidden_files?:list<string>,
     *   candidate_packet_hash?:string,
     *   assigned_packet_hash?:string,
     *   candidate_dependency_ids?:list<string>,
     *   completed_dependency_ids?:list<string>,
     *   active_reservations?:list<array{reservation_id?:string,owner_session_id?:string,changed_files?:list<string>}>,
     *   hot_forbidden_scope?:list<string>,
     *   current_session_id?:string,
     *   completion_gate_status?:string
     * } $input
     * @return array{
     *   surface:string, schema:string,
     *   candidate_packet_id:?string,
     *   decision:string,
     *   blocking_reasons:list<string>,
     *   overlapping_files:list<string>,
     *   active_reservation_ids:list<string>,
     *   stale_hashes:list<array{assigned:?string,candidate:?string}>,
     *   dependency_blockers:list<string>,
     *   hot_scope_matches:list<string>,
     *   required_next_command:string,
     *   guarantee:array<string,false>,
     *   claim_persisted:false, is_execution:false
     * }
     */
    public function evaluate(array $input = []): array
    {
        $candidateId = is_string($input['candidate_packet_id'] ?? null) ? $input['candidate_packet_id'] : null;

        $allowed = AtlasAaeosStringListNormalizer::nonEmptyStrings($input['candidate_allowed_files'] ?? null);
        $forbidden = AtlasAaeosStringListNormalizer::nonEmptyStrings($input['candidate_forbidden_files'] ?? null);
        $candidateFiles = array_values(array_unique(array_merge($allowed, $forbidden)));

        $hotScope = AtlasAaeosStringListNormalizer::nonEmptyStrings($input['hot_forbidden_scope'] ?? null);
        $candidateDeps = AtlasAaeosStringListNormalizer::nonEmptyStrings($input['candidate_dependency_ids'] ?? null);
        $completedDeps = AtlasAaeosStringListNormalizer::nonEmptyStrings($input['completed_dependency_ids'] ?? null);

        $currentSession = is_string($input['current_session_id'] ?? null) ? $input['current_session_id'] : null;
        $candidateHash = is_string($input['candidate_packet_hash'] ?? null) ? $input['candidate_packet_hash'] : null;
        $assignedHash = is_string($input['assigned_packet_hash'] ?? null) ? $input['assigned_packet_hash'] : null;

        $gate = is_string($input['completion_gate_status'] ?? null) ? $input['completion_gate_status'] : null;

        // --- hot_scope_forbidden -------------------------------------------------
        $hotMatches = array_values(array_intersect($candidateFiles, $hotScope));

        // --- active_file_overlap + owner_conflict + active_reservation_ids -------
        $overlappingFiles = [];
        $activeReservationIds = [];
        $ownerConflict = false;
        foreach ($this->reservations($input['active_reservations'] ?? null) as $reservation) {
            $resId = is_string($reservation['reservation_id'] ?? null) ? $reservation['reservation_id'] : null;
            if ($resId !== null) {
                $activeReservationIds[] = $resId;
            }

            $changed = AtlasAaeosStringListNormalizer::nonEmptyStrings($reservation['changed_files'] ?? null);
            $hit = array_values(array_intersect($candidateFiles, $changed));
            if ($hit === []) {
                continue;
            }

            // This reservation's changed files collide with the candidate.
            foreach ($hit as $file) {
                $overlappingFiles[] = $file;
            }

            // Owner conflict: the colliding lease is owned by ANOTHER session.
            $owner = is_string($reservation['owner_session_id'] ?? null) ? $reservation['owner_session_id'] : null;
            if ($owner !== null && $currentSession !== null && $owner !== $currentSession) {
                $ownerConflict = true;
            }
        }
        $overlappingFiles = array_values(array_unique($overlappingFiles));
        $activeReservationIds = array_values(array_unique($activeReservationIds));

        // --- packet_hash_stale ---------------------------------------------------
        // Stale only when we KNOW both hashes and they differ. (A missing assigned
        // hash is not asserted-stale here; absence is reported, not invented.)
        $hashStale = $candidateHash !== null && $assignedHash !== null && $candidateHash !== $assignedHash;
        $staleHashes = $hashStale
            ? [['assigned' => $assignedHash, 'candidate' => $candidateHash]]
            : [];

        // --- dependency_incomplete ----------------------------------------------
        $dependencyBlockers = array_values(array_diff($candidateDeps, $completedDeps));

        // --- completion_gate_blocked --------------------------------------------
        // Fail-closed: anything other than the explicit green status blocks.
        $gateBlocked = $gate !== self::COMPLETION_GATE_GREEN;

        // --- assemble fired blockers in documented order ------------------------
        $fired = [];
        if ($hotMatches !== []) {
            $fired[] = 'hot_scope_forbidden';
        }
        if ($overlappingFiles !== []) {
            $fired[] = 'active_file_overlap';
        }
        if ($hashStale) {
            $fired[] = 'packet_hash_stale';
        }
        if ($dependencyBlockers !== []) {
            $fired[] = 'dependency_incomplete';
        }
        if ($gateBlocked) {
            $fired[] = 'completion_gate_blocked';
        }
        if ($ownerConflict) {
            $fired[] = 'owner_conflict';
        }

        $decision = $this->decide($fired);

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'candidate_packet_id' => $candidateId,
            'decision' => $decision,
            'blocking_reasons' => $fired,
            'overlapping_files' => $overlappingFiles,
            'active_reservation_ids' => $activeReservationIds,
            'stale_hashes' => $staleHashes,
            'dependency_blockers' => $dependencyBlockers,
            'hot_scope_matches' => $hotMatches,
            'required_next_command' => $this->nextCommand($decision),
            'guarantee' => $this->guarantee(),
            // Restated per the doc's hard non-execution guarantee.
            'claim_persisted' => false,
            'is_execution' => false,
        ];
    }

    /**
     * Deterministic decision from the fired blockers (documented rule):
     *   - none fired                       => allow_preview;
     *   - any HARD blocker fired           => block_claim (dominates);
     *   - ONLY soft blocker(s) fired       => require_human_review.
     *
     * @param list<string> $fired
     */
    public function decide(array $fired): string
    {
        if ($fired === []) {
            return self::DECISION_ALLOW_PREVIEW;
        }

        foreach ($fired as $blocker) {
            if (in_array($blocker, self::HARD_BLOCKERS, true)) {
                return self::DECISION_BLOCK_CLAIM;
            }
        }

        // Only soft blockers remain (completion gate / owner conflict).
        return self::DECISION_REQUIRE_HUMAN_REVIEW;
    }

    /**
     * The required next command for each decision, so claim code never guesses
     * the review state (doc Required Output: "required next command").
     */
    public function nextCommand(string $decision): string
    {
        return match ($decision) {
            self::DECISION_ALLOW_PREVIEW => 'atlas:aaeos:durable-reservation-post-approval-preflight',
            self::DECISION_REQUIRE_HUMAN_REVIEW => 'atlas:aaeos:durable-reservation-approval-request',
            default => 'atlas:aaeos:durable-reservation-collision-guard-contract',
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
