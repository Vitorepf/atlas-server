<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\TaskQueue\TaskPacketCanonicalizer;
use App\Services\Ai\SelfConstruction\TaskQueue\TaskQueueRegistryIndexStore;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature-level gate for TaskQueueRegistryIndexStore.
 *
 * Verifies missing/corrupt registry handling, register/update with cap,
 * and oversized registry self-healing via bounded path.
 */
final class TaskQueueRegistryIndexStoreTest extends TestCase
{
    private string $diskName;

    protected function setUp(): void
    {
        parent::setUp();
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

    // ── AC1: Missing registry returns entries=[], corrupt returns flag ───────

    public function test_missing_registry_returns_empty_entries(): void
    {
        $registry = $this->store()->loadRegistry();

        $this->assertSame(['entries' => []], $registry);
        $this->assertArrayNotHasKey('corrupt', $registry);
    }

    public function test_corrupt_json_returns_corrupt_flag_without_throwing(): void
    {
        Storage::disk($this->diskName)->put(
            TaskQueueRegistryIndexStore::REGISTRY_PATH,
            'not-json{{{',
        );

        $registry = $this->store()->loadRegistry();

        $this->assertTrue($registry['corrupt']);
        $this->assertSame([], $registry['entries']);
    }

    public function test_wrong_shape_returns_corrupt(): void
    {
        Storage::disk($this->diskName)->put(
            TaskQueueRegistryIndexStore::REGISTRY_PATH,
            '"just-a-string"',
        );

        $registry = $this->store()->loadRegistry();

        $this->assertTrue($registry['corrupt']);
    }

    // ── AC2: register and update preserve entries, cap, no packet deletion ──

    public function test_register_preserves_newest_and_caps(): void
    {
        $store = $this->store();

        // Fill beyond the soft cap (200 in registerInRegistry).
        for ($i = 0; $i < 210; $i++) {
            $store->registerInRegistry([
                'task_packet_id' => 'tp-' . $i,
                'task_packet_hash' => 'h' . $i,
                'enqueued_at' => '2026-01-01',
                'updated_at' => '2026-01-01',
                'status' => 'queued',
                'priority' => 5,
                'tags' => [],
            ]);
        }

        $registry = $store->loadRegistry();
        $this->assertLessThanOrEqual(TaskQueueRegistryIndexStore::HARD_CAP, count($registry['entries']));
        // Newest entry must be present.
        $ids = array_column($registry['entries'], 'task_packet_id');
        $this->assertContains('tp-209', $ids);
    }

    public function test_update_modifies_existing_entry(): void
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
        $this->assertCount(1, $registry['entries']);
        $this->assertSame('claimed', $registry['entries'][0]['status']);
    }

    // ── AC3: Oversized registry self-heals through bounded path ─────────────

    public function test_oversized_registry_trimmed_and_self_heals(): void
    {
        // Write a registry larger than MAX_REGISTRY_BYTES (409600).
        // ~370 bytes per entry × 1200 entries ≈ 440 KB.
        $entries = [];
        for ($i = 0; $i < 1200; $i++) {
            $entries[] = [
                'task_packet_id' => 'tp-bulk-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'task_packet_hash' => str_repeat('h', 40),
                'enqueued_at' => '2026-01-01T00:00:00+00:00',
                'updated_at' => '2026-01-01T00:00:00+00:00',
                'status' => 'queued',
                'priority' => 5,
                'tags' => ['bulk'],
            ];
        }
        $json = (new TaskPacketCanonicalizer)->encode(['entries' => $entries]);

        // Confirm it's oversized.
        $this->assertGreaterThan(409600, strlen($json));

        Storage::disk($this->diskName)->put(TaskQueueRegistryIndexStore::REGISTRY_PATH, $json);

        $registry = $this->store()->loadRegistry();

        // Self-healed: trimmed to HARD_CAP entries.
        $this->assertLessThanOrEqual(TaskQueueRegistryIndexStore::HARD_CAP, count($registry['entries']));
        $this->assertNotEmpty($registry['entries']);

        // The file on disk was rewritten (trimmed) by the bounded path.
        $rewritten = Storage::disk($this->diskName)->get(TaskQueueRegistryIndexStore::REGISTRY_PATH);
        $this->assertLessThan(strlen($json), strlen($rewritten));
    }
}
