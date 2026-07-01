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
    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function append(array $receipt): array
    {
        $line = $this->appendWith(static fn (?string $lastLine): array => $receipt);

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
    public function appendWith(callable $build): ?string
    {
        $dir = \dirname($this->path);
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
            $line = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
}
