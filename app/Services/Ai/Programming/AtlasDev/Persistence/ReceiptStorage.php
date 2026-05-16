<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Persistence;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;
use RuntimeException;

/**
 * Atomic, per-run filesystem storage for Atlas Dev receipts and telemetry.
 *
 * Conventions:
 * - Base path: <baseDir>/<run_id>/
 * - One JSON artifact per filename. Writes are atomic (tmp + rename); a crash
 *   mid-write can leave a residual `.tmp.*` next to the artifact but never a
 *   partially-written `<name>.json`.
 * - Monotonic versions live as `<base>.v<n>.json`; the underlying writeAtomic
 *   refuses to overwrite, so each version is durable and append-only by name.
 * - Filenames are validated to refuse path traversal.
 */
final class ReceiptStorage
{
    private const RUN_ID_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';
    private const FILENAME_PATTERN = '/^[A-Za-z0-9._-]{1,160}\.json$/';
    private const VERSION_BASE_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    public function __construct(
        private readonly string $baseDir,
    ) {
        if ($this->baseDir === '') {
            throw new InvalidArgumentException('ReceiptStorage.baseDir must not be empty.');
        }
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    public function runDirectory(string $runId): string
    {
        $this->assertRunId($runId);

        return rtrim($this->baseDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$runId;
    }

    public function ensureRunDirectory(string $runId): string
    {
        $dir = $this->runDirectory($runId);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("ReceiptStorage: failed to create run directory '{$dir}'.");
        }

        return $dir;
    }

    public function exists(string $runId, string $filename): bool
    {
        $this->assertFilename($filename);

        return is_file($this->path($runId, $filename));
    }

    public function path(string $runId, string $filename): string
    {
        $this->assertFilename($filename);

        return $this->runDirectory($runId).DIRECTORY_SEPARATOR.$filename;
    }

    /**
     * Atomic write of a canonical payload as JSON. Refuses to overwrite an
     * existing artifact: callers wanting versions must use writeMonotonic.
     *
     * @param  array<string, mixed>  $payload
     * @return string  absolute path of the resulting artifact
     */
    public function writeAtomic(string $runId, string $filename, array $payload): string
    {
        $this->assertFilename($filename);
        $dir = $this->ensureRunDirectory($runId);
        $final = $dir.DIRECTORY_SEPARATOR.$filename;

        if (is_file($final)) {
            throw new RuntimeException("ReceiptStorage: refusing to overwrite '{$final}'. Use writeMonotonic for versioned artifacts.");
        }

        $encoded = CanonicalJson::encode($payload);
        $tmp = $final.'.tmp.'.bin2hex(random_bytes(6));

        $bytes = @file_put_contents($tmp, $encoded, LOCK_EX);
        if ($bytes === false || $bytes !== strlen($encoded)) {
            @unlink($tmp);
            throw new RuntimeException("ReceiptStorage: failed to write temp file '{$tmp}'.");
        }

        // Best-effort durability before the rename.
        $fh = @fopen($tmp, 'r');
        if (is_resource($fh)) {
            @fflush($fh);
            if (function_exists('fdatasync')) {
                @fdatasync($fh);
            } elseif (function_exists('fsync')) {
                @fsync($fh);
            }
            fclose($fh);
        }

        if (! @rename($tmp, $final)) {
            @unlink($tmp);
            throw new RuntimeException("ReceiptStorage: failed to rename '{$tmp}' to '{$final}'.");
        }

        @chmod($final, 0o444);

        return $final;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $runId, string $filename): ?array
    {
        $this->assertFilename($filename);
        $path = $this->path($runId, $filename);
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("ReceiptStorage: failed to read '{$path}'.");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("ReceiptStorage: artifact at '{$path}' is not a JSON object.");
        }

        return $decoded;
    }

    /**
     * Versions are named `<base>.v<n>.json` and allocated monotonically.
     *
     * @param  array<string, mixed>  $payload
     * @return array{path: string, version: int}
     */
    public function writeMonotonic(string $runId, string $base, array $payload): array
    {
        $this->assertVersionBase($base);
        $next = $this->nextVersion($runId, $base);
        $filename = "{$base}.v{$next}.json";
        $path = $this->writeAtomic($runId, $filename, $payload);

        return ['path' => $path, 'version' => $next];
    }

    /**
     * @return list<int>  sorted ascending
     */
    public function listVersions(string $runId, string $base): array
    {
        $this->assertVersionBase($base);
        $dir = $this->runDirectory($runId);
        if (! is_dir($dir)) {
            return [];
        }

        $pattern = '/^'.preg_quote($base, '/').'\.v(\d+)\.json$/';
        $versions = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match($pattern, $entry, $m) === 1) {
                $versions[] = (int) $m[1];
            }
        }
        sort($versions, SORT_NUMERIC);

        return $versions;
    }

    public function nextVersion(string $runId, string $base): int
    {
        $versions = $this->listVersions($runId, $base);
        if ($versions === []) {
            return 1;
        }

        return max($versions) + 1;
    }

    public function latestVersion(string $runId, string $base): ?int
    {
        $versions = $this->listVersions($runId, $base);
        if ($versions === []) {
            return null;
        }

        return max($versions);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readVersion(string $runId, string $base, int $version): ?array
    {
        $this->assertVersionBase($base);
        if ($version < 1) {
            throw new InvalidArgumentException("ReceiptStorage.readVersion: version must be >= 1, got {$version}.");
        }

        return $this->read($runId, "{$base}.v{$version}.json");
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readLatestVersion(string $runId, string $base): ?array
    {
        $latest = $this->latestVersion($runId, $base);
        if ($latest === null) {
            return null;
        }

        return $this->readVersion($runId, $base, $latest);
    }

    /**
     * @return list<string>  filenames of orphan temp files (path traversal safe)
     */
    public function listOrphanTempFiles(string $runId): array
    {
        $dir = $this->runDirectory($runId);
        if (! is_dir($dir)) {
            return [];
        }
        $orphans = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (str_contains($entry, '.tmp.')) {
                $orphans[] = $entry;
            }
        }
        sort($orphans, SORT_STRING);

        return $orphans;
    }

    private function assertRunId(string $runId): void
    {
        if (preg_match(self::RUN_ID_PATTERN, $runId) !== 1) {
            throw new InvalidArgumentException("ReceiptStorage: run_id '{$runId}' does not match required pattern.");
        }
    }

    private function assertFilename(string $filename): void
    {
        if (preg_match(self::FILENAME_PATTERN, $filename) !== 1
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || str_contains($filename, '..')) {
            throw new InvalidArgumentException("ReceiptStorage: filename '{$filename}' is invalid or unsafe.");
        }
    }

    private function assertVersionBase(string $base): void
    {
        if (preg_match(self::VERSION_BASE_PATTERN, $base) !== 1
            || str_contains($base, '/')
            || str_contains($base, '\\')
            || str_contains($base, '..')) {
            throw new InvalidArgumentException("ReceiptStorage: version base '{$base}' is invalid or unsafe.");
        }
    }
}
