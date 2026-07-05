<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\MultiProject;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneCrossWorkspaceCollisionGuard;
use Tests\TestCase;

final class AtlasProjectLaneCrossWorkspaceCollisionGuardTest extends TestCase
{
    private function guard(): AtlasProjectLaneCrossWorkspaceCollisionGuard
    {
        return new AtlasProjectLaneCrossWorkspaceCollisionGuard;
    }

    // ── AC: same relative target in different lanes is separated ──

    public function test_same_target_different_lanes_separated(): void
    {
        $result = $this->guard()->guard([
            ['proposal_id' => 'p1', 'lane_id' => 'lane-a', 'target_family' => 'implementation'],
            ['proposal_id' => 'p2', 'lane_id' => 'lane-b', 'target_family' => 'implementation'],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['blocked']);
        $this->assertCount(1, $result['separated']);
        $this->assertSame('implementation', $result['separated'][0]['target_family']);
        $this->assertContains('lane-a', $result['separated'][0]['lanes']);
        $this->assertContains('lane-b', $result['separated'][0]['lanes']);
    }

    // ── AC: same lane duplicates are blocked ──

    public function test_same_lane_duplicate_blocked(): void
    {
        $result = $this->guard()->guard([
            ['proposal_id' => 'p1', 'lane_id' => 'lane-a', 'target_family' => 'refactor'],
            ['proposal_id' => 'p2', 'lane_id' => 'lane-a', 'target_family' => 'refactor'],
        ]);

        $this->assertFalse($result['passed']);
        $this->assertCount(1, $result['blocked']);
        $this->assertSame('same_lane_duplicate_target', $result['blocked'][0]['reason']);
    }

    // ── no collisions ──

    public function test_no_collisions_passes(): void
    {
        $result = $this->guard()->guard([
            ['proposal_id' => 'p1', 'lane_id' => 'lane-a', 'target_family' => 'impl'],
            ['proposal_id' => 'p2', 'lane_id' => 'lane-b', 'target_family' => 'test'],
        ]);

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['blocked']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->guard()->guard([]);

        $this->assertSame(AtlasProjectLaneCrossWorkspaceCollisionGuard::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('blocked', $result);
        $this->assertArrayHasKey('separated', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $proposals = [
            ['proposal_id' => 'p1', 'lane_id' => 'lane-a', 'target_family' => 'x'],
            ['proposal_id' => 'p2', 'lane_id' => 'lane-b', 'target_family' => 'x'],
        ];

        $a = $this->guard()->guard($proposals);
        $b = $this->guard()->guard($proposals);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
