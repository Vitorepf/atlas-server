<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Adaptive;

use DomainException;
use Throwable;

final class AtlasMaestroAdaptiveDecisionReceipt
{
    public const SCHEMA = 'atlas.maestro.adaptive.decision_receipt.v1';

    public const RESHAPE_APPLIED = 'reshape_applied';
    public const RESHAPE_PASSTHROUGH = 'reshape_passthrough';
    public const ROUTE_SELECTED = 'route_selected';
    public const ROUTE_ABSTAIN = 'route_abstain';

    private const DECISION_KINDS = [
        self::RESHAPE_APPLIED,
        self::RESHAPE_PASSTHROUGH,
        self::ROUTE_SELECTED,
        self::ROUTE_ABSTAIN,
    ];

    public function __construct(private readonly ?string $path = null)
    {
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    public function record(array $payload): ?array
    {
        if (! (bool) config('atlas.maestro.adaptive.decision_receipt_enabled', false)) {
            return null;
        }

        $row = $this->normalize($payload, $this->nextDecisionId());
        $this->append($row);

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function receipts(string $packetId): array
    {
        if (! (bool) config('atlas.maestro.adaptive.decision_receipt_enabled', false)) {
            return [];
        }

        return array_values(array_filter(
            $this->rows(),
            static fn (array $row): bool => (string) ($row['packet_id'] ?? '') === $packetId,
        ));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function receiptsSince(int $decisionId): array
    {
        if (! (bool) config('atlas.maestro.adaptive.decision_receipt_enabled', false)) {
            return [];
        }

        return array_values(array_filter(
            $this->rows(),
            static fn (array $row): bool => (int) ($row['decision_id'] ?? 0) > $decisionId,
        ));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function update(int $decisionId, array $payload): never
    {
        throw new DomainException('atlas_maestro_adaptive_decision_receipts_are_append_only');
    }

    public function delete(int $decisionId): never
    {
        throw new DomainException('atlas_maestro_adaptive_decision_receipts_are_append_only');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalize(array $payload, int $decisionId): array
    {
        $kind = (string) ($payload['decision_kind'] ?? '');
        if (! in_array($kind, self::DECISION_KINDS, true)) {
            $kind = self::ROUTE_ABSTAIN;
        }

        return [
            'schema' => self::SCHEMA,
            'decision_id' => $decisionId,
            'decision_kind' => $kind,
            'packet_id' => (string) ($payload['packet_id'] ?? 'unknown'),
            'original_hash' => (string) ($payload['original_hash'] ?? ''),
            'outcome_hash' => (string) ($payload['outcome_hash'] ?? $payload['reshaped_hash'] ?? ''),
            'miner_facts_used' => array_values((array) ($payload['miner_facts_used'] ?? [])),
            'eligible_workers_snapshot' => array_values((array) ($payload['eligible_workers_snapshot'] ?? [])),
            'chosen_worker_or_null' => isset($payload['chosen_worker_or_null']) ? (string) $payload['chosen_worker_or_null'] : null,
            'abstain_reason_or_null' => isset($payload['abstain_reason_or_null']) ? (string) $payload['abstain_reason_or_null'] : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function append(array $row): void
    {
        $path = $this->receiptPath();
        try {
            if (! is_dir(dirname($path))) {
                @mkdir(dirname($path), 0o775, true);
            }
            @file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $exception) {
            throw new DomainException('atlas_maestro_adaptive_decision_receipt_append_failed', previous: $exception);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(): array
    {
        $path = $this->receiptPath();
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

        usort($rows, static fn (array $left, array $right): int => ((int) $left['decision_id']) <=> ((int) $right['decision_id']));

        return $rows;
    }

    private function nextDecisionId(): int
    {
        $last = 0;
        foreach ($this->rows() as $row) {
            $last = max($last, (int) ($row['decision_id'] ?? 0));
        }

        return $last + 1;
    }

    private function receiptPath(): string
    {
        return $this->path ?? storage_path('app/atlas/maestro/adaptive/decision-receipts.jsonl');
    }
}
