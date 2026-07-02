<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Migration\Runner;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

/**
 * Append-only ledger of migration execution receipts (apply/rollback/dryrun).
 *
 * Each receipt records:
 *   receipt_id, step_id, action, checkpoint_id, pre_sha256_manifest, post_sha256_manifest,
 *   started_at, finished_at, status, refused_reason?
 *
 * Storage path is config-driven via atlas.loop.migration_ledger_path. Writes use LOCK_EX and are
 * never mutated — duplicate receipt_ids append a second line; the original bytes survive (documented
 * idempotency contract).
 */
final class AtlasLoopSchemaMigrationExecutionReceiptLedger
{
    public const REQUIRED_FIELDS = [
        'receipt_id', 'step_id', 'action', 'checkpoint_id', 'pre_sha256_manifest',
        'post_sha256_manifest', 'started_at', 'finished_at', 'status',
    ];

    public function __construct(private readonly ?string $ledgerPathOverride = null) {}

    /**
     * @param  array<string,mixed>  $receipt
     */
    public function append(array $receipt): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $receipt)) {
                throw new \InvalidArgumentException('missing_required_receipt_field:'.$field);
            }
        }
        (new JsonlReceiptStore($this->ledgerPath()))->append($receipt);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function tail(int $n): array
    {
        $rows = $this->all();
        if ($n <= 0) {
            return [];
        }

        return array_slice($rows, -$n);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forStep(string $stepId): array
    {
        return array_values(array_filter($this->all(), static fn (array $r): bool => (string) ($r['step_id'] ?? '') === $stepId));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $receiptId): ?array
    {
        // First match wins; subsequent duplicates are documented idempotency entries.
        foreach ($this->all() as $row) {
            if ((string) ($row['receipt_id'] ?? '') === $receiptId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return (new JsonlReceiptStore($this->ledgerPath()))->replay();
    }

    public function ledgerPath(): string
    {
        if ($this->ledgerPathOverride !== null) {
            return $this->ledgerPathOverride;
        }
        if (function_exists('config')) {
            $configured = config('atlas.loop.migration_ledger_path');
            if (is_string($configured) && $configured !== '') {
                return $configured;
            }
        }

        return storage_path('atlas/loop/migration/execution_receipts.jsonl');
    }
}
