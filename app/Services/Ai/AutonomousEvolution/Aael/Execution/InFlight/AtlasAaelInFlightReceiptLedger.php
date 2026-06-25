<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight;

use RuntimeException;

/**
 * Append-only persistence for inter-step ValidationFact and rolling DriftFact receipts emitted
 * during an AAEL run. Keyed by aael_run_id + step_index; receipts are byte-stable JSON with
 * monotonic sequence ids per ledger file. No in-place updates — re-appending the same payload
 * always produces a NEW line with a fresh seq.
 */
final class AtlasAaelInFlightReceiptLedger
{
    public const SCHEMA = 'atlas.aael.inflight_receipt.v1';

    public const KIND_VALIDATION = 'validation';

    public const KIND_DRIFT = 'drift';

    public function __construct(private readonly string $ledgerPath) {}

    /**
     * @param  array<string,mixed>  $fact
     * @return array<string,mixed>
     */
    public function appendValidation(string $aaelRunId, int $stepIndex, array $fact): array
    {
        return $this->append($aaelRunId, $stepIndex, self::KIND_VALIDATION, $fact);
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return array<string,mixed>
     */
    public function appendDrift(string $aaelRunId, int $stepIndex, array $fact): array
    {
        return $this->append($aaelRunId, $stepIndex, self::KIND_DRIFT, $fact);
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return array<string,mixed>
     */
    private function append(string $aaelRunId, int $stepIndex, string $kind, array $fact): array
    {
        $aaelRunId = trim($aaelRunId);
        if ($aaelRunId === '') {
            throw new RuntimeException('aael_inflight_receipt_missing_run_id');
        }
        $dir = \dirname($this->ledgerPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('aael_inflight_receipt_mkdir_failed:'.$dir);
        }

        $fh = @fopen($this->ledgerPath, 'cb+');
        if (! is_resource($fh)) {
            throw new RuntimeException('aael_inflight_receipt_open_failed');
        }
        try {
            if (! @flock($fh, LOCK_EX)) {
                throw new RuntimeException('aael_inflight_receipt_lock_failed');
            }

            $seq = $this->nextSeq($fh);
            $receipt = $this->canonicalize([
                'schema' => self::SCHEMA,
                'seq' => $seq,
                'aael_run_id' => $aaelRunId,
                'step_index' => $stepIndex,
                'kind' => $kind,
                'fact' => $fact,
            ]);
            $line = (string) json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            fseek($fh, 0, SEEK_END);
            if (fwrite($fh, $line."\n") === false) {
                throw new RuntimeException('aael_inflight_receipt_write_failed');
            }
            @fflush($fh);

            return $receipt;
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forRun(string $aaelRunId): array
    {
        $aaelRunId = trim($aaelRunId);
        if ($aaelRunId === '' || ! is_file($this->ledgerPath)) {
            return [];
        }
        $rows = [];
        foreach ((array) file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (! is_array($decoded)) {
                continue;
            }
            if ((string) ($decoded['aael_run_id'] ?? '') === $aaelRunId) {
                $rows[] = $decoded;
            }
        }
        usort($rows, static fn (array $a, array $b): int => ((int) $a['seq']) <=> ((int) $b['seq']));

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $rows = [];
        foreach ((array) file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    public function ledgerPath(): string
    {
        return $this->ledgerPath;
    }

    /**
     * Compute the next monotonic sequence id by scanning the open file's existing receipts.
     *
     * @param  resource  $fh
     */
    private function nextSeq($fh): int
    {
        rewind($fh);
        $max = 0;
        while (($line = fgets($fh)) !== false) {
            $decoded = json_decode((string) trim($line), true);
            if (is_array($decoded) && isset($decoded['seq']) && (int) $decoded['seq'] > $max) {
                $max = (int) $decoded['seq'];
            }
        }

        return $max + 1;
    }

    /**
     * Recursively ksort associative arrays (list arrays preserve order).
     *
     * @param  array<string|int,mixed>  $value
     * @return array<string|int,mixed>
     */
    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->canonicalize($v);
            }
        }

        return $value;
    }
}
