<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\SelfConstruction\TaskQueue\TaskPacketCanonicalizer;
use App\Services\Ai\SelfConstruction\TaskQueue\TaskQueueRegistryIndexStore;
use Illuminate\Filesystem\FilesystemAdapter;
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

    public function test_cap_registry_no_op_under_cap(): void
    {
        $store = $this->store();
        $entries = [['status' => 'queued', 'id' => 1]];

        $result = $store->capRegistry(['entries' => $entries], 5);

        self::assertCount(1, $result['entries']);
    }
}