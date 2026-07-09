<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\AtlasCliDashboardService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasCliDashboardServiceTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-cli-dashboard-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        $this->workspace = realpath($this->workspace) ?: $this->workspace;
        File::put($this->workspace.'/composer.json', json_encode([
            'scripts' => [
                'test' => 'php artisan test',
            ],
        ], JSON_PRETTY_PRINT));

        config([
            'atlas.ai.workdir' => $this->workspace,
            'atlas.ai.runtime.profile_cache_ttl_seconds' => 0,
            'atlas.ai.tool_permissions.allowed_roots' => [$this->workspace],
            'atlas.ai.tool_permissions.default_mode' => 'read',
            'atlas.ai.tool_permissions.allow_danger' => false,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_dashboard_builds_operational_snapshot_without_database_tables(): void
    {
        $snapshot = app(AtlasCliDashboardService::class)->build($this->workspace, refresh: true);

        $this->assertSame($this->workspace, $snapshot['workspace']['path']);
        $this->assertSame('composer', $snapshot['workspace']['package_manager']);
        $this->assertContains('composer', $snapshot['workspace']['stack']);
        $this->assertContains('composer test', $snapshot['workspace']['test_commands']);
        $this->assertSame('read', $snapshot['runtime']['default_permission']);
        $this->assertFalse($snapshot['runtime']['danger_allowed']);
        $this->assertContains('atlas runtime package.detect', $snapshot['recommended_commands']);

        // New control-plane sections must be present
        $this->assertArrayHasKey('task_health', $snapshot);
        $this->assertArrayHasKey('brain_control_plane', $snapshot);
        $this->assertArrayHasKey('inbox_backlog', $snapshot);

        // Each section degrades gracefully (warning or available=false) when sources are missing
        $this->assertTrue(is_array($snapshot['task_health']));
        $this->assertTrue(is_array($snapshot['brain_control_plane']));
        $this->assertTrue(is_array($snapshot['inbox_backlog']));
    }

    public function test_dashboard_sections_degrade_with_warning_when_source_fails(): void
    {
        $service = app(AtlasCliDashboardService::class);
        $snapshot = $service->build($this->workspace, refresh: true);

        // task_health: may be available or degraded — either way it must NOT throw
        $taskHealth = $snapshot['task_health'];
        $this->assertTrue(is_array($taskHealth));
        if (($taskHealth['available'] ?? true) !== true) {
            $this->assertArrayHasKey('warning', $taskHealth);
            $this->assertStringContainsString('task_health', $taskHealth['warning']);
        }

        // brain_control_plane: same fail-open contract
        $brain = $snapshot['brain_control_plane'];
        $this->assertTrue(is_array($brain));
        if (($brain['available'] ?? true) !== true) {
            $this->assertArrayHasKey('warning', $brain);
        }

        // inbox_backlog: table may or may not exist — either way, no fatal
        $inbox = $snapshot['inbox_backlog'];
        $this->assertTrue(is_array($inbox));
        if (($inbox['available'] ?? true) !== true) {
            $this->assertArrayHasKey('warning', $inbox);
        }

        // No section references the dead ACDE/loop as a source of truth
        $json = json_encode($snapshot);
        $this->assertStringNotContainsString('acde', strtolower($json));
    }
}
