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

    public function test_real_delivered_deltas_score_higher_than_raw_seed_volume(): void
    {
        // 1 implemented outcome (weight 10) must outweigh 1 raw seed (weight 1).
        $real = (new AtlasSelfConstructionLeverageDeltaReporter)->report(
            ['implemented_outcomes' => 0, 'seed_volume' => 0],
            ['implemented_outcomes' => 1, 'seed_volume' => 0],
        );
        $seed = (new AtlasSelfConstructionLeverageDeltaReporter)->report(
            ['implemented_outcomes' => 0, 'seed_volume' => 0],
            ['implemented_outcomes' => 0, 'seed_volume' => 1],
        );

        $this->assertGreaterThan(
            $seed['score']['components']['raw_seed_volume']['contribution'],
            $real['score']['components']['implemented_outcomes']['contribution'],
            'one implemented outcome must score higher than one raw seed',
        );
    }

    public function test_score_includes_evidence_fields_per_component(): void
    {
        $report = (new AtlasSelfConstructionLeverageDeltaReporter)->report(
            ['implemented_outcomes' => 2, 'give_back_rate' => 0.3, 'autonomous_recovery_coverage' => 5, 'seed_volume' => 10, 'queue_health' => 4],
            ['implemented_outcomes' => 5, 'give_back_rate' => 0.1, 'autonomous_recovery_coverage' => 8, 'seed_volume' => 12, 'queue_health' => 7],
        );

        $this->assertArrayHasKey('score', $report);
        $this->assertArrayHasKey('total', $report['score']);
        $components = $report['score']['components'];
        foreach (['implemented_outcomes', 'queue_health', 'give_back_reduction', 'autonomous_recovery', 'raw_seed_volume'] as $key) {
            $this->assertArrayHasKey($key, $components, "missing component: {$key}");
            $this->assertArrayHasKey('value', $components[$key]);
            $this->assertArrayHasKey('weight', $components[$key]);
            $this->assertArrayHasKey('contribution', $components[$key]);
        }
        // 3 implemented outcomes × weight 10 = 30
        $this->assertEqualsWithDelta(30.0, $components['implemented_outcomes']['contribution'], 0.0001);
        // give_back improved by 0.2 → give_back_reduction value = 0.2 × weight 5 = 1.0
        $this->assertEqualsWithDelta(1.0, $components['give_back_reduction']['contribution'], 0.0001);
        // 2 raw seeds × weight 1 = 2
        $this->assertEqualsWithDelta(2.0, $components['raw_seed_volume']['contribution'], 0.0001);
    }
}
