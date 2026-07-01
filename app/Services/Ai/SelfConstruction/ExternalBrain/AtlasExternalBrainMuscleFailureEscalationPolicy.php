<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure escalation policy for worker/muscle failures. Converts repeated
 * give_back, test failure, scope violation, duplicate capability, malformed
 * acceptance, local client stall, and repeated retry events into a concrete
 * next action instead of letting the loop burn cycles retrying the same
 * broken thing.
 *
 * Each root cause has an escalation ladder (cheapest fix first, operator
 * last). The step taken is min(repeat_count, ladder length) so a cause that
 * keeps repeating always moves further down the ladder — it never resets.
 *
 * Hard rule: continue_retry is NEVER returned once repeat_count exceeds the
 * configurable threshold, even for root causes whose ladder nominally starts
 * there — the policy forces the next ladder step instead.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainMuscleFailureEscalationPolicy
{
    public const SCHEMA = 'atlas.external_brain.muscle_failure_escalation_policy.v1';

    public const ACTION_REPAIR_TASK_SPEC = 'repair_task_spec';
    public const ACTION_SWITCH_MUSCLE = 'switch_muscle';
    public const ACTION_ADJUST_PROMPT_VARIANT = 'adjust_prompt_variant';
    public const ACTION_QUARANTINE_CANDIDATE = 'quarantine_candidate';
    public const ACTION_OPERATOR_FIX_REQUIRED = 'operator_fix_required';
    public const ACTION_CONTINUE_RETRY = 'continue_retry';

    private const DEFAULT_THRESHOLD = 3;

    /** Relative severity so "escalate to X or stronger" comparisons are well-defined. */
    private const ACTION_RANK = [
        self::ACTION_CONTINUE_RETRY => 0,
        self::ACTION_ADJUST_PROMPT_VARIANT => 1,
        self::ACTION_REPAIR_TASK_SPEC => 2,
        self::ACTION_QUARANTINE_CANDIDATE => 2,
        self::ACTION_SWITCH_MUSCLE => 3,
        self::ACTION_OPERATOR_FIX_REQUIRED => 4,
    ];

    /** @var array<string, list<string>> */
    private const ESCALATION_LADDERS = [
        'give_back' => [
            self::ACTION_CONTINUE_RETRY,
            self::ACTION_ADJUST_PROMPT_VARIANT,
            self::ACTION_SWITCH_MUSCLE,
            self::ACTION_OPERATOR_FIX_REQUIRED,
        ],
        'test_failure' => [
            self::ACTION_ADJUST_PROMPT_VARIANT,
            self::ACTION_SWITCH_MUSCLE,
            self::ACTION_OPERATOR_FIX_REQUIRED,
        ],
        'scope_violation' => [
            self::ACTION_REPAIR_TASK_SPEC,
            self::ACTION_OPERATOR_FIX_REQUIRED,
        ],
        'duplicate_capability' => [
            self::ACTION_QUARANTINE_CANDIDATE,
        ],
        'malformed_acceptance' => [
            self::ACTION_REPAIR_TASK_SPEC,
            self::ACTION_OPERATOR_FIX_REQUIRED,
        ],
        'local_client_stall' => [
            self::ACTION_SWITCH_MUSCLE,
            self::ACTION_OPERATOR_FIX_REQUIRED,
        ],
        'repeated_retry' => [
            self::ACTION_CONTINUE_RETRY,
            self::ACTION_SWITCH_MUSCLE,
            self::ACTION_OPERATOR_FIX_REQUIRED,
        ],
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function escalate(array $facts): array
    {
        $rootCause = strtolower(trim((string) ($facts['root_cause'] ?? '')));
        $repeatCount = max(1, (int) ($facts['repeat_count'] ?? 1));
        $threshold = max(0, (int) ($facts['threshold'] ?? self::DEFAULT_THRESHOLD));
        $lastAction = (string) ($facts['last_action'] ?? '');
        $cooldownActive = (bool) ($facts['cooldown_active'] ?? false);

        $ladder = self::ESCALATION_LADDERS[$rootCause] ?? [
            self::ACTION_ADJUST_PROMPT_VARIANT,
            self::ACTION_SWITCH_MUSCLE,
            self::ACTION_OPERATOR_FIX_REQUIRED,
        ];
        $stepIndex = min($repeatCount - 1, count($ladder) - 1);
        $action = $ladder[$stepIndex];

        $thresholdExceeded = $repeatCount > $threshold;
        $forcedOffContinueRetry = false;
        if ($thresholdExceeded && $action === self::ACTION_CONTINUE_RETRY) {
            $forcedOffContinueRetry = true;
            $nextIndex = $stepIndex;
            while ($nextIndex < count($ladder) - 1 && $ladder[$nextIndex] === self::ACTION_CONTINUE_RETRY) {
                $nextIndex++;
            }
            $action = $ladder[$nextIndex] === self::ACTION_CONTINUE_RETRY
                ? self::ACTION_OPERATOR_FIX_REQUIRED
                : $ladder[$nextIndex];
        }

        // Last-action awareness: repeating adjust_prompt_variant is oscillation, not
        // progress — force the next step to at least switch_muscle severity.
        $forcedByLastAction = false;
        if ($lastAction === self::ACTION_ADJUST_PROMPT_VARIANT
            && (self::ACTION_RANK[$action] ?? 0) < self::ACTION_RANK[self::ACTION_SWITCH_MUSCLE]) {
            $action = self::ACTION_SWITCH_MUSCLE;
            $forcedByLastAction = true;
        }

        // Cooldown: never immediately re-trigger switch_muscle while a prior switch is
        // still cooling down — fall back to a spec-level fix (or operator) instead.
        $forcedByCooldown = false;
        if ($cooldownActive && $action === self::ACTION_SWITCH_MUSCLE) {
            $action = in_array(self::ACTION_REPAIR_TASK_SPEC, $ladder, true)
                ? self::ACTION_REPAIR_TASK_SPEC
                : self::ACTION_OPERATOR_FIX_REQUIRED;
            $forcedByCooldown = true;
        }

        return [
            'schema_version' => self::SCHEMA,
            'root_cause' => $rootCause,
            'repeat_count' => $repeatCount,
            'threshold' => $threshold,
            'threshold_exceeded' => $thresholdExceeded,
            'escalation_ladder' => $ladder,
            'escalation_action' => $action,
            'forced_off_continue_retry' => $forcedOffContinueRetry,
            'forced_by_last_action' => $forcedByLastAction,
            'forced_by_cooldown' => $forcedByCooldown,
        ];
    }
}
