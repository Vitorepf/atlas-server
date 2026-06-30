<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasTaskCoordinationHealthServiceQueueDiskPropagationTest extends TestCase
{
    private const SERVING_DISK = 'atlas-health-serving-test';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake(self::SERVING_DISK);
        Config::set('atlas.task_serving.queue_disk', self::SERVING_DISK);
    }

    public function test_snapshot_reads_queue_distribution_from_non_default_serving_disk(): void
    {
        $servingQueue = new AgentControlPlaneTaskPacketQueueRepository(self::SERVING_DISK);
        $defaultQueue = new AgentControlPlaneTaskPacketQueueRepository('local');

        $servingQueue->enqueue($this->packet('blocked-on-serving-disk', 'blocked'));
        $defaultQueue->enqueue($this->packet('claimable-on-default-disk', 'claimable'));

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertSame(1, $snapshot['queue_status_distribution']['blocked']);
        $this->assertSame(0, $snapshot['queue_status_distribution']['claimable']);
        $this->assertSame(1, $snapshot['quarantined_count']);
    }

    public function test_recoverability_cascade_reads_the_same_non_default_queue_disk(): void
    {
        $servingQueue = new AgentControlPlaneTaskPacketQueueRepository(self::SERVING_DISK);
        $defaultQueue = new AgentControlPlaneTaskPacketQueueRepository('local');

        $servingQueue->enqueue($this->packet('recoverable-on-serving-disk', 'released', [
            'metadata' => ['release_reason' => 'worker_give_back'],
        ]));
        $defaultQueue->enqueue($this->packet('recoverable-on-default-disk', 'released', [
            'metadata' => ['release_reason' => 'worker_give_back'],
        ]));

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertSame(1, $snapshot['recoverable']['total']);
        $this->assertSame(
            1,
            $snapshot['recoverable']['by_classification']['recoverable_released_task'],
        );
    }

    // ── worker_drain_forecast ────────────────────────────────────────────────

    public function test_snapshot_includes_worker_drain_forecast_keys(): void
    {
        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertArrayHasKey('worker_drain_forecast', $snapshot);
        $forecast = $snapshot['worker_drain_forecast'];
        $this->assertArrayHasKey('servable_now', $forecast);
        $this->assertArrayHasKey('active_leases', $forecast);
        $this->assertArrayHasKey('claimable_per_active_worker', $forecast);
        $this->assertArrayHasKey('queue_pressure', $forecast);
        $this->assertArrayHasKey('replenish_recommendation', $forecast);
    }

    public function test_zero_active_leases_yields_null_claimable_per_worker(): void
    {
        // No tasks enqueued, no active leases → claimable_per_active_worker = null.
        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $forecast = $snapshot['worker_drain_forecast'];
        $this->assertSame(0, $forecast['active_leases']);
        $this->assertNull($forecast['claimable_per_active_worker']);
    }

    public function test_existing_behavior_preserved_healthy_and_health_flags(): void
    {
        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertArrayHasKey('healthy', $snapshot);
        $this->assertArrayHasKey('health_flags', $snapshot);
        $this->assertArrayHasKey('queue_status_distribution', $snapshot);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function packet(string $id, string $status, array $overrides = []): array
    {
        return array_replace_recursive([
            'task_packet_id' => $id,
            'task_packet_hash' => sha1($id),
            'status' => $status,
            'objective' => 'queue disk propagation '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['test'],
        ], $overrides);
    }
}
