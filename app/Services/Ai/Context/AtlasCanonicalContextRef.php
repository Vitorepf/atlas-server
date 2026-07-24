<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;
use App\Support\CanonicalValue;

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

    private const SPAN_PATTERN = '/^((?:code|memory|graph):[a-f0-9]{32}):span:([a-f0-9]{16}):v:([a-f0-9]{12})$/';

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
            CanonicalValue::canonicalize($payload),
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

        foreach ((array) data_get($pack, 'obra_working_set.items', []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $ref = trim((string) ($item['ref'] ?? ''));
            if (self::isCanonical($ref)) {
                $refs[] = $ref;
            }
        }

        foreach ((array) data_get($pack, 'span_level_retrieval.claims', []) as $claim) {
            if (! is_array($claim)) {
                continue;
            }
            foreach ((array) ($claim['spans'] ?? []) as $span) {
                if (! is_array($span)) {
                    continue;
                }
                $ref = trim((string) ($span['span_ref'] ?? ''));
                if (self::isSpanRef($ref)) {
                    $refs[] = $ref;
                }
            }
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

    public static function spanRef(string $parentRef, string $contentVersion, int $start, int $end): string
    {
        $parentRef = trim($parentRef);
        $contentVersion = trim($contentVersion);
        $start = max(0, $start);
        $end = max($start, $end);
        $spanHash = substr(hash('sha256', $parentRef.'|'.$contentVersion.'|'.$start.'|'.$end), 0, 16);
        $versionHash = substr(hash('sha256', $contentVersion), 0, 12);

        return $parentRef.':span:'.$spanHash.':v:'.$versionHash;
    }

    public static function isSpanRef(string $ref): bool
    {
        return preg_match(self::SPAN_PATTERN, trim($ref)) === 1;
    }

    public static function parentRefFromSpanRef(string $ref): ?string
    {
        if (preg_match(self::SPAN_PATTERN, trim($ref), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public static function isMemoryRef(string $ref): bool
    {
        $ref = trim($ref);
        if ($ref === '') {
            return false;
        }

        if (preg_match('/^memory:[a-f0-9]{32}$/', $ref) === 1) {
            return true;
        }

        return str_starts_with(strtolower($ref), 'compounding_memory:');
    }

    /**
     * @param  array<string,mixed>  $item
     */
    public static function isCompoundingMemoryServedItem(array $item): bool
    {
        $sourceType = strtolower(trim((string) ($item['source_type'] ?? '')));

        return in_array($sourceType, ['ai_compounding_memory', 'compounding'], true)
            || str_starts_with($sourceType, 'compounding');
    }

    /**
     * Lift-compatible ref for AiCompoundingMemory served in the pack.
     *
     * @param  array<string,mixed>  $item
     */
    public static function compoundingMemoryLiftRef(array $item): ?string
    {
        if (! self::isCompoundingMemoryServedItem($item)) {
            return null;
        }

        foreach (['source_ref_id', 'source_id', 'memory_id', 'id', 'content_hash', 'memory_hash', 'candidate_id'] as $key) {
            $value = trim((string) ($item[$key] ?? ''));
            if ($value === '' || str_starts_with($value, 'ahri_')) {
                continue;
            }

            if (str_starts_with(strtolower($value), 'compounding_memory:')) {
                return self::normalize($value);
            }

            return 'compounding_memory:'.$value;
        }

        return null;
    }

    public static function normalizeCompoundingMemoryRef(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            return '';
        }

        if (str_starts_with(strtolower($ref), 'compounding_memory:')) {
            return self::normalize($ref);
        }

        if (str_contains($ref, ':')) {
            return '';
        }

        return 'compounding_memory:'.$ref;
    }

    /**
     * @return array<int,string>
     */
    public static function mentionForms(string $ref): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return [];
        }

        $forms = [$ref];
        $parentRef = self::parentRefFromSpanRef($ref);
        if ($parentRef !== null) {
            $forms[] = $parentRef;
        }
        if (preg_match('/^memory:([a-f0-9]{32})$/', $ref, $matches) === 1) {
            $forms[] = $matches[1];
        }

        if (str_contains($ref, ':')) {
            $suffix = substr(strstr($ref, ':') ?: '', 1);
            if ($suffix !== '') {
                $forms[] = $suffix;
            }
        }

        return self::uniqueStrings($forms);
    }

    public static function isMentionedInText(string $ref, string $text): bool
    {
        if ($text === '') {
            return false;
        }

        foreach (self::mentionForms($ref) as $form) {
            if (strlen($form) >= 4 && str_contains($text, $form)) {
                return true;
            }
        }

        return false;
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
