<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\Receipts;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use RuntimeException;
use Throwable;

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

        $store = new JsonlReceiptStore($this->ledgerFile);
        $seq = 0;
        try {
            // Tail scan (seq + prev hash) runs INSIDE the store's exclusive lock.
            $store->appendWith(function (?string $lastLine) use ($store, $envelope, &$seq): array {
                $lastSeq = 0;
                $lastEnvelopeHash = '';
                foreach ($store->replay() as $line) {
                    $lastSeq = (int) ($line['seq'] ?? $lastSeq);
                    $lastEnvelopeHash = (string) ($line['envelope']['envelope_hash'] ?? $lastEnvelopeHash);
                }
                $seq = $lastSeq + 1;

                return [
                    'seq' => $seq,
                    'prev_envelope_hash' => $lastSeq === 0 ? self::GENESIS_PREV_HASH : $lastEnvelopeHash,
                    'envelope' => $envelope,
                ];
            });
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
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
     * @return list<array<string,mixed>>
     */
    private function readLines(): array
    {
        return (new JsonlReceiptStore($this->ledgerFile))->replay();
    }

    private static function defaultLedgerFile(): string
    {
        $base = function_exists('storage_path')
            ? storage_path('atlas/loop/quaternity')
            : sys_get_temp_dir().'/atlas/loop/quaternity';

        return $base.'/cycle-receipts.jsonl';
    }
}
