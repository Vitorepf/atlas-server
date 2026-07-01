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
    /** ineligibility reason => concrete repair hint. */
    private const REPAIR_HINT_MAP = [
        'capability_missing' => 'add or declare the missing capability for this provider before re-bidding',
        'locality_violation' => 'route to a provider whose locality matches the task requirement',
        'sensitivity_violation' => 'route to a provider cleared for this data sensitivity class',
        'evidence_burden_exceeded' => 'reduce required evidence burden or route to a provider that can satisfy it',
        'risk_tier_mismatch' => 'route to a provider whose risk tier matches the task requirement',
    ];

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

            $repairHints = [];
            foreach (array_keys($reasons) as $reason) {
                $repairHints[$reason] = self::REPAIR_HINT_MAP[$reason] ?? 'inspect ineligibility_reasons and correct the routing fact that caused it';
            }
            ksort($repairHints);

            return new NoEligibleProviderVerdict($reasons, $repairHints);
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
