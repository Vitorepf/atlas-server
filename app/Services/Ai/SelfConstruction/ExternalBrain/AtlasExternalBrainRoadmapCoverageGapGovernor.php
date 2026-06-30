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
 * Pure: no I/O, no queue mutation.
 */
final class AtlasExternalBrainRoadmapCoverageGapGovernor
{
    public const SCHEMA = 'atlas.external_brain.roadmap_coverage_gap_governor.v1';

    private const DEFAULT_TARGET_COVERAGE = 3;
    private const HIGH_PRIORITY_FLOOR = 8.0;

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

        $coverageByGap = [];
        $overcoveredGaps = [];
        $undercoveredHighPriorityGaps = [];
        $gapPriorities = [];
        $gapTargets = [];

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
            if ($isHighPriority) {
                $undercoveredHighPriorityGaps[] = $gapId;
            }
        }

        usort($undercoveredHighPriorityGaps, static fn (string $a, string $b): int => $coverageByGap[$b]['coverage_gap'] <=> $coverageByGap[$a]['coverage_gap']);

        $candidateBatchDecisions = [];
        foreach ($candidateBatches as $candidate) {
            $candidate = (array) $candidate;
            $gapId = (string) ($candidate['gap_id'] ?? '');
            $isPrerequisiteUnlock = (bool) ($candidate['is_prerequisite_unlock'] ?? false);
            $isHighValueRepair = (bool) ($candidate['is_high_value_repair'] ?? false);
            $isOvercovered = in_array($gapId, $overcoveredGaps, true);

            $blocked = $isOvercovered && ! $isPrerequisiteUnlock && ! $isHighValueRepair;

            $candidateBatchDecisions[] = [
                'gap_id' => $gapId,
                'is_overcovered' => $isOvercovered,
                'is_prerequisite_unlock' => $isPrerequisiteUnlock,
                'is_high_value_repair' => $isHighValueRepair,
                'decision' => $blocked ? 'blocked' : 'allowed',
                'reason' => $blocked ? 'gap_already_overcovered' : ($isOvercovered ? 'exception_applies_prerequisite_or_repair' : 'gap_not_overcovered'),
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'coverage_by_gap' => $coverageByGap,
            'overcovered_gaps' => array_values($overcoveredGaps),
            'undercovered_high_priority_gaps' => array_values($undercoveredHighPriorityGaps),
            'next_batch_should_target' => array_values($undercoveredHighPriorityGaps),
            'candidate_batch_decisions' => $candidateBatchDecisions,
            'mutates_queue' => false,
        ];
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
