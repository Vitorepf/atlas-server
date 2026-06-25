<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Pinning;

use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use Closure;
use RuntimeException;

/**
 * Adds time-bounded pins on top of AtlasMaestroTaskPinningRegistry.
 *
 * TTL snapshot stores ONLY expires_at per packet_id — never duplicates the registry payload.
 * Read-side enforcement: effectivePinFor() lazy-sweeps expired entries before returning.
 *
 * Master-switch (AtlasTaskServingSwitch) OFF → byte-identical no-ops.
 */
final class AtlasMaestroTaskPinningTtlExpiry
{
    public const MIN_TTL_SECONDS = 1;
    public const MAX_TTL_SECONDS = 31_536_000;
    public const UNPIN_REASON = 'ttl-expiry';

    /** @var Closure():string */
    private Closure $now;

    /** @var Closure():bool */
    private Closure $switchEnabled;

    public function __construct(
        private readonly AtlasMaestroTaskPinningRegistry $registry,
        private readonly string $ttlSnapshotPath,
        ?callable $nowIso = null,
        ?callable $switchEnabled = null,
    ) {
        $dir = dirname($this->ttlSnapshotPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        $this->now = Closure::fromCallable($nowIso ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'));
        $this->switchEnabled = Closure::fromCallable($switchEnabled ?? static fn (): bool => AtlasTaskServingSwitch::enabled());
    }

    /** @return array<string,mixed>|null the underlying registry row, or null when OFF */
    public function pinWithTtl(string $packetId, string $workerId, string $reason, int $ttlSeconds): ?array
    {
        if (! ($this->switchEnabled)()) {
            return null;
        }
        if ($ttlSeconds < self::MIN_TTL_SECONDS || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new AtlasMaestroTaskPinningTtlException('ttl_out_of_range:'.$ttlSeconds);
        }

        $row = $this->registry->pin($packetId, $workerId, $reason);

        $state = $this->loadTtl();
        $state[$packetId] = ['expires_at' => $this->addSeconds($this->nowIso(), $ttlSeconds)];
        $this->saveTtl($state);

        return $row;
    }

    /** @return list<string> packet_ids removed, ASC */
    public function sweep(): array
    {
        if (! ($this->switchEnabled)()) {
            return [];
        }
        $state = $this->loadTtl();
        $now = $this->nowIso();
        $expired = [];
        foreach ($state as $packetId => $entry) {
            $exp = (string) ($entry['expires_at'] ?? '');
            if ($exp !== '' && strcmp($exp, $now) <= 0) {
                $this->registry->unpin($packetId);
                unset($state[$packetId]);
                $expired[] = $packetId;
            }
        }
        sort($expired, SORT_STRING);
        $this->saveTtl($state);

        return $expired;
    }

    /** @return array<string,mixed>|null */
    public function effectivePinFor(string $packetId): ?array
    {
        if (! ($this->switchEnabled)()) {
            return null;
        }
        $state = $this->loadTtl();
        if (isset($state[$packetId])) {
            $exp = (string) ($state[$packetId]['expires_at'] ?? '');
            if ($exp !== '' && strcmp($exp, $this->nowIso()) <= 0) {
                $this->registry->unpin($packetId);
                unset($state[$packetId]);
                $this->saveTtl($state);

                return null;
            }
        }

        return $this->registry->lookup($packetId);
    }

    private function nowIso(): string
    {
        return (string) ($this->now)();
    }

    private function addSeconds(string $iso, int $seconds): string
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            $ts = 0;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $ts + $seconds);
    }

    /** @return array<string,array<string,mixed>> */
    private function loadTtl(): array
    {
        if (! is_file($this->ttlSnapshotPath)) {
            return [];
        }
        $bytes = (string) @file_get_contents($this->ttlSnapshotPath);
        if ($bytes === '') {
            return [];
        }
        $decoded = json_decode($bytes, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,array<string,mixed>> $state */
    private function saveTtl(array $state): void
    {
        ksort($state);
        $bytes = (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp = $this->ttlSnapshotPath.'.tmp.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new RuntimeException('ttl_write_failed:'.$tmp);
        }
        if (! @rename($tmp, $this->ttlSnapshotPath)) {
            @unlink($tmp);
            throw new RuntimeException('ttl_rename_failed:'.$this->ttlSnapshotPath);
        }
    }
}
