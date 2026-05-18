<?php

namespace App\Services\Ai\ProgrammingRuntime;

/**
 * Filesystem-style probe abstraction so the readiness service can be tested
 * deterministically. Tests pass a fake probe; production uses
 * `FilesystemRepoProbe` which reads from `base_path()`.
 */
interface RepoProbe
{
    public function fileExists(string $relativePath): bool;

    public function readFile(string $relativePath): ?string;

    /**
     * Count occurrences of `$needle` inside files matching `$glob` under
     * `$relativeDirectory`. Excluded paths (relative) are skipped to keep the
     * production behavior deterministic and tests focused.
     *
     * @param  array<int,string>  $excludeRelativePaths
     */
    public function countMatchesInDirectory(
        string $relativeDirectory,
        string $needle,
        string $glob = '*.php',
        array $excludeRelativePaths = [],
    ): int;

    /**
     * Find files under `$relativeDirectory` that contain `$needle`. Returns
     * the relative path of each matching file (no duplicates).
     *
     * @param  array<int,string>  $excludeRelativePaths
     * @return array<int,string>
     */
    public function findFilesContaining(
        string $relativeDirectory,
        string $needle,
        string $glob = '*.php',
        array $excludeRelativePaths = [],
    ): array;
}
