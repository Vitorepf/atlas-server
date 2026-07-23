<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
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
