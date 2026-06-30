<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure failure-mode miner. Turns repeated implementation and queue failures
 * into reusable design patterns with concrete prevention rules and task-fabric
 * enforcement hooks, so future batches cannot recreate the same failure shape.
 *
 * Rejection hierarchy per candidate (first match wins):
 *   one_off_anecdote    — task_count < 2; a single failure is not a pattern
 *   duplicate_label     — root_cause_label already promoted in this batch
 *   no_prevention_rule  — prevention_rule is empty
 *   no_enforcement_hook — enforcement_hook is empty (no task-fabric hook)
 *
 * A candidate is promoted when:
 *   - task_count ≥ 2 (at least two independent failures)
 *   - root_cause_label is unique in the promoted set
 *   - prevention_rule is non-empty
 *   - enforcement_hook is non-empty
 *
 * Confidence: high (task_count ≥ 5) | medium (task_count ≥ 2)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainPatternDesignFailureModeMiner
{
    public const SCHEMA = 'atlas.external_brain.pattern_design_failure_mode_miner.v1';

    public const REJECTION_ONE_OFF_ANECDOTE    = 'one_off_anecdote';
    public const REJECTION_DUPLICATE_LABEL     = 'duplicate_label';
    public const REJECTION_NO_PREVENTION_RULE  = 'no_prevention_rule';
    public const REJECTION_NO_ENFORCEMENT_HOOK = 'no_enforcement_hook';

    public const CONFIDENCE_HIGH   = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';

    private const CONFIDENCE_HIGH_THRESHOLD = 5;

    /**
     * @param  array{failure_candidates?: list<array>}  $input
     * @return array{schema:string, promoted_patterns:list<array>, rejected_candidates:list<array>, enforcement_hooks:list<string>, confidence_reasons:list<array>}
     */
    public function mine(array $input): array
    {
        $candidates = (array) ($input['failure_candidates'] ?? []);

        $promoted          = [];
        $rejected          = [];
        $confidenceReasons = [];
        $promotedLabels    = [];
        $hooks             = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $failureId      = trim((string) ($candidate['failure_id']        ?? ''));
            $rootCauseLabel = trim((string) ($candidate['root_cause_label']  ?? ''));
            $taskCount      = max(0, (int) ($candidate['task_count']         ?? 0));
            $description    = trim((string) ($candidate['description']       ?? ''));
            $preventionRule = trim((string) ($candidate['prevention_rule']   ?? ''));
            $enforcementHook = trim((string) ($candidate['enforcement_hook'] ?? ''));

            $reason = $this->reject($taskCount, $rootCauseLabel, $preventionRule, $enforcementHook, $promotedLabels);

            if ($reason !== null) {
                $rejected[] = ['candidate' => $candidate, 'rejection_reason' => $reason];
                continue;
            }

            $confidence = $taskCount >= self::CONFIDENCE_HIGH_THRESHOLD
                ? self::CONFIDENCE_HIGH
                : self::CONFIDENCE_MEDIUM;

            $promoted[] = [
                'failure_id'       => $failureId,
                'root_cause_label' => $rootCauseLabel,
                'task_count'       => $taskCount,
                'description'      => $description,
                'prevention_rule'  => $preventionRule,
                'enforcement_hook' => $enforcementHook,
                'confidence'       => $confidence,
            ];

            $confidenceReasons[] = [
                'failure_id'       => $failureId,
                'root_cause_label' => $rootCauseLabel,
                'confidence'       => $confidence,
                'reason'           => $taskCount >= self::CONFIDENCE_HIGH_THRESHOLD
                    ? "task_count={$taskCount} ≥ 5; strong repeated evidence"
                    : "task_count={$taskCount} ≥ 2; meets minimum independence threshold",
            ];

            $promotedLabels[$rootCauseLabel] = true;

            if (! in_array($enforcementHook, $hooks, true)) {
                $hooks[] = $enforcementHook;
            }
        }

        return [
            'schema'             => self::SCHEMA,
            'promoted_patterns'  => $promoted,
            'rejected_candidates' => $rejected,
            'enforcement_hooks'  => $hooks,
            'confidence_reasons' => $confidenceReasons,
        ];
    }

    private function reject(
        int $taskCount,
        string $rootCauseLabel,
        string $preventionRule,
        string $enforcementHook,
        array $promotedLabels,
    ): ?string {
        if ($taskCount < 2) {
            return self::REJECTION_ONE_OFF_ANECDOTE;
        }

        if (isset($promotedLabels[$rootCauseLabel])) {
            return self::REJECTION_DUPLICATE_LABEL;
        }

        if ($preventionRule === '') {
            return self::REJECTION_NO_PREVENTION_RULE;
        }

        if ($enforcementHook === '') {
            return self::REJECTION_NO_ENFORCEMENT_HOOK;
        }

        return null;
    }
}
