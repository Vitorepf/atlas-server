<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FactConfidence;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;

/**
 * Append-only JSONL audit trail for every FACT seen by the confidence-bounds validator.
 * Records (ts, fact_key, value, sample_size, source_count, caller_path, enforce_flag, outcome)
 * so the operator can distinguish single-sample claims from real-backed FACTs.
 * Anti-Goodhart: never produces a scalar score; only enumerable FACT receipts.
 *
 * Consolidated onto the kernel JsonlReceiptStore (fable-eng-r2): the raw
 * fopen/flock/json-line mechanics now live in the store; this class keeps
 * its domain payload shaping (ksort, enabled gate) and public API.
 */
final class AtlasLoopFactConfidenceBoundsReceiptLedger
{
    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_LEGACY = 'legacy';

    private readonly JsonlReceiptStore $store;

    public function __construct(
        private readonly string $ledgerPath,
        private readonly bool $enabled = false,
    ) {
        $this->store = new JsonlReceiptStore($ledgerPath);
    }

    /**
     * @param  array<string,mixed>  $receipt  fully-formed payload (caller assembles fields)
     */
    public function append(array $receipt): bool
    {
        if (! $this->enabled) {
            return false;
        }

        ksort($receipt, SORT_STRING);

        $line = $this->store->appendWith(static fn (?string $lastLine): array => $receipt);

        return $line !== null;
    }

    public function ledgerPath(): string
    {
        return $this->ledgerPath;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
