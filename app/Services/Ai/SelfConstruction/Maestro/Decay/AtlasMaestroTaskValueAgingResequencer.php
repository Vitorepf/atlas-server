<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Decay;

/**
 * Pure resequencer that resequences aging tasks by value decay so stale
 * low-value packets move behind fresh high-leverage repair and learning work.
 *
 * Rules:
 *   - Old low-value tasks are demoted (moved behind fresh work)
 *   - Old high-value blockers are surfaced (moved ahead)
 *   - Fresh critical repairs stay ahead
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasMaestroTaskValueAgingResequencer
{
    public const SCHEMA = 'atlas.maestro.task_value_aging_resequencer.v1';

    private const STALE_AGE_DAYS = 7;
    private const HIGH_VALUE_THRESHOLD = 0.7;
    private const CRITICAL_PRIORITY = 'critical';

    /**
     * @param  array<int, array<string, mixed>>  $tasks
     * @return array<string, mixed>
     */
    public function resequence(array $tasks): array
    {
        $enriched = [];
        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            $taskId = (string) ($task['task_id'] ?? '');
            $ageDays = (int) ($task['age_days'] ?? 0);
            $valueScore = (float) ($task['value_score'] ?? 0.0);
            $priority = (string) ($task['priority'] ?? 'normal');
            $isBlocker = (bool) ($task['is_blocker'] ?? false);

            $isStale = $ageDays > self::STALE_AGE_DAYS;
            $isHighValue = $valueScore >= self::HIGH_VALUE_THRESHOLD;
            $isCritical = $priority === self::CRITICAL_PRIORITY;

            // Compute effective priority: higher = earlier in queue.
            $effectivePriority = 0.0;
            if ($isCritical && ! $isStale) {
                $effectivePriority = 100.0; // Fresh critical repairs stay ahead.
            } elseif ($isBlocker && $isHighValue && $isStale) {
                $effectivePriority = 90.0; // Old high-value blockers surfaced.
            } elseif ($isHighValue) {
                $effectivePriority = 50.0 + $valueScore;
            } elseif ($isStale && ! $isHighValue) {
                $effectivePriority = 10.0; // Old low-value demoted.
            } else {
                $effectivePriority = 30.0 + $valueScore;
            }

            $enriched[] = [
                'task_id' => $taskId,
                'age_days' => $ageDays,
                'value_score' => $valueScore,
                'priority' => $priority,
                'is_blocker' => $isBlocker,
                'is_stale' => $isStale,
                'is_high_value' => $isHighValue,
                'is_critical' => $isCritical,
                'effective_priority' => $effectivePriority,
                'adjustment' => $this->adjustmentLabel($isStale, $isHighValue, $isCritical, $isBlocker),
            ];
        }

        // Sort by effective_priority DESC, then task_id ASC for determinism.
        usort($enriched, static function (array $a, array $b): int {
            $cmp = $b['effective_priority'] <=> $a['effective_priority'];
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp($a['task_id'], $b['task_id']);
        });

        return [
            'schema_version' => self::SCHEMA,
            'resequenced' => array_column($enriched, 'task_id'),
            'enriched' => $enriched,
            'total' => count($enriched),
        ];
    }

    private function adjustmentLabel(bool $isStale, bool $isHighValue, bool $isCritical, bool $isBlocker): string
    {
        if ($isCritical && ! $isStale) {
            return 'fresh_critical_stays_ahead';
        }
        if ($isBlocker && $isHighValue && $isStale) {
            return 'old_high_value_blocker_surfaced';
        }
        if ($isStale && ! $isHighValue) {
            return 'old_low_value_demoted';
        }

        return 'maintain_position';
    }
}
