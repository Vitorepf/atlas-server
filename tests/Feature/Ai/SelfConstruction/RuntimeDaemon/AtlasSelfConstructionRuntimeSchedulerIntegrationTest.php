<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction\RuntimeDaemon;

use Tests\TestCase;

final class AtlasSelfConstructionRuntimeSchedulerIntegrationTest extends TestCase
{
    public function test_p1a_does_not_register_a_runtime_daemon_scheduler_or_schedule_flag(): void
    {
        $routes = (string) file_get_contents(base_path('routes/console.php'));
        $config = (string) file_get_contents(base_path('config/atlas_dev.php'));

        self::assertStringNotContainsString('atlas:self-construction:runtime-daemon plan', $routes);
        self::assertStringNotContainsString('runtime_daemon_schedule_enabled', $config);
        self::assertStringNotContainsString('ATLAS_AAEOS_RUNTIME_DAEMON_SCHEDULE_ENABLED', $config);
    }
}
