<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneTaskFabricSeedRouter;
use PHPUnit\Framework\TestCase;

final class AtlasProjectLaneTaskFabricSeedRouterTest extends TestCase
{
    private AtlasProjectLaneTaskFabricSeedRouter $router;

    protected function setUp(): void
    {
        $this->router = new AtlasProjectLaneTaskFabricSeedRouter;
    }

    public function test_lane_specific_seed_routes_correctly(): void
    {
        $result = $this->router->route([
            'workspace' => '/Users/test/atlas-server',
            'capability' => 'external_brain',
            'allowed_files' => ['app/Services/Foo.php'],
        ]);

        $this->assertTrue($result['routed']);
        $this->assertNotNull($result['lane']);
        $this->assertEmpty($result['blockers']);
    }

    public function test_missing_workspace_blocks_enqueue(): void
    {
        $result = $this->router->route([
            'capability' => 'external_brain',
            'allowed_files' => ['app/Services/Foo.php'],
        ]);

        $this->assertFalse($result['routed']);
        $this->assertContains('missing_workspace', $result['blockers']);
    }

    public function test_missing_capability_blocks_enqueue(): void
    {
        $result = $this->router->route([
            'workspace' => '/Users/test/atlas-server',
            'allowed_files' => ['app/Services/Foo.php'],
        ]);

        $this->assertFalse($result['routed']);
        $this->assertContains('missing_capability', $result['blockers']);
    }

    public function test_missing_allowed_files_blocks_enqueue(): void
    {
        $result = $this->router->route([
            'workspace' => '/Users/test/atlas-server',
            'capability' => 'external_brain',
        ]);

        $this->assertFalse($result['routed']);
        $this->assertContains('missing_allowed_files', $result['blockers']);
    }

    public function test_schema_present(): void
    {
        $result = $this->router->route([]);
        $this->assertSame(AtlasProjectLaneTaskFabricSeedRouter::SCHEMA, $result['schema']);
    }
}
