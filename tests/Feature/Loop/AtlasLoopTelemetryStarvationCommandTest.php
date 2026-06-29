<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Telemetry\AtlasLoopTelemetryEventProducer;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the pipeline-starvation detector is live at the operator surface: claims with no matching serve in the
 * window read as starved, while a paired claim→serve for the same cycle reads as healthy. The event source is
 * injected so the test touches no live disk.
 */
final class AtlasLoopTelemetryStarvationCommandTest extends TestCase
{
    private function bindEvents(array $events): void
    {
        $this->app->bind(
            AtlasLoopTelemetryEventProducer::class,
            fn (): AtlasLoopTelemetryEventProducer => new AtlasLoopTelemetryEventProducer($events),
        );
    }

    public function test_claims_without_serves_in_window_are_starved(): void
    {
        $recent = (new DateTimeImmutable('-1 minute'))->format(DATE_ATOM);
        $this->bindEvents([
            ['kind' => 'claim', 'cycle_id' => 'cyc-1', 'occurred_at_iso' => $recent],
            ['kind' => 'claim', 'cycle_id' => 'cyc-2', 'occurred_at_iso' => $recent],
        ]);

        $exit = Artisan::call('atlas:loop:telemetry-starvation', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertTrue($decoded['starved'], 'claims with zero matching serves ⇒ starved');
        $this->assertSame(2, $decoded['counts']['claim']);
    }

    public function test_paired_claim_then_serve_is_not_starved(): void
    {
        $recent = (new DateTimeImmutable('-1 minute'))->format(DATE_ATOM);
        $this->bindEvents([
            ['kind' => 'claim', 'cycle_id' => 'cyc-1', 'occurred_at_iso' => $recent],
            ['kind' => 'serve', 'cycle_id' => 'cyc-1', 'occurred_at_iso' => $recent],
        ]);

        $exit = Artisan::call('atlas:loop:telemetry-starvation', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertFalse($decoded['starved'], 'a paired claim→serve for the same cycle is healthy');
    }
}
