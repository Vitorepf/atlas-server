<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Attribution\AtlasLoopCapabilityDeltaAttributionService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the capability-delta attribution service is live at the operator surface: a shape whose deliveries
 * consistently move behavior positively (enough samples) is credited with a high mean delta, while a shape with
 * flat deliveries is not credited — distinguishing the higher-impact shape from the lower-impact one.
 */
final class AtlasLoopCapabilityAttributionCommandTest extends TestCase
{
    private function attribute(array $deliveries): array
    {
        $exit = Artisan::call('atlas:loop:capability-attribution', [
            '--deliveries' => (string) json_encode($deliveries),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_distinguishes_high_impact_from_low_impact_shape(): void
    {
        $deliveries = [];
        // 6 high-impact deliveries (positive delta) ⇒ credited (>= default 5 samples, wilson > 0)
        for ($i = 0; $i < 6; $i++) {
            $deliveries[] = ['shape_token' => 'high_impact', 'net_behavior_delta' => 2];
        }
        // 6 flat deliveries (zero delta) ⇒ not credited
        for ($i = 0; $i < 6; $i++) {
            $deliveries[] = ['shape_token' => 'flat', 'net_behavior_delta' => 0];
        }

        ['exit' => $exit, 'd' => $d] = $this->attribute($deliveries);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopCapabilityDeltaAttributionService::SCHEMA, $d['schema']);

        $byShape = array_column($d['by_shape'], null, 'shape_token');
        $this->assertArrayHasKey('high_impact', $byShape, (string) json_encode($d));
        $this->assertArrayHasKey('flat', $byShape);

        $this->assertTrue($byShape['high_impact']['credited']);
        $this->assertEqualsWithDelta(2.0, $byShape['high_impact']['mean_delta'], 1e-9);

        $this->assertFalse($byShape['flat']['credited']);
        $this->assertEqualsWithDelta(0.0, $byShape['flat']['mean_delta'], 1e-9);
    }

    public function test_missing_deliveries_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:capability-attribution', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
