<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Converts final_95 gap reports into an ordered burn-down schedule with blockers, owner subsystem,
 * cheapest next proof, and stop conditions.
 *
 * PREFERENCE ORDER (never creates new feature work when a cheaper approach is available):
 *   evidence_backfill > proof_replay > consolidation > doc_sync > integration_wiring
 *   > unblock_dependency > new_feature_work
 *
 * GAP TYPE PRIORITY (1 = close first):
 *   blocked (1) > missing (2) > thin (3) > stale (4)
 *   > integration_debt (5) > doc_drift (6) > weak_outcome_learning (7)
 *
 * INPUT: list<gap_report>
 *   gap_report:
 *     organ_id:                string
 *     gap_type:                'blocked'|'missing'|'thin'|'stale'
 *                              |'integration_debt'|'doc_drift'|'weak_outcome_learning'
 *     owner_subsystem?:        string   (default 'unknown')
 *     blocker?:                string   (only meaningful for gap_type='blocked')
 *     can_evidence_backfill?:  bool    (default false)
 *     can_proof_replay?:       bool    (default false)
 *     can_consolidate?:        bool    (default false)
 *     can_doc_sync?:           bool    (default false)
 *     can_integration_wiring?: bool    (default false)
 *     depends_on?:             list<string>  (organ_ids that must close first; default [])
 *     unlocks?:                list<string>  (organ_ids this gap unblocks; default [])
 *     impact_score?:           float   (default 0.5)
 *     effort_score?:           float   (default 0.5)
 *     evidence_age_hours?:     float   (default 0.0)
 *
 * DEPENDENCY ORDERING (dominates nominal impact):
 *   A gap with unresolved depends_on never ranks before the upstream gaps it depends on,
 *   even when its impact_score is nominally higher. Ordering is a topological sort
 *   (Kahn's algorithm) over the depends_on/unlocks graph; gap_type priority and
 *   (impact_score - effort_score + evidence_age_hours/1000) only break ties among gaps
 *   that are simultaneously ready (no unresolved dependency).
 *
 * OUTPUT:
 *   { schema, burn_down_schedule, dependency_chains, total_gaps,
 *     gaps_closeable_without_new_feature_work, next_batch_recommendation }
 *
 *   burn_down_schedule: list<{
 *     organ_id, gap_type, priority_rank, owner_subsystem,
 *     resolution_approach, cheapest_next_proof, stop_condition, blocker,
 *     depends_on, unlocks, impact_score, effort_score, evidence_age_hours
 *   }>
 *   dependency_chains: list<{organ_id, depends_on, unlocks}>  (only entries with edges)
 *   total_gaps: int
 *   gaps_closeable_without_new_feature_work: int
 *   next_batch_recommendation: string
 *
 * PURE / DETERMINISTIC. No I/O.
 */
final class AtlasExternalBrainFinal95GapBurnDownScheduler
{
    public const SCHEMA = 'atlas.external_brain.final_95_gap_burn_down_scheduler.v1';

    public const APPROACH_EVIDENCE_BACKFILL    = 'evidence_backfill';
    public const APPROACH_PROOF_REPLAY         = 'proof_replay';
    public const APPROACH_CONSOLIDATION        = 'consolidation';
    public const APPROACH_DOC_SYNC             = 'doc_sync';
    public const APPROACH_INTEGRATION_WIRING   = 'integration_wiring';
    public const APPROACH_UNBLOCK_DEPENDENCY   = 'unblock_dependency';
    public const APPROACH_NEW_FEATURE_WORK     = 'new_feature_work';

    private const GAP_PRIORITY = [
        'blocked'               => 1,
        'missing'               => 2,
        'thin'                  => 3,
        'stale'                 => 4,
        'integration_debt'      => 5,
        'doc_drift'             => 6,
        'weak_outcome_learning' => 7,
    ];

    /**
     * @param  list<array<string,mixed>>  $gaps
     * @return array<string,mixed>
     */
    public function schedule(array $gaps): array
    {
        $entries = [];

        foreach ($gaps as $gap) {
            if (! is_array($gap) || ! isset($gap['organ_id'])) {
                continue;
            }

            $organId     = (string) $gap['organ_id'];
            $gapType     = (string) ($gap['gap_type'] ?? 'missing');
            $ownerSub    = (string) ($gap['owner_subsystem'] ?? 'unknown');
            $blocker              = isset($gap['blocker']) ? (string) $gap['blocker'] : null;
            $canBackfill          = (bool) ($gap['can_evidence_backfill']  ?? false);
            $canReplay            = (bool) ($gap['can_proof_replay']        ?? false);
            $canConsolidate       = (bool) ($gap['can_consolidate']         ?? false);
            $canDocSync           = (bool) ($gap['can_doc_sync']            ?? false);
            $canIntegrationWiring = (bool) ($gap['can_integration_wiring'] ?? false);
            $dependsOn            = is_array($gap['depends_on'] ?? null) ? array_map('strval', $gap['depends_on']) : [];
            $unlocks              = is_array($gap['unlocks'] ?? null) ? array_map('strval', $gap['unlocks']) : [];
            $impactScore          = (float) ($gap['impact_score'] ?? 0.5);
            $effortScore          = (float) ($gap['effort_score'] ?? 0.5);
            $evidenceAgeHours     = (float) ($gap['evidence_age_hours'] ?? 0.0);

            $priority = self::GAP_PRIORITY[$gapType] ?? 99;
            $approach = $this->resolveApproach($gapType, $canBackfill, $canReplay, $canConsolidate, $canDocSync, $canIntegrationWiring);

            $entries[$organId] = [
                'organ_id'            => $organId,
                'gap_type'            => $gapType,
                '_priority'           => $priority,
                '_score'              => $impactScore - $effortScore + ($evidenceAgeHours / 1000.0),
                'owner_subsystem'     => $ownerSub,
                'resolution_approach' => $approach,
                'cheapest_next_proof' => $this->proofCommand($approach, $organId, $ownerSub),
                'stop_condition'      => $this->stopCondition($approach),
                'blocker'             => $blocker,
                'depends_on'          => $dependsOn,
                'unlocks'             => $unlocks,
                'impact_score'        => $impactScore,
                'effort_score'        => $effortScore,
                'evidence_age_hours'  => $evidenceAgeHours,
            ];
        }

        $order = $this->topologicalOrder($entries);

        $schedule   = [];
        $chains     = [];
        $nonFeature = 0;
        foreach ($order as $rank => $organId) {
            $entry        = $entries[$organId];
            $isNonFeature = $entry['resolution_approach'] !== self::APPROACH_NEW_FEATURE_WORK;
            if ($isNonFeature) {
                $nonFeature++;
            }
            $schedule[] = [
                'organ_id'            => $entry['organ_id'],
                'gap_type'            => $entry['gap_type'],
                'priority_rank'       => $rank + 1,
                'owner_subsystem'     => $entry['owner_subsystem'],
                'resolution_approach' => $entry['resolution_approach'],
                'cheapest_next_proof' => $entry['cheapest_next_proof'],
                'stop_condition'      => $entry['stop_condition'],
                'blocker'             => $entry['blocker'],
                'depends_on'          => $entry['depends_on'],
                'unlocks'             => $entry['unlocks'],
                'impact_score'        => $entry['impact_score'],
                'effort_score'        => $entry['effort_score'],
                'evidence_age_hours'  => $entry['evidence_age_hours'],
            ];

            if ($entry['depends_on'] !== [] || $entry['unlocks'] !== []) {
                $chains[] = [
                    'organ_id'   => $entry['organ_id'],
                    'depends_on' => $entry['depends_on'],
                    'unlocks'    => $entry['unlocks'],
                ];
            }
        }

        $nextBatch = $schedule === []
            ? 'no_gaps_to_schedule'
            : sprintf(
                'work %s next via %s (rank 1/%d); unlocks: %s',
                $schedule[0]['organ_id'],
                $schedule[0]['resolution_approach'],
                count($schedule),
                $schedule[0]['unlocks'] === [] ? 'none' : implode(', ', $schedule[0]['unlocks']),
            );

        return [
            'schema'                                 => self::SCHEMA,
            'burn_down_schedule'                     => $schedule,
            'dependency_chains'                      => $chains,
            'total_gaps'                             => count($schedule),
            'gaps_closeable_without_new_feature_work' => $nonFeature,
            'next_batch_recommendation'               => $nextBatch,
        ];
    }

    /**
     * Kahn's algorithm: dependency edges (depends_on/unlocks) always dominate ordering.
     * Among simultaneously-ready gaps (no unresolved dependency), gap_type priority then
     * nominal score then organ_id break ties. Any residual cycle is appended deterministically.
     *
     * @param  array<string,array<string,mixed>>  $entries  keyed by organ_id
     * @return list<string>  organ_ids in schedule order
     */
    private function topologicalOrder(array $entries): array
    {
        $inDegree = [];
        $downstream = [];
        foreach ($entries as $organId => $entry) {
            $inDegree[$organId] ??= 0;
            foreach ($entry['depends_on'] as $upstream) {
                if (! isset($entries[$upstream])) {
                    continue;
                }
                $inDegree[$organId]++;
                $downstream[$upstream][] = $organId;
            }
            foreach ($entry['unlocks'] as $downstreamId) {
                if (! isset($entries[$downstreamId])) {
                    continue;
                }
                $downstream[$organId][] = $downstreamId;
                $inDegree[$downstreamId] = ($inDegree[$downstreamId] ?? 0) + 1;
            }
        }

        $compareKey = function (string $organId) use ($entries): array {
            return [$entries[$organId]['_priority'], -$entries[$organId]['_score'], $organId];
        };

        $ready = array_filter(array_keys($entries), static fn (string $id): bool => $inDegree[$id] === 0);
        usort($ready, static fn (string $a, string $b) => $compareKey($a) <=> $compareKey($b));

        $order = [];
        $remainingDegree = $inDegree;
        while ($ready !== []) {
            $current = array_shift($ready);
            $order[] = $current;

            foreach ($downstream[$current] ?? [] as $next) {
                $remainingDegree[$next]--;
                if ($remainingDegree[$next] === 0) {
                    $ready[] = $next;
                }
            }
            usort($ready, static fn (string $a, string $b) => $compareKey($a) <=> $compareKey($b));
        }

        // Residual cycle members (never reached in_degree 0): append deterministically.
        $leftover = array_diff(array_keys($entries), $order);
        if ($leftover !== []) {
            usort($leftover, static fn (string $a, string $b) => $compareKey($a) <=> $compareKey($b));
            array_push($order, ...$leftover);
        }

        return $order;
    }

    private function resolveApproach(
        string $gapType,
        bool $canBackfill,
        bool $canReplay,
        bool $canConsolidate,
        bool $canDocSync,
        bool $canIntegrationWiring,
    ): string {
        if ($canBackfill) {
            return self::APPROACH_EVIDENCE_BACKFILL;
        }
        if ($canReplay) {
            return self::APPROACH_PROOF_REPLAY;
        }
        if ($canConsolidate) {
            return self::APPROACH_CONSOLIDATION;
        }
        if ($canDocSync) {
            return self::APPROACH_DOC_SYNC;
        }
        if ($canIntegrationWiring) {
            return self::APPROACH_INTEGRATION_WIRING;
        }
        if ($gapType === 'blocked') {
            return self::APPROACH_UNBLOCK_DEPENDENCY;
        }
        // Gap-type-specific defaults when no cheap flag is set.
        if ($gapType === 'integration_debt') {
            return self::APPROACH_INTEGRATION_WIRING;
        }
        if ($gapType === 'doc_drift') {
            return self::APPROACH_DOC_SYNC;
        }
        if ($gapType === 'weak_outcome_learning') {
            return self::APPROACH_EVIDENCE_BACKFILL;
        }

        return self::APPROACH_NEW_FEATURE_WORK;
    }

    private function proofCommand(string $approach, string $organId, string $ownerSub): string
    {
        return match ($approach) {
            self::APPROACH_EVIDENCE_BACKFILL   => 'atlas:brain:seed --organ='.$organId,
            self::APPROACH_PROOF_REPLAY        => 'atlas:brain:verify --organ='.$organId,
            self::APPROACH_CONSOLIDATION       => 'atlas:brain:consolidate --organ='.$organId,
            self::APPROACH_DOC_SYNC            => 'atlas:brain:doc-sync --organ='.$organId,
            self::APPROACH_INTEGRATION_WIRING  => 'atlas:brain:wire --organ='.$organId,
            self::APPROACH_UNBLOCK_DEPENDENCY  => 'atlas:brain:unblock --organ='.$organId,
            default                            => 'atlas:task:next --scope='.$ownerSub,
        };
    }

    private function stopCondition(string $approach): string
    {
        return match ($approach) {
            self::APPROACH_EVIDENCE_BACKFILL   => 'first_green_evidence_captured',
            self::APPROACH_PROOF_REPLAY        => 'existing_proof_returns_green',
            self::APPROACH_CONSOLIDATION       => 'duplicate_count_reduced_to_one',
            self::APPROACH_DOC_SYNC            => 'doc_drift_eliminated',
            self::APPROACH_INTEGRATION_WIRING  => 'integration_points_fully_wired',
            self::APPROACH_UNBLOCK_DEPENDENCY  => 'blocker_resolved',
            default                            => 'acceptance_criteria_green',
        };
    }
}
