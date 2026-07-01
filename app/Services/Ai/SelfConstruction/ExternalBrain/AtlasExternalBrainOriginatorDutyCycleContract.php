<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure contract: an external brain with an active mission is never allowed to
 * treat a comfortably deep queue as permission to stop. Every non-terminal
 * evaluation returns keep_originating, pivot_or_research, or
 * consolidate_with_next_batch — never stop, and never a bare "wait" that lets
 * the mission silently go idle.
 *
 * Terminal is reserved for explicit, machine-readable conditions: quota_complete,
 * disabled_switch, or no_value_after_exhaustive_escalation. A healthy queue depth
 * (replenish_action=wait) is NOT a terminal condition — it only downgrades the
 * action to consolidate_with_next_batch.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainOriginatorDutyCycleContract
{
    public const SCHEMA = 'atlas.external_brain.originator_duty_cycle_contract.v1';

    public const ACTION_KEEP_ORIGINATING = 'keep_originating';

    public const ACTION_PIVOT_OR_RESEARCH = 'pivot_or_research';

    public const ACTION_CONSOLIDATE_WITH_NEXT_BATCH = 'consolidate_with_next_batch';

    public const TERMINAL_QUOTA_COMPLETE = 'quota_complete';

    public const TERMINAL_DISABLED_SWITCH = 'disabled_switch';

    public const TERMINAL_NO_VALUE_AFTER_EXHAUSTIVE_ESCALATION = 'no_value_after_exhaustive_escalation';

    /** Claimable-depth-per-active-worker ratio at or above this is comfortable enough to consolidate. */
    private const COMFORTABLE_CLAIMABLE_PER_WORKER = 4.0;

    /** @var list<string> */
    private const ESCALATION_FRONTS = ['cross_domain_search', 'deep_architecture_scan', 'research_adaptation'];

    /**
     * @param  array{
     *   mission_active?: bool,
     *   quota_remaining?: int,
     *   quota_complete?: bool,
     *   brain_enabled?: bool,
     *   claimable_depth?: int,
     *   servable_now?: int,
     *   active_workers?: int,
     *   replenish_action?: string,
     *   local_surface_exhausted?: bool,
     *   exhaustive_escalation_attempted?: bool,
     *   value_found_after_escalation?: bool,
     * }  $facts
     * @return array{schema:string, terminal:bool, terminal_reason:?string, next_action:?string, required_escalation_fronts:list<string>, reasons:list<string>}
     */
    public function evaluate(array $facts): array
    {
        $quotaComplete = (bool) ($facts['quota_complete'] ?? false);
        $brainEnabled = (bool) ($facts['brain_enabled'] ?? true);
        $exhaustiveEscalationAttempted = (bool) ($facts['exhaustive_escalation_attempted'] ?? false);
        $valueFoundAfterEscalation = (bool) ($facts['value_found_after_escalation'] ?? true);

        if ($quotaComplete) {
            return $this->terminalResult(self::TERMINAL_QUOTA_COMPLETE);
        }

        if (! $brainEnabled) {
            return $this->terminalResult(self::TERMINAL_DISABLED_SWITCH);
        }

        if ($exhaustiveEscalationAttempted && ! $valueFoundAfterEscalation) {
            return $this->terminalResult(self::TERMINAL_NO_VALUE_AFTER_EXHAUSTIVE_ESCALATION);
        }

        $localSurfaceExhausted = (bool) ($facts['local_surface_exhausted'] ?? false);
        if ($localSurfaceExhausted) {
            return [
                'schema' => self::SCHEMA,
                'terminal' => false,
                'terminal_reason' => null,
                'next_action' => self::ACTION_PIVOT_OR_RESEARCH,
                'required_escalation_fronts' => self::ESCALATION_FRONTS,
                'reasons' => ['local_surface_exhausted:pivot_required_instead_of_no_task_created'],
            ];
        }

        $claimableDepth = max(0, (int) ($facts['claimable_depth'] ?? 0));
        $activeWorkers = max(1, (int) ($facts['active_workers'] ?? 1));
        $replenishAction = (string) ($facts['replenish_action'] ?? '');
        $claimablePerWorker = $claimableDepth / $activeWorkers;

        if ($replenishAction === 'wait' && $claimablePerWorker >= self::COMFORTABLE_CLAIMABLE_PER_WORKER) {
            return [
                'schema' => self::SCHEMA,
                'terminal' => false,
                'terminal_reason' => null,
                'next_action' => self::ACTION_CONSOLIDATE_WITH_NEXT_BATCH,
                'required_escalation_fronts' => [],
                'reasons' => ['queue_comfortable:'.round($claimablePerWorker, 2).'_per_worker_but_mission_active_so_consolidate_not_stop'],
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'terminal' => false,
            'terminal_reason' => null,
            'next_action' => self::ACTION_KEEP_ORIGINATING,
            'required_escalation_fronts' => [],
            'reasons' => ['mission_active_and_no_terminal_condition_present'],
        ];
    }

    private function terminalResult(string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'terminal' => true,
            'terminal_reason' => $reason,
            'next_action' => null,
            'required_escalation_fronts' => [],
            'reasons' => [$reason],
        ];
    }
}
