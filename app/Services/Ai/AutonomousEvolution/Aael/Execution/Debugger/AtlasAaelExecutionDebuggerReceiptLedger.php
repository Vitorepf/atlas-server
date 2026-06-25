<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger;

use RuntimeException;

final class AtlasAaelExecutionDebuggerReceiptLedger
{
    public const SCHEMA = 'atlas.aael.execution.debugger.receipt.v2';
    public const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly string $storageRoot)
    {
        if (! is_dir($this->storageRoot)) {
            @mkdir($this->storageRoot, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed> the persisted canonical entry
     */
    public function append(string $runId, int $stepIndex, string $event, string $tsIso8601, array $extra = []): array
    {
        $path = $this->ledgerPath($runId);
        $handle = $this->openLocked($path);
        try {
            $prevHash = $this->tailHash($handle);
            $entry = new DebuggerReceiptEntry($tsIso8601, $runId, $stepIndex, $event, $prevHash, $extra);
            $bytes = $entry->canonicalBytes()."\n";
            fseek($handle, 0, SEEK_END);
            if (fwrite($handle, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('debugger_receipt_short_write');
            }
            fflush($handle);

            return $entry->toCanonicalArray();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return list<array<string,mixed>>
     * @throws HashChainBrokenException
     */
    public function replay(string $runId): array
    {
        $path = $this->ledgerPath($runId);
        if (! is_file($path)) {
            return [];
        }
        $rows = [];
        $expected = self::GENESIS_PREV_HASH;
        $index = 0;
        foreach ((array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (! is_array($decoded)) {
                throw new HashChainBrokenException(sprintf('malformed_line run_id=%s index=%d', $runId, $index));
            }
            $prev = (string) ($decoded['prev_entry_sha256'] ?? '');
            if ($prev !== $expected) {
                throw new HashChainBrokenException(sprintf('hash_chain_broken run_id=%s index=%d expected=%s got=%s', $runId, $index, $expected, $prev));
            }
            $canonical = $this->reCanonicalize($decoded);
            if ($canonical !== (string) $line) {
                throw new HashChainBrokenException(sprintf('canonical_mismatch run_id=%s index=%d', $runId, $index));
            }
            $expected = hash('sha256', $canonical);
            $rows[] = $decoded;
            $index++;
        }

        return $rows;
    }

    /** @param array<string,mixed> $decoded */
    private function reCanonicalize(array $decoded): string
    {
        ksort($decoded);

        return (string) json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param resource $handle */
    private function tailHash($handle): string
    {
        rewind($handle);
        $last = null;
        while (($line = fgets($handle)) !== false) {
            $trim = rtrim($line, "\n");
            if ($trim !== '') {
                $last = $trim;
            }
        }
        if ($last === null) {
            return self::GENESIS_PREV_HASH;
        }

        return hash('sha256', $last);
    }

    /** @return resource */
    private function openLocked(string $path)
    {
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('debugger_receipt_open_failed:'.$path);
        }
        if (! flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('debugger_receipt_lock_failed:'.$path);
        }

        return $handle;
    }

    private function ledgerPath(string $runId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $runId) ?? $runId;

        return rtrim($this->storageRoot, '/').'/'.$safe.'.receipts.jsonl';
    }
}

final class HashChainBrokenException extends RuntimeException {}
