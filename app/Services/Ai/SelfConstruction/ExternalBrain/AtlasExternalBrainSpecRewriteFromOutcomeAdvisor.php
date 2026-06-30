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
 * INPUT:
 *   original_spec?:          array<string,mixed> (objective, allowed_files, acceptance_criteria, required_evidence, dependencies)
 *   outcome_lessons?:        list<string> (lesson codes, e.g. from AtlasExternalBrainPostImplementationLessonExtractor)
 *   give_back_root_cause?:   string (default '')
 *   test_coverage_facts?:    {tests_authored?: bool, tests_passed?: bool}
 *   changed_file_evidence?:  list<string> (default [])
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
            ];
        }

        $lessons = is_array($input['outcome_lessons'] ?? null) ? array_map('strval', $input['outcome_lessons']) : [];
        $testCoverageFacts = is_array($input['test_coverage_facts'] ?? null) ? $input['test_coverage_facts'] : [];
        $testsAuthored = (bool) ($testCoverageFacts['tests_authored'] ?? true);
        $changedFileEvidence = is_array($input['changed_file_evidence'] ?? null) ? $input['changed_file_evidence'] : [];

        $objective = (in_array('give_back_due_to_unclear_spec', $lessons, true) || $giveBackRootCause === 'unclear_spec')
            ? 'clarify_objective_with_concrete_acceptance_examples'
            : null;

        $allowedFiles = (in_array('give_back_due_to_scope_mismatch', $lessons, true) || $giveBackRootCause === 'scope_too_narrow')
            ? 'expand_allowed_files_to_cover_required_implementation'
            : null;

        $acceptanceCriteria = (in_array('attempt_failed', $lessons, true) || in_array('required_repair_after_initial_attempt', $lessons, true))
            ? 'add_explicit_failure_mode_coverage_to_acceptance_criteria'
            : null;

        $requiredEvidence = (in_array('self_reported_success_without_evidence', $lessons, true) || ! $testsAuthored)
            ? 'require_runnable_test_command_in_acceptance_criteria'
            : null;

        $duplicateSignal = str_contains(strtolower($giveBackRootCause), 'duplicate')
            || in_array('duplicate_of_existing_capability', $lessons, true);
        $dependencies = (in_array('give_back_unclassified_reason', $lessons, true) && $duplicateSignal)
            ? 'add_dedup_check_against_existing_capability'
            : null;

        $taskSplitOrMerge = (count($changedFileEvidence) > self::LARGE_CHANGED_FILE_COUNT || in_array('large_changed_file_count_review_scope', $lessons, true))
            ? 'split'
            : null;

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
        ];
    }
}
