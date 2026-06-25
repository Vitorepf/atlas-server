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
}
