<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneRuntimeInstanceSoak;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the multi-project lane soak is live at the operator surface: the soak result references every supplied
 * lane; two lanes sharing a queue namespace surface a leak attempt and fail the soak.
 */
final class AtlasLoopLaneSoakCommandTest extends TestCase
{
    private function soak(array $instances, array $options = []): array
    {
        $exit = Artisan::call('atlas:loop:lane-soak', [
            '--instances' => (string) json_encode($instances),
            '--options' => (string) json_encode($options),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_soak_references_every_lane(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->soak([
            ['lane_id' => 'l1', 'queue_namespace' => 'ns1', 'allowed_roots' => ['/repo/p1']],
            ['lane_id' => 'l2', 'queue_namespace' => 'ns2', 'allowed_roots' => ['/repo/p2']],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasProjectLaneRuntimeInstanceSoak::SCHEMA, $d['schema_version']);
        $this->assertArrayHasKey('l1', $d['lane_results'], (string) json_encode($d));
        $this->assertArrayHasKey('l2', $d['lane_results']);
        $this->assertSame([], $d['leak_attempts']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $d['multi_project_soak_hash']);
    }

    public function test_namespace_collision_is_a_leak_and_fails(): void
    {
        ['d' => $d] = $this->soak([
            ['lane_id' => 'l1', 'queue_namespace' => 'shared', 'allowed_roots' => ['/repo/p1']],
            ['lane_id' => 'l2', 'queue_namespace' => 'shared', 'allowed_roots' => ['/repo/p2']],
        ]);

        $this->assertNotEmpty($d['leak_attempts'], (string) json_encode($d));
        $this->assertSame('namespace_collision', $d['leak_attempts'][0]['kind']);
        $this->assertFalse($d['passed']);
    }

    public function test_missing_instances_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:lane-soak', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
