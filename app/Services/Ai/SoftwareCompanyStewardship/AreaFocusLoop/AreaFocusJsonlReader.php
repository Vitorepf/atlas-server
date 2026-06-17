<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Support\AppendOnlyJsonlStore;

final class AreaFocusJsonlReader
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function rows(string $path): array
    {
        return AppendOnlyJsonlStore::read($path);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function rowsWithSchemaVersion(string $path, string $schemaVersion): array
    {
        return array_values(array_filter(self::rows($path), static function (array $row) use ($schemaVersion): bool {
            $actual = $row['schema_version'] ?? null;

            return is_string($actual) && $actual === $schemaVersion;
        }));
    }

    /**
     * ARBOR-GRAFT W5 (LED1c) — distinct schema versions present in the ledger that are NEWER than the
     * supported one. rowsWithSchemaVersion() SILENTLY drops non-matching (incl. newer) rows; this lets a
     * caller SURFACE / park a forward-incompatible ledger (operator-visible) instead of silently losing
     * state on an additive schema bump. Additive + pure: it changes NO existing read behaviour and NEVER
     * throws or halts — the floor-safe "flag, don't crash the 24h runner" stance the resume path requires.
     * Older / equal versions are NOT flagged (only strictly-newer), so a legit older reader is never broken.
     *
     * @return list<string>
     */
    public static function newerThanSupported(string $path, string $schemaVersion): array
    {
        $supported = self::versionOrdinal($schemaVersion);
        $newer = [];
        foreach (self::rows($path) as $row) {
            $actual = $row['schema_version'] ?? null;
            // collect as VALUES (not keys) so numeric-string versions like "3" stay strings, not ints.
            if (is_string($actual) && $actual !== $schemaVersion && self::versionOrdinal($actual) > $supported) {
                $newer[] = $actual;
            }
        }

        return array_values(array_unique($newer));
    }

    /** Trailing-or-embedded integer of a schema version string ("1" => 1, "v3" => 3, "" => 0). */
    private static function versionOrdinal(string $version): int
    {
        return preg_match('/(\d+)/', $version, $m) === 1 ? (int) $m[1] : 0;
    }

    /**
     * Stream JSONL rows for large append-only ledgers without loading the file.
     *
     * @return \Generator<int,array<string,mixed>>
     */
    public static function streamRowsWithSchemaVersion(string $path, string $schemaVersion): \Generator
    {
        foreach (self::streamLines($path) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['schema_version'] ?? '') === $schemaVersion) {
                yield $decoded;
            }
        }
    }

    /**
     * Read rows for one schema while preserving the physical JSONL line index.
     *
     * @return list<array<string,mixed>>
     */
    public static function rowsWithSchemaVersionAndSequence(string $path, string $schemaVersion, string $sequenceKey): array
    {
        $rows = [];
        foreach (self::lines($path) as $index => $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && (string) ($decoded['schema_version'] ?? '') === $schemaVersion) {
                $decoded[$sequenceKey] = $index;
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function rowsWithPresentKey(string $path, string $requiredKey): array
    {
        return array_values(array_filter(
            self::rows($path),
            static fn (array $row): bool => isset($row[$requiredKey])
        ));
    }

    /**
     * Return the latest valid row matching a schema and exact scalar key value.
     *
     * @return array<string,mixed>|null
     */
    public static function latestRowWithSchemaValue(string $path, string $schemaVersion, string $key, string $value): ?array
    {
        if ($value === '') {
            return null;
        }

        $rows = self::rowsWithSchemaVersion($path, $schemaVersion);
        for ($index = count($rows) - 1; $index >= 0; $index--) {
            if ((string) ($rows[$index][$key] ?? '') === $value) {
                return $rows[$index];
            }
        }

        return null;
    }

    /**
     * Read JSONL rows whose required key is a string, counting absent/malformed
     * lines as corrupted instead of throwing.
     *
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    public static function rowsWithStringKey(string $path, string $requiredKey): array
    {
        return AppendOnlyJsonlStore::readWhereWithRejectedCount(
            $path,
            static fn (array $row): bool => isset($row[$requiredKey]) && is_string($row[$requiredKey]),
        );
    }

    /**
     * @return list<string>
     */
    private static function lines(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        return file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }

    /**
     * @return \Generator<int,string>
     */
    private static function streamLines(string $path): \Generator
    {
        if (! is_file($path)) {
            return;
        }

        $file = new \SplFileObject($path, 'rb');
        while (! $file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line !== '') {
                yield $line;
            }
        }
    }
}
