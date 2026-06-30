<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueLeaseCertificationService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AgentControlPlaneTaskQueueLeaseCertificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function certify(): array
    {
        $svc = new AgentControlPlaneTaskQueueLeaseCertificationService(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
        );

        return $svc->certify();
    }

    public function test_probe_containment_lists_probe_ids_tags_and_counts(): void
    {
        $result = $this->certify();

        $this->assertArrayHasKey('probe_containment', $result);
        $containment = $result['probe_containment'];

        $this->assertNotEmpty($containment['probe_task_packet_ids']);
        $this->assertNotEmpty($containment['probe_tags']);
        $this->assertSame(count($containment['probe_task_packet_ids']), $containment['created_task_count']);
        $this->assertSame(count($containment['probe_lease_ids']), $containment['created_lease_count']);
        $this->assertGreaterThan(0, $containment['created_task_count']);
        $this->assertGreaterThan(0, $containment['created_lease_count']);
        $this->assertFalse($containment['real_worker_lane_touched']);

        foreach ($containment['probe_task_packet_ids'] as $id) {
            $this->assertStringStartsWith('probe_', $id);
        }
        foreach ($containment['probe_tags'] as $tag) {
            $this->assertTrue(
                str_starts_with($tag, 'certification_lane_') || str_starts_with($tag, 'worker_lane_'),
            );
        }
    }

    public function test_probe_containment_does_not_flip_any_runtime_safety_flag(): void
    {
        $result = $this->certify();

        foreach ([
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'completion_real_allowed',
        ] as $flag) {
            $this->assertFalse($result[$flag], "{$flag} must remain false after probe containment");
        }

        $this->assertSame('available', $result['status']);
        $this->assertTrue($result['invariants_all_true']);
    }
}
