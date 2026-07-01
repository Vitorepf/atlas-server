<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel mechanism: record durable evidence, replay refs, hashes, gates and outcomes.
 *
 * Owns: recording durable evidence — replay refs, hashes, gates, outcomes — as an append-only
 * proof trail, and replaying that trail to verify it hasn't drifted.
 * Must never own: deciding whether an outcome was good or bad (Verification Court's job) or
 * whether to land/canary/revert based on it (Governor's job via MergeActuator).
 */
interface ReceiptLedger
{
    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function append(array $receipt): array;

    /**
     * @return array<string,mixed>
     */
    public function replay(): array;
}
