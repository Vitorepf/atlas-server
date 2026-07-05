<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQueue;

/**
 * ITEM8 — the cohesive cluster of pure / stateless serialization + canonicalization helpers the queue
 * repository needs to hash task packets bit-identically and normalize string lists.
 *
 * Six methods, all migrated verbatim from AgentControlPlaneTaskPacketQueueRepository:
 *  - {@see self::recursivelyKsort}: deep ksort that preserves list vs assoc shape (ksort only on assoc).
 *  - {@see self::normalizePacketForHash}: strip volatile / identity fields, then deep ksort so the
 *    resulting JSON is bit-identical to what {@see \App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder}
 *    hashes for the same packet.
 *  - {@see self::contractMatchesDefault}: every default key is present in $current AND equals $default
 *    (extra keys in $current are tolerated — they do NOT trigger a non-conform verdict).
 *  - {@see self::stableHash}: SHA-256 of the canonical JSON (unescaped slashes + unicode, no pretty).
 *  - {@see self::encode}: canonical pretty JSON (used by appendReceipt's debug artefacts).
 *  - {@see self::stringList}: trim + non-empty filter + array_values on any list of mixed values.
 *
 * Pure / zero Laravel surface / zero side effects — extracted so the repository can split cohesive
 * canonicalization logic out of its public signature without changing ANY caller-visible byte.
 */
class TaskPacketCanonicalizer
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Volatile / identity fields that must NEVER participate in the packet hash.
     * Stripped before canonicalization so two packets with identical task-shaping
     * fields but different runtime metadata produce the same dedup hash.
     */
    private const VOLATILE_FIELDS = [
        'task_packet_id',
        'generated_at',
        'created_at',
        'updated_at',
        'resolved_at',
        'task_packet_hash',
        'human_summary',
        'lease_id',
        'process_id',
        'pid',
        'worker_id',
        'session_id',
        'attempt_count',
        'retry_count',
        'sequence',
        'run_id',
        'trace_id',
        'request_id',
        'correlation_id',
    ];

    /**
     * List-typed packet fields that are semantically UNORDERED (sets, not sequences).
     * Sorting these before hashing lets two packets with the same set of files but
     * different declaration order dedup correctly.
     *
     * IMPORTANT: acceptance_criteria and depends_on are intentionally NOT here —
     * their order carries semantic meaning (precedence, dependency chain).
     */
    private const UNORDERED_LIST_FIELDS = [
        'allowed_files',
        'required_evidence',
        'forbidden_files',
        'scope_in',
        'tags',
        'labels',
    ];

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $default
     */
    public function contractMatchesDefault(array $current, array $default): bool
    {
        foreach ($default as $key => $expected) {
            if (! array_key_exists($key, $current) || $current[$key] !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Mirror of AgentControlPlaneTaskPacketBuilder::normalizeForHash: strip volatile/identity fields,
     * sort semantically unordered lists, then deep-ksort so the resulting JSON is bit-identical to
     * what the builder hashes for the same packet.
     *
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    public function normalizePacketForHash(array $packet): array
    {
        foreach (self::VOLATILE_FIELDS as $field) {
            unset($packet[$field]);
        }

        // Sort semantically unordered list fields (allowed_files, required_evidence, etc.)
        // so set-equal packets dedup regardless of declaration order.
        foreach (self::UNORDERED_LIST_FIELDS as $field) {
            if (isset($packet[$field]) && is_array($packet[$field]) && array_is_list($packet[$field])) {
                $values = array_map('strval', $packet[$field]);
                sort($values);
                $packet[$field] = $values;
            }
        }

        return $this->recursivelyKsort($packet);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    public function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->recursivelyKsort($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        return (string) json_encode($payload, self::JSON_FLAGS | JSON_PRETTY_PRINT);
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    public function stableHash(array $payload): string
    {
        return hash('sha256', (string) json_encode($payload, self::JSON_FLAGS));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    public function stringList(array $values): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }
}
