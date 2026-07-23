<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketQueueRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GOD-DEBULK Fase 0 characterization, updated by the Fase 1 slice that fixed
 * A1-SC-0003: these routes DO write durable state (behavior preserved — the
 * live terminal loop depends on it) and the envelope now reports that truth
 * instead of hard-coding a read_only contract.
 */
final class ReadinessStatusMutationCharacterizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_task_queue_orchestrator_status_mutates_and_reports_the_write_truthfully(): void
    {
        $disk = Storage::disk('local');
        $before = $disk->allFiles(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-orchestrator-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['runtime_write_allowed']);
        $this->assertTrue((bool) $payload['runtime_write_performed']);
        $this->assertSame('mutating_agent_control_plane_task_queue_orchestrator_status', $payload['mode']);
        $this->assertFalse((bool) $payload['execution_allowed']);
        $this->assertFalse((bool) $payload['dispatch_allowed']);
        $this->assertFalse((bool) $payload['ledger_write_allowed']);
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

    public function test_task_auto_replenishment_status_mutates_and_reports_the_write_truthfully(): void
    {
        $disk = Storage::disk('local');
        $before = $disk->allFiles(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-auto-replenishment-status' => true,
            '--target-min-claimable-tasks' => 1,
            '--max-new-tasks' => 1,
            '--queue-tag' => ['god_debulk_characterization'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['runtime_write_allowed']);
        $this->assertTrue((bool) $payload['runtime_write_performed']);
        $this->assertSame('mutating_agent_control_plane_task_auto_replenishment_status', $payload['mode']);
        $this->assertGreaterThanOrEqual(1, (int) data_get($payload, 'agent_control_plane_task_auto_replenishment.generated_task_count'));

        $after = $disk->allFiles(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX);
        $created = array_values(array_diff($after, $before));
        $taskFiles = array_values(array_filter($created, static fn (string $path): bool => str_contains($path, '/task_')));

        $this->assertNotEmpty($taskFiles, 'the status route executes queue writers');
        $this->assertGreaterThanOrEqual(1, (int) data_get($payload, 'agent_control_plane_task_auto_replenishment_status.generated_task_count'));
    }

    public function test_terminal_worker_bootstrap_status_mutates_and_reports_the_write_truthfully(): void
    {
        $disk = Storage::disk('local');
        $before = $disk->allFiles(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-terminal-worker-bootstrap-status' => true,
            '--target-min-claimable-tasks' => 1,
            '--max-new-tasks' => 1,
            '--queue-tag' => ['god_debulk_bootstrap_characterization'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['runtime_write_allowed']);
        $this->assertTrue((bool) $payload['runtime_write_performed']);
        $this->assertSame('mutating_agent_control_plane_terminal_worker_bootstrap_status', $payload['mode']);
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap.runtime_claim_persisted'));

        $after = $disk->allFiles(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX);
        $created = array_values(array_diff($after, $before));
        $taskFiles = array_values(array_filter($created, static fn (string $path): bool => str_contains($path, '/task_')));

        $this->assertCount(1, $taskFiles, 'the status route executes queue and claim writers');
        $this->assertNotSame('', (string) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.lease_id'));
    }

    public function test_task_queue_claim_next_status_mutates_and_reports_the_write_truthfully(): void
    {
        $disk = Storage::disk('local');
        $before = $disk->allFiles(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-task-queue-claim-next-status' => true,
            '--actor' => 'god-debulk-claim-characterization',
            '--queue-tag' => ['god_debulk_claim_characterization'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue((bool) $payload['runtime_write_allowed']);
        $this->assertTrue((bool) $payload['runtime_write_performed']);
        $this->assertSame('mutating_agent_control_plane_task_queue_claim_next_status', $payload['mode']);
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_task_queue_claim_next.runtime_claim_persisted'));

        $after = $disk->allFiles(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX);

        $this->assertNotSame($before, $after, 'claim-next persists a claim (and fallback-enqueues when the lane is empty)');
    }

    public function test_preview_bootstrap_status_stays_read_only(): void
    {
        $disk = Storage::disk('local');
        $before = $disk->allFiles(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-terminal-worker-bootstrap-status' => true,
            '--terminal-worker-bootstrap-preview' => true,
            '--target-min-claimable-tasks' => 1,
            '--max-new-tasks' => 1,
            '--queue-tag' => ['god_debulk_preview_characterization'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertFalse((bool) $payload['runtime_write_allowed']);
        $this->assertArrayNotHasKey('runtime_write_performed', $payload);
        $this->assertSame('read_only_agent_control_plane_terminal_worker_bootstrap_status', $payload['mode']);
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap.preview_only'));
        $this->assertSame($before, $disk->allFiles(AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX), 'preview must not write');
    }
}
