<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Turns a failed or weakly delivered task into concrete spec rewrite advice
 * instead of letting it be blindly retried or duplicated. Pure: it never
 * mutates the queue or the original spec — it only returns advice.
 *
 * TERMINAL CASES (recommendation=give_back_or_retire, no rewrite attempted):
 *   give_back_root_cause in {capability_already_exists, forbidden_target, contradictory_acceptance}
 *
 * Otherwise recommendation=rewrite and rewrite_actions are derived from
 * outcome_lessons, give_back_root_cause, test_coverage_facts, and
 * changed_file_evidence (each action is null when its trigger did not fire):
 *
 *   objective            <- lesson give_back_due_to_unclear_spec OR root_cause=unclear_spec
 *                            -> clarify_objective_with_concrete_acceptance_examples
 *   allowed_files         <- lesson give_back_due_to_scope_mismatch OR root_cause=scope_too_narrow
 *                            -> expand_allowed_files_to_cover_required_implementation
 *   acceptance_criteria   <- lesson attempt_failed OR required_repair_after_initial_attempt
 *                            -> add_explicit_failure_mode_coverage_to_acceptance_criteria
 *   required_evidence     <- lesson self_reported_success_without_evidence
 *                            OR test_coverage_facts.tests_authored=false
 *                            -> require_runnable_test_command_in_acceptance_criteria
 *   dependencies          <- lesson give_back_unclassified_reason AND a duplicate signal
 *                            (give_back_root_cause contains "duplicate" OR lesson
 *                            duplicate_of_existing_capability is present)
 *                            -> add_dedup_check_against_existing_capability
 *   task_split_or_merge    <- count(changed_file_evidence) > 10
 *                            OR lesson large_changed_file_count_review_scope
 *                            -> split
 *
 * Also handled (each still a concrete, evidence-STRENGTHENING action — never a wrapper-only or
 * operator-dependent rewrite):
 *   allowed_files          <- lesson give_back_due_to_insufficient_allowed_files OR
 *                            root_cause=insufficient_allowed_files -> same expand action as scope_mismatch
 *   acceptance_criteria    <- poison_or_quarantine_count>=2 OR lesson repeated_poison_or_quarantine
 *                            -> tighten_acceptance_criteria_with_concrete_implementation_and_test_file_requirements
 *   acceptance_criteria    <- lesson shallow_acceptance_criteria
 *                            -> add_concrete_runnable_gate_examples_to_shallow_acceptance_criteria
 *   required_evidence      <- lesson weak_success
 *                            -> require_measurable_capability_delta_via_runnable_test_command
 *
 * INPUT:
 *   original_spec?:            array<string,mixed> (objective, allowed_files, acceptance_criteria, required_evidence, dependencies)
 *   outcome_lessons?:          list<string> (lesson codes, e.g. from AtlasExternalBrainPostImplementationLessonExtractor)
 *   give_back_root_cause?:     string (default '')
 *   test_coverage_facts?:      {tests_authored?: bool, tests_passed?: bool}
 *   changed_file_evidence?:    list<string> (default [])
 *   poison_or_quarantine_count?: int (default 0)
 *
 * OUTPUT:
 *   { schema, recommendation: rewrite|give_back_or_retire, reason,
 *     rewrite_actions: {objective, allowed_files, acceptance_criteria,
 *       required_evidence, dependencies, task_split_or_merge} }
 *
 * Pure: no I/O, no queue mutation, no side effects.
 */
final class AtlasExternalBrainSpecRewriteFromOutcomeAdvisor
{
    public const SCHEMA = 'atlas.external_brain.spec_rewrite_from_outcome_advisor.v1';

    private const TERMINAL_ROOT_CAUSES = [
        'capability_already_exists',
        'forbidden_target',
        'contradictory_acceptance',
    ];

    private const LARGE_CHANGED_FILE_COUNT = 10;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function advise(array $input): array
    {
        $giveBackRootCause = (string) ($input['give_back_root_cause'] ?? '');

        if (in_array($giveBackRootCause, self::TERMINAL_ROOT_CAUSES, true)) {
            return [
                'schema' => self::SCHEMA,
                'recommendation' => 'give_back_or_retire',
                'reason' => $giveBackRootCause,
                'rewrite_actions' => [
                    'objective' => null,
                    'allowed_files' => null,
                    'acceptance_criteria' => null,
                    'required_evidence' => null,
                    'dependencies' => null,
                    'task_split_or_merge' => null,
                ],
                'prioritized_actions' => ['retire_or_give_back'],
            ];
        }

        $lessons = is_array($input['outcome_lessons'] ?? null) ? array_map('strval', $input['outcome_lessons']) : [];
        $testCoverageFacts = is_array($input['test_coverage_facts'] ?? null) ? $input['test_coverage_facts'] : [];
        $testsAuthored = (bool) ($testCoverageFacts['tests_authored'] ?? true);
        $changedFileEvidence = is_array($input['changed_file_evidence'] ?? null) ? $input['changed_file_evidence'] : [];
        $poisonOrQuarantineCount = max(0, (int) ($input['poison_or_quarantine_count'] ?? 0));

        // "Repeated" = it was poisoned/quarantined more than once, OR the caller already knows the count and
        // tags the lesson directly — either way this is a distinct signal from a single one-off give_back.
        $repeatedPoisonOrQuarantine = $poisonOrQuarantineCount >= 2 || in_array('repeated_poison_or_quarantine', $lessons, true);
        $weakSuccess = in_array('weak_success', $lessons, true);
        $shallowAcceptance = in_array('shallow_acceptance_criteria', $lessons, true);

        $objective = (in_array('give_back_due_to_unclear_spec', $lessons, true) || $giveBackRootCause === 'unclear_spec')
            ? 'clarify_objective_with_concrete_acceptance_examples'
            : null;

        $allowedFiles = (
                in_array('give_back_due_to_scope_mismatch', $lessons, true)
                || in_array('give_back_due_to_insufficient_allowed_files', $lessons, true)
                || $giveBackRootCause === 'scope_too_narrow'
                || $giveBackRootCause === 'insufficient_allowed_files'
            )
            ? 'expand_allowed_files_to_cover_required_implementation'
            : null;

        // Ordered by specificity: an explicit repair/failure signal wins over the broader poison/shallow ones.
        $acceptanceCriteria = match (true) {
            in_array('attempt_failed', $lessons, true) => 'add_explicit_failure_mode_coverage_to_acceptance_criteria',
            in_array('required_repair_after_initial_attempt', $lessons, true) => 'add_explicit_failure_mode_coverage_to_acceptance_criteria',
            $repeatedPoisonOrQuarantine => 'tighten_acceptance_criteria_with_concrete_implementation_and_test_file_requirements',
            $shallowAcceptance => 'add_concrete_runnable_gate_examples_to_shallow_acceptance_criteria',
            default => null,
        };

        $requiredEvidence = match (true) {
            in_array('self_reported_success_without_evidence', $lessons, true) => 'require_runnable_test_command_in_acceptance_criteria',
            ! $testsAuthored => 'require_runnable_test_command_in_acceptance_criteria',
            $weakSuccess => 'require_measurable_capability_delta_via_runnable_test_command',
            default => null,
        };

        $duplicateSignal = str_contains(strtolower($giveBackRootCause), 'duplicate')
            || in_array('duplicate_of_existing_capability', $lessons, true);
        $dependencies = (in_array('give_back_unclassified_reason', $lessons, true) && $duplicateSignal)
            ? 'add_dedup_check_against_existing_capability'
            : null;

        $taskSplitOrMerge = (count($changedFileEvidence) > self::LARGE_CHANGED_FILE_COUNT || in_array('large_changed_file_count_review_scope', $lessons, true))
            ? 'split'
            : null;

        // Priority order: scope (allowed_files) before acceptance/evidence strengthening,
        // core correctness actions before the oversized-work split, which is always last.
        $prioritizedActions = array_values(array_filter([
            $allowedFiles !== null ? 'allowed_files' : null,
            $objective !== null ? 'objective' : null,
            $acceptanceCriteria !== null ? 'acceptance_criteria' : null,
            $requiredEvidence !== null ? 'required_evidence' : null,
            $dependencies !== null ? 'dependencies' : null,
            $taskSplitOrMerge !== null ? 'split_oversized_task' : null,
        ]));

        return [
            'schema' => self::SCHEMA,
            'recommendation' => 'rewrite',
            'reason' => 'spec_rewrite_advised_from_outcome_lessons',
            'rewrite_actions' => [
                'objective' => $objective,
                'allowed_files' => $allowedFiles,
                'acceptance_criteria' => $acceptanceCriteria,
                'required_evidence' => $requiredEvidence,
                'dependencies' => $dependencies,
                'task_split_or_merge' => $taskSplitOrMerge,
            ],
            'prioritized_actions' => $prioritizedActions,
        ];
    }
}
