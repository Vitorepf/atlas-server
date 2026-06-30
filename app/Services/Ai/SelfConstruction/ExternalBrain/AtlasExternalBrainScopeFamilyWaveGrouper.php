<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure wave grouper. Groups candidate tasks into compatible batches that can be drained in
 * parallel by multiple muscles on the SAME shared local main, without two muscles colliding
 * on the same file, the same fragile test fixture, or a git/index path that recently contended.
 *
 * INPUT per task:
 *   task_id:                string
 *   allowed_files:           list<string>
 *   fragile_test_fixtures?:  list<string>  — shared test fixture paths this task touches
 *   recent_contention?:      bool          — this task recently caused git/index lock contention
 *
 * CONFLICT (unsafe_for_same_wave) between two tasks, first match wins per pair:
 *   allowed_files_overlap         — any allowed_files entry is identical between the two tasks
 *   fragile_test_fixture_overlap  — they share a fragile_test_fixtures entry
 *   recent_contention             — both tasks are flagged recent_contention=true
 *
 * GROUPING: greedy graph-coloring over the conflict graph — a task joins the first existing
 * compatible_group whose members it conflicts with none of; otherwise it starts a new group.
 * Tasks within the same group never conflict and can run in the same wave concurrently.
 *
 * OUTPUT:
 *   { schema, compatible_groups:list<list<string>>, conflict_groups:list<{task_a,task_b,reason}>,
 *     recommended_parallelism:int, tasks_that_should_run_serially:list<string> }
 *
 * Pure / deterministic. No I/O, no provider calls.
 */
final class AtlasExternalBrainScopeFamilyWaveGrouper
{
    public const SCHEMA = 'atlas.external_brain.scope_family_wave_grouper.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function group(array $input): array
    {
        $rawTasks = is_array($input['tasks'] ?? null) ? $input['tasks'] : [];

        $tasks = [];
        foreach ($rawTasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $id = trim((string) ($task['task_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $tasks[$id] = [
                'task_id'                => $id,
                'allowed_files'          => array_values(array_unique(array_map('strval', (array) ($task['allowed_files'] ?? [])))),
                'fragile_test_fixtures'  => array_values(array_unique(array_map('strval', (array) ($task['fragile_test_fixtures'] ?? [])))),
                'recent_contention'      => (bool) ($task['recent_contention'] ?? false),
            ];
        }

        $taskIds = array_keys($tasks);
        sort($taskIds, SORT_STRING);

        $conflictGroups = [];
        $conflictsWith  = array_fill_keys($taskIds, []);

        for ($i = 0; $i < count($taskIds); $i++) {
            for ($j = $i + 1; $j < count($taskIds); $j++) {
                $a = $tasks[$taskIds[$i]];
                $b = $tasks[$taskIds[$j]];
                $reason = $this->conflictReason($a, $b);
                if ($reason === null) {
                    continue;
                }
                $conflictGroups[] = ['task_a' => $a['task_id'], 'task_b' => $b['task_id'], 'reason' => $reason];
                $conflictsWith[$a['task_id']][] = $b['task_id'];
                $conflictsWith[$b['task_id']][] = $a['task_id'];
            }
        }

        // Greedy graph coloring: each task joins the first group with no conflicting member.
        $compatibleGroups = [];
        foreach ($taskIds as $id) {
            $placed = false;
            foreach ($compatibleGroups as &$group) {
                $conflictsInGroup = array_intersect($group, $conflictsWith[$id]);
                if ($conflictsInGroup === []) {
                    $group[] = $id;
                    $placed  = true;
                    break;
                }
            }
            unset($group);
            if (! $placed) {
                $compatibleGroups[] = [$id];
            }
        }

        $largestGroupSize = 0;
        foreach ($compatibleGroups as $group) {
            $largestGroupSize = max($largestGroupSize, count($group));
        }

        $serial = [];
        foreach ($conflictGroups as $pair) {
            $serial[$pair['task_a']] = true;
            $serial[$pair['task_b']] = true;
        }
        $tasksThatShouldRunSerially = array_values(array_keys($serial));
        sort($tasksThatShouldRunSerially, SORT_STRING);

        return [
            'schema'                          => self::SCHEMA,
            'compatible_groups'               => array_values($compatibleGroups),
            'conflict_groups'                 => $conflictGroups,
            'recommended_parallelism'         => max(1, $largestGroupSize),
            'tasks_that_should_run_serially'  => $tasksThatShouldRunSerially,
        ];
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function conflictReason(array $a, array $b): ?string
    {
        if (array_intersect($a['allowed_files'], $b['allowed_files']) !== []) {
            return 'allowed_files_overlap';
        }
        if (array_intersect($a['fragile_test_fixtures'], $b['fragile_test_fixtures']) !== []) {
            return 'fragile_test_fixture_overlap';
        }
        if ($a['recent_contention'] && $b['recent_contention']) {
            return 'recent_contention';
        }

        return null;
    }
}
