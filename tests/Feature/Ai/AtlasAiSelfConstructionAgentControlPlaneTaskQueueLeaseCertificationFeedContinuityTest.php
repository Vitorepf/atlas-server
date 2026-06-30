<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueLeaseCertificationService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTaskQueueLeaseCertificationFeedContinuityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function service(): AgentControlPlaneTaskQueueLeaseCertificationService
    {
        return new AgentControlPlaneTaskQueueLeaseCertificationService(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
        );
    }

    public function test_active_leases_with_insufficient_claimable_floor_records_violation(): void
    {
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $validator = new AgentControlPlaneScopeLockRuntimeValidator;

        $file = 'app/Services/Ai/SelfConstruction/__feed_continuity_fixture__/fixture.php';
        $packet = $builder->build([
            'task_packet_id' => 'feed-continuity-fixture',
            'objective' => 'feed continuity fixture task',
            'operator_id' => 'fixture-operator',
            'allowed_files' => [$file],
            'scope_in' => [$file],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
            'risk_level' => 'low',
        ]);
        $validation = $validator->validate($packet);
        $queue->enqueue($packet);

        $scopeLock = [
            'write_set' => (array) data_get($packet, 'normalized_scope.allowed_files', []),
            'read_set' => (array) data_get($packet, 'normalized_scope.scope_in', []),
            'scope_lock_plan_hash' => (string) $validation['scope_lock_hash'],
        ];
        $claim = $leases->claim('feed-continuity-fixture', 'agent-fixture', $scopeLock);
        $this->assertSame('ok', (string) $claim['status']);

        $svc = $this->service();
        $result = $svc->certify(['active_worker_count' => 10, 'minimum_claimable_per_worker' => 1]);

        $this->assertArrayHasKey('feed_continuity_floor', $result['invariants']);
        $this->assertFalse($result['invariants']['feed_continuity_floor']);

        $codes = array_column($result['violations'], 'code');
        $this->assertContains('feed_continuity_floor', $codes);
        $this->assertSame('blocked', $result['status']);

        if (($claim['lease_id'] ?? '') !== '') {
            $leases->release((string) $claim['lease_id'], 'agent-fixture');
        }
    }

    public function test_runtime_safety_flags_remain_false_even_when_floor_violated(): void
    {
        $result = $this->service()->certify(['active_worker_count' => 1000]);

        foreach (['runtime_execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'self_programming_allowed', 'ledger_write_allowed', 'completion_real_allowed'] as $flag) {
            $this->assertFalse($result[$flag]);
        }
        $this->assertTrue($result['runtime_safety']['runtime_safety_all_false']);
    }

    public function test_no_active_worker_count_supplied_skips_floor_check_unaffected(): void
    {
        $result = $this->service()->certify();

        $this->assertArrayNotHasKey('feed_continuity_floor', $result['invariants']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertSame('available', $result['status']);
    }

    public function test_no_active_lease_leak_and_registry_mismatch_invariants_present(): void
    {
        $result = $this->service()->certify();

        $this->assertArrayHasKey('no_active_lease_leak', $result['invariants']);
        $this->assertArrayHasKey('no_registry_lease_mismatch', $result['invariants']);
        $this->assertTrue($result['invariants']['no_active_lease_leak']);
        $this->assertTrue($result['invariants']['no_registry_lease_mismatch']);
    }
}
