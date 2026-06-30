<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure post-commit wave resequencer. After each commit, give_back, or
 * repair the plan can go stale: a task already superseded by the commit, a
 * task whose dependency rotted, or a task with a repeated give_back
 * diagnosis is no longer worth keeping in the wave — and a task that the
 * latest commit just unblocked, especially a critical-path one, deserves
 * to move to the front instead of waiting behind a stale queue order.
 *
 * Pure: no I/O, never mutates the queue — returns the recommended order
 * and the reasons for every move so the caller can apply or audit it.
 */
final class AtlasExternalBrainPostCommitWaveResequencer
{
    public const SCHEMA = 'atlas.external_brain.post_commit_wave_resequencer.v1';

    private const DEFAULT_GIVE_BACK_REPEAT_THRESHOLD = 2;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function resequence(array $facts): array
    {
        $previousWave = (array) ($facts['previous_wave'] ?? []);
        $previousTaskIds = array_values(array_map('strval', (array) ($previousWave['task_ids'] ?? [])));
        $queueTasks = array_values((array) ($facts['queue_tasks'] ?? []));
        $staleDependencyFindings = (array) ($facts['stale_dependency_findings'] ?? []);
        $staleTaskIds = array_values(array_map('strval', (array) ($staleDependencyFindings['stale_task_ids'] ?? [])));
        $giveBackThreshold = max(1, (int) ($facts['give_back_repeat_threshold'] ?? self::DEFAULT_GIVE_BACK_REPEAT_THRESHOLD));

        $tasksById = [];
        foreach ($queueTasks as $task) {
            $task = (array) $task;
            $taskId = (string) ($task['task_id'] ?? '');
            if ($taskId === '') {
                continue;
            }
            $tasksById[$taskId] = $task;
        }

        $removedTaskIds = [];
        $resequenceReasons = [];

        foreach ($tasksById as $taskId => $task) {
            $supersededByCommit = (bool) ($task['superseded_by_commit'] ?? false);
            $giveBackRepeatCount = (int) ($task['give_back_repeat_count'] ?? 0);
            $isStale = in_array($taskId, $staleTaskIds, true);

            if ($supersededByCommit) {
                $removedTaskIds[$taskId] = true;
                $resequenceReasons[] = "removed_superseded_by_commit:{$taskId}";
            } elseif ($giveBackRepeatCount >= $giveBackThreshold) {
                $removedTaskIds[$taskId] = true;
                $resequenceReasons[] = "removed_repeated_give_back:{$taskId}";
            } elseif ($isStale) {
                $removedTaskIds[$taskId] = true;
                $resequenceReasons[] = "removed_stale_dependency:{$taskId}";
            }
        }

        $newlyUnblockedCriticalPath = [];
        $newlyUnblockedOther = [];
        foreach ($tasksById as $taskId => $task) {
            if (isset($removedTaskIds[$taskId])) {
                continue;
            }
            if (! (bool) ($task['unblocked_by_latest_commit'] ?? false)) {
                continue;
            }
            if ((bool) ($task['is_critical_path'] ?? false)) {
                $newlyUnblockedCriticalPath[] = $taskId;
                $resequenceReasons[] = "prioritized_newly_unblocked_critical_path:{$taskId}";
            } else {
                $newlyUnblockedOther[] = $taskId;
                $resequenceReasons[] = "prioritized_newly_unblocked:{$taskId}";
            }
        }

        $newlyUnblockedTaskIds = array_merge($newlyUnblockedCriticalPath, $newlyUnblockedOther);
        $placed = array_fill_keys($newlyUnblockedTaskIds, true);

        $remainder = [];
        foreach ($previousTaskIds as $taskId) {
            if (isset($removedTaskIds[$taskId]) || isset($placed[$taskId])) {
                continue;
            }
            $remainder[] = $taskId;
            $placed[$taskId] = true;
        }
        // Any queue task not present in the previous wave order (newly added, not unblocked-flagged) goes last, stable order.
        foreach (array_keys($tasksById) as $taskId) {
            if (isset($removedTaskIds[$taskId]) || isset($placed[$taskId])) {
                continue;
            }
            $remainder[] = $taskId;
            $placed[$taskId] = true;
        }

        $resequencedTaskIds = array_values(array_merge($newlyUnblockedTaskIds, $remainder));

        return [
            'schema_version' => self::SCHEMA,
            'resequenced_task_ids' => $resequencedTaskIds,
            'removed_stale_task_ids' => array_values(array_keys($removedTaskIds)),
            'newly_unblocked_task_ids' => $newlyUnblockedTaskIds,
            'resequence_reasons' => $resequenceReasons,
            'mutates_queue' => false,
        ];
    }
}
