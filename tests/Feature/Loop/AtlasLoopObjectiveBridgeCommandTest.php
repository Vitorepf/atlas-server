<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the V4 objective-to-work bridge is live at the operator surface: a meta-objective naming inventory
 * symbols bridges to those scoped items; an objective grounding to no inventory symbol is refused; a
 * non-originated objective is refused.
 */
final class AtlasLoopObjectiveBridgeCommandTest extends TestCase
{
    private function bridge(array $metaObjective, array $inventory): array
    {
        $exit = Artisan::call('atlas:loop:objective-bridge', [
            '--meta-objective' => (string) json_encode($metaObjective),
            '--inventory' => (string) json_encode($inventory),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_objective_bridges_to_named_inventory(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->bridge(
            ['originated' => true, 'objective' => 'Refactor AtlasFoo and AtlasBar to reduce coupling', 'target_metric' => 'maintainability', 'target_delta' => 2],
            ['AtlasFoo', 'AtlasBaz', 'AtlasBar'],
        );

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.v4_objective_bridge.v1', $d['schema']);
        $this->assertTrue($d['bridged'], (string) json_encode($d));
        $this->assertContains('AtlasFoo', $d['scoped_inventory']);
        $this->assertContains('AtlasBar', $d['scoped_inventory']);
        $this->assertNotContains('AtlasBaz', $d['scoped_inventory']); // not named in the objective
        $this->assertSame('maintainability:+2', $d['leverage_hint']);
    }

    public function test_no_scoped_inventory_is_refused(): void
    {
        ['d' => $d] = $this->bridge(
            ['originated' => true, 'objective' => 'improve things generally'],
            ['AtlasFoo'],
        );

        $this->assertFalse($d['bridged']);
        $this->assertSame('no_scoped_inventory', $d['refuse_reason']);
    }

    public function test_not_originated_is_refused(): void
    {
        ['d' => $d] = $this->bridge(
            ['originated' => false, 'objective' => 'Refactor AtlasFoo'],
            ['AtlasFoo'],
        );

        $this->assertFalse($d['bridged']);
        $this->assertSame('upstream_not_originated', $d['refuse_reason']);
    }

    public function test_missing_inventory_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:objective-bridge', ['--meta-objective' => '{}', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
