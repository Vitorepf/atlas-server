<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneWorkerTaskEligibilityCertificationService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves AgentControlPlaneWorkerTaskEligibilityCertificationService::certify() exposes a
 * top-level `blocked_reasons` contract: non-empty and stable when status=blocked, empty when
 * status=available, derived from failed_check_ids and violation codes so callers never see
 * status=blocked with no actionable reason.
 */
final class AgentControlPlaneWorkerTaskEligibilityCertificationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_blocked_status_has_non_empty_blocked_reasons(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-blocked-1',
            'task_packet_hash' => 'hash-1',
            'status' => 'planned',
            'continuation_context' => [
                'operator_handoff_required' => true,
            ],
        ]);

        $result = $this->service($queue)->certify();

        $this->assertSame('blocked', $result['status']);
        $this->assertNotEmpty($result['blocked_reasons']);
        $this->assertContains(
            'claimable_or_claimed_task_requires_operator_handoff',
            $result['blocked_reasons'],
        );
    }

    public function test_blocked_reasons_includes_failed_check_id(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-blocked-2',
            'task_packet_hash' => 'hash-2',
            'status' => 'planned',
            'continuation_context' => [
                'operator_handoff_required' => true,
            ],
        ]);

        $result = $this->service($queue)->certify();

        $this->assertContains(
            'claimable_tasks_do_not_require_operator_handoff',
            $result['failed_check_ids'],
        );
        $this->assertContains(
            'claimable_tasks_do_not_require_operator_handoff',
            $result['blocked_reasons'],
        );
    }

    public function test_available_status_has_empty_blocked_reasons_and_checks_all_true(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;

        $result = $this->service($queue)->certify();

        $this->assertSame('available', $result['status']);
        $this->assertSame([], $result['blocked_reasons']);
        $this->assertTrue($result['checks_all_true']);
        $this->assertSame([], $result['failed_check_ids']);
    }

    public function test_blocked_reasons_is_deterministic_across_repeated_calls(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $queue->enqueue([
            'task_packet_id' => 'tp-blocked-3',
            'task_packet_hash' => 'hash-3',
            'status' => 'planned',
            'continuation_context' => [
                'operator_handoff_required' => true,
            ],
        ]);

        $service = $this->service($queue);
        $first = $service->certify();
        $second = $service->certify();

        $this->assertSame($first['blocked_reasons'], $second['blocked_reasons']);
    }

    private function service(AgentControlPlaneTaskPacketQueueRepository $queue): AgentControlPlaneWorkerTaskEligibilityCertificationService
    {
        return new AgentControlPlaneWorkerTaskEligibilityCertificationService(
            app(AtlasSelfConstructionReadinessService::class),
            $queue,
        );
    }
}
