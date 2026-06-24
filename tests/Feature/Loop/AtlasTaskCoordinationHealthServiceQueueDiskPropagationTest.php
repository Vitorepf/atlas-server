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
