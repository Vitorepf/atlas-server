<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure, facts-only advisor for whether a backlog task should be RETIRED, RESPEC'd, or KEPT. Keeps the
 * queue from becoming a museum of old plans (superseded or duplicate work gets retired) while protecting
 * genuinely useful blocked work (a clear repair path and real strategic value earns a respec, not a
 * deletion). Decides per task; the caller iterates a backlog and calls advise() once per item.
 *
 * Input task shape:
 *   {task_id, family, age_days:int, status:'claimable'|'blocked', give_back_count?:int,
 *    superseded_capability?:bool, duplicate_target?:bool, repair_path_clear?:bool,
 *    strategic_value?:'low'|'medium'|'high'}
 */
final class AtlasExternalBrainStaleBacklogRetirementAdvisor
{
    public const SCHEMA = 'atlas.self_construction.external_brain.stale_backlog_retirement_advisor.v1';

    public const DECISION_RETIRE = 'retire';

    public const DECISION_RESPEC = 'respec';

    public const DECISION_RESPEC_REFRESH = 'respec_refresh';

    public const DECISION_KEEP = 'keep';

    private const OLD_THRESHOLD_DAYS = 30;

    private const AGING_THRESHOLD_DAYS = 14;

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    public function advise(array $task): array
    {
        $taskId = (string) ($task['task_id'] ?? '');
        $family = (string) ($task['family'] ?? '');
        $ageDays = (int) ($task['age_days'] ?? 0);
        $status = (string) ($task['status'] ?? 'claimable');
        $superseded = (bool) ($task['superseded_capability'] ?? false);
        $duplicate = (bool) ($task['duplicate_target'] ?? false);
        $repairPathClear = (bool) ($task['repair_path_clear'] ?? false);
        $strategicValue = (string) ($task['strategic_value'] ?? 'low');
        $replacementTaskId = trim((string) ($task['replacement_task_id'] ?? ''));

        $ageBand = match (true) {
            $ageDays < self::AGING_THRESHOLD_DAYS => 'fresh',
            $ageDays < self::OLD_THRESHOLD_DAYS => 'aging',
            default => 'stale',
        };
        $isOld = $ageDays >= self::OLD_THRESHOLD_DAYS;

        if ($isOld && ($superseded || $duplicate)) {
            $reason = $superseded ? 'superseded_capability' : 'duplicate_target';

            if ($replacementTaskId !== '') {
                return $this->result($taskId, $family, $ageBand, self::DECISION_RETIRE, $reason, 'remove_from_queue');
            }

            return $this->result(
                $taskId, $family, $ageBand, self::DECISION_RESPEC,
                $reason.'_without_replacement_proof',
                'confirm_replacement_task_id_before_retirement',
            );
        }

        if ($isOld && $status === 'blocked' && $repairPathClear && $strategicValue === 'high') {
            return $this->result(
                $taskId, $family, $ageBand, self::DECISION_RESPEC,
                'old_blocked_high_value_with_clear_repair_path',
                'rewrite_spec_with_clear_repair_path',
            );
        }

        // AC3: a stale (not superseded/duplicate/blocked-with-clear-repair-path) packet whose
        // leverage is still high is not dead work — its SPEC needs revalidating against the
        // current codebase before it is safe to serve, not a full rewrite and not a deletion.
        if ($isOld && $strategicValue === 'high') {
            return $this->result(
                $taskId, $family, $ageBand, self::DECISION_RESPEC_REFRESH,
                'stale_still_high_leverage',
                'refresh_spec_against_current_codebase_before_serving',
            );
        }

        if (! $isOld && $strategicValue === 'high' && $status === 'claimable') {
            return $this->result($taskId, $family, $ageBand, self::DECISION_KEEP, 'fresh_high_value_claimable', 'no_action_needed');
        }

        return $this->result($taskId, $family, $ageBand, self::DECISION_KEEP, 'no_strong_retirement_signal', 'no_action_needed');
    }

    /**
     * @return array<string, mixed>
     */
    private function result(string $taskId, string $family, string $ageBand, string $decision, string $reason, string $nextAction): array
    {
        return [
            'schema' => self::SCHEMA,
            'task_id' => $taskId,
            'family' => $family,
            'age_band' => $ageBand,
            'decision' => $decision,
            'reason' => $reason,
            'next_action' => $nextAction,
        ];
    }
}
