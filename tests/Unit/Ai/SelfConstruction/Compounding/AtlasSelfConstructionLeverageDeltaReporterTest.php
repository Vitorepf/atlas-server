<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Compounding;

use App\Services\Ai\SelfConstruction\Compounding\AtlasSelfConstructionLeverageDeltaReporter;
use Tests\TestCase;

final class AtlasSelfConstructionLeverageDeltaReporterTest extends TestCase
{
    public function test_real_leverage_is_flagged_when_capability_grows_with_verification_steady(): void
    {
        $report = (new AtlasSelfConstructionLeverageDeltaReporter)->report(
            ['capability_coverage' => 5, 'verification_strength' => 10, 'task_waste' => 3],
            ['capability_coverage' => 8, 'verification_strength' => 10, 'task_waste' => 3],
        );

        $this->assertTrue($report['real_leverage']);
        $this->assertSame(3, $report['deltas']['capability_coverage']);
        $this->assertFalse($report['flagged_proxy']);
    }

    public function test_proxy_win_task_count_growth_without_capability_or_verification_is_flagged(): void
    {
        $report = (new AtlasSelfConstructionLeverageDeltaReporter)->report(
            ['task_count' => 10, 'capability_coverage' => 5, 'verification_strength' => 10],
            ['task_count' => 30, 'capability_coverage' => 5, 'verification_strength' => 10],
        );

        $this->assertFalse($report['real_leverage'], 'task_count alone is NOT real leverage');
        $this->assertTrue($report['flagged_proxy']);
        $this->assertContains('task_count_grew_without_capability_or_verification_lift', $report['proxy_wins']);
    }

    public function test_line_churn_without_capability_lift_is_flagged_as_proxy(): void
    {
        $report = (new AtlasSelfConstructionLeverageDeltaReporter)->report(
            ['line_churn' => 100, 'capability_coverage' => 5],
            ['line_churn' => 500, 'capability_coverage' => 5],
        );

        $this->assertContains('line_churn_grew_without_capability_lift', $report['proxy_wins']);
    }

    public function test_mixed_progress_capability_up_but_task_waste_also_up_is_not_real_leverage(): void
    {
        $report = (new AtlasSelfConstructionLeverageDeltaReporter)->report(
            ['capability_coverage' => 5, 'verification_strength' => 10, 'task_waste' => 1],
            ['capability_coverage' => 8, 'verification_strength' => 10, 'task_waste' => 6],
        );

        $this->assertFalse($report['real_leverage'], 'task_waste growth disqualifies real leverage even with capability lift');
        $this->assertSame(5, $report['deltas']['task_waste']);
    }

    public function test_regression_in_verification_disqualifies_real_leverage(): void
    {
        $report = (new AtlasSelfConstructionLeverageDeltaReporter)->report(
            ['capability_coverage' => 5, 'verification_strength' => 10, 'task_waste' => 3],
            ['capability_coverage' => 8, 'verification_strength' => 4, 'task_waste' => 3],
        );

        $this->assertFalse($report['real_leverage']);
        $this->assertSame(-6, $report['deltas']['verification_strength']);
    }

    public function test_report_carries_all_five_named_dimensions(): void
    {
        $report = (new AtlasSelfConstructionLeverageDeltaReporter)->report([], []);
        foreach (AtlasSelfConstructionLeverageDeltaReporter::DIMENSIONS as $dim) {
            $this->assertArrayHasKey($dim, $report['deltas']);
        }
    }

    public function test_report_is_deterministic_byte_identical(): void
    {
        $svc = new AtlasSelfConstructionLeverageDeltaReporter;
        $before = ['capability_coverage' => 5];
        $after = ['capability_coverage' => 8];
        $this->assertSame(json_encode($svc->report($before, $after)), json_encode($svc->report($before, $after)));
    }
}
