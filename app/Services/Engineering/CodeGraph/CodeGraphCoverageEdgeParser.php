<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;


/**
 * AP-815 · P-9 — Test→code coverage edge parser (runtime-grade precision).
 *
 * Coverage is the strongest available evidence that a piece of code is actually
 * EXERCISED by the test suite. This parser turns a raw coverage report (the
 * artifact PHPUnit / most language toolchains already emit) into two things the
 * code graph needs:
 *
 *   - a per-file coverage summary (covered/total statements + a [0,1] ratio), and
 *   - `covered_by` EDGES from each file's graph node ("node:<path>") to the test
 *     suite node ("test:suite"), scored by that file's coverage ratio.
 *
 * Those edges are EXTRACTED-grade by construction — they are proven by a real
 * report, not inferred — so downstream {@see CodeGraphInferredGuard} keeps them
 * unconditionally. The `score` is the coverage ratio, giving the graph a real
 * "how well is this exercised" signal rather than a binary covered/not-covered.
 *
 * Two report formats are supported:
 *
 *   - 'clover'  — the Clover XML PHPUnit/PHP toolchains emit. Each
 *     `<file name="..."><metrics statements=".." coveredstatements=".."/></file>`
 *     becomes one file row. Parsed via SimpleXML under
 *     libxml_use_internal_errors() so a malformed document NEVER raises — it
 *     yields the empty result.
 *   - 'lcov'    — the line-oriented LCOV tracefile (gcov / nyc / many others).
 *     `SF:<path>` opens a record; each `DA:<line>,<hits>` is one statement,
 *     covered iff hits > 0; `end_of_record` (or the next `SF:`) closes it.
 *
 * Format detection: when `$format` is not one of the two known ids it is treated
 * as 'auto' — the parser sniffs the content (an XML/`<coverage>` lead → clover;
 * an `SF:`/`DA:` line → lcov) so a mislabelled report still parses instead of
 * silently returning nothing.
 *
 * Determinism & fail-safety (house contract):
 *   - Pure transform. No DB, no clock, no random, no provider, no filesystem.
 *     The same report string always yields byte-identical output: file rows
 *     preserve first-seen order; a file appearing twice is MERGED (summed), not
 *     duplicated, so a concatenated multi-run report is handled correctly.
 *   - Never throws on bad data. Empty/binary/malformed/partial input, negative or
 *     non-numeric counts, covered>total — all are sanitized
 *     (counts clamped to ≥0, covered clamped to ≤ total) and degrade to the empty
 *     or a safe partial result. A single bad line/element is skipped, not fatal.
 *   - ratio = total > 0 ? covered/total : 0.0 (never division by zero, never NaN).
 *   - Config is read with an inline default literal so it works with no config
 *     edits; `$suite` (param) overrides config per call for the target test node.
 *
 * This is [php] by the runtime-language boundary: it PARSES a small text/XML
 * report into graph edges (orchestration/identity), it does not compute heavy
 * coverage data — the language toolchain already produced the numbers.
 */
class CodeGraphCoverageEdgeParser
{
    public const SCHEMA = 'atlas.code_graph.coverage_edge_parser.v1';

    /** Recognised report formats. Anything else is sniffed ('auto'). */
    public const FORMAT_CLOVER = 'clover';

    public const FORMAT_LCOV = 'lcov';

    public const FORMAT_AUTO = 'auto';

    /** The edge grade these edges carry: proven by a real report, never inferred. */
    public const EDGE_TYPE = 'covered_by';

    /** Fallback test-suite node id when neither the param nor config supplies one. */
    private const DEFAULT_SUITE_NODE = 'test:suite';

    /**
     * Parse a coverage report into file summaries + `covered_by` edges.
     *
     * @param  string  $content  the raw report (Clover XML or LCOV tracefile).
     * @param  string  $format  one of self::FORMAT_*; any unknown value is sniffed
     *   from the content (see class docblock). Case-insensitive.
     * @param  string|null  $suite  the test-suite node id the edges point at
     *   (`to_node_id`). Defaults to config 'atlas.code_graph.coverage_default_suite'
     *   else 'test:suite'. A blank value falls back to the same default.
     * @return array{
     *   files: array<int,array{path:string, covered:int, total:int, ratio:float}>,
     *   edges: array<int,array{from_node_id:string, to_node_id:string, edge_type:string, score:float}>,
     *   stats: array{files:int, avg_ratio:float}
     * }
     *   `files` is in first-seen path order (duplicates merged). `edges` has one
     *   `covered_by` edge per file, aligned 1:1 with `files`. `stats.files` is the
     *   file count; `stats.avg_ratio` is the mean of the per-file ratios (0.0 when
     *   there are no files). Every numeric field is finite and, for ratios/score,
     *   in [0,1]. On any failure the whole structure is the safe empty default.
     */
    public function parse(string $content, string $format = self::FORMAT_CLOVER, ?string $suite = null): array
    {
        $suiteNode = $this->resolveSuiteNode($suite);

        if (trim($content) === '') {
            return $this->emptyResult();
        }

        $resolvedFormat = $this->resolveFormat($content, $format);

        $files = match ($resolvedFormat) {
            self::FORMAT_CLOVER => $this->parseClover($content),
            self::FORMAT_LCOV => $this->parseLcov($content),
            default => [],
        };

        if ($files === []) {
            return $this->emptyResult();
        }

        return $this->assemble($files, $suiteNode);
    }

    /**
     * Build the final {files, edges, stats} structure from sanitized file rows.
     *
     * @param  array<int,array{path:string, covered:int, total:int}>  $rawFiles
     * @return array{
     *   files: array<int,array{path:string, covered:int, total:int, ratio:float}>,
     *   edges: array<int,array{from_node_id:string, to_node_id:string, edge_type:string, score:float}>,
     *   stats: array{files:int, avg_ratio:float}
     * }
     */
    private function assemble(array $rawFiles, string $suiteNode): array
    {
        $files = [];
        $edges = [];
        $ratioSum = 0.0;

        foreach ($rawFiles as $file) {
            $total = $file['total'];
            $covered = $file['covered'];
            $ratio = $total > 0 ? $this->clamp01($covered / $total) : 0.0;
            $ratioSum += $ratio;

            $files[] = [
                'path' => $file['path'],
                'covered' => $covered,
                'total' => $total,
                'ratio' => $ratio,
            ];

            $edges[] = [
                'from_node_id' => 'node:'.$file['path'],
                'to_node_id' => $suiteNode,
                'edge_type' => self::EDGE_TYPE,
                'score' => $ratio,
            ];
        }

        $count = count($files);
        $avgRatio = $count > 0 ? $this->clamp01($ratioSum / $count) : 0.0;

        return [
            'files' => $files,
            'edges' => $edges,
            'stats' => [
                'files' => $count,
                'avg_ratio' => $this->round($avgRatio),
            ],
        ];
    }

    /**
     * Parse a Clover XML report into sanitized file rows.
     *
     * Reads every `<file>` element anywhere in the document (Clover nests them
     * under `<project><package>` but `//file` finds them at any depth). The
     * per-file `<metrics statements coveredstatements>` carries the counts; a
     * `<file>` without metrics, or with non-numeric counts, is skipped (it cannot
     * be scored). libxml_use_internal_errors() makes malformed XML fail-safe.
     *
     * @return array<int,array{path:string, covered:int, total:int}>
     */
    private function parseClover(string $content): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET hardens against any external/network entity resolution.
            $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
        } catch (\Throwable) {
            // SimpleXML reports via libxml errors, not exceptions, but a guard here
            // keeps us safe against any future/edge throw — never let a report kill
            // the caller.
            $xml = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            return [];
        }

        $elements = $xml->xpath('//file');
        if (! is_array($elements) || $elements === []) {
            return [];
        }

        $byPath = []; // path => ['path'=>.., 'covered'=>int, 'total'=>int]
        $order = [];  // preserves first-seen path order

        foreach ($elements as $fileEl) {
            $attrs = $fileEl->attributes();
            if ($attrs === null) {
                continue;
            }

            // Clover uses `name`; some emitters use `path`. Accept either.
            $path = $this->normalizePath((string) ($attrs->name ?? $attrs->path ?? ''));
            if ($path === '') {
                continue;
            }

            $metrics = $fileEl->metrics;
            if (! isset($metrics) || $metrics->count() === 0) {
                // No metrics → nothing to score. Skip rather than invent zeros,
                // so an empty <file/> stub does not pollute the average with a 0.
                continue;
            }

            $mAttrs = $metrics[0]->attributes();
            if ($mAttrs === null) {
                continue;
            }

            $total = $this->intOrNull($mAttrs->statements ?? null);
            $covered = $this->intOrNull($mAttrs->coveredstatements ?? null);
            if ($total === null && $covered === null) {
                continue;
            }

            $this->accumulate($byPath, $order, $path, $covered ?? 0, $total ?? 0);
        }

        return $this->orderedRows($byPath, $order);
    }

    /**
     * Parse an LCOV tracefile into sanitized file rows.
     *
     * Line grammar (only the records we need):
     *   SF:<path>            opens a file record (closing any open one).
     *   DA:<line>,<hits>     one statement; covered iff hits > 0.
     *   end_of_record        closes the current file record.
     *
     * A record with no DA lines yields total=0 (ratio 0.0). A duplicate SF for the
     * same path is merged (summed) into the existing row. Unknown lines are
     * ignored. Handles CRLF and stray whitespace.
     *
     * @return array<int,array{path:string, covered:int, total:int}>
     */
    private function parseLcov(string $content): array
    {
        $byPath = [];
        $order = [];

        $currentPath = null;
        $covered = 0;
        $total = 0;

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if ($lines === false) {
            return [];
        }

        $flush = function () use (&$byPath, &$order, &$currentPath, &$covered, &$total): void {
            if ($currentPath !== null) {
                $this->accumulate($byPath, $order, $currentPath, $covered, $total);
            }
            $currentPath = null;
            $covered = 0;
            $total = 0;
        };

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'SF:')) {
                // A new source file closes the previous record first.
                $flush();
                $currentPath = $this->normalizePath(substr($line, 3));
                if ($currentPath === '') {
                    // Unusable path → drop this record entirely (don't attach its
                    // DA lines to a phantom file).
                    $currentPath = null;
                }

                continue;
            }

            if ($line === 'end_of_record') {
                $flush();

                continue;
            }

            if ($currentPath !== null && str_starts_with($line, 'DA:')) {
                $hits = $this->lcovHits(substr($line, 3));
                if ($hits === null) {
                    continue; // malformed DA → skip just this statement.
                }
                $total++;
                if ($hits > 0) {
                    $covered++;
                }
            }
        }

        // A trailing record with no closing end_of_record is still valid.
        $flush();

        return $this->orderedRows($byPath, $order);
    }

    /**
     * Parse the `<line>,<hits>` payload of a DA: record. Returns the hit count
     * (≥0) or null when malformed. Accepts the optional 3rd checksum field LCOV
     * may append (DA:line,hits,checksum) by reading only the second column.
     */
    private function lcovHits(string $payload): ?int
    {
        $parts = explode(',', $payload);
        if (count($parts) < 2) {
            return null;
        }

        $hits = $this->intOrNull(trim($parts[1]));
        if ($hits === null) {
            return null;
        }

        return $hits; // already clamped to ≥0 by intOrNull.
    }

    /**
     * Merge a file's counts into the accumulator, preserving first-seen order and
     * keeping covered ≤ total at all times (a report claiming more covered than
     * total statements is sanitized, never trusted to over-claim coverage).
     *
     * @param  array<string,array{path:string, covered:int, total:int}>  $byPath
     * @param  array<int,string>  $order
     */
    private function accumulate(array &$byPath, array &$order, string $path, int $covered, int $total): void
    {
        $covered = max(0, $covered);
        $total = max(0, $total);

        if (! isset($byPath[$path])) {
            $order[] = $path;
            $byPath[$path] = ['path' => $path, 'covered' => 0, 'total' => 0];
        }

        $byPath[$path]['covered'] += $covered;
        $byPath[$path]['total'] += $total;

        // Coverage can never exceed the statement count.
        if ($byPath[$path]['covered'] > $byPath[$path]['total']) {
            $byPath[$path]['covered'] = $byPath[$path]['total'];
        }
    }

    /**
     * Materialize the accumulator into a list in first-seen order.
     *
     * @param  array<string,array{path:string, covered:int, total:int}>  $byPath
     * @param  array<int,string>  $order
     * @return array<int,array{path:string, covered:int, total:int}>
     */
    private function orderedRows(array $byPath, array $order): array
    {
        $rows = [];
        foreach ($order as $path) {
            if (isset($byPath[$path])) {
                $rows[] = $byPath[$path];
            }
        }

        return $rows;
    }

    /**
     * Decide which parser to run. A known format id is honoured as-is; anything
     * else (including 'auto') is sniffed from the content so a mislabelled or
     * unlabelled report still parses.
     */
    private function resolveFormat(string $content, string $format): string
    {
        $normalized = strtolower(trim($format));
        if ($normalized === self::FORMAT_CLOVER || $normalized === self::FORMAT_LCOV) {
            return $normalized;
        }

        return $this->sniffFormat($content);
    }

    /**
     * Best-effort content sniff. LCOV is line-oriented and never starts with `<`;
     * Clover is XML. We look for the strongest unambiguous marker of each.
     */
    private function sniffFormat(string $content): string
    {
        $head = ltrim($content);

        // LCOV markers are unambiguous and cheap to detect anywhere near the top.
        if (preg_match('/^(SF:|TN:|DA:|end_of_record)/m', $head) === 1) {
            return self::FORMAT_LCOV;
        }

        // XML lead (declaration or a coverage/clover root) → clover.
        if (str_starts_with($head, '<')) {
            return self::FORMAT_CLOVER;
        }

        return self::FORMAT_AUTO; // unknown → caller maps to empty result.
    }

    /**
     * Resolve the test-suite node id (the edges' `to_node_id`). Param wins, then
     * config, then the inline default. A blank/whitespace value is treated as
     * absent so an edge always has a real target.
     */
    private function resolveSuiteNode(?string $suite): string
    {
        if ($suite !== null && trim($suite) !== '') {
            return trim($suite);
        }

        $configured = config('atlas.code_graph.coverage_default_suite', self::DEFAULT_SUITE_NODE);

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : self::DEFAULT_SUITE_NODE;
    }

    /**
     * Normalize a file path read from a report: trim, unify separators to '/',
     * strip a leading './'. Backslashes (Windows Clover) become '/' so node ids
     * are stable across the OS the report was produced on.
     */
    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $path = str_replace('\\', '/', $path);

        // Drop a single leading './' that some emitters prepend.
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return trim($path);
    }

    /**
     * Coerce a value (SimpleXML attribute, string, or scalar) to a non-negative
     * int, or null when it is absent/non-numeric/negative-and-unsafe. Negative
     * counts are clamped to 0 (a count can never be negative); non-integer numeric
     * values are floored. NaN/INF are rejected as null.
     */
    private function intOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        // SimpleXMLElement attributes stringify cleanly.
        if (is_object($value)) {
            $value = (string) $value;
        }

        if (is_bool($value)) {
            return null;
        }

        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            return max(0, (int) floor($value));
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || ! is_numeric($trimmed)) {
                return null;
            }
            $float = (float) $trimmed;
            if (is_nan($float) || is_infinite($float)) {
                return null;
            }

            return max(0, (int) floor($float));
        }

        return null;
    }

    /** Clamp to [0,1]; NaN/INF degrade to 0.0 (the over-claim-safe direction). */
    private function clamp01(float $value): float
    {
        if (is_nan($value) || is_infinite($value)) {
            return 0.0;
        }
        if ($value < 0.0) {
            return 0.0;
        }
        if ($value > 1.0) {
            return 1.0;
        }

        return $value;
    }

    /** Round reported floats so stats are stable for assertions and audit. */
    private function round(float $value): float
    {
        return round($value, 6);
    }

    /**
     * The safe empty result returned for empty/malformed/unknown input.
     *
     * @return array{
     *   files: array<int,array{path:string, covered:int, total:int, ratio:float}>,
     *   edges: array<int,array{from_node_id:string, to_node_id:string, edge_type:string, score:float}>,
     *   stats: array{files:int, avg_ratio:float}
     * }
     */
    private function emptyResult(): array
    {
        return [
            'files' => [],
            'edges' => [],
            'stats' => [
                'files' => 0,
                'avg_ratio' => 0.0,
            ],
        ];
    }
}
