<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\SelfConstruction\TaskQueue\TaskPacketCanonicalizer;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * Registry-index persistence for the Agent Control Plane task packet queue
 * repository.
 *
 * Extracted from AgentControlPlaneTaskPacketQueueRepository to reduce the
 * god-class. Pure / stateless (takes Filesystem + canonicalizer as constructor
 * arguments).
 */
final class TaskQueueRegistryIndexStore
{
    public const REGISTRY_PATH = 'atlas/self-construction/task-packet-queue-registry.json';

    public function __construct(
        private readonly Filesystem $disk,
        private readonly TaskPacketCanonicalizer $canonicalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public function registerInRegistry(array $record): void
    {
        $registry = $this->loadRegistry();
        $registry['entries'][] = [
            'task_packet_id' => (string) ($record['task_packet_id'] ?? ''),
            'task_packet_hash' => (string) ($record['task_packet_hash'] ?? ''),
            'enqueued_at' => (string) ($record['enqueued_at'] ?? ''),
            'updated_at' => (string) ($record['updated_at'] ?? ''),
            'status' => (string) ($record['status'] ?? ''),
            'priority' => (int) ($record['priority'] ?? 0),
            'tags' => (array) ($record['tags'] ?? []),
        ];
        $registry = $this->capRegistry($registry, 200);
        $this->saveRegistry($registry);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function updateRegistryEntry(string $taskPacketId, array $record, ?string $packetHashOverride = null): void
    {
        $registry = $this->loadRegistry();
        $entries = (array) ($registry['entries'] ?? []);
        $found = false;
        foreach ($entries as $i => $entry) {
            if ((string) ($entry['task_packet_id'] ?? '') === $taskPacketId) {
                $entries[$i]['status'] = (string) ($record['status'] ?? '');
                $entries[$i]['updated_at'] = (string) ($record['updated_at'] ?? '');
                if ($packetHashOverride !== null) {
                    $entries[$i]['task_packet_hash'] = $packetHashOverride;
                }
                $found = true;
                break;
            }
        }
        if (! $found) {
            $entries[] = [
                'task_packet_id' => $taskPacketId,
                'task_packet_hash' => (string) ($record['task_packet_hash'] ?? ''),
                'enqueued_at' => (string) ($record['enqueued_at'] ?? ''),
                'updated_at' => (string) ($record['updated_at'] ?? ''),
                'status' => (string) ($record['status'] ?? ''),
                'priority' => (int) ($record['priority'] ?? 0),
                'tags' => (array) ($record['tags'] ?? []),
            ];
        }
        $registry['entries'] = $entries;
        $this->saveRegistry($registry);
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, corrupt?: bool}
     */
    public function loadRegistry(): array
    {
        if (! $this->disk->exists(self::REGISTRY_PATH)) {
            return ['entries' => []];
        }
        $raw = (string) $this->disk->get(self::REGISTRY_PATH);
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['entries' => [], 'corrupt' => true];
        }
        if (! is_array($decoded) || ! isset($decoded['entries']) || ! is_array($decoded['entries'])) {
            return ['entries' => [], 'corrupt' => true];
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $registry
     */
    public function saveRegistry(array $registry): void
    {
        $this->disk->put(self::REGISTRY_PATH, $this->canonicalizer->encode($registry));
    }

    /**
     * Bound the registry WITHOUT ever losing live work. Keep ALL
     * non-terminal entries; evict only the oldest TERMINAL entries to fit the
     * cap. If live work alone exceeds the cap, the registry grows past it.
     *
     * @param  array<string, mixed>  $registry
     * @return array<string, mixed>
     */
    public function capRegistry(array $registry, int $cap): array
    {
        $entries = array_values((array) ($registry['entries'] ?? []));
        if ($cap > 0 && count($entries) > $cap) {
            $terminal = ['completed_dry_run', 'cancelled'];
            $live = [];
            $done = [];
            foreach ($entries as $entry) {
                if (in_array((string) ($entry['status'] ?? ''), $terminal, true)) {
                    $done[] = $entry;
                } else {
                    $live[] = $entry;
                }
            }
            $roomForTerminal = max(0, $cap - count($live));
            $done = $roomForTerminal > 0 ? array_slice($done, -$roomForTerminal) : [];
            $entries = array_merge($live, $done);
        }
        $registry['entries'] = $entries;
        unset($registry['corrupt']);

        return $registry;
    }
}