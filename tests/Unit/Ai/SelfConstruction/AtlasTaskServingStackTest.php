<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Tests\TestCase;

/**
 * Proves AtlasTaskServingStack rejects a serving queue disk NAME that
 * contains '/', backslash, or '..' with reason 'serving_disk_name_unsafe',
 * making assertServingDiskConfigured() throw instead of silently registering
 * a disk whose root escapes storage/app/atlas/task-serving.
 */
final class AtlasTaskServingStackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.task_serving.queue_disk', '');
    }

    public function test_serving_disk_unset_has_ok_false(): void
    {
        $health = AtlasTaskServingStack::servingDiskHealth();

        $this->assertFalse($health['ok']);
        $this->assertSame('serving_disk_unset', $health['reason']);
    }

    public function test_serving_disk_default_local_has_ok_false(): void
    {
        config()->set('atlas.task_serving.queue_disk', 'local');
        $health = AtlasTaskServingStack::servingDiskHealth();

        $this->assertFalse($health['ok']);
        $this->assertSame('serving_disk_default_local_forbidden', $health['reason']);
    }

    public function test_unsafe_disk_with_slash_has_ok_false(): void
    {
        config()->set('atlas.task_serving.queue_disk', '../../etc');
        $health = AtlasTaskServingStack::servingDiskHealth();

        $this->assertFalse($health['ok']);
        $this->assertSame('serving_disk_name_unsafe', $health['reason']);
    }

    public function test_unsafe_disk_with_backslash_has_ok_false(): void
    {
        config()->set('atlas.task_serving.queue_disk', 'sub\\escape');
        $health = AtlasTaskServingStack::servingDiskHealth();

        $this->assertFalse($health['ok']);
        $this->assertSame('serving_disk_name_unsafe', $health['reason']);
    }

    public function test_unsafe_disk_with_double_dot_has_ok_false(): void
    {
        config()->set('atlas.task_serving.queue_disk', 'foo..bar/hack');
        $health = AtlasTaskServingStack::servingDiskHealth();

        $this->assertFalse($health['ok']);
        $this->assertSame('serving_disk_name_unsafe', $health['reason']);
    }

    public function test_safe_dedicated_disk_has_ok_true(): void
    {
        config()->set('atlas.task_serving.queue_disk', 'atlas_serving');
        $health = AtlasTaskServingStack::servingDiskHealth();

        $this->assertTrue($health['ok']);
        $this->assertSame('dedicated_disk_configured', $health['reason']);
    }

    public function test_assert_serving_disk_configured_throws_for_unsafe_name(): void
    {
        config()->set('atlas.task_serving.queue_disk', '../../etc');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('atlas_task_serving_disk_misconfigured');
        AtlasTaskServingStack::assertServingDiskConfigured();
    }

    public function test_assert_serving_disk_configured_passes_for_safe_name(): void
    {
        config()->set('atlas.task_serving.queue_disk', 'atlas_serving');

        // Should not throw.
        AtlasTaskServingStack::assertServingDiskConfigured();
        $this->assertTrue(true);
    }

    public function test_serving_service_injects_elite_kernel_and_context_runtime(): void
    {
        config()->set('atlas.task_serving.queue_disk', 'atlas_serving');

        $service = AtlasTaskServingStack::servingService();
        $ref = new \ReflectionClass($service);

        $kernelProp = $ref->getProperty('eliteKernel');
        $kernelProp->setAccessible(true);
        $runtimeProp = $ref->getProperty('contextRuntime');
        $runtimeProp->setAccessible(true);

        $this->assertInstanceOf(
            \App\Services\Ai\EngineeringKernel\EliteExecutorKernel::class,
            $kernelProp->getValue($service),
        );
        $this->assertInstanceOf(
            \App\Services\Ai\Context\AtlasContextRuntime::class,
            $runtimeProp->getValue($service),
        );
    }
}
