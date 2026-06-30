<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedRespecPlanRegressionHarness;
use PHPUnit\Framework\TestCase;

final class AtlasTaskBlockedRespecPlanRegressionHarnessLiveShapeTest extends TestCase
{
    private function harness(): AtlasTaskBlockedRespecPlanRegressionHarness
    {
        return new AtlasTaskBlockedRespecPlanRegressionHarness();
    }

    public function test_live_shape_fixture_reproduces_current_all_unknown_blocked_plan(): void
    {
        $plan = $this->harness()->liveShapeBlockedPlan();

        $this->assertGreaterThan(0, $plan['blocked_count']);
        $this->assertArrayHasKey('unknown', $plan['blocked_counts']);
        $this->assertSame($plan['blocked_count'], $plan['blocked_counts']['unknown']);
        $this->assertFalse($plan['can_submit']);

        foreach ($plan['replacement_drafts'] as $draft) {
            $this->assertSame('review_recommended', $draft['recommendation']);
            $this->assertFalse($draft['can_submit']);
            $this->assertContains('allowed_files', $draft['missing_fields']);
            $this->assertContains('acceptance_criteria', $draft['missing_fields']);
            $this->assertContains('required_evidence', $draft['missing_fields']);
        }
    }

    public function test_live_shape_fixture_fails_the_harness_pass_condition(): void
    {
        $plan = $this->harness()->liveShapeBlockedPlan();
        $evaluation = $this->harness()->evaluatePlan($plan);

        $this->assertFalse($evaluation['passes']);
        $this->assertSame(0, $evaluation['submit_ready_count']);
        $this->assertSame(0, $evaluation['recovered_field_count']);
    }

    public function test_repaired_fixture_has_at_least_one_submit_ready_replacement(): void
    {
        $plan = $this->harness()->repairedLiveShapeBlockedPlan();

        $this->assertTrue($plan['can_submit']);
        $submitReady = array_values(array_filter($plan['replacement_drafts'], static fn (array $d): bool => $d['can_submit'] === true));
        $this->assertNotEmpty($submitReady);
    }

    public function test_repaired_fixture_passes_the_harness_and_records_recovered_field_count(): void
    {
        $plan = $this->harness()->repairedLiveShapeBlockedPlan();
        $evaluation = $this->harness()->evaluatePlan($plan);

        $this->assertTrue($evaluation['passes']);
        $this->assertGreaterThanOrEqual(1, $evaluation['submit_ready_count']);
        $this->assertGreaterThan(0, $evaluation['recovered_field_count']);
        $this->assertSame($evaluation['recovered_field_count'], $plan['recovered_field_count']);
    }

    public function test_repaired_fixture_recovered_field_count_matches_recovered_fields(): void
    {
        $plan = $this->harness()->repairedLiveShapeBlockedPlan();

        $this->assertSame(3, $plan['recovered_field_count']);
    }
}
