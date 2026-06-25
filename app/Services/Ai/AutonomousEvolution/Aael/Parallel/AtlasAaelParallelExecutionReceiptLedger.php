<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Parallel;

/**
 * Append-only receipt ledger for parallel sub-step executions. One JSON object per line under
 * storage/atlas/aael/parallel/receipts/<YYYY-MM-DD>.ndjson.
 *
 * INVARIANTS:
 *   - Append-only: never rewrites; flock(LOCK_EX) around writes.
 *   - Crash-tolerant: read() detects a partial trailing line (no terminating \n + invalid JSON)
 *     and quarantines it (skipped + a `.partial` sidecar copies the bad bytes).
 *   - receipt_id = sha256 over canonical body (step_id|group_index|lock_handle_id|start_ts|end_ts).
 */
final class AtlasAaelParallelExecutionReceiptLedger
{
    private static ?string $rootOverride = null;

    public static function setRootForTesting(?string $root): void
    {
        self::$rootOverride = $root;
    }

    /**
     * @param  array<string,mixed>  $receipt {step_id, group_index, write_set, lock_handle_id, start_ts, end_ts, exit_status, files_touched_sha256, day?:string}
     */
    public function append(array $receipt): string
    {
        $day = (string) ($receipt['day'] ?? gmdate('Y-m-d'));
        $body = [
            'step_id' => (string) ($receipt['step_id'] ?? ''),
            'group_index' => (int) ($receipt['group_index'] ?? 0),
            'write_set' => array_values(array_map('strval', (array) ($receipt['write_set'] ?? []))),
            'lock_handle_id' => (string) ($receipt['lock_handle_id'] ?? ''),
            'start_ts' => (int) ($receipt['start_ts'] ?? 0),
            'end_ts' => (int) ($receipt['end_ts'] ?? 0),
            'exit_status' => (string) ($receipt['exit_status'] ?? 'unknown'),
            'files_touched_sha256' => (string) ($receipt['files_touched_sha256'] ?? ''),
        ];
        $body['receipt_id'] = $this->receiptId($body);

        $path = $this->pathForDay($day);
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('aael_receipt_mkdir_failed:'.$dir);
        }
        $line = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $fh = @fopen($path, 'ab');
        if ($fh === false) {
            throw new \RuntimeException('aael_receipt_open_failed:'.$path);
        }
        try {
            @flock($fh, LOCK_EX);
            fwrite($fh, $line."\n");
        } finally {
            @flock($fh, LOCK_UN);
            fclose($fh);
        }

        return $body['receipt_id'];
    }

    /**
     * @return iterable<array<string,mixed>>
     */
    public function read(string $day): iterable
    {
        $path = $this->pathForDay($day);
        if (! is_file($path)) {
            return;
        }
        $raw = (string) file_get_contents($path);
        if ($raw === '') {
            return;
        }
        $endsWithNewline = str_ends_with($raw, "\n");
        $lines = explode("\n", rtrim($raw, "\n"));
        $lastIndex = count($lines) - 1;
        foreach ($lines as $i => $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                // Quarantine: if this is the trailing partial line (no terminating newline), copy
                // to a .partial sidecar and SKIP; never silently truncate.
                if ($i === $lastIndex && ! $endsWithNewline) {
                    @file_put_contents($path.'.partial', $line);

                    continue;
                }
                // Mid-file malformed line: also quarantine, do not yield.
                @file_put_contents($path.'.partial', ($line)."\n", FILE_APPEND);

                continue;
            }
            yield $decoded;
        }
    }

    /**
     * @param  array<string,mixed>  $body
     */
    public function receiptId(array $body): string
    {
        $canonical = $body;
        unset($canonical['receipt_id']);
        ksort($canonical);

        return hash('sha256', (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function root(): string
    {
        return self::$rootOverride ?? storage_path('atlas/aael/parallel/receipts');
    }

    public function pathForDay(string $day): string
    {
        return $this->root().'/'.$day.'.ndjson';
    }
}
