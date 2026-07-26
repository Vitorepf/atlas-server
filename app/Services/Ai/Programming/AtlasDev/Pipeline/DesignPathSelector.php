<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

/**
 * Deterministic Design Path Selector: today the choice of HOW to shape a change is implicit
 * inside routing (which only decides WHERE a run executes). This selector makes that choice
 * explicit and observable, reusing the same facts the pipeline already produces — task_kind
 * (TaskClassifier), risk (RiskLevelScorer), and the discovery manifest signals (likely files,
 * callers, tests) — never re-classifying or asking a model.
 *
 * design_path values:
 *   bugfix_root_cause       — repair-shaped task; fix the failing behavior at its root, not a
 *                              papered-over symptom.
 *   deletion_first           — a duplicate capability with no live callers; the safest shape is
 *                              deleting the redundant one, not patching around it.
 *   adapter_composition      — a duplicate capability WITH live callers; those callers must be
 *                              bridged, not stranded, so composition wins over blind deletion.
 *   gate_hardening           — a risky-classified task; the safe shape is strengthening the gate
 *                              around the risk, not a direct feature edit.
 *   replay_of_proven_pattern — a reusable design pattern is already proven; replay it instead of
 *                              inventing a new shape.
 *   safe_refactor            — an explicitly refactor-only task with no behavior change intended.
 *   feature_slice            — default: a bounded, testable vertical slice of new behavior.
 *
 * Input shape:
 *   { task_kind?:                string,        // TaskClassification::KIND_* value
 *     risk?:                     string,        // RiskLevelScorer::R0..R5
 *     likely_files?:             list<string>,
 *     callers?:                  list<string>,
 *     tests?:                    list<string>,
 *     duplicate_capability?:     bool,
 *     reusable_pattern_available?: bool,
 *     refactor_only?:            bool }
 *
 * Pure PHP, deterministic, no I/O, no model calls.
 */
final class DesignPathSelector
{
    public const PATH_BUGFIX_ROOT_CAUSE = 'bugfix_root_cause';

    public const PATH_FEATURE_SLICE = 'feature_slice';

    public const PATH_SAFE_REFACTOR = 'safe_refactor';

    public const PATH_DELETION_FIRST = 'deletion_first';

    public const PATH_ADAPTER_COMPOSITION = 'adapter_composition';

    public const PATH_GATE_HARDENING = 'gate_hardening';

    public const PATH_REPLAY_OF_PROVEN_PATTERN = 'replay_of_proven_pattern';

    /**
     * @param  array<string,mixed>  $facts
     * @return array{design_path:string, reason:string, evidence:array<string,mixed>}
     */
    public function select(array $facts): array
    {
        $taskKind = (string) ($facts['task_kind'] ?? '');
        $risk = (string) ($facts['risk'] ?? '');
        $likelyFiles = $this->stringList($facts['likely_files'] ?? null);
        $callers = $this->stringList($facts['callers'] ?? null);
        $tests = $this->stringList($facts['tests'] ?? null);
        $duplicateCapability = (bool) ($facts['duplicate_capability'] ?? false);
        $reusablePatternAvailable = (bool) ($facts['reusable_pattern_available'] ?? false);
        $refactorOnly = (bool) ($facts['refactor_only'] ?? false);

        $evidence = [
            'task_kind' => $taskKind,
            'risk' => $risk,
            'likely_file_count' => count($likelyFiles),
            'caller_count' => count($callers),
            'test_count' => count($tests),
        ];

        if ($duplicateCapability) {
            if ($callers !== []) {
                return [
                    'design_path' => self::PATH_ADAPTER_COMPOSITION,
                    'reason' => sprintf(
                        'duplicate_capability with %d live caller(s) — bridge callers through composition instead of stranding them behind a blind deletion.',
                        count($callers),
                    ),
                    'evidence' => $evidence,
                ];
            }

            return [
                'design_path' => self::PATH_DELETION_FIRST,
                'reason' => 'duplicate_capability with zero live callers — deleting the redundant implementation is the safest shape.',
                'evidence' => $evidence,
            ];
        }

        if ($taskKind === TaskClassification::KIND_REPAIR) {
            return [
                'design_path' => self::PATH_BUGFIX_ROOT_CAUSE,
                'reason' => sprintf(
                    'task_kind=repair with %d caller(s) identified in discovery — fix the failing behavior at its root, not a papered-over symptom.',
                    count($callers),
                ),
                'evidence' => $evidence,
            ];
        }

        if ($taskKind === TaskClassification::KIND_RISKY) {
            return [
                'design_path' => self::PATH_GATE_HARDENING,
                'reason' => 'task_kind=risky — the safe shape strengthens the gate around the risk rather than editing the feature directly.',
                'evidence' => $evidence,
            ];
        }

        if ($reusablePatternAvailable) {
            return [
                'design_path' => self::PATH_REPLAY_OF_PROVEN_PATTERN,
                'reason' => 'a reusable, already-proven design pattern is available — replay it instead of inventing a new shape.',
                'evidence' => $evidence,
            ];
        }

        if ($refactorOnly) {
            return [
                'design_path' => self::PATH_SAFE_REFACTOR,
                'reason' => 'explicitly refactor-only intent with no behavior change — the safe shape preserves behavior while restructuring.',
                'evidence' => $evidence,
            ];
        }

        return [
            'design_path' => self::PATH_FEATURE_SLICE,
            'reason' => sprintf('default shape for task_kind=%s: a bounded, testable vertical slice of new behavior.', $taskKind !== '' ? $taskKind : 'unknown'),
            'evidence' => $evidence,
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== ''));
    }
}
