<?php

namespace App\Services\Ai\ProgrammingRuntime;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Default probe: reads real files under `base_path()`. Recursively walks
 * directories without invoking shell commands, keeping the readiness service
 * portable across CI environments and Docker containers.
 */
class FilesystemRepoProbe implements RepoProbe
{
    /**
     * Directories that NEVER hold Atlas source the probes look for, PRUNED from
     * traversal (not just filtered from results). Without this the recursive
     * walk descended into and file_get_contents()'d every file under vendor/,
     * node_modules/, storage/ (~400k) and tools/ (~207k rivals benchmarks) —
     * ~20-30s per call. Running on the synchronous chat path (Hyperflow entry),
     * that blew past the desktop bridge timeout and surfaced as
     * "kernel offline · could not reach atlas-server" (03/07). Pruning at the
     * iterator level is the fix: excludeRelativePaths only skips MATCHES, it
     * never stopped the descent.
     */
    private const HARD_PRUNE_DIRS = [
        'vendor', 'node_modules', '.git', 'storage', 'target', 'dist',
        '.next', '.turbo', '.idea', '.vscode', 'tools', 'bootstrap',
        'runtimes', 'public',
    ];

    /** Backstop: a pathological tree can never hang the walk past this many files. */
    private const MAX_FILES_SCANNED = 25000;

    /**
     * Per-instance content cache keyed by "root|ext" → [relative => contents].
     * The readiness services scan the SAME directory (e.g. `app`, ~7800 .php)
     * 6+ times with different needles on the synchronous chat path — without
     * this each needle re-walked and re-read every file (~20-30s total → bridge
     * timeout). Now the first scan of a (dir, glob) reads once; every later
     * needle searches the in-memory map. Bounded by MAX_FILES_SCANNED and a byte
     * budget so a huge tree can never blow memory.
     *
     * @var array<string, array<string, string>>
     */
    private array $contentCache = [];

    private const CACHE_BYTE_BUDGET = 96 * 1024 * 1024;

    public function __construct(private readonly ?string $basePathOverride = null) {}

    public function fileExists(string $relativePath): bool
    {
        return is_file($this->absolute($relativePath));
    }

    public function readFile(string $relativePath): ?string
    {
        $absolute = $this->absolute($relativePath);
        if (! is_file($absolute) || ! is_readable($absolute)) {
            return null;
        }

        $contents = file_get_contents($absolute);

        return $contents === false ? null : $contents;
    }

    public function countMatchesInDirectory(
        string $relativeDirectory,
        string $needle,
        string $glob = '*.php',
        array $excludeRelativePaths = [],
    ): int {
        return count($this->findFilesContaining($relativeDirectory, $needle, $glob, $excludeRelativePaths));
    }

    public function findFilesContaining(
        string $relativeDirectory,
        string $needle,
        string $glob = '*.php',
        array $excludeRelativePaths = [],
    ): array {
        $absoluteRoot = $this->absolute($relativeDirectory);
        if (! is_dir($absoluteRoot)) {
            return [];
        }

        $extension = $this->extensionFromGlob($glob);
        $matches = [];
        foreach ($this->cachedContents($absoluteRoot, $extension) as $relative => $contents) {
            if ($this->isExcluded($relative, $excludeRelativePaths)) {
                continue;
            }
            if (str_contains($contents, $needle)) {
                $matches[$relative] = true;
            }
        }

        ksort($matches);

        return array_keys($matches);
    }

    /**
     * Walk + read ONCE per (root, extension), pruning heavy/never-source
     * directories at the iterator level (a callback returning false for a
     * directory stops the descent entirely — the old code descended into
     * vendor/storage/tools/… and only filtered matches, which is why it hung).
     * The map is cached and reused by every later needle search of the same
     * directory, so the readiness services' 6+ same-directory scans read each
     * file once instead of once-per-needle.
     *
     * @return array<string, string>
     */
    private function cachedContents(string $absoluteRoot, ?string $extension): array
    {
        $key = $absoluteRoot.'|'.($extension ?? '*');
        if (isset($this->contentCache[$key])) {
            return $this->contentCache[$key];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $current): bool {
                    if ($current->isDir()) {
                        return ! in_array($current->getFilename(), self::HARD_PRUNE_DIRS, true);
                    }

                    return true;
                },
            ),
        );

        $map = [];
        $bytes = 0;
        $scanned = 0;
        foreach ($iterator as $file) {
            if (++$scanned > self::MAX_FILES_SCANNED || $bytes >= self::CACHE_BYTE_BUDGET) {
                break;
            }
            if (! $file->isFile()) {
                continue;
            }
            if ($extension !== null && strtolower((string) $file->getExtension()) !== $extension) {
                continue;
            }
            $contents = @file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            $bytes += strlen($contents);
            $map[$this->relative($file->getPathname())] = $contents;
        }

        return $this->contentCache[$key] = $map;
    }

    private function absolute(string $relativePath): string
    {
        $base = rtrim($this->basePathOverride ?? base_path(), DIRECTORY_SEPARATOR);

        return $base.DIRECTORY_SEPARATOR.ltrim($relativePath, DIRECTORY_SEPARATOR);
    }

    private function relative(string $absolute): string
    {
        $base = rtrim($this->basePathOverride ?? base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (str_starts_with($absolute, $base)) {
            return substr($absolute, strlen($base));
        }

        return $absolute;
    }

    /**
     * @param  array<int,string>  $excludeRelativePaths
     */
    private function isExcluded(string $relative, array $excludeRelativePaths): bool
    {
        foreach ($excludeRelativePaths as $exclude) {
            if ($exclude === '') {
                continue;
            }
            if (str_starts_with($relative, $exclude)) {
                return true;
            }
        }

        return false;
    }

    private function extensionFromGlob(string $glob): ?string
    {
        if ($glob === '*' || $glob === '*.*') {
            return null;
        }
        if (! str_starts_with($glob, '*.')) {
            return null;
        }

        return strtolower(substr($glob, 2));
    }
}
