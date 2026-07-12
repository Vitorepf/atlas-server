<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Services\Ai\Cognition\Watchdog\Checks\SubstrateRestoreDrillWatchdogCheck;
use App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateRestoreDrillRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasMemorySubstrateRestoreDrillCommandTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().'/substrate-restore-drill-'.Str::lower(Str::random(8));
        @mkdir($this->tmpRoot, 0775, true);

        config([
            'atlas.cognition.substrate_snapshot.destination_root' => $this->tmpRoot.'/snapshots',
            'atlas.cognition.substrate_restore_drill.receipt_path' => $this->tmpRoot.'/receipts.jsonl',
            'atlas.cognition.substrate_restore_drill.target.port' => 55433,
            'atlas.cognition.substrate_restore_drill.canonical.port' => 5433,
            'atlas.cognition.substrate_restore_drill.max_success_age_days' => 45,
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tmpRoot);

        parent::tearDown();
    }

    public function test_restore_drill_restores_snapshot_and_records_restored_ok_receipt(): void
    {
        $snapshot = $this->writeSnapshot('2026-07-11T21-01-41-753590');
        $runner = new FakeRestoreDrillRunner(['atlas_memory_entries' => 2, 'atlas_memory_entry_usages' => 1]);
        $this->app->instance(AtlasMemorySubstrateRestoreDrillRunner::class, $runner);

        $exit = Artisan::call('atlas:substrate:restore-drill', [
            '--snapshot' => $snapshot,
            '--json' => true,
        ]);

        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame('restored_ok', $payload['status']);
        $this->assertTrue($payload['restored_ok']);
        $this->assertSame([], $payload['diffs']);
        $this->assertSame(55433, data_get($runner->lastConfig, 'port'));
        $this->assertStringContainsString('receipts.jsonl', (string) $payload['receipt_path']);
        $this->assertStringContainsString('"status":"restored_ok"', (string) file_get_contents($this->tmpRoot.'/receipts.jsonl'));
    }

    public function test_corrupted_snapshot_returns_restore_failed_with_named_diff(): void
    {
        $snapshot = $this->writeSnapshot('2026-07-11T21-01-41-753590');
        file_put_contents($snapshot.'/memory-substrate.sql', "-- corrupted\n");
        $this->app->instance(
            AtlasMemorySubstrateRestoreDrillRunner::class,
            new FakeRestoreDrillRunner(['atlas_memory_entries' => 2, 'atlas_memory_entry_usages' => 1]),
        );

        $exit = Artisan::call('atlas:substrate:restore-drill', [
            '--snapshot' => $snapshot,
            '--json' => true,
        ]);

        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame('restore_failed', $payload['status']);
        $this->assertFalse($payload['restored_ok']);
        $this->assertSame('dump_hash_sha256', data_get($payload, 'diffs.0.name'));
    }

    public function test_connection_guard_refuses_canonical_port_without_invoking_runner(): void
    {
        $snapshot = $this->writeSnapshot('2026-07-11T21-01-41-753590');
        $runner = new FakeRestoreDrillRunner(['atlas_memory_entries' => 2]);
        $this->app->instance(AtlasMemorySubstrateRestoreDrillRunner::class, $runner);
        config(['atlas.cognition.substrate_restore_drill.target.port' => 5433]);

        $exit = Artisan::call('atlas:substrate:restore-drill', [
            '--snapshot' => $snapshot,
            '--json' => true,
        ]);

        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame('restore_failed', $payload['status']);
        $this->assertSame('canonical_connection_guard', data_get($payload, 'diffs.0.name'));
        $this->assertFalse($runner->called);
    }

    public function test_watchdog_alerts_when_last_successful_drill_is_older_than_45_days(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-12T00:00:00Z'));
        file_put_contents($this->tmpRoot.'/receipts.jsonl', json_encode([
            'schema_version' => 'atlas.memory.substrate_restore_drill.v1',
            'status' => 'restored_ok',
            'restored_ok' => true,
            'checked_at' => '2026-05-20T00:00:00Z',
        ])."\n");

        $result = (new SubstrateRestoreDrillWatchdogCheck)->run()->toArray();

        CarbonImmutable::setTestNow();

        $this->assertSame('alert', $result['status']);
        $this->assertSame('substrate_restore_drill_stale', data_get($result, 'alert.code'));
        $this->assertSame(53, data_get($result, 'evidence.age_days'));
    }

    private function writeSnapshot(string $name): string
    {
        $snapshot = $this->tmpRoot.'/snapshots/'.$name;
        @mkdir($snapshot.'/series-jsonl', 0775, true);

        $dumpPath = $snapshot.'/memory-substrate.sql';
        $seriesPath = $snapshot.'/series-jsonl/acos-delta-series.jsonl';
        file_put_contents($dumpPath, "-- fixture dump\n");
        file_put_contents($seriesPath, "{\"date\":\"2026-07-11\"}\n");

        $manifest = [
            'schema_version' => 'atlas.memory.substrate_snapshot.v1',
            'recorded_at' => '2026-07-11T21:01:41+00:00',
            'slice' => 'SUB-01',
            'destination' => $snapshot,
            'dump_path' => $dumpPath,
            'dump_hash_sha256' => hash_file('sha256', $dumpPath),
            'dump_ok' => true,
            'live_counts' => [
                'atlas_memory_entries' => 2,
                'atlas_memory_entry_usages' => 1,
            ],
            'jsonl_copies' => [
                [
                    'target' => $seriesPath,
                    'present' => true,
                    'sha256' => hash_file('sha256', $seriesPath),
                    'line_count' => 1,
                ],
            ],
        ];

        file_put_contents(
            $snapshot.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        return $snapshot;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}

final class FakeRestoreDrillRunner implements AtlasMemorySubstrateRestoreDrillRunner
{
    public bool $called = false;

    /** @var array<string,mixed>|null */
    public ?array $lastConfig = null;

    /** @param array<string,int> $restoredCounts */
    public function __construct(private readonly array $restoredCounts) {}

    public function restore(string $dumpPath, array $tableNames, array $targetConnection): array
    {
        $this->called = true;
        $this->lastConfig = $targetConnection;

        return [
            'ok' => true,
            'restored_counts' => $this->restoredCounts,
            'target' => [
                'database' => (string) ($targetConnection['database'] ?? ''),
                'port' => (int) ($targetConnection['port'] ?? 0),
            ],
        ];
    }
}
