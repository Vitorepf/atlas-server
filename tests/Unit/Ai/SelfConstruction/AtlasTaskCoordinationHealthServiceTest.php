<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskCoordinationHealthService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasTaskCoordinationHealthServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('serving_alt');
    }

    public function test_snapshot_includes_serving_disk_health_with_ok_disk_and_reason(): void
    {
        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertArrayHasKey('serving_disk_health', $snapshot);
        foreach (['ok', 'disk', 'reason'] as $key) {
            $this->assertArrayHasKey($key, $snapshot['serving_disk_health'], "serving_disk_health missing key: {$key}");
        }
    }

    public function test_serving_disk_health_reflects_atlas_task_serving_stack(): void
    {
        Config::set('atlas.task_serving.queue_disk', 'serving_alt');

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertTrue($snapshot['serving_disk_health']['ok']);
        $this->assertSame('serving_alt', $snapshot['serving_disk_health']['disk']);
        $this->assertSame('dedicated_disk_configured', $snapshot['serving_disk_health']['reason']);
    }

    public function test_serving_disk_health_reports_unsafe_when_disk_is_unset(): void
    {
        Config::set('atlas.task_serving.queue_disk', '');

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertFalse($snapshot['serving_disk_health']['ok']);
        $this->assertSame('serving_disk_unset', $snapshot['serving_disk_health']['reason']);
    }

    public function test_serving_disk_health_reports_unsafe_when_disk_is_forbidden_local(): void
    {
        Config::set('atlas.task_serving.queue_disk', 'local');

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertFalse($snapshot['serving_disk_health']['ok']);
        $this->assertSame('serving_disk_default_local_forbidden', $snapshot['serving_disk_health']['reason']);
    }

    public function test_queue_disk_mismatch_detected_behavior_remains_intact(): void
    {
        Config::set('atlas.task_serving.queue_disk', 'serving_alt');

        $snapshot = (new AtlasTaskCoordinationHealthService)->snapshot();

        $this->assertArrayHasKey('queue_disk_mismatch_detected', $snapshot['health_flags']);
        $this->assertFalse($snapshot['health_flags']['queue_disk_mismatch_detected']);
        $this->assertTrue($snapshot['healthy']);
    }
}
