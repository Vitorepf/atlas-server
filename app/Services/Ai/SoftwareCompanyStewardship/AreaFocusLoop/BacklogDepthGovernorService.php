<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * AP-810 / LHL-09 — Backlog Depth Governor (AP-806/809).
 *
 * Answers one question, honestly:
 *
 *   > Does the loop have enough REAL bounded work to run for the next 24h?
 *
 * It counts only admissible, canonical depth: canonical backlog parents and the
 * Self-Construction packets they decompose into. It EXCLUDES filler / recovery /
 * starvation items from depth — those are never "real work" and must never be
 * dressed up as runway. Factory / evolution proposals are counted ONLY once they
 * have been PROMOTED to a canonical finding with packets (input flag); raw,
 * un-promoted proposals do not count toward depth.
 *
 * When the admissible packet depth falls below the configured floor (default 10),
 * the governor reports status=below_floor and blocks_24h=true so the long-horizon
 * loop refuses to keep spending provider calls on an empty / starved backlog.
 *
 * This service is read-only / deterministic / input-seam driven. It NEVER invokes
 * a provider, NEVER runs the loop, NEVER merges, NEVER deletes a branch/worktree,
 * NEVER mutates code. It may read AreaFocusFactoryMaxCanonicalBacklogService's
 * output, but ONLY via an input seam — it does not instantiate it for data.
 *
 * Contract: AP-806/809; AP-810 build contract slice LHL-09.
 * Reference shape: AreaFocusFactoryMaxCanonicalBacklogService,
 * AreaFocusSelfConstructionAdmissionBridgeService.
 */
final class BacklogDepthGovernorService
{
    public const REPORT_SCHEMA = 'atlas.software_company_stewardship.loop_backlog_depth.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_BELOW_FLOOR = 'below_floor';

    /** Default minimum admissible packet depth required for a 24h run. */
    public const DEFAULT_FLOOR = 10;

    /** Canonical risk buckets reported in the risk distribution. */
    private const RISK_BUCKETS = ['low', 'medium', 'high', 'critical'];

    /**
     * Origin / kind markers that identify NON-admissible filler-or-recovery work.
     * These never count toward depth.
     */
    private const FILLER_RECOVERY_MARKERS = [
        'filler',
        'recovery',
        'starvation_recovery',
        'starvation',
        'missing_test_filler',
        'benchmark',
        'rivals',
    ];

    /**
     * Single entrypoint. Every key is optional; the diagnostic default analyzes an
     * empty backlog (which is, honestly, below the floor) and never crashes.
     *
     * Input seams (all optional, all overridable):
     *   - `canonical_parents`  : list of canonical backlog parent findings.
     *   - `self_construction_packets` : list of bounded packets.
     *   - `runtime_gap_matrix` : list of runtime gap items (additional canonical depth).
     *   - `blocker_reports`    : AP-807/808 blocker reports (locked/quarantined signals).
     *   - `factory_proposals`  : raw factory/evolution proposals (counted ONLY when promoted).
     *   - `floor`              : minimum admissible packet depth (default 10).
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function assess(array $input): array
    {
        // A wiring-phase `fixture` (from --fixture-file) may carry the whole input
        // bundle; fold it under the explicit input so direct keys still win.
        $input = AreaFocusLoopPayloadNormalizer::mergeFixture($input);

        $area = trim((string) ($input['area'] ?? 'agentic_engineering_os')) ?: 'agentic_engineering_os';
        $focus = trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge';

        $floor = $this->resolveFloor($input);

        $warnings = [];
        $blockers = [];

        // ---- Parents (canonical backlog parent findings) ---------------------
        $parentRows = $this->itemList($input['canonical_parents'] ?? ($input['parents'] ?? []));
        $admissibleParents = [];
        $excludedFiller = 0;
        foreach ($parentRows as $parent) {
            if ($this->isFillerOrRecovery($parent)) {
                $excludedFiller++;

                continue;
            }
            $admissibleParents[] = $parent;
        }

        // Runtime gap matrix items are additional canonical depth (parents-of-work)
        // unless they are filler.
        $gapRows = $this->itemList($input['runtime_gap_matrix'] ?? ($input['runtime_gaps'] ?? []));
        $admissibleGaps = [];
        foreach ($gapRows as $gap) {
            if ($this->isFillerOrRecovery($gap)) {
                $excludedFiller++;

                continue;
            }
            $admissibleGaps[] = $gap;
        }

        $parentsCount = count($admissibleParents) + count($admissibleGaps);

        // ---- Packets (bounded Self-Construction packets) ---------------------
        $packetRows = $this->itemList($input['self_construction_packets'] ?? ($input['packets'] ?? []));
        $admissiblePackets = [];
        $lockedCount = 0;
        $quarantinedCount = 0;
        foreach ($packetRows as $packet) {
            // NEGATIVE INVARIANT: filler / recovery / starvation packets are never
            // counted toward depth, even if otherwise well-formed.
            if ($this->isFillerOrRecovery($packet)) {
                $excludedFiller++;

                continue;
            }

            if ($this->isQuarantined($packet)) {
                $quarantinedCount++;

                // A quarantined packet is not runway; exclude from depth.
                continue;
            }
            if ($this->isLocked($packet)) {
                $lockedCount++;

                // Review-locked packets are not executable runway right now.
                continue;
            }

            $admissiblePackets[] = $packet;
        }

        // ---- Factory / evolution proposals: counted ONLY when promoted -------
        $proposalRows = $this->itemList($input['factory_proposals'] ?? ($input['evolution_proposals'] ?? []));
        $promotedProposalPackets = 0;
        $unpromotedProposals = 0;
        foreach ($proposalRows as $proposal) {
            if ($this->isFillerOrRecovery($proposal)) {
                $excludedFiller++;

                continue;
            }
            if (! $this->isPromotedToCanonical($proposal)) {
                // NEGATIVE INVARIANT: a raw factory/evolution idea is NOT depth until
                // it is promoted to a canonical finding + packets.
                $unpromotedProposals++;

                continue;
            }
            // A promoted proposal contributes its packets (default 1) and a parent.
            $promotedPackets = $this->promotedPacketCount($proposal);
            $promotedProposalPackets += $promotedPackets;
            $parentsCount++;
        }

        // Locked / quarantined counts may also arrive directly from blocker reports.
        $lockedCount += $this->countFromBlockerReports($input, ['locked', 'review_locked']);
        $quarantinedCount += $this->countFromBlockerReports($input, ['quarantined']);

        $packetsCount = count($admissiblePackets) + $promotedProposalPackets;

        // ---- Risk distribution over admissible packets -----------------------
        $riskDistribution = $this->riskDistribution($admissiblePackets, $proposalRows);

        // ---- Estimated useful cycles = admissible packet depth ---------------
        // Each admissible bounded packet is at most one useful provider cycle.
        $estimatedUsefulCycles = $packetsCount;

        if ($unpromotedProposals > 0) {
            $warnings[] = 'unpromoted_factory_proposals_excluded_from_depth';
        }
        if ($excludedFiller > 0) {
            $warnings[] = 'filler_or_recovery_excluded_from_depth';
        }
        if ($lockedCount > 0) {
            $warnings[] = 'review_locked_packets_excluded_from_runway';
        }
        if ($quarantinedCount > 0) {
            $warnings[] = 'quarantined_packets_excluded_from_runway';
        }

        $belowFloor = $packetsCount < $floor;
        if ($belowFloor) {
            // NEGATIVE INVARIANT: an empty / starved backlog must block the 24h run.
            // It is never dressed up as "ok" or as available recovery runway.
            $blockers[] = 'packet_depth_below_floor';
            $status = self::STATUS_BELOW_FLOOR;
            $blocks24h = true;
            $nextAction = 'stop_24h_until_backlog_replenished';
        } else {
            $status = self::STATUS_OK;
            $blocks24h = false;
            $nextAction = 'continue';
        }

        $payload = [
            'schema_version' => self::REPORT_SCHEMA,
            'ap_contract' => 'AP-806',
            'slice_id' => 'LHL-09',
            'status' => $status,
            'depth_id' => 'bdg_'.substr(MissionCanonicalHash::sha256([
                $area,
                $focus,
                $parentsCount,
                $packetsCount,
                $floor,
            ]), 0, 16),
            'area' => $area,
            'focus' => $focus,
            'checked_at' => AreaFocusUtcClock::atomNow(),
            'parents_count' => $parentsCount,
            'packets_count' => $packetsCount,
            'risk_distribution' => $riskDistribution,
            'locked_count' => $lockedCount,
            'quarantined_count' => $quarantinedCount,
            'estimated_useful_cycles' => $estimatedUsefulCycles,
            'excluded_filler_recovery_count' => $excludedFiller,
            'unpromoted_proposals_count' => $unpromotedProposals,
            'floor' => $floor,
            'blocks_24h' => $blocks24h,
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($blockers),
            'warnings' => AreaFocusStringListNormalizer::uniqueStringValues($warnings),
            'next_action' => $nextAction,
            'claim_policy' => [
                'read_only' => true,
                'runs_provider' => false,
                'runs_loop' => false,
                'runs_merge' => false,
                'deletes_branches' => false,
                'filler_recovery_counts_as_depth' => false,
                'unpromoted_proposals_count_as_depth' => false,
                'blocked_never_dressed_as_ready' => true,
            ],
        ];

        $payload['report_hash'] = 'sha256:'.MissionCanonicalHash::sha256(AreaFocusLoopPayloadNormalizer::withoutVolatileReportFields($payload));

        return $payload;
    }

    // ---------------------------------------------------------------- helpers

    private function resolveFloor(array $input): int
    {
        if (! array_key_exists('floor', $input) || $input['floor'] === null) {
            return self::DEFAULT_FLOOR;
        }
        $floor = (int) $input['floor'];

        return $floor > 0 ? $floor : self::DEFAULT_FLOOR;
    }

    /**
     * Is this row a filler / recovery / starvation / benchmark item that must never
     * be counted toward real backlog depth?
     *
     * @param  array<string,mixed>  $row
     */
    private function isFillerOrRecovery(array $row): bool
    {
        if ((bool) ($row['is_filler'] ?? false)
            || (bool) ($row['is_recovery'] ?? false)
            || (bool) ($row['is_starvation_recovery'] ?? false)
            || (bool) ($row['is_missing_test_filler'] ?? false)
            || (bool) ($row['is_benchmark'] ?? false)) {
            return true;
        }

        $haystacks = [
            strtolower((string) ($row['origin_type'] ?? '')),
            strtolower((string) ($row['source'] ?? '')),
            strtolower((string) ($row['kind'] ?? '')),
            strtolower((string) ($row['category'] ?? '')),
        ];
        foreach ($haystacks as $value) {
            if ($value === '') {
                continue;
            }
            foreach (self::FILLER_RECOVERY_MARKERS as $marker) {
                if ($value === $marker || str_contains($value, $marker)) {
                    return true;
                }
            }
        }

        // A missing_test kind with no strategic value is routine filler.
        $kind = strtolower((string) ($row['kind'] ?? ''));
        if ($kind === 'missing_test' && ! (bool) ($row['strategic_value'] ?? false)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function isLocked(array $packet): bool
    {
        if ((bool) ($packet['review_locked'] ?? false) || (bool) ($packet['locked'] ?? false)) {
            return true;
        }
        $state = strtolower((string) ($packet['lock_state'] ?? ($packet['status'] ?? '')));

        return in_array($state, ['locked', 'review_locked', 'review-locked'], true);
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function isQuarantined(array $packet): bool
    {
        if ((bool) ($packet['quarantined'] ?? false)) {
            return true;
        }
        $state = strtolower((string) ($packet['quarantine_state'] ?? ($packet['status'] ?? '')));

        return $state === 'quarantined';
    }

    /**
     * A factory / evolution proposal counts toward depth ONLY when promoted to a
     * canonical finding with packets.
     *
     * @param  array<string,mixed>  $proposal
     */
    private function isPromotedToCanonical(array $proposal): bool
    {
        if (array_key_exists('promoted_to_canonical', $proposal)) {
            return (bool) $proposal['promoted_to_canonical'];
        }
        if (array_key_exists('promoted', $proposal)) {
            return (bool) $proposal['promoted'];
        }
        $state = strtolower((string) ($proposal['promotion_state'] ?? ($proposal['status'] ?? '')));

        return in_array($state, ['promoted', 'canonical', 'promoted_to_canonical'], true);
    }

    /**
     * @param  array<string,mixed>  $proposal
     */
    private function promotedPacketCount(array $proposal): int
    {
        $packets = $this->itemList($proposal['packets'] ?? ($proposal['proposed_packets'] ?? []));
        if ($packets !== []) {
            return count($packets);
        }
        $declared = (int) ($proposal['packets_count'] ?? 0);

        return $declared > 0 ? $declared : 1;
    }

    /**
     * Build a canonical risk distribution over admissible packets (and any risk
     * declared on promoted proposals).
     *
     * @param  list<array<string,mixed>>  $packets
     * @param  list<array<string,mixed>>  $proposals
     * @return array<string,int>
     */
    private function riskDistribution(array $packets, array $proposals): array
    {
        $distribution = array_fill_keys(self::RISK_BUCKETS, 0);

        foreach ($packets as $packet) {
            $bucket = $this->riskBucket((string) ($packet['risk_level'] ?? ($packet['severity'] ?? 'medium')));
            $distribution[$bucket]++;
        }

        foreach ($proposals as $proposal) {
            if (! $this->isPromotedToCanonical($proposal) || $this->isFillerOrRecovery($proposal)) {
                continue;
            }
            $count = $this->promotedPacketCount($proposal);
            $bucket = $this->riskBucket((string) ($proposal['risk_level'] ?? ($proposal['severity'] ?? 'medium')));
            $distribution[$bucket] += $count;
        }

        return $distribution;
    }

    private function riskBucket(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, self::RISK_BUCKETS, true) ? $value : 'medium';
    }

    /**
     * Count rows in AP-807/808 blocker reports whose state matches any of $states.
     *
     * @param  array<string,mixed>  $input
     * @param  list<string>  $states
     */
    private function countFromBlockerReports(array $input, array $states): int
    {
        $count = 0;
        $reports = $this->itemList($input['blocker_reports'] ?? []);
        foreach ($reports as $report) {
            $rows = $this->itemList($report['blocked'] ?? ($report['items'] ?? []));
            foreach ($rows as $row) {
                $state = strtolower((string) ($row['state'] ?? ($row['status'] ?? '')));
                if (in_array($state, $states, true)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Normalize a seam value into a list of associative rows. Tolerates absent /
     * scalar / nested values so the method never crashes on malformed input.
     *
     * @param  mixed  $value
     * @return list<array<string,mixed>>
     */
    private function itemList($value): array
    {
        return AreaFocusLoopPayloadNormalizer::listOfArrays($value);
    }
}
