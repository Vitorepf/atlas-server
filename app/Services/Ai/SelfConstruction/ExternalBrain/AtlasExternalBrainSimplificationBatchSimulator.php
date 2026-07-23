<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Deterministic simulator: decides whether a batch of individually-safe simplification tasks
 * is safe to run TOGETHER. A set of tasks that are each fine alone can still be unsafe as a
 * batch — two tasks touching the same file cannot run concurrently, and combined risk or
 * worker demand can exceed what the batch as a whole may spend.
 *
 * INPUT:
 *   tasks: list<array{id, allowed_files:list<string>, risk_level?:'low'|'medium'|'high',
 *     proof_ready?:bool (default true), worker_capacity_cost?:int (default 1)}>
 *   max_combined_risk?: int (default 6)
 *   available_worker_capacity?: int (default PHP_INT_MAX — capacity is opt-in)
 *
 * DECISION (first match wins):
 *   hold     — batch is empty, OR every task is proof-unready, OR available_worker_capacity <= 0
 *   split    — overlapping write sets, OR combined risk exceeds the limit, OR worker capacity
 *              demand exceeds what is available, OR at least one (not all) task is proof-unready
 *   approved — no overlap, risk within limit, capacity sufficient, every task proof-ready
 *
 * A split decision includes split_recommendation: sub-batches (greedy, deterministic) where no
 * two tasks in the same sub-batch share a file, and each sub-batch stays within the risk and
 * capacity limits. Proof-unready tasks are pulled out entirely into prework_required_task_ids
 * — never placed in a sub-batch, since a batch execution mechanism cannot make an unproven
 * destructive task safe by grouping it differently.
 *
 * Pure: no I/O, no provider calls.
 */
final class AtlasExternalBrainSimplificationBatchSimulator
{
    public const SCHEMA = 'atlas.external_brain.simplification_batch_simulator.v1';

    public const DECISION_APPROVED = 'approved';
    public const DECISION_SPLIT = 'split';
    public const DECISION_HOLD = 'hold';

    public const DEFAULT_MAX_COMBINED_RISK = 6;

    private const RISK_WEIGHT = ['low' => 1, 'medium' => 2, 'high' => 4];

    /**
     * @param  array{tasks?: list<array<string,mixed>>, max_combined_risk?: int, available_worker_capacity?: int}  $facts
     * @return array<string,mixed>
     */
    public function simulate(array $facts): array
    {
        $maxCombinedRisk = max(0, (int) ($facts['max_combined_risk'] ?? self::DEFAULT_MAX_COMBINED_RISK));
        $availableCapacity = array_key_exists('available_worker_capacity', $facts)
            ? max(0, (int) $facts['available_worker_capacity'])
            : PHP_INT_MAX;

        $tasks = [];
        foreach ((array) ($facts['tasks'] ?? []) as $task) {
            if (! is_array($task)) {
                continue;
            }
            $id = trim((string) ($task['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $tasks[] = [
                'id' => $id,
                'allowed_files' => array_values(array_filter(array_map('strval', (array) ($task['allowed_files'] ?? [])), static fn (string $f): bool => $f !== '')),
                'risk_level' => strtolower(trim((string) ($task['risk_level'] ?? 'low'))),
                'proof_ready' => (bool) ($task['proof_ready'] ?? true),
                'worker_capacity_cost' => max(0, (int) ($task['worker_capacity_cost'] ?? 1)),
            ];
        }

        if ($tasks === []) {
            return $this->envelope(self::DECISION_HOLD, ['empty_batch'], [], [], 0, $maxCombinedRisk, 0, $availableCapacity, []);
        }

        $unreadyIds = array_values(array_map(
            static fn (array $t): string => $t['id'],
            array_filter($tasks, static fn (array $t): bool => ! $t['proof_ready']),
        ));

        if (count($unreadyIds) === count($tasks)) {
            return $this->envelope(self::DECISION_HOLD, ['no_proof_ready_tasks'], [], [], $this->combinedRisk($tasks), $maxCombinedRisk, $this->combinedCapacity($tasks), $availableCapacity, $unreadyIds);
        }
        if ($availableCapacity <= 0) {
            return $this->envelope(self::DECISION_HOLD, ['no_worker_capacity'], [], [], $this->combinedRisk($tasks), $maxCombinedRisk, $this->combinedCapacity($tasks), $availableCapacity, $unreadyIds);
        }

        $overlaps = $this->fileOverlaps($tasks);
        $combinedRisk = $this->combinedRisk($tasks);
        $capacityUsed = $this->combinedCapacity($tasks);

        $reasons = [];
        if ($overlaps !== []) {
            $reasons[] = 'overlapping_write_sets';
        }
        if ($combinedRisk > $maxCombinedRisk) {
            $reasons[] = 'combined_risk_exceeds_limit';
        }
        if ($capacityUsed > $availableCapacity) {
            $reasons[] = 'worker_capacity_exceeded';
        }
        if ($unreadyIds !== []) {
            $reasons[] = 'unready_tasks_present';
        }

        if ($reasons === []) {
            return $this->envelope(self::DECISION_APPROVED, ['batch_within_all_limits'], [], [], $combinedRisk, $maxCombinedRisk, $capacityUsed, $availableCapacity, []);
        }

        $readyTasks = array_values(array_filter($tasks, static fn (array $t): bool => $t['proof_ready']));
        $subBatches = $this->partitionIntoSubBatches($readyTasks, $maxCombinedRisk, $availableCapacity);

        return $this->envelope(self::DECISION_SPLIT, $reasons, $overlaps, $subBatches, $combinedRisk, $maxCombinedRisk, $capacityUsed, $availableCapacity, $unreadyIds);
    }

    /** @param  list<array<string,mixed>>  $tasks */
    private function combinedRisk(array $tasks): int
    {
        return array_sum(array_map(fn (array $t): int => self::RISK_WEIGHT[$t['risk_level']] ?? 1, $tasks));
    }

    /** @param  list<array<string,mixed>>  $tasks */
    private function combinedCapacity(array $tasks): int
    {
        return array_sum(array_column($tasks, 'worker_capacity_cost'));
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     * @return list<array{file:string, task_ids:list<string>}>
     */
    private function fileOverlaps(array $tasks): array
    {
        $ownersByFile = [];
        foreach ($tasks as $task) {
            foreach ($task['allowed_files'] as $file) {
                $ownersByFile[$file][] = $task['id'];
            }
        }

        $overlaps = [];
        foreach ($ownersByFile as $file => $owners) {
            if (count($owners) >= 2) {
                $overlaps[] = ['file' => (string) $file, 'task_ids' => $owners];
            }
        }
        usort($overlaps, static fn (array $a, array $b): int => strcmp($a['file'], $b['file']));

        return $overlaps;
    }

    /**
     * Greedy, deterministic partition: tasks sorted by risk weight descending (then id), each
     * placed in the first sub-batch it does not file-overlap with and that stays within the
     * risk/capacity limits, else a new sub-batch is opened.
     *
     * @param  list<array<string,mixed>>  $tasks
     * @return list<array{task_ids:list<string>, combined_risk:int, capacity_used:int}>
     */
    private function partitionIntoSubBatches(array $tasks, int $maxCombinedRisk, int $availableCapacity): array
    {
        usort($tasks, static fn (array $a, array $b): int =>
            (self::RISK_WEIGHT[$b['risk_level']] ?? 1) !== (self::RISK_WEIGHT[$a['risk_level']] ?? 1)
                ? (self::RISK_WEIGHT[$b['risk_level']] ?? 1) <=> (self::RISK_WEIGHT[$a['risk_level']] ?? 1)
                : strcmp($a['id'], $b['id'])
        );

        $subBatches = [];
        foreach ($tasks as $task) {
            $riskWeight = self::RISK_WEIGHT[$task['risk_level']] ?? 1;
            $placed = false;

            foreach ($subBatches as &$sub) {
                $overlapsSub = array_any($task['allowed_files'], static fn (string $f): bool => in_array($f, $sub['files'], true));
                if ($overlapsSub) {
                    continue;
                }
                if ($sub['combined_risk'] + $riskWeight > $maxCombinedRisk) {
                    continue;
                }
                if ($sub['capacity_used'] + $task['worker_capacity_cost'] > $availableCapacity) {
                    continue;
                }
                $sub['task_ids'][] = $task['id'];
                $sub['files'] = array_merge($sub['files'], $task['allowed_files']);
                $sub['combined_risk'] += $riskWeight;
                $sub['capacity_used'] += $task['worker_capacity_cost'];
                $placed = true;
                break;
            }
            unset($sub);

            if (! $placed) {
                $subBatches[] = [
                    'task_ids' => [$task['id']],
                    'files' => $task['allowed_files'],
                    'combined_risk' => $riskWeight,
                    'capacity_used' => $task['worker_capacity_cost'],
                ];
            }
        }

        return array_map(static fn (array $sub): array => [
            'task_ids' => $sub['task_ids'],
            'combined_risk' => $sub['combined_risk'],
            'capacity_used' => $sub['capacity_used'],
        ], $subBatches);
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<array{file:string, task_ids:list<string>}>  $overlaps
     * @param  list<array{task_ids:list<string>, combined_risk:int, capacity_used:int}>  $splitRecommendation
     * @param  list<string>  $unreadyIds
     */
    private function envelope(
        string $decision,
        array $reasons,
        array $overlaps,
        array $splitRecommendation,
        int $combinedRisk,
        int $maxCombinedRisk,
        int $capacityUsed,
        int $availableCapacity,
        array $unreadyIds,
    ): array {
        return [
            'schema' => self::SCHEMA,
            'decision' => $decision,
            'reasons' => $reasons,
            'overlapping_files' => $overlaps,
            'split_recommendation' => $splitRecommendation,
            'combined_risk_score' => $combinedRisk,
            'max_combined_risk' => $maxCombinedRisk,
            'worker_capacity_used' => $capacityUsed,
            'available_worker_capacity' => $availableCapacity === PHP_INT_MAX ? null : $availableCapacity,
            'prework_required_task_ids' => $unreadyIds,
        ];
    }
}
