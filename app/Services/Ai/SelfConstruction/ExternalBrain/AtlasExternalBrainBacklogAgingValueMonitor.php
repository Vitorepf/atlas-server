<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use Carbon\CarbonImmutable;

/**
 * Pure backlog-aging monitor. A queued task is not assumed valuable
 * forever: this scores value decay by age and priority, then routes stale
 * tasks to revalidate, consolidate, or retire so the originator stops
 * authoring more tasks on top of a backlog nobody re-checked.
 *
 * retire_candidates    — superseded by a newer task, or already implemented.
 * consolidate_candidates — multiple stale tasks sharing the same theme+target
 *                          (duplicated backlog, should merge into one).
 * revalidate_candidates — stale on its own, not superseded or duplicated;
 *                          needs a fresh look before counting as live work.
 *
 * Recent high-priority tasks decay slower and stay out of every candidate
 * list as long as they remain under the stale threshold.
 *
 * Pure: no I/O, never mutates the task queue.
 */
final class AtlasExternalBrainBacklogAgingValueMonitor
{
    public const SCHEMA = 'atlas.external_brain.backlog_aging_value_monitor.v1';

    private const STALE_THRESHOLD_DAYS = 14.0;
    private const DECAY_HORIZON_DAYS = 60.0;
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
    public function evaluate(array $facts): array
    {
        $tasks = array_values((array) ($facts['tasks'] ?? []));
        $now = $this->resolveNow((string) ($facts['now'] ?? ''));

        $rows = array_map(fn (array $task): array => $this->row((array) $task, $now), $tasks);

        // Pass 2: detect duplicate stale (theme, target) groups for consolidation.
        $staleGroupCounts = [];
        foreach ($rows as $row) {
            if (! $row['is_stale'] || $row['retire_reason'] !== null) {
                continue;
            }
            $key = $row['theme'].'::'.$row['target'];
            $staleGroupCounts[$key] = ($staleGroupCounts[$key] ?? 0) + 1;
        }

        $retireCandidates = [];
        $consolidateCandidates = [];
        $revalidateCandidates = [];
        $staleCount = 0;

        foreach ($rows as $row) {
            if ($row['is_stale']) {
                $staleCount++;
            }
            if ($row['retire_reason'] !== null) {
                $retireCandidates[] = ['task_id' => $row['task_id'], 'reason' => $row['retire_reason']];

                continue;
            }
            if (! $row['is_stale']) {
                continue;
            }

            $groupKey = $row['theme'].'::'.$row['target'];
            if (($staleGroupCounts[$groupKey] ?? 0) > 1) {
                $consolidateCandidates[] = [
                    'task_id' => $row['task_id'],
                    'reason' => 'duplicate_stale_same_theme_target',
                    'group_key' => $groupKey,
                ];

                continue;
            }

            $revalidateCandidates[] = ['task_id' => $row['task_id'], 'reason' => 'stale_needs_revalidation'];
        }

        $taskCount = count($rows);
        $valueDecayRisk = $taskCount === 0
            ? 0.0
            : round(array_sum(array_column($rows, 'value_decay_risk')) / $taskCount, 6);

        return [
            'schema_version' => self::SCHEMA,
            'task_count' => $taskCount,
            'stale_count' => $staleCount,
            'value_decay_risk' => $valueDecayRisk,
            'task_rows' => array_map(
                static fn (array $row): array => [
                    'task_id' => $row['task_id'],
                    'age_days' => $row['age_days'],
                    'value_decay_risk' => $row['value_decay_risk'],
                    'is_high_priority' => $row['is_high_priority'],
                    'is_stale' => $row['is_stale'],
                ],
                $rows,
            ),
            'revalidate_candidates' => $revalidateCandidates,
            'consolidate_candidates' => $consolidateCandidates,
            'retire_candidates' => $retireCandidates,
            'mutates_queue' => false,
        ];
    }

    /** @param array<string,mixed> $task */
    private function row(array $task, CarbonImmutable $now): array
    {
        $taskId = (string) ($task['task_id'] ?? '');
        $theme = trim((string) ($task['theme'] ?? ''));
        $target = trim((string) ($task['target'] ?? ''));
        $implementationStatus = strtolower(trim((string) ($task['implementation_status'] ?? '')));
        $dependencyStatus = strtolower(trim((string) ($task['dependency_status'] ?? '')));
        $supersededBy = trim((string) ($task['superseded_by'] ?? ''));
        $priorityValue = $this->priorityValue($task['priority'] ?? null);
        $isHighPriority = $priorityValue >= self::HIGH_PRIORITY_FLOOR;

        $enqueuedAt = $this->parseDate((string) ($task['enqueued_at'] ?? ''));
        $ageDays = $enqueuedAt === null ? 0.0 : max(0.0, $enqueuedAt->diffInHours($now) / 24.0);
        $isStale = $ageDays >= self::STALE_THRESHOLD_DAYS;

        $decaySlowdown = $isHighPriority ? 0.5 : 1.0;
        $valueDecayRisk = round(min(1.0, ($ageDays / self::DECAY_HORIZON_DAYS) * $decaySlowdown), 6);

        $retireReason = match (true) {
            $supersededBy !== '' => 'superseded_by_newer_task',
            $implementationStatus === 'done' => 'already_implemented',
            default => null,
        };

        return [
            'task_id' => $taskId,
            'theme' => $theme,
            'target' => $target,
            'dependency_status' => $dependencyStatus,
            'priority_value' => $priorityValue,
            'is_high_priority' => $isHighPriority,
            'age_days' => round($ageDays, 4),
            'is_stale' => $isStale,
            'value_decay_risk' => $valueDecayRisk,
            'retire_reason' => $retireReason,
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

    private function parseDate(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveNow(string $value): CarbonImmutable
    {
        $parsed = $value === '' ? null : $this->parseDate($value);

        return $parsed ?? CarbonImmutable::now();
    }
}
