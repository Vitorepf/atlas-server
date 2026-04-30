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
    }
}
