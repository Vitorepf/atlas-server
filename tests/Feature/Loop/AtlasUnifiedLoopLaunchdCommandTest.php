<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasUnifiedLoopLaunchdCommandTest extends TestCase
{
    public function test_dry_run_renders_keepalive_plist_for_existing_run_id(): void
    {
        $exit = Artisan::call('atlas:loop:unified:install-launchd', [
            '--dry-run' => true,
            '--json' => true,
            '--run' => 'run-test-123',
            '--modes' => 'deadcode',
            '--provider' => 'hermes_cli',
            '--max-seconds' => 123,
            '--label' => 'com.atlas.unified-loop.test',
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('install', $payload['action']);
        $this->assertSame('run-test-123', $payload['run_id']);
        $this->assertSame('provided', $payload['run_id_source']);
        $this->assertSame('deadcode', $payload['modes']);
        $this->assertSame('hermes_cli', $payload['provider']);
        $this->assertSame(123, $payload['max_seconds']);
        $this->assertFalse($payload['loaded']);
        $this->assertFalse($payload['claim_policy']['mutates_launchd']);
        $this->assertTrue($payload['claim_policy']['launchd_keepalive']);
        $this->assertStringContainsString('<key>KeepAlive</key>', (string) $payload['plist']);
        $this->assertStringContainsString('<true/>', (string) $payload['plist']);
        $this->assertStringContainsString('<string>--run-id=run-test-123</string>', (string) $payload['plist']);
        $this->assertStringContainsString('<string>--max-seconds=123</string>', (string) $payload['plist']);
        $this->assertStringContainsString('<string>--provider=hermes_cli</string>', (string) $payload['plist']);
    }

    public function test_uninstall_dry_run_does_not_remove_launchd_agent(): void
    {
        $exit = Artisan::call('atlas:loop:unified:install-launchd', [
            '--uninstall' => true,
            '--dry-run' => true,
            '--json' => true,
            '--label' => 'com.atlas.unified-loop.test',
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('uninstall', $payload['action']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['unloaded']);
        $this->assertFalse($payload['removed']);
    }
}
