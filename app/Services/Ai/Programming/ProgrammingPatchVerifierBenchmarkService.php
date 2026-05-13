<?php

namespace App\Services\Ai\Programming;

class ProgrammingPatchVerifierBenchmarkService
{
    public function __construct(
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

        return [
            'schema_version' => 'atlas.programming.patch_verifier_benchmark.v1',
            'status' => $status,
            'benchmark_id' => hash('sha256', 'programming_patch_verifier_golden_set_v1'),
            'golden_set' => [
                'name' => 'programming_patch_verifier_golden_set_v1',
                'case_count' => count($cases),
                'source' => 'repo_canonical_programming_patch_verifier_cases',
            ],
            'metrics' => [
                'grounded_patch_rate' => round($passed / max(1, count($results)), 4),
                'failed_case_count' => count($results) - $passed,
            ],
            'promotion_gate' => [
                'grounded_patch_promotion_allowed' => $status === 'passed',
                'requires_rivals_programming' => true,
                'reason' => $status === 'passed'
                    ? 'local_patch_verifier_golden_set_passed_rivals_programming_still_required'
                    : 'local_patch_verifier_golden_set_failed',
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
        $receipt = $this->patchVerifier->verify((array) $case['input']);
        $expectedStatus = (string) $case['expected_status'];
        $expectedReasons = (array) ($case['expected_blocking_reasons'] ?? []);
        $actualReasons = (array) ($receipt['blocking_reasons'] ?? []);
        $missingReasons = array_values(array_diff($expectedReasons, $actualReasons));
        $statusMatches = ($receipt['status'] ?? null) === $expectedStatus;
        $reasonsMatch = $missingReasons === [];

        return [
            'case_id' => (string) $case['id'],
            'status' => $statusMatches && $reasonsMatch ? 'passed' : 'failed',
            'expected_status' => $expectedStatus,
            'actual_status' => $receipt['status'] ?? null,
            'expected_blocking_reasons' => $expectedReasons,
            'actual_blocking_reasons' => $actualReasons,
            'missing_blocking_reasons' => $missingReasons,
            'receipt_schema' => $receipt['schema_version'] ?? null,
            'manifest_covered_files' => $receipt['manifest_covered_files'] ?? [],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function goldenCases(): array
    {
        return [
            [
                'id' => 'grounded_patch_passes',
                'expected_status' => 'passed',
                'input' => [
                    'changed_files' => ['app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php'],
                    'tests' => ['tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php'],
                    'action_manifests' => [[
                        'schema_version' => 'atlas.programming.action_manifest.v1',
                        'stage' => 'patch',
                        'dry_run' => false,
                        'changed_files' => ['app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php'],
                        'rollback' => ['available' => true],
                        'gate_effect' => 'passed',
                    ]],
                ],
            ],
            [
                'id' => 'missing_tests_blocks',
                'expected_status' => 'blocked',
                'expected_blocking_reasons' => ['missing_tests_or_reason'],
                'input' => [
                    'changed_files' => ['app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php'],
                    'tests' => [],
                    'action_manifests' => [],
                ],
            ],
            [
                'id' => 'partial_manifest_blocks',
                'expected_status' => 'blocked',
                'expected_blocking_reasons' => ['partial_action_manifest_coverage'],
                'input' => [
                    'changed_files' => [
                        'app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php',
                        'app/Services/Ai/Programming/ProgrammingRetrievalExecutor.php',
                    ],
                    'tests' => ['tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php'],
                    'action_manifests' => [[
                        'schema_version' => 'atlas.programming.action_manifest.v1',
                        'stage' => 'patch',
                        'dry_run' => false,
                        'changed_files' => ['app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php'],
                        'rollback' => ['available' => true],
                        'gate_effect' => 'passed',
                    ]],
                ],
            ],
        ];
    }
}
