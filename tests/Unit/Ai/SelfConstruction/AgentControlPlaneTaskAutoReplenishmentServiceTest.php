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
