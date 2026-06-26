<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension;


use App\Services\Ai\SelfConstruction\Support\CanonicalizesNestedValues;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Raised when an append would overwrite/truncate the ledger — the append-only invariant. */
final class LedgerImmutabilityViolation extends RuntimeException
{
}

/** Raised when an atomic_seq rewinds or skips — the monotonic-sequence invariant. */
final class LedgerSequenceViolation extends RuntimeException
{
}

/**
 * AUTOPOIESIS · SELF-EXTENSION — the append-only, hash-CHAINED bootstrap receipt ledger. One receipt per scope
 * bootstrap, each cryptographically linked to its predecessor, so the full self-extension history is tamper-
 * evident and every scope installation is traceable to operator authorship (operator_intent_digest).
 *
 * Invariants:
 *   (1) APPEND-ONLY — writes only ever grow the file; a failure to grow raises {@see LedgerImmutabilityViolation}.
 *   (2) HASH-CHAIN — this_receipt_hash = sha256(prev_receipt_hash + canonical_json(payload_without_hash));
 *       {@see verifyChain()} walks the file and flags the FIRST break (mutated byte, broken link, or bad seq).
 *   (3) MONOTONIC atomic_seq — each receipt's seq must be exactly last+1; a rewind or gap raises
 *       {@see LedgerSequenceViolation} and writes NOTHING.
 *
 * Pure-PHP flat JSONL (flock-guarded append) — no DB, no provider. Path is injectable for tests.
 */
final class AtlasLoopAutopoieticBootstrapReceiptLedger
{
    use CanonicalizesNestedValues;
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const RELATIVE_PATH = 'atlas/autopoiesis/bootstrap-receipts.jsonl';

    public function __construct(private readonly ?string $path = null) {}


    public function path(): string
    {
        return $this->path ?? storage_path(self::RELATIVE_PATH);
    }

    /**
     * Append one bootstrap receipt and return the full persisted receipt.
     *
     * @param  array{atomic_seq:int, scope_id?:string, manifest_sha256?:string, verifier_ok?:bool, verifier_violations?:array<int,mixed>, operator_intent_digest?:string}  $fields
     * @return array<string,mixed>
     */
    public function append(array $fields): array
    {
        $atomicSeq = (int) ($fields['atomic_seq'] ?? 0);
        $last = $this->lastReceipt();
        $lastSeq = (int) ($last['atomic_seq'] ?? 0);

        if ($atomicSeq !== $lastSeq + 1) {
            // Validate BEFORE any write — a sequence violation must leave the file byte-unchanged.
            throw new LedgerSequenceViolation("atomic_seq must be ".($lastSeq + 1).", got {$atomicSeq}");
        }

        $prev = (string) ($last['this_receipt_hash'] ?? self::GENESIS_HASH);
        $payload = [
            'receipt_id' => (string) Str::ulid(),
            'atomic_seq' => $atomicSeq,
            'scope_id' => (string) ($fields['scope_id'] ?? ''),
            'manifest_sha256' => (string) ($fields['manifest_sha256'] ?? ''),
            'verifier_ok' => (bool) ($fields['verifier_ok'] ?? false),
            'verifier_violations' => array_values((array) ($fields['verifier_violations'] ?? [])),
            'operator_intent_digest' => (string) ($fields['operator_intent_digest'] ?? ''),
            'prev_receipt_hash' => $prev,
        ];
        $thisHash = $this->chainHash($prev, $payload);
        $receipt = $this->canonicalize($payload + ['this_receipt_hash' => $thisHash]);

        $this->appendLine((string) json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $receipt;
    }

    /**
     * Walk the chain and report the first break.
     *
     * @return array{ok:bool, chain_length:int, first_break_at:int|null, reason:string}
     */
    public function verifyChain(): array
    {
        $lines = $this->readLines();
        $expectedPrev = self::GENESIS_HASH;
        $expectedSeq = 1;

        foreach ($lines as $i => $line) {
            try {
                $receipt = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return ['ok' => false, 'chain_length' => $i, 'first_break_at' => $i, 'reason' => 'unparseable_line'];
            }
            if (! is_array($receipt)) {
                return ['ok' => false, 'chain_length' => $i, 'first_break_at' => $i, 'reason' => 'non_array_line'];
            }

            $payload = $receipt;
            unset($payload['this_receipt_hash']);
            $recomputed = $this->chainHash($expectedPrev, $payload);

            if ((string) ($receipt['prev_receipt_hash'] ?? '') !== $expectedPrev) {
                return ['ok' => false, 'chain_length' => $i, 'first_break_at' => $i, 'reason' => 'prev_link_broken'];
            }
            if ((int) ($receipt['atomic_seq'] ?? -1) !== $expectedSeq) {
                return ['ok' => false, 'chain_length' => $i, 'first_break_at' => $i, 'reason' => 'sequence_broken'];
            }
            if (! hash_equals($recomputed, (string) ($receipt['this_receipt_hash'] ?? ''))) {
                return ['ok' => false, 'chain_length' => $i, 'first_break_at' => $i, 'reason' => 'hash_mismatch'];
            }

            $expectedPrev = (string) $receipt['this_receipt_hash'];
            $expectedSeq++;
        }

        return ['ok' => true, 'chain_length' => count($lines), 'first_break_at' => null, 'reason' => 'intact'];
    }

    /**
     * @param  array<string,mixed>  $payloadWithoutHash
     */
    public function chainHash(string $prevHash, array $payloadWithoutHash): string
    {
        unset($payloadWithoutHash['this_receipt_hash']);

        return hash('sha256', $prevHash.$this->canonicalJson($payloadWithoutHash));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lastReceipt(): ?array
    {
        $lines = $this->readLines();
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            try {
                $decoded = json_decode($lines[$i], true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function readLines(): array
    {
        $path = $this->path();
        if (! is_file($path)) {
            return [];
        }

        return array_values(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    private function appendLine(string $line): void
    {
        $path = $this->path();
        $dir = \dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $before = is_file($path) ? (int) filesize($path) : 0;

        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new LedgerImmutabilityViolation('cannot open ledger for append: '.$path);
        }
        try {
            if (! flock($fp, LOCK_EX)) {
                throw new LedgerImmutabilityViolation('cannot lock ledger for append');
            }
            fwrite($fp, $line.PHP_EOL);
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        clearstatcache(true, $path);
        if ((int) filesize($path) <= $before) {
            throw new LedgerImmutabilityViolation('append did not grow the ledger — refusing silent truncation/overwrite');
        }
    }

    private function canonicalJson(mixed $value): string
    {
        return (string) json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

}
