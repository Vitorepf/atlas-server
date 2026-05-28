<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProgrammingRepairLoopBenchmarkService;
use Tests\TestCase;

class ProgrammingRepairLoopBenchmarkServiceTest extends TestCase
{
    public function test_run_returns_governed_repair_loop_benchmark_report(): void
    {
        $report = app(ProgrammingRepairLoopBenchmarkService::class)->run();

        $this->assertSame('atlas.programming.repair_loop_benchmark.v1', $report['schema_version']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame(
            hash('sha256', 'programming_repair_loop_golden_set_v1'),
            $report['benchmark_id'],
        );
        $this->assertSame('programming_repair_loop_golden_set_v1', data_get($report, 'golden_set.name'));
        $this->assertSame(4, data_get($report, 'golden_set.case_count'));
        $this->assertSame('repo_canonical_programming_repair_loop_cases', data_get($report, 'golden_set.source'));
        $this->assertSame(1.0, data_get($report, 'metrics.repair_planning_pass_rate'));
        $this->assertSame(1.0, data_get($report, 'metrics.guard_case_pass_rate'));
        $this->assertTrue(data_get($report, 'metrics.receipt_integrity_passed'));
        $this->assertSame(0, data_get($report, 'metrics.failed_case_count'));
        $this->assertTrue(data_get($report, 'promotion_gate.repair_loop_promotion_allowed'));
        $this->assertTrue(data_get($report, 'promotion_gate.requires_rivals_programming'));
        $this->assertNotEmpty($report['created_at']);

        $caseIds = collect($report['cases'])->pluck('case_id')->all();
        $this->assertSame(
            [
                'first_attempt_plans_patch_repair',
                'repeated_failure_blocks_no_progress',
                'repair_manifest_without_rollback_blocks',
                'max_attempt_overflow_blocks_human_review',
            ],
            $caseIds,
        );

        foreach ($report['cases'] as $case) {
            $this->assertSame('passed', $case['status']);
            $this->assertTrue($case['guard_passed']);
            $this->assertTrue($case['receipt_integrity_passed']);
        }
    }
}
