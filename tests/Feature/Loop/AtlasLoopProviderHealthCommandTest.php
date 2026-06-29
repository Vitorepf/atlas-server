<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderHealthProbe;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the provider-health probe is live at the operator surface: it reads the append-only ledger and emits a
 * deterministic windowed distribution (p50/p95, ok/error, cost), excluding samples older than the window.
 */
final class AtlasLoopProviderHealthCommandTest extends TestCase
{
    private string $root = '';

    private AtlasLoopProviderHealthProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-provider-health-'.bin2hex(random_bytes(5));
        @mkdir($this->root, 0o755, true);

        $this->probe = new AtlasLoopProviderHealthProbe();
        $this->probe->setStorageRootForTesting($this->root);
        // Pin the clock so the window cutoff is deterministic (now = 2026-06-29T12:00:00Z).
        $this->probe->setClockForTesting(fn (): DateTimeImmutable => new DateTimeImmutable('2026-06-29T12:00:00', new DateTimeZone('UTC')));
        $this->app->instance(AtlasLoopProviderHealthProbe::class, $this->probe);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function seedLedger(string $provider, array $rows): void
    {
        $lines = array_map(static fn (array $r): string => (string) json_encode($r), $rows);
        file_put_contents($this->probe->path($provider), implode("\n", $lines)."\n");
    }

    public function test_windowed_distribution_excludes_old_samples(): void
    {
        $this->seedLedger('claude', [
            ['ok' => true, 'latency_ms' => 100, 'cost_cents' => 5, 'recorded_at_iso8601' => '2026-06-29T11:30:00+00:00'],
            ['ok' => true, 'latency_ms' => 200, 'cost_cents' => 5, 'recorded_at_iso8601' => '2026-06-29T11:45:00+00:00'],
            ['ok' => false, 'latency_ms' => 300, 'cost_cents' => null, 'recorded_at_iso8601' => '2026-06-29T11:59:00+00:00'],
            // older than the 3600s window (before 11:00:00) ⇒ excluded
            ['ok' => true, 'latency_ms' => 999, 'cost_cents' => 99, 'recorded_at_iso8601' => '2026-06-29T10:00:00+00:00'],
        ]);

        $exit = Artisan::call('atlas:loop:provider-health', [
            '--provider' => 'claude',
            '--window-seconds' => 3600,
            '--json' => true,
        ]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopProviderHealthProbe::SCHEMA_VERSION, $d['schema_version']);
        $this->assertSame('claude', $d['provider_key']);
        $this->assertSame(3, $d['sample_count'], (string) json_encode($d));
        $this->assertSame(200, $d['p50_ms']);
        $this->assertSame(300, $d['p95_ms']);
        $this->assertSame(2, $d['ok_count']);
        $this->assertSame(1, $d['error_count']);
        $this->assertSame(10, $d['cost_cents_sum']);
    }

    public function test_unknown_provider_is_all_zero_snapshot(): void
    {
        $exit = Artisan::call('atlas:loop:provider-health', ['--provider' => 'ghost', '--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $d['sample_count']);
        $this->assertSame(0, $d['p50_ms']);
        $this->assertSame(0, $d['ok_count']);
    }

    public function test_missing_provider_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:provider-health', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
