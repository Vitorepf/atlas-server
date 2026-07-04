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

    // ── AC1: failure-class taxonomy (independent of the legacy root_cause ladder above) ──
    public const CLASS_TASK_POISON = 'task_poison';
    public const CLASS_WORKER_MISMATCH = 'worker_mismatch';
    public const CLASS_FLAKY_TEST = 'flaky_test';
    public const CLASS_SCOPE_GAP = 'scope_gap';
    public const CLASS_PROOF_GAP = 'proof_gap';
    public const CLASS_INFRASTRUCTURE_CONTENTION = 'infrastructure_contention';

    private const FAILURE_CLASSES = [
        self::CLASS_TASK_POISON,
        self::CLASS_WORKER_MISMATCH,
        self::CLASS_FLAKY_TEST,
        self::CLASS_SCOPE_GAP,
        self::CLASS_PROOF_GAP,
        self::CLASS_INFRASTRUCTURE_CONTENTION,
    ];

    // ── AC2: recommended_action taxonomy ──
    public const RECOMMEND_REPAIR = 'repair';
    public const RECOMMEND_RESPEC = 'respec';
    public const RECOMMEND_REROUTE = 'reroute';
    public const RECOMMEND_PAUSE = 'pause';
    public const RECOMMEND_ESCALATE = 'escalate';

    /** failure_class => recommended_action. */
    private const CLASS_TO_RECOMMENDED_ACTION = [
        self::CLASS_TASK_POISON => self::RECOMMEND_RESPEC,
        self::CLASS_SCOPE_GAP => self::RECOMMEND_RESPEC,
        self::CLASS_WORKER_MISMATCH => self::RECOMMEND_REROUTE,
        self::CLASS_FLAKY_TEST => self::RECOMMEND_REPAIR,
        self::CLASS_PROOF_GAP => self::RECOMMEND_REPAIR,
        self::CLASS_INFRASTRUCTURE_CONTENTION => self::RECOMMEND_PAUSE,
    ];

    /** Only infrastructure_contention is a legitimate reason to add more workers to a class. */
    private const REQUESTED_ACTION_ADD_MORE_WORKERS = 'add_more_workers';

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

        $failureClass = $this->classifyFailure($facts, $rootCause, $repeatCount);
        $recommendedAction = self::CLASS_TO_RECOMMENDED_ACTION[$failureClass] ?? self::RECOMMEND_ESCALATE;

        // AC3: reject "add more workers" as a fix for anything that isn't a genuine
        // infrastructure/capacity contention — more workers never fixes a poisoned task,
        // a scope gap, a proof gap, or a worker/class mismatch.
        $requestedAction = trim((string) ($facts['requested_action'] ?? ''));
        $requestedActionRejected = false;
        $requestedActionRejectionReason = null;
        if ($requestedAction === self::REQUESTED_ACTION_ADD_MORE_WORKERS
            && $failureClass !== self::CLASS_INFRASTRUCTURE_CONTENTION) {
            $requestedActionRejected = true;
            $requestedActionRejectionReason = sprintf(
                'adding_more_workers_does_not_fix_%s;_recommended_action_is_%s',
                $failureClass,
                $recommendedAction,
            );
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
            'failure_class' => $failureClass,
            'recommended_action' => $recommendedAction,
            'requested_action_rejected' => $requestedActionRejected,
            'requested_action_rejection_reason' => $requestedActionRejectionReason,
        ];
    }

    /**
     * AC1: classifies the failure into one of the 6 canonical classes. An explicit
     * `failure_class` fact always wins; otherwise derived from structured signals, falling
     * back to the legacy root_cause, and finally to task_poison — never silently assumed to
     * be a worker-capacity problem.
     *
     * @param  array<string,mixed>  $facts
     */
    private function classifyFailure(array $facts, string $rootCause, int $repeatCount): string
    {
        $explicit = strtolower(trim((string) ($facts['failure_class'] ?? '')));
        if (in_array($explicit, self::FAILURE_CLASSES, true)) {
            return $explicit;
        }

        if ((bool) ($facts['infrastructure_contention'] ?? false)) {
            return self::CLASS_INFRASTRUCTURE_CONTENTION;
        }
        if ((bool) ($facts['is_flaky'] ?? false)) {
            return self::CLASS_FLAKY_TEST;
        }
        if ((bool) ($facts['worker_mismatch'] ?? false) || $rootCause === 'local_client_stall') {
            return self::CLASS_WORKER_MISMATCH;
        }
        if ((int) ($facts['scope_violation_count'] ?? 0) > 0 || $rootCause === 'scope_violation') {
            return self::CLASS_SCOPE_GAP;
        }
        if ((int) ($facts['missing_evidence_count'] ?? 0) > 0) {
            return self::CLASS_PROOF_GAP;
        }
        if (in_array($rootCause, ['malformed_acceptance', 'duplicate_capability'], true)) {
            return self::CLASS_TASK_POISON;
        }
        if ($rootCause === 'give_back' && $repeatCount >= 2) {
            // Repeated give_back on the same task is a spec problem, not a worker shortage.
            return self::CLASS_TASK_POISON;
        }

        return self::CLASS_TASK_POISON;
    }

    /**
     * Failure escalation: maps failure types to distinct next actions, escalation levels,
     * and retry blocked reasons. Never returns continue_retry after repeat_count exceeds threshold.
     *
     * @param  array{
     *   root_cause: string,
     *   repeat_count: int,
     *   max_repeat_count: int,
     * }  $input
     * @return array{next_action:string, escalation_level:string, root_cause:string, retry_blocked_reason:string}
     */
    public function failureEscalation(array $input): array
    {
        $rootCause = (string) ($input['root_cause'] ?? '');
        $repeatCount = (int) ($input['repeat_count'] ?? 0);
        $maxRepeatCount = (int) ($input['max_repeat_count'] ?? 3);

        $retryBlocked = $repeatCount >= $maxRepeatCount;

        $nextAction = match ($rootCause) {
            'give_back' => $retryBlocked ? 'respec_task' : 'retry_with_adjusted_prompt',
            'scope_violation' => 'respec_task_with_scope_repair',
            'malformed_acceptance' => 'repair_acceptance_criteria',
            'local_client_stall' => 'switch_muscle',
            'repeated_retry' => 'quarantine_and_notify_operator',
            default => $retryBlocked ? 'switch_muscle' : 'retry_with_adjusted_prompt',
        };

        $escalationLevel = match (true) {
            $repeatCount >= $maxRepeatCount => 'critical',
            $repeatCount >= $maxRepeatCount - 1 => 'warning',
            default => 'info',
        };

        $retryBlockedReason = $retryBlocked
            ? sprintf('repeat_count=%d exceeds threshold=%d; escalation to %s', $repeatCount, $maxRepeatCount, $nextAction)
            : sprintf('repeat_count=%d within threshold=%d; may retry', $repeatCount, $maxRepeatCount);

        return [
            'next_action' => $nextAction,
            'escalation_level' => $escalationLevel,
            'root_cause' => $rootCause,
            'retry_blocked_reason' => $retryBlockedReason,
        ];
    }
}
