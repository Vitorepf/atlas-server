<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure release gate. A candidate batch task only enters the queue when it
 * fits the current critical path, covers an under-served roadmap gap, or
 * repairs a real blocker. It NEVER enqueues or mutates anything itself — it
 * only reports facts for a separate enqueue step to act on.
 *
 * A candidate is admissible when ANY of:
 *   on_critical_path        = true
 *   covers_roadmap_gap      = true
 *   repairs_blocker         = true
 *
 * AC3 — off-path leaf work (none of the above true) is BLOCKED whenever the
 * critical path still has unresolved prerequisites, i.e.
 * critical_path_task_ids is non-empty. Off-path work is only allowed once
 * the critical path is fully clear.
 *
 * A candidate is ALSO blocked when its novelty_score is below
 * novelty_threshold (default 0.3) — a near-duplicate batch entry, even if
 * it would otherwise fit the path/gap/repair criteria.
 *
 * release_allowed (batch-level) = blocked_task_ids is empty.
 *
 * INPUT:
 *   candidates: list<{
 *     task_id:               string
 *     on_critical_path?:     bool (default false)
 *     covers_roadmap_gap?:   bool (default false)
 *     repairs_blocker?:      bool (default false)
 *     novelty_score?:        float (default 1.0)
 *   }>
 *   critical_path_task_ids:  list<string> (unresolved critical-path tasks)
 *   backlog_depth?:          int
 *   novelty_threshold?:      float (default 0.3)
 *
 * Pure: no I/O, no side effects, never enqueues or mutates a task.
 */
final class AtlasExternalBrainTaskGraphReleaseGate
{
    public const SCHEMA = 'atlas.external_brain.task_graph_release_gate.v1';

    private const DEFAULT_NOVELTY_THRESHOLD = 0.30;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $candidates = is_array($input['candidates'] ?? null) ? $input['candidates'] : [];
        $criticalPathTaskIds = is_array($input['critical_path_task_ids'] ?? null)
            ? array_map('strval', $input['critical_path_task_ids'])
            : [];
        $backlogDepth = max(0, (int) ($input['backlog_depth'] ?? 0));
        $noveltyThreshold = (float) ($input['novelty_threshold'] ?? self::DEFAULT_NOVELTY_THRESHOLD);

        $criticalPathUnresolved = $criticalPathTaskIds !== [];

        $blockedTaskIds = [];
        $releaseReasons = [];
        $requiredRewrites = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || ! isset($candidate['task_id'])) {
                continue;
            }
            $taskId = (string) $candidate['task_id'];
            $onCriticalPath = (bool) ($candidate['on_critical_path'] ?? false);
            $coversRoadmapGap = (bool) ($candidate['covers_roadmap_gap'] ?? false);
            $repairsBlocker = (bool) ($candidate['repairs_blocker'] ?? false);
            $noveltyScore = (float) ($candidate['novelty_score'] ?? 1.0);

            $admissible = $onCriticalPath || $coversRoadmapGap || $repairsBlocker;
            $offPathWhileUnresolved = ! $admissible && $criticalPathUnresolved;
            $lowNovelty = $noveltyScore < $noveltyThreshold;

            if ($offPathWhileUnresolved) {
                $blockedTaskIds[] = $taskId;
                $releaseReasons[] = sprintf('%s: off_path_while_critical_path_unresolved (critical_path_remaining=%d)', $taskId, count($criticalPathTaskIds));
                $requiredRewrites[] = sprintf('rescope %s onto the critical path or defer until the critical path clears', $taskId);
            }

            if ($lowNovelty) {
                $blockedTaskIds[] = $taskId;
                $releaseReasons[] = sprintf('%s: low_novelty_duplicate_risk (novelty_score=%.2f < threshold=%.2f)', $taskId, $noveltyScore, $noveltyThreshold);
                $requiredRewrites[] = sprintf('merge or pivot %s per the novelty gate before re-submitting', $taskId);
            }
        }

        $blockedTaskIds = array_values(array_unique($blockedTaskIds));

        return [
            'schema' => self::SCHEMA,
            'release_allowed' => $blockedTaskIds === [],
            'blocked_task_ids' => $blockedTaskIds,
            'release_reasons' => $releaseReasons,
            'required_rewrites' => $requiredRewrites,
            'critical_path_unresolved' => $criticalPathUnresolved,
            'backlog_depth' => $backlogDepth,
        ];
    }
}
