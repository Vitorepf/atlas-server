<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\Pinning;

use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningRegistry;
use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningTtlException;
use App\Services\Ai\SelfConstruction\Maestro\Pinning\AtlasMaestroTaskPinningTtlExpiry;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract test using a file-backed fake registry (real AtlasMaestroTaskPinningRegistry
 * pointed at a scratch snapshot — a pure test double, no live queue/provider surface). Proves:
 * master-switch off returns no-ops, out-of-range TTL throws, pinWithTtl writes only an
 * expires_at snapshot per packet, sweep unpins expired packet ids in deterministic ASC order,
 * effectivePinFor lazy-expires stale pins, lastLazyExpiryReceipt reports the lazy expiry, and
 * corrupt/missing TTL snapshot data never crashes read-side enforcement.
 */
final class AtlasMaestroTaskPinningTtlExpiryTest extends TestCase
{
    private string $registryPath = '';

    private string $ttlPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir().'/atlas-maestro-ttl-'.bin2hex(random_bytes(6));
        @mkdir($dir, 0o755, true);
        $this->registryPath = $dir.'/registry.json';
        $this->ttlPath = $dir.'/ttl.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->registryPath);
        @unlink($this->ttlPath);
        parent::tearDown();
    }

    private function expiry(callable $now, bool $switchOn = true): AtlasMaestroTaskPinningTtlExpiry
    {
        $registry = new AtlasMaestroTaskPinningRegistry($this->registryPath, $now);

        return new AtlasMaestroTaskPinningTtlExpiry($registry, $this->ttlPath, $now, static fn (): bool => $switchOn);
    }

    private function clockAt(string $iso): callable
    {
        return static fn (): string => $iso;
    }

    public function test_master_switch_off_returns_no_ops_for_every_method(): void
    {
        $expiry = $this->expiry($this->clockAt('2026-06-25T00:00:00Z'), switchOn: false);

        self::assertNull($expiry->pinWithTtl('pk-1', 'worker-1', 'test', 60));
        self::assertSame([], $expiry->sweep());
        self::assertNull($expiry->effectivePinFor('pk-1'));
        self::assertFileDoesNotExist($this->ttlPath);
    }

    public function test_ttl_out_of_range_throws(): void
    {
        $expiry = $this->expiry($this->clockAt('2026-06-25T00:00:00Z'));

        $this->expectException(AtlasMaestroTaskPinningTtlException::class);
        $expiry->pinWithTtl('pk-1', 'worker-1', 'test', 0);
    }

    public function test_ttl_above_max_throws(): void
    {
        $expiry = $this->expiry($this->clockAt('2026-06-25T00:00:00Z'));

        $this->expectException(AtlasMaestroTaskPinningTtlException::class);
        $expiry->pinWithTtl('pk-1', 'worker-1', 'test', AtlasMaestroTaskPinningTtlExpiry::MAX_TTL_SECONDS + 1);
    }

    public function test_pin_with_ttl_writes_only_expires_at_snapshot(): void
    {
        $expiry = $this->expiry($this->clockAt('2026-06-25T00:00:00Z'));
        $expiry->pinWithTtl('pk-1', 'worker-1', 'test', 60);

        $snapshot = json_decode((string) file_get_contents($this->ttlPath), true);

        self::assertSame(['expires_at'], array_keys($snapshot['pk-1']));
        self::assertSame('2026-06-25T00:01:00Z', $snapshot['pk-1']['expires_at']);
    }

    public function test_sweep_unpins_expired_packet_ids_in_deterministic_ascending_order(): void
    {
        $expiry = $this->expiry($this->clockAt('2026-06-25T00:00:00Z'));
        $expiry->pinWithTtl('pk-zebra', 'worker-1', 'test', 1);
        $expiry->pinWithTtl('pk-alpha', 'worker-1', 'test', 1);
        $expiry->pinWithTtl('pk-not-expired', 'worker-1', 'test', 3600);

        $laterExpiry = $this->expiry($this->clockAt('2026-06-25T00:00:10Z'));
        $receipts = $laterExpiry->sweep();

        self::assertSame(['pk-alpha', 'pk-zebra'], array_column($receipts, 'packet_id'));
        foreach ($receipts as $receipt) {
            self::assertSame(AtlasMaestroTaskPinningTtlExpiry::UNPIN_REASON, $receipt['reason']);
        }

        $registry = new AtlasMaestroTaskPinningRegistry($this->registryPath, $this->clockAt('2026-06-25T00:00:10Z'));
        self::assertNull($registry->lookup('pk-alpha'));
        self::assertNull($registry->lookup('pk-zebra'));
        self::assertNotNull($registry->lookup('pk-not-expired'));
    }

    public function test_effective_pin_for_lazy_expires_stale_pins_and_reports_receipt(): void
    {
        $expiry = $this->expiry($this->clockAt('2026-06-25T00:00:00Z'));
        $expiry->pinWithTtl('pk-1', 'worker-1', 'test', 1);

        $laterExpiry = $this->expiry($this->clockAt('2026-06-25T00:00:10Z'));
        self::assertNull($laterExpiry->lastLazyExpiryReceipt());

        $pin = $laterExpiry->effectivePinFor('pk-1');

        self::assertNull($pin);
        $receipt = $laterExpiry->lastLazyExpiryReceipt();
        self::assertNotNull($receipt);
        self::assertSame('pk-1', $receipt['packet_id']);
        self::assertSame(AtlasMaestroTaskPinningTtlExpiry::UNPIN_REASON, $receipt['reason']);

        // The pin was removed through the registry (unpin), not by mutating any queue record.
        $registry = new AtlasMaestroTaskPinningRegistry($this->registryPath, $this->clockAt('2026-06-25T00:00:10Z'));
        self::assertNull($registry->lookup('pk-1'));
    }

    public function test_effective_pin_for_returns_live_pin_and_clears_prior_lazy_expiry_receipt(): void
    {
        $expiry = $this->expiry($this->clockAt('2026-06-25T00:00:00Z'));
        $expiry->pinWithTtl('pk-1', 'worker-1', 'test', 3600);

        $pin = $expiry->effectivePinFor('pk-1');

        self::assertIsArray($pin);
        self::assertSame('pk-1', $pin['task_packet_id']);
        self::assertNull($expiry->lastLazyExpiryReceipt());
    }

    public function test_corrupt_ttl_snapshot_data_does_not_crash_read_side_enforcement(): void
    {
        file_put_contents($this->ttlPath, 'not-valid-json{{{');
        $expiry = $this->expiry($this->clockAt('2026-06-25T00:00:00Z'));

        self::assertNull($expiry->effectivePinFor('pk-1'));
        self::assertSame([], $expiry->sweep());
    }

    public function test_missing_ttl_snapshot_data_does_not_crash_read_side_enforcement(): void
    {
        // No file has been created at all yet — must be treated as empty state.
        $expiry = $this->expiry($this->clockAt('2026-06-25T00:00:00Z'));

        self::assertFileDoesNotExist($this->ttlPath);
        self::assertNull($expiry->effectivePinFor('pk-1'));
        self::assertSame([], $expiry->sweep());
    }

    public function test_sweep_and_lazy_expiry_remove_stale_pins_through_registry_not_direct_mutation(): void
    {
        $expiry = $this->expiry($this->clockAt('2026-06-25T00:00:00Z'));
        $expiry->pinWithTtl('pk-1', 'worker-1', 'test', 1);
        $expiry->pinWithTtl('pk-2', 'worker-1', 'test', 1);

        $registryBeforeExpiry = new AtlasMaestroTaskPinningRegistry($this->registryPath, $this->clockAt('2026-06-25T00:00:00Z'));
        self::assertNotNull($registryBeforeExpiry->lookup('pk-1'));
        self::assertNotNull($registryBeforeExpiry->lookup('pk-2'));

        $laterExpiry = $this->expiry($this->clockAt('2026-06-25T00:00:10Z'));
        $laterExpiry->effectivePinFor('pk-1'); // lazy expiry path
        $laterExpiry->sweep(); // sweep path

        $registryAfter = new AtlasMaestroTaskPinningRegistry($this->registryPath, $this->clockAt('2026-06-25T00:00:10Z'));
        self::assertNull($registryAfter->lookup('pk-1'), 'lazy expiry must remove the pin through the registry');
        self::assertNull($registryAfter->lookup('pk-2'), 'sweep must remove the pin through the registry');
    }
}
