<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderHealthProbe;
use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

/**
 * The provider-health probe: a fact-only, fail-safe, flag-gated JSONL ledger of per-provider grind outcomes.
 */
final class AtlasLoopProviderHealthProbeTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-provider-health-'.bin2hex(random_bytes(6));
        config(['atlas.loop.provider_health_probe_enabled' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            foreach ((array) glob($this->root.'/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    public function test_record_appends_versioned_entry_and_snapshot_aggregates_window(): void
    {
        $probe = $this->probeAt('2026-06-24T12:00:00+00:00');

        $probe->record('openai-codex', 'gpt-5.5', ['ok' => true, 'latency_ms' => 100, 'cost_cents' => 10, 'grind_id' => 'g1']);
        $probe->record('openai-codex', 'gpt-5.5', ['ok' => true, 'latency_ms' => 200, 'cost_cents' => 5, 'grind_id' => 'g2']);
        $probe->record('openai-codex', 'gpt-5.5', ['ok' => false, 'latency_ms' => 300, 'cost_cents' => null, 'grind_id' => 'g3']);

        $rows = $this->readLedger($probe, 'openai-codex');
        $this->assertCount(3, $rows);
        $first = $rows[0];
        foreach (['schema_version', 'provider_key', 'model', 'ok', 'latency_ms', 'cost_cents', 'recorded_at_iso8601', 'grind_id'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
        $this->assertSame('atlas.loop.provider_health.v1', $first['schema_version']);
        $this->assertSame('openai-codex', $first['provider_key']);
        $this->assertSame('gpt-5.5', $first['model']);
        $this->assertTrue($first['ok']);
        $this->assertSame(100, $first['latency_ms']);
        $this->assertSame(10, $first['cost_cents']);
        $this->assertNull($rows[2]['cost_cents']);

        $snapshot = $probe->snapshot('openai-codex', 3600);
        $this->assertSame('atlas.loop.provider_health.v1', $snapshot['schema_version']);
        $this->assertSame(3, $snapshot['sample_count']);
        $this->assertSame(2, $snapshot['ok_count']);
        $this->assertSame(1, $snapshot['error_count']);
        $this->assertSame(15, $snapshot['cost_cents_sum']);
        $this->assertSame(200, $snapshot['p50_ms']); // nearest-rank of [100,200,300]
        $this->assertSame(300, $snapshot['p95_ms']);
    }

    public function test_dedup_per_grind_id_appends_once(): void
    {
        $probe = $this->probeAt('2026-06-24T12:00:00+00:00');

        $probe->record('minimax-oauth', 'm3', ['ok' => true, 'latency_ms' => 50, 'cost_cents' => 1, 'grind_id' => 'same']);
        $probe->record('minimax-oauth', 'm3', ['ok' => true, 'latency_ms' => 999, 'cost_cents' => 9, 'grind_id' => 'same']);

        $this->assertCount(1, $this->readLedger($probe, 'minimax-oauth'));
        $this->assertSame(1, $probe->snapshot('minimax-oauth', 3600)['sample_count']);
    }

    public function test_flag_off_writes_no_jsonl_byte_identical(): void
    {
        config(['atlas.loop.provider_health_probe_enabled' => false]);
        $probe = $this->probeAt('2026-06-24T12:00:00+00:00');

        // A simulated grind cycle's worth of records — with the flag OFF, nothing is ever written.
        $probe->record('openai-codex', 'gpt-5.5', ['ok' => true, 'latency_ms' => 100, 'cost_cents' => 10, 'grind_id' => 'g1']);
        $probe->record('openai-codex', 'gpt-5.5', ['ok' => false, 'latency_ms' => 200, 'cost_cents' => null, 'grind_id' => 'g2']);

        $this->assertFileDoesNotExist($probe->path('openai-codex'));
        $this->assertDirectoryDoesNotExist($this->root);
        $snapshot = $probe->snapshot('openai-codex', 3600);
        $this->assertSame(0, $snapshot['sample_count']);
        $this->assertSame(0, $snapshot['ok_count']);
        $this->assertSame(0, $snapshot['error_count']);
    }

    public function test_storage_write_failure_is_swallowed(): void
    {
        // Point the ledger root at a FILE, so the directory can never be created and the write throws —
        // which the probe must swallow (a health write never breaks a grind).
        $blocker = $this->root; // use the root path as a regular file
        @mkdir(dirname($blocker), 0775, true);
        file_put_contents($blocker, 'not-a-directory');

        $probe = new AtlasLoopProviderHealthProbe;
        $probe->setStorageRootForTesting($blocker);
        $probe->setClockForTesting(fn () => new DateTimeImmutable('2026-06-24T12:00:00+00:00', new DateTimeZone('UTC')));

        $probe->record('openai-codex', 'gpt-5.5', ['ok' => true, 'latency_ms' => 100, 'cost_cents' => 10, 'grind_id' => 'g1']);

        // No exception escaped; snapshot also degrades gracefully to zeros.
        $this->assertSame(0, $probe->snapshot('openai-codex', 3600)['sample_count']);
        @unlink($blocker);
    }

    public function test_snapshot_window_excludes_samples_older_than_window(): void
    {
        $old = $this->probeAt('2026-06-24T09:00:00+00:00'); // 3h before "now"
        $old->record('openai-codex', 'gpt-5.5', ['ok' => true, 'latency_ms' => 100, 'cost_cents' => 1, 'grind_id' => 'old']);

        $recent = $this->probeAt('2026-06-24T11:59:30+00:00'); // 30s before "now"
        $recent->record('openai-codex', 'gpt-5.5', ['ok' => true, 'latency_ms' => 200, 'cost_cents' => 2, 'grind_id' => 'recent']);

        $reader = $this->probeAt('2026-06-24T12:00:00+00:00');
        $snapshot = $reader->snapshot('openai-codex', 3600); // last hour only

        $this->assertSame(1, $snapshot['sample_count']);
        $this->assertSame(200, $snapshot['p50_ms']);
        $this->assertSame(2, $snapshot['cost_cents_sum']);
    }

    private function probeAt(string $iso): AtlasLoopProviderHealthProbe
    {
        $probe = new AtlasLoopProviderHealthProbe;
        $probe->setStorageRootForTesting($this->root);
        $probe->setClockForTesting(fn () => new DateTimeImmutable($iso, new DateTimeZone('UTC')));

        return $probe;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readLedger(AtlasLoopProviderHealthProbe $probe, string $providerKey): array
    {
        $path = $probe->path($providerKey);
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }
}
