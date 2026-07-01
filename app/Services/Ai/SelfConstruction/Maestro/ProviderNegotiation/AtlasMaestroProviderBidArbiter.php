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

    /** minimum declared capability_score an otherwise-eligible bid must meet for a high-risk task. */
    private const HIGH_RISK_MIN_CAPABILITY = 70;

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

    /**
     * Same fact-only ordering as {@see self::arbitrate()}, plus a risk-aware capability floor
     * and an explicit selected/rejected reason trail for downstream serving decisions.
     *
     * For a high-risk task, an otherwise-eligible bid below HIGH_RISK_MIN_CAPABILITY is treated
     * as ineligible for THIS decision only (never mutates ProviderBid::$eligibilityBool) — this
     * never overrides the frozen BidComparator order, it only narrows which bids reach it.
     *
     * @param  array{risk_tier?:string}  $taskFacts
     * @return array{winner_provider_id:?string, selected_reason:?string, rejected_bid_reasons:array<string,string>, risk_tier:string}
     */
    public function arbitrateWithReasons(BidSet $set, string $taskId, array $taskFacts = []): array
    {
        $riskTier = (string) ($taskFacts['risk_tier'] ?? 'normal');
        $isHighRisk = $riskTier === 'high';

        $eligible = [];
        $rejectedReasons = [];
        foreach ($set->bids as $bid) {
            if (! $bid->eligibilityBool) {
                $rejectedReasons[$bid->providerId] = $bid->ineligibilityReasons !== []
                    ? implode(',', $bid->ineligibilityReasons)
                    : 'ineligible';

                continue;
            }
            if ($isHighRisk && $bid->capabilityScore < self::HIGH_RISK_MIN_CAPABILITY) {
                $rejectedReasons[$bid->providerId] = 'insufficient_proof_capability_for_high_risk_task';

                continue;
            }
            $eligible[] = $bid;
        }

        if ($eligible === []) {
            ksort($rejectedReasons);

            return [
                'winner_provider_id' => null,
                'selected_reason' => null,
                'rejected_bid_reasons' => $rejectedReasons,
                'risk_tier' => $riskTier,
            ];
        }

        $comparator = new BidComparator($taskId);
        usort($eligible, static fn (ProviderBid $a, ProviderBid $b): int => $comparator->compare($a, $b));
        $winner = $eligible[0];

        foreach (array_slice($eligible, 1) as $other) {
            [, $step] = $comparator->stepCompare($winner, $other);
            $rejectedReasons[$other->providerId] = "lost_on_{$step}";
        }
        ksort($rejectedReasons);

        return [
            'winner_provider_id' => $winner->providerId,
            'selected_reason' => $isHighRisk
                ? 'highest_ranked_bid_meeting_high_risk_capability_floor'
                : 'highest_ranked_eligible_bid',
            'rejected_bid_reasons' => $rejectedReasons,
            'risk_tier' => $riskTier,
        ];
    }
}
