<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use RuntimeException;

final class AppendOnlyJsonlStore
{
    public const DEFAULT_JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @return list<array<string,mixed>>
     */
    public static function read(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @param  callable(array<string,mixed>):bool  $accept
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    public static function readWhereWithRejectedCount(string $path, callable $accept): array
    {
        if (! is_file($path)) {
            return [[], 0];
        }

        $rows = [];
        $rejected = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && $accept($decoded)) {
                $rows[] = $decoded;
            } else {
                $rejected++;
            }
        }

        return [$rows, $rejected];
    }

    /**
     * @return list<string>
     */
    public static function jsonlFilesInDirectory(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter((array) glob($dir.DIRECTORY_SEPARATOR.'*.jsonl'), 'is_string'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function append(string $path, array $payload, int $jsonFlags = self::DEFAULT_JSON_FLAGS): void
    {
        self::ensureDirectory(dirname($path));

        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new RuntimeException("Could not open {$path} for writing.");
        }

        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, $jsonFlags).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    /**
     * Preserve legacy file_put_contents JSONL append semantics: no fopen
     * exception path, caller-provided file write flags, and caller-provided JSON flags.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function appendUsingFilePutContents(
        string $path,
        array $payload,
        int $jsonFlags,
        int $writeFlags = FILE_APPEND | LOCK_EX,
        int $directoryMode = 0775,
    ): void {
        self::ensureDirectory(dirname($path), $directoryMode);

        file_put_contents($path, json_encode($payload, $jsonFlags).PHP_EOL, $writeFlags);
    }

    /**
     * Preserve legacy best-effort JSONL appends: directory creation is attempted,
     * fopen is suppressed, an unopenable file is ignored, and JSON encoding does
     * not throw unless the caller passes JSON_THROW_ON_ERROR.
     *
     * @param  array<string,mixed>  $payload
     */
    public static function appendSilently(string $path, array $payload, int $jsonFlags): void
    {
        self::ensureDirectory(dirname($path));

        $fp = @fopen($path, 'ab');
        if ($fp === false) {
            return;
        }

        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, $jsonFlags).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }

    public static function appendEncodedLineSilently(
        string $path,
        string $line,
        int $writeFlags = FILE_APPEND,
        int $directoryMode = 0775,
    ): void {
        self::ensureDirectory(dirname($path), $directoryMode);

        @file_put_contents($path, $line.PHP_EOL, $writeFlags);
    }

    /**
     * Preserve legacy batch JSONL appends: all rows are encoded into one buffer
     * and written through one file_put_contents call.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  callable(array<string,mixed>):string  $encodeRow
     */
    public static function appendRowsUsingFilePutContents(
        string $path,
        array $rows,
        callable $encodeRow,
        int $writeFlags = FILE_APPEND | LOCK_EX,
        int $directoryMode = 0775,
    ): void {
        self::ensureDirectory(dirname($path), $directoryMode);

        $buffer = '';
        foreach ($rows as $row) {
            $buffer .= $encodeRow($row).PHP_EOL;
        }

        file_put_contents($path, $buffer, $writeFlags);
    }

    private static function ensureDirectory(string $dir, int $mode = 0775): void
    {
        if (is_dir($dir)) {
            return;
        }

        @mkdir($dir, $mode, true);
    }
}
