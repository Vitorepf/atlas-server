<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

/**
 * Verdict returned by {@see AtlasMaestroProviderBidArbiter::arbitrate()} when zero eligible bids
 * exist. Closes the silent first-come fallback: the arbiter refuses to pick an ineligible bid.
 */
final readonly class NoEligibleProviderVerdict
{
    /**
     * @param  array<string,int>  $reasonCodes  reason → count aggregated from ineligibility_reasons
     */
    public function __construct(public array $reasonCodes) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'reason_codes' => $this->reasonCodes,
            'verdict' => 'no_eligible_provider',
        ];
    }
}
