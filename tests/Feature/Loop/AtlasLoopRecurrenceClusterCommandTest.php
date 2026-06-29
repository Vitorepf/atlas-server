<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the recurrence-cluster observer is live at the operator surface: an fqcn orphaned across 3+ snapshots
 * is a recurring unit (chronic backlog); an fqcn seen in only one snapshot is not; no recurrence yields an
 * empty observation.
 */
final class AtlasLoopRecurrenceClusterCommandTest extends TestCase
{
    private function observe(array $snapshots): array
    {
        $exit = Artisan::call('atlas:loop:recurrence-cluster', [
            '--snapshots' => (string) json_encode($snapshots),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_thrice_seen_fqcn_recurs_once_seen_does_not(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->observe([
            ['orphan_fqcns' => ['App\\Chronic', 'App\\Once']],
            ['orphan_fqcns' => ['App\\Chronic']],
            ['orphan_fqcns' => ['App\\Chronic']],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.recurrence_cluster.v1', $d['schema']);
        $this->assertTrue($d['observed'], (string) json_encode($d));
        $this->assertContains('App\\Chronic', $d['recurring_units']);
        $this->assertNotContains('App\\Once', $d['recurring_units']);
    }

    public function test_no_recurrence_is_empty_observation(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->observe([
            ['orphan_fqcns' => ['App\\A']],
            ['orphan_fqcns' => ['App\\B']],
        ]);

        $this->assertSame(0, $exit);
        $this->assertFalse($d['observed']);
        $this->assertSame([], $d['recurring_units']);
    }

    public function test_missing_snapshots_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:recurrence-cluster', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
