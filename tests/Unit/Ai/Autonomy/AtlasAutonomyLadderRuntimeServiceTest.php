<?php

namespace Tests\Unit\Ai\Autonomy;

use App\Services\Ai\Autonomy\AtlasAutonomyDemoteWatchdog;
use App\Services\Ai\Autonomy\AtlasAutonomyLadderRuntimeService;
use App\Services\Ai\Autonomy\AtlasAutonomyMetricsAggregator;
use Tests\TestCase;

class AtlasAutonomyLadderRuntimeServiceTest extends TestCase
{
    public function test_ladder_exposes_eight_levels_l0_to_l7(): void
    {
        $ladder = $this->service()->ladder();

        $this->assertCount(8, $ladder);
        $this->assertSame('L0', $ladder[0]['level']);
        $this->assertSame('L7', $ladder[7]['level']);
        $this->assertSame('Self-Evolving', $ladder[7]['name']);
        $this->assertNull($ladder[7]['next_level']);
    }

    public function test_promotion_eligible_when_criteria_and_single_signature_met(): void
    {
        $result = $this->service()->evaluatePromotion('L1', [
            'consecutive_green_slices' => 20,
            'scope_violation_count' => 0,
            'repair_loop_count' => 1,
        ], ['operator' => true]);

        $this->assertTrue($result['eligible']);
        $this->assertSame('promote', $result['decision']);
        $this->assertSame('L2', $result['next_level']);
        $this->assertSame([], $result['unmet_criteria']);
    }

    public function test_promotion_to_l4_blocked_without_dual_signature(): void
    {
        $metrics = ['cert_green_features' => 15, 'blocker_in_review_per_feature' => 1];

        $operatorOnly = $this->service()->evaluatePromotion('L3', $metrics, ['operator' => true]);
        $this->assertFalse($operatorOnly['eligible']);
        $this->assertContains('architect', $operatorOnly['signatures']['missing']);

        $dual = $this->service()->evaluatePromotion('L3', $metrics, ['operator' => true, 'architect' => true]);
        $this->assertTrue($dual['eligible']);
        $this->assertSame('L4', $dual['next_level']);
    }

    public function test_promotion_blocked_when_a_criterion_is_unmet(): void
    {
        $result = $this->service()->evaluatePromotion('L1', [
            'consecutive_green_slices' => 19, // below 20
            'scope_violation_count' => 0,
            'repair_loop_count' => 1,
        ], ['operator' => true]);

        $this->assertFalse($result['eligible']);
        $this->assertSame('blocked', $result['decision']);
        $this->assertSame('consecutive_green_slices', $result['unmet_criteria'][0]['metric']);
    }

    public function test_l7_is_at_ceiling(): void
    {
        $result = $this->service()->evaluatePromotion('L7', [], ['operator' => true]);

        $this->assertFalse($result['eligible']);
        $this->assertSame('at_ceiling', $result['decision']);
        $this->assertNull($result['next_level']);
    }

    public function test_auto_demote_after_two_consecutive_breaching_cycles(): void
    {
        $breach = ['green_pair_obras' => 10, 'regression_catch_rate' => 0.5];

        $watchdog = new AtlasAutonomyDemoteWatchdog($this->service());
        $out = $watchdog->watch('L2', [$breach, $breach]);

        $this->assertTrue($out['decision']['demote']);
        $this->assertSame('L1', $out['decision']['to_level']);
        $this->assertSame('automatic_demote', $out['demote_receipt']['kind']);
        $this->assertFalse($out['demote_receipt']['signature_required']);
    }

    public function test_no_demote_when_latest_cycle_holds(): void
    {
        $breach = ['green_pair_obras' => 10, 'regression_catch_rate' => 0.5];
        $ok = ['green_pair_obras' => 30, 'regression_catch_rate' => 0.95];

        $result = $this->service()->evaluateDemote('L2', [$breach, $ok]);

        $this->assertFalse($result['demote']);
        $this->assertSame('L2', $result['to_level']);
    }

    public function test_metrics_aggregator_derives_acceptance_rate(): void
    {
        $metrics = (new AtlasAutonomyMetricsAggregator)->aggregate([
            'assist_sessions' => 50,
            'accepted_sessions' => 45,
            'total_sessions' => 50,
            'severe_hallucination_count' => 0,
        ]);

        $this->assertSame(50.0, $metrics['assist_sessions']);
        $this->assertSame(0.9, $metrics['acceptance_rate']);
    }

    public function test_comparator_satisfied_epsilon(): void
    {
        $service = $this->service();
        $this->assertTrue($service->comparatorSatisfied('>=', 0.969999999, 0.97));
        $this->assertTrue($service->comparatorSatisfied('<=', 0.10, 0.10));
        $this->assertFalse($service->comparatorSatisfied('>=', 0.5, 0.9));
    }

    private function service(): AtlasAutonomyLadderRuntimeService
    {
        return new AtlasAutonomyLadderRuntimeService;
    }
}
