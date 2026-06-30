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

    /**
     * Hard ceiling for the registry index.
     * The registry is an index — evicted entries remain on disk and can be
     * re-indexed via rebuildRegistryFromLeaseFiles(). Keeps the newest entries.
     */
    public const HARD_CAP = 500;

    /**
     * Raw-bytes threshold above which loadRegistry() uses the bounded (streaming)
     * path instead of json_decode() on the whole file.  Set below what HARD_CAP
     * entries would produce so a healthy file never triggers it.
     * 500 entries × ~700 bytes pretty-printed ≈ 350 KB → threshold = 400 KB.
     */
    private const MAX_REGISTRY_BYTES = 409600;

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

        // Bounded path: avoid a full json_decode() on a very large file — decoding
        // a 3 MB JSON registry into a PHP array can use 50–100 MB and OOM under
        // constrained limits.  Instead extract only the last HARD_CAP entry objects
        // using a character-level scanner, save the trimmed file (self-heal), and
        // return the small result.
        if (strlen($raw) > self::MAX_REGISTRY_BYTES) {
            $entries = $this->extractLastEntriesRaw($raw, self::HARD_CAP);
            $trimmed = ['entries' => $entries];
            $this->saveRegistry($trimmed);

            return $trimmed;
        }

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
     * Bound the registry in two tiers:
     *   1. Soft cap ($cap): evict oldest TERMINAL entries first.
     *   2. Hard cap (HARD_CAP): if live work alone still exceeds the absolute
     *      ceiling, evict oldest live entries too — the index is truncated, not
     *      the task files on disk.
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
        // Hard ceiling: evict oldest entries beyond the absolute max.
        if (count($entries) > self::HARD_CAP) {
            $entries = array_slice($entries, -self::HARD_CAP);
        }
        $registry['entries'] = $entries;
        unset($registry['corrupt']);

        return $registry;
    }

    /**
     * Memory-bounded entry extractor for oversized registry JSON.
     *
     * Scans the raw pretty-printed JSON character by character, tracking brace
     * depth and string boundaries to locate entry object boundaries without
     * calling json_decode() on the full file.  Individual entry objects
     * (~450 bytes each) are json_decoded one at a time, so peak memory is
     * proportional to $keep entries, not to the total file size.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractLastEntriesRaw(string $raw, int $keep): array
    {
        $len = strlen($raw);
        $entries = [];
        $depth = 0;
        $entryStart = -1;
        $inEntriesArray = false;
        $inString = false;
        $keepBuffer = $keep * 2; // intermediate bound; sliced to $keep at the end

        // Jump straight to the "entries" key to skip the outer wrapper.
        $seekPos = strpos($raw, '"entries"');
        if ($seekPos === false) {
            return [];
        }

        for ($i = $seekPos; $i < $len; $i++) {
            $ch = $raw[$i];

            // ── string tracking (skip brace/bracket counts inside strings) ──
            if ($inString) {
                if ($ch === '\\') {
                    $i++; // skip escaped character
                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                continue;
            }

            // ── find the opening [ of the entries array ──
            if (! $inEntriesArray) {
                if ($ch === '[') {
                    $inEntriesArray = true;
                }
                continue;
            }

            // ── inside the entries array ──
            if ($ch === '{') {
                if ($depth === 0) {
                    $entryStart = $i;
                }
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0 && $entryStart >= 0) {
                    $entryJson = substr($raw, $entryStart, $i - $entryStart + 1);
                    try {
                        $entry = json_decode($entryJson, true, flags: JSON_THROW_ON_ERROR);
                        if (is_array($entry)) {
                            $entries[] = $entry;
                            if (count($entries) > $keepBuffer) {
                                // Periodic trim keeps $entries bounded in memory.
                                $entries = array_slice($entries, -$keep);
                            }
                        }
                    } catch (Throwable) {
                        // skip corrupt individual entry
                    }
                    $entryStart = -1;
                }
            } elseif ($ch === ']' && $depth === 0) {
                break; // reached end of entries array
            }
        }

        return array_slice($entries, -$keep);
    }
}
