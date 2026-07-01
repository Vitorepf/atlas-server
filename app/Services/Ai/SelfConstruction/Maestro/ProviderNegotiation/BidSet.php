<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

final readonly class BidSet
{
    /**
     * @param  list<ProviderBid>  $bids
     */
    public function __construct(
        public array $bids,
    ) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (ProviderBid $bid): array => $bid->toArray(),
            $this->bids,
        );
    }

    /**
     * Eligibility summary + bid count over the already-typed bids — additive alongside
     * toArray(), which stays a plain list because AtlasTaskMaestroBidCommand's propose action
     * emits it directly and existing consumers index it positionally.
     *
     * @return array{bid_count:int, eligible_count:int, ineligible_count:int, duplicate_providers:list<string>}
     */
    public function summary(): array
    {
        $eligible = 0;
        $seen = [];
        foreach ($this->bids as $bid) {
            if ($bid->eligibilityBool) {
                $eligible++;
            }
            $seen[$bid->providerId] = ($seen[$bid->providerId] ?? 0) + 1;
        }

        $duplicates = [];
        foreach ($seen as $providerId => $count) {
            if ($count > 1) {
                $duplicates[] = $providerId;
            }
        }
        sort($duplicates, SORT_STRING);

        return [
            'bid_count' => count($this->bids),
            'eligible_count' => $eligible,
            'ineligible_count' => count($this->bids) - $eligible,
            'duplicate_providers' => $duplicates,
        ];
    }

    /**
     * Fact-only pre-arbitration validation over RAW bid rows a caller intends to build a
     * BidSet from — before they are converted into typed ProviderBid objects — so arbitration
     * never reads a set corrupted by duplicate provider bids or cross-task contamination.
     * ProviderBid itself carries no task_id (task identity is set-level, not per-bid), so this
     * validates the raw envelope shape where a task_id per row can still be asserted.
     *
     * @param  list<array<string,mixed>>  $rows  each row: {provider_id?:string, task_id?:string}
     * @return array{ok:bool, bid_count:int, duplicate_providers:list<string>, mixed_task_ids:list<string>, reasons:list<string>}
     */
    public static function validateRows(string $expectedTaskId, array $rows): array
    {
        $seenProviders = [];
        $mixedTaskIds = [];

        foreach ($rows as $row) {
            $providerId = (string) ($row['provider_id'] ?? '');
            if ($providerId !== '') {
                $seenProviders[$providerId] = ($seenProviders[$providerId] ?? 0) + 1;
            }
            if (array_key_exists('task_id', $row)) {
                $rowTaskId = (string) $row['task_id'];
                if ($rowTaskId !== $expectedTaskId && ! in_array($rowTaskId, $mixedTaskIds, true)) {
                    $mixedTaskIds[] = $rowTaskId;
                }
            }
        }

        $duplicateProviders = [];
        foreach ($seenProviders as $providerId => $count) {
            if ($count > 1) {
                $duplicateProviders[] = $providerId;
            }
        }
        sort($duplicateProviders, SORT_STRING);
        sort($mixedTaskIds, SORT_STRING);

        $reasons = [];
        if ($duplicateProviders !== []) {
            $reasons[] = 'duplicate_provider_bids';
        }
        if ($mixedTaskIds !== []) {
            $reasons[] = 'mixed_task_ids';
        }

        return [
            'ok' => $reasons === [],
            'bid_count' => count($rows),
            'duplicate_providers' => $duplicateProviders,
            'mixed_task_ids' => $mixedTaskIds,
            'reasons' => $reasons,
        ];
    }
}
