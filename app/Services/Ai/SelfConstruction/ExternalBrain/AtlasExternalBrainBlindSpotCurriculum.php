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
        foreach ($curriculumItems as $item) {
            foreach ($item['preflight_checks'] as $check) {
                $allChecks[$check] = true;
            }
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
            ],
        ];
    }
}
