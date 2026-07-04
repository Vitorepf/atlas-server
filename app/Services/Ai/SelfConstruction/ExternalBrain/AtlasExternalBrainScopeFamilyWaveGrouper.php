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
 *   depends_on?:             list<string>  — task_ids that must complete in an EARLIER wave
 *
 * CONFLICT (unsafe_for_same_wave) between two tasks, first match wins per pair:
 *   allowed_files_overlap         — any allowed_files entry is identical between the two tasks
 *   fragile_test_fixture_overlap  — they share a fragile_test_fixtures entry
 *   recent_contention             — both tasks are flagged recent_contention=true
 *
 * GROUPING: greedy graph-coloring over the conflict graph, processed in dependency-topological
 * order — a task joins the earliest compatible_group whose members it conflicts with none of AND
 * whose wave index is strictly after every wave index its depends_on targets landed in;
 * otherwise it starts a new group. Tasks within the same group never conflict and can run in the
 * same wave concurrently. A dependency cycle or a depends_on target absent from this batch never
 * blocks placement — the constraint is simply skipped for that edge.
 *
 * OUTPUT:
 *   { schema, compatible_groups:list<list<string>>, conflict_groups:list<{task_a,task_b,reason}>,
 *     recommended_parallelism:int, tasks_that_should_run_serially:list<string>,
 *     task_placements:list<{task_id,wave_id,parallel_safe,collision_reason}> }
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
                'depends_on'             => array_values(array_unique(array_map('strval', (array) ($task['depends_on'] ?? [])))),
            ];
        }

        $taskIds = array_keys($tasks);
        sort($taskIds, SORT_STRING);

        // Dependency-topological order: a task is only eligible once every depends_on target
        // present in this batch has already been ordered. Cycles/missing targets never stall —
        // whatever remains after no progress is made is appended as-is.
        $orderedTaskIds = [];
        $remaining = $taskIds;
        while ($remaining !== []) {
            $progressed = false;
            $stillRemaining = [];
            foreach ($remaining as $id) {
                $deps = array_intersect($tasks[$id]['depends_on'], $taskIds);
                $ready = true;
                foreach ($deps as $dep) {
                    if (! in_array($dep, $orderedTaskIds, true)) {
                        $ready = false;
                        break;
                    }
                }
                if ($ready) {
                    $orderedTaskIds[] = $id;
                    $progressed = true;
                } else {
                    $stillRemaining[] = $id;
                }
            }
            if (! $progressed) {
                $orderedTaskIds = array_merge($orderedTaskIds, $stillRemaining);
                break;
            }
            $remaining = $stillRemaining;
        }

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

        // Greedy graph coloring in dependency-topological order: each task joins the earliest
        // group with no conflicting member AND an index strictly after every depends_on target's
        // wave index.
        $compatibleGroups = [];
        $waveIndexOf = [];
        foreach ($orderedTaskIds as $id) {
            $deps = array_intersect($tasks[$id]['depends_on'], $taskIds);
            $minGroupIndex = 0;
            foreach ($deps as $dep) {
                if (isset($waveIndexOf[$dep])) {
                    $minGroupIndex = max($minGroupIndex, $waveIndexOf[$dep] + 1);
                }
            }

            $placed = false;
            for ($gi = $minGroupIndex; $gi < count($compatibleGroups); $gi++) {
                $conflictsInGroup = array_intersect($compatibleGroups[$gi], $conflictsWith[$id]);
                if ($conflictsInGroup === []) {
                    $compatibleGroups[$gi][] = $id;
                    $waveIndexOf[$id] = $gi;
                    $placed = true;
                    break;
                }
            }
            if (! $placed) {
                $compatibleGroups[] = [$id];
                $waveIndexOf[$id] = count($compatibleGroups) - 1;
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

        // First conflict reason recorded for each task, for the per-task collision_reason.
        $firstConflictReasonFor = [];
        foreach ($conflictGroups as $pair) {
            $firstConflictReasonFor[$pair['task_a']] ??= $pair['reason'];
            $firstConflictReasonFor[$pair['task_b']] ??= $pair['reason'];
        }

        $taskPlacements = [];
        foreach ($taskIds as $id) {
            $taskPlacements[] = [
                'task_id'          => $id,
                'wave_id'          => 'wave_' . ($waveIndexOf[$id] ?? 0),
                'parallel_safe'    => ! isset($serial[$id]),
                'collision_reason' => $firstConflictReasonFor[$id] ?? null,
            ];
        }

        // Family load: per-family wave distribution — tracks whether a file family is
        // over-concentrated in any single wave.
        $familyLoad = [];
        foreach ($tasks as $id => $task) {
            foreach ($task['allowed_files'] as $file) {
                if (! isset($familyLoad[$file])) {
                    $familyLoad[$file] = ['file' => $file, 'wave_distribution' => []];
                }
                $waveIdx = $waveIndexOf[$id] ?? 0;
                $familyLoad[$file]['wave_distribution'][$waveIdx] = ($familyLoad[$file]['wave_distribution'][$waveIdx] ?? 0) + 1;
            }
        }
        // Sort keys in each wave_distribution for deterministic output.
        foreach ($familyLoad as &$entry) {
            ksort($entry['wave_distribution'], SORT_NUMERIC);
        }
        unset($entry);
        usort($familyLoad, static fn (array $a, array $b): int => strcmp($a['file'], $b['file']));

        return [
            'schema'                          => self::SCHEMA,
            'compatible_groups'               => array_values($compatibleGroups),
            'conflict_groups'                 => $conflictGroups,
            'recommended_parallelism'         => max(1, $largestGroupSize),
            'tasks_that_should_run_serially'  => $tasksThatShouldRunSerially,
            'task_placements'                 => $taskPlacements,
            'family_load'                     => $familyLoad,
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
