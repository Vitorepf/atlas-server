<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneOriginatorScopeFence;
use PHPUnit\Framework\TestCase;

final class AtlasProjectLaneOriginatorScopeFenceTest extends TestCase
{
    private AtlasProjectLaneOriginatorScopeFence $fence;

    protected function setUp(): void
    {
        $this->fence = new AtlasProjectLaneOriginatorScopeFence;
    }

    public function test_outside_lane_files_rejected(): void
    {
        $result = $this->fence->fence([
            'lane_root' => '/Users/test/atlas-server',
            'allowed_files' => [
                '/Users/test/atlas-server/app/Services/Foo.php',
                '/Users/other/project/app/Bar.php',
            ],
        ]);

        $this->assertFalse($result['clean']);
        $this->assertSame(1, $result['violation_count']);
        $this->assertSame('outside_lane_scope', $result['violations'][0]['reason']);
    }

    public function test_active_lane_service_plus_test_scopes_pass(): void
    {
        $result = $this->fence->fence([
            'lane_root' => '/Users/test/atlas-server',
            'allowed_files' => [
                '/Users/test/atlas-server/app/Services/Foo.php',
                '/Users/test/atlas-server/tests/Unit/FooTest.php',
            ],
        ]);

        $this->assertTrue($result['clean']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertCount(2, $result['fenced_files']);
    }

    public function test_no_lane_root_accepts_relative_app_and_tests(): void
    {
        $result = $this->fence->fence([
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
        ]);

        $this->assertTrue($result['clean']);
    }

    public function test_no_lane_root_rejects_absolute_outside(): void
    {
        $result = $this->fence->fence([
            'allowed_files' => ['/Users/other/project/app/Bar.php'],
        ]);

        $this->assertFalse($result['clean']);
    }

    public function test_schema_present(): void
    {
        $result = $this->fence->fence([]);
        $this->assertSame(AtlasProjectLaneOriginatorScopeFence::SCHEMA, $result['schema']);
    }
}
