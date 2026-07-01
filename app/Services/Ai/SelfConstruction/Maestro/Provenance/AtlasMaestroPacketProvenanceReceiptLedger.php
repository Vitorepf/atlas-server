<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Provenance;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use RuntimeException;

/**
 * Append-only receipt store for packet provenance + verdict records.
 *
 * Storage: a single JSONL file at `$ledgerPath`. Each line is one immutable receipt:
 *   { receipt_id, packet_id, sequence_no, record_hash, verdict, written_at }
 *
 * Invariants:
 *   - append() is atomic (file is opened with LOCK_EX; writes happen inside the lock).
 *   - append() is idempotent on (packet_id, record_hash) — second append returns the existing receipt.
 *   - append() refuses to overwrite an existing (packet_id, sequence_no) tuple with a different
 *     record_hash → throws SequenceCollisionException and leaves the file byte-identical.
 */
final class AtlasMaestroPacketProvenanceReceiptLedger
{
    public const SCHEMA = 'atlas.maestro.packet_provenance_receipt.v1';

    public function __construct(private readonly string $ledgerPath)
    {
        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $verdict
     * @return array<string,mixed>
     */
    public function append(string $packetId, int $sequenceNo, string $recordHash, array $verdict, string $writtenAtIso): array
    {
        if ($packetId === '' || $recordHash === '') {
            throw new RuntimeException('append_requires_packet_id_and_record_hash');
        }
        $store = new JsonlReceiptStore($this->ledgerPath);
        $result = null;
        // Idempotency, collision detection and chain derivation all run INSIDE the write lock.
        $store->appendWith(function (?string $lastLine) use ($store, $packetId, $sequenceNo, $recordHash, $verdict, $writtenAtIso, &$result): ?array {
            $rows = $store->replay();

            // Idempotency: same (packet_id, record_hash) returns existing receipt.
            foreach ($rows as $row) {
                if ((string) ($row['packet_id'] ?? '') === $packetId && (string) ($row['record_hash'] ?? '') === $recordHash) {
                    $result = $row;

                    return null;
                }
            }

            // Sequence collision: same (packet_id, sequence_no) with a different record_hash is forbidden.
            foreach ($rows as $row) {
                if ((string) ($row['packet_id'] ?? '') === $packetId && (int) ($row['sequence_no'] ?? -1) === $sequenceNo) {
                    throw new SequenceCollisionException('sequence_collision:'.$packetId.':'.$sequenceNo);
                }
            }

            $receipt = [
                'receipt_id' => hash('sha256', $packetId.':'.$sequenceNo.':'.$recordHash),
                'packet_id' => $packetId,
                'sequence_no' => $sequenceNo,
                'record_hash' => $recordHash,
                'verdict' => $verdict,
                'written_at' => $writtenAtIso,
                'schema_version' => self::SCHEMA,
            ];

            // Tamper-evident chain: each receipt's chain_hash covers its own full content plus the
            // previous receipt's chain_hash, so editing any field on any prior line — or this one —
            // breaks validateChain() from that point forward.
            $prevChainHash = $rows === [] ? '' : (string) ($rows[array_key_last($rows)]['chain_hash'] ?? '');
            $receipt['chain_hash'] = $this->computeChainHash($prevChainHash, $receipt);
            $result = $receipt;

            return $receipt;
        });

        return $result;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getReceipt(string $receiptId): ?array
    {
        foreach ($this->readAll() as $row) {
            if ((string) ($row['receipt_id'] ?? '') === $receiptId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForPacket(string $packetId): array
    {
        $rows = array_values(array_filter(
            $this->readAll(),
            static fn (array $r): bool => (string) ($r['packet_id'] ?? '') === $packetId,
        ));
        usort($rows, static fn (array $a, array $b): int => ((int) ($a['sequence_no'] ?? 0)) <=> ((int) ($b['sequence_no'] ?? 0)));

        return $rows;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function tail(int $limit): array
    {
        $rows = $this->readAll();
        // Deterministic ordering: written_at descending, then sequence_no descending, then
        // receipt_id as a final tiebreak — so two receipts sharing a written_at never sort
        // arbitrarily by insertion order.
        usort($rows, static function (array $a, array $b): int {
            $writtenCmp = strcmp((string) ($b['written_at'] ?? ''), (string) ($a['written_at'] ?? ''));
            if ($writtenCmp !== 0) {
                return $writtenCmp;
            }
            $seqCmp = ((int) ($b['sequence_no'] ?? 0)) <=> ((int) ($a['sequence_no'] ?? 0));
            if ($seqCmp !== 0) {
                return $seqCmp;
            }

            return strcmp((string) ($b['receipt_id'] ?? ''), (string) ($a['receipt_id'] ?? ''));
        });

        return array_slice($rows, 0, max(0, $limit));
    }

    /**
     * Validate the tamper-evident chain across all receipts in physical append order. Any
     * modification to a receipt's stored fields — including one made directly to the file —
     * breaks the recomputed chain_hash from that receipt onward.
     *
     * @return array{valid:bool, broken_at_index:?int, broken_receipt_id:?string}
     */
    public function validateChain(): array
    {
        $rows = $this->readAll();
        $prevChainHash = '';

        foreach ($rows as $i => $row) {
            $storedChainHash = (string) ($row['chain_hash'] ?? '');
            $withoutChainHash = $row;
            unset($withoutChainHash['chain_hash']);
            $expected = $this->computeChainHash($prevChainHash, $withoutChainHash);

            if ($expected !== $storedChainHash) {
                return [
                    'valid' => false,
                    'broken_at_index' => $i,
                    'broken_receipt_id' => (string) ($row['receipt_id'] ?? ''),
                ];
            }

            $prevChainHash = $storedChainHash;
        }

        return ['valid' => true, 'broken_at_index' => null, 'broken_receipt_id' => null];
    }

    /**
     * @param  array<string,mixed>  $receiptWithoutChainHash
     */
    private function computeChainHash(string $prevChainHash, array $receiptWithoutChainHash): string
    {
        return hash('sha256', $prevChainHash.'|'.(string) json_encode($receiptWithoutChainHash, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Query by family: all receipts whose packet_id starts with $familyPrefix.
     *
     * @return list<array<string,mixed>>
     */
    public function forFamily(string $familyPrefix): array
    {
        return array_values(array_filter(
            $this->readAll(),
            static fn (array $r): bool => str_starts_with((string) ($r['packet_id'] ?? ''), $familyPrefix),
        ));
    }

    /**
     * Query by source: all receipts where verdict['source'] matches $source.
     *
     * @return list<array<string,mixed>>
     */
    public function forSource(string $source): array
    {
        return array_values(array_filter(
            $this->readAll(),
            static fn (array $r): bool => (string) (($r['verdict'] ?? [])['source'] ?? '') === $source,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readAll(): array
    {
        return (new JsonlReceiptStore($this->ledgerPath))->replay();
    }
}

final class SequenceCollisionException extends RuntimeException {}
