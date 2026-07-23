<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;


/**
 * AP-815 · E-2 — Incremental re-index planner for LIVE code-graph indexing.
 *
 * When the operator edits the workspace, re-indexing the whole tree is wasteful:
 * a single saved file should re-extract one file, not melt the [py] parse stage on
 * a 200k-file monorepo. This planner is the [php] orchestration that turns a
 * git/file diff (the files that CHANGED and the files that were DELETED since the
 * last index) into the MINIMAL work plan so live indexing only touches what moved.
 *
 * It is the diff→work sibling of two existing planners and stays deliberately
 * distinct from both:
 *   - {@see CodeGraphIncrementalPlanner} computes the diff itself from two
 *     `path => content_hash` maps (its INPUT is hashes; its OUTPUT is the
 *     changed/removed paths). This class takes that already-known diff (or a raw
 *     `git diff --name-status` split) and turns it into a batched re-index PLAN.
 *   - {@see CodeGraphFirstIndexPlanner} budgets the FIRST index of a fresh repo.
 *     This class handles every index AFTER the first — the steady-state live loop.
 *
 * The contract:
 *
 *   - reindex          — changed source files needing re-extraction (deduped,
 *                        sorted SORT_STRING). A file that is ALSO deleted is NOT
 *                        here: you cannot re-extract a file that no longer exists,
 *                        so deletion wins (fail-safe: never queue a parse of a gone
 *                        file).
 *   - archive          — deleted source files whose symbols/edges must be evicted
 *                        (archived) from the graph (deduped, sorted SORT_STRING).
 *   - modules_touched  — the top-level module DIRECTORIES affected, derived from
 *                        EVERY touched file (reindex AND archive — a deleted file's
 *                        module is still touched). A module is the file's directory
 *                        capped to the first two segments (a directory, never a
 *                        file); a root-level file rolls up to the '(root)' sentinel.
 *                        Deduped, sorted SORT_STRING.
 *   - batch_size       — files per re-extract batch (from $opts, else config, else
 *                        500; clamped to ≥ 1).
 *   - batches          — number of batches the reindex list partitions into
 *                        (ceil(|reindex| / batch_size)); 0 when nothing is reindexed.
 *                        Archive is metadata eviction, not a batched parse, so it
 *                        does not contribute batches.
 *   - noop             — true when, after filtering, there is nothing to reindex AND
 *                        nothing to archive (the indexer can skip the run entirely).
 *
 * Source filter: only .php / .ts / .tsx / .js / .jsx / .md files are planned (the
 * languages the graph extracts). Everything else (lockfiles, images, configs,
 * binaries) is ignored so a churny `composer.lock` never triggers a parse — UNLESS
 * `$opts['all']` is truthy, which plans EVERY non-empty path regardless of extension
 * (used by full-control callers that have already decided what is relevant).
 *
 * Determinism & fail-safety (house contract, mirrors {@see CodeGraphInferredGuard}
 * and {@see CodeGraphFirstIndexPlanner}):
 *   - Pure function of its arguments + config. No DB, no clock, no random, no
 *     provider, no filesystem. Same (changed, deleted, opts) → byte-identical output.
 *     Both lists are sorted SORT_STRING so input order never leaks into the plan.
 *   - Never throws. Non-string / blank / non-array entries are skipped; paths are
 *     normalised (backslashes → '/', leading './' and '/' stripped, '..' segments
 *     dropped) so OS paths and git paths collapse to the same key. A garbage
 *     batch_size (0, negative, NaN, non-numeric) clamps to the safe floor of 1, so
 *     the batch math can never divide by zero or stall the indexer.
 *
 * This is [php] by the runtime-language boundary: it PLANS the re-index (a bounded
 * decision/orchestration). The heavy re-extract the plan governs is [py].
 */
class CodeGraphIncrementalReindexPlanner
{
    public const SCHEMA = 'atlas.code_graph.incremental_reindex_plan.v1';

    /**
     * The source extensions the code graph extracts. Anything else is ignored unless
     * $opts['all'] is set. Lower-case, leading dot; compared case-insensitively.
     *
     * @var array<int,string>
     */
    public const SOURCE_EXTENSIONS = ['.php', '.ts', '.tsx', '.js', '.jsx', '.md'];

    /** Lowest batch size. A 0/negative batch would divide-by-zero / stall the indexer. */
    private const MIN_BATCH_SIZE = 1;

    /**
     * Module reported for a file that sits at the repository ROOT (no directory, e.g.
     * 'index.php', 'composer.json' under $opts['all']). Such a file has no module dir,
     * but it is still TOUCHED, so it is surfaced under this explicit sentinel rather
     * than being silently dropped. A non-path sentinel so it can never collide with a
     * real directory name.
     */
    public const ROOT_MODULE = '(root)';

    /**
     * Build the minimal incremental re-index plan.
     *
     * @param  array<int,mixed>  $changedFiles  paths added/modified since the last index
     *   (e.g. `git diff --name-only` for A+M). Non-string/blank entries are skipped.
     * @param  array<int,mixed>  $deletedFiles  paths removed since the last index
     *   (e.g. `git diff --name-only --diff-filter=D`). Non-string/blank entries skipped.
     * @param  array<string,mixed>  $opts  per-call overrides:
     *   - `batch_size` (int): files per re-extract batch (default from config
     *     'atlas.code_graph.index_batch_size', else 500; clamped to ≥ 1).
     *   - `all` (bool): when truthy, plan EVERY non-empty path regardless of
     *     extension (skip the source-extension filter).
     * @return array{
     *   schema_version:string,
     *   reindex:array<int,string>,
     *   archive:array<int,string>,
     *   modules_touched:array<int,string>,
     *   batch_size:int,
     *   batches:int,
     *   noop:bool
     * }
     */
    public function plan(array $changedFiles, array $deletedFiles = [], array $opts = []): array
    {
        $all = $this->wantsAll($opts);
        $batchSize = $this->resolveBatchSize($opts);

        // Normalise + filter both sides into deduped sets keyed by canonical path.
        $archiveSet = $this->collect($deletedFiles, $all);   // path => true
        $changedSet = $this->collect($changedFiles, $all);   // path => true

        // Deletion is terminal: a path that is both changed AND deleted cannot be
        // re-extracted (the file is gone), so it lives only in `archive`. Drop any
        // such path from the reindex set — never queue a parse of a deleted file.
        foreach ($archiveSet as $path => $_) {
            unset($changedSet[$path]);
        }

        $reindex = array_keys($changedSet);
        $archive = array_keys($archiveSet);
        sort($reindex, SORT_STRING);
        sort($archive, SORT_STRING);

        // Modules touched span BOTH reindex and archive: editing or deleting a file
        // both "touch" its module. Derive from the first two path segments, dedup,
        // sort. Use a set so order of discovery never leaks in.
        $moduleSet = [];
        foreach ($reindex as $path) {
            $moduleSet[$this->moduleOf($path)] = true;
        }
        foreach ($archive as $path) {
            $moduleSet[$this->moduleOf($path)] = true;
        }
        unset($moduleSet['']); // never report an empty module key
        $modulesTouched = array_keys($moduleSet);
        sort($modulesTouched, SORT_STRING);

        $reindexCount = count($reindex);
        $batches = $this->batchCount($reindexCount, $batchSize);

        return [
            'schema_version' => self::SCHEMA,
            'reindex' => array_values($reindex),
            'archive' => array_values($archive),
            'modules_touched' => array_values($modulesTouched),
            'batch_size' => $batchSize,
            'batches' => $batches,
            // Nothing to re-extract and nothing to evict → the indexer may skip the run.
            'noop' => $reindexCount === 0 && count($archive) === 0,
        ];
    }

    /**
     * Normalise, filter (source extensions unless $all), and dedup a raw path list
     * into a `canonicalPath => true` set. Non-string, blank, and (when filtering)
     * non-source paths are dropped silently — fail-safe, never throws.
     *
     * @param  array<int,mixed>  $paths
     * @return array<string,true>
     */
    private function collect(array $paths, bool $all): array
    {
        $set = [];
        foreach ($paths as $raw) {
            if (! is_string($raw)) {
                continue;
            }
            $path = $this->canonicalPath($raw);
            if ($path === '') {
                continue;
            }
            if (! $all && ! $this->isSourceFile($path)) {
                continue;
            }
            $set[$path] = true;
        }

        return $set;
    }

    /**
     * Canonicalise a path for stable keying: trim, convert Windows backslashes to
     * '/', strip a leading './' and any leading '/', collapse repeated slashes, and
     * drop '.'/'..' segments so '../a/b' and 'a/b' (and 'a//b') never key apart.
     * Returns '' for anything that normalises to nothing.
     */
    private function canonicalPath(string $raw): string
    {
        $value = str_replace('\\', '/', trim($raw));
        if ($value === '') {
            return '';
        }

        // Split on '/', dropping empty segments (handles leading '/', './', and
        // repeated '//') and '.'/'..' segments (defensive against diff noise).
        $clean = [];
        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $clean[] = $segment;
        }

        return implode('/', $clean);
    }

    /**
     * The top-level module DIRECTORY a file belongs to, capped to the first two
     * directory segments. A module is a directory, never a file, so the filename
     * (the last segment) is dropped first; the remaining directory is then capped at
     * depth 2 so deep trees still roll up to a stable top-level module.
     *
     *   app/Services/Foo.php        → app/Services   (dir [app,Services], capped 2)
     *   app/Services/Sub/Deep.php   → app/Services   (deep tree rolls up to depth 2)
     *   a/b.php                     → a              (dir [a] — the real top-level dir,
     *                                                 NOT 'a/b.php' which is the file)
     *   index.php                   → (root)         (root-level file: no module dir)
     *
     * Assumes a canonical path (no leading '/' or './') as produced by
     * {@see canonicalPath()}. An empty path yields '' (caller drops it).
     */
    private function moduleOf(string $canonicalPath): string
    {
        if ($canonicalPath === '') {
            return '';
        }

        $segments = explode('/', $canonicalPath);

        // Drop the filename (last segment): a module is a directory, not a file.
        array_pop($segments);

        // A root-level file has no directory → an explicit, surfaced sentinel.
        if ($segments === []) {
            return self::ROOT_MODULE;
        }

        // Cap the directory at the first two segments (the top-level module).
        return implode('/', array_slice($segments, 0, 2));
    }

    /**
     * Whether a canonical path is a graph-extracted source file by extension.
     * Case-insensitive; matches on the final extension only (so 'a.test.ts' → '.ts').
     */
    private function isSourceFile(string $canonicalPath): bool
    {
        $dot = strrpos($canonicalPath, '.');
        if ($dot === false) {
            return false; // no extension → not a recognised source file
        }

        $ext = strtolower(substr($canonicalPath, $dot)); // includes the leading '.'

        return in_array($ext, self::SOURCE_EXTENSIONS, true);
    }

    /**
     * Number of batches the reindex list partitions into: ceil(count / batchSize).
     * Zero files → zero batches. batchSize is already clamped ≥ 1 so this never
     * divides by zero. Uses integer arithmetic to avoid float rounding surprises.
     */
    private function batchCount(int $reindexCount, int $batchSize): int
    {
        if ($reindexCount <= 0) {
            return 0;
        }

        return intdiv($reindexCount + $batchSize - 1, $batchSize);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function resolveBatchSize(array $opts): int
    {
        if (array_key_exists('batch_size', $opts)) {
            $candidate = $this->intOrNull($opts['batch_size']);
            if ($candidate !== null) {
                return max(self::MIN_BATCH_SIZE, $candidate);
            }
        }

        $configured = $this->intOrNull(config('atlas.code_graph.index_batch_size', 500));

        return max(self::MIN_BATCH_SIZE, $configured ?? 500);
    }

    /**
     * @param  array<string,mixed>  $opts
     */
    private function wantsAll(array $opts): bool
    {
        return array_key_exists('all', $opts) && filter_var($opts['all'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Coerce a config/opt value to an int, or null if it is not a usable number.
     * Accepts int, float (truncated toward zero), and numeric strings. Rejects NaN
     * and infinities so a garbage config can never poison the batch arithmetic.
     */
    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                return null;
            }

            return (int) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            $float = (float) trim($value);
            if (is_nan($float) || is_infinite($float)) {
                return null;
            }

            return (int) $float;
        }

        return null;
    }
}
