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
     * Mirror of AgentControlPlaneTaskPacketBuilder::normalizeForHash: strip volatile/identity fields
     * then deep-ksort so the resulting JSON is bit-identical to what the builder hashes.
     *
     * @param  array<string, mixed>  $packet
     * @return array<string, mixed>
     */
    public function normalizePacketForHash(array $packet): array
    {
        unset($packet['task_packet_id'], $packet['generated_at'], $packet['task_packet_hash'], $packet['human_summary']);

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
