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
     * @param  array<string,int>     $reasonCodes  reason → count aggregated from ineligibility_reasons
     * @param  array<string,string>  $repairHints  reason → concrete fix, sorted deterministically by reason key
     */
    public function __construct(public array $reasonCodes, public array $repairHints = []) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'reason_codes' => $this->reasonCodes,
            'repair_hints' => $this->repairHints,
            'verdict' => 'no_eligible_provider',
        ];
    }
}
