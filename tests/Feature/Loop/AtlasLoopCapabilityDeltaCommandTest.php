<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the capability-delta attribution service is live at the operator surface: injected deliveries roll up
 * per shape_token with their sample count and credited verdict.
 */
final class AtlasLoopCapabilityDeltaCommandTest extends TestCase
{
    public function test_capability_delta_attribution_emits_by_shape(): void
    {
        $this->app->bind('atlas.loop.capability_delta.deliveries', fn (): array => [
            ['shape_token' => 'wiring', 'net_behavior_delta' => 1],
            ['shape_token' => 'wiring', 'net_behavior_delta' => 1],
            ['shape_token' => 'wiring', 'net_behavior_delta' => 0],
        ]);

        $exit = Artisan::call('atlas:loop:capability-delta-attribution', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.capability_delta_attribution.v1', $decoded['schema']);
        $this->assertIsArray($decoded['by_shape']);

        $byShape = array_column($decoded['by_shape'], null, 'shape_token');
        $this->assertArrayHasKey('wiring', $byShape);
        $this->assertSame(3, $byShape['wiring']['samples']);
        $this->assertArrayHasKey('credited', $byShape['wiring']);
    }
}
