<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

/**
 * Immutable ledger entry. Hash-chained via prev_entry_sha256.
 */
final readonly class BidReceiptEntry
{
    /**
     * @param  list<string>  $bidHashes
     * @param  list<array<string,mixed>>  $criteriaTrace
     */
    public function __construct(
        public string $taskId,
        public string $envelopeHash,
        public array $bidHashes,
        public string $winnerProviderId,
        public string $decisiveCriterion,
        public array $criteriaTrace,
        public string $recordedAtIso,
        public string $prevEntrySha256,
        public string $entrySha256,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'bid_hashes' => $this->bidHashes,
            'criteria_trace' => $this->criteriaTrace,
            'decisive_criterion' => $this->decisiveCriterion,
            'entry_sha256' => $this->entrySha256,
            'envelope_hash' => $this->envelopeHash,
            'prev_entry_sha256' => $this->prevEntrySha256,
            'recorded_at_iso' => $this->recordedAtIso,
            'task_id' => $this->taskId,
            'winner_provider_id' => $this->winnerProviderId,
        ];
    }
}
