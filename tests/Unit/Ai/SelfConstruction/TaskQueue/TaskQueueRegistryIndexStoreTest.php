<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\SelfConstruction\TaskQueue\TaskPacketCanonicalizer;
use App\Services\Ai\SelfConstruction\TaskQueue\TaskQueueRegistryIndexStore;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaskQueueRegistryIndexStoreTest extends TestCase
{
    private string $diskName;

    protected function setUp(): void
    {
        parent::setUp();
        // Use a fresh in-memory-ish disk for each test
        $this->diskName = 'local_test_' . uniqid('', true);
        config(["filesystems.disks.{$this->diskName}" => [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/' . $this->diskName),
        ]]);
    }

    private function store(): TaskQueueRegistryIndexStore
    {
        return new TaskQueueRegistryIndexStore(
            Storage::disk($this->diskName),
            new TaskPacketCanonicalizer,
        );
    }

    public function test_load_registry_empty_when_missing(): void
    {
        $registry = $this->store()->loadRegistry();

        self::assertSame(['entries' => []], $registry);
        self::assertArrayNotHasKey('corrupt', $registry);
    }

    public function test_save_and_load_registry_roundtrip(): void
    {
        $store = $this->store();
        $registry = ['entries' => [['task_packet_id' => 'tp1', 'status' => 'queued']]];

        $store->saveRegistry($registry);
        $loaded = $store->loadRegistry();

        self::assertSame('tp1', $loaded['entries'][0]['task_packet_id']);
        self::assertSame('queued', $loaded['entries'][0]['status']);
    }

    public function test_load_registry_corrupt_returns_flag(): void
    {
        $store = $this->store();
        Storage::disk($this->diskName)->put('atlas/self-construction/task-packet-queue-registry.json', 'not-json{');

        $registry = $store->loadRegistry();

        self::assertTrue($registry['corrupt']);
        self::assertSame([], $registry['entries']);
    }

    public function test_load_registry_invalid_shape_returns_corrupt(): void
    {
        $store = $this->store();
        Storage::disk($this->diskName)->put('atlas/self-construction/task-packet-queue-registry.json', '"not-an-object"');

        $registry = $store->loadRegistry();

        self::assertTrue($registry['corrupt']);
    }

    public function test_register_in_registry_adds_entry(): void
    {
        $store = $this->store();
        $store->registerInRegistry([
            'task_packet_id' => 'tp1',
            'task_packet_hash' => 'h1',
            'enqueued_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
            'status' => 'queued',
            'priority' => 5,
            'tags' => ['tag1'],
        ]);

        $registry = $store->loadRegistry();
        self::assertCount(1, $registry['entries']);
        self::assertSame('tp1', $registry['entries'][0]['task_packet_id']);
    }

    public function test_update_registry_entry_modifies_existing(): void
    {
        $store = $this->store();
        $store->registerInRegistry([
            'task_packet_id' => 'tp1',
            'task_packet_hash' => 'h1',
            'enqueued_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
            'status' => 'queued',
            'priority' => 5,
            'tags' => [],
        ]);

        $store->updateRegistryEntry('tp1', [
            'task_packet_hash' => 'h1',
            'enqueued_at' => '2026-01-01',
            'updated_at' => '2026-01-02',
            'status' => 'claimed',
            'priority' => 5,
            'tags' => [],
        ]);

        $registry = $store->loadRegistry();
        self::assertCount(1, $registry['entries']);
        self::assertSame('claimed', $registry['entries'][0]['status']);
        self::assertSame('2026-01-02', $registry['entries'][0]['updated_at']);
    }

    public function test_update_registry_entry_adds_when_not_found(): void
    {
        $store = $this->store();
        $store->updateRegistryEntry('tp_new', [
            'task_packet_hash' => 'h1',
            'enqueued_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
            'status' => 'queued',
            'priority' => 0,
            'tags' => [],
        ]);

        $registry = $store->loadRegistry();
        self::assertCount(1, $registry['entries']);
        self::assertSame('tp_new', $registry['entries'][0]['task_packet_id']);
    }

    public function test_cap_registry_preserves_live_drops_old_terminal(): void
    {
        $store = $this->store();
        $entries = [
            ['status' => 'queued', 'id' => 1],
            ['status' => 'claimable', 'id' => 2],
            ['status' => 'completed_dry_run', 'id' => 3],
            ['status' => 'completed_dry_run', 'id' => 4],
            ['status' => 'completed_dry_run', 'id' => 5],
        ];

        $result = $store->capRegistry(['entries' => $entries], 4);

        self::assertCount(4, $result['entries']);
        // Live (queued, claimable) kept
        self::assertSame(1, $result['entries'][0]['id']);
        self::assertSame(2, $result['entries'][1]['id']);
        // Only 2 terminal entries kept (newest)
        self::assertSame(4, $result['entries'][2]['id']);
        self::assertSame(5, $result['entries'][3]['id']);
    }

    public function test_cap_registry_grows_past_cap_when_live_exceeds(): void
    {
        $store = $this->store();
        $entries = [
            ['status' => 'queued', 'id' => 1],
            ['status' => 'claimable', 'id' => 2],
            ['status' => 'claimed', 'id' => 3],
        ];

        $result = $store->capRegistry(['entries' => $entries], 2);

        // All 3 live entries preserved despite cap=2
        self::assertCount(3, $result['entries']);
    }

    public function test_cap_registry_never_hides_live_entries_above_hard_cap(): void
    {
        $store = $this->store();
        $entries = [];
        for ($i = 1; $i <= TaskQueueRegistryIndexStore::HARD_CAP + 25; $i++) {
            $entries[] = ['status' => 'queued', 'id' => $i];
        }
        for ($i = 1; $i <= 30; $i++) {
            $entries[] = ['status' => 'completed_dry_run', 'id' => 'done-'.$i];
        }

        $result = $store->capRegistry(['entries' => $entries], 200);

        self::assertCount(TaskQueueRegistryIndexStore::HARD_CAP + 25, $result['entries']);
        self::assertSame(1, $result['entries'][0]['id']);
        self::assertSame(TaskQueueRegistryIndexStore::HARD_CAP + 25, $result['entries'][TaskQueueRegistryIndexStore::HARD_CAP + 24]['id']);
    }

    public function test_cap_registry_no_op_under_cap(): void
    {
        $store = $this->store();
        $entries = [['status' => 'queued', 'id' => 1]];

        $result = $store->capRegistry(['entries' => $entries], 5);

        self::assertCount(1, $result['entries']);
    }

    // --- registerInRegistry deduplication ----------------------------

    public function test_register_in_registry_does_not_duplicate_equivalent_records(): void
    {
        $store = $this->store();
        $record = [
            'task_packet_id' => 'tp1',
            'task_packet_hash' => 'hash_a',
            'enqueued_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
            'status' => 'queued',
            'priority' => 5,
            'tags' => ['tag1'],
        ];

        $store->registerInRegistry($record);
        $store->registerInRegistry($record);

        $registry = $store->loadRegistry();
        self::assertCount(1, $registry['entries']);
        self::assertSame('tp1', $registry['entries'][0]['task_packet_id']);
        self::assertSame('hash_a', $registry['entries'][0]['task_packet_hash']);
    }

    public function test_register_in_registry_updates_in_place_when_packet_id_and_hash_match(): void
    {
        $store = $this->store();

        $store->registerInRegistry([
            'task_packet_id' => 'tp1',
            'task_packet_hash' => 'hash_a',
            'enqueued_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
            'status' => 'queued',
            'priority' => 5,
            'tags' => [],
        ]);

        // Same packet_id + same hash → update in place, not duplicate.
        $store->registerInRegistry([
            'task_packet_id' => 'tp1',
            'task_packet_hash' => 'hash_a',
            'enqueued_at' => '2026-01-01',
            'updated_at' => '2026-01-02',
            'status' => 'claimed',
            'priority' => 10,
            'tags' => ['new_tag'],
        ]);

        $registry = $store->loadRegistry();
        self::assertCount(1, $registry['entries']);
        self::assertSame('claimed', $registry['entries'][0]['status']);
        self::assertSame('2026-01-02', $registry['entries'][0]['updated_at']);
        self::assertSame(10, $registry['entries'][0]['priority']);
        self::assertSame(['new_tag'], $registry['entries'][0]['tags']);
    }

    public function test_register_in_registry_appends_when_packet_id_same_but_hash_differs(): void
    {
        $store = $this->store();

        $store->registerInRegistry([
            'task_packet_id' => 'tp1',
            'task_packet_hash' => 'hash_a',
            'enqueued_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
            'status' => 'queued',
            'priority' => 0,
            'tags' => [],
        ]);

        // Same packet_id but different hash → new entry (packet was revised).
        $store->registerInRegistry([
            'task_packet_id' => 'tp1',
            'task_packet_hash' => 'hash_b',
            'enqueued_at' => '2026-01-01',
            'updated_at' => '2026-01-02',
            'status' => 'queued',
            'priority' => 0,
            'tags' => [],
        ]);

        $registry = $store->loadRegistry();
        self::assertCount(2, $registry['entries']);
        self::assertSame('hash_a', $registry['entries'][0]['task_packet_hash']);
        self::assertSame('hash_b', $registry['entries'][1]['task_packet_hash']);
    }

    // --- updateRegistryEntry preserves unrelated entries --------------

    public function test_update_registry_entry_preserves_unrelated_entries(): void
    {
        $store = $this->store();

        $store->registerInRegistry([
            'task_packet_id' => 'tp1',
            'task_packet_hash' => 'h1',
            'enqueued_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
            'status' => 'queued',
            'priority' => 1,
            'tags' => [],
        ]);

        $store->registerInRegistry([
            'task_packet_id' => 'tp2',
            'task_packet_hash' => 'h2',
            'enqueued_at' => '2026-01-01',
            'updated_at' => '2026-01-01',
            'status' => 'queued',
            'priority' => 2,
            'tags' => [],
        ]);

        $store->updateRegistryEntry('tp1', [
            'task_packet_hash' => 'h1',
            'enqueued_at' => '2026-01-01',
            'updated_at' => '2026-01-03',
            'status' => 'claimed',
            'priority' => 1,
            'tags' => [],
        ]);

        $registry = $store->loadRegistry();
        self::assertCount(2, $registry['entries']);

        // tp1 was updated
        $tp1 = $registry['entries'][0];
        self::assertSame('tp1', $tp1['task_packet_id']);
        self::assertSame('claimed', $tp1['status']);
        self::assertSame('2026-01-03', $tp1['updated_at']);

        // tp2 was preserved unchanged
        $tp2 = $registry['entries'][1];
        self::assertSame('tp2', $tp2['task_packet_id']);
        self::assertSame('queued', $tp2['status']);
        self::assertSame('2026-01-01', $tp2['updated_at']);
        self::assertSame(2, $tp2['priority']);
    }

    // --- capRegistry determinism -------------------------------------

    public function test_cap_registry_is_deterministic(): void
    {
        $store = $this->store();
        $entries = [
            ['status' => 'queued', 'id' => 1],
            ['status' => 'completed_dry_run', 'id' => 2],
            ['status' => 'completed_dry_run', 'id' => 3],
            ['status' => 'claimable', 'id' => 4],
            ['status' => 'completed_dry_run', 'id' => 5],
        ];

        $a = $store->capRegistry(['entries' => $entries], 3);
        $b = $store->capRegistry(['entries' => $entries], 3);

        self::assertSame($a, $b);
    }
}
