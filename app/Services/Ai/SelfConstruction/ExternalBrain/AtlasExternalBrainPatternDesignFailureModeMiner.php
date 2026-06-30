<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure failure-mode miner. Turns repeated implementation and queue failures
 * into reusable design patterns with concrete prevention rules, enforcement
 * hooks, affected task families, and falsification checks — so future batches
 * cannot recreate the same failure shape.
 *
 * Rejection hierarchy per candidate (first match wins):
 *   one_off_anecdote       — task_count < 2
 *   duplicate_label        — root_cause_label already promoted
 *   no_prevention_rule     — prevention_rule is empty
 *   no_enforcement_hook    — enforcement_hook is empty
 *   no_affected_family     — affected_task_family is empty
 *   no_falsification_check — falsification_check is empty
 *
 * A candidate is promoted when all six above pass.
 *
 * Confidence: high (task_count ≥ 5) | medium (task_count ≥ 2)
 *
 * Output: promoted_patterns, rejected_candidates, enforcement_hooks,
 *         confidence_reasons, task_fabric_patch_hints.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainPatternDesignFailureModeMiner
{
    public const SCHEMA = 'atlas.external_brain.pattern_design_failure_mode_miner.v1';

    public const REJECTION_ONE_OFF_ANECDOTE       = 'one_off_anecdote';
    public const REJECTION_DUPLICATE_LABEL        = 'duplicate_label';
    public const REJECTION_NO_PREVENTION_RULE     = 'no_prevention_rule';
    public const REJECTION_NO_ENFORCEMENT_HOOK    = 'no_enforcement_hook';
    public const REJECTION_NO_AFFECTED_FAMILY     = 'no_affected_family';
    public const REJECTION_NO_FALSIFICATION_CHECK = 'no_falsification_check';

    public const CONFIDENCE_HIGH   = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';

    private const CONFIDENCE_HIGH_THRESHOLD = 5;

    /**
     * @param  array{failure_candidates?: list<array>}  $input
     * @return array<string,mixed>
     */
    public function mine(array $input): array
    {
        $candidates = (array) ($input['failure_candidates'] ?? []);

        $promoted          = [];
        $rejected          = [];
        $confidenceReasons = [];
        $promotedLabels    = [];
        $hooks             = [];
        $patchHints        = [];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $failureId           = trim((string) ($candidate['failure_id']           ?? ''));
            $rootCauseLabel      = trim((string) ($candidate['root_cause_label']     ?? ''));
            $taskCount           = max(0, (int) ($candidate['task_count']            ?? 0));
            $description         = trim((string) ($candidate['description']          ?? ''));
            $preventionRule      = trim((string) ($candidate['prevention_rule']      ?? ''));
            $enforcementHook     = trim((string) ($candidate['enforcement_hook']     ?? ''));
            $affectedTaskFamily  = trim((string) ($candidate['affected_task_family'] ?? ''));
            $falsificationCheck  = trim((string) ($candidate['falsification_check']  ?? ''));

            $reason = $this->reject(
                $taskCount, $rootCauseLabel, $preventionRule, $enforcementHook,
                $affectedTaskFamily, $falsificationCheck, $promotedLabels,
            );

            if ($reason !== null) {
                $rejected[] = ['candidate' => $candidate, 'rejection_reason' => $reason];
                continue;
            }

            $confidence = $taskCount >= self::CONFIDENCE_HIGH_THRESHOLD
                ? self::CONFIDENCE_HIGH
                : self::CONFIDENCE_MEDIUM;

            $promoted[] = [
                'failure_id'           => $failureId,
                'root_cause_label'     => $rootCauseLabel,
                'task_count'           => $taskCount,
                'description'          => $description,
                'prevention_rule'      => $preventionRule,
                'enforcement_hook'     => $enforcementHook,
                'affected_task_family' => $affectedTaskFamily,
                'falsification_check'  => $falsificationCheck,
                'confidence'           => $confidence,
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

            $patchHints[] = [
                'family'          => $affectedTaskFamily,
                'hook'            => $enforcementHook,
                'prevention_rule' => $preventionRule,
                'failure_id'      => $failureId,
            ];
        }

        return [
            'schema'                  => self::SCHEMA,
            'promoted_patterns'       => $promoted,
            'rejected_candidates'     => $rejected,
            'enforcement_hooks'       => $hooks,
            'confidence_reasons'      => $confidenceReasons,
            'task_fabric_patch_hints' => $patchHints,
        ];
    }

    private function reject(
        int $taskCount,
        string $rootCauseLabel,
        string $preventionRule,
        string $enforcementHook,
        string $affectedTaskFamily,
        string $falsificationCheck,
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
        if ($affectedTaskFamily === '') {
            return self::REJECTION_NO_AFFECTED_FAMILY;
        }
        if ($falsificationCheck === '') {
            return self::REJECTION_NO_FALSIFICATION_CHECK;
        }

        return null;
    }
}
