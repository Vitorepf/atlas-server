<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Acos;

use Throwable;

/**
 * Append/replace a dated snapshot in a JSONL ACOS delta series file.
 *
 * Full-pass reuse: de-duplicates private appendSnapshot() on AtlasAcosDeltaSeries* commands.
 *
 * @param  callable(array<string, mixed>): string  $encodeLine
 * @param  callable(string): (list<array<string, mixed>>|null)  $readSeries
 * @param  array<string, mixed>  $snapshot
 * @return list<array<string, mixed>>|null
 */
final class AcosDeltaSeriesJsonl
{
    /**
     * @return list<array<string, mixed>>|null  null = unreadable (not empty)
     */
    public static function readSeries(string $path): ?array
    {
        if (! is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $rows = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function encodeLine(array $row): string
    {
        return (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function appendSnapshot(string $path, array $snapshot, ?callable $encodeLine = null, ?callable $readSeries = null): ?array
    {
        $encodeLine ??= static fn (array $row): string => self::encodeLine($row);
        $readSeries ??= static fn (string $seriesPath): ?array => self::readSeries($seriesPath);
        try {
            $series = $readSeries($path);
            if ($series === null) {
                return null;
            }

            $date = (string) ($snapshot['date'] ?? '');
            $series = array_values(array_filter(
                $series,
                static fn (array $row): bool => (string) ($row['date'] ?? '') !== $date,
            ));
            $series[] = $snapshot;

            usort($series, static fn (array $a, array $b): int => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

            $dir = dirname($path);
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                return null;
            }

            $lines = array_map(static fn (array $row): string => $encodeLine($row), $series);
            if (@file_put_contents($path, implode("\n", $lines)."\n") === false) {
                return null;
            }

            return $series;
        } catch (Throwable) {
            return null;
        }
    }
}
