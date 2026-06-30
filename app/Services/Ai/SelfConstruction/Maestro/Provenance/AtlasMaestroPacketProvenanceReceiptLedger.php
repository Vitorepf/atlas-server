<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Provenance;

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
        $handle = $this->openLocked();
        try {
            $rows = $this->readAllFromHandle($handle);

            // Idempotency: same (packet_id, record_hash) returns existing receipt.
            foreach ($rows as $row) {
                if ((string) ($row['packet_id'] ?? '') === $packetId && (string) ($row['record_hash'] ?? '') === $recordHash) {
                    return $row;
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

            fseek($handle, 0, SEEK_END);
            fwrite($handle, (string) json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            fflush($handle);

            return $receipt;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['written_at'] ?? ''), (string) ($a['written_at'] ?? '')));

        return array_slice($rows, 0, max(0, $limit));
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

    /**
     * @return resource
     */
    private function openLocked()
    {
        $handle = fopen($this->ledgerPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException('ledger_open_failed:'.$this->ledgerPath);
        }
        if (! flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('ledger_lock_failed:'.$this->ledgerPath);
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     * @return list<array<string,mixed>>
     */
    private function readAllFromHandle($handle): array
    {
        rewind($handle);
        $rows = [];
        while (($line = fgets($handle)) !== false) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }
}

final class SequenceCollisionException extends RuntimeException {}
