<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Receipts;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Generator;
use RuntimeException;

/** Raised when an append is offered a signed receipt that fails the signer's verify(). */
final class CycleReceiptChainRejection extends RuntimeException {}

/**
 * Append-only, tamper-evident CHAIN of signed loop-cycle receipts ({@see AtlasLoopCycleReceiptSigner}). Each
 * entry stores { seq, prev_chain_hash, signed_receipt, chain_hash } where
 * chain_hash = sha256(prev_chain_hash . body_canonical_sha256 . signature); the genesis prev is 64 zeros.
 *
 * Storage is a single JSONL file ({@see path()}), appended under an EXCLUSIVE flock with the seq/prev computed
 * INSIDE the lock — so two concurrent appenders get consecutive seqs and never tear a line. {@see verifyChain()}
 * walks the file and reports the first break (rewrite, broken link, or out-of-order seq). Append fail-CLOSED:
 * a signed receipt whose signature does not verify is rejected (it never enters the chain).
 */
final class AtlasLoopCycleReceiptLedger
{
    public const GENESIS_PREV = '0000000000000000000000000000000000000000000000000000000000000000';

    private const RELATIVE_PATH = 'app/atlas/loop/cycle_receipts.chain.jsonl';

    public function __construct(
        private readonly ?AtlasLoopCycleReceiptSigner $signer = null,
        private readonly ?string $path = null,
    ) {}

    public function path(): string
    {
        if ($this->path !== null && trim($this->path) !== '') {
            return $this->path;
        }

        return (string) config('atlas.ai.loop.cycle_receipt_chain_path', storage_path(self::RELATIVE_PATH));
    }

    /**
     * @param  array<string,mixed>  $signedReceipt
     * @return array{seq:int, prev_chain_hash:string, signed_receipt:array<string,mixed>, chain_hash:string}
     */
    public function append(array $signedReceipt): array
    {
        if (! ($this->signer ?? new AtlasLoopCycleReceiptSigner)->verify($signedReceipt)) {
            throw new CycleReceiptChainRejection('refusing to chain a signed receipt that fails verification');
        }

        $entry = [];
        // appendWith computes seq + prev from the tail line INSIDE the lock so concurrent
        // appenders never collide.
        (new JsonlReceiptStore($this->path()))->appendWith(function (?string $lastLine) use ($signedReceipt, &$entry): array {
            $last = $lastLine !== null ? json_decode($lastLine, true) : null;
            $last = is_array($last) ? $last : null;
            $prev = $last !== null ? (string) ($last['chain_hash'] ?? self::GENESIS_PREV) : self::GENESIS_PREV;
            $seq = $last !== null ? ((int) ($last['seq'] ?? 0) + 1) : 1;

            return $entry = [
                'seq' => $seq,
                'prev_chain_hash' => $prev,
                'signed_receipt' => $signedReceipt,
                'chain_hash' => $this->chainHash($prev, $signedReceipt),
            ];
        });

        return $entry;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latest(): ?array
    {
        $last = null;
        foreach ($this->all() as $entry) {
            $last = $entry;
        }

        return $last;
    }

    /**
     * @return Generator<int, array<string,mixed>>
     */
    public function all(): Generator
    {
        // Corrupt/empty/non-array lines are skipped by replay() — same policy the inline loop had.
        yield from (new JsonlReceiptStore($this->path()))->replay();
    }

    /**
     * @return array{ok:bool, broken_at:?int, reason:?string}
     */
    public function verifyChain(): array
    {
        $expectedPrev = self::GENESIS_PREV;
        $expectedSeq = 1;

        foreach ($this->all() as $entry) {
            $seq = (int) ($entry['seq'] ?? -1);
            if ($seq !== $expectedSeq) {
                return ['ok' => false, 'broken_at' => $seq >= 0 ? $seq : $expectedSeq, 'reason' => 'out_of_order_seq'];
            }
            if ((string) ($entry['prev_chain_hash'] ?? '') !== $expectedPrev) {
                return ['ok' => false, 'broken_at' => $seq, 'reason' => 'prev_link_broken'];
            }
            $signed = is_array($entry['signed_receipt'] ?? null) ? (array) $entry['signed_receipt'] : [];
            $recomputed = $this->chainHash($expectedPrev, $signed);
            if (! hash_equals($recomputed, (string) ($entry['chain_hash'] ?? ''))) {
                return ['ok' => false, 'broken_at' => $seq, 'reason' => 'chain_hash_mismatch'];
            }

            $expectedPrev = (string) $entry['chain_hash'];
            $expectedSeq++;
        }

        return ['ok' => true, 'broken_at' => null, 'reason' => null];
    }

    /**
     * @param  array<string,mixed>  $signedReceipt
     */
    private function chainHash(string $prev, array $signedReceipt): string
    {
        $bodySha = (string) ($signedReceipt['body_canonical_sha256'] ?? '');
        $signature = (string) ($signedReceipt['signature'] ?? '');

        return hash('sha256', $prev.$bodySha.$signature);
    }
}
