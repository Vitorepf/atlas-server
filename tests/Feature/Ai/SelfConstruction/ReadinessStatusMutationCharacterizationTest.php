<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ReadinessStatusMutationCharacterizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_task_queue_orchestrator_status_mutates_while_reporting_a_read_only_contract(): void
    {
        $disk = Storage::disk('local');
        $before = $disk->allFiles(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-orchestrator-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertFalse((bool) $payload['runtime_write_allowed']);
        $this->assertSame('read_only_agent_control_plane_task_queue_orchestrator_status', $payload['mode']);
        $this->assertSame('prepared_and_enqueued', data_get($payload, 'agent_control_plane_task_queue_orchestrator_status.event'));

        $after = $disk->allFiles(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX);
        $created = array_values(array_diff($after, $before));
        $taskFiles = array_values(array_filter($created, static fn (string $path): bool => str_contains($path, '/task_')));

        $this->assertCount(1, $taskFiles, 'the status route executes the queue writer');
        $this->assertSame(
            (string) data_get($payload, 'agent_control_plane_task_queue_orchestrator_status.task_packet_id'),
            (string) data_get($payload, 'agent_control_plane_task_queue_orchestrator.task_packet.task_packet_id'),
        );
    }
}
