<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

/**
 * @deprecated Use JsonlReceiptStore in EngineeringKernel/Adapters instead.
 *             This class is now a thin forwarder kept for backward compatibility.
 */
final class AppendOnlyJsonlStore
{
    public const DEFAULT_JSON_FLAGS = JsonlReceiptStore::DEFAULT_JSON_FLAGS;

    /**
     * @return list<array<string,mixed>>
     */
    public static function read(string $path): array
    {
        return (new JsonlReceiptStore($path))->read();
    }

    /**
     * @param  callable(array<string,mixed>):bool  $accept
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    public static function readWhereWithRejectedCount(string $path, callable $accept): array
    {
        return (new JsonlReceiptStore($path))->readWhereWithRejectedCount($accept);
    }

    /**
     * @return list<string>
     */
    public static function jsonlFilesInDirectory(string $dir): array
    {
        return (new JsonlReceiptStore($dir.'/dummy.jsonl'))->jsonlFilesInDirectory();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function append(string $path, array $payload, int $jsonFlags = self::DEFAULT_JSON_FLAGS): void
    {
        (new JsonlReceiptStore($path))->append($payload, $jsonFlags);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    public static function rewrite(string $path, array $rows, int $jsonFlags = self::DEFAULT_JSON_FLAGS): void
    {
        (new JsonlReceiptStore($path))->rewrite($rows, $jsonFlags);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    public static function rewriteAtomic(string $path, array $rows, int $jsonFlags = self::DEFAULT_JSON_FLAGS): void
    {
        (new JsonlReceiptStore($path))->rewriteAtomic($rows, $jsonFlags);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function appendUsingFilePutContents(
        string $path,
        array $payload,
        int $jsonFlags,
        int $writeFlags = FILE_APPEND | LOCK_EX,
        int $directoryMode = 0775,
    ): void {
        (new JsonlReceiptStore($path))->appendUsingFilePutContents($payload, $jsonFlags, $writeFlags, $directoryMode);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function appendSilently(string $path, array $payload, int $jsonFlags): void
    {
        (new JsonlReceiptStore($path))->appendSilently($payload, $jsonFlags);
    }

    public static function appendEncodedLineSilently(
        string $path,
        string $line,
        int $writeFlags = FILE_APPEND | LOCK_EX,
        int $directoryMode = 0775,
    ): void {
        (new JsonlReceiptStore($path))->appendEncodedLineSilently($line, $writeFlags, $directoryMode);
    }

    /**
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
        (new JsonlReceiptStore($path))->appendRowsUsingFilePutContents($rows, $encodeRow, $writeFlags, $directoryMode);
    }
}
