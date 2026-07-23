<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyLoopGapRouter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAutonomyLoopGapRouterTest extends TestCase
{
    private AtlasExternalBrainAutonomyLoopGapRouter $router;

    protected function setUp(): void
    {
        $this->router = new AtlasExternalBrainAutonomyLoopGapRouter;
    }

    // ── AC: each gap stage maps to a distinct task family ──

    public function test_all_stages_map_to_distinct_families(): void
    {
        $gaps = array_map(
            static fn (string $stage): array => ['stage' => $stage, 'reason' => 'missing '.$stage, 'severity' => 'medium'],
            AtlasExternalBrainAutonomyLoopGapRouter::ALL_STAGES
        );

        $result = $this->router->route(['originator_id' => 'orig-1', 'round_id' => 'round-1', 'gaps' => $gaps]);

        $families = array_column($result['routes'], 'task_family');
        $this->assertCount(5, array_unique($families));
        $this->assertContains(AtlasExternalBrainAutonomyLoopGapRouter::FAMILY_SENSING, $families);
        $this->assertContains(AtlasExternalBrainAutonomyLoopGapRouter::FAMILY_DECIDING, $families);
        $this->assertContains(AtlasExternalBrainAutonomyLoopGapRouter::FAMILY_ACTING, $families);
        $this->assertContains(AtlasExternalBrainAutonomyLoopGapRouter::FAMILY_VERIFYING, $families);
        $this->assertContains(AtlasExternalBrainAutonomyLoopGapRouter::FAMILY_LEARNING, $families);
    }

    // ── AC: allowed_files are present for every route ──

    public function test_each_route_has_allowed_files(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'gaps' => [
                ['stage' => 'sensing', 'reason' => 'no sensor', 'severity' => 'high'],
            ],
        ]);

        $this->assertCount(1, $result['routes']);
        $route = $result['routes'][0];
        $this->assertNotEmpty($route['allowed_files']);
        $this->assertStringContainsString('AtlasAutonomousRuntimeSensor.php', $route['allowed_files'][0]);
    }

    // ── AC: runnable acceptance criteria are present ──

    public function test_each_route_has_acceptance_criteria(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'gaps' => [
                ['stage' => 'acting', 'reason' => 'no actuator', 'severity' => 'medium'],
            ],
        ]);

        $route = $result['routes'][0];
        $this->assertNotEmpty($route['acceptance_criteria']);
        $this->assertStringContainsString('actuator', $route['acceptance_criteria'][0]);
    }

    // ── priority ordering ──

    public function test_critical_gaps_are_ordered_first(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'gaps' => [
                ['stage' => 'learning', 'reason' => 'slow', 'severity' => 'low'],
                ['stage' => 'sensing', 'reason' => 'blind', 'severity' => 'critical'],
                ['stage' => 'deciding', 'reason' => 'stuck', 'severity' => 'high'],
            ],
        ]);

        $stages = array_column($result['routes'], 'stage');
        $this->assertSame(['sensing', 'deciding', 'learning'], $stages);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'gaps' => [
                ['stage' => 'verifying', 'reason' => 'no proof', 'severity' => 'high'],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainAutonomyLoopGapRouter::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('routes', $result);
        $this->assertArrayHasKey('routable_gap_count', $result);
        $this->assertSame(1, $result['routable_gap_count']);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'gaps' => [
                ['stage' => 'sensing', 'reason' => 'no sensor', 'severity' => 'high'],
                ['stage' => 'acting', 'reason' => 'no actuator', 'severity' => 'medium'],
            ],
        ];

        $a = $this->router->route($input);
        $b = $this->router->route($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
