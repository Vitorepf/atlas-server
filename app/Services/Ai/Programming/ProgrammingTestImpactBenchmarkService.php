<?php

namespace App\Services\Ai\Programming;

class ProgrammingTestImpactBenchmarkService
{
    public function __construct(
        private readonly ProgrammingTestImpactAnalyzer $testImpactAnalyzer,
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

        $recall = collect($results)->avg('recall') ?? 0.0;
        $precision = collect($results)->avg('precision') ?? 0.0;
        $failed = collect($results)->where('status', 'failed')->count();
        $status = $failed === 0 && $recall >= 0.85 ? 'passed' : 'failed';

        return [
            'schema_version' => 'atlas.programming.test_impact_benchmark.v1',
            'status' => $status,
            'benchmark_id' => hash('sha256', 'programming_test_impact_golden_set_v1'),
            'golden_set' => [
                'name' => 'programming_test_impact_golden_set_v1',
                'case_count' => count($cases),
                'source' => 'repo_canonical_programming_test_impact_cases',
            ],
            'metrics' => [
                'recall' => round((float) $recall, 4),
                'precision' => round((float) $precision, 4),
                'failed_case_count' => $failed,
            ],
            'promotion_gate' => [
                'test_selection_promotion_allowed' => $status === 'passed',
                'requires_rivals_programming' => true,
                'reason' => $status === 'passed'
                    ? 'local_test_impact_golden_set_passed_rivals_programming_still_required'
                    : 'local_test_impact_golden_set_failed',
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
        $receipt = $this->testImpactAnalyzer->analyze(
            changedFiles: (array) $case['changed_files'],
            codeGraph: (array) ($case['code_graph'] ?? []),
            risk: (string) ($case['risk'] ?? 'medium'),
        );

        $selected = collect((array) ($receipt['selected_existing_tests'] ?? $receipt['selected_tests'] ?? []))
            ->map(fn ($test): string => (string) $test)
            ->all();
        $expected = (array) $case['expected_tests'];
        $hits = collect($expected)
            ->filter(fn (string $test): bool => in_array($test, $selected, true))
            ->values()
            ->all();

        $recall = count($expected) > 0 ? count($hits) / count($expected) : 1.0;
        $precision = $selected === [] ? 0.0 : count($hits) / count($selected);

        return [
            'case_id' => (string) $case['id'],
            'status' => $recall >= (float) ($case['min_recall'] ?? 1.0) ? 'passed' : 'failed',
            'risk' => (string) ($case['risk'] ?? 'medium'),
            'changed_files' => (array) $case['changed_files'],
            'expected_tests' => $expected,
            'selected_tests' => $selected,
            'hit_tests' => $hits,
            'missed_tests' => array_values(array_diff($expected, $hits)),
            'recall' => round($recall, 4),
            'precision' => round($precision, 4),
            'receipt_schema' => $receipt['schema_version'] ?? null,
            'requires_no_test_reason' => (bool) ($receipt['requires_no_test_reason'] ?? false),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function goldenCases(): array
    {
        return [
            [
                'id' => 'orchestrator_related_unit_test',
                'risk' => 'high',
                'changed_files' => ['app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php'],
                'code_graph' => [
                    'related_tests' => ['tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php'],
                ],
                'expected_tests' => ['tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php'],
            ],
            [
                'id' => 'programming_runtime_contract_test',
                'risk' => 'medium',
                'changed_files' => ['app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php'],
                'code_graph' => [
                    'related_tests' => ['tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php'],
                ],
                'expected_tests' => ['tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php'],
            ],
            [
                'id' => 'changed_test_preserved',
                'risk' => 'low',
                'changed_files' => ['tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php'],
                'expected_tests' => ['tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php'],
            ],
        ];
    }
}
