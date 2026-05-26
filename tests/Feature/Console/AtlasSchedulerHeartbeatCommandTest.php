<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Ai\Patamar4\AtlasSchedulerHealthService;
use Tests\TestCase;

class AtlasSchedulerHeartbeatCommandTest extends TestCase
{
    public function test_heartbeat_command_records_and_returns_json(): void
    {
        $log = sys_get_temp_dir().'/atlas_scheduler_cmd_'.uniqid('', true).'.jsonl';
        $svc = new AtlasSchedulerHealthService;
        $svc->setLogPathForTesting($log);
        $this->app->instance(AtlasSchedulerHealthService::class, $svc);

        $this->artisan('atlas:scheduler:heartbeat', ['--json' => true])
            ->assertExitCode(0);

        $this->assertFileExists($log);
        $contents = file_get_contents($log);
        $this->assertNotFalse($contents);
        $this->assertStringContainsString('atlas.scheduler.heartbeat.v1', $contents);
        @unlink($log);
    }

    public function test_status_command_silent_then_alive(): void
    {
        $log = sys_get_temp_dir().'/atlas_scheduler_cmd2_'.uniqid('', true).'.jsonl';
        $svc = new AtlasSchedulerHealthService;
        $svc->setLogPathForTesting($log);
        $this->app->instance(AtlasSchedulerHealthService::class, $svc);

        $this->artisan('atlas:scheduler:status', ['--json' => true, '--threshold' => 300])
            ->assertExitCode(0);

        $svc->recordHeartbeat();

        $this->artisan('atlas:scheduler:status', ['--json' => true, '--threshold' => 300])
            ->assertExitCode(0);
        @unlink($log);
    }

    public function test_status_strict_returns_3_when_silent(): void
    {
        $log = sys_get_temp_dir().'/atlas_scheduler_strict_'.uniqid('', true).'.jsonl';
        $svc = new AtlasSchedulerHealthService;
        $svc->setLogPathForTesting($log);
        $this->app->instance(AtlasSchedulerHealthService::class, $svc);

        $this->artisan('atlas:scheduler:status', ['--json' => true, '--strict' => true])
            ->assertExitCode(3);
        @unlink($log);
    }

    public function test_install_launchd_dry_run_renders_plist(): void
    {
        $exit = $this->artisan('atlas:scheduler:install-launchd', ['--dry-run' => true, '--json' => true])
            ->assertExitCode(0)
            ->run();
        $this->assertSame(0, $exit);
    }
}
