<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningRegistry;
use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningTtlException;
use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningTtlExpiry;
use Tests\TestCase;

final class AtlasMaestroTaskPinningTtlExpiryTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-ttl-'.bin2hex(random_bytes(6));
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

    private function build(string $now, bool $switchOn = true): array
    {
        $reg = new AtlasMaestroTaskPinningRegistry($this->root.'/pins.json', fn () => $now);
        $clock = function () use (&$now) { return $now; };
        $ttl = new AtlasMaestroTaskPinningTtlExpiry(
            $reg,
            $this->root.'/ttl.json',
            $clock,
            fn () => $switchOn,
        );

        return [$reg, $ttl, function (string $newNow) use (&$now) { $now = $newNow; }];
    }

    public function test_pin_with_ttl_expires_via_effective_pin_for_lazy_sweep(): void
    {
        [$reg, $ttl, $advance] = $this->build('2026-06-25T05:00:00Z');

        $row = $ttl->pinWithTtl('PKT-X', 'worker_codex', 'short-lease', 60);
        $this->assertNotNull($row);
        $this->assertSame('worker_codex', $row['worker_id']);

        $effective = $ttl->effectivePinFor('PKT-X');
        $this->assertNotNull($effective);
        $this->assertSame('worker_codex', $effective['worker_id']);

        $advance('2026-06-25T05:02:00Z'); // +120s, well past 60s TTL
        $this->assertNull($ttl->effectivePinFor('PKT-X'));
        $this->assertNull($reg->lookup('PKT-X'), 'lazy sweep should have removed the registry pin');
    }

    public function test_sweep_returns_only_expired_ids_in_asc_order(): void
    {
        [$reg, $ttl, $advance] = $this->build('2026-06-25T05:00:00Z');
        $ttl->pinWithTtl('zebra', 'w', 'r', 60);
        $ttl->pinWithTtl('apple', 'w', 'r', 60);
        $ttl->pinWithTtl('mango', 'w', 'r', 36000);

        $advance('2026-06-25T05:02:00Z');
        $expired = $ttl->sweep();
        $this->assertSame(['apple', 'zebra'], $expired);
        $this->assertNull($reg->lookup('apple'));
        $this->assertNull($reg->lookup('zebra'));
        $this->assertNotNull($reg->lookup('mango'));
    }

    public function test_out_of_range_ttl_throws_with_offending_value(): void
    {
        [, $ttl] = $this->build('2026-06-25T05:00:00Z');
        foreach ([0, -1, 31_536_001] as $bad) {
            try {
                $ttl->pinWithTtl('p', 'w', 'r', $bad);
                $this->fail("expected throw for ttl=$bad");
            } catch (AtlasMaestroTaskPinningTtlException $e) {
                $this->assertStringContainsString((string) $bad, $e->getMessage());
            }
        }
    }

    public function test_switch_off_produces_no_filesystem_mutations_and_returns_null_or_empty(): void
    {
        [, $ttl] = $this->build('2026-06-25T05:00:00Z', switchOn: false);

        $listBefore = $this->dirSnap();
        $this->assertNull($ttl->pinWithTtl('p', 'w', 'r', 60));
        $this->assertSame([], $ttl->sweep());
        $this->assertNull($ttl->effectivePinFor('p'));
        $listAfter = $this->dirSnap();
        $this->assertSame($listBefore, $listAfter, 'switch-off must not mutate filesystem');
    }

    /** @return list<string> */
    private function dirSnap(): array
    {
        $files = (array) glob($this->root.'/*');
        $rows = [];
        foreach ($files as $f) {
            $rows[] = $f.':'.(string) @sha1_file((string) $f);
        }
        sort($rows);

        return $rows;
    }
}
