<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

/**
 * FACT-only deterministic decider over a {@see BidSet}. Emits exactly one verdict — either a
 * {@see BidArbitrationVerdict} or {@see NoEligibleProviderVerdict}.
 *
 * NEVER calls a provider. NEVER scores capabilities. Only orders facts using the frozen
 * {@see BidComparator}.
 */
final class AtlasMaestroProviderBidArbiter
{
    /**
     * @return BidArbitrationVerdict|NoEligibleProviderVerdict
     */
    public function arbitrate(BidSet $set, string $taskId): BidArbitrationVerdict|NoEligibleProviderVerdict
    {
        $eligible = array_values(array_filter($set->bids, static fn (ProviderBid $b): bool => $b->eligibilityBool));
        if ($eligible === []) {
            $reasons = [];
            foreach ($set->bids as $bid) {
                foreach ($bid->ineligibilityReasons as $r) {
                    $reasons[(string) $r] = ($reasons[(string) $r] ?? 0) + 1;
                }
            }
            ksort($reasons);

            return new NoEligibleProviderVerdict($reasons);
        }

        $comparator = new BidComparator($taskId);
        $bids = $eligible;
        usort($bids, static fn (ProviderBid $a, ProviderBid $b): int => $comparator->compare($a, $b));

        $winner = $bids[0];
        $runnersUp = [];
        $criteriaTrace = [];
        $decisive = 'tiebreak';
        foreach (array_slice($bids, 1) as $other) {
            [$cmp, $step] = $comparator->stepCompare($winner, $other);
            $runnersUp[] = $other->providerId;
            $criteriaTrace[] = [
                'provider_id' => $other->providerId,
                'eliminated_by' => $step,
                'cmp' => $cmp,
            ];
            if ($decisive === 'tiebreak' && $step !== 'tiebreak') {
                $decisive = $step;
            }
        }
        // If only one bid, decisive is eligibility (won by being the lone eligible).
        if ($criteriaTrace === []) {
            $decisive = 'eligibility';
        }

        return new BidArbitrationVerdict(
            winnerProviderId: $winner->providerId,
            rankedRunnersUp: $runnersUp,
            decisiveCriterion: $decisive,
            criteriaTrace: $criteriaTrace,
        );
    }
}
