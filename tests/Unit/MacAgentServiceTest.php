<?php

namespace Tests\Unit;

use App\Http\Controllers\Mobile\MobileMacAgentController;
use App\Models\AtlasHostStatus;
use App\Models\AtlasMaintenanceWindow;
use App\Models\AtlasPowerEvent;
use App\Models\AtlasPowerSession;
use App\Services\MacAgent\MacAgentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class MacAgentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropMacAgentTables();
    }

    protected function tearDown(): void
    {
        $this->dropMacAgentTables();

        parent::tearDown();
    }

    public function test_status_without_tables_is_provider_safe_and_actionable(): void
    {
        $status = app(MacAgentService::class)->status(refresh: false);

        $this->assertSame(MacAgentService::HOST_KEY, $status['host_key']);
        $this->assertSame('not_installed', $status['status']);
        $this->assertNull($status['host']);
        $this->assertSame([], $status['active_sessions']);
        $this->assertArrayHasKey('mac_agent', $status);
        $this->assertArrayHasKey('power_helper', $status);
        $this->assertArrayHasKey('caffeinate_runtime', $status);
        $this->assertArrayHasKey('wake_schedule', $status);
        $this->assertArrayHasKey('readiness', $status);
        $this->assertArrayHasKey('generated_at', $status);

        $this->assertArrayHasKey('installed', $status['mac_agent']);
        $this->assertArrayHasKey('installed', $status['power_helper']);
        $this->assertArrayHasKey('needs_install', $status['power_helper']);
        $this->assertArrayHasKey('scheduled', $status['wake_schedule']);
        $this->assertArrayHasKey('atlas_confirmed', $status['wake_schedule']);
        $this->assertArrayHasKey('system_has_wakeorpoweron', $status['wake_schedule']);
        $this->assertArrayHasKey('orphan_count', $status['caffeinate_runtime']);
        $this->assertArrayHasKey('ready_for_remote', $status['readiness']);
        $this->assertArrayHasKey('ready_for_scheduled_wake', $status['readiness']);
        $this->assertArrayHasKey('ready_for_background_jobs', $status['readiness']);
        $this->assertArrayHasKey('power_ready_for_background_jobs', $status['readiness']);
        $this->assertArrayHasKey('summary', $status['readiness']);
        $this->assertArrayHasKey('next_action', $status['readiness']);
    }

    public function test_power_helper_status_has_stable_shape(): void
    {
        $status = app(MacAgentService::class)->powerHelperStatus();

        $this->assertSame(MacAgentService::POWER_HELPER_LABEL, $status['label']);
        $this->assertSame(MacAgentService::POWER_HELPER_PLIST, $status['plist']);
        $this->assertArrayHasKey('installed', $status);
        $this->assertArrayHasKey('running', $status);
        $this->assertArrayHasKey('ready', $status);
        $this->assertArrayHasKey('needs_install', $status);
        $this->assertArrayHasKey('last_success_fresh', $status);
        $this->assertArrayHasKey('stale_after_minutes', $status);
        $this->assertArrayHasKey('next_action', $status);
        $this->assertArrayHasKey('admin_password_required', $status);
        $this->assertArrayHasKey('last_error', $status);
    }

    public function test_power_helper_success_must_be_recent_to_be_fresh(): void
    {
        $this->createMacAgentTables();
        AtlasPowerEvent::query()->create([
            'host_key' => MacAgentService::HOST_KEY,
            'event_type' => 'power_helper_check_succeeded',
            'severity' => 'info',
            'message' => 'Old root check.',
            'metadata' => ['running_as_root' => true],
            'occurred_at' => now()->subHour(),
        ]);

        $status = app(MacAgentService::class)->powerHelperStatus();

        $this->assertFalse($status['last_success_fresh']);
        if (($status['installed'] ?? false) === true && ($status['loaded'] ?? true) !== false) {
            $this->assertSame('power_helper_check_stale', $status['next_action']['code']);
        }
    }

    public function test_mac_agent_supervisor_status_has_stable_shape(): void
    {
        $status = app(MacAgentService::class)->macAgentSupervisorStatus();

        $this->assertSame(MacAgentService::MAC_AGENT_LABEL, $status['label']);
        $this->assertArrayHasKey('installed', $status);
        $this->assertArrayHasKey('loaded', $status);
        $this->assertArrayHasKey('running', $status);
        $this->assertArrayHasKey('ready', $status);
        $this->assertArrayHasKey('needs_install', $status);
        $this->assertArrayHasKey('next_action', $status);
        $this->assertArrayHasKey('install_command', $status);
        $this->assertArrayHasKey('uninstall_command', $status);
    }

    public function test_session_payload_reports_caffeinate_liveness(): void
    {
        $session = new AtlasPowerSession([
            'id' => 'session-test',
            'host_key' => MacAgentService::HOST_KEY,
            'kind' => 'remote_manual',
            'status' => 'active',
            'reason' => 'test',
            'caffeinate_pid' => null,
            'metadata' => [],
        ]);

        $payload = app(MacAgentService::class)->sessionPayload($session);

        $this->assertArrayHasKey('caffeinate_pid', $payload);
        $this->assertArrayHasKey('caffeinate_label', $payload);
        $this->assertArrayHasKey('caffeinate_alive', $payload);
        $this->assertNull($payload['caffeinate_label']);
        $this->assertNull($payload['caffeinate_alive']);
    }

    public function test_caffeinate_runtime_status_has_stable_shape(): void
    {
        $status = app(MacAgentService::class)->caffeinateRuntimeStatus();

        $this->assertArrayHasKey('available', $status);
        $this->assertArrayHasKey('method', $status);
        $this->assertArrayHasKey('active_labels', $status);
        $this->assertArrayHasKey('launchctl_labels', $status);
        $this->assertArrayHasKey('orphan_labels', $status);
        $this->assertArrayHasKey('orphan_count', $status);
        $this->assertArrayHasKey('cleanup_command', $status);
    }

    public function test_wake_schedule_status_has_stable_shape(): void
    {
        $status = app(MacAgentService::class)->wakeScheduleStatus();

        $this->assertArrayHasKey('available', $status);
        $this->assertArrayHasKey('scheduled', $status);
        $this->assertArrayHasKey('atlas_confirmed', $status);
        $this->assertArrayHasKey('system_has_wakeorpoweron', $status);
        $this->assertArrayHasKey('raw', $status);
        $this->assertArrayHasKey('next_wake_at', $status);
        $this->assertSame($status['atlas_confirmed'], $status['scheduled']);
    }

    public function test_readiness_status_has_stable_shape(): void
    {
        $service = app(MacAgentService::class);
        $readiness = $service->readinessStatus(
            'online_idle',
            null,
            0,
            ['ready' => true],
            ['ready' => false, 'next_action' => ['message' => 'Install helper', 'command' => 'install']],
            ['available' => true, 'orphan_count' => 0, 'cleanup_command' => 'cleanup'],
            ['atlas_confirmed' => false, 'system_has_wakeorpoweron' => false],
        );

        $this->assertArrayHasKey('overall', $readiness);
        $this->assertArrayHasKey('summary', $readiness);
        $this->assertArrayHasKey('ready_for_remote', $readiness);
        $this->assertArrayHasKey('ready_for_scheduled_wake', $readiness);
        $this->assertArrayHasKey('ready_for_background_jobs', $readiness);
        $this->assertArrayHasKey('power_ready_for_background_jobs', $readiness);
        $this->assertArrayHasKey('on_ac_power', $readiness);
        $this->assertArrayHasKey('battery_percent', $readiness);
        $this->assertArrayHasKey('mac_agent_ready', $readiness);
        $this->assertArrayHasKey('next_action', $readiness);
        $this->assertArrayHasKey('blockers', $readiness);
        $this->assertArrayHasKey('warnings', $readiness);
        $this->assertTrue($readiness['ready_for_remote']);
        $this->assertFalse($readiness['ready_for_scheduled_wake']);
        $this->assertFalse($readiness['ready_for_background_jobs']);
        $this->assertTrue($readiness['power_ready_for_background_jobs']);
        $this->assertSame('remote_ready', $readiness['summary']['mode']);
        $this->assertSame('power_helper_not_ready', $readiness['next_action']['code']);
        $this->assertSame('background_setup', $readiness['next_action']['kind']);
    }

    public function test_readiness_blocks_background_jobs_on_low_battery_without_blocking_remote(): void
    {
        $service = app(MacAgentService::class);
        $host = new AtlasHostStatus([
            'on_ac_power' => false,
            'battery_percent' => 25,
            'active_ai_jobs' => 0,
        ]);

        $readiness = $service->readinessStatus(
            'online_idle',
            $host,
            0,
            ['ready' => true],
            ['ready' => true],
            ['available' => true, 'orphan_count' => 0, 'cleanup_command' => 'cleanup'],
            ['atlas_confirmed' => true, 'system_has_wakeorpoweron' => true],
        );

        $this->assertTrue($readiness['ready_for_remote']);
        $this->assertTrue($readiness['ready_for_scheduled_wake']);
        $this->assertFalse($readiness['power_ready_for_background_jobs']);
        $this->assertFalse($readiness['ready_for_background_jobs']);
        $this->assertSame('battery_too_low_for_background_jobs', $readiness['next_action']['code']);
        $this->assertSame(false, $readiness['on_ac_power']);
        $this->assertSame(25, $readiness['battery_percent']);
        $this->assertContains(
            'battery_too_low_for_background_jobs',
            array_column($readiness['blockers'], 'code'),
        );
    }

    public function test_safe_bootstrap_returns_stable_operational_contract(): void
    {
        $this->createMacAgentTables();

        $bootstrap = app(MacAgentService::class)->safeBootstrap([
            'name' => 'Janela Atlas Teste',
            'wake_time' => '02:00',
            'duration_minutes' => 120,
            'timezone' => 'America/Sao_Paulo',
        ]);

        $this->assertArrayHasKey('ok', $bootstrap);
        $this->assertArrayHasKey('complete', $bootstrap);
        $this->assertArrayHasKey('steps', $bootstrap);
        $this->assertArrayHasKey('next_actions', $bootstrap);
        $this->assertArrayHasKey('before_readiness', $bootstrap);
        $this->assertArrayHasKey('status', $bootstrap);
        $this->assertArrayHasKey('readiness', $bootstrap['status']);
        $this->assertContains('mac_agent_launch_agent', array_column($bootstrap['steps'], 'name'));
        $this->assertContains('caffeinate_cleanup', array_column($bootstrap['steps'], 'name'));
        $this->assertContains('maintenance_window', array_column($bootstrap['steps'], 'name'));
        $this->assertContains('power_helper_launch_daemon', array_column($bootstrap['steps'], 'name'));
        $this->assertTrue(Schema::hasTable('atlas_maintenance_windows'));
        $this->assertDatabaseHas('atlas_maintenance_windows', [
            'host_key' => MacAgentService::HOST_KEY,
            'name' => 'Janela Atlas Teste',
            'wake_time' => '02:00',
        ]);
    }

    public function test_mobile_remote_session_start_renews_existing_device_session(): void
    {
        $this->createMacAgentTables();
        $deviceId = (string) Str::uuid();
        $session = AtlasPowerSession::query()->create([
            'host_key' => MacAgentService::HOST_KEY,
            'kind' => 'remote_manual',
            'status' => 'active',
            'reason' => 'Modo remoto antigo',
            'source' => 'mobile_remote_mode',
            'created_by_device_id' => $deviceId,
            'started_at' => now()->subMinutes(10),
            'expires_at' => now()->addMinutes(60),
            'metadata' => ['duration_minutes' => 60],
        ]);

        $agent = $this->mock(MacAgentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('event')
                ->once()
                ->with('power_session_renewed', 'info', 'Power session renewed from mobile.', \Mockery::type('array'), \Mockery::type(AtlasPowerSession::class))
                ->andReturn(new AtlasPowerEvent);
            $mock->shouldReceive('sessionPayload')
                ->once()
                ->andReturnUsing(fn (AtlasPowerSession $renewed): array => ['id' => $renewed->id, 'expires_at' => $renewed->expires_at?->toJSON()]);
            $mock->shouldReceive('status')
                ->once()
                ->with(true)
                ->andReturn(['status' => 'held_awake']);
            $mock->shouldNotReceive('startSession');
        });

        $request = Request::create('/v1/mobile/mac/remote-session', 'POST', [
            'duration_minutes' => 240,
            'reason' => 'Modo remoto na rua',
        ]);
        $request->attributes->set('atlas_mobile_device', (object) [
            'id' => $deviceId,
            'device_label' => 'iPhone Vitor',
        ]);

        $response = app(MobileMacAgentController::class)->startRemoteSession($request, $agent);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['idempotent']);
        $this->assertSame($session->id, $payload['session']['id']);
        $this->assertDatabaseCount('atlas_power_sessions', 1);
        $this->assertDatabaseHas('atlas_power_sessions', [
            'id' => $session->id,
            'reason' => 'Modo remoto na rua',
            'status' => 'active',
        ]);
        $this->assertSame(240, AtlasPowerSession::query()->findOrFail($session->id)->metadata['duration_minutes']);
    }

    public function test_mobile_status_only_returns_enabled_maintenance_windows(): void
    {
        $this->createMacAgentTables();
        AtlasMaintenanceWindow::query()->create([
            'host_key' => MacAgentService::HOST_KEY,
            'name' => 'Janela Atlas',
            'enabled' => true,
            'timezone' => 'America/Sao_Paulo',
            'wake_time' => '02:00',
            'duration_minutes' => 120,
            'days_of_week' => [],
            'metadata' => ['pmset_ok' => true],
        ]);
        AtlasMaintenanceWindow::query()->create([
            'host_key' => MacAgentService::HOST_KEY,
            'name' => 'Manutencao Atlas duplicada',
            'enabled' => false,
            'timezone' => 'America/Sao_Paulo',
            'wake_time' => '02:00',
            'duration_minutes' => 120,
            'days_of_week' => [],
            'metadata' => ['pmset_ok' => true],
        ]);

        $agent = $this->mock(MacAgentService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('status')
                ->once()
                ->with(true)
                ->andReturn(['status' => 'online_idle']);
            $mock->shouldReceive('recentEvents')
                ->once()
                ->with(12)
                ->andReturn([]);
        });

        $response = app(MobileMacAgentController::class)->status($agent);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $payload['maintenance_windows']);
        $this->assertSame('Janela Atlas', $payload['maintenance_windows'][0]['name']);
    }

    public function test_non_darwin_status_uses_host_runtime_snapshot_for_mobile_readiness(): void
    {
        $this->createMacAgentTables();
        AtlasHostStatus::query()->create([
            'host_key' => MacAgentService::HOST_KEY,
            'hostname' => 'Vitors-MacBook-Pro.local',
            'status' => 'online',
            'agent_available' => true,
            'caffeinate_available' => true,
            'pmset_available' => true,
            'on_ac_power' => true,
            'battery_percent' => 80,
            'active_power_sessions' => 1,
            'active_ai_jobs' => 0,
            'last_seen_at' => now(),
            'metadata' => [
                'runtime' => [
                    'mac_agent' => ['ready' => true, 'installed' => true],
                    'power_helper' => ['ready' => true, 'installed' => true],
                    'caffeinate_runtime' => [
                        'available' => true,
                        'active_labels' => ['com.atlas.caffeinate.remote'],
                        'launchctl_labels' => ['com.atlas.caffeinate.remote'],
                        'orphan_labels' => [],
                        'orphan_count' => 0,
                        'cleanup_command' => '/opt/homebrew/bin/php artisan atlas:host cleanup-caffeinate --json',
                    ],
                    'wake_schedule' => [
                        'available' => true,
                        'scheduled' => true,
                        'atlas_confirmed' => true,
                        'system_has_wakeorpoweron' => true,
                        'next_wake_at' => now()->addDay()->toJSON(),
                    ],
                ],
            ],
        ]);
        AtlasPowerSession::query()->create([
            'host_key' => MacAgentService::HOST_KEY,
            'kind' => 'remote_manual',
            'status' => 'active',
            'reason' => 'Modo remoto',
            'source' => 'mobile_remote_mode',
            'caffeinate_pid' => null,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'metadata' => [
                'caffeinate' => [
                    'label' => 'com.atlas.caffeinate.remote',
                    'method' => 'launchctl_submit',
                ],
            ],
        ]);

        $status = app(NonDarwinMacAgentServiceForTest::class)->status(refresh: true);

        $this->assertSame('held_awake', $status['status']);
        $this->assertTrue($status['readiness']['ready_for_remote']);
        $this->assertTrue($status['readiness']['ready_for_scheduled_wake']);
        $this->assertTrue($status['readiness']['ready_for_background_jobs']);
        $this->assertTrue($status['active_sessions'][0]['caffeinate_alive']);
        $this->assertSame('none', $status['readiness']['next_action']['code']);
    }

    public function test_non_darwin_cleanup_request_is_queued_for_host_agent(): void
    {
        $this->createMacAgentTables();
        AtlasHostStatus::query()->create([
            'host_key' => MacAgentService::HOST_KEY,
            'hostname' => 'docker-backend',
            'status' => 'online',
            'last_seen_at' => now(),
            'metadata' => [
                'runtime' => [
                    'caffeinate_runtime' => [
                        'launchctl_labels' => ['com.atlas.caffeinate.active', 'com.atlas.caffeinate.orphan'],
                        'orphan_labels' => ['com.atlas.caffeinate.orphan'],
                    ],
                ],
            ],
        ]);

        $cleanup = app(NonDarwinMacAgentServiceForTest::class)->cleanupOrphanCaffeinateJobs();

        $this->assertSame(2, $cleanup['checked']);
        $this->assertSame(0, $cleanup['removed']);
        $this->assertTrue($cleanup['queued_for_host']);
        $this->assertSame(['com.atlas.caffeinate.orphan'], $cleanup['labels']);
        $this->assertDatabaseHas('atlas_power_events', [
            'event_type' => 'caffeinate_cleanup_requested_pending_host',
            'severity' => 'info',
        ]);
    }

    private function createMacAgentTables(): void
    {
        Schema::create('atlas_host_status', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('host_key')->unique();
            $table->string('hostname')->nullable();
            $table->string('status')->default('unknown');
            $table->boolean('agent_available')->default(false);
            $table->boolean('caffeinate_available')->default(false);
            $table->boolean('pmset_available')->default(false);
            $table->boolean('docker_available')->default(false);
            $table->boolean('on_ac_power')->nullable();
            $table->unsignedSmallInteger('battery_percent')->nullable();
            $table->unsignedInteger('active_power_sessions')->default(0);
            $table->unsignedInteger('active_ai_jobs')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_power_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('host_key')->index();
            $table->string('kind')->index();
            $table->string('status')->default('active')->index();
            $table->string('reason')->nullable();
            $table->string('source')->nullable();
            $table->uuid('created_by_device_id')->nullable();
            $table->uuid('ai_job_id')->nullable();
            $table->unsignedInteger('caffeinate_pid')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_maintenance_windows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('host_key')->index();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->string('timezone')->default('America/Sao_Paulo');
            $table->string('wake_time', 5);
            $table->unsignedSmallInteger('duration_minutes')->default(120);
            $table->json('days_of_week')->nullable();
            $table->timestamp('last_scheduled_at')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_power_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('host_key')->index();
            $table->uuid('power_session_id')->nullable();
            $table->uuid('ai_job_id')->nullable();
            $table->string('event_type');
            $table->string('severity')->default('info');
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    private function dropMacAgentTables(): void
    {
        foreach (['atlas_power_events', 'atlas_maintenance_windows', 'atlas_power_sessions', 'atlas_host_status'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}

class NonDarwinMacAgentServiceForTest extends MacAgentService
{
    protected function isDarwinRuntime(): bool
    {
        return false;
    }
}
