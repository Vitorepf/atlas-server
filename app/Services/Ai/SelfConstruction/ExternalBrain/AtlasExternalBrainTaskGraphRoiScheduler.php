<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Orders accepted macro-tasks into execution waves using topological dependency ordering
 * combined with ROI scoring within each topological layer.
 *
 * ORDERING RULES:
 *   1. Prerequisites must appear in earlier waves than dependents (Kahn topological sort).
 *   2. Within the same topological layer, tasks are ranked by ROI score:
 *        ROI = (expected_impact × unlock_value) / (cost_risk + 0.01)
 *      High unlock_value (a task that unblocks many dependents) outranks cheap isolated work.
 *   3. Worker pressure scales down wave width: at high pressure only essentials ship first.
 *   4. Waves are capped at max_wave_width (default 5).
 *
 * WARNINGS emitted when:
 *   - Two or more tasks in the same wave share an allowed_file (same-file collision).
 *   - A wave exceeds max_wave_width (over_width).
 *
 * INPUT tasks:
 *   list<{ task_id:string, depends_on?:list<string>, expected_impact?:float,
 *          cost_risk?:float, unlock_value?:float, allowed_files?:list<string> }>
 *
 * INPUT context:
 *   { worker_pressure?:float, max_wave_width?:int }
 *
 * OUTPUT:
 *   { schema, waves:list<Wave>, warnings:list<string>,
 *     critical_path:list<string>, frontier_unlocks:array<string,list<string>>,
 *     dependency_dead_end_warnings:list<string>,
 *     next_wave_candidate_reasons:array<string,list<string>> }
 *
 * Wave:
 *   { wave_index:int, tasks:list<string>, parallel_safe:bool,
 *     collisions:list<string>, over_width:bool }
 *
 * critical_path             — longest dependency chain (task_ids in order)
 * frontier_unlocks          — per-task: which tasks become available after it completes
 * dependency_dead_end_warnings — leaf tasks with unlock_value=0 (no chain leverage)
 * next_wave_candidate_reasons  — per wave-0 task: why it matters (roi, chain unlock, critical path)
 *
 * PURE / DETERMINISTIC (stable sort, deterministic tie-break on task_id). No I/O.
 */
final class AtlasExternalBrainTaskGraphRoiScheduler
{
    public const SCHEMA = 'atlas.external_brain.task_graph_roi_scheduler.v1';

    public const DEFAULT_MAX_WAVE_WIDTH = 5;

    /**
     * @param  list<array{task_id?:string, depends_on?:list<string>, expected_impact?:float,
     *                    cost_risk?:float, unlock_value?:float, allowed_files?:list<string>}>  $tasks
     * @param  array{worker_pressure?:float, max_wave_width?:int}  $context
     * @return array{schema:string, waves:list<array<string,mixed>>, warnings:list<string>}
     */
    public function schedule(array $tasks, array $context = []): array
    {
        $workerPressure = max(0.0, min(1.0, (float) ($context['worker_pressure'] ?? 0.0)));
        $maxWidth = max(1, (int) ($context['max_wave_width'] ?? self::DEFAULT_MAX_WAVE_WIDTH));

        // family_risk = array<family→float> lowers order within a layer; poison_family_hints = list<family> (full risk).
        $familyRisk = [];
        foreach ((array) ($context['family_risk'] ?? []) as $fam => $score) {
            $familyRisk[(string) $fam] = min(1.0, max(0.0, (float) $score));
        }
        foreach ((array) ($context['poison_family_hints'] ?? []) as $fam) {
            $familyRisk[(string) $fam] = 1.0;
        }

        // Scale wave width down under high pressure: at pressure=1.0, width halves.
        $effectiveWidth = max(1, (int) round($maxWidth * (1.0 - $workerPressure * 0.5)));

        // Normalise tasks — filter empty ids.
        $taskMap = [];
        foreach ($tasks as $t) {
            $id = trim((string) ($t['task_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $taskMap[$id] = [
                'task_id'                   => $id,
                'depends_on'                => is_array($t['depends_on'] ?? null) ? array_values(array_filter(array_map('strval', $t['depends_on']))) : [],
                'expected_impact'           => max(0.0, min(1.0, (float) ($t['expected_impact'] ?? 0.5))),
                'cost_risk'                 => max(0.0, min(1.0, (float) ($t['cost_risk'] ?? 0.5))),
                'unlock_value'              => max(0.0, min(1.0, (float) ($t['unlock_value'] ?? 0.0))),
                'allowed_files'             => is_array($t['allowed_files'] ?? null) ? array_values(array_filter(array_map('strval', $t['allowed_files']))) : [],
                'task_family'               => trim((string) ($t['task_family'] ?? '')),
                'give_back_risk'            => max(0.0, min(1.0, (float) ($t['give_back_risk'] ?? 0.0))),
                'blocked_prerequisite_risk' => max(0.0, min(1.0, (float) ($t['blocked_prerequisite_risk'] ?? 0.0))),
            ];
        }

        if ($taskMap === []) {
            return [
                'schema'                       => self::SCHEMA,
                'waves'                        => [],
                'warnings'                     => [],
                'critical_path'                => [],
                'frontier_unlocks'             => [],
                'dependency_dead_end_warnings' => [],
                'next_wave_candidate_reasons'  => [],
                'risk_adjusted_roi'            => [],
                'delayed_poison_family_tasks'  => [],
                'wave_candidate_reasons'       => [],
            ];
        }

        // ── Kahn's topological sort → layers ──────────────────────────────────
        $layers = $this->topoLayers($taskMap);

        // ── Score and sort within each layer ──────────────────────────────────
        $waves = [];
        $warnings = [];
        $waveIndex = 0;
        $riskAdjustedRoi = [];
        $delayedPoisonFamilyTasks = [];
        $criticalPathForDelay = array_flip($this->computeCriticalPath($taskMap, $layers));

        foreach ($layers as $layer) {
            // Sort: adjusted-ROI DESC (penalised by family risk, give_back_risk, blocked_prerequisite_risk), task_id ASC for determinism.
            usort($layer, function (string $a, string $b) use ($taskMap, $familyRisk): int {
                $ra = $this->computeTaskScore($taskMap[$a], $familyRisk)['adjusted_roi'];
                $rb = $this->computeTaskScore($taskMap[$b], $familyRisk)['adjusted_roi'];

                return $ra !== $rb ? ($rb <=> $ra) : strcmp($a, $b);
            });

            // AC1/AC2: track adjusted ROI per task and flag poison-family tasks that were
            // delayed behind at least one safer, equally-available task in the same layer,
            // unless that poison-family task is a required prerequisite on the critical path.
            $seenSafeTaskInLayer = false;
            foreach ($layer as $id) {
                $score = $this->computeTaskScore($taskMap[$id], $familyRisk);
                $riskAdjustedRoi[$id] = round($score['adjusted_roi'], 4);

                $isPoisonFamily = $score['family_risk'] >= 1.0;
                if ($isPoisonFamily && $seenSafeTaskInLayer && ! isset($criticalPathForDelay[$id])) {
                    $delayedPoisonFamilyTasks[] = $id;
                }
                if (! $isPoisonFamily) {
                    $seenSafeTaskInLayer = true;
                }
            }

            // Chunk into waves respecting effectiveWidth.
            foreach (array_chunk($layer, $effectiveWidth) as $chunk) {
                [$wave, $waveWarnings] = $this->buildWave($waveIndex, $chunk, $taskMap, $maxWidth);
                $waves[] = $wave;
                $warnings = array_merge($warnings, $waveWarnings);
                $waveIndex++;
            }
        }

        $frontierUnlocks          = $this->buildFrontierUnlocks($taskMap);
        $criticalPath             = $this->computeCriticalPath($taskMap, $layers);
        $deadEndWarnings          = $this->computeDeadEndWarnings($taskMap, $frontierUnlocks);
        $downstreamReach          = $this->computeDownstreamReach($taskMap, $frontierUnlocks, $layers);
        $nextWaveCandidateReasons = $this->computeNextWaveCandidateReasons($taskMap, $waves, $criticalPath, $downstreamReach, $familyRisk);
        $waveCandidateReasons     = $this->computeWaveCandidateReasons($taskMap, $criticalPath, $downstreamReach, $familyRisk);

        return [
            'schema'                       => self::SCHEMA,
            'waves'                        => $waves,
            'warnings'                     => array_values(array_unique($warnings)),
            'critical_path'                => $criticalPath,
            'frontier_unlocks'             => $frontierUnlocks,
            'dependency_dead_end_warnings' => $deadEndWarnings,
            'next_wave_candidate_reasons'  => $nextWaveCandidateReasons,
            'risk_adjusted_roi'            => $riskAdjustedRoi,
            'delayed_poison_family_tasks'  => array_values(array_unique($delayedPoisonFamilyTasks)),
            'wave_candidate_reasons'       => $waveCandidateReasons,
        ];
    }

    /**
     * Kahn's algorithm — returns ordered list of layers (each layer = tasks with no unmet deps).
     * Cycles are broken by appending remaining nodes as a final layer (fail-open, emit warning later).
     *
     * @param  array<string, array<string, mixed>>  $taskMap
     * @return list<list<string>>
     */
    private function topoLayers(array $taskMap): array
    {
        $knownIds = array_keys($taskMap);
        $inDegree = array_fill_keys($knownIds, 0);
        $revEdges = array_fill_keys($knownIds, []);

        foreach ($taskMap as $id => $t) {
            foreach ($t['depends_on'] as $dep) {
                if (! isset($inDegree[$dep])) {
                    continue; // Unknown dependency — ignore.
                }
                $inDegree[$id]++;
                $revEdges[$dep][] = $id;
            }
        }

        $layers = [];
        $ready = array_keys(array_filter($inDegree, static fn (int $d): bool => $d === 0));
        sort($ready); // deterministic start

        while ($ready !== []) {
            $layers[] = $ready;
            $next = [];
            foreach ($ready as $id) {
                foreach ($revEdges[$id] as $dependent) {
                    $inDegree[$dependent]--;
                    if ($inDegree[$dependent] === 0) {
                        $next[] = $dependent;
                    }
                }
            }
            sort($next);
            $ready = $next;
        }

        // Cycle residue: emit in a final layer so we don't lose tasks.
        $remaining = array_keys(array_filter($inDegree, static fn (int $d): bool => $d > 0));
        if ($remaining !== []) {
            sort($remaining);
            $layers[] = $remaining;
        }

        return $layers;
    }

    /**
     * @param  array<string, array<string, mixed>>  $taskMap
     * @return array<string, list<string>>
     */
    private function buildFrontierUnlocks(array $taskMap): array
    {
        $unlocks = array_fill_keys(array_keys($taskMap), []);
        foreach ($taskMap as $id => $task) {
            foreach ($task['depends_on'] as $dep) {
                if (isset($unlocks[$dep])) {
                    $unlocks[$dep][] = $id;
                }
            }
        }
        foreach ($unlocks as &$list) {
            sort($list);
        }
        return $unlocks;
    }

    /**
     * Longest dependency chain via DP over topological layers.
     *
     * @param  array<string, array<string, mixed>>  $taskMap
     * @param  list<list<string>>  $layers
     * @return list<string>
     */
    private function computeCriticalPath(array $taskMap, array $layers): array
    {
        $dist = array_fill_keys(array_keys($taskMap), 0);
        $prev = array_fill_keys(array_keys($taskMap), null);

        foreach ($layers as $layer) {
            foreach ($layer as $id) {
                foreach ($taskMap[$id]['depends_on'] as $dep) {
                    if (isset($dist[$dep]) && $dist[$dep] + 1 > $dist[$id]) {
                        $dist[$id] = $dist[$dep] + 1;
                        $prev[$id] = $dep;
                    }
                }
            }
        }

        $endNode = (string) array_key_first($dist);
        foreach ($dist as $id => $d) {
            if ($d > $dist[$endNode] || ($d === $dist[$endNode] && strcmp($id, $endNode) < 0)) {
                $endNode = $id;
            }
        }

        $path = [];
        $cur  = $endNode;
        while ($cur !== null) {
            array_unshift($path, $cur);
            $cur = $prev[$cur];
        }

        return $path;
    }

    /**
     * @param  array<string, array<string, mixed>>  $taskMap
     * @param  array<string, list<string>>  $frontierUnlocks
     * @return list<string>
     */
    private function computeDeadEndWarnings(array $taskMap, array $frontierUnlocks): array
    {
        $warnings = [];
        foreach ($taskMap as $id => $task) {
            if ($frontierUnlocks[$id] === [] && $task['unlock_value'] == 0.0) {
                $warnings[] = $id;
            }
        }
        sort($warnings);
        return $warnings;
    }

    /**
     * Transitive downstream reach per task (count of all tasks unlocked transitively).
     * Processed in reverse topological order so children are resolved before parents.
     *
     * @param  array<string, array<string, mixed>>  $taskMap
     * @param  array<string, list<string>>  $frontierUnlocks
     * @param  list<list<string>>  $layers
     * @return array<string, int>
     */
    private function computeDownstreamReach(array $taskMap, array $frontierUnlocks, array $layers): array
    {
        $reach = array_fill_keys(array_keys($taskMap), 0);
        foreach (array_reverse($layers) as $layer) {
            foreach ($layer as $id) {
                $direct = $frontierUnlocks[$id];
                $count  = count($direct);
                foreach ($direct as $dep) {
                    $count += $reach[$dep] ?? 0;
                }
                $reach[$id] = $count;
            }
        }
        return $reach;
    }

    /**
     * Per wave-0 task: list of reasons it belongs in the next wave.
     * Always emits roi_score; adds chain_unlocker when downstream reach > 0;
     * adds on_critical_path when applicable.
     *
     * @param  array<string, array<string, mixed>>  $taskMap
     * @param  list<array<string, mixed>>  $waves
     * @param  list<string>  $criticalPath
     * @param  array<string, int>  $downstreamReach
     * @return array<string, list<string>>
     */
    /**
     * @param  array<string, float>  $familyRisk
     * @return array<string, list<string>>
     */
    private function computeNextWaveCandidateReasons(
        array $taskMap,
        array $waves,
        array $criticalPath,
        array $downstreamReach,
        array $familyRisk = [],
    ): array {
        $reasons     = [];
        $firstWave   = $waves[0]['tasks'] ?? [];
        $criticalSet = array_flip($criticalPath);

        foreach ($firstWave as $id) {
            $task        = $taskMap[$id];
            $score       = $this->computeTaskScore($task, $familyRisk);
            $taskReasons = [sprintf('roi_score:%.3f', round($score['raw_roi'], 3))];

            $reach = $downstreamReach[$id] ?? 0;
            if ($reach > 0) {
                $taskReasons[] = "chain_unlocker:unlocks_{$reach}_downstream_tasks";
            }

            if (isset($criticalSet[$id])) {
                $taskReasons[] = 'on_critical_path';
            }

            $family = $task['task_family'];
            if ($score['family_risk'] > 0.0 && $family !== '') {
                $taskReasons[] = sprintf('risk_penalty:family=%s:penalty=%.2f', $family, $score['family_risk']);
            }

            if ($task['give_back_risk'] > 0.0) {
                $taskReasons[] = sprintf('give_back_risk:%.2f', $task['give_back_risk']);
            }
            if ($task['blocked_prerequisite_risk'] > 0.0) {
                $taskReasons[] = sprintf('blocked_prerequisite_risk:%.2f', $task['blocked_prerequisite_risk']);
            }

            $reasons[$id] = $taskReasons;
        }

        return $reasons;
    }

    /**
     * General version of computeNextWaveCandidateReasons — covers EVERY task in the schedule,
     * not only wave 0, so callers can inspect why any task ranks where it does.
     *
     * @param  array<string, array<string, mixed>>  $taskMap
     * @param  list<string>  $criticalPath
     * @param  array<string, int>  $downstreamReach
     * @param  array<string, float>  $familyRisk
     * @return array<string, list<string>>
     */
    private function computeWaveCandidateReasons(
        array $taskMap,
        array $criticalPath,
        array $downstreamReach,
        array $familyRisk,
    ): array {
        $reasons = [];
        $criticalSet = array_flip($criticalPath);

        foreach ($taskMap as $id => $task) {
            $score = $this->computeTaskScore($task, $familyRisk);
            $taskReasons = [sprintf('roi_score:%.3f', round($score['raw_roi'], 3))];

            $reach = $downstreamReach[$id] ?? 0;
            if ($reach > 0) {
                $taskReasons[] = "chain_unlocker:unlocks_{$reach}_downstream_tasks";
            }

            if (isset($criticalSet[$id])) {
                $taskReasons[] = 'on_critical_path';
            }

            $family = $task['task_family'];
            if ($score['family_risk'] > 0.0 && $family !== '') {
                $taskReasons[] = sprintf('risk_penalty:family=%s:penalty=%.2f', $family, $score['family_risk']);
            }

            if ($task['give_back_risk'] > 0.0) {
                $taskReasons[] = sprintf('give_back_risk:%.2f', $task['give_back_risk']);
            }
            if ($task['blocked_prerequisite_risk'] > 0.0) {
                $taskReasons[] = sprintf('blocked_prerequisite_risk:%.2f', $task['blocked_prerequisite_risk']);
            }

            $reasons[$id] = $taskReasons;
        }

        return $reasons;
    }

    /**
     * Single task-score circuit: computes raw ROI and the family/give_back/
     * blocked-prerequisite-adjusted ROI from one place, so wave ordering and
     * next_wave_candidate_reasons can never disagree about a task's score.
     *
     * @param  array<string, mixed>  $task
     * @param  array<string, float>  $familyRisk
     * @return array{raw_roi:float, adjusted_roi:float, family_risk:float}
     */
    private function computeTaskScore(array $task, array $familyRisk): array
    {
        $rawRoi = $task['expected_impact'] * $task['unlock_value'] / ($task['cost_risk'] + 0.01);
        $risk   = $familyRisk[$task['task_family']] ?? 0.0;

        $adjustedRoi = $rawRoi
            * (1.0 - $risk * 0.5)
            * (1.0 - $task['give_back_risk'])
            * (1.0 - $task['blocked_prerequisite_risk']);

        return [
            'raw_roi'      => $rawRoi,
            'adjusted_roi' => $adjustedRoi,
            'family_risk'  => $risk,
        ];
    }

    /**
     * @param  list<string>  $taskIds
     * @param  array<string, array<string, mixed>>  $taskMap
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function buildWave(int $index, array $taskIds, array $taskMap, int $maxWidth): array
    {
        $warnings = [];

        // Detect same-file collisions within this wave.
        $fileSeen = [];
        $collisions = [];
        foreach ($taskIds as $id) {
            foreach ($taskMap[$id]['allowed_files'] as $f) {
                if (isset($fileSeen[$f])) {
                    $collisions[] = $f;
                    $warnings[] = "wave_{$index}:same_file_collision:{$f}";
                }
                $fileSeen[$f] = true;
            }
        }
        $collisions = array_values(array_unique($collisions));

        $overWidth = count($taskIds) > $maxWidth;
        if ($overWidth) {
            $warnings[] = "wave_{$index}:over_width:".count($taskIds).">{$maxWidth}";
        }

        return [
            [
                'wave_index' => $index,
                'tasks' => $taskIds,
                'parallel_safe' => $collisions === [],
                'collisions' => $collisions,
                'over_width' => $overWidth,
            ],
            $warnings,
        ];
    }
}
