<?php

namespace App\Services\Ai\ProgrammingRuntime;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Default probe: reads real files under `base_path()`. Recursively walks
 * directories without invoking shell commands, keeping the readiness service
 * portable across CI environments and Docker containers.
 */
class FilesystemRepoProbe implements RepoProbe
{
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
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            if ($extension !== null && strtolower((string) $file->getExtension()) !== $extension) {
                continue;
            }
            $relative = $this->relative($file->getPathname());
            if ($this->isExcluded($relative, $excludeRelativePaths)) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            if (str_contains($contents, $needle)) {
                $matches[$relative] = true;
            }
        }

        ksort($matches);

        return array_keys($matches);
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
