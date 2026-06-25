<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Tiering;

use RuntimeException;
use Throwable;

/**
 * Persistent registry of declared per-client_id worker tier capacity. The single authority answering
 * "what tier can client X handle" — consulted by the Maestro routing policy.
 *
 * INVARIANTS:
 *   - Persistence is a single JSON snapshot under storage/atlas/maestro/worker-tier-registry.json
 *     (path overridable via constructor for tests).
 *   - WRITE-TEMP-THEN-RENAME — readers never see a torn write.
 *   - Snapshot key ordering: client_id ascending — byte-identical for same logical state.
 *   - declaredMaxTier ∈ {easy, hard, hardest} — anything else throws and IS NOT PERSISTED.
 */
final class AtlasMaestroWorkerTierRegistry
{
    public const ALLOWED_TIERS = [
        AtlasMaestroTaskTierClassifier::TIER_EASY,
        AtlasMaestroTaskTierClassifier::TIER_HARD,
        AtlasMaestroTaskTierClassifier::TIER_HARDEST,
    ];

    public function __construct(private readonly string $snapshotPath) {}

    /**
     * @param  array<string,mixed>  $meta
     * @return WorkerTierRecord
     */
    public function register(string $clientId, string $declaredMaxTier, array $meta = []): WorkerTierRecord
    {
        if ($clientId === '') {
            throw new RuntimeException('client_id is required');
        }
        if (! in_array($declaredMaxTier, self::ALLOWED_TIERS, true)) {
            throw new RuntimeException('declaredMaxTier outside allowed set: '.$declaredMaxTier);
        }
        $snapshot = $this->loadSnapshot();
        $snapshot[$clientId] = ['client_id' => $clientId, 'declared_max_tier' => $declaredMaxTier, 'meta' => $meta];
        $this->persistSnapshot($snapshot);

        return new WorkerTierRecord($clientId, $declaredMaxTier, $meta);
    }

    public function lookup(string $clientId): ?WorkerTierRecord
    {
        $snapshot = $this->loadSnapshot();
        if (! isset($snapshot[$clientId])) {
            return null;
        }
        $row = $snapshot[$clientId];

        return new WorkerTierRecord(
            (string) $row['client_id'],
            (string) $row['declared_max_tier'],
            (array) ($row['meta'] ?? []),
        );
    }

    /**
     * @return list<WorkerTierRecord>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->loadSnapshot() as $row) {
            $out[] = new WorkerTierRecord(
                (string) $row['client_id'],
                (string) $row['declared_max_tier'],
                (array) ($row['meta'] ?? []),
            );
        }

        return $out;
    }

    public function revoke(string $clientId): bool
    {
        $snapshot = $this->loadSnapshot();
        if (! isset($snapshot[$clientId])) {
            return false;
        }
        unset($snapshot[$clientId]);
        $this->persistSnapshot($snapshot);

        return true;
    }

    /**
     * @return array<string,array{client_id:string, declared_max_tier:string, meta:array<string,mixed>}>
     */
    private function loadSnapshot(): array
    {
        if (! is_file($this->snapshotPath)) {
            return [];
        }
        $raw = @file_get_contents($this->snapshotPath);
        if ($raw === false || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        if (! is_array($decoded)) {
            return [];
        }
        // The on-disk shape is a list ordered by client_id; reindex into the in-memory map.
        $map = [];
        foreach ($decoded as $row) {
            if (! is_array($row) || ! isset($row['client_id'])) {
                continue;
            }
            $map[(string) $row['client_id']] = [
                'client_id' => (string) $row['client_id'],
                'declared_max_tier' => (string) ($row['declared_max_tier'] ?? ''),
                'meta' => (array) ($row['meta'] ?? []),
            ];
        }

        return $map;
    }

    /**
     * @param  array<string,array{client_id:string, declared_max_tier:string, meta:array<string,mixed>}>  $snapshot
     */
    private function persistSnapshot(array $snapshot): void
    {
        $dir = dirname($this->snapshotPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // On-disk shape = list ordered by client_id ascending (deterministic).
        ksort($snapshot, SORT_STRING);
        $orderedList = array_values($snapshot);
        $json = (string) json_encode($orderedList, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $tmp = $this->snapshotPath.'.tmp.'.bin2hex(random_bytes(6));
        $written = @file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Cannot write Maestro worker-tier registry snapshot');
        }
        if (! @rename($tmp, $this->snapshotPath)) {
            @unlink($tmp);
            throw new RuntimeException('Cannot atomically rename Maestro worker-tier registry snapshot into place');
        }
    }
}

/**
 * Immutable VO returned by {@see AtlasMaestroWorkerTierRegistry::lookup()} and ::list().
 */
final class WorkerTierRecord
{
    /**
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $declaredMaxTier,
        public readonly array $meta = [],
    ) {}
}
