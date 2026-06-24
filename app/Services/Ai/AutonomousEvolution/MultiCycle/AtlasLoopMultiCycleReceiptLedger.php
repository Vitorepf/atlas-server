<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\MultiCycle;

use Closure;
use RuntimeException;

final class AtlasLoopMultiCycleReceiptLedger
{
    /**
     * @param  ?Closure():string  $clock
     * @param  ?Closure():string  $receiptIdGenerator
     */
    public function __construct(
        private readonly ?string $ledgerPath = null,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $receiptIdGenerator = null,
    ) {
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function record(string $eventType, string $cycleId, string $subScopeHash, array $payload): string
    {
        $receiptId = $this->receiptId();
        $row = [
            'schema_version' => 'atlas.decision_receipt.v2',
            'receipt_id' => $receiptId,
            'event_type' => $eventType,
            'cycle_id' => $cycleId,
            'sub_scope_hash' => $subScopeHash,
            'ts' => $this->now(),
            'payload' => $payload,
        ];

        $this->appendRow($row);

        return $receiptId;
    }

    /**
     * @return iterable<array<string,mixed>>
     */
    public function history(?string $cycleId = null): iterable
    {
        $path = $this->ledgerPath();
        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open receipt ledger: '.$path);
        }

        try {
            $rows = [];
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    continue;
                }

                if ($cycleId !== null && (string) ($decoded['cycle_id'] ?? '') !== $cycleId) {
                    continue;
                }

                $rows[] = $decoded;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    public function ledgerPath(): string
    {
        return $this->ledgerPath ?? storage_path('atlas/loop/multicycle/receipts.ndjson');
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function appendRow(array $row): void
    {
        $path = $this->ledgerPath();
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create receipt ledger directory: '.$dir);
        }

        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Unable to open receipt ledger: '.$path);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock receipt ledger: '.$path);
            }

            fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function receiptId(): string
    {
        if ($this->receiptIdGenerator !== null) {
            return ($this->receiptIdGenerator)();
        }

        return bin2hex(random_bytes(16));
    }

    private function now(): string
    {
        return $this->clock !== null
            ? ($this->clock)()
            : date(DATE_ATOM);
    }
}
