<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Simulation;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

// PSR-4 maps DryRunReceipt to the dry-runner file; force load it before typehinting.
\class_exists(AtlasLoopSimulationDryRunner::class);

/**
 * Append-only JSONL ledger of every DryRunReceipt produced by AtlasLoopSimulationDryRunner.
 * Exposes ONLY append/history — no update, delete, truncate. Raw line IO (mkdir, exclusive-lock
 * append, line read-back) is delegated to the kernel {@see JsonlReceiptStore}; this class owns
 * only the domain payload: schema, receipt id, and the prev_line_sha256 chain so any post-hoc
 * tampering is detected on read. The chain hash is derived from the current tail INSIDE the
 * store's write lock, so 8 concurrent writers still produce 8 well-formed chained lines.
 */
final class AtlasLoopSimulationReceiptLedger
{
    public const SCHEMA = 'atlas.loop.simulation_receipt_ledger.v1';

    public function __construct(private readonly ?string $path = null) {}

    public function append(DryRunReceipt $receipt): string
    {
        $receiptId = $this->uuidv7();
        $this->store()->appendWith(function (?string $lastLine) use ($receipt, $receiptId): array {
            $payload = [
                'schema' => self::SCHEMA,
                'receipt_id' => $receiptId,
                'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'prev_line_sha256' => $lastLine === null ? str_repeat('0', 64) : hash('sha256', $lastLine),
                'receipt' => $receipt->toArray(),
            ];
            ksort($payload, SORT_STRING);

            return $payload;
        });

        return $receiptId;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function history(int $limit = 50, ?string $sinceSha = null): array
    {
        $rows = [];
        $expectedPrev = str_repeat('0', 64);
        foreach ($this->store()->rawLines() as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }
            $storedPrev = (string) ($decoded['prev_line_sha256'] ?? '');
            $decoded['chain_valid'] = ($storedPrev === $expectedPrev);
            $expectedPrev = hash('sha256', $line);
            $rows[] = $decoded;
        }
        if ($sinceSha !== null && $sinceSha !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => (string) ($r['prev_line_sha256'] ?? '') === $sinceSha
                    || strcmp((string) ($r['recorded_at'] ?? ''), $sinceSha) >= 0,
            ));
        }
        $rows = array_reverse($rows);
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        return array_values($rows);
    }

    public function path(): string
    {
        return $this->resolvePath();
    }

    private function store(): JsonlReceiptStore
    {
        return new JsonlReceiptStore($this->resolvePath());
    }

    private function resolvePath(): string
    {
        if ($this->path !== null && $this->path !== '') {
            return $this->path;
        }
        if (function_exists('storage_path')) {
            return storage_path('atlas/loop/simulation/receipts.jsonl');
        }

        return sys_get_temp_dir().'/atlas-loop-simulation-receipts.jsonl';
    }

    private function uuidv7(): string
    {
        $bytes = random_bytes(16);
        $tsMs = (int) (microtime(true) * 1000);
        $bytes[0] = chr(($tsMs >> 40) & 0xFF);
        $bytes[1] = chr(($tsMs >> 32) & 0xFF);
        $bytes[2] = chr(($tsMs >> 24) & 0xFF);
        $bytes[3] = chr(($tsMs >> 16) & 0xFF);
        $bytes[4] = chr(($tsMs >> 8) & 0xFF);
        $bytes[5] = chr($tsMs & 0xFF);
        $bytes[6] = chr((0x70) | (ord($bytes[6]) & 0x0F));
        $bytes[8] = chr((0x80) | (ord($bytes[8]) & 0x3F));
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
