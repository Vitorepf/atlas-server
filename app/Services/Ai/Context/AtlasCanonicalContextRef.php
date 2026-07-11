<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

/**
 * COM-01 / X-03 — the UNIQUE owner of the AOBG context-ref namespace.
 *
 * Canonical hashed forms: code:<32hex>, memory:<32hex>, graph:<32hex>.
 * Plain refs pass through unchanged when already canonical or explicitly provided.
 */
final class AtlasCanonicalContextRef
{
    public const SCHEMA = 'atlas.context_ref.v1';

    private const CANONICAL_PATTERN = '/^(code|memory|graph):[a-f0-9]{32}$/';

    /**
     * @param  array<string,mixed>  $item
     */
    public static function fromCodeItem(array $item): string
    {
        return self::hashed('code', [
            'id' => (string) ($item['id'] ?? ''),
            'file_path' => (string) ($item['file_path'] ?? ''),
            'symbol_type' => (string) ($item['symbol_type'] ?? ''),
        ]);
    }

    /**
     * @param  array<string,mixed>  $path
     */
    public static function fromGraphPath(array $path): string
    {
        return self::hashed('graph', [
            'source' => (string) ($path['source'] ?? ''),
            'target' => (string) ($path['target'] ?? ''),
            'hops' => self::normalizeHops($path['hops'] ?? []),
        ]);
    }

    /**
     * @param  array<string,mixed>  $item
     */
    public static function fromMemoryItem(array $item): string
    {
        $contentHash = trim((string) ($item['content_hash'] ?? ''));

        return $contentHash !== ''
            ? 'memory:'.substr(hash('sha256', $contentHash), 0, 32)
            : self::hashed('memory', [
                'type' => (string) ($item['type'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
            ]);
    }

    public static function hashed(string $type, mixed $payload): string
    {
        return $type.':'.substr(hash('sha256', (string) json_encode(
            self::canonicalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )), 0, 32);
    }

    /**
     * @param  array<string,mixed>  $pack
     * @return array<int,string>
     */
    public static function deliveredFromPack(array $pack, int $limit = 32): array
    {
        $refs = [];

        foreach ((array) ($pack['code_graph'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $refs[] = self::fromCodeItem($item);
        }

        foreach ((array) ($pack['reality_graph_paths'] ?? []) as $path) {
            if (! is_array($path)) {
                continue;
            }
            $refs[] = self::fromGraphPath($path);
        }

        foreach ((array) ($pack['memory'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $refs[] = self::fromMemoryItem($item);
        }

        return array_slice(self::uniqueStrings($refs), 0, max(0, $limit));
    }

    /**
     * All matchable forms for a memory item (canonical + legacy plain refs).
     *
     * @param  array<string,mixed>  $item
     * @return array<int,string>
     */
    public static function memoryItemForms(array $item): array
    {
        $hash = trim((string) ($item['content_hash'] ?? ''));
        $sourceType = trim((string) ($item['source_type'] ?? ''));

        return self::uniqueStrings([
            $hash,
            $hash !== '' ? 'memory:'.substr(hash('sha256', $hash), 0, 32) : '',
            $sourceType !== '' && $hash !== '' ? $sourceType.':'.$hash : '',
            self::hashed('memory', [
                'type' => (string) ($item['type'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
            ]),
            (string) ($item['title'] ?? ''),
        ]);
    }

    public static function isCanonical(string $ref): bool
    {
        return preg_match(self::CANONICAL_PATTERN, trim($ref)) === 1;
    }

    public static function normalize(string $ref): string
    {
        $ref = trim($ref);

        return $ref;
    }

    /**
     * @param  array<int,string>  $refs
     * @return array<int,string>
     */
    public static function uniqueStrings(array $refs): array
    {
        return array_values(array_unique(array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $refs,
        ), static fn (string $value): bool => $value !== ''))));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonicalize($item), $value);
        }

        ksort($value);

        return array_map(static fn (mixed $item): mixed => self::canonicalize($item), $value);
    }

    private static function normalizeHops(mixed $hops): array
    {
        $out = [];
        foreach ((array) $hops as $hop) {
            if (! is_array($hop)) {
                continue;
            }
            $out[] = [
                'from' => (string) ($hop['from'] ?? ''),
                'to' => (string) ($hop['to'] ?? ''),
                'edge_kind' => (string) ($hop['edge_kind'] ?? ''),
                'confidence' => round((float) ($hop['confidence'] ?? 0.0), 4),
                'direction' => (string) ($hop['direction'] ?? ''),
            ];
        }

        return $out;
    }
}
