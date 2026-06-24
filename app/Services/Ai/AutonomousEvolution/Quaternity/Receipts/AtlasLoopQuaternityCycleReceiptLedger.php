<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\Receipts;

use RuntimeException;

/**
 * QUATERNITY CYCLE RECEIPT LEDGER — append-only on-disk JSONL ledger of envelopes produced by
 * {@see AtlasLoopQuaternityCycleReceiptComposer}. Every line carries a monotonically-increasing seq and
 * prev_envelope_hash linking it to the previous line (64 hex zeros for the genesis line), so the file is a
 * tamper-evident hash chain: mutating any line's envelope_hash breaks the link recorded on the NEXT line and is
 * surfaced by {@see verify()}.
 *
 * Concurrency: append() uses an exclusive flock(LOCK_EX) over read-tail+write so two concurrent appenders cannot
 * interleave or compute the same prev_envelope_hash. Append-only by construction: there is NO public
 * update/delete/truncate method on this class.
 */
final class AtlasLoopQuaternityCycleReceiptLedger
{
    /** The prev_envelope_hash of the genesis (first) line — 64 hex zeros, fixed. */
    public const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private readonly string $ledgerFile;

    public function __construct(?string $ledgerFile = null)
    {
        $this->ledgerFile = $ledgerFile ?? self::defaultLedgerFile();
    }

    /**
     * Append one composed envelope to the chain. Returns the new sequence number (1-indexed).
     *
     * @param  array<string,mixed>  $envelope  one composer output (must carry an envelope_hash field)
     */
    public function append(array $envelope): int
    {
        $envelopeHash = (string) ($envelope['envelope_hash'] ?? '');
        if ($envelopeHash === '') {
            throw new RuntimeException('Quaternity receipt ledger refuses an envelope with empty envelope_hash');
        }

        $dir = dirname($this->ledgerFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($this->ledgerFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Quaternity receipt ledger cannot open file: '.$this->ledgerFile);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Quaternity receipt ledger cannot acquire exclusive lock');
            }

            [$lastSeq, $lastEnvelopeHash] = $this->readTail($handle);
            $seq = $lastSeq + 1;
            $prev = $lastSeq === 0 ? self::GENESIS_PREV_HASH : $lastEnvelopeHash;

            $line = [
                'seq' => $seq,
                'prev_envelope_hash' => $prev,
                'envelope' => $envelope,
            ];

            fseek($handle, 0, SEEK_END);
            fwrite($handle, (string) json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $seq;
    }

    /**
     * Walk the chain and flag any seq whose recorded prev_envelope_hash does not match the prior line's
     * envelope_hash (or the genesis hash, for seq 1). Returns one row per line.
     *
     * @return list<array{seq:int,ok:bool,reason?:string}>
     */
    public function verify(): array
    {
        $rows = [];
        $expectedPrev = self::GENESIS_PREV_HASH;
        foreach ($this->readLines() as $line) {
            $seq = (int) ($line['seq'] ?? 0);
            $recordedPrev = (string) ($line['prev_envelope_hash'] ?? '');
            $envelopeHash = (string) ($line['envelope']['envelope_hash'] ?? '');

            if (! hash_equals($expectedPrev, $recordedPrev)) {
                $rows[] = ['seq' => $seq, 'ok' => false, 'reason' => 'prev_envelope_hash mismatch: expected '.$expectedPrev.' got '.$recordedPrev];
            } else {
                $rows[] = ['seq' => $seq, 'ok' => true];
            }
            $expectedPrev = $envelopeHash;
        }

        return $rows;
    }

    /**
     * @param  resource  $handle
     * @return array{0:int,1:string}  [lastSeq, lastEnvelopeHash] — [0, ''] on empty file
     */
    private function readTail($handle): array
    {
        rewind($handle);
        $lastSeq = 0;
        $lastEnvelopeHash = '';
        while (($raw = fgets($handle)) !== false) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $line = json_decode($raw, true);
            if (! is_array($line)) {
                continue;
            }
            $lastSeq = (int) ($line['seq'] ?? $lastSeq);
            $lastEnvelopeHash = (string) ($line['envelope']['envelope_hash'] ?? $lastEnvelopeHash);
        }

        return [$lastSeq, $lastEnvelopeHash];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readLines(): array
    {
        if (! is_file($this->ledgerFile)) {
            return [];
        }
        $lines = [];
        foreach (file($this->ledgerFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $lines[] = $decoded;
            }
        }

        return $lines;
    }

    private static function defaultLedgerFile(): string
    {
        $base = function_exists('storage_path')
            ? storage_path('atlas/loop/quaternity')
            : sys_get_temp_dir().'/atlas/loop/quaternity';

        return $base.'/cycle-receipts.jsonl';
    }
}
