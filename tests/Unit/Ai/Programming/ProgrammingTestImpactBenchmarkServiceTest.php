<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProgrammingTestImpactBenchmarkService;
use Tests\TestCase;

class ProgrammingTestImpactBenchmarkServiceTest extends TestCase
{
    public function test_run_returns_governed_test_impact_benchmark_report(): void
    {
        $report = app(ProgrammingTestImpactBenchmarkService::class)->run();

        $this->assertSame('atlas.programming.test_impact_benchmark.v1', $report['schema_version']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame(
            hash('sha256', 'programming_test_impact_golden_set_v1'),
            $report['benchmark_id'],
        );
        $this->assertSame('programming_test_impact_golden_set_v1', data_get($report, 'golden_set.name'));
        $this->assertSame(3, data_get($report, 'golden_set.case_count'));
        $this->assertSame('repo_canonical_programming_test_impact_cases', data_get($report, 'golden_set.source'));
        $this->assertSame(1.0, data_get($report, 'metrics.recall'));
        $this->assertSame(1.0, data_get($report, 'metrics.precision'));
        $this->assertSame(0, data_get($report, 'metrics.failed_case_count'));
        $this->assertTrue(data_get($report, 'promotion_gate.test_selection_promotion_allowed'));
        $this->assertTrue(data_get($report, 'promotion_gate.requires_rivals_programming'));
        $this->assertNotEmpty($report['created_at']);

        $caseIds = collect($report['cases'])->pluck('case_id')->all();
        $this->assertSame(
            [
                'orchestrator_related_unit_test',
                'programming_runtime_contract_test',
                'changed_test_preserved',
            ],
            $caseIds,
        );

        foreach ($report['cases'] as $case) {
            $this->assertSame('passed', $case['status']);
            $this->assertSame(1.0, $case['recall']);
            $this->assertNotEmpty($case['expected_tests']);
            $this->assertNotEmpty($case['selected_tests']);
        }
    }
}
