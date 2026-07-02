<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\MultiProvider;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
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

        try {
            // Idempotency AND previous-hash chain derivation run INSIDE the store's exclusive
            // write lock — concurrent record() calls can neither duplicate a receipt nor fork
            // the chain (the dedup-before-lock TOCTOU is dead). Null return aborts the write.
            (new JsonlReceiptStore($this->ledgerPath()))->appendWith(function (?string $lastLine) use ($payload, $hash): ?array {
                foreach ($this->rows() as $existing) {
                    if (($existing['receipt_hash'] ?? null) === $hash) {
                        return null;
                    }
                }

                $last = $lastLine !== null ? json_decode($lastLine, true) : null;
                $previous = is_array($last) ? (string) ($last['receipt_hash'] ?? 'genesis') : 'genesis';

                return $payload + ['receipt_hash' => $hash, 'previous_hash' => $previous];
            });
        } catch (Throwable $exception) {
            throw new DomainException('atlas_maestro_assignment_receipt_append_failed', previous: $exception);
        }

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
     * Same as {@see recent()} but newest-first — convenient for dashboards/CLI listings.
     *
     * @return list<array<string,mixed>>
     */
    public function recentNewestFirst(int $n = 50): array
    {
        return array_reverse($this->recent($n));
    }

    /**
     * Walk the ledger and verify that each row's previous_hash matches its predecessor's receipt_hash,
     * AND that each row's stored receipt_hash still matches a fresh hash of its own content — so
     * in-place payload tampering (not just link tampering) is also detected.
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
            $storedHash = (string) ($row['receipt_hash'] ?? '');
            $content = $row;
            unset($content['receipt_hash'], $content['previous_hash']);
            $recomputedHash = hash('sha256', (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if ($recomputedHash !== $storedHash) {
                return false;
            }
            $expected = $storedHash;
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
            'worker_id' => (string) ($receipt['worker_id'] ?? ''),
            'task_family' => (string) ($receipt['task_family'] ?? ''),
            'routing_reason' => (string) ($receipt['routing_reason'] ?? $receipt['reason_code'] ?? $receipt['classifier_rule_id'] ?? ''),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(): array
    {
        return (new JsonlReceiptStore($this->ledgerPath()))->replay();
    }

    private function ledgerPath(): string
    {
        return $this->path ?? storage_path('app/atlas/maestro/multi-provider/assignments.jsonl');
    }
}
