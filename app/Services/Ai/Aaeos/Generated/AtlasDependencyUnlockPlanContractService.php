<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Self-Construction Dependency Unlock Plan — pure, deterministic, read-only planner.
 *
 * Shows how completing one packet WOULD change future work availability, without
 * mutating queue state. It answers, for a read-only packet queue:
 *   - which packets are blocked;
 *   - for each blocked packet, its dependencies;
 *   - which of those dependencies are already available (durable);
 *   - what would become preview-assignable after completion;
 *   - which work stays withheld (and why);
 *   - the unlock plan hash.
 *
 * Contract (from the doc Purpose + Unlock Rule + Non Goals + Completion Criteria):
 *   Entrada: phase ('preview' is the only phase that can produce candidates;
 *            'durable' is reserved for a future AP), packets[] (each
 *            {id, dependencies[]:string, lane?: cold|hot, withheld?: bool}),
 *            available[] (dependency ids that already have durable completion
 *            evidence). A dependency token prefixed/flagged as hot external is
 *            owned by another front and can never be satisfied by this cold lane.
 *   Saida:   schema_version, plan_id, phase, plan_hash,
 *            blocked_packets[] (each {id, dependencies[], available[], pending[],
 *              hot_blockers[], unlock_status}),
 *            unlock_candidates[] (ids that would become preview-assignable once
 *              their remaining cold dependencies complete),
 *            withheld_work[] (each {id, reason}),
 *            queue_mutated=false, durable_completion_written=false,
 *            dispatched=false.
 *
 * Documented rules this code genuinely enforces:
 *   - Unlock Rule: "A packet may become preview-assignable only when all
 *     dependencies have durable completion evidence in a future phase. In the
 *     current phase, Atlas may only preview the unlock relationship." =>
 *     unlock_status is at most `preview_unlockable` (never `assignable`) while
 *     phase='preview'; a candidate is reported as a PREVIEW, never dispatched.
 *   - Non Goal "Do not unlock hot external work" + decision "Hot withheld work
 *     must not be unlocked by Self-Construction cold-lane packets." => any packet
 *     whose lane is hot, or whose dependency set contains a hot external token,
 *     is forced to unlock_status=withheld_hot and listed in withheld_work; it is
 *     NEVER emitted as an unlock candidate, even if every cold dependency is met.
 *   - Purpose "which dependencies are already available" => each blocked packet
 *     splits its deps into available[] (in the durable available set) and
 *     pending[] (not yet available); a packet with zero pending COLD deps and no
 *     hot blocker is the only thing that becomes a candidate.
 *   - Non Goals (read-only): the plan NEVER marks dependencies complete, mutates
 *     queue state, persists completion, or dispatches newly unlocked work =>
 *     queue_mutated, durable_completion_written, dispatched are all false always.
 *   - Completion Criteria: the planner emits a deterministic read-only unlock
 *     plan (stable plan_hash for identical inputs) with blocked packets, unlock
 *     candidates and withheld hot work.
 *
 * @see docs/engineering-knowledge-base/self-construction/dependency-unlock-plan-contract.md
 */
final class AtlasDependencyUnlockPlanContractService
{
    /** Stable plan schema id this planner emits. */
    public const SCHEMA = 'atlas.self_construction_dependency_unlock_plan.v1';

    /** The only phase that can produce preview candidates. */
    public const PHASE_PREVIEW = 'preview';

    /** Reserved future phase where durable completion enables real assignment. */
    public const PHASE_DURABLE = 'durable';

    /** Per-blocked-packet unlock statuses (closed set). */
    public const UNLOCK_BLOCKED = 'blocked';            // still has pending cold deps
    public const UNLOCK_PREVIEW_UNLOCKABLE = 'preview_unlockable'; // all cold deps met (preview only)
    public const UNLOCK_WITHHELD_HOT = 'withheld_hot';  // hot lane / hot external dependency

    /** Packet lanes (closed set). Self-Construction owns the cold lane only. */
    public const LANE_COLD = 'cold';
    public const LANE_HOT = 'hot';

    /**
     * Build the read-only dependency unlock plan for a packet queue snapshot.
     *
     * @param array<string, mixed> $input
     * @return array{
     *   schema_version: string,
     *   plan_id: string,
     *   phase: string,
     *   plan_hash: string,
     *   blocked_packets: list<array{
     *     id: string,
     *     lane: string,
     *     dependencies: list<string>,
     *     available: list<string>,
     *     pending: list<string>,
     *     hot_blockers: list<string>,
     *     unlock_status: string
     *   }>,
     *   unlock_candidates: list<string>,
     *   withheld_work: list<array{id: string, reason: string}>,
     *   queue_mutated: bool,
     *   durable_completion_written: bool,
     *   dispatched: bool
     * }
     */
    public function plan(array $input): array
    {
        $phase = $this->normalisePhase($input['phase'] ?? self::PHASE_PREVIEW);
        $available = $this->stringSet($input['available'] ?? []);
        $packets = $this->packets($input['packets'] ?? []);

        $blocked = [];
        $candidates = [];
        $withheld = [];

        foreach ($packets as $packet) {
            $deps = $packet['dependencies'];

            // Split dependencies into already-available vs still-pending.
            $availableDeps = [];
            $pendingDeps = [];
            $hotBlockers = [];

            foreach ($deps as $dep) {
                if ($this->isHotExternalToken($dep)) {
                    // A hot external dependency is owned by another front; the
                    // cold lane can never satisfy it (Non Goal: do not unlock hot).
                    $hotBlockers[] = $dep;
                    continue;
                }
                if (isset($available[$dep])) {
                    $availableDeps[] = $dep;
                } else {
                    $pendingDeps[] = $dep;
                }
            }

            $laneHot = $packet['lane'] === self::LANE_HOT;
            $explicitlyWithheld = $packet['withheld'];
            $hasHotDependency = $hotBlockers !== [];

            // ---- Apply the Unlock Rule + hot-work Non Goal in strict precedence ----
            if ($laneHot || $hasHotDependency || $explicitlyWithheld) {
                // Hot withheld work must NEVER be unlocked by cold-lane packets.
                $status = self::UNLOCK_WITHHELD_HOT;
                $reason = $laneHot
                    ? 'hot_lane_owned_by_external_front'
                    : ($hasHotDependency
                        ? 'hot_external_dependency:' . $hotBlockers[0]
                        : 'explicitly_withheld');
                $withheld[] = ['id' => $packet['id'], 'reason' => $reason];
            } elseif ($pendingDeps === []) {
                // All COLD dependencies are durable-available. Per the Unlock Rule,
                // in the preview phase this is at most a PREVIEW relationship — it
                // is a candidate but is NEVER dispatched or marked assignable.
                $status = self::UNLOCK_PREVIEW_UNLOCKABLE;
                if ($phase === self::PHASE_PREVIEW) {
                    $candidates[] = $packet['id'];
                }
            } else {
                // Still has pending cold dependencies; stays blocked.
                $status = self::UNLOCK_BLOCKED;
            }

            $blocked[] = [
                'id' => $packet['id'],
                'lane' => $packet['lane'],
                'dependencies' => $deps,
                'available' => $availableDeps,
                'pending' => $pendingDeps,
                'hot_blockers' => $hotBlockers,
                'unlock_status' => $status,
            ];
        }

        $candidates = $this->dedupe($candidates);

        return [
            'schema_version' => self::SCHEMA,
            'plan_id' => $this->planId($phase, $blocked, $available),
            'phase' => $phase,
            'plan_hash' => $this->planHash($phase, $blocked, $candidates, $withheld),
            'blocked_packets' => $blocked,
            'unlock_candidates' => $candidates,
            'withheld_work' => $withheld,
            // Non Goals (read-only invariants) — never true in any phase here.
            'queue_mutated' => false,
            'durable_completion_written' => false,
            'dispatched' => false,
        ];
    }

    /**
     * Normalise the phase. Only an explicit 'durable' is honoured as durable;
     * anything else collapses to 'preview' so the planner never optimistically
     * promotes an ambiguous phase into real assignment.
     */
    private function normalisePhase(mixed $raw): string
    {
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        return $value === self::PHASE_DURABLE ? self::PHASE_DURABLE : self::PHASE_PREVIEW;
    }

    /**
     * A dependency token is "hot external" when it is explicitly flagged as such.
     * Recognised flags: a leading 'hot:' / 'hot-external:' prefix, or a token that
     * lives under an external runtime path the cold lane does not own.
     */
    private function isHotExternalToken(string $dep): bool
    {
        $lower = strtolower($dep);

        return str_starts_with($lower, 'hot:')
            || str_starts_with($lower, 'hot-external:')
            || str_starts_with($lower, 'external:');
    }

    /**
     * Parse the packet list into a strict shape, dropping malformed rows.
     *
     * @param mixed $raw
     * @return list<array{id: string, lane: string, dependencies: list<string>, withheld: bool}>
     */
    private function packets(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = isset($row['id']) && is_string($row['id']) ? trim($row['id']) : '';
            if ($id === '') {
                continue;
            }

            $lane = isset($row['lane']) && is_string($row['lane'])
                ? strtolower(trim($row['lane']))
                : self::LANE_COLD;
            if ($lane !== self::LANE_HOT) {
                $lane = self::LANE_COLD;
            }

            $out[] = [
                'id' => $id,
                'lane' => $lane,
                'dependencies' => AtlasAaeosStringListNormalizer::uniqueTrimmedStrings($row['dependencies'] ?? []),
                'withheld' => (bool) ($row['withheld'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * Deterministic plan id derived from phase + packet ids + available set, so
     * identical snapshots always produce the same id (read-only, no clock).
     *
     * @param list<array{id: string, lane: string, dependencies: list<string>, available: list<string>, pending: list<string>, hot_blockers: list<string>, unlock_status: string}> $blocked
     * @param array<string, true> $available
     */
    private function planId(string $phase, array $blocked, array $available): string
    {
        $ids = array_map(static fn (array $p): string => $p['id'], $blocked);
        $availKeys = array_keys($available);
        sort($ids);
        sort($availKeys);
        $seed = $phase . '|' . implode(',', $ids) . '|' . implode(',', $availKeys);
        $digest = substr(sha1($seed), 0, 4);

        return 'UNLOCK-PLAN-' . strtoupper($digest) . '-0001';
    }

    /**
     * Deterministic plan hash over the whole computed plan body. Identical inputs
     * (in any packet ORDER) yield the same hash — the Completion Criteria require
     * a deterministic read-only plan.
     *
     * @param list<array{id: string, lane: string, dependencies: list<string>, available: list<string>, pending: list<string>, hot_blockers: list<string>, unlock_status: string}> $blocked
     * @param list<string> $candidates
     * @param list<array{id: string, reason: string}> $withheld
     */
    private function planHash(string $phase, array $blocked, array $candidates, array $withheld): string
    {
        // Order-independent canonical projection of each blocked packet.
        $rows = array_map(static function (array $p): string {
            $deps = $p['dependencies'];
            $avail = $p['available'];
            $pending = $p['pending'];
            $hot = $p['hot_blockers'];
            sort($deps);
            sort($avail);
            sort($pending);
            sort($hot);

            return implode('~', [
                $p['id'],
                $p['lane'],
                $p['unlock_status'],
                implode(',', $deps),
                implode(',', $avail),
                implode(',', $pending),
                implode(',', $hot),
            ]);
        }, $blocked);
        sort($rows);

        $cands = $candidates;
        sort($cands);

        $withheldRows = array_map(
            static fn (array $w): string => $w['id'] . ':' . $w['reason'],
            $withheld,
        );
        sort($withheldRows);

        $seed = implode('|', [
            self::SCHEMA,
            $phase,
            implode(';', $rows),
            implode(',', $cands),
            implode(';', $withheldRows),
        ]);

        return 'sha256:' . hash('sha256', $seed);
    }

    /**
     * @param mixed $raw
     * @return array<string, true>
     */
    private function stringSet(mixed $raw): array
    {
        $out = [];
        foreach (AtlasAaeosStringListNormalizer::uniqueTrimmedStrings($raw) as $value) {
            $out[$value] = true;
        }

        return $out;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function dedupe(array $values): array
    {
        return array_values(array_unique($values));
    }
}
