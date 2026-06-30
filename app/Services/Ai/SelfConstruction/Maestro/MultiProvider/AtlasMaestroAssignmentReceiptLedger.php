<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\MultiProvider;

use DomainException;
use Throwable;

final class AtlasMaestroAssignmentReceiptLedger
{
    public const SCHEMA = 'atlas.maestro.multi_provider.assignment_receipt.v1';

    private const OUTCOMES = ['assigned', 'succeeded', 'failed_over', 'abandoned'];

    public function __construct(private readonly ?string $path = null)
    {
    }

    /**
     * Record an assignment receipt idempotently.
     *
     * Throws DomainException for unknown outcomes.
     * Returns the same receipt_hash if the receipt was already recorded (dedup by hash).
     *
     * @param  array<string,mixed>  $receipt
     */
    public function record(array $receipt): string
    {
        $outcome = (string) ($receipt['outcome'] ?? 'assigned');
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new DomainException("unknown_outcome:{$outcome}");
        }

        $payload = $this->canonicalPayload($receipt, $outcome);
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        // Idempotent: skip append if receipt_hash already present.
        $existingRows = $this->rows();
        foreach ($existingRows as $existing) {
            if (($existing['receipt_hash'] ?? null) === $hash) {
                return $hash;
            }
        }

        $previous = empty($existingRows)
            ? 'genesis'
            : (string) ($existingRows[array_key_last($existingRows)]['receipt_hash'] ?? 'genesis');

        $row = $payload + ['receipt_hash' => $hash, 'previous_hash' => $previous];
        $this->append($row);

        return $hash;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function recent(int $n = 50): array
    {
        $rows = $this->rows();

        return array_slice($rows, -max(1, $n));
    }

    /**
     * Walk the ledger and verify that each row's previous_hash matches its predecessor's receipt_hash.
     * Returns true for an empty ledger (vacuously valid).
     */
    public function verifyChain(): bool
    {
        $rows = $this->rows();
        $expected = 'genesis';
        foreach ($rows as $row) {
            if (($row['previous_hash'] ?? null) !== $expected) {
                return false;
            }
            $expected = (string) ($row['receipt_hash'] ?? '');
        }

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalPayload(array $receipt, string $outcome): array
    {
        return [
            'schema' => self::SCHEMA,
            'task_packet_id' => (string) ($receipt['task_packet_id'] ?? 'unknown'),
            // New field names; fall back to legacy keys for backward compatibility.
            'packet_class' => (string) ($receipt['packet_class'] ?? $receipt['classified_class'] ?? AtlasMaestroPacketClassifier::GRIND),
            'provider_class' => (string) ($receipt['provider_class'] ?? $receipt['primary_provider'] ?? 'minimax-m3'),
            'reason_code' => (string) ($receipt['reason_code'] ?? $receipt['classifier_rule_id'] ?? AtlasMaestroPacketClassifier::REASON_GRIND),
            'fallback_chain' => array_values(array_map('strval', (array) ($receipt['fallback_chain'] ?? []))),
            'fallback_used' => isset($receipt['fallback_used']) ? (string) $receipt['fallback_used'] : null,
            'outcome' => $outcome,
            'wall_clock_iso8601' => (string) ($receipt['wall_clock_iso8601'] ?? date('c')),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function append(array $row): void
    {
        $path = $this->ledgerPath();
        try {
            if (! is_dir(dirname($path))) {
                @mkdir(dirname($path), 0o775, true);
            }
            @file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $exception) {
            throw new DomainException('atlas_maestro_assignment_receipt_append_failed', previous: $exception);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(): array
    {
        $path = $this->ledgerPath();
        if (! is_file($path)) {
            return [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function ledgerPath(): string
    {
        return $this->path ?? storage_path('app/atlas/maestro/multi-provider/assignments.jsonl');
    }
}
