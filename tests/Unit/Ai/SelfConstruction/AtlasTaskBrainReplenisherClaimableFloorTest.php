<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskBrainReplenisher;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasTaskBrainReplenisherClaimableFloorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function replenisher(): AtlasTaskBrainReplenisher
    {
        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );

        return new AtlasTaskBrainReplenisher($orchestrator);
    }

    public function test_claimable_at_or_below_floor_forces_replenish_with_worker_feed_floor_fact(): void
    {
        $result = $this->replenisher()->nextBatchDecision([
            'claimable_per_active_worker' => 2.0,
            'existing_policy_should_replenish' => false,
        ]);

        $this->assertTrue($result['should_replenish']);
        $this->assertContains(AtlasTaskBrainReplenisher::DECISION_FACT_WORKER_FEED_FLOOR, $result['decision_facts']);
        $this->assertTrue($result['worker_feed_floor_breached']);
    }

    public function test_claimable_below_floor_forces_replenish(): void
    {
        $result = $this->replenisher()->nextBatchDecision([
            'claimable_per_active_worker' => 0.5,
            'existing_policy_should_replenish' => false,
        ]);

        $this->assertTrue($result['should_replenish']);
        $this->assertContains(AtlasTaskBrainReplenisher::DECISION_FACT_WORKER_FEED_FLOOR, $result['decision_facts']);
    }

    public function test_claimable_above_floor_does_not_force_replenish_when_existing_policy_waits(): void
    {
        $result = $this->replenisher()->nextBatchDecision([
            'claimable_per_active_worker' => 10.0,
            'existing_policy_should_replenish' => false,
        ]);

        $this->assertFalse($result['should_replenish']);
        $this->assertNotContains(AtlasTaskBrainReplenisher::DECISION_FACT_WORKER_FEED_FLOOR, $result['decision_facts']);
        $this->assertFalse($result['worker_feed_floor_breached']);
    }

    public function test_existing_policy_still_drives_replenish_above_floor(): void
    {
        $result = $this->replenisher()->nextBatchDecision([
            'claimable_per_active_worker' => 10.0,
            'existing_policy_should_replenish' => true,
        ]);

        $this->assertTrue($result['should_replenish']);
        $this->assertContains(AtlasTaskBrainReplenisher::DECISION_FACT_EXISTING_POLICY, $result['decision_facts']);
        $this->assertNotContains(AtlasTaskBrainReplenisher::DECISION_FACT_WORKER_FEED_FLOOR, $result['decision_facts']);
    }

    public function test_missing_claimable_facts_default_to_not_breached(): void
    {
        $result = $this->replenisher()->nextBatchDecision([]);

        $this->assertFalse($result['should_replenish']);
        $this->assertFalse($result['worker_feed_floor_breached']);
    }
}
