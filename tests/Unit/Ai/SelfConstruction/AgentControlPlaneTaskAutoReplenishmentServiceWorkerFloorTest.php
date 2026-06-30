<?php

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pins evaluateWorkerFeedRisk(): a pure, bounded top-up plan computed from leading worker-feed
 * indicators (active_leases, claimable_depth, claimable_per_active_worker,
 * replenish_recommendation) so replenishment can start BEFORE workers hit no_claimable_task.
 */
final class AgentControlPlaneTaskAutoReplenishmentServiceWorkerFloorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_replenish_soon_recommendation_with_active_leases_triggers_worker_feed_risk_plan(): void
    {
        $result = $this->service()->evaluateWorkerFeedRisk([
            'active_leases' => 6,
            'claimable_depth' => 14,
            'replenish_recommendation' => 'replenish_soon',
        ]);

        $this->assertTrue($result['top_up_required']);
        $this->assertSame('worker_feed_risk', $result['reason']);
        $this->assertGreaterThan(0, $result['target_new_packets']);
    }

    public function test_low_claimable_per_active_worker_triggers_plan_without_explicit_recommendation(): void
    {
        $result = $this->service()->evaluateWorkerFeedRisk([
            'active_leases' => 5,
            'claimable_depth' => 5, // 1.0 per worker, below the 2.0 default threshold
        ]);

        $this->assertTrue($result['top_up_required']);
        $this->assertSame('worker_feed_risk', $result['reason']);
        $this->assertSame(1.0, $result['claimable_per_active_worker']);
    }

    public function test_no_active_leases_is_a_no_op(): void
    {
        $result = $this->service()->evaluateWorkerFeedRisk([
            'active_leases' => 0,
            'claimable_depth' => 0,
            'replenish_recommendation' => 'replenish_soon',
        ]);

        $this->assertFalse($result['top_up_required']);
        $this->assertSame(0, $result['target_new_packets']);
        $this->assertNull($result['reason']);
    }

    public function test_comfortable_worker_buffer_is_a_no_op(): void
    {
        $result = $this->service()->evaluateWorkerFeedRisk([
            'active_leases' => 5,
            'claimable_depth' => 50, // 10 per worker, comfortably above threshold
        ]);

        $this->assertFalse($result['top_up_required']);
        $this->assertSame(0, $result['target_new_packets']);
        $this->assertNull($result['reason']);
    }

    public function test_target_new_packets_is_bounded_by_batch_cap(): void
    {
        $result = $this->service()->evaluateWorkerFeedRisk([
            'active_leases' => 100,
            'claimable_depth' => 0,
            'replenish_recommendation' => 'replenish_soon',
            'batch_cap' => 3,
        ]);

        $this->assertSame(3, $result['target_new_packets']);
    }

    public function test_explicit_claimable_per_active_worker_overrides_derived_value(): void
    {
        $result = $this->service()->evaluateWorkerFeedRisk([
            'active_leases' => 10,
            'claimable_depth' => 100,
            'claimable_per_active_worker' => 1.0,
        ]);

        $this->assertTrue($result['top_up_required']);
        $this->assertSame(1.0, $result['claimable_per_active_worker']);
    }

    public function test_evaluate_worker_feed_risk_is_deterministic(): void
    {
        $service = $this->service();
        $context = ['active_leases' => 6, 'claimable_depth' => 14, 'replenish_recommendation' => 'replenish_soon'];

        $this->assertSame($service->evaluateWorkerFeedRisk($context), $service->evaluateWorkerFeedRisk($context));
    }

    public function test_thin_buffer_with_deep_claimable_depth_plans_more_than_a_token_top_up(): void
    {
        $result = $this->service()->evaluateWorkerFeedRisk([
            'active_leases' => 7,
            'claimable_depth' => 20,
            'claimable_per_active_worker' => 2,
            'min_claimable_per_worker' => 5,
            'batch_cap' => 10,
        ]);

        $this->assertTrue($result['top_up_required']);
        $this->assertGreaterThan(1, $result['target_new_packets']);
        $this->assertLessThanOrEqual(10, $result['target_new_packets']);
    }

    public function test_comfortable_buffer_with_no_recommendation_remains_a_no_op(): void
    {
        $result = $this->service()->evaluateWorkerFeedRisk([
            'active_leases' => 7,
            'claimable_depth' => 70,
            'claimable_per_active_worker' => 10,
            'min_claimable_per_worker' => 5,
            'batch_cap' => 10,
        ]);

        $this->assertFalse($result['top_up_required']);
        $this->assertSame(0, $result['target_new_packets']);
        $this->assertNull($result['reason']);
    }

    private function service(): AgentControlPlaneTaskAutoReplenishmentService
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        return new AgentControlPlaneTaskAutoReplenishmentService(
            new AgentControlPlaneTaskQueueOrchestrator(
                new AgentControlPlaneTaskPacketBuilder,
                new AgentControlPlaneScopeLockRuntimeValidator,
                $queue,
                new AgentControlPlaneClaimLeaseRepository,
                new AgentControlPlaneEvidenceLedgerDryRun,
                new AgentControlPlaneContinuationSummaryBuilder,
            ),
            $queue,
        );
    }
}
