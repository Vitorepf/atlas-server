<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasTaskCoordinationHealthServiceDiskMismatchFlagTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('serving_alt');
        Config::set('atlas.task_serving.queue_disk', 'serving_alt');
    }

    public function test_default_health_service_threads_configured_disk_and_stays_healthy(): void
    {
        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertArrayHasKey('queue_disk_mismatch_detected', $snapshot['health_flags']);
        $this->assertFalse($snapshot['health_flags']['queue_disk_mismatch_detected']);
        $this->assertTrue($snapshot['healthy']);
    }

    public function test_injected_default_queue_under_non_default_env_flags_mismatch_as_unhealthy(): void
    {
        $snapshot = (new AtlasTaskCoordinationHealthService(
            new AgentControlPlaneTaskPacketQueueRepository('local'),
        ))->snapshot();

        $this->assertTrue($snapshot['health_flags']['queue_disk_mismatch_detected']);
        $this->assertFalse($snapshot['healthy']);
    }
}
