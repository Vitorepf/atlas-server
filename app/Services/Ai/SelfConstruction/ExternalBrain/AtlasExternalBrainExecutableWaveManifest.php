<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure executable-wave manifest builder. Turns a large prioritized task-graph backlog into a
 * small, ordered wave that muscles can execute immediately — clear priority, clear scope, clear
 * evidence expectations — instead of handing over the whole backlog undifferentiated.
 *
 * INPUT:
 *   tasks: list<{
 *     task_id:            string
 *     priority?:          float   (0..1, default 0.5)
 *     on_critical_path?:  bool    (default false)
 *     blocked?:           bool    (default false)
 *     stale?:             bool    (default false)
 *     duplicate?:         bool    (default false)
 *     novelty_score?:     float   (0..1, default 1.0)
 *     is_repair_blocker?: bool    (default false) — overrides every exclusion below
 *     unlocks?:           list<string>  — task_ids this task's completion unblocks
 *     prerequisite_task_ids?: list<string>  (default [])  — AC2/AC3 new
 *     prerequisites_proven?:  bool           (default false) — AC3 new; only checked when
 *                                                               prerequisite_task_ids is non-empty
 *     proof_gate?:             string        (default '')  — AC2 new; falls back to
 *                                                              'tests_or_gates_result' when empty
 *     expected_capability_delta?: string     (default '')  — AC2 new
 *     touches_runtime_or_queue?:  bool       (default false) — AC4 new
 *     rollback_notes?:            string     (default '')  — AC4 new; required when
 *                                                              touches_runtime_or_queue=true
 *   }>
 *   muscle_routing_hints?: array<string, string>  — task_id => suggested muscle_id
 *   queue_depth?:           int
 *   max_wave_size?:         int  (default 5)
 *   low_novelty_threshold?: float (default 0.20)
 *
 * worker_ready (AC3/AC4 new): false whenever any wave task has an UNPROVEN prerequisite
 * (prerequisite_task_ids non-empty AND prerequisites_proven=false) or touches runtime/queue
 * behavior without rollback_notes — the specific per-task reasons are listed in
 * not_worker_ready_reasons rather than silently degrading to worker_ready=true.
 *
 * EXCLUSION (AC3 — first match wins, never applies when is_repair_blocker=true):
 *   blocked              — blocked === true
 *   stale                — stale === true
 *   duplicate            — duplicate === true
 *   low_novelty          — novelty_score < low_novelty_threshold
 *   off_critical_path    — on_critical_path === false
 *
 * Eligible tasks are ordered by priority DESC (critical-path tasks already required to pass the
 * critical-path filter), task_id ASC as a deterministic tiebreak, then capped at max_wave_size —
 * anything cut by the cap is also recorded in out_of_wave_reasons as wave_size_cap_exceeded.
 *
 * OUTPUT:
 *   { schema, wave_id, ordered_task_ids, task_reasons, expected_unlocks,
 *     assigned_muscle_hints, out_of_wave_reasons }
 *
 * Pure / deterministic. No I/O, no provider calls.
 */
final class AtlasExternalBrainExecutableWaveManifest
{
    public const SCHEMA = 'atlas.external_brain.executable_wave_manifest.v1';

    private const DEFAULT_MAX_WAVE_SIZE       = 5;
    private const DEFAULT_LOW_NOVELTY_THRESHOLD = 0.20;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        $rawTasks      = is_array($input['tasks'] ?? null) ? $input['tasks'] : [];
        $routingHints  = is_array($input['muscle_routing_hints'] ?? null) ? $input['muscle_routing_hints'] : [];
        $maxWaveSize   = max(1, (int) ($input['max_wave_size'] ?? self::DEFAULT_MAX_WAVE_SIZE));
        $noveltyFloor  = (float) ($input['low_novelty_threshold'] ?? self::DEFAULT_LOW_NOVELTY_THRESHOLD);

        $eligible = [];
        $outOfWaveReasons = [];

        foreach ($rawTasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $id = trim((string) ($task['task_id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $priority       = max(0.0, min(1.0, (float) ($task['priority'] ?? 0.5)));
            $onCriticalPath = (bool) ($task['on_critical_path'] ?? false);
            $blocked        = (bool) ($task['blocked'] ?? false);
            $stale          = (bool) ($task['stale'] ?? false);
            $duplicate      = (bool) ($task['duplicate'] ?? false);
            $novelty        = max(0.0, min(1.0, (float) ($task['novelty_score'] ?? 1.0)));
            $isRepairBlocker = (bool) ($task['is_repair_blocker'] ?? false);
            $unlocks        = array_values(array_unique(array_map('strval', (array) ($task['unlocks'] ?? []))));
            $prerequisiteTaskIds = array_values(array_unique(array_map('strval', (array) ($task['prerequisite_task_ids'] ?? []))));
            $prerequisitesProven = (bool) ($task['prerequisites_proven'] ?? false);
            $proofGate      = trim((string) ($task['proof_gate'] ?? ''));
            $expectedCapabilityDelta = trim((string) ($task['expected_capability_delta'] ?? ''));
            $touchesRuntimeOrQueue = (bool) ($task['touches_runtime_or_queue'] ?? false);
            $rollbackNotes  = trim((string) ($task['rollback_notes'] ?? ''));

            $exclusionReason = $isRepairBlocker ? null : match (true) {
                $blocked              => 'blocked',
                $stale                => 'stale',
                $duplicate            => 'duplicate',
                $novelty < $noveltyFloor => 'low_novelty',
                ! $onCriticalPath     => 'off_critical_path',
                default               => null,
            };

            if ($exclusionReason !== null) {
                $outOfWaveReasons[$id] = $exclusionReason;
                continue;
            }

            $eligible[] = [
                'task_id'         => $id,
                'priority'        => $priority,
                'on_critical_path' => $onCriticalPath,
                'is_repair_blocker' => $isRepairBlocker,
                'unlocks'         => $unlocks,
                'prerequisite_task_ids' => $prerequisiteTaskIds,
                'prerequisites_proven' => $prerequisitesProven,
                'proof_gate' => $proofGate,
                'expected_capability_delta' => $expectedCapabilityDelta,
                'touches_runtime_or_queue' => $touchesRuntimeOrQueue,
                'rollback_notes' => $rollbackNotes,
            ];
        }

        usort($eligible, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority'] ?: strcmp($a['task_id'], $b['task_id']));

        $wave = array_slice($eligible, 0, $maxWaveSize);
        foreach (array_slice($eligible, $maxWaveSize) as $cut) {
            $outOfWaveReasons[$cut['task_id']] = 'wave_size_cap_exceeded';
        }

        $orderedTaskIds  = array_column($wave, 'task_id');
        $taskReasons     = [];
        $expectedUnlocks = [];
        $assignedMuscleHints = [];
        $prerequisites = [];
        $proofGates = [];
        $expectedCapabilityDelta = [];
        $rollbackNotes = [];
        $notWorkerReadyReasons = [];

        foreach ($wave as $entry) {
            $id = $entry['task_id'];
            $taskReasons[$id] = $entry['is_repair_blocker']
                ? 'repair_blocker_override'
                : sprintf('on_critical_path:priority=%.2f', $entry['priority']);
            $expectedUnlocks[$id] = $entry['unlocks'];
            if (isset($routingHints[$id])) {
                $assignedMuscleHints[$id] = (string) $routingHints[$id];
            }

            $prerequisites[$id] = $entry['prerequisite_task_ids'];
            $proofGates[$id] = [$entry['proof_gate'] !== '' ? $entry['proof_gate'] : 'tests_or_gates_result'];
            $expectedCapabilityDelta[$id] = $entry['expected_capability_delta'];
            $rollbackNotes[$id] = $entry['rollback_notes'];

            if ($entry['prerequisite_task_ids'] !== [] && ! $entry['prerequisites_proven']) {
                $notWorkerReadyReasons[] = "missing_prerequisite_proof:{$id}";
            }
            if ($entry['touches_runtime_or_queue'] && $entry['rollback_notes'] === '') {
                $notWorkerReadyReasons[] = "missing_rollback_notes:{$id}";
            }
        }

        ksort($outOfWaveReasons);

        return [
            'schema'                 => self::SCHEMA,
            'wave_id'                => 'wave-'.substr(hash('sha256', implode(',', $orderedTaskIds)), 0, 12),
            'ordered_task_ids'       => $orderedTaskIds,
            'task_reasons'           => $taskReasons,
            'expected_unlocks'       => $expectedUnlocks,
            'assigned_muscle_hints'  => $assignedMuscleHints,
            'out_of_wave_reasons'    => $outOfWaveReasons,
            'prerequisites'          => $prerequisites,
            'proof_gates'            => $proofGates,
            'expected_capability_delta' => $expectedCapabilityDelta,
            'rollback_notes'         => $rollbackNotes,
            'worker_ready'           => $notWorkerReadyReasons === [],
            'not_worker_ready_reasons' => $notWorkerReadyReasons,
        ];
    }
}
