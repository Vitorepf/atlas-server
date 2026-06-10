<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class AreaFocusJsonlWriter
{
    /**
     * @param  array<string,mixed>  $recordPayload
     */
    public static function append(string $path, array $recordPayload): void
    {
        self::ensureDirectory($path);

        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }

        try {
            if (! flock($fp, LOCK_EX)) {
                throw new \RuntimeException("Could not lock {$path} for writing.");
            }

            fwrite($fp, self::line($recordPayload));
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $records
     */
    public static function rewrite(string $path, array $records): void
    {
        self::ensureDirectory($path);

        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for rewriting.");
        }

        try {
            if (! flock($fp, LOCK_EX)) {
                throw new \RuntimeException("Could not lock {$path} for rewriting.");
            }

            foreach ($records as $record) {
                fwrite($fp, self::line($record));
            }

            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }

    private static function ensureDirectory(string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Could not create {$dir} for JSONL write.");
        }
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private static function line(array $record): string
    {
        return json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}
