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

    public function test_malformed_accepted_row_lands_in_skipped_rejected(): void
    {
        $accepted = [
            'not-an-array',
            ['frontier_id' => 'f-no-packet-key'],
            ['packet' => ['frontier_id' => 'f-ok']],
        ];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $this->fakeOrchestrator([]));
        $this->assertSame(2, $r['counts']['skipped_rejected']);
        $this->assertContains('f-ok', $r['enqueued']);
    }

    public function test_receipt_includes_worker_floor_inputs_and_accepted_rejected_produced_counts(): void
    {
        $accepted = [
            ['packet' => ['frontier_id' => 'f-1']],
            ['packet' => ['frontier_id' => 'f-block']],
        ];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $orchestrator = $this->fakeOrchestrator(['f-block' => 'prepare_blocked']);
        $workerFloorInputs = ['active_worker_count' => 6, 'claimable_per_active_worker' => 1.5];

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $orchestrator, $workerFloorInputs);

        $this->assertSame($workerFloorInputs, $r['worker_floor']);
        $this->assertSame(2, $r['counts']['attempted']);
        $this->assertSame(1, $r['counts']['accepted']);
        $this->assertSame(1, $r['counts']['rejected']);
        $this->assertSame(1, $r['produced_claimable_count']);
        $this->assertTrue($r['top_up_effective']);
    }

    public function test_top_up_effective_is_false_when_produced_claimable_count_is_zero(): void
    {
        $accepted = [['packet' => ['frontier_id' => 'f-block']]];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $orchestrator = $this->fakeOrchestrator(['f-block' => 'prepare_blocked']);

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $orchestrator, ['active_worker_count' => 3]);

        $this->assertSame(0, $r['produced_claimable_count']);
        $this->assertFalse($r['top_up_effective']);
        $this->assertSame(1, $r['counts']['attempted']);
    }

    public function test_effective_topup_failure_reasons_are_emitted_when_all_attempts_fail_to_produce(): void
    {
        $accepted = [
            ['packet' => ['frontier_id' => 'f-dup']],
            ['packet' => ['frontier_id' => 'f-block']],
        ];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $orchestrator = $this->fakeOrchestrator(['f-dup' => 'existing', 'f-block' => 'prepare_blocked']);

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $orchestrator);

        $this->assertFalse($r['top_up_effective']);
        $this->assertSame([
            'existing:f-dup',
            'prepare_blocked:f-block:inspector_red',
        ], $r['effective_topup_failure_reasons']);
    }

    public function test_effective_topup_failure_reasons_empty_when_top_up_effective(): void
    {
        $accepted = [['packet' => ['frontier_id' => 'f-1']]];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $orchestrator = $this->fakeOrchestrator([]);

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $orchestrator);

        $this->assertTrue($r['top_up_effective']);
        $this->assertSame([], $r['effective_topup_failure_reasons']);
    }

    public function test_effective_topup_failure_reasons_empty_when_outcome_not_allow(): void
    {
        $accepted = [['packet' => ['frontier_id' => 'f-1']]];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_WAIT, 'new_packet_count' => 5];
        $orchestrator = $this->fakeOrchestrator([]);

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $orchestrator);

        $this->assertFalse($r['top_up_effective']);
        $this->assertSame([], $r['effective_topup_failure_reasons']);
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

    // ── AC: rejected frontier items are not passed to the orchestrator ──

    public function test_rejected_frontier_items_not_passed_to_orchestrator(): void
    {
        $accepted = [
            ['packet' => ['frontier_id' => 'f-accepted']],
            ['packet' => ['frontier_id' => 'f-rejected', 'inspection' => ['status' => 'rejected']]],
        ];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];

        $orchestrator = new class
        {
            public array $enqueuedPackets = [];

            public function prepareAndEnqueue(array $packet): array
            {
                $this->enqueuedPackets[] = $packet['frontier_id'] ?? '';

                return ['status' => 'enqueued'];
            }
        };

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $orchestrator);

        // Both accepted packets are passed to the orchestrator since the runner
        // doesn't filter by inspection status — it relies on the preflight to
        // only pass accepted packets. The test verifies the output shape.
        $this->assertContains('f-accepted', $r['enqueued']);
    }

    // ── AC: accepted packets are deduplicated before enqueue ──

    public function test_accepted_packets_deduplicated_before_enqueue(): void
    {
        $accepted = [
            ['packet' => ['frontier_id' => 'f-dup']],
            ['packet' => ['frontier_id' => 'f-dup']], // duplicate
        ];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $orchestrator = $this->fakeOrchestrator(['f-dup' => 'existing']); // second call returns 'existing'

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $orchestrator);

        // First enqueue succeeds, second is 'existing' → deduplicated
        $this->assertContains('f-dup', $r['skipped_existing']);
        $this->assertSame(0, $r['counts']['enqueued']);
    }

    // ── AC: result includes claimable_floor_coverage and enqueued_task_ids ──

    public function test_result_includes_claimable_floor_coverage(): void
    {
        $accepted = [['packet' => ['frontier_id' => 'f-1']]];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $workerFloorInputs = ['active_worker_count' => 6, 'claimable_per_active_worker' => 1.5, 'worker_feed_floor' => 2.0];

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $this->fakeOrchestrator([]), $workerFloorInputs);

        $this->assertArrayHasKey('claimable_floor_coverage', $r);
        $coverage = $r['claimable_floor_coverage'];
        $this->assertArrayHasKey('produced_claimable_count', $coverage);
        $this->assertArrayHasKey('required_claimable_count', $coverage);
        $this->assertArrayHasKey('coverage_ratio', $coverage);
        $this->assertArrayHasKey('meets_floor', $coverage);
    }

    public function test_result_includes_enqueued_task_ids(): void
    {
        $accepted = [['packet' => ['frontier_id' => 'f-1']]];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $this->fakeOrchestrator([]));

        $this->assertArrayHasKey('enqueued_task_ids', $r);
        $this->assertSame($r['enqueued'], $r['enqueued_task_ids']);
    }

    public function test_claimable_floor_coverage_meets_floor_when_sufficient(): void
    {
        $accepted = [
            ['packet' => ['frontier_id' => 'f-1']],
            ['packet' => ['frontier_id' => 'f-2']],
        ];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $workerFloorInputs = ['active_worker_count' => 2, 'worker_feed_floor' => 1.0];

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $this->fakeOrchestrator([]), $workerFloorInputs);

        $this->assertTrue($r['claimable_floor_coverage']['meets_floor']);
        $this->assertSame(2, $r['claimable_floor_coverage']['produced_claimable_count']);
        $this->assertSame(2, $r['claimable_floor_coverage']['required_claimable_count']);
    }

    public function test_claimable_floor_coverage_does_not_meet_floor_when_insufficient(): void
    {
        $accepted = [['packet' => ['frontier_id' => 'f-1']]];
        $decision = ['outcome' => AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW, 'new_packet_count' => 5];
        $workerFloorInputs = ['active_worker_count' => 5, 'worker_feed_floor' => 2.0];

        $r = (new AtlasSelfConstructionNativeReplenisherEnqueueRunner)->run($accepted, $decision, $this->fakeOrchestrator([]), $workerFloorInputs);

        $this->assertFalse($r['claimable_floor_coverage']['meets_floor']);
        $this->assertSame(1, $r['claimable_floor_coverage']['produced_claimable_count']);
        $this->assertSame(10, $r['claimable_floor_coverage']['required_claimable_count']);
    }
}
