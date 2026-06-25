<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

/**
 * Immutable arbitration verdict produced by {@see AtlasMaestroProviderBidArbiter}.
 */
final readonly class BidArbitrationVerdict
{
    /**
     * @param  list<string>  $rankedRunnersUp           ordered provider ids
     * @param  list<array<string,mixed>>  $criteriaTrace explicit elimination steps per runner
     */
    public function __construct(
        public string $winnerProviderId,
        public array $rankedRunnersUp,
        public string $decisiveCriterion,
        public array $criteriaTrace,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'criteria_trace' => $this->criteriaTrace,
            'decisive_criterion' => $this->decisiveCriterion,
            'ranked_runners_up' => $this->rankedRunnersUp,
            'winner_provider_id' => $this->winnerProviderId,
        ];
    }
}
