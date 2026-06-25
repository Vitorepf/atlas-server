<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ReentrySafety;

/**
 * Append-only ledger of every reentry decision (FRESH / ALREADY_DONE / TORN). Proves no
 * double-merge / double-receipt / double-claim happened on resume.
 *
 * INVARIANTS:
 *   - Writes are ATOMIC: full file rebuild → temp file → rename. A crash mid-write leaves the
 *     old file intact.
 *   - Self-reentry-safe: on next open(), if the tail entry's content_hash does not match its
 *     payload, the torn entry is REMOVED before accepting new appends.
 *   - Entries carry: cycle_id, phase, kind, key, decision, prior_ref, recorded_at_unix,
 *     monotonic_seq, content_hash.
 */
final class AtlasLoopCycleReentryReceiptLedger
{
    public function __construct(private readonly string $ledgerPath) {}

    /**
     * Append one reentry decision entry. Returns the written row.
     *
     * @return array<string,mixed>
     */
    public function record(
        string $cycleId,
        string $phase,
        string $kind,
        string $key,
        string $decision,
        ?string $priorRef = null,
        ?int $recordedAtUnix = null,
        ?int $monotonicSeq = null,
    ): array {
        $rows = $this->loadRowsRepairingTornTail();
        $seq = $monotonicSeq ?? (count($rows) > 0 ? (int) ($rows[count($rows) - 1]['monotonic_seq'] ?? 0) + 1 : 1);
        $row = [
            'cycle_id' => $cycleId,
            'phase' => $phase,
            'kind' => $kind,
            'key' => $key,
            'decision' => $decision,
            'prior_ref' => $priorRef,
            'recorded_at_unix' => $recordedAtUnix ?? time(),
            'monotonic_seq' => $seq,
        ];
        $row['content_hash'] = $this->contentHash($row);
        $rows[] = $row;
        $this->writeAtomic($rows);

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function query(string $cycleId): array
    {
        $rows = $this->loadRowsRepairingTornTail(repair: false);

        return array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['cycle_id'] ?? '') === $cycleId));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function allRows(): array
    {
        return $this->loadRowsRepairingTornTail(repair: false);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadRowsRepairingTornTail(bool $repair = true): array
    {
        if (! is_file($this->ledgerPath)) {
            return [];
        }
        $rows = [];
        $fh = @fopen($this->ledgerPath, 'rb');
        if ($fh === false) {
            return [];
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\n");
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        } finally {
            fclose($fh);
        }

        if ($repair && $rows !== []) {
            $tail = $rows[count($rows) - 1];
            $stated = (string) ($tail['content_hash'] ?? '');
            $recomputed = $this->contentHash($tail);
            if ($stated !== $recomputed) {
                array_pop($rows);
                $this->writeAtomic($rows);
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function writeAtomic(array $rows): void
    {
        $dir = \dirname($this->ledgerPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return;
        }
        $tmp = $this->ledgerPath.'.tmp.'.bin2hex(random_bytes(4));
        $lines = [];
        foreach ($rows as $r) {
            $lines[] = (string) json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        @file_put_contents($tmp, implode("\n", $lines).(count($lines) > 0 ? "\n" : ''), LOCK_EX);
        @rename($tmp, $this->ledgerPath);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function contentHash(array $row): string
    {
        $body = $row;
        unset($body['content_hash']);
        ksort($body);

        return hash('sha256', (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
