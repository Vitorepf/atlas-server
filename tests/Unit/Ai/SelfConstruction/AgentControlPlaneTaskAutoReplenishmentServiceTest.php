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

final class AgentControlPlaneTaskAutoReplenishmentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_terminal_seed_is_not_reissued_and_is_reported_as_skipped(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $service = $this->service($queue);

        $first = $service->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
        ]);
        $this->assertSame(1, $first['generated_task_count']);

        $taskPacketId = (string) $first['generated_tasks'][0]['task_packet_id'];
        $seedKey = (string) $first['generated_tasks'][0]['seed_key'];

        $queue->updateStatus($taskPacketId, 'cancelled');

        $second = $service->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
        ]);

        $this->assertSame(0, $second['generated_task_count']);
        $this->assertGreaterThan(0, $second['skipped_duplicate_seed_count']);

        $skippedSeeds = (array) $second['plan_evaluation']['skipped_duplicate_seeds'];
        $matching = array_values(array_filter(
            $skippedSeeds,
            static fn (array $s): bool => $s['seed_key'] === $seedKey,
        ));

        $this->assertNotEmpty($matching, 'expected the terminal seed to be reported as skipped');
        $this->assertSame('auto_replenishment_seed_already_terminal', $matching[0]['reason']);
        $this->assertSame($taskPacketId, $matching[0]['existing_task_packet_id']);
        $this->assertSame('cancelled', $matching[0]['existing_status']);
    }

    public function test_evaluate_worker_feed_risk_no_active_leases_returns_top_up_false(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $service = $this->service($queue);

        $result = $service->evaluateWorkerFeedRisk([
            'active_leases' => 0,
            'claimable_depth' => 5,
        ]);

        $this->assertFalse($result['top_up_required']);
        $this->assertSame(0, $result['target_new_packets']);
        $this->assertNull($result['reason']);
    }

    public function test_evaluate_worker_feed_risk_above_floor_returns_top_up_false(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $service = $this->service($queue);

        $result = $service->evaluateWorkerFeedRisk([
            'active_leases' => 3,
            'claimable_depth' => 20,
            'min_claimable_per_worker' => 2.0,
        ]);

        $this->assertFalse($result['top_up_required']);
        $this->assertSame(0, $result['target_new_packets']);
    }

    public function test_evaluate_worker_feed_risk_below_floor_returns_top_up_true_with_reason(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $service = $this->service($queue);

        $result = $service->evaluateWorkerFeedRisk([
            'active_leases' => 5,
            'claimable_depth' => 3,
            'min_claimable_per_worker' => 2.0,
            'batch_cap' => 10,
        ]);

        $this->assertTrue($result['top_up_required']);
        $this->assertSame('worker_feed_risk', $result['reason']);
        $this->assertGreaterThan(0, $result['target_new_packets']);
        $this->assertLessThanOrEqual(10, $result['target_new_packets']);
        $this->assertContains('claimable_per_worker_below_floor', $result['feed_risk_reasons']);
    }

    public function test_evaluate_worker_feed_risk_respects_batch_cap(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $service = $this->service($queue);

        $result = $service->evaluateWorkerFeedRisk([
            'active_leases' => 20,
            'claimable_depth' => 0,
            'min_claimable_per_worker' => 2.0,
            'batch_cap' => 5,
        ]);

        $this->assertTrue($result['top_up_required']);
        $this->assertSame('worker_feed_risk', $result['reason']);
        $this->assertSame(5, $result['target_new_packets']);
    }

    public function test_terminal_seed_reissue_allowed_with_explicit_opt_in(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $service = $this->service($queue);

        $first = $service->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
        ]);
        $taskPacketId = (string) $first['generated_tasks'][0]['task_packet_id'];
        $queue->updateStatus($taskPacketId, 'cancelled');

        $second = $service->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'allow_terminal_reissue' => true,
        ]);

        $this->assertSame(1, $second['generated_task_count']);
    }

    public function test_replenish_never_calls_provider_or_starts_process(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $service = $this->service($queue);

        $result = $service->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
        ]);

        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertFalse($result['ledger_write_allowed']);
        $this->assertFalse($result['completion_real_allowed']);
    }

    public function test_replenish_generated_entry_has_required_fields(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $service = $this->service($queue);

        $result = $service->replenish($this->context(), [
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
        ]);

        $this->assertGreaterThan(0, $result['generated_task_count']);
        $entry = $result['generated_tasks'][0];
        $this->assertNotEmpty($entry['seed_key']);
        $this->assertNotEmpty($entry['task_packet_id']);
        $this->assertNotEmpty($entry['task_packet_hash']);
        $this->assertSame('prepared_and_enqueued', $entry['event']);
        $this->assertNotEmpty($entry['source']);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function context(array $override = []): array
    {
        return array_replace_recursive([
            'control_plane' => [
                'control_plane' => [
                    'persistent_runtime' => [
                        'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract',
                    ],
                    'not_yet_runtime_capable' => [
                        'adapter_execution_runtime',
                        'automatic_cost_import_runtime',
                    ],
                ],
            ],
            'completion_audit' => [
                'failed_criteria' => [],
            ],
            'chain_integrity' => [
                'violations' => [],
            ],
        ], $override);
    }

    private function service(AgentControlPlaneTaskPacketQueueRepository $queue): AgentControlPlaneTaskAutoReplenishmentService
    {
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
