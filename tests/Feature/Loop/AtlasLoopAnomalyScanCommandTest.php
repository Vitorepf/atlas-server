<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Closure;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the anomaly baseline + deviation pair is live at the operator surface: injected receipts build a
 * per-signal baseline and the deviation detector runs over it, emitting a deviations list. A missing --campaign
 * is a usage_error.
 */
final class AtlasLoopAnomalyScanCommandTest extends TestCase
{
    public function test_anomaly_scan_builds_baseline_and_runs_deviation_detector(): void
    {
        $recent = gmdate(DATE_ATOM, time() - 3600);
        $this->app->bind(
            'atlas.loop.anomaly.receipts',
            fn (): Closure => fn (string $campaign): array => [
                ['ts' => $recent, 'outcome' => 'completion'],
                ['ts' => $recent, 'outcome' => 'completion'],
                ['ts' => $recent, 'outcome' => 'give_back'],
            ],
        );

        $exit = Artisan::call('atlas:loop:anomaly-scan', ['--campaign' => 'camp-test', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.anomaly_scan.v1', $decoded['schema_version']);
        // baseline reflects the injected receipts.
        $this->assertSame(2, $decoded['baseline']['completion']['count_in_window']);
        $this->assertSame(1, $decoded['baseline']['give_back']['count_in_window']);
        $this->assertSame(3, $decoded['baseline']['completion']['total_in_window']);
        // the deviation detector ran and produced a (deterministic) deviations list.
        $this->assertIsArray($decoded['deviations']);
        $this->assertSame(count($decoded['deviations']), $decoded['deviations_count']);
    }

    public function test_missing_campaign_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:anomaly-scan', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
