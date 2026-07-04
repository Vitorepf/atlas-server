<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure roadmap coverage governor. Compares queued + completed work against
 * the external-brain roadmap's declared gaps so the originator creates
 * future batches ONLY for under-covered high-priority gaps — not endless
 * variants on a theme that is already covered.
 *
 * A candidate batch targeting an already-overcovered gap is blocked unless
 * it is a prerequisite unlock or a high-value repair: those two are
 * structurally different from "more of the same theme" and stay allowed.
 *
 * Staleness (new): a roadmap_gap may declare last_reviewed_days_ago (default 0). Once that
 * exceeds STALE_AFTER_DAYS, the gap is marked stale instead of being blindly enqueued into
 * undercovered_high_priority_gaps / next_batch_should_target / gap_candidates / task-fabric
 * replenishment — a roadmap item nobody has revisited in that long needs revalidation first, not
 * an automatic new task.
 *
 * gap_candidates (new): every undercovered, non-stale, high-priority gap gets a candidate object
 * naming a `reason` and a `suggested_task_family` (the gap's own declared task_family, or a
 * derived fallback), so the originator has enough material to build a real task instead of a
 * bare gap_id.
 *
 * Pure: no I/O, no queue mutation.
 */
final class AtlasExternalBrainRoadmapCoverageGapGovernor
{
    public const SCHEMA = 'atlas.external_brain.roadmap_coverage_gap_governor.v1';

    private const DEFAULT_TARGET_COVERAGE = 3;
    private const HIGH_PRIORITY_FLOOR = 8.0;
    private const STALE_AFTER_DAYS = 180;

    /** claimable_per_active_worker at or below this ratio means workers are about to starve. */
    private const WORKER_FLOOR_LOW_THRESHOLD = 2.0;

    private const PRIORITY_NAME_VALUES = [
        'critical' => 10.0,
        'high' => 8.0,
        'medium' => 5.0,
        'normal' => 5.0,
        'low' => 2.0,
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function govern(array $facts): array
    {
        $roadmapGaps = array_values((array) ($facts['roadmap_gaps'] ?? []));
        $queuedTasks = array_values((array) ($facts['queued_tasks'] ?? []));
        $completedCapabilities = array_values((array) ($facts['completed_capabilities'] ?? []));
        $candidateBatches = array_values((array) ($facts['candidate_batches'] ?? []));

        $coverageCounts = [];
        foreach ($queuedTasks as $task) {
            $task = (array) $task;
            $gapId = (string) ($task['gap_id'] ?? '');
            if ($gapId === '') {
                continue;
            }
            $coverageCounts[$gapId] = ($coverageCounts[$gapId] ?? 0) + 1;
        }
        foreach ($completedCapabilities as $completed) {
            $gapId = is_array($completed) ? (string) ($completed['gap_id'] ?? '') : (string) $completed;
            if ($gapId === '') {
                continue;
            }
            $coverageCounts[$gapId] = ($coverageCounts[$gapId] ?? 0) + 1;
        }

        $claimablePerActiveWorker = isset($facts['claimable_per_active_worker']) ? (float) $facts['claimable_per_active_worker'] : null;
        $workerFloorLow = $claimablePerActiveWorker !== null && $claimablePerActiveWorker <= self::WORKER_FLOOR_LOW_THRESHOLD;

        $coverageByGap = [];
        $overcoveredGaps = [];
        $undercoveredHighPriorityGaps = [];
        $gapPriorities = [];
        $gapTargets = [];
        $gapMaterial = [];
        $staleRoadmapGaps = [];
        $gapCandidates = [];

        foreach ($roadmapGaps as $gap) {
            $gap = (array) $gap;
            $gapId = (string) ($gap['gap_id'] ?? '');
            if ($gapId === '') {
                continue;
            }
            $targetCoverage = max(0, (int) ($gap['target_coverage'] ?? self::DEFAULT_TARGET_COVERAGE));
            $priorityValue = $this->priorityValue($gap['priority'] ?? null);
            $isHighPriority = $priorityValue >= self::HIGH_PRIORITY_FLOOR;
            $currentCoverage = $coverageCounts[$gapId] ?? 0;
            $isOvercovered = $currentCoverage >= $targetCoverage;
            $gapMaterial[$gapId] = [
                'allowed_files' => array_values(array_filter(array_map('strval', (array) ($gap['allowed_files'] ?? [])))),
                'acceptance_criteria' => array_values(array_filter(array_map('strval', (array) ($gap['acceptance_criteria'] ?? [])))),
            ];

            $gapPriorities[$gapId] = $priorityValue;
            $gapTargets[$gapId] = $targetCoverage;

            $coverageByGap[$gapId] = [
                'gap_id' => $gapId,
                'current_coverage' => $currentCoverage,
                'target_coverage' => $targetCoverage,
                'priority' => $priorityValue,
                'is_high_priority' => $isHighPriority,
                'is_overcovered' => $isOvercovered,
                'coverage_gap' => max(0, $targetCoverage - $currentCoverage),
            ];

            if ($isOvercovered) {
                $overcoveredGaps[] = $gapId;

                continue;
            }

            // AC4: a roadmap item that hasn't been reviewed in STALE_AFTER_DAYS is marked stale
            // instead of being blindly enqueued as an undercovered/high-priority target.
            $lastReviewedDaysAgo = max(0, (int) ($gap['last_reviewed_days_ago'] ?? 0));
            if ($lastReviewedDaysAgo > self::STALE_AFTER_DAYS) {
                $staleRoadmapGaps[] = [
                    'gap_id' => $gapId,
                    'reason' => 'stale_requires_revalidation_before_enqueue',
                    'last_reviewed_days_ago' => $lastReviewedDaysAgo,
                ];

                continue;
            }

            if ($isHighPriority) {
                $undercoveredHighPriorityGaps[] = $gapId;

                // AC3: give the originator a real candidate — reason + suggested task family —
                // instead of a bare gap_id.
                $taskFamily = trim((string) ($gap['task_family'] ?? ''));
                $suggestedTaskFamily = $taskFamily !== '' ? $taskFamily : 'roadmap_'.preg_replace('/[^a-z0-9]+/', '_', strtolower($gapId));
                $gapCandidates[] = [
                    'gap_id' => $gapId,
                    'reason' => sprintf('undercovered_high_priority_gap:current=%d,target=%d', $currentCoverage, $targetCoverage),
                    'suggested_task_family' => $suggestedTaskFamily,
                ];
            }
        }

        usort($undercoveredHighPriorityGaps, static fn (string $a, string $b): int => $coverageByGap[$b]['coverage_gap'] <=> $coverageByGap[$a]['coverage_gap']);

        // Low worker floor: route high-confidence (undercovered + high priority) gaps into
        // IMMEDIATE task-fabric replenishment instead of leaving them as abstract roadmap debt —
        // but ONLY when the gap carries enough concrete material (allowed_files + a runnable
        // acceptance criterion) to produce a real claimable task. A gap without that material
        // stays roadmap debt rather than fabricating a claimable task with no real scope.
        $taskFabricReplenishmentActions = [];
        if ($workerFloorLow) {
            foreach ($undercoveredHighPriorityGaps as $gapId) {
                $material = $gapMaterial[$gapId] ?? ['allowed_files' => [], 'acceptance_criteria' => []];
                if ($material['allowed_files'] !== [] && $this->hasRunnableAcceptance($material['acceptance_criteria'])) {
                    $taskFabricReplenishmentActions[] = [
                        'gap_id' => $gapId,
                        'action' => 'task_fabric_replenishment',
                        'allowed_files' => $material['allowed_files'],
                        'acceptance_criteria' => $material['acceptance_criteria'],
                        'reason' => 'low_worker_floor_routes_high_confidence_gap_to_immediate_replenishment',
                    ];
                }
            }
        }

        $candidateBatchDecisions = [];
        foreach ($candidateBatches as $candidate) {
            $candidate = (array) $candidate;
            $gapId = (string) ($candidate['gap_id'] ?? '');
            $isPrerequisiteUnlock = (bool) ($candidate['is_prerequisite_unlock'] ?? false);
            $isHighValueRepair = (bool) ($candidate['is_high_value_repair'] ?? false);
            $isRegressionRepair = (bool) ($candidate['is_regression_repair'] ?? $isHighValueRepair);
            $isOvercovered = in_array($gapId, $overcoveredGaps, true);

            $blocked = $isOvercovered && ! $isPrerequisiteUnlock && ! $isRegressionRepair;

            $candidateBatchDecisions[] = [
                'gap_id' => $gapId,
                'is_overcovered' => $isOvercovered,
                'is_prerequisite_unlock' => $isPrerequisiteUnlock,
                'is_high_value_repair' => $isHighValueRepair,
                'is_regression_repair' => $isRegressionRepair,
                'decision' => $blocked ? 'blocked' : 'allowed',
                'reason' => $blocked ? 'gap_already_overcovered' : ($isOvercovered ? 'exception_applies_prerequisite_or_repair' : 'gap_not_overcovered'),
            ];
        }

        $blockedOverfocusBatches = array_values(array_filter(
            $candidateBatchDecisions,
            static fn (array $d): bool => $d['decision'] === 'blocked',
        ));

        // Rank uncovered high-priority gaps by coverage_gap desc, priority desc, worker_readiness.
        $recommendedGapOrder = array_values(array_map(
            static fn (string $gapId): array => [
                'gap_id' => $gapId,
                'coverage_gap' => $coverageByGap[$gapId]['coverage_gap'] ?? 0,
                'priority' => $gapPriorities[$gapId] ?? 0.0,
                'worker_readiness' => $workerFloorLow ? 'low' : 'normal',
            ],
            $undercoveredHighPriorityGaps,
        ));

        $workerFloorAction = $workerFloorLow
            ? ($taskFabricReplenishmentActions !== [] ? 'route_to_task_fabric_replenishment' : 'low_worker_floor_no_replenishable_gaps')
            : 'normal_worker_floor_maintain_throughput';

        return [
            'schema_version' => self::SCHEMA,
            'coverage_by_gap' => $coverageByGap,
            'overcovered_gaps' => array_values($overcoveredGaps),
            'blocked_overfocus_batches' => $blockedOverfocusBatches,
            'undercovered_high_priority_gaps' => array_values($undercoveredHighPriorityGaps),
            'next_batch_should_target' => array_values($undercoveredHighPriorityGaps),
            'recommended_gap_order' => $recommendedGapOrder,
            'candidate_batch_decisions' => $candidateBatchDecisions,
            'worker_floor_low' => $workerFloorLow,
            'worker_floor_action' => $workerFloorAction,
            'claimable_per_active_worker' => $claimablePerActiveWorker,
            'task_fabric_replenishment_actions' => $taskFabricReplenishmentActions,
            'gap_candidates' => $gapCandidates,
            'stale_roadmap_gaps' => $staleRoadmapGaps,
            'mutates_queue' => false,
        ];
    }

    /**
     * @param  list<string>  $acceptanceCriteria
     */
    private function hasRunnableAcceptance(array $acceptanceCriteria): bool
    {
        return SharedAtlasExternalBrainRoadmapCoverageGapGovernorSeam::hasRunnableAcceptance($acceptanceCriteria);
    }

    private function priorityValue(mixed $priority): float
    {
        if (is_numeric($priority)) {
            return (float) $priority;
        }

        $name = strtolower(trim((string) $priority));

        return self::PRIORITY_NAME_VALUES[$name] ?? 5.0;
    }
}
