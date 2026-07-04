<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningRegistry;
use Tests\TestCase;

final class AtlasMaestroTaskPinningRegistryTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-pin-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    private function reg(string $now = '2026-06-25T05:00:00Z'): AtlasMaestroTaskPinningRegistry
    {
        return new AtlasMaestroTaskPinningRegistry($this->root.'/pins.json', fn () => $now);
    }

    public function test_pin_lookup_and_unpin_full_cycle(): void
    {
        $r = $this->reg();
        $row = $r->pin('pkt-A', 'worker-1', 'critical-path');
        $this->assertSame('pkt-A', $row['task_packet_id']);
        $this->assertSame('worker-1', $row['worker_id']);
        $this->assertSame('critical-path', $row['reason']);
        $this->assertSame('2026-06-25T05:00:00Z', $row['pinned_at']);
        $this->assertSame(hash('sha256', json_encode(['pkt-A', 'worker-1', 'critical-path'], JSON_THROW_ON_ERROR)), $row['pin_hash']);

        $this->assertSame($row, $r->lookup('pkt-A'));
        $this->assertNull($r->lookup('unknown'));

        $this->assertTrue($r->unpin('pkt-A'));
        $this->assertNull($r->lookup('pkt-A'));
        $this->assertFalse($r->unpin('pkt-A'));
    }

    public function test_idempotent_pin_returns_existing_entry(): void
    {
        $r = $this->reg('2026-06-25T05:00:00Z');
        $first = $r->pin('pkt-A', 'w1', 'r');

        $r2 = $this->reg('2026-06-25T06:00:00Z'); // newer "now", same backing file
        $second = $r2->pin('pkt-A', 'w1', 'r');

        $this->assertSame($first, $second, 'idempotent pin must not bump pinned_at');
    }

    public function test_snapshot_is_byte_stable_for_same_set(): void
    {
        $r = $this->reg();
        $r->pin('z', 'w', 'r');
        $r->pin('a', 'w', 'r');
        $r->pin('m', 'w', 'r');

        $r2 = new AtlasMaestroTaskPinningRegistry($this->root.'/pins2.json', fn () => '2026-06-25T05:00:00Z');
        $r2->pin('m', 'w', 'r');
        $r2->pin('a', 'w', 'r');
        $r2->pin('z', 'w', 'r');

        $this->assertSame($r->snapshotBytes(), $r2->snapshotBytes());
        $this->assertSame(['a', 'm', 'z'], array_keys($r->snapshot()));
    }

    public function test_one_active_pin_per_task_packet_id_replaces_on_different_hash(): void
    {
        $r = $this->reg();
        $r->pin('pkt', 'w1', 'r1');
        $second = $r->pin('pkt', 'w2', 'r2');

        $this->assertSame('w2', $second['worker_id']);
        $this->assertSame('r2', $second['reason']);
        $this->assertCount(1, $r->snapshot());
    }

    // ── inspect: active_pins, releasable_pins, receipt_hashes, stale_pin_count ──

    public function test_inspect_has_required_keys(): void
    {
        $r = $this->reg();
        $result = $r->inspect();
        $this->assertArrayHasKey('active_pins', $result);
        $this->assertArrayHasKey('releasable_pins', $result);
        $this->assertArrayHasKey('receipt_hashes', $result);
        $this->assertArrayHasKey('stale_pin_count', $result);
    }

    public function test_inspect_active_pin_with_ttl(): void
    {
        $r = new AtlasMaestroTaskPinningRegistry($this->root.'/pins_inspect.json', fn () => '2026-07-04T00:00:00Z');
        $r->pin('pkt-1', 'w1', 'capability_fit', 3600);
        $result = $r->inspect();
        $this->assertCount(1, $result['active_pins']);
        $this->assertCount(0, $result['releasable_pins']);
        $this->assertSame(0, $result['stale_pin_count']);
        $this->assertCount(1, $result['receipt_hashes']);
    }

    public function test_inspect_expired_pin_is_releasable(): void
    {
        $clock = new class { public $val = '2026-07-04T00:00:00Z'; };
        $r = new AtlasMaestroTaskPinningRegistry($this->root.'/pins_expired.json', fn () => $clock->val);
        $r->pin('pkt-1', 'w1', 'capability_fit', 1);
        $clock->val = '2026-07-04T00:00:05Z';
        $result = $r->inspect();
        $this->assertCount(0, $result['active_pins']);
        $this->assertCount(1, $result['releasable_pins']);
        $this->assertSame(1, $result['stale_pin_count']);
        $this->assertSame('ttl_expired', $result['releasable_pins'][0]['release_reason']);
    }

    public function test_inspect_receipt_hashes_are_deterministic(): void
    {
        $r = new AtlasMaestroTaskPinningRegistry($this->root.'/pins_hash.json', fn () => '2026-07-04T00:00:00Z');
        $r->pin('pkt-1', 'w1', 'capability_fit', 3600);
        $result1 = $r->inspect();
        $result2 = $r->inspect();
        $this->assertSame($result1['receipt_hashes'], $result2['receipt_hashes']);
    }

    public function test_mark_crashed_worker_pins_releases_them(): void
    {
        $r = new AtlasMaestroTaskPinningRegistry($this->root.'/pins_crash.json', fn () => '2026-07-04T00:00:00Z');
        $r->pin('pkt-1', 'crashed-worker', 'capability_fit', 3600);
        $r->pin('pkt-2', 'alive-worker', 'capability_fit', 3600);
        $releasable = $r->markCrashedWorkerPins(['crashed-worker']);
        $this->assertCount(1, $releasable);
        $this->assertSame('worker_crashed', $releasable[0]['release_reason']);
        $result = $r->inspect();
        $this->assertCount(1, $result['active_pins']);
        $this->assertSame('alive-worker', $result['active_pins'][0]['worker_id']);
    }

    public function test_mark_crashed_worker_pins_empty_when_no_match(): void
    {
        $r = new AtlasMaestroTaskPinningRegistry($this->root.'/pins_nocrash.json', fn () => '2026-07-04T00:00:00Z');
        $r->pin('pkt-1', 'w1', 'capability_fit', 3600);
        $releasable = $r->markCrashedWorkerPins(['nonexistent']);
        $this->assertCount(0, $releasable);
    }
}
