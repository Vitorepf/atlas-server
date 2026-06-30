<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Cost;

/**
 * Append-only receipt ledger for {@see AtlasMaestroBudgetGate} decisions.
 *
 * Each receipt: task_packet_id, cycle_id, gate, reason, window, overage_cents, recorded_at,
 * receipt_hash. Prior receipts are never mutated; history is filtered by task or cycle.
 */
final class AtlasMaestroBudgetReceiptLedger
{
    public const SCHEMA = 'atlas.maestro.budget_receipt.v1';

    public function __construct(private readonly string $ledgerPath)
    {
        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $decision  {gate, reason, window?, overage_cents}
     * @return array<string,mixed>
     */
    public function append(string $taskPacketId, ?string $cycleId, array $decision, ?string $recordedAt = null): array
    {
        if (trim($taskPacketId) === '') {
            throw new \InvalidArgumentException('budget_receipt_ledger: task_packet_id must not be empty');
        }
        $gate = (string) ($decision['gate'] ?? '');
        if ($gate === '') {
            throw new \InvalidArgumentException('budget_receipt_ledger: decision.gate must not be empty');
        }

        $row = [
            'schema' => self::SCHEMA,
            'task_packet_id' => $taskPacketId,
            'cycle_id' => $cycleId,
            'gate' => $gate,
            'reason' => (string) ($decision['reason'] ?? ''),
            'window' => isset($decision['window']) ? (string) $decision['window'] : null,
            'overage_cents' => (int) ($decision['overage_cents'] ?? 0),
            'recorded_at' => $recordedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $row['receipt_hash'] = $this->receiptHash($row);

        $hash = $row['receipt_hash'];
        $duplicate = $this->orderedFilter(static fn (array $r): bool => ($r['receipt_hash'] ?? '') === $hash);
        if ($duplicate !== []) {
            return $row;
        }

        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $handle = @fopen($this->ledgerPath, 'a');
        if ($handle === false) {
            throw new \RuntimeException('budget receipt ledger: cannot open '.$this->ledgerPath.' for append');
        }
        try {
            fwrite($handle, $line."\n");
        } finally {
            fclose($handle);
        }

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function receiptsForTask(string $taskPacketId): array
    {
        return $this->orderedFilter(static fn (array $r): bool => (string) ($r['task_packet_id'] ?? '') === $taskPacketId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function receiptsForCycle(string $cycleId): array
    {
        return $this->orderedFilter(static fn (array $r): bool => (string) ($r['cycle_id'] ?? '') === $cycleId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->orderedFilter(static fn (array $r): bool => true);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function orderedFilter(callable $predicate): array
    {
        $rows = $this->readAll();
        $rows = array_values(array_filter($rows, $predicate));

        return $rows; // append-order preserved.
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
        $handle = @fopen($this->ledgerPath, 'r');
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

    /**
     * @param  array<string,mixed>  $row
     */
    private function receiptHash(array $row): string
    {
        unset($row['receipt_hash']);
        $canonical = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'budget_receipt_'.substr(hash('sha256', (string) $canonical), 0, 24);
    }
}
