<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use RuntimeException;
use Tests\TestCase;

class AtlasTaskServingDiskGuardTest extends TestCase
{
    public function test_dedicated_disk_passes_assert_and_reports_ok(): void
    {
        config()->set('atlas.task_serving.queue_disk', 'atlas_serving');
        AtlasTaskServingStack::assertServingDiskConfigured();
        $health = AtlasTaskServingStack::servingDiskHealth();
        self::assertTrue($health['ok']);
        self::assertSame('atlas_serving', $health['disk']);
    }

    public function test_unset_disk_throws_and_reports_not_ok(): void
    {
        config()->set('atlas.task_serving.queue_disk', null);
        $health = AtlasTaskServingStack::servingDiskHealth();
        self::assertFalse($health['ok']);
        self::assertSame('serving_disk_unset', $health['reason']);

        $this->expectException(RuntimeException::class);
        AtlasTaskServingStack::assertServingDiskConfigured();
    }

    public function test_empty_disk_throws_and_reports_not_ok(): void
    {
        config()->set('atlas.task_serving.queue_disk', '');
        $health = AtlasTaskServingStack::servingDiskHealth();
        self::assertFalse($health['ok']);
        self::assertSame('serving_disk_unset', $health['reason']);

        $this->expectException(RuntimeException::class);
        AtlasTaskServingStack::assertServingDiskConfigured();
    }

    public function test_default_local_disk_is_refused(): void
    {
        config()->set('atlas.task_serving.queue_disk', 'local');
        $health = AtlasTaskServingStack::servingDiskHealth();
        self::assertFalse($health['ok']);
        self::assertSame('serving_disk_default_local_forbidden', $health['reason']);

        $this->expectException(RuntimeException::class);
        AtlasTaskServingStack::assertServingDiskConfigured();
    }

    public function test_disk_method_behavior_unchanged_with_dedicated_disk(): void
    {
        config()->set('atlas.task_serving.queue_disk', 'atlas_serving');
        $disk = AtlasTaskServingStack::disk();
        self::assertSame('atlas_serving', $disk);
        // The dedicated disk is now registered in filesystems.disks.
        $registered = config('filesystems.disks.atlas_serving');
        self::assertIsArray($registered);
        self::assertSame('local', $registered['driver']);
    }

    public function test_assert_exception_message_names_the_misconfiguration(): void
    {
        config()->set('atlas.task_serving.queue_disk', 'local');
        try {
            AtlasTaskServingStack::assertServingDiskConfigured();
            self::fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('atlas_task_serving_disk_misconfigured', $e->getMessage());
            self::assertStringContainsString('local', $e->getMessage());
            self::assertStringContainsString('ATLAS_TASK_SERVING_QUEUE_DISK', $e->getMessage());
        }
    }
}
