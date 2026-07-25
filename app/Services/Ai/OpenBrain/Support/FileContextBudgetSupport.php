<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrain\Support;

/**
 * Pure helpers for {@see \App\Services\Ai\AtlasOpenBrainFileContextService}
 * (full-pass peel): total budget ceiling, *Chars sizing, neighbor item shape,
 * markdown render, relevance needles / mentionsAny, basename / stem, and
 * relative-path string ops. No DB, no workspace identity, no I/O.
 */
final class FileContextBudgetSupport
{
    /**
     * Enforce the total char ceiling on the MEASURED, assembled delta. Trims
     * trailing items — consumers first, then defined, then memory, then reality
     * paths — keeping at least the top hit of each non-empty section. When
     * $totalBudget <= 0 (uncapped) returns the sections unchanged.
     *
     * @param  array{present:bool, defined:list<array<string,mixed>>, consumers:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $neighbors
     * @param  array{present:bool, items:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $memory
     * @param  array{present:bool, paths:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}  $reality
     * @return array{0:array{present:bool, defined:list<array<string,mixed>>, consumers:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}, 1:array{present:bool, items:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}, 2:array{present:bool, paths:list<array<string,mixed>>, chars:int, provenance:array<string,mixed>}}
     */
    public static function enforceTotalCeiling(int $totalBudget, array $neighbors, array $memory, array $reality): array
    {
        if ($totalBudget <= 0) {
            return [$neighbors, $memory, $reality];
        }

        $total = static fn (): int => (int) $neighbors['chars'] + (int) $memory['chars'] + (int) $reality['chars'];

        // 1) Drop trailing CONSUMERS (keep >= 1 if any).
        while ($total() > $totalBudget && count($neighbors['consumers']) > 1) {
            array_pop($neighbors['consumers']);
            $neighbors['consumers'] = array_values($neighbors['consumers']);
            $neighbors['chars'] = self::neighborsChars($neighbors);
        }
        // 2) Drop trailing DEFINED (keep >= 1 if any).
        while ($total() > $totalBudget && count($neighbors['defined']) > 1) {
            array_pop($neighbors['defined']);
            $neighbors['defined'] = array_values($neighbors['defined']);
            $neighbors['chars'] = self::neighborsChars($neighbors);
        }
        // 3) Drop trailing MEMORY (keep >= 1 if any).
        while ($total() > $totalBudget && count($memory['items']) > 1) {
            array_pop($memory['items']);
            $memory['items'] = array_values($memory['items']);
            $memory['chars'] = self::memoryItemsChars($memory['items']);
        }
        // 4) Drop trailing PATHS (keep >= 1 if any).
        while ($total() > $totalBudget && count($reality['paths']) > 1) {
            array_pop($reality['paths']);
            $reality['paths'] = array_values($reality['paths']);
            $reality['chars'] = self::pathsChars($reality['paths']);
        }

        $neighbors['present'] = $neighbors['defined'] !== [] || $neighbors['consumers'] !== [];
        $memory['present'] = $memory['items'] !== [];
        $reality['present'] = $reality['paths'] !== [];

        return [$neighbors, $memory, $reality];
    }

    /**
     * @param  array{defined:list<array<string,mixed>>, consumers:list<array<string,mixed>>}  $neighbors
     */
    public static function neighborsChars(array $neighbors): int
    {
        $chars = 0;
        foreach (array_merge($neighbors['defined'], $neighbors['consumers']) as $item) {
            $chars += self::neighborItemChars($item);
        }

        return $chars;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    public static function neighborItemChars(array $item): int
    {
        return strlen(
            (string) ($item['symbol_name'] ?? '')
            .(string) ($item['file_path'] ?? '')
            .(string) ($item['signature'] ?? ''),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    public static function memoryItemsChars(array $items): int
    {
        $chars = 0;
        foreach ($items as $item) {
            $chars += strlen(
                (string) ($item['title'] ?? '')
                .(string) ($item['summary'] ?? '')
                .(string) ($item['body'] ?? ''),
            );
        }

        return $chars;
    }

    /**
     * @param  list<array<string,mixed>>  $paths
     */
    public static function pathsChars(array $paths): int
    {
        $chars = 0;
        foreach ($paths as $path) {
            $chars += strlen((string) json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return $chars;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    public static function neighborItem(array $row): array
    {
        return [
            'symbol_name' => (string) ($row['symbol_name'] ?? ''),
            'symbol_type' => (string) ($row['symbol_type'] ?? ''),
            'file_path' => (string) ($row['file_path'] ?? ''),
            'module_slug' => (string) ($row['module_slug'] ?? ''),
            'signature' => (string) ($row['signature'] ?? ''),
        ];
    }

    /**
     * Render the brain-delta as the compact markdown block the hook injects.
     * Empty sections are labelled honestly (never silently dropped) so the
     * consumer can tell "the brain has nothing here" from "not consulted".
     *
     * @param  array<string,mixed>  $delta
     */
    public static function renderMarkdown(array $delta, string $honestyLabel): string
    {
        $lines = [];
        $lines[] = '# Atlas brain — what is known about this file (AOBG)';
        $lines[] = sprintf(
            'file=%s  workspace=%s  provider-bound=yes  %s',
            (string) ($delta['path'] ?? ''),
            (string) ($delta['workspace'] ?? ''),
            $honestyLabel,
        );
        $lines[] = '';

        // 1) decisions / missions (AURG paths) — the "this file is governed by".
        $lines[] = '## Decisions / missions touching this file (reality graph)';
        $paths = (array) ($delta['reality_graph_paths'] ?? []);
        if ($paths === []) {
            $lines[] = '_no provider-safe paths from the brain for this file_';
        } else {
            foreach ($paths as $path) {
                $chain = array_map(
                    static fn (array $n): string => (string) (($n['label'] ?? '') !== '' ? $n['label'] : ($n['id'] ?? '')),
                    (array) ($path['chain'] ?? []),
                );
                $lines[] = sprintf(
                    '- %s%s',
                    implode(' -> ', $chain),
                    ($path['cross_layer'] ?? false) ? '  (cross-layer)' : '',
                );
            }
        }
        $lines[] = '';

        // 2) memory / decisions referencing the file (redacted).
        $lines[] = '## Memory referencing this file (provider-safe, redacted)';
        $memory = (array) ($delta['memory'] ?? []);
        if ($memory === []) {
            $lines[] = '_no provider-safe memory recalled for this file_';
        } else {
            foreach ($memory as $item) {
                $title = (string) ($item['title'] ?? '');
                $summary = trim((string) ($item['summary'] ?? ($item['body'] ?? '')));
                $lines[] = sprintf(
                    '- [%s] %s%s',
                    ($item['type'] ?? '') !== '' ? (string) $item['type'] : 'memory',
                    $title !== '' ? $title : '(untitled)',
                    $summary !== '' ? ' — '.mb_substr($summary, 0, 180) : '',
                );
            }
        }
        $lines[] = '';

        // 3) consumed by Z (code neighbors).
        $lines[] = '## Consumed by (code-graph neighbors)';
        $consumers = (array) ($delta['consumers'] ?? []);
        if ($consumers === []) {
            $lines[] = '_no known consumers in this workspace index_';
        } else {
            foreach ($consumers as $item) {
                $lines[] = sprintf(
                    '- %s [%s]%s',
                    (string) ($item['symbol_name'] ?? ''),
                    (string) ($item['file_path'] ?? 'n/a'),
                    ($item['symbol_type'] ?? '') !== '' ? ' type='.(string) $item['symbol_type'] : '',
                );
            }
        }
        $lines[] = '';

        // 4) symbols defined here (orientation).
        $lines[] = '## Symbols defined in this file';
        $defined = (array) ($delta['defined_symbols'] ?? []);
        if ($defined === []) {
            $lines[] = '_file not in the workspace code index_';
        } else {
            foreach ($defined as $item) {
                $lines[] = sprintf(
                    '- %s (%s)',
                    (string) ($item['symbol_name'] ?? ''),
                    ($item['symbol_type'] ?? '') !== '' ? (string) $item['symbol_type'] : 'symbol',
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Reduce an absolute path to a workspace-relative one when it sits under one
     * of the known roots. Already-relative paths are normalised (leading "./" +
     * slashes). Pure string ops — roots are collected by the service (opts / base_path).
     *
     * @param  list<string>  $roots
     */
    public static function relativePathAgainstRoots(string $path, array $roots): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }
        $path = preg_replace('#^\./#', '', $path) ?? $path;

        foreach ($roots as $root) {
            $root = rtrim(str_replace('\\', '/', (string) $root), '/');
            if ($root !== '' && str_starts_with($path, $root.'/')) {
                return ltrim(substr($path, strlen($root) + 1), '/');
            }
        }

        return ltrim($path, '/');
    }

    /**
     * The literal needles a memory must mention to count as "about this file":
     * the class/stem, the basename, and the relative path. Lowercased + deduped;
     * sub-3-char tokens dropped (too generic to gate on). Empty list disables the
     * floor (so a path-less / pathological input never starves the recall to zero).
     *
     * @return list<string>
     */
    public static function relevanceNeedles(string $stem, string $relPath): array
    {
        $needles = [];
        foreach ([$stem, self::basename($relPath), $relPath] as $candidate) {
            $candidate = mb_strtolower(trim((string) $candidate));
            if ($candidate !== '' && mb_strlen($candidate) >= 3 && ! in_array($candidate, $needles, true)) {
                $needles[] = $candidate;
            }
        }

        return $needles;
    }

    /**
     * @param  list<string>  $needles
     */
    public static function mentionsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function basename(string $relPath): string
    {
        if ($relPath === '') {
            return '';
        }
        $parts = explode('/', $relPath);

        return (string) end($parts);
    }

    /**
     * The class/symbol stem a consumer references: strip the final extension only
     * ("Foo.php" -> "Foo", "schema.test.ts" -> "schema.test"). Empty when none.
     */
    public static function stem(string $basename): string
    {
        $basename = trim($basename);
        if ($basename === '') {
            return '';
        }
        $dot = strrpos($basename, '.');
        if ($dot === false || $dot === 0) {
            return $basename;
        }

        return substr($basename, 0, $dot);
    }
}
