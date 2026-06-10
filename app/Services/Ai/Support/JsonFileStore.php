<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use RuntimeException;

final class JsonFileStore
{
    public const DEFAULT_JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @return array<string,mixed>|null
     */
    public static function readArray(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function write(
        string $path,
        array $payload,
        int $jsonFlags = self::DEFAULT_JSON_FLAGS,
        int $writeFlags = 0,
        int $directoryMode = 0775,
    ): void {
        self::ensureDirectory(dirname($path), $directoryMode);

        file_put_contents($path, json_encode($payload, $jsonFlags), $writeFlags);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function writeLine(
        string $path,
        array $payload,
        int $jsonFlags = self::DEFAULT_JSON_FLAGS,
        int $writeFlags = 0,
        int $directoryMode = 0775,
    ): void {
        self::ensureDirectory(dirname($path), $directoryMode);

        file_put_contents($path, json_encode($payload, $jsonFlags).PHP_EOL, $writeFlags);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function writeAtomic(
        string $path,
        array $payload,
        int $jsonFlags = self::DEFAULT_JSON_FLAGS,
        int $directoryMode = 0775,
    ): void {
        self::ensureDirectory(dirname($path), $directoryMode);

        $tmp = $path.'.tmp-'.bin2hex(random_bytes(6));
        if (file_put_contents($tmp, json_encode($payload, $jsonFlags)) === false) {
            throw new RuntimeException("Could not write temporary JSON file {$tmp}.");
        }

        if (! rename($tmp, $path)) {
            @unlink($tmp);

            throw new RuntimeException("Could not replace JSON file {$path}.");
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function writeTemporary(
        string $directory,
        string $prefix,
        array $payload,
        int $jsonFlags = self::DEFAULT_JSON_FLAGS,
        int $directoryMode = 0775,
        ?int $fileMode = null,
    ): string {
        $safePrefix = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($prefix));
        $safePrefix = is_string($safePrefix) && $safePrefix !== '' ? trim($safePrefix, '-') : 'manifest';
        $safePrefix = $safePrefix !== '' ? $safePrefix : 'manifest';
        $path = rtrim($directory, '/').'/'.$safePrefix.'-'.bin2hex(random_bytes(8)).'.json';

        self::write($path, $payload, $jsonFlags, 0, $directoryMode);

        if ($fileMode !== null) {
            @chmod($path, $fileMode);
        }

        return $path;
    }

    public static function deleteQuietly(?string $path): void
    {
        if (is_string($path) && $path !== '') {
            @unlink($path);
        }
    }

    private static function ensureDirectory(string $dir, int $mode): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, $mode, true);
        }
    }
}
