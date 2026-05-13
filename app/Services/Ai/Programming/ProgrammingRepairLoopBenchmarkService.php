<?php

namespace App\Services\Ai\Programming;

class ProgrammingRepairLoopBenchmarkService
{
    public function __construct(
        private readonly ProgrammingRepairExecutor $repairExecutor,
        private readonly ProgrammingRepairAttemptStore $repairAttempts,
        private readonly ProgrammingPatchVerifier $patchVerifier,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $cases = $this->goldenCases();
        $results = collect($cases)
            ->map(fn (array $case): array => $this->runCase($case))
            ->values()
            ->all();

        $passed = collect($results)->where('status', 'passed')->count();
        $status = $passed === count($results) ? 'passed' : 'failed';
        $receiptIntegrityPassed = collect($results)
            ->filter(fn (array $result): bool => (bool) ($result['requires_receipt_integrity'] ?? false))
            ->every(fn (array $result): bool => (bool) ($result['receipt_integrity_passed'] ?? false));
        $guardCases = collect($results)
            ->filter(fn (array $result): bool => (bool) ($result['requires_guard'] ?? false));
        $guardPassed = $guardCases
            ->every(fn (array $result): bool => (bool) ($result['guard_passed'] ?? false));

        return [
            'schema_version' => 'atlas.programming.repair_loop_benchmark.v1',
            'status' => $status,
            'benchmark_id' => hash('sha256', 'programming_repair_loop_golden_set_v1'),
            'golden_set' => [
                'name' => 'programming_repair_loop_golden_set_v1',
                'case_count' => count($cases),
                'source' => 'repo_canonical_programming_repair_loop_cases',
            ],
            'metrics' => [
                'repair_planning_pass_rate' => round($passed / max(1, count($results)), 4),
                'guard_case_pass_rate' => round($guardCases->where('guard_passed', true)->count() / max(1, $guardCases->count()), 4),
                'receipt_integrity_passed' => $receiptIntegrityPassed,
                'failed_case_count' => count($results) - $passed,
            ],
            'promotion_gate' => [
                'repair_loop_promotion_allowed' => $status === 'passed' && $receiptIntegrityPassed && $guardPassed,
                'requires_rivals_programming' => true,
                'reason' => $status === 'passed'
                    ? 'local_repair_loop_golden_set_passed_rivals_programming_still_required'
                    : 'local_repair_loop_golden_set_failed',
            ],
            'cases' => $results,
            'created_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return array<string,mixed>
     */
    private function runCase(array $case): array
    {
        $repairPlan = $this->repairExecutor->attemptPlan(
            failurePacket: (array) $case['failure_packet'],
            retrievalPlan: (array) $case['retrieval_plan'],
            attempt: (int) $case['attempt'],
            maxAttempts: (int) $case['max_attempts'],
        );
        $patchReport = $this->patchVerifier->verify((array) $case['patch_context']);
        $receipt = $this->repairAttempts->receipt(
            planId: (string) $case['plan_id'],
            parentPlanId: is_string($case['parent_plan_id'] ?? null) ? (string) $case['parent_plan_id'] : null,
            attempt: (int) $case['attempt'],
            status: (string) $case['receipt_status'],
            failurePacket: (array) $case['failure_packet'],
            patchManifest: (array) $case['patch_manifest'],
            testManifest: (array) $case['test_manifest'],
        );

        $expectedRepairStatus = (string) $case['expected_repair_status'];
        $expectedPatchStatus = (string) $case['expected_patch_status'];
        $expectedNextAction = (string) $case['expected_next_action'];
        $expectedFailureCategory = (string) $case['expected_failure_category'];
        $expectedPatchReasons = (array) ($case['expected_patch_blocking_reasons'] ?? []);
        $actualPatchReasons = (array) ($patchReport['blocking_reasons'] ?? []);
        $missingPatchReasons = array_values(array_diff($expectedPatchReasons, $actualPatchReasons));
        $receiptIntegrityPassed = ($receipt['schema_version'] ?? null) === 'atlas.programming.stage_receipt.v1'
            && data_get($receipt, 'repair_attempt.repair_attempt_schema') === 'atlas.programming.repair_attempt.receipt.v1'
            && data_get($receipt, 'repair_attempt.patch_manifest_schema') === ($case['patch_manifest']['schema_version'] ?? null)
            && data_get($receipt, 'repair_attempt.test_manifest_schema') === ($case['test_manifest']['schema_version'] ?? null);
        $guardPassed = ($repairPlan['status'] ?? null) === $expectedRepairStatus
            && ($repairPlan['next_action'] ?? null) === $expectedNextAction
            && data_get($repairPlan, 'repair_capsule.failure_taxonomy.category') === $expectedFailureCategory
            && data_get($repairPlan, 'repair_capsule.provider_policy.fallback_allowed') === false
            && data_get($repairPlan, 'repair_capsule.verification_plan.patch_verifier_required') === true
            && ($patchReport['status'] ?? null) === $expectedPatchStatus
            && $missingPatchReasons === [];

        return [
            'case_id' => (string) $case['id'],
            'status' => $guardPassed && $receiptIntegrityPassed ? 'passed' : 'failed',
            'expected_repair_status' => $expectedRepairStatus,
            'actual_repair_status' => $repairPlan['status'] ?? null,
            'expected_next_action' => $expectedNextAction,
            'actual_next_action' => $repairPlan['next_action'] ?? null,
            'expected_failure_category' => $expectedFailureCategory,
            'actual_failure_category' => data_get($repairPlan, 'repair_capsule.failure_taxonomy.category'),
            'expected_patch_status' => $expectedPatchStatus,
            'actual_patch_status' => $patchReport['status'] ?? null,
            'expected_patch_blocking_reasons' => $expectedPatchReasons,
            'actual_patch_blocking_reasons' => $actualPatchReasons,
            'missing_patch_blocking_reasons' => $missingPatchReasons,
            'guard_passed' => $guardPassed,
            'requires_guard' => true,
            'receipt_integrity_passed' => $receiptIntegrityPassed,
            'requires_receipt_integrity' => true,
            'repair_plan_schema' => $repairPlan['schema_version'] ?? null,
            'repair_capsule_schema' => data_get($repairPlan, 'repair_capsule.schema_version'),
            'repair_receipt_schema' => $receipt['schema_version'] ?? null,
            'repair_attempt_schema' => data_get($receipt, 'repair_attempt.repair_attempt_schema'),
            'patch_verifier_schema' => $patchReport['schema_version'] ?? null,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function goldenCases(): array
    {
        $baseFailure = [
            'failure_hash' => 'test-failure-a',
            'command' => '/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php',
            'primary_error' => 'PHPUnit assertion failed in FooTest',
            'changed_files' => ['app/Services/Foo.php'],
            'failed_commands' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php'],
        ];
        $retrievalPlan = [
            'retrieval_receipt' => [
                'receipt_id' => 'retrieval-repair-1',
            ],
        ];
        $passingManifest = [
            'schema_version' => 'atlas.programming.action_manifest.v1',
            'action_id' => 'repair-patch-1',
            'stage' => 'repair',
            'dry_run' => false,
            'changed_files' => ['app/Services/Foo.php'],
            'rollback' => ['available' => true],
            'gate_effect' => 'passed',
        ];
        $testManifest = [
            'schema_version' => 'atlas.programming.action_manifest.v1',
            'action_id' => 'repair-test-1',
            'stage' => 'test',
            'dry_run' => true,
            'changed_files' => [],
            'rollback' => ['available' => true],
            'gate_effect' => 'passed',
        ];

        return [
            [
                'id' => 'first_attempt_plans_patch_repair',
                'plan_id' => 'repair-benchmark-plan-1',
                'parent_plan_id' => null,
                'attempt' => 1,
                'max_attempts' => 3,
                'failure_packet' => $baseFailure,
                'retrieval_plan' => $retrievalPlan,
                'patch_manifest' => $passingManifest,
                'test_manifest' => $testManifest,
                'patch_context' => [
                    'changed_files' => ['app/Services/Foo.php'],
                    'tests' => ['tests/Unit/FooTest.php'],
                    'action_manifests' => [$passingManifest],
                ],
                'receipt_status' => 'passed',
                'expected_repair_status' => 'planned',
                'expected_next_action' => 'patch_repair_then_retest',
                'expected_failure_category' => 'test_failure',
                'expected_patch_status' => 'passed',
            ],
            [
                'id' => 'repeated_failure_blocks_no_progress',
                'plan_id' => 'repair-benchmark-plan-2',
                'parent_plan_id' => 'repair-benchmark-plan-1',
                'attempt' => 2,
                'max_attempts' => 3,
                'failure_packet' => array_merge($baseFailure, [
                    'previous_failure_hash' => 'test-failure-a',
                ]),
                'retrieval_plan' => $retrievalPlan,
                'patch_manifest' => $passingManifest,
                'test_manifest' => $testManifest,
                'patch_context' => [
                    'changed_files' => ['app/Services/Foo.php'],
                    'tests' => ['tests/Unit/FooTest.php'],
                    'action_manifests' => [$passingManifest],
                ],
                'receipt_status' => 'blocked_no_progress',
                'expected_repair_status' => 'blocked_no_progress',
                'expected_next_action' => 'human_review',
                'expected_failure_category' => 'test_failure',
                'expected_patch_status' => 'passed',
            ],
            [
                'id' => 'repair_manifest_without_rollback_blocks',
                'plan_id' => 'repair-benchmark-plan-3',
                'parent_plan_id' => 'repair-benchmark-plan-1',
                'attempt' => 1,
                'max_attempts' => 3,
                'failure_packet' => $baseFailure,
                'retrieval_plan' => $retrievalPlan,
                'patch_manifest' => array_merge($passingManifest, [
                    'action_id' => 'repair-patch-rollback-missing',
                    'rollback' => ['available' => false],
                ]),
                'test_manifest' => $testManifest,
                'patch_context' => [
                    'changed_files' => ['app/Services/Foo.php'],
                    'tests' => ['tests/Unit/FooTest.php'],
                    'action_manifests' => [array_merge($passingManifest, [
                        'action_id' => 'repair-patch-rollback-missing',
                        'rollback' => ['available' => false],
                    ])],
                ],
                'receipt_status' => 'blocked',
                'expected_repair_status' => 'planned',
                'expected_next_action' => 'patch_repair_then_retest',
                'expected_failure_category' => 'test_failure',
                'expected_patch_status' => 'blocked',
                'expected_patch_blocking_reasons' => ['write_action_without_rollback'],
            ],
            [
                'id' => 'max_attempt_overflow_blocks_human_review',
                'plan_id' => 'repair-benchmark-plan-4',
                'parent_plan_id' => 'repair-benchmark-plan-1',
                'attempt' => 4,
                'max_attempts' => 3,
                'failure_packet' => [
                    'failure_hash' => 'test-failure-b',
                    'previous_failure_hash' => 'test-failure-a',
                    'primary_error' => 'Pint lint failure after repair',
                    'command' => './vendor/bin/pint --test',
                ],
                'retrieval_plan' => $retrievalPlan,
                'patch_manifest' => $passingManifest,
                'test_manifest' => $testManifest,
                'patch_context' => [
                    'changed_files' => ['app/Services/Foo.php'],
                    'tests' => ['tests/Unit/FooTest.php'],
                    'action_manifests' => [$passingManifest],
                ],
                'receipt_status' => 'blocked_max_attempts',
                'expected_repair_status' => 'blocked_max_attempts',
                'expected_next_action' => 'human_review',
                'expected_failure_category' => 'style_or_static_check',
                'expected_patch_status' => 'passed',
            ],
        ];
    }
}
