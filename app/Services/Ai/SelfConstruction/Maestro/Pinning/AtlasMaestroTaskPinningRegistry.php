<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Pinning;

use Closure;
use RuntimeException;

/**
 * Active-pin registry — one active pin per task_packet_id.
 *
 * Idempotent: pinning the same (task_packet_id, worker_id, reason) twice does not duplicate.
 * Deterministic snapshot: byte-identical bytes for byte-identical input set.
 */
final class AtlasMaestroTaskPinningRegistry
{
    public const SCHEMA = 'atlas.maestro.task_pin.v1';

    /** @var Closure():string */
    private Closure $now;

    public function __construct(
        private readonly string $snapshotPath,
        ?callable $nowIso = null,
    ) {
        $dir = dirname($this->snapshotPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        $this->now = Closure::fromCallable($nowIso ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'));
    }

    /** @return array<string,mixed> */
    public function pin(string $taskPacketId, string $workerId, string $reason, ?int $ttlSeconds = null): array
    {
        if ($taskPacketId === '' || $workerId === '') {
            throw new RuntimeException('pinning_requires_task_and_worker');
        }
        $state = $this->load();
        $existing = $state[$taskPacketId] ?? null;
        $pinHash = hash('sha256', json_encode([$taskPacketId, $workerId, $reason], JSON_THROW_ON_ERROR));
        if (is_array($existing) && (string) ($existing['pin_hash'] ?? '') === $pinHash) {
            return $existing;
        }
        $entry = [
            'pin_hash' => $pinHash,
            'pinned_at' => (string) ($this->now)(),
            'reason' => $reason,
            'task_packet_id' => $taskPacketId,
            'ttl_expires_at' => $ttlSeconds !== null ? $this->addSeconds((string) ($this->now)(), $ttlSeconds) : null,
            'worker_id' => $workerId,
        ];
        ksort($entry);
        $state[$taskPacketId] = $entry;
        $this->save($state);

        return $entry;
    }

    public function unpin(string $taskPacketId): bool
    {
        $state = $this->load();
        if (! array_key_exists($taskPacketId, $state)) {
            return false;
        }
        unset($state[$taskPacketId]);
        $this->save($state);

        return true;
    }

    /** @return array<string,mixed>|null */
    public function lookup(string $taskPacketId): ?array
    {
        $state = $this->load();
        $row = $state[$taskPacketId] ?? null;
        if (! is_array($row)) {
            return null;
        }
        $ttlExpiresAt = (string) ($row['ttl_expires_at'] ?? '');
        if ($ttlExpiresAt !== '' && strcmp($ttlExpiresAt, (string) ($this->now)()) <= 0) {
            unset($state[$taskPacketId]);
            $this->save($state);

            return null;
        }

        return $row;
    }

    /** @return array<string,array<string,mixed>> */
    public function snapshot(): array
    {
        $state = $this->load();
        ksort($state);

        return $state;
    }

    public function snapshotBytes(): string
    {
        return (string) json_encode($this->snapshot(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Audit surface: active_pins, releasable_pins, receipt_hashes, stale_pin_count.
     *
     * @return array{active_pins:list<array>, releasable_pins:list<array>, receipt_hashes:list<string>, stale_pin_count:int}
     */
    public function inspect(): array
    {
        $state = $this->load();
        $now = (string) ($this->now)();

        $activePins = [];
        $releasablePins = [];
        $receiptHashes = [];

        foreach ($state as $taskPacketId => $pin) {
            if (! is_array($pin)) {
                continue;
            }

            $ttlExpiresAt = (string) ($pin['ttl_expires_at'] ?? '');
            $isExpired = $ttlExpiresAt !== '' && strcmp($ttlExpiresAt, $now) <= 0;

            // Compute receipt hash for audit
            $receiptHash = hash('sha256', json_encode([
                'task_id' => $taskPacketId,
                'worker_id' => (string) ($pin['worker_id'] ?? ''),
                'expires_at' => $ttlExpiresAt,
                'reason' => (string) ($pin['reason'] ?? ''),
            ], JSON_THROW_ON_ERROR));
            $receiptHashes[] = $receiptHash;

            if ($isExpired) {
                $releasablePins[] = $pin + ['task_packet_id' => $taskPacketId, 'release_reason' => 'ttl_expired'];
            } else {
                $activePins[] = $pin + ['task_packet_id' => $taskPacketId];
            }
        }

        return [
            'active_pins' => $activePins,
            'releasable_pins' => $releasablePins,
            'receipt_hashes' => $receiptHashes,
            'stale_pin_count' => count($releasablePins),
        ];
    }

    /**
     * Mark pins for crashed workers as releasable.
     *
     * @param  list<string>  $crashedWorkerIds
     * @return list<array<string,mixed>>
     */
    public function markCrashedWorkerPins(array $crashedWorkerIds): array
    {
        $state = $this->load();
        $releasable = [];

        foreach ($crashedWorkerIds as $workerId) {
            foreach ($state as $taskPacketId => $pin) {
                if (! is_array($pin)) {
                    continue;
                }
                if ((string) ($pin['worker_id'] ?? '') === $workerId) {
                    $releasable[] = $pin + ['task_packet_id' => $taskPacketId, 'release_reason' => 'worker_crashed'];
                    unset($state[$taskPacketId]);
                }
            }
        }

        if ($releasable !== []) {
            $this->save($state);
        }

        return $releasable;
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
    private function load(): array
    {
        if (! is_file($this->snapshotPath)) {
            return [];
        }
        $bytes = (string) @file_get_contents($this->snapshotPath);
        if ($bytes === '') {
            return [];
        }
        $decoded = json_decode($bytes, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,array<string,mixed>> $state */
    private function save(array $state): void
    {
        ksort($state);
        $bytes = (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp = $this->snapshotPath.'.tmp.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes) === false) {
            throw new RuntimeException('pinning_write_failed:'.$tmp);
        }
        if (! @rename($tmp, $this->snapshotPath)) {
            @unlink($tmp);
            throw new RuntimeException('pinning_rename_failed:'.$this->snapshotPath);
        }
    }
}
