<?php

namespace Tests\Unit;

use App\Models\AtlasPowerSession;
use App\Services\MacAgent\MacAgentService;
use Tests\TestCase;

class MacAgentServiceTest extends TestCase
{
    public function test_status_without_tables_is_provider_safe_and_actionable(): void
    {
        $status = app(MacAgentService::class)->status(refresh: false);

        $this->assertSame(MacAgentService::HOST_KEY, $status['host_key']);
        $this->assertSame('not_installed', $status['status']);
        $this->assertNull($status['host']);
        $this->assertSame([], $status['active_sessions']);
        $this->assertArrayHasKey('power_helper', $status);
        $this->assertArrayHasKey('wake_schedule', $status);
        $this->assertArrayHasKey('generated_at', $status);

        $this->assertArrayHasKey('installed', $status['power_helper']);
        $this->assertArrayHasKey('needs_install', $status['power_helper']);
        $this->assertArrayHasKey('scheduled', $status['wake_schedule']);
    }

    public function test_power_helper_status_has_stable_shape(): void
    {
        $status = app(MacAgentService::class)->powerHelperStatus();

        $this->assertSame(MacAgentService::POWER_HELPER_LABEL, $status['label']);
        $this->assertSame(MacAgentService::POWER_HELPER_PLIST, $status['plist']);
        $this->assertArrayHasKey('installed', $status);
        $this->assertArrayHasKey('running', $status);
        $this->assertArrayHasKey('needs_install', $status);
        $this->assertArrayHasKey('last_error', $status);
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
}
