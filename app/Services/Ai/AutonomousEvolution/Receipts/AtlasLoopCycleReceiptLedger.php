<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Receipts;

use Generator;
use RuntimeException;
use Throwable;

/** Raised when an append is offered a signed receipt that fails the signer's verify(). */
final class CycleReceiptChainRejection extends RuntimeException
{
}

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

        $path = $this->path();
        $dir = \dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $fp = fopen($path, 'c+');
        if ($fp === false) {
            throw new RuntimeException('cannot open cycle-receipt chain: '.$path);
        }

        try {
            if (! flock($fp, LOCK_EX)) {
                throw new RuntimeException('cannot lock cycle-receipt chain for append');
            }

            // Compute seq + prev INSIDE the lock so concurrent appenders never collide.
            $last = $this->lastEntryFromHandle($fp);
            $prev = $last !== null ? (string) ($last['chain_hash'] ?? self::GENESIS_PREV) : self::GENESIS_PREV;
            $seq = $last !== null ? ((int) ($last['seq'] ?? 0) + 1) : 1;

            $entry = [
                'seq' => $seq,
                'prev_chain_hash' => $prev,
                'signed_receipt' => $signedReceipt,
                'chain_hash' => $this->chainHash($prev, $signedReceipt),
            ];

            fseek($fp, 0, SEEK_END);
            fwrite($fp, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
            fflush($fp);

            return $entry;
        } finally {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
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
        $path = $this->path();
        if (! is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (is_array($decoded)) {
                yield $decoded;
            }
        }
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

    /**
     * Read the LAST decoded entry directly from the open (locked) handle.
     *
     * @param  resource  $fp
     * @return array<string,mixed>|null
     */
    private function lastEntryFromHandle($fp): ?array
    {
        rewind($fp);
        $content = (string) stream_get_contents($fp);
        $last = null;
        foreach (explode("\n", $content) as $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (is_array($decoded)) {
                $last = $decoded;
            }
        }

        return $last;
    }
}
