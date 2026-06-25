<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Retention;

/**
 * Append-only audit ledger for {@see AtlasLoopSnapshotRetentionGc} advisory output.
 *
 * Each `record()` call appends ONE Decision Receipt v2-shaped JSON line to the canonical
 * per-day file `<root>/YYYY-MM-DD.jsonl`. The class exposes NO public update/delete/truncate/
 * clear methods by design — append-only by construction.
 */
final class AtlasLoopSnapshotRetentionReceiptLedger
{
    public const SCHEMA = 'atlas.loop.snapshot_retention_receipt.v1';

    /**
     * @param  string  $rootPath  filesystem root for per-day jsonl files
     */
    public function __construct(private readonly string $rootPath)
    {
        if (! is_dir($this->rootPath)) {
            @mkdir($this->rootPath, 0o755, true);
        }
    }

    /**
     * Append ONE receipt for the supplied advisory to today's jsonl file.
     *
     * @param  array<string,mixed>  $advisory  output of AtlasLoopSnapshotRetentionGc::scan()
     * @return array<string,mixed>  the receipt that was appended
     */
    public function record(array $advisory): array
    {
        $scannedAt = (string) ($advisory['scanned_at'] ?? '');
        $day = $this->dayFromScannedAt($scannedAt);

        $receipt = [
            'schema_version' => self::SCHEMA,
            'receipt_id' => $this->receiptId($scannedAt, $advisory),
            'scanned_at' => $scannedAt,
            'policy_fingerprint' => (string) ($advisory['policy_fingerprint'] ?? ''),
            'kept_count' => (int) ($advisory['totals']['kept'] ?? count((array) ($advisory['kept_ids'] ?? []))),
            'prune_candidate_count' => (int) ($advisory['totals']['prune_candidate'] ?? count((array) ($advisory['prune_candidate_ids'] ?? []))),
            'prune_candidate_ids' => array_values(array_map('strval', (array) ($advisory['prune_candidate_ids'] ?? []))),
            'advisory_only' => true,
        ];

        $line = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $file = $this->fileForDay($day);
        // O_APPEND open via fopen('a') is atomic per line on local FS.
        $handle = @fopen($file, 'a');
        if ($handle === false) {
            throw new \RuntimeException('snapshot retention ledger: cannot open '.$file.' for append');
        }
        try {
            fwrite($handle, $line."\n");
        } finally {
            fclose($handle);
        }

        return $receipt;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function readAll(): array
    {
        $rows = [];
        foreach (glob($this->rootPath.'/*.jsonl') ?: [] as $file) {
            foreach ($this->readJsonl($file) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function readByDay(string $day): array
    {
        $file = $this->fileForDay($day);
        if (! is_file($file)) {
            return [];
        }

        return $this->readJsonl($file);
    }

    public function fingerprint(): string
    {
        $files = glob($this->rootPath.'/*.jsonl') ?: [];
        sort($files);
        $size = 0;
        foreach ($files as $f) {
            $size += (int) filesize($f);
        }

        return 'ledger_'.substr(hash('sha256', implode('|', $files).':'.$size), 0, 24);
    }

    private function dayFromScannedAt(string $scannedAt): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $scannedAt, $m) === 1) {
            return $m[1];
        }

        return 'unknown';
    }

    /**
     * @param  array<string,mixed>  $advisory
     */
    private function receiptId(string $scannedAt, array $advisory): string
    {
        $canonical = json_encode([
            'scanned_at' => $scannedAt,
            'policy_fingerprint' => $advisory['policy_fingerprint'] ?? '',
            'kept_ids' => $advisory['kept_ids'] ?? [],
            'prune_candidate_ids' => $advisory['prune_candidate_ids'] ?? [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'receipt_'.substr(hash('sha256', (string) $canonical.':'.uniqid('', true)), 0, 24);
    }

    private function fileForDay(string $day): string
    {
        return $this->rootPath.'/'.$day.'.jsonl';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $file): array
    {
        $rows = [];
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        fclose($handle);

        return $rows;
    }
}
