<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\Adapters;

use App\Services\Ai\EngineeringKernel\ReceiptLedger;
use RuntimeException;

/**
 * The ONE append-only JSONL line engine behind the kernel ReceiptLedger interface.
 *
 * Consolidates the fopen/mkdir/flock(LOCK_EX)/json-line mechanics that ledger classes across
 * AutonomousEvolution and SelfConstruction each hand-rolled with subtle drift. Domain payload
 * shaping (hash chains, schemas, sidecars) stays in the owning ledger; only raw line IO lives
 * here.
 *
 * appendWith() exists because chained ledgers must derive their prev-hash from the CURRENT tail
 * inside the same exclusive lock that writes the next line — handing them append(array) alone
 * would reintroduce the read-outside-lock race each of them privately solved.
 */
final class JsonlReceiptStore implements ReceiptLedger
{
    public const DEFAULT_JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Decoded lines, oldest-first. Undecodable lines are skipped.
     *
     * @return list<array<string,mixed>>
     */
    public function read(): array
    {
        return $this->replay();
    }

    /**
     * @param  callable(array<string,mixed>):bool  $accept
     * @return array{0:list<array<string,mixed>>,1:int}
     */
    public function readWhereWithRejectedCount(callable $accept): array
    {
        $rows = [];
        $rejected = 0;
        foreach ($this->rawLines() as $line) {
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
    public function jsonlFilesInDirectory(): array
    {
        $dir = \dirname($this->path);
        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter((array) glob($dir.DIRECTORY_SEPARATOR.'*.jsonl'), 'is_string'));
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function append(array $receipt, ?int $jsonFlags = null): array
    {
        $line = $this->appendWith(static fn (?string $lastLine): array => $receipt, $jsonFlags);

        return ['path' => $this->path, 'line' => $line, 'receipt' => $receipt];
    }

    /**
     * Run $build under the exclusive write lock, giving it the current last raw line (null on an
     * empty ledger), and append its returned payload as one canonical JSON line. A null return
     * from $build skips the write (idempotent ledgers dedup INSIDE the lock and abort).
     *
     * @param  callable(?string):(array<string,mixed>|null)  $build
     * @return string|null the raw line written, or null when $build aborted
     */
    public function appendWith(callable $build, ?int $jsonFlags = null): ?string
    {
        $dir = \dirname($this->path);
        if (file_exists($dir) && ! is_dir($dir)) {
            throw new RuntimeException('jsonl_receipt_store_parent_not_directory:'.$dir);
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('jsonl_receipt_store_mkdir_failed:'.$dir);
        }
        $fh = @fopen($this->path, 'ab+');
        if ($fh === false) {
            throw new RuntimeException('jsonl_receipt_store_open_failed:'.$this->path);
        }
        try {
            if (! @flock($fh, LOCK_EX)) {
                throw new RuntimeException('jsonl_receipt_store_lock_failed:'.$this->path);
            }
            $lines = $this->rawLines();
            $last = $lines === [] ? null : (string) end($lines);
            $payload = $build($last);
            if ($payload === null) {
                return null;
            }
            // PRESERVE_ZERO_FRACTION: signed/hashed payloads canonicalize floats as "1.0"; storage
            // must round-trip them identically or disk re-verification of signatures breaks.
            $flags = $jsonFlags ?? JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
            $line = (string) json_encode($payload, $flags);
            fseek($fh, 0, SEEK_END);
            fwrite($fh, $line."\n");
            fflush($fh);

            return $line;
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Preserve legacy best-effort JSONL appends: directory creation is attempted,
     * fopen is suppressed, an unopenable file is ignored, and JSON encoding does
     * not throw unless the caller passes JSON_THROW_ON_ERROR.
     *
     * @param  array<string,mixed>  $payload
     */
    public function appendSilently(array $payload, int $jsonFlags): void
    {
        $dir = \dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        $fp = @fopen($this->path, 'ab');
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

    /**
     * Preserve legacy file_put_contents JSONL append semantics: no fopen
     * exception path, caller-provided file write flags, and caller-provided JSON flags.
     *
     * @param  array<string,mixed>  $payload
     */
    public function appendUsingFilePutContents(
        array $payload,
        int $jsonFlags,
        int $writeFlags = FILE_APPEND | LOCK_EX,
        int $directoryMode = 0775,
    ): void {
        self::ensureDirectory(\dirname($this->path), $directoryMode);

        file_put_contents($this->path, json_encode($payload, $jsonFlags).PHP_EOL, $writeFlags);
    }

    /**
     * Preserve legacy pre-encoded line append semantics.
     */
    public function appendEncodedLineSilently(
        string $line,
        int $writeFlags = FILE_APPEND | LOCK_EX,
        int $directoryMode = 0775,
    ): void {
        self::ensureDirectory(\dirname($this->path), $directoryMode);

        @file_put_contents($this->path, $line.PHP_EOL, $writeFlags);
    }

    /**
     * Preserve legacy batch JSONL appends: all rows are encoded into one buffer
     * and written through one file_put_contents call.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  callable(array<string,mixed>):string  $encodeRow
     */
    public function appendRowsUsingFilePutContents(
        array $rows,
        callable $encodeRow,
        int $writeFlags = FILE_APPEND | LOCK_EX,
        int $directoryMode = 0775,
    ): void {
        self::ensureDirectory(\dirname($this->path), $directoryMode);

        $buffer = '';
        foreach ($rows as $row) {
            $buffer .= $encodeRow($row).PHP_EOL;
        }

        file_put_contents($this->path, $buffer, $writeFlags);
    }

    /**
     * Replace a JSONL file with the current row set while preserving the same
     * locked writer semantics as append().
     *
     * @param  list<array<string,mixed>>  $rows
     */
    public function rewrite(array $rows, ?int $jsonFlags = null): void
    {
        $dir = \dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('jsonl_receipt_store_mkdir_failed:'.$dir);
        }

        // 'cb' (create, NO truncate): 'wb' truncava o arquivo ANTES do flock — um lock
        // que falhasse (ou leitor concorrente na janela) via/deixava o ledger vazio.
        // Truncamento agora só SOB o lock exclusivo. (Finding do review semântico
        // glm-5.2 sobre a landing op-01kwvx937, confirmado no código.)
        $fp = fopen($this->path, 'cb');
        if ($fp === false) {
            throw new RuntimeException('jsonl_receipt_store_open_failed:'.$this->path);
        }

        $flags = $jsonFlags ?? self::DEFAULT_JSON_FLAGS;

        try {
            if (! flock($fp, LOCK_EX)) {
                throw new RuntimeException('jsonl_receipt_store_lock_failed:'.$this->path);
            }

            ftruncate($fp, 0);
            rewind($fp);

            foreach ($rows as $row) {
                fwrite($fp, json_encode($row, $flags).PHP_EOL);
            }

            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }
    }

    /**
     * ARBOR-GRAFT LED1 — durable atomic rewrite. Unlike rewrite() (which opens the canonical path 'wb' and
     * can leave a torn file if the process is killed mid-write — a real hazard for the resume ledger of a
     * 24h soak), this writes the full row set to a temp sibling, flushes it to disk, then atomically
     * renames it over the canonical path. A crash at any point leaves EITHER the old complete file OR the
     * new complete file — never a half-written one. Mirrors the in-repo ReceiptStorage::writeAtomic pattern.
     *
     * @param  list<array<string,mixed>>  $rows
     */
    public function rewriteAtomic(array $rows, ?int $jsonFlags = null): void
    {
        $dir = \dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('jsonl_receipt_store_mkdir_failed:'.$dir);
        }

        $flags = $jsonFlags ?? self::DEFAULT_JSON_FLAGS;
        $tmp = $this->path.'.tmp.'.bin2hex(random_bytes(6));
        $fp = fopen($tmp, 'wb');
        if ($fp === false) {
            throw new RuntimeException('jsonl_receipt_store_open_failed:'.$tmp);
        }

        try {
            try {
                foreach ($rows as $row) {
                    fwrite($fp, json_encode($row, $flags).PHP_EOL);
                }
                fflush($fp);
                // Flush to physical disk before the rename so a power-loss can't leave a torn canonical file.
                if (function_exists('fdatasync')) {
                    @fdatasync($fp);
                } elseif (function_exists('fsync')) {
                    @fsync($fp);
                }
            } finally {
                fclose($fp);
            }
        } catch (\Throwable $e) {
            // json_encode com JSON_THROW_ON_ERROR pode lançar mid-write: sem isto o
            // .tmp ficava órfão pra sempre (finding p3 do review semântico glm-5.2).
            @unlink($tmp);
            throw $e;
        }

        if (! @rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException('jsonl_receipt_store_atomic_rename_failed:'.$this->path);
        }
    }

    /**
     * Decoded lines, oldest-first. Undecodable lines are skipped.
     *
     * @return list<array<string,mixed>>
     */
    public function replay(): array
    {
        $rows = [];
        foreach ($this->rawLines() as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * Raw non-empty lines, oldest-first — for ledgers that keep their own decode/corrupt policy.
     *
     * @return list<string>
     */
    public function rawLines(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        return array_values((array) file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    private static function ensureDirectory(string $dir, int $mode = 0775): void
    {
        if (is_dir($dir)) {
            return;
        }

        @mkdir($dir, $mode, true);
    }
}
