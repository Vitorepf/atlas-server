<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure router: turns successful muscle commit records into compact lessons and
 * warnings that feed the next origination cycle.
 *
 * A commit is only processed when all three evidence signals are present:
 *   changed_capability (non-empty string)
 *   implementation_evidence (truthy)
 *   test_evidence (truthy)
 *
 * Commits missing any of those go to ignored_low_evidence_commits.
 *
 * Positive lessons promoted when:
 *   compounding_value >= 7  → high_compounding_value
 *   test_strength >= 7      → strong_test_coverage
 *   scope_size == 1         → focused_scope
 *
 * Warnings emitted when:
 *   scope_size > 3          → excessive_scope
 *   test_strength < 4       → weak_tests
 *   duplicate_detected      → duplicate_capability
 *   compounding_value < 3   → low_compounding_value
 *   capability_delta == 0   → no_capability_delta  (only when field is explicitly provided)
 *
 * AC1 new output buckets:
 *   positive_lessons         — same lessons but suppressed when weak_tests or no_capability_delta blocks
 *   negative_constraints     — alias for next_batch_constraints
 *   worker_affinity_updates  — per-commit boosts/suppresses derived from worker_id (optional field)
 *   task_family_policy_updates — per-commit policy derived from task_family (optional field)
 *
 * next_batch_constraints are derived from warnings; each constraint type appears once.
 */
final class AtlasExternalBrainPostCommitLearningFeedbackRouter
{
    public const SCHEMA = 'atlas.external_brain.postcommit_learning_feedback_router.v1';

    public const HIGH_COMPOUNDING_THRESHOLD = 7;

    public const STRONG_TEST_THRESHOLD = 7;

    public const WEAK_TEST_THRESHOLD = 4;

    public const EXCESSIVE_SCOPE_THRESHOLD = 3;

    public const LOW_COMPOUNDING_THRESHOLD = 3;

    private const CONSTRAINT_MAP = [
        'excessive_scope'      => ['constraint' => 'limit_scope_to_single_capability',   'severity' => 'high'],
        'weak_tests'           => ['constraint' => 'require_minimum_test_strength_7',     'severity' => 'high'],
        'duplicate_capability' => ['constraint' => 'verify_no_duplicate_before_enqueue',  'severity' => 'medium'],
        'low_compounding_value' => ['constraint' => 'prefer_high_leverage_capabilities',  'severity' => 'low'],
        'no_capability_delta'  => ['constraint' => 'require_measurable_capability_delta', 'severity' => 'high'],
    ];

    /**
     * @param  array<string,mixed>  $input  commits list
     * @return array<string,mixed>
     */
    public function route(array $input): array
    {
        $commits = is_array($input['commits'] ?? null) ? $input['commits'] : [];

        $promotedLessons        = [];
        $positiveLessons        = [];
        $warnings               = [];
        $ignoredCommits         = [];
        $constraintsSeen        = [];
        $nextBatchConstraints   = [];
        $workerAffinityUpdates  = [];
        $taskFamilyPolicyUpdates = [];

        foreach ($commits as $commit) {
            $capability = trim((string) ($commit['changed_capability'] ?? ''));
            $hasImpl    = ! empty($commit['implementation_evidence']);
            $hasTest    = ! empty($commit['test_evidence']);

            if ($capability === '' || ! $hasImpl || ! $hasTest) {
                $ignoredCommits[] = $commit;
                continue;
            }

            $compoundingValue  = (int) ($commit['compounding_value']   ?? 0);
            $testStrength      = (int) ($commit['test_strength']        ?? 0);
            $scopeSize         = (int) ($commit['scope_size']           ?? 1);
            $duplicateDetected = (bool) ($commit['duplicate_detected']  ?? false);

            // AC2: positive_lessons is suppressed when weak_tests or no_capability_delta blocks.
            $weakTestsBlock      = $testStrength < self::WEAK_TEST_THRESHOLD;
            $capabilityDelta     = array_key_exists('capability_delta', $commit)
                                    ? (int) $commit['capability_delta'] : null;
            $noCapabilityDelta   = $capabilityDelta !== null && $capabilityDelta === 0;
            $positiveBlocked     = $weakTestsBlock || $noCapabilityDelta;

            // Promoted lessons (all green commits).
            if ($compoundingValue >= self::HIGH_COMPOUNDING_THRESHOLD) {
                $lesson = [
                    'lesson'            => 'high_compounding_value',
                    'capability'        => $capability,
                    'compounding_value' => $compoundingValue,
                ];
                $promotedLessons[] = $lesson;
                if (! $positiveBlocked) {
                    $positiveLessons[] = $lesson;
                }
            }
            if ($testStrength >= self::STRONG_TEST_THRESHOLD) {
                $lesson = [
                    'lesson'        => 'strong_test_coverage',
                    'capability'    => $capability,
                    'test_strength' => $testStrength,
                ];
                $promotedLessons[] = $lesson;
                if (! $positiveBlocked) {
                    $positiveLessons[] = $lesson;
                }
            }
            if ($scopeSize === 1) {
                $lesson = ['lesson' => 'focused_scope', 'capability' => $capability];
                $promotedLessons[] = $lesson;
                if (! $positiveBlocked) {
                    $positiveLessons[] = $lesson;
                }
            }

            // Warnings + constraints.
            if ($scopeSize > self::EXCESSIVE_SCOPE_THRESHOLD) {
                $warnings[] = ['warning' => 'excessive_scope', 'capability' => $capability, 'scope_size' => $scopeSize];
                if (! isset($constraintsSeen['excessive_scope'])) {
                    $constraintsSeen['excessive_scope'] = true;
                    $nextBatchConstraints[] = self::CONSTRAINT_MAP['excessive_scope'];
                }
            }
            if ($weakTestsBlock) {
                $warnings[] = ['warning' => 'weak_tests', 'capability' => $capability, 'test_strength' => $testStrength];
                if (! isset($constraintsSeen['weak_tests'])) {
                    $constraintsSeen['weak_tests'] = true;
                    $nextBatchConstraints[] = self::CONSTRAINT_MAP['weak_tests'];
                }
            }
            if ($duplicateDetected) {
                $warnings[] = ['warning' => 'duplicate_capability', 'capability' => $capability];
                if (! isset($constraintsSeen['duplicate_capability'])) {
                    $constraintsSeen['duplicate_capability'] = true;
                    $nextBatchConstraints[] = self::CONSTRAINT_MAP['duplicate_capability'];
                }
            }
            if ($compoundingValue < self::LOW_COMPOUNDING_THRESHOLD) {
                $warnings[] = ['warning' => 'low_compounding_value', 'capability' => $capability, 'compounding_value' => $compoundingValue];
                if (! isset($constraintsSeen['low_compounding_value'])) {
                    $constraintsSeen['low_compounding_value'] = true;
                    $nextBatchConstraints[] = self::CONSTRAINT_MAP['low_compounding_value'];
                }
            }
            if ($noCapabilityDelta) {
                $warnings[] = ['warning' => 'no_capability_delta', 'capability' => $capability];
                if (! isset($constraintsSeen['no_capability_delta'])) {
                    $constraintsSeen['no_capability_delta'] = true;
                    $nextBatchConstraints[] = self::CONSTRAINT_MAP['no_capability_delta'];
                }
            }

            // AC1: worker_affinity_updates — only when worker_id is provided.
            $workerId = (string) ($commit['worker_id'] ?? '');
            if ($workerId !== '') {
                $affinity = ($compoundingValue >= self::HIGH_COMPOUNDING_THRESHOLD
                             && $testStrength >= self::STRONG_TEST_THRESHOLD)
                            ? 'boost' : 'suppress';
                $workerAffinityUpdates[] = [
                    'worker_id'  => $workerId,
                    'affinity'   => $affinity,
                    'capability' => $capability,
                ];
            }

            // AC1: task_family_policy_updates — only when task_family is provided.
            $taskFamily = (string) ($commit['task_family'] ?? '');
            if ($taskFamily !== '') {
                if ($compoundingValue >= self::HIGH_COMPOUNDING_THRESHOLD && ! $duplicateDetected) {
                    $policy = 'promote';
                } elseif ($duplicateDetected || $compoundingValue < self::LOW_COMPOUNDING_THRESHOLD) {
                    $policy = 'deprioritize';
                } else {
                    $policy = 'watch';
                }
                $taskFamilyPolicyUpdates[] = [
                    'task_family' => $taskFamily,
                    'policy'      => $policy,
                    'capability'  => $capability,
                ];
            }
        }

        return [
            'schema_version'             => self::SCHEMA,
            'promoted_lessons'           => $promotedLessons,
            'positive_lessons'           => $positiveLessons,
            'warnings'                   => $warnings,
            'next_batch_constraints'     => $nextBatchConstraints,
            'negative_constraints'       => $nextBatchConstraints,
            'worker_affinity_updates'    => $workerAffinityUpdates,
            'task_family_policy_updates' => $taskFamilyPolicyUpdates,
            'ignored_low_evidence_commits' => $ignoredCommits,
        ];
    }
}
