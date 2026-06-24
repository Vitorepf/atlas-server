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
     * @param  array<string,mixed>  $receipt
     */
    public function record(array $receipt): string
    {
        $payload = $this->canonicalPayload($receipt);
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $row = $payload + ['receipt_hash' => $hash];
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
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function canonicalPayload(array $receipt): array
    {
        $outcome = (string) ($receipt['outcome'] ?? 'assigned');
        if (! in_array($outcome, self::OUTCOMES, true)) {
            $outcome = 'assigned';
        }

        return [
            'schema' => self::SCHEMA,
            'task_packet_id' => (string) ($receipt['task_packet_id'] ?? 'unknown'),
            'classified_class' => (string) ($receipt['classified_class'] ?? AtlasMaestroPacketClassifier::GRIND),
            'classifier_rule_id' => (string) ($receipt['classifier_rule_id'] ?? AtlasMaestroPacketClassifier::REASON_GRIND),
            'primary_provider' => (string) ($receipt['primary_provider'] ?? 'minimax-m3'),
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
