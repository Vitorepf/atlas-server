<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure curriculum builder: promotes repeated model failure modes into challenge
 * cases, runbook reminders, and preflight checks for future small-model runs.
 *
 * Promotion rule: a failure_type is promoted when it appears across
 * ≥ PROMOTION_THRESHOLD unique run_ids OR ≥ PROMOTION_THRESHOLD unique task_classes.
 * Single-occurrence candidates are rejected with reason 'below_threshold'.
 *
 * Output: promoted_blind_spots, rejected_candidates, curriculum_items, injection_rules.
 */
final class AtlasExternalBrainBlindSpotCurriculum
{
    public const SCHEMA = 'atlas.external_brain.blind_spot_curriculum.v1';

    public const PROMOTION_THRESHOLD = 2;

    private const CATALOG = [
        'proxy_risk' => [
            'challenge_cases' => [
                'Objective matches cleanup/rename/whitespace pattern',
                'Single-file refactor with no observable behavior change',
            ],
            'runbook_reminders' => [
                'Verify objective adds new capability, not cosmetic change',
                'Confirm acceptance criteria require behavior proof',
            ],
            'preflight_checks' => [
                'objective_contains_no_proxy_keywords',
                'acceptance_requires_behavior_test',
            ],
            'stop_repeating_rule' => 'never_admit_a_task_whose_objective_is_cosmetic_only',
            'required_evidence' => 'acceptance_requires_behavior_test',
        ],
        'operator_dependency' => [
            'challenge_cases' => [
                'Task requires manual approval mid-execution',
                'Task description includes "ask operator" or "human confirms"',
            ],
            'runbook_reminders' => [
                'Ensure task is fully autonomous end-to-end',
                'Verify no human-in-the-loop steps exist in acceptance criteria',
            ],
            'preflight_checks' => [
                'no_manual_step_in_acceptance',
                'task_has_no_human_approval_gate',
            ],
            'stop_repeating_rule' => 'never_admit_a_task_with_a_human_approval_gate_in_acceptance',
            'required_evidence' => 'task_has_no_human_approval_gate',
        ],
        'duplicate_target' => [
            'challenge_cases' => [
                'Same allowed_file targeted in multiple concurrent packets',
                'Capability already implemented under a different class name',
            ],
            'runbook_reminders' => [
                'Search codebase for similar class names before implementing',
                'Check capability registry for existing equivalents',
            ],
            'preflight_checks' => [
                'no_duplicate_allowed_file_across_active_tasks',
                'capability_not_in_registry',
            ],
            'stop_repeating_rule' => 'never_originate_a_task_without_a_dedup_search_against_existing_capabilities',
            'required_evidence' => 'capability_registry_search_performed',
        ],
        'low_leverage' => [
            'challenge_cases' => [
                'Objective shorter than 50 chars with vague goal',
                'Single-line change packaged as a full task',
            ],
            'runbook_reminders' => [
                'Require objective to specify measurable impact',
                'Ensure task unlocks or unblocks downstream capabilities',
            ],
            'preflight_checks' => [
                'objective_length_above_50_chars',
                'task_has_downstream_dependents_or_unlocks',
            ],
            'stop_repeating_rule' => 'never_originate_a_task_whose_only_justification_is_ease_of_implementation',
            'required_evidence' => 'task_has_downstream_dependents_or_unlocks',
        ],
        'false_green_acceptance' => [
            'challenge_cases' => [
                'All acceptance criteria are exit-code-only checks',
                'Tests pass but feature is not wired to any consumer',
            ],
            'runbook_reminders' => [
                'Require at least one criterion that tests behavior, not just exit code',
                'Verify wiring to a real consumer exists before marking complete',
            ],
            'preflight_checks' => [
                'acceptance_criteria_include_behavior_assertion',
                'wiring_to_consumer_verified',
            ],
            'stop_repeating_rule' => 'never_mark_complete_without_verified_wiring_to_a_real_consumer',
            'required_evidence' => 'wiring_to_consumer_verified',
        ],
        'weak_acceptance' => [
            'challenge_cases' => [
                'Acceptance criterion only says "tests pass" with no behavior named',
                'Acceptance criteria are satisfied by a stub implementation',
            ],
            'runbook_reminders' => [
                'Rewrite each acceptance criterion to assert the specific behavior being proven',
                'Reject acceptance criteria that a no-op implementation could satisfy',
            ],
            'preflight_checks' => [
                'acceptance_criteria_assert_specific_behavior',
                'acceptance_criteria_reject_no_op_implementations',
            ],
            'stop_repeating_rule' => 'never_accept_acceptance_criteria_that_are_exit_code_only',
            'required_evidence' => 'acceptance_criteria_assert_specific_behavior',
        ],
        'over_complexity' => [
            'challenge_cases' => [
                'Implementation adds abstractions not required by acceptance criteria',
                'Class has more than 3 responsibilities or > 200 LOC for a simple task',
            ],
            'runbook_reminders' => [
                'Verify each added class / interface is directly required by acceptance criteria',
                'Delete abstraction layers that survive only for hypothetical future use',
            ],
            'preflight_checks' => [
                'no_speculative_abstractions',
                'class_count_within_task_scope',
            ],
            'stop_repeating_rule' => 'never_add_an_abstraction_not_directly_required_by_acceptance_criteria',
            'required_evidence' => 'no_speculative_abstractions',
        ],
        'missed_dedup' => [
            'challenge_cases' => [
                'Same logic duplicated across two or more classes within the same PR',
                'New helper replicates an existing utility already in the codebase',
            ],
            'runbook_reminders' => [
                'Grep for existing utilities before implementing a new helper',
                'Consolidate duplicated logic into the canonical home before adding new callers',
            ],
            'preflight_checks' => [
                'no_duplicate_logic_in_allowed_files',
                'existing_utility_search_done',
            ],
            'stop_repeating_rule' => 'never_add_a_helper_without_searching_for_an_existing_equivalent',
            'required_evidence' => 'existing_utility_search_done',
        ],
        'no_runnable_evidence' => [
            'challenge_cases' => [
                'Acceptance criterion cannot be tested by running the code directly',
                'Evidence path requires a live external system unavailable in CI',
            ],
            'runbook_reminders' => [
                'Each acceptance criterion must map to a runnable assertion (PHPUnit, artisan, or CLI)',
                'Replace live-system evidence with a hermetic fixture or in-process stub',
            ],
            'preflight_checks' => [
                'all_acceptance_criteria_have_runnable_evidence_path',
                'no_external_system_dependency_in_evidence',
            ],
            'stop_repeating_rule' => 'never_admit_a_decision_without_a_runnable_evidence_reference',
            'required_evidence' => 'all_acceptance_criteria_have_runnable_evidence_path',
        ],
        'provider_dependency' => [
            'challenge_cases' => [
                'Implementation calls an LLM provider directly instead of going through Atlas gateway',
                'Output includes raw provider prompts, session IDs, or unredacted trace data',
            ],
            'runbook_reminders' => [
                'Route all provider calls through AtlasAiGateway; never call provider SDKs directly',
                'Redact provider_prompt, provider_session_id, and private_trace before any output',
            ],
            'preflight_checks' => [
                'no_direct_provider_sdk_calls',
                'output_is_provider_safe',
            ],
            'stop_repeating_rule' => 'never_call_a_provider_sdk_directly_outside_the_atlas_gateway',
            'required_evidence' => 'output_is_provider_safe',
        ],
    ];

    public const GROUPS = [
        'missed_evidence',
        'bad_scope',
        'weak_acceptance',
        'duplicate_target',
        'low_impact_reasoning',
    ];

    private const GROUP_CATALOG = [
        'missed_evidence' => [
            'lesson' => 'A claim without runnable evidence is not proof — collect the evidence before trusting the claim',
            'practice_case' => 'Given a give_back with no tests_or_gates_result attached, identify the missing evidence and name the exact command that would produce it',
            'required_evidence' => 'tests_or_gates_result',
            'stop_repeating_rule' => 'never_admit_a_decision_without_a_runnable_evidence_reference',
        ],
        'bad_scope' => [
            'lesson' => 'Scope that does not cover every file the implementation actually needs guarantees a give_back',
            'practice_case' => 'Given an objective and an allowed_files list, identify the caller files missing from scope before claiming the task is implementable',
            'required_evidence' => 'allowed_files_cover_all_required_callers',
            'stop_repeating_rule' => 'never_author_a_task_whose_allowed_files_excludes_a_required_caller',
        ],
        'weak_acceptance' => [
            'lesson' => 'Acceptance criteria that only check exit codes let a regression slip through unnoticed',
            'practice_case' => 'Given an acceptance criterion that only says "tests pass", rewrite it to assert the specific behavior being proven',
            'required_evidence' => 'acceptance_criteria_assert_specific_behavior',
            'stop_repeating_rule' => 'never_accept_acceptance_criteria_that_are_exit_code_only',
        ],
        'duplicate_target' => [
            'lesson' => 'Originating a task for a capability that already exists wastes a full cycle on a guaranteed give_back',
            'practice_case' => 'Given a proposed class name and objective, search the codebase for an existing equivalent before authoring the task',
            'required_evidence' => 'capability_registry_search_performed',
            'stop_repeating_rule' => 'never_originate_a_task_without_a_dedup_search_against_existing_capabilities',
        ],
        'low_impact_reasoning' => [
            'lesson' => 'A task justified only by curiosity or ease, not by measured impact on quality or autonomy, is queue padding',
            'practice_case' => 'Given two candidate tasks, one easy and low-impact, one harder and high-impact, justify selecting the high-impact one',
            'required_evidence' => 'impact_on_quality_or_autonomy_is_quantified',
            'stop_repeating_rule' => 'never_originate_a_task_whose_only_justification_is_ease_of_implementation',
        ],
    ];

    private const GENERIC = [
        'challenge_cases' => [
            'Repeated failure in unclassified context',
            'Model applied incorrect pattern without recognized type',
        ],
        'runbook_reminders' => [
            'Investigate root cause of this failure type before next run',
            'Add a specific preflight check once root cause is known',
        ],
        'preflight_checks' => [
            'manual_review_required_for_this_failure_type',
        ],
        'stop_repeating_rule' => 'investigate_root_cause_before_next_run',
        'required_evidence' => 'manual_review_required_for_this_failure_type',
    ];

    /**
     * @param  array<string,mixed>  $input  failure_observations list
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        $observations = is_array($input['failure_observations'] ?? null) ? $input['failure_observations'] : [];

        $byType = [];
        foreach ($observations as $obs) {
            $type = (string) ($obs['failure_type'] ?? 'unknown');
            $runId = (string) ($obs['run_id'] ?? '');
            $taskClass = (string) ($obs['task_class'] ?? '');
            $byType[$type]['runs'][$runId] = true;
            $byType[$type]['task_classes'][$taskClass] = true;
        }

        $promotedBlindSpots = [];
        $rejectedCandidates = [];
        $curriculumItems = [];
        $injectionRules = [];

        foreach ($byType as $type => $groups) {
            $uniqueRuns = count($groups['runs'] ?? []);
            $uniqueClasses = count($groups['task_classes'] ?? []);
            $promoted = $uniqueRuns >= self::PROMOTION_THRESHOLD || $uniqueClasses >= self::PROMOTION_THRESHOLD;

            if ($promoted) {
                $catalog = self::CATALOG[$type] ?? self::GENERIC;
                $promotedBlindSpots[] = [
                    'type' => $type,
                    'unique_runs' => $uniqueRuns,
                    'unique_task_classes' => $uniqueClasses,
                ];
                $curriculumItems[] = [
                    'blind_spot_type' => $type,
                    'challenge_cases' => $catalog['challenge_cases'],
                    'runbook_reminders' => $catalog['runbook_reminders'],
                    'preflight_checks' => $catalog['preflight_checks'],
                    'stop_repeating_rule' => $catalog['stop_repeating_rule'],
                    'required_evidence' => $catalog['required_evidence'],
                ];
                $injectionRules[] = [
                    'inject_before' => $type . '_class_tasks',
                    'curriculum_type' => $type,
                ];
            } else {
                $rejectedCandidates[] = [
                    'type' => $type,
                    'unique_runs' => $uniqueRuns,
                    'unique_task_classes' => $uniqueClasses,
                    'reason' => 'below_threshold',
                ];
            }
        }

        $allChecks = [];
        $preflightRules = [];
        foreach ($curriculumItems as $item) {
            foreach ($item['preflight_checks'] as $check) {
                $allChecks[$check] = true;
            }
            $preflightRules[] = [
                'blind_spot_type' => $item['blind_spot_type'],
                'stop_repeating_rule' => $item['stop_repeating_rule'],
                'required_evidence' => $item['required_evidence'],
                'preflight_checks' => $item['preflight_checks'],
            ];
        }

        return [
            'schema_version'       => self::SCHEMA,
            'promoted_blind_spots' => $promotedBlindSpots,
            'rejected_candidates'  => $rejectedCandidates,
            'curriculum_items'     => $curriculumItems,
            'injection_rules'      => $injectionRules,
            'preflight_contract'   => [
                'checks'        => array_values(array_keys($allChecks)),
                'applied_types' => array_column($promotedBlindSpots, 'type'),
                'rules'         => $preflightRules,
            ],
        ];
    }

    private const RECURRENCE_NORMALIZATION_CAP = 5;

    private const GIVE_BACK_CLUSTER_CAP = 5;

    private const REJECTED_SPEC_CAP = 5;

    private const STALE_LANE_DAYS_CAP = 30;

    private const WEAK_MODEL_FAILURE_CAP = 5;

    /** group => human-readable capability the originator must actually get better at. */
    private const GROUP_TARGET_CAPABILITY = [
        'missed_evidence' => 'evidence_collection_discipline',
        'bad_scope' => 'scope_completeness_analysis',
        'weak_acceptance' => 'acceptance_criteria_authoring',
        'duplicate_target' => 'capability_dedup_search',
        'low_impact_reasoning' => 'impact_prioritization_reasoning',
    ];

    /**
     * Groups blind spots by missed_evidence, bad_scope, weak_acceptance,
     * duplicate_target, and low_impact_reasoning, then ranks each one by
     * REAL outcome signals — not by ease of fixing and never from a generic
     * topic list. A blind spot with zero real evidence behind it (no
     * recurrence, no quality-lift estimate, no give_back cluster, no
     * rejected specs, no stale lane, no weak-model failures, no evidence_refs)
     * is rejected outright into rejected_generic_topics instead of ranked.
     *
     * score = future_quality_lift_estimate * 0.6
     *       + min(1, recurrence_count / 5) * 0.4
     *       + min(1, give_back_cluster_size / 5) * 0.15
     *       + min(1, rejected_spec_count / 5) * 0.15
     *       + min(1, stale_lane_days / 30) * 0.20
     *       + (1 - commit_yield_rate) * 0.10
     *       + min(1, weak_model_failure_count / 5) * 0.15
     *
     * Each ranked item emits lesson, practice_case, required_evidence, and
     * stop_repeating_rule from the canonical group catalog, PLUS
     * target_capability, evidence_refs, practice_task_shape, and
     * expected_next_batch_improvement grounded in the real outcome signals.
     * Unrecognized groups fall back to a generic learning item rather than
     * being dropped — but only when they DO carry real outcome evidence.
     *
     * @param  array<string,mixed>  $input  { blind_spots: list<{blind_spot_id,
     *   group, recurrence_count?, future_quality_lift_estimate?,
     *   give_back_cluster_size?, rejected_spec_count?, stale_lane_days?,
     *   commit_yield_rate?, weak_model_failure_count?, evidence_refs?}> }
     * @return array<string,mixed>
     */
    public function rankLearningItems(array $input): array
    {
        $blindSpots = is_array($input['blind_spots'] ?? null) ? $input['blind_spots'] : [];

        $rankedItems = [];
        $rejectedGenericTopics = [];
        foreach ($blindSpots as $spot) {
            if (! is_array($spot) || ! isset($spot['blind_spot_id'])) {
                continue;
            }

            $blindSpotId = (string) $spot['blind_spot_id'];
            $group = (string) ($spot['group'] ?? 'unknown');
            $recurrenceCount = max(0, (int) ($spot['recurrence_count'] ?? 0));
            $futureQualityLiftEstimate = max(0.0, min(1.0, (float) ($spot['future_quality_lift_estimate'] ?? 0.0)));
            $giveBackClusterSize = max(0, (int) ($spot['give_back_cluster_size'] ?? 0));
            $rejectedSpecCount = max(0, (int) ($spot['rejected_spec_count'] ?? 0));
            $staleLaneDays = max(0, (int) ($spot['stale_lane_days'] ?? 0));
            $commitYieldRate = max(0.0, min(1.0, (float) ($spot['commit_yield_rate'] ?? 1.0)));
            $weakModelFailureCount = max(0, (int) ($spot['weak_model_failure_count'] ?? 0));
            $evidenceRefs = array_values(array_map('strval', (array) ($spot['evidence_refs'] ?? [])));

            // Generic-topic rejection: no real outcome evidence at all behind this "blind spot".
            $hasRealEvidence = $recurrenceCount > 0
                || $futureQualityLiftEstimate > 0.0
                || $giveBackClusterSize > 0
                || $rejectedSpecCount > 0
                || $staleLaneDays > 0
                || $weakModelFailureCount > 0
                || $evidenceRefs !== [];
            if (! $hasRealEvidence) {
                $rejectedGenericTopics[] = [
                    'blind_spot_id' => $blindSpotId,
                    'group' => $group,
                    'reason' => 'no_real_outcome_evidence',
                ];

                continue;
            }

            $normalizedRecurrence = min(1.0, $recurrenceCount / self::RECURRENCE_NORMALIZATION_CAP);
            $normalizedGiveBack = min(1.0, $giveBackClusterSize / self::GIVE_BACK_CLUSTER_CAP);
            $normalizedRejectedSpecs = min(1.0, $rejectedSpecCount / self::REJECTED_SPEC_CAP);
            $normalizedStaleLane = min(1.0, $staleLaneDays / self::STALE_LANE_DAYS_CAP);
            $normalizedWeakModel = min(1.0, $weakModelFailureCount / self::WEAK_MODEL_FAILURE_CAP);
            $lowYieldPressure = 1.0 - $commitYieldRate;

            $score = round(
                $futureQualityLiftEstimate * 0.6
                + $normalizedRecurrence * 0.4
                + $normalizedGiveBack * 0.15
                + $normalizedRejectedSpecs * 0.15
                + $normalizedStaleLane * 0.20
                + $lowYieldPressure * 0.10
                + $normalizedWeakModel * 0.15,
                4,
            );

            $catalogEntry = self::GROUP_CATALOG[$group] ?? [
                'lesson' => self::GENERIC['challenge_cases'][0],
                'practice_case' => self::GENERIC['runbook_reminders'][0],
                'required_evidence' => self::GENERIC['preflight_checks'][0],
                'stop_repeating_rule' => 'investigate_root_cause_before_next_run',
            ];

            $derivedEvidenceRefs = $evidenceRefs;
            if ($giveBackClusterSize > 0) {
                $derivedEvidenceRefs[] = 'give_back_cluster:'.$giveBackClusterSize;
            }
            if ($rejectedSpecCount > 0) {
                $derivedEvidenceRefs[] = 'rejected_specs:'.$rejectedSpecCount;
            }
            if ($staleLaneDays > 0) {
                $derivedEvidenceRefs[] = 'stale_lane_days:'.$staleLaneDays;
            }
            if ($weakModelFailureCount > 0) {
                $derivedEvidenceRefs[] = 'weak_model_failures:'.$weakModelFailureCount;
            }
            if ($recurrenceCount > 0) {
                $derivedEvidenceRefs[] = 'recurrence_count:'.$recurrenceCount;
            }
            if ($futureQualityLiftEstimate > 0.0) {
                $derivedEvidenceRefs[] = 'future_quality_lift_estimate:'.$futureQualityLiftEstimate;
            }

            $rankedItems[] = [
                'blind_spot_id' => $blindSpotId,
                'group' => $group,
                'recurrence_count' => $recurrenceCount,
                'future_quality_lift_estimate' => $futureQualityLiftEstimate,
                'score' => $score,
                'lesson' => $catalogEntry['lesson'],
                'practice_case' => $catalogEntry['practice_case'],
                'required_evidence' => $catalogEntry['required_evidence'],
                'stop_repeating_rule' => $catalogEntry['stop_repeating_rule'],
                'target_capability' => self::GROUP_TARGET_CAPABILITY[$group] ?? 'general_capability:'.$group,
                'evidence_refs' => array_values(array_unique($derivedEvidenceRefs)),
                'practice_task_shape' => $catalogEntry['practice_case'],
                'expected_next_batch_improvement' => sprintf('%d%% fewer %s recurrences expected in next batch', (int) round($score * 100), $group),
            ];
        }

        usort($rankedItems, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['blind_spot_id'], $b['blind_spot_id']));
        usort($rejectedGenericTopics, static fn (array $a, array $b): int => strcmp($a['blind_spot_id'], $b['blind_spot_id']));

        return [
            'schema_version' => self::SCHEMA,
            'ranked_learning_items' => array_values($rankedItems),
            'rejected_generic_topics' => array_values($rejectedGenericTopics),
        ];
    }
}
