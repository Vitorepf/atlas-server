<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionNativeReplenisherEnqueueRunner;
use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionQueueTopUpPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionNativeReplenisherEnqueueRunner: bounded enqueue stops at
 * new_packet_count even when accepted has more; duplicate ('existing' status) lands in
 * skipped_existing; prepare_blocked is RECORDED as repair evidence (NOT counted as enqueued);
 * outcome != 'allow' ⇒ counts.attempted=0.
 */
final class AtlasSelfConstructionNativeReplenisherEnqueueRunnerTest extends TestCase
{
    /**
     * Fake orchestrator with a recorded prepareAndEnqueue() method. Returns the status keyed by
     * frontier_id (defaults to 'enqueued').
     */
    private function fakeOrchestrator(array $statusByFrontier): object
    {
        return new class($statusByFrontier)
        {
            public function __construct(private readonly array $byId) {}

            public function prepareAndEnqueue(array $packet): array
            {
                $id = (string) ($packet['frontier_id'] ?? '');
                $status = $this->byId[$id] ?? 'enqueued';
                if ($status === 'prepare_blocked') {
                    return ['status' => 'prepare_blocked', 'reason' => 'inspector_red'];
                }

                return ['status' => $status];
            }
        };
    }

    public function test_bounded_enqueue_stops_at_new_packet_count(): void
    {
        $accepted = [
            ['packet' => ['frontier_id' => 'f-1']],
            ['packet' => ['frontier_id' => 'f-2']],
            ['packet' => ['frontier_id' => 'f-3']],
        ];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 2];
        $orchestrator = $this->fakeOrchestrator([]);

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $orchestrator);
        $this->assertCount(2, $r['enqueued']);
        $this->assertSame(2, $r['counts']['attempted']);
    }

    public function test_existing_status_lands_in_skipped_existing(): void
    {
        $accepted = [['packet' => ['frontier_id' => 'f-dup']]];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $orchestrator = $this->fakeOrchestrator(['f-dup' => 'existing']);

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $orchestrator);
        $this->assertContains('f-dup', $r['skipped_existing']);
        $this->assertSame(0, $r['counts']['enqueued']);
    }

    public function test_prepare_blocked_is_recorded_not_counted_as_enqueued(): void
    {
        $accepted = [['packet' => ['frontier_id' => 'f-block']]];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $orchestrator = $this->fakeOrchestrator(['f-block' => 'prepare_blocked']);

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $orchestrator);
        $this->assertSame(0, $r['counts']['enqueued']);
        $this->assertSame(1, $r['counts']['prepare_blocked']);
        $this->assertSame('inspector_red', $r['prepare_blocked'][0]['reason']);
    }

    public function test_outcome_not_allow_yields_zero_attempted(): void
    {
        $accepted = [['packet' => ['frontier_id' => 'f-1']]];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_WAIT, 'new_packet_count' => 5];
        $orchestrator = $this->fakeOrchestrator([]);

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $orchestrator);
        $this->assertSame(0, $r['counts']['attempted']);
        $this->assertSame([], $r['enqueued']);
    }

    public function test_orchestrator_throws_is_recorded_as_prepare_blocked(): void
    {
        $accepted = [['packet' => ['frontier_id' => 'f-throw']]];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $orchestrator = new class
        {
            public function prepareAndEnqueue(array $packet): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $orchestrator);
        $this->assertStringContainsString('orchestrator_threw:boom', $r['prepare_blocked'][0]['reason']);
    }

    public function test_lists_are_sorted_byte_stably(): void
    {
        $accepted = [
            ['packet' => ['frontier_id' => 'zeta']],
            ['packet' => ['frontier_id' => 'alpha']],
        ];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $this->fakeOrchestrator([]));
        $this->assertSame(['alpha', 'zeta'], $r['enqueued']);
    }
}
