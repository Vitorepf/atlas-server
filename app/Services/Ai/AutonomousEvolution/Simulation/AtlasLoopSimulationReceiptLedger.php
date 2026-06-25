<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Simulation;

// PSR-4 maps DryRunReceipt to the dry-runner file; force load it before typehinting.
\class_exists(AtlasLoopSimulationDryRunner::class);

/**
 * Append-only JSONL ledger of every DryRunReceipt produced by AtlasLoopSimulationDryRunner.
 * Exposes ONLY append/history — no update, delete, truncate. flock(LOCK_EX) on every append
 * so 8 concurrent writers produce 8 well-formed lines (no interleaving). Each line carries a
 * prev_line_sha256 so any post-hoc tampering is detected on read.
 */
final class AtlasLoopSimulationReceiptLedger
{
    public const SCHEMA = 'atlas.loop.simulation_receipt_ledger.v1';

    public function __construct(private readonly ?string $path = null) {}

    public function append(DryRunReceipt $receipt): string
    {
        $path = $this->resolvePath();
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('atlas_simulation_ledger_mkdir_failed');
        }
        $fh = @fopen($path, 'ab+');
        if ($fh === false) {
            throw new \RuntimeException('atlas_simulation_ledger_open_failed');
        }
        try {
            if (! @flock($fh, LOCK_EX)) {
                throw new \RuntimeException('atlas_simulation_ledger_lock_failed');
            }
            $prev = $this->prevLineSha256($path);
            $receiptId = $this->uuidv7();
            $payload = [
                'schema' => self::SCHEMA,
                'receipt_id' => $receiptId,
                'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'prev_line_sha256' => $prev,
                'receipt' => $receipt->toArray(),
            ];
            ksort($payload, SORT_STRING);
            $line = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            fseek($fh, 0, SEEK_END);
            fwrite($fh, $line."\n");
            fflush($fh);

            return $receiptId;
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function history(int $limit = 50, ?string $sinceSha = null): array
    {
        $path = $this->resolvePath();
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        $expectedPrev = str_repeat('0', 64);
        $prevForChainCheck = '';
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (! is_array($decoded)) {
                continue;
            }
            $storedPrev = (string) ($decoded['prev_line_sha256'] ?? '');
            $decoded['chain_valid'] = ($storedPrev === $expectedPrev);
            $expectedPrev = hash('sha256', (string) $line);
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

    private function prevLineSha256(string $path): string
    {
        if (! is_file($path)) {
            return str_repeat('0', 64);
        }
        $lines = (array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === []) {
            return str_repeat('0', 64);
        }
        $last = (string) end($lines);

        return hash('sha256', $last);
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
