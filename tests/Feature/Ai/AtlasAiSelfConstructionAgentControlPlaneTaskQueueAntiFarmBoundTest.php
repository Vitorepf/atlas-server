<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskCoordinationHealthService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTaskQueueAntiFarmBoundTest extends TestCase
{
    use MakesAgentControlPlaneTaskQueueOrchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_prepare_blocks_before_materializing_an_unbounded_claimable_queue(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $builder = new AgentControlPlaneTaskPacketBuilder;

        for ($index = 0; $index < 65; $index++) {
            $packet = $this->input('anti-farm-bound-'.$index);
            $packet['objective'] = 'independent bounded admission scenario '.$index;
            $packet['acceptance_criteria'] = ['prove isolated admission constraint '.$index];
            $queue->enqueue($builder->build($packet));
        }

        $blocked = $this->orchestrator()->prepareAndEnqueue([
            'task_packet' => $this->input('anti-farm-bound-candidate'),
        ]);

        $this->assertSame('prepare_blocked', $blocked['event']);
        $this->assertSame('anti_farm_queue_scan_limit_exceeded', $blocked['reason']);
        $this->assertSame(65, data_get($blocked, 'anti_farm_gate.claimable_count'));
        $this->assertSame(64, data_get($blocked, 'anti_farm_gate.scan_limit'));
        $this->assertNull($queue->get('anti-farm-bound-candidate'));
    }

    public function test_prepare_keeps_same_packet_id_replay_idempotent_over_the_scan_limit(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $replay = $this->input('anti-farm-bound-replay');
        $queue->enqueue($builder->build($replay));

        for ($index = 0; $index < 64; $index++) {
            $packet = $this->input('anti-farm-bound-replay-'.$index);
            $packet['objective'] = 'independent bounded replay scenario '.$index;
            $packet['acceptance_criteria'] = ['prove isolated replay constraint '.$index];
            $queue->enqueue($builder->build($packet));
        }

        $result = $this->orchestrator()->prepareAndEnqueue(['task_packet' => $replay]);

        $this->assertSame('prepared_and_enqueued', $result['event']);
        $this->assertTrue((bool) data_get($result, 'queue_entry.idempotent'));
        $this->assertSame(65, data_get($queue->registry(['status' => 'claimable'], true), 'entry_count'));
    }

    public function test_claim_blocks_when_only_unscanned_packets_may_be_servable(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $builder = new AgentControlPlaneTaskPacketBuilder;

        for ($index = 0; $index < 64; $index++) {
            $packet = $this->input('claim-bound-blocked-'.$index);
            $queue->enqueue($builder->build($packet), [
                'metadata' => ['depends_on' => ['missing-claim-bound-'.$index]],
            ]);
        }
        $queue->enqueue($builder->build($this->input('claim-bound-later')));

        $blocked = $this->orchestrator()->claimNext('claim-bound-worker');

        $this->assertSame('claim_blocked', $blocked['event']);
        $this->assertSame('queue_scan_limit_exceeded', $blocked['reason']);
        $this->assertSame(64, $blocked['candidate_count']);
        $this->assertSame(65, $blocked['minimum_claimable_count']);
        $this->assertSame(64, $blocked['scan_limit']);
        $this->assertSame('claimable', data_get($queue->get('claim-bound-later'), 'status'));
    }

    public function test_servability_refuses_to_classify_an_unbounded_claimable_queue(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $builder = new AgentControlPlaneTaskPacketBuilder;

        for ($index = 0; $index < 65; $index++) {
            $packet = $this->input('servability-bound-'.$index);
            $packet['objective'] = 'independent bounded servability scenario '.$index;
            $packet['acceptance_criteria'] = ['prove isolated servability constraint '.$index];
            $queue->enqueue($builder->build($packet));
        }

        $breakdown = $this->orchestrator()->servabilityBreakdown();

        $this->assertSame('blocked', $breakdown['status']);
        $this->assertSame('servability_queue_scan_limit_exceeded', $breakdown['reason']);
        $this->assertSame(65, $breakdown['claimable']);
        $this->assertSame(64, $breakdown['scan_limit']);
        $this->assertSame(0, $breakdown['inspected_claimable']);
        $this->assertNull($breakdown['servable_now']);
        $this->assertNull($breakdown['waiting_on_inflight_deps']);
    }

    public function test_health_marks_an_unbounded_servability_inventory_as_unknown_not_jammed(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $builder = new AgentControlPlaneTaskPacketBuilder;

        for ($index = 0; $index < 65; $index++) {
            $packet = $this->input('health-servability-bound-'.$index);
            $packet['objective'] = 'independent health servability scenario '.$index;
            $packet['acceptance_criteria'] = ['prove isolated health servability constraint '.$index];
            $queue->enqueue($builder->build($packet));
        }

        $snapshot = (new AtlasTaskCoordinationHealthService(
            $queue,
            new AgentControlPlaneClaimLeaseRepository,
            orchestrator: $this->orchestrator(),
        ))->snapshot();

        $this->assertFalse($snapshot['healthy']);
        $this->assertNull($snapshot['servable_now']);
        $this->assertTrue($snapshot['health_flags']['servability_scan_limit_exceeded']);
        $this->assertFalse($snapshot['health_flags']['serving_jammed']);
        $this->assertSame('unknown', $snapshot['worker_drain_forecast']['queue_pressure']);
        $this->assertSame('inspect_servability_scan_limit', $snapshot['worker_drain_forecast']['replenish_recommendation']);
    }

    public function test_malformed_sweep_refuses_an_unbounded_claimable_inventory(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $builder = new AgentControlPlaneTaskPacketBuilder;

        for ($index = 0; $index < 65; $index++) {
            $packet = $this->input('malformed-sweep-bound-'.$index);
            $packet['objective'] = 'independent bounded malformed sweep scenario '.$index;
            $packet['acceptance_criteria'] = ['prove isolated malformed sweep constraint '.$index];
            $queue->enqueue($builder->build($packet));
        }

        $sweep = $this->orchestrator()->sweepMalformedClaimableTasks(dryRun: true);

        $this->assertSame('blocked', $sweep['status']);
        $this->assertSame('malformed_sweep_scan_limit_exceeded', $sweep['reason']);
        $this->assertSame(65, $sweep['claimable_count']);
        $this->assertSame(64, $sweep['scan_limit']);
        $this->assertSame(0, $sweep['inspected_claimable']);
        $this->assertSame(0, $sweep['would_block_count']);
    }

    public function test_malformed_sweep_command_returns_failure_with_the_unbounded_inventory_receipt(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $builder = new AgentControlPlaneTaskPacketBuilder;

        for ($index = 0; $index < 65; $index++) {
            $packet = $this->input('malformed-sweep-command-bound-'.$index);
            $packet['objective'] = 'independent malformed sweep command scenario '.$index;
            $packet['acceptance_criteria'] = ['prove malformed sweep command constraint '.$index];
            $queue->enqueue($builder->build($packet));
        }

        $exit = Artisan::call('atlas:task:sweep-malformed', ['--dry-run' => true, '--json' => true]);
        $receipt = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('blocked', $receipt['status']);
        $this->assertSame('malformed_sweep_scan_limit_exceeded', $receipt['reason']);
        $this->assertSame(65, $receipt['claimable_count']);
    }

    public function test_forbidden_target_repair_refuses_an_unbounded_blocked_inventory(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $builder = new AgentControlPlaneTaskPacketBuilder;

        for ($index = 0; $index < 65; $index++) {
            $id = 'forbidden-repair-bound-'.$index;
            $packet = $this->input($id);
            $packet['objective'] = 'independent bounded forbidden target repair scenario '.$index;
            $packet['acceptance_criteria'] = ['prove forbidden target repair constraint '.$index];
            $queue->enqueue($builder->build($packet));
            $queue->updateStatus($id, 'blocked', ['reason' => 'fixture_blocked_for_bound_test']);
        }

        $repair = $this->orchestrator()->repairBlockedForbiddenSelfTargetTasks(dryRun: true);

        $this->assertSame('blocked', $repair['status']);
        $this->assertSame('forbidden_target_repair_scan_limit_exceeded', $repair['reason']);
        $this->assertSame(65, $repair['blocked_count']);
        $this->assertSame(64, $repair['scan_limit']);
        $this->assertSame(0, $repair['inspected_blocked']);
        $this->assertSame('blocked', data_get($queue->get('forbidden-repair-bound-64'), 'status'));
    }

    public function test_scope_repair_refuses_an_unbounded_blocked_inventory(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $builder = new AgentControlPlaneTaskPacketBuilder;

        for ($index = 0; $index < 65; $index++) {
            $id = 'scope-repair-bound-'.$index;
            $packet = $this->input($id);
            $packet['objective'] = 'independent bounded scope repair scenario '.$index;
            $packet['acceptance_criteria'] = ['prove scope repair constraint '.$index];
            $queue->enqueue($builder->build($packet));
            $queue->updateStatus($id, 'blocked', ['reason' => 'fixture_blocked_for_bound_test']);
        }

        $repair = $this->orchestrator()->repairScopeBlockedTasks(dryRun: true);

        $this->assertSame('blocked', $repair['status']);
        $this->assertSame('scope_repair_scan_limit_exceeded', $repair['reason']);
        $this->assertSame(65, $repair['blocked_count']);
        $this->assertSame(64, $repair['scan_limit']);
        $this->assertSame(0, $repair['inspected_blocked']);
        $this->assertSame('blocked', data_get($queue->get('scope-repair-bound-64'), 'status'));
    }

    public function test_repair_command_returns_failure_for_an_unbounded_blocked_inventory(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $builder = new AgentControlPlaneTaskPacketBuilder;

        for ($index = 0; $index < 65; $index++) {
            $id = 'repair-command-bound-'.$index;
            $packet = $this->input($id);
            $packet['objective'] = 'independent bounded repair command scenario '.$index;
            $packet['acceptance_criteria'] = ['prove repair command constraint '.$index];
            $queue->enqueue($builder->build($packet));
            $queue->updateStatus($id, 'blocked', ['reason' => 'fixture_blocked_for_bound_test']);
        }

        $exit = Artisan::call('atlas:task:repair-blocked', ['--dry-run' => true, '--json' => true]);
        $receipt = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('blocked', $receipt['status']);
        $this->assertSame('forbidden_target_repair_scan_limit_exceeded', $receipt['reason']);
        $this->assertSame('forbidden_self_target_repair', $receipt['repair_path']);
        $this->assertSame(65, $receipt['repair']['blocked_count']);
    }

    /** @return array<string, mixed> */
    private function input(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'anti farm bound '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['task_packet_created'],
        ];
    }
}
