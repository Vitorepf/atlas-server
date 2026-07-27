<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Memory;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateDumpRunner;
use App\Services\Ai\Memory\Substrate\AtlasMemorySubstrateRestoreProofRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

final class AtlasMemorySubstrateSnapshotCommandTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().'/substrate-snapshot-'.Str::lower(Str::random(8));
        @mkdir($this->tmpRoot, 0775, true);

        $this->createAtlasMemoryEntryTable();
        Schema::dropIfExists('atlas_memory_entry_usages');
        (require database_path('migrations/2026_05_02_001000_create_atlas_memory_entry_usages_table.php'))->up();
        $this->migrateLedger();

        config([
            'atlas.cognition.substrate_snapshot.destination_root' => $this->tmpRoot,
            'atlas.cognition.substrate_snapshot.tables' => [
                'atlas_memory_entries',
                'atlas_memory_entry_usages',
            ],
            'atlas.cognition.substrate_snapshot.series_jsonls' => [
                $this->tmpRoot.'/delta-series.jsonl',
                'live_outcomes:',
            ],
        ]);

        file_put_contents($this->tmpRoot.'/delta-series.jsonl', "{\"date\":\"2026-07-11\"}\n");

        app(AtlasDecideLiveOutcomeFeedbackService::class)->setLogPathForTesting($this->tmpRoot.'/live_outcomes.jsonl');
        file_put_contents($this->tmpRoot.'/live_outcomes.jsonl', "{\"result\":\"success\"}\n");

        $this->seedMemoryRows();
        $this->bindFakeRunners();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('atlas_memory_entry_usages');
        $this->dropAtlasMemoryEntryTable();

        $this->deleteDirectory($this->tmpRoot);

        parent::tearDown();
    }

    public function test_command_snapshots_counts_copies_jsonls_and_records_ledger_receipt_with_dump_hash(): void
    {
        $this->artisan('atlas:memory:substrate-snapshot', ['--json' => true])
            ->assertExitCode(0);

        $event = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::MemorySubstrateSnapshotRecorded->value)
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('memory_substrate', $event->scope_type);
        $this->assertSame('SUB-01', $event->scope_id);
        $this->assertSame('atlas.memory.substrate_snapshot.v1', data_get($event->payload, 'schema_version'));
        $this->assertSame('SUB-01', data_get($event->payload, 'slice'));
        $this->assertTrue((bool) data_get($event->payload, 'restore_proof_ok'));
        $this->assertSame(
            ['atlas_memory_entries' => 2, 'atlas_memory_entry_usages' => 1],
            (array) data_get($event->payload, 'live_counts'),
        );

        $dumpHash = (string) data_get($event->payload, 'dump_hash_sha256');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $dumpHash);

        $jsonlCopies = (array) data_get($event->payload, 'jsonl_copies');
        $this->assertCount(2, $jsonlCopies);
        $this->assertTrue(collect($jsonlCopies)->every(static fn (array $copy): bool => ($copy['present'] ?? false) === true));
    }

    public function test_restore_proof_fails_when_mocked_counts_diverge(): void
    {
        $this->app->instance(AtlasMemorySubstrateRestoreProofRunner::class, new readonly class implements AtlasMemorySubstrateRestoreProofRunner
        {
            public function prove(string $dumpPath, array $tableNames, array $liveCounts): array
            {
                return [
                    'ok' => false,
                    'ephemeral_schema' => 'atlas_substrate_proof_test',
                    'restored_counts' => ['atlas_memory_entries' => 0],
                    'live_counts' => $liveCounts,
                    'reason' => 'count_mismatch',
                ];
            }
        });

        $this->artisan('atlas:memory:substrate-snapshot', ['--json' => true])
            ->assertExitCode(1);
    }

    private function seedMemoryRows(): void
    {
        $entryA = (string) Str::uuid();
        $entryB = (string) Str::uuid();

        DB::table('atlas_memory_entries')->insert([
            [
                'id' => $entryA,
                'memory_type' => 'decision',
                'scope_type' => 'global',
                'body' => 'Body A',
                'title' => 'Entry A',
                'status' => 'active',
                'tags' => '[]',
                'metadata' => '{}',
                'recorded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => $entryB,
                'memory_type' => 'decision',
                'scope_type' => 'global',
                'body' => 'Body B',
                'title' => 'Entry B',
                'status' => 'active',
                'tags' => '[]',
                'metadata' => '{}',
                'recorded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('atlas_memory_entry_usages')->insert([
            'id' => (string) Str::uuid(),
            'memory_entry_id' => $entryA,
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'source_type' => 'memory_recall',
            'position' => 0,
            'source_ref_json' => '{}',
            'context_payload_json' => '{}',
            'metadata' => '{}',
            'feedback_action' => 'useful',
            'feedback_recorded_at' => now(),
            'used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function bindFakeRunners(): void
    {
        $this->app->instance(AtlasMemorySubstrateDumpRunner::class, new readonly class implements AtlasMemorySubstrateDumpRunner
        {
            public function dump(array $tableNames, string $dumpPath): array
            {
                $dir = \dirname($dumpPath);
                if (! is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }

                $lines = ["-- mocked pg_dump for SUB-01\n"];
                foreach ($tableNames as $table) {
                    $count = (int) DB::table($table)->count();
                    $lines[] = "COPY public.{$table} FROM stdin;\n";
                    $lines[] = "-- rows={$count}\n";
                    $lines[] = "\\.\n";
                }
                file_put_contents($dumpPath, implode('', $lines));

                return [
                    'ok' => true,
                    'dump_path' => $dumpPath,
                    'stderr' => '',
                ];
            }
        });

        $this->app->instance(AtlasMemorySubstrateRestoreProofRunner::class, new readonly class implements AtlasMemorySubstrateRestoreProofRunner
        {
            public function prove(string $dumpPath, array $tableNames, array $liveCounts): array
            {
                $this->assertDumpExists($dumpPath);

                return [
                    'ok' => true,
                    'ephemeral_schema' => 'atlas_substrate_proof_test',
                    'restored_counts' => $liveCounts,
                    'live_counts' => $liveCounts,
                ];
            }

            private function assertDumpExists(string $dumpPath): void
            {
                if (! is_file($dumpPath)) {
                    throw new \RuntimeException('missing dump at '.$dumpPath);
                }
            }
        });
    }

    private function migrateLedger(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_19_050000_extend_atlas_ledger_events_with_timeline_fields.php'))->up();
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
