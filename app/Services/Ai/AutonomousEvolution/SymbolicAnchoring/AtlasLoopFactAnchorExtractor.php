<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SymbolicAnchoring;

/**
 * Immutable value object representing the anchors extracted from a FACT.
 */
final class AnchorList
{
    /**
     * @param  list<array<string,mixed>>  $resolved
     * @param  list<array<string,mixed>>  $unresolved
     */
    public function __construct(
        public readonly array $resolved,
        public readonly array $unresolved,
    ) {}

    public function isAnchored(): bool
    {
        return $this->resolved !== [];
    }

    public function resolvedCount(): int
    {
        return count($this->resolved);
    }

    public function unresolvedCount(): int
    {
        return count($this->unresolved);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'resolved' => $this->resolved,
            'unresolved' => $this->unresolved,
            'is_anchored' => $this->isAnchored(),
            'resolved_count' => $this->resolvedCount(),
            'unresolved_count' => $this->unresolvedCount(),
        ];
    }
}

/**
 * Deterministic parser that scans a FACT payload and extracts every embedded symbolic anchor:
 *   - file paths relative to base_path
 *   - file:line and file:line-line refs
 *   - fully-qualified PHP symbols (Namespace\Class, Class::method)
 *   - config('atlas.foo.bar') and route('foo.bar') refs
 *
 * Anti-Goodhart: a ref is RESOLVED only when the file exists on disk. Phantom refs are surfaced
 * as `unresolved` so a FACT padded with fake paths cannot inflate anchor coverage.
 */
final class AtlasLoopFactAnchorExtractor
{
    public function __construct(private readonly string $projectRoot) {}

    /**
     * @param  array<string,mixed>  $metadata  optional metadata array merged into the scan text
     */
    public function extract(string $text, array $metadata = []): AnchorList
    {
        $combined = $text."\n".(string) json_encode($metadata, JSON_UNESCAPED_SLASHES);

        $seenResolved = [];
        $resolved = [];
        $seenUnresolved = [];
        $unresolved = [];

        // 1. file:line-line  (range)
        if (preg_match_all('/([A-Za-z0-9_\/\.\-]+\.[A-Za-z0-9]+):(\d+)-(\d+)/u', $combined, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $whole) {
                $path = (string) $matches[1][$i][0];
                $start = (int) $matches[2][$i][0];
                $end = (int) $matches[3][$i][0];
                $this->record($path, $resolved, $unresolved, $seenResolved, $seenUnresolved, [
                    'kind' => 'file_line_range',
                    'span' => [$whole[1], $whole[1] + strlen((string) $whole[0])],
                    'line_start' => $start,
                    'line_end' => $end,
                ]);
            }
        }

        // 2. file:line
        if (preg_match_all('/([A-Za-z0-9_\/\.\-]+\.[A-Za-z0-9]+):(\d++)(?!-)/u', $combined, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $whole) {
                $path = (string) $matches[1][$i][0];
                $line = (int) $matches[2][$i][0];
                $this->record($path, $resolved, $unresolved, $seenResolved, $seenUnresolved, [
                    'kind' => 'file_line',
                    'span' => [$whole[1], $whole[1] + strlen((string) $whole[0])],
                    'line' => $line,
                ]);
            }
        }

        // 3. bare file paths (with extension)
        if (preg_match_all('/(?<![:\w])([A-Za-z0-9_\/\.\-]+\.[A-Za-z0-9]+)(?!:\d)/u', $combined, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $i => $whole) {
                $path = (string) $whole[0];
                if (! str_contains($path, '/') && ! str_contains($path, '.')) {
                    continue;
                }
                $this->record($path, $resolved, $unresolved, $seenResolved, $seenUnresolved, [
                    'kind' => 'file_path',
                    'span' => [$whole[1], $whole[1] + strlen($path)],
                ]);
            }
        }

        // 4. PHP symbols: Namespace\Class or Class::method
        if (preg_match_all('/((?:[A-Z][A-Za-z0-9_]*\\\\)+[A-Z][A-Za-z0-9_]*)(?:::([A-Za-z_][A-Za-z0-9_]*))?/u', $combined, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $whole) {
                $symbol = (string) $whole[0];
                $key = 'symbol:'.$symbol;
                if (isset($seenResolved[$key])) {
                    continue;
                }
                $seenResolved[$key] = true;
                $resolved[] = [
                    'kind' => 'php_symbol',
                    'symbol' => $symbol,
                    'span' => [$whole[1], $whole[1] + strlen($symbol)],
                ];
            }
        }

        // 5. Class::method without namespace
        if (preg_match_all('/(?<![\\\\\w])([A-Z][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)/u', $combined, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $whole) {
                $symbol = (string) $whole[0];
                $key = 'symbol:'.$symbol;
                if (isset($seenResolved[$key])) {
                    continue;
                }
                $seenResolved[$key] = true;
                $resolved[] = [
                    'kind' => 'php_symbol',
                    'symbol' => $symbol,
                    'span' => [$whole[1], $whole[1] + strlen($symbol)],
                ];
            }
        }

        // 6. config('atlas.foo.bar') / route('foo.bar')
        if (preg_match_all('/\b(config|route)\(\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]\s*\)/u', $combined, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $whole) {
                $kind = (string) $matches[1][$i][0];
                $key = (string) $matches[2][$i][0];
                $hashKey = $kind.':'.$key;
                if (isset($seenResolved[$hashKey])) {
                    continue;
                }
                $seenResolved[$hashKey] = true;
                $resolved[] = [
                    'kind' => $kind.'_ref',
                    'key' => $key,
                    'span' => [$whole[1], $whole[1] + strlen((string) $whole[0])],
                ];
            }
        }

        return new AnchorList(array_values($resolved), array_values($unresolved));
    }

    /**
     * @param  list<array<string,mixed>>  $resolved
     * @param  list<array<string,mixed>>  $unresolved
     * @param  array<string,bool>  $seenResolved
     * @param  array<string,bool>  $seenUnresolved
     * @param  array<string,mixed>  $extra
     */
    private function record(
        string $path,
        array &$resolved,
        array &$unresolved,
        array &$seenResolved,
        array &$seenUnresolved,
        array $extra,
    ): void {
        $extra['file'] = $path;
        $abs = rtrim($this->projectRoot, '/').'/'.$path;
        if (is_file($abs)) {
            if (isset($extra['line']) || isset($extra['line_start'])) {
                $lines = max(1, (int) count(file($abs) ?: []));
                $checkLine = (int) ($extra['line'] ?? $extra['line_end'] ?? 0);
                if ($checkLine > 0 && $checkLine > $lines) {
                    $extra['line_out_of_range'] = true;
                }
            }
            $key = $extra['kind'].':'.$path.':'.(isset($extra['line']) ? 'L'.$extra['line'] : (isset($extra['line_start']) ? 'R'.$extra['line_start'].'-'.$extra['line_end'] : ''));
            if (isset($seenResolved[$key])) {
                return;
            }
            $seenResolved[$key] = true;
            $resolved[] = $extra;

            return;
        }
        $extra['reason'] = 'file_not_on_disk';
        $key = 'unresolved:'.$path;
        if (isset($seenUnresolved[$key])) {
            return;
        }
        $seenUnresolved[$key] = true;
        $unresolved[] = $extra;
    }
}
