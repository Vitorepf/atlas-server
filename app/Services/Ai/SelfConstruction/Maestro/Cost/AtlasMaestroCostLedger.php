<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Cost;

/**
 * Append-only cost fact ledger for Maestro task execution.
 *
 * Pure facts: each row carries task_packet_id, task_class, provider, model, cycle_id, tokens_in,
 * tokens_out, cost_cents, recorded_at, cost_hash. Malformed rows are skipped without throwing.
 *
 * Pure: no provider calls, no shell, no auto-routing.
 */
final class AtlasMaestroCostLedger
{
    public const SCHEMA = 'atlas.maestro.cost_ledger.v1';

    /** @var list<string> */
    private const REQUIRED_FIELDS = [
        'task_packet_id', 'task_class', 'provider', 'model', 'cycle_id',
        'tokens_in', 'tokens_out', 'cost_cents', 'recorded_at',
    ];

    /**
     * Extra input fields that are explicitly NOT stored. The append() method only copies
     * REQUIRED_FIELDS, so any field here is automatically dropped — this list documents intent.
     *
     * @var list<string>
     */
    private const SENSITIVE_FIELDS = [
        'api_key', 'authorization', 'raw_payload', 'raw_response', 'prompt_text',
        'provider_trace_id', 'internal_model_id',
    ];

    public function __construct(private readonly string $ledgerPath)
    {
        $dir = dirname($this->ledgerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return array<string,mixed>|null  null on malformed input (skipped, never thrown)
     */
    public function append(array $fact): ?array
    {
        if (! $this->isWellFormed($fact)) {
            return null;
        }
        $row = [
            'task_packet_id' => (string) $fact['task_packet_id'],
            'task_class' => (string) $fact['task_class'],
            'provider' => (string) $fact['provider'],
            'model' => (string) $fact['model'],
            'cycle_id' => (string) $fact['cycle_id'],
            'tokens_in' => (int) $fact['tokens_in'],
            'tokens_out' => (int) $fact['tokens_out'],
            'cost_cents' => (int) $fact['cost_cents'],
            'recorded_at' => (string) $fact['recorded_at'],
        ];
        $row['cost_hash'] = $this->costHash($row);

        // Idempotent: skip if this cost_hash is already in the ledger.
        foreach ($this->readAll() as $existing) {
            if ((string) ($existing['cost_hash'] ?? '') === $row['cost_hash']) {
                return $existing;
            }
        }

        $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $handle = @fopen($this->ledgerPath, 'a');
        if ($handle === false) {
            throw new \RuntimeException('cost ledger: cannot open '.$this->ledgerPath.' for append');
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
    public function queryForTask(string $taskPacketId): array
    {
        return $this->orderedFilter(static fn (array $r): bool => (string) ($r['task_packet_id'] ?? '') === $taskPacketId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function queryForProvider(string $provider): array
    {
        return $this->orderedFilter(static fn (array $r): bool => (string) ($r['provider'] ?? '') === $provider);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function queryForCycle(string $cycleId): array
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
     * @param  array<string,mixed>  $fact
     */
    private function isWellFormed(array $fact): bool
    {
        foreach (self::REQUIRED_FIELDS as $key) {
            if (! array_key_exists($key, $fact)) {
                return false;
            }
        }
        foreach (['tokens_in', 'tokens_out', 'cost_cents'] as $intKey) {
            if (! is_numeric($fact[$intKey]) || (int) $fact[$intKey] < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function orderedFilter(callable $predicate): array
    {
        $rows = $this->readAll();
        $rows = array_values(array_filter($rows, $predicate));
        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp((string) ($a['recorded_at'] ?? ''), (string) ($b['recorded_at'] ?? ''));

            return $cmp !== 0 ? $cmp : strcmp((string) ($a['cost_hash'] ?? ''), (string) ($b['cost_hash'] ?? ''));
        });

        return $rows;
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
            if (! is_array($decoded)) {
                continue; // skip malformed row
            }
            if (! $this->isWellFormed($decoded)) {
                continue; // skip if missing required fields
            }
            $rows[] = $decoded;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function costHash(array $row): string
    {
        unset($row['cost_hash']);
        $canonical = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'cost_'.substr(hash('sha256', (string) $canonical), 0, 24);
    }
}
