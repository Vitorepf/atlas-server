<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Pinning;

use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningRegistry;
use Tests\TestCase;

final class AtlasMaestroTaskPinningRegistryTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-pin-ttl-'.bin2hex(random_bytes(6));
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

    public function test_pin_record_includes_ttl_expires_at_and_reason(): void
    {
        $r = $this->reg('2026-06-25T05:00:00Z');
        $row = $r->pin('pkt-A', 'worker-1', 'critical-path', ttlSeconds: 3600);

        $this->assertSame('critical-path', $row['reason']);
        $this->assertSame('2026-06-25T06:00:00Z', $row['ttl_expires_at']);
    }

    public function test_pin_without_ttl_has_null_ttl_expires_at(): void
    {
        $r = $this->reg();
        $row = $r->pin('pkt-A', 'worker-1', 'critical-path');

        $this->assertNull($row['ttl_expires_at']);
    }

    public function test_expired_pin_is_omitted_from_lookup(): void
    {
        $path = $this->root.'/pins.json';
        $r = new AtlasMaestroTaskPinningRegistry($path, fn () => '2026-06-25T05:00:00Z');
        $r->pin('pkt-A', 'worker-1', 'short-lived', ttlSeconds: 60);

        // advance clock past expiry via a fresh instance bound to the same file.
        $rLater = new AtlasMaestroTaskPinningRegistry($path, fn () => '2026-06-25T05:05:00Z');

        $this->assertNull($rLater->lookup('pkt-A'));
    }

    public function test_non_expired_ttl_pin_is_still_returned_by_lookup(): void
    {
        $path = $this->root.'/pins.json';
        $r = new AtlasMaestroTaskPinningRegistry($path, fn () => '2026-06-25T05:00:00Z');
        $r->pin('pkt-A', 'worker-1', 'short-lived', ttlSeconds: 3600);

        $rSoon = new AtlasMaestroTaskPinningRegistry($path, fn () => '2026-06-25T05:05:00Z');

        $this->assertNotNull($rSoon->lookup('pkt-A'));
    }

    public function test_snapshot_bytes_is_deterministic_across_record_insertion_order(): void
    {
        $r = $this->reg();
        $r->pin('z', 'w', 'r', ttlSeconds: 100);
        $r->pin('a', 'w', 'r');
        $r->pin('m', 'w', 'r', ttlSeconds: 200);

        $r2 = new AtlasMaestroTaskPinningRegistry($this->root.'/pins2.json', fn () => '2026-06-25T05:00:00Z');
        $r2->pin('m', 'w', 'r', ttlSeconds: 200);
        $r2->pin('a', 'w', 'r');
        $r2->pin('z', 'w', 'r', ttlSeconds: 100);

        $this->assertSame($r->snapshotBytes(), $r2->snapshotBytes());
    }

    public function test_pin_lookup_and_unpin_full_cycle_still_works_without_ttl(): void
    {
        $r = $this->reg();
        $row = $r->pin('pkt-A', 'worker-1', 'critical-path');
        $this->assertSame('pkt-A', $row['task_packet_id']);
        $this->assertSame($row, $r->lookup('pkt-A'));
        $this->assertTrue($r->unpin('pkt-A'));
        $this->assertNull($r->lookup('pkt-A'));
    }
}
