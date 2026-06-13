<?php

namespace App\Services\Ai\Memory\LocalAgentIngestion;

/**
 * Walks a configured root in read-only mode and emits a normalised list of
 * file descriptors. Out of scope: opening files, hashing, classification —
 * those happen further down the pipeline so discovery alone can never leak
 * content.
 *
 * Discovery applies:
 *  - allowlist by file extension;
 *  - denylist by filename substring (case-insensitive);
 *  - per-file size cap (computed via stat, not by read);
 *  - global per-run file count cap (`max_files_per_run`).
 *
 * Any item that hits a guard becomes a SKIPPED descriptor instead of being
 * dropped — that way the receipt can explain the run honestly.
 */
final class LocalAgentSourceDiscoveryService
{
    /**
     * @param  array<string,mixed>  $root  shape: {alias:string, path:string, enabled:bool}
     * @param  array<string,mixed>  $config
     * @return array<int,array<string,mixed>>
     */
    public function walk(array $root, array $config): array
    {
        $alias = (string) ($root['alias'] ?? '');
        $rootPath = (string) ($root['path'] ?? '');
        if ($alias === '' || $rootPath === '' || ! is_dir($rootPath)) {
            return [];
        }

        $realRoot = realpath($rootPath) ?: $rootPath;
        $maxBytes = (int) ($config['max_file_bytes'] ?? 1_048_576);
        $maxFiles = (int) ($config['max_files_per_run'] ?? 500);
        $denylist = array_map(
            static fn ($p): string => strtolower(trim((string) $p)),
            (array) ($config['denylist_patterns'] ?? []),
        );
        $allowExts = array_map(
            static fn ($e): string => ltrim(strtolower(trim((string) $e)), '.'),
            (array) ($config['allowlist_extensions'] ?? []),
        );

        $items = [];
        $count = 0;
        $truncated = false;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $realRoot,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            if ($count >= $maxFiles) {
                $truncated = true;
                break;
            }
            $count++;

            $absolute = $file->getPathname();
            $resolved = realpath($absolute) ?: $absolute;

            // Reject anything that resolves outside the root (symlink escape).
            if (! str_starts_with($resolved, rtrim($realRoot, '/').'/') && $resolved !== $realRoot) {
                $items[] = $this->skip($alias, $absolute, $realRoot, LocalAgentMemoryIngestionCanon::SKIP_PATH_OUTSIDE_ROOT, $file);

                continue;
            }

            $basenameLower = strtolower($file->getFilename());
            foreach ($denylist as $needle) {
                if ($needle !== '' && str_contains($basenameLower, $needle)) {
                    $items[] = $this->skip($alias, $resolved, $realRoot, LocalAgentMemoryIngestionCanon::SKIP_DENYLIST_PATTERN, $file);

                    continue 2;
                }
            }

            $ext = strtolower((string) $file->getExtension());
            if ($allowExts !== [] && ! in_array($ext, $allowExts, true)) {
                $items[] = $this->skip($alias, $resolved, $realRoot, LocalAgentMemoryIngestionCanon::SKIP_EXTENSION_NOT_ALLOWED, $file);

                continue;
            }

            $size = (int) $file->getSize();
            if ($size > $maxBytes) {
                $items[] = $this->skip($alias, $resolved, $realRoot, LocalAgentMemoryIngestionCanon::SKIP_SIZE_EXCEEDED, $file);

                continue;
            }

            if (! $file->isReadable()) {
                $items[] = $this->skip($alias, $resolved, $realRoot, LocalAgentMemoryIngestionCanon::SKIP_UNREADABLE, $file);

                continue;
            }

            $items[] = [
                'alias' => $alias,
                'absolute_path' => $resolved,
                'relative_path' => $this->relativePath($resolved, $realRoot),
                'extension' => $ext,
                'size_bytes' => $size,
                'mtime' => $file->getMTime(),
                'skip_reason' => null,
            ];
        }

        if ($truncated) {
            $items[] = [
                'alias' => $alias,
                'absolute_path' => null,
                'relative_path' => null,
                'extension' => null,
                'size_bytes' => 0,
                'mtime' => null,
                'skip_reason' => LocalAgentMemoryIngestionCanon::SKIP_DISCOVERY_TRUNCATED,
            ];
        }

        return $items;
    }

    /**
     * @return array<string,mixed>
     */
    private function skip(string $alias, string $path, string $rootPath, string $reason, \SplFileInfo $file): array
    {
        $leaksOutsideRootMetadata = $reason === LocalAgentMemoryIngestionCanon::SKIP_PATH_OUTSIDE_ROOT;

        return [
            'alias' => $alias,
            'absolute_path' => $path,
            'relative_path' => $this->relativePath($path, $rootPath),
            'extension' => strtolower((string) $file->getExtension()),
            'size_bytes' => $leaksOutsideRootMetadata ? 0 : (int) $file->getSize(),
            'mtime' => $leaksOutsideRootMetadata ? null : $file->getMTime(),
            'skip_reason' => $reason,
        ];
    }

    private function relativePath(string $absolute, string $root): string
    {
        $rootWithSlash = rtrim($root, '/').'/';
        if (str_starts_with($absolute, $rootWithSlash)) {
            return substr($absolute, strlen($rootWithSlash));
        }

        return basename($absolute);
    }
}
