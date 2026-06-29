<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneTaskFabricRouter;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the project-lane task-fabric router is live at the operator surface: an in-scope candidate routes into
 * a project-prefixed packet carrying the candidate objective and a scope-locked workspace policy; a candidate
 * whose path escapes the lane scope is refused.
 */
final class AtlasLoopLaneRouteCommandTest extends TestCase
{
    private function route(array $lane, array $candidate): array
    {
        $exit = Artisan::call('atlas:loop:lane-route', [
            '--lane' => (string) json_encode($lane),
            '--candidate' => (string) json_encode($candidate),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_in_scope_candidate_routes_into_packet(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->route(
            ['project_id' => 'p1', 'allowed_scope_roots' => ['app/marketing']], // leaf 'marketing'
            ['task_packet_id' => 't1', 'objective' => 'Build the marketing widget', 'allowed_files' => ['marketing/Foo.php']],
        );

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasProjectLaneTaskFabricRouter::SCHEMA, $d['schema_version']);
        $this->assertSame('p1:t1', $d['task_packet_id'], (string) json_encode($d));
        $this->assertSame('Build the marketing widget', $d['objective']);
        $this->assertContains('marketing/Foo.php', $d['allowed_files']);
        $this->assertSame(AtlasProjectLaneTaskFabricRouter::ISOLATION, $d['workspace_policy']['isolation']);
    }

    public function test_path_escaping_lane_scope_is_refused(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->route(
            ['project_id' => 'p1', 'allowed_scope_roots' => ['app/marketing']], // leaf 'marketing'
            ['objective' => 'Escape the lane', 'allowed_files' => ['other/Bar.php']],
        );

        $this->assertNotSame(0, $exit);
        $this->assertSame('route_refused', $d['reason']);
        $this->assertStringContainsString('path_escapes_lane_scope', $d['message']);
    }

    public function test_missing_lane_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:lane-route', ['--candidate' => '{}', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
