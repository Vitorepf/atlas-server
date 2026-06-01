<?php

namespace App\Services\Ai\Hermes;

/**
 * Shared sealing helpers for governed Hermes adapter and gate receipts.
 *
 * Every Hermes adapter/gate emits an `atlas.hermes.*_receipt.v1` payload sealed
 * with a deterministic `receipt_hash`, so the ATLS Evidence Ledger can prove
 * what the executor runtime did without trusting Hermes to narrate it. Keeping
 * the hashing in one place keeps the receipt contract identical across the
 * Memory, Schedule, Gateway, Procedure and Router adapters.
 */
trait HermesAdapterReceipt
{
    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function withReceiptHash(array $receipt): array
    {
        $receipt['receipt_hash'] = $this->hashValue($receipt);

        return $receipt;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function hashValue(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
