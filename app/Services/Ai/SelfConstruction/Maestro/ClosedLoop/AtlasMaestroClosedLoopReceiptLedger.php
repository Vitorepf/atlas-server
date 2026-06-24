<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

use DomainException;
use Generator;
use Throwable;

final class AtlasMaestroClosedLoopReceiptLedger
{
    public const SCHEMA = 'atlas.maestro.closed_loop.receipt_ledger.v1';

    public function __construct(
        private readonly ?string $path = null,
        private readonly ?string $shapeLedgerPath = null,
    ) {
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function recordCycle(array $payload): array
    {
        $entry = [
            'schema' => self::SCHEMA,
            'timestamp' => (string) ($payload['timestamp'] ?? gmdate('c')),
            'ledger_snapshot_hash' => $this->ledgerSnapshotHash(),
            'mined_bucket_count' => max(0, (int) ($payload['mined_bucket_count'] ?? 0)),
            'guarded_pass_or_reject' => $this->passOrReject($payload['guarded_pass_or_reject'] ?? null),
            'feedback_block_sha256' => $this->feedbackHash((string) ($payload['feedback_block'] ?? '')),
            'replenisher_consumed' => (bool) ($payload['replenisher_consumed'] ?? false),
            'flag_enabled' => (bool) ($payload['flag_enabled'] ?? false),
        ];

        $this->append($entry);

        return $entry;
    }

    /**
     * @return Generator<int,array<string,mixed>>
     */
    public function stream(): Generator
    {
        $path = $this->ledgerPath();
        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    yield $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function append(array $entry): void
    {
        $path = $this->ledgerPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        $lockPath = $path.'.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new DomainException('closed_loop_receipt_ledger_lock_open_failed');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new DomainException('closed_loop_receipt_ledger_lock_failed');
            }

            $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $current = is_file($path) ? (string) file_get_contents($path) : '';
            $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
            file_put_contents($tmp, $current.$line.PHP_EOL, LOCK_EX);
            rename($tmp, $path);
        } catch (Throwable $exception) {
            throw $exception instanceof DomainException
                ? $exception
                : new DomainException('closed_loop_receipt_ledger_append_failed', previous: $exception);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function passOrReject(mixed $value): string
    {
        return $value === 'pass' ? 'pass' : 'reject';
    }

    private function feedbackHash(string $block): string
    {
        return hash('sha256', $block);
    }

    private function ledgerSnapshotHash(): string
    {
        $path = $this->shapeLedgerPath ?? storage_path('atlas/loop/maestro/closed-loop/shape-ledger.jsonl');

        return hash('sha256', is_file($path) ? (string) file_get_contents($path) : '');
    }

    private function ledgerPath(): string
    {
        return $this->path ?? storage_path('atlas/loop/maestro/closed-loop/receipts.jsonl');
    }
}
