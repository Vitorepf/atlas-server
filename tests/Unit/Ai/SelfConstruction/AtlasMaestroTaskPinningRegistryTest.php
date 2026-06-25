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
        $this->assertSame(hash('sha256', 'pkt-A:worker-1:critical-path'), $row['pin_hash']);

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
}
