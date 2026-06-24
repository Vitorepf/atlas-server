<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Sentinels\AtlasLoopServingQueueDiskConformanceSentinel;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService;
use Tests\TestCase;

/**
 * Regression net for the serving-queue-disk drift: health must inspect the SAME disk the serving CLI uses,
 * even when the health service is built with no injected queue.
 */
final class AtlasLoopServingQueueDiskConformanceSentinelTest extends TestCase
{
    private const TEST_DISK = 'atlas_task_serving_test';

    public function test_live_default_health_service_reads_the_configured_serving_disk(): void
    {
        config()->set('atlas.task_serving.queue_disk', self::TEST_DISK);

        // The LIVE default health service — no injected queue dependency.
        $facts = (new AtlasLoopServingQueueDiskConformanceSentinel)->check(new AtlasTaskCoordinationHealthService);

        $this->assertTrue(
            $facts['conformant'],
            'health default queueRepo() must resolve the configured serving disk, not a hard-coded fallback'
        );
        $this->assertSame(self::TEST_DISK, $facts['env_disk']);
        $this->assertSame(self::TEST_DISK, $facts['resolved_disk']);
        $this->assertArrayNotHasKey('drift', $facts);
    }

    public function test_sentinel_detects_a_health_service_pinned_to_the_wrong_disk(): void
    {
        config()->set('atlas.task_serving.queue_disk', self::TEST_DISK);

        // A health service whose resolved queueRepo() is pinned to a DIFFERENT disk than the configured one —
        // exactly the drift this sentinel exists to catch. (AtlasTaskCoordinationHealthService is final, so the
        // wrong disk is supplied through the SAME queue dependency queueRepo() returns, rather than a subclass.)
        $health = new AtlasTaskCoordinationHealthService(
            new AgentControlPlaneTaskPacketQueueRepository('some_other_disk')
        );

        $facts = (new AtlasLoopServingQueueDiskConformanceSentinel)->check($health);

        $this->assertFalse($facts['conformant']);
        $this->assertSame(self::TEST_DISK, $facts['env_disk']);
        $this->assertSame('some_other_disk', $facts['resolved_disk']);
        $this->assertSame(self::TEST_DISK, $facts['drift']['expected_disk']);
        $this->assertSame('some_other_disk', $facts['drift']['resolved_disk']);
    }
}
