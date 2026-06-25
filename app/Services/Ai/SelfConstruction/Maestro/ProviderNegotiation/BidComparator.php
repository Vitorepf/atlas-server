<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation;

/**
 * Frozen lexicographic comparator over ProviderBid. Stable, deterministic — no wall-clock, no
 * first-come.
 *
 * Order:
 *   1. eligibility_bool=true beats false
 *   2. higher capability_score
 *   3. lower declared_cost_units
 *   4. lower declared_eta_ms
 *   5. sha256(provider_id || task_id) tiebreak
 */
final class BidComparator
{
    public const STEPS = ['eligibility', 'capability', 'cost', 'eta', 'tiebreak'];

    public function __construct(private readonly string $taskId) {}

    public function compare(ProviderBid $a, ProviderBid $b): int
    {
        return $this->stepCompare($a, $b)[0];
    }

    /**
     * @return array{0:int, 1:string}  [cmp, decisive_step]
     */
    public function stepCompare(ProviderBid $a, ProviderBid $b): array
    {
        // 1. eligibility: true beats false ⇒ true > false ⇒ b - a so that true sorts FIRST.
        $cmp = ($b->eligibilityBool ? 1 : 0) <=> ($a->eligibilityBool ? 1 : 0);
        if ($cmp !== 0) {
            return [$cmp, 'eligibility'];
        }
        // 2. capability_score descending.
        $cmp = $b->capabilityScore <=> $a->capabilityScore;
        if ($cmp !== 0) {
            return [$cmp, 'capability'];
        }
        // 3. cost ascending.
        $cmp = $a->declaredCostUnits <=> $b->declaredCostUnits;
        if ($cmp !== 0) {
            return [$cmp, 'cost'];
        }
        // 4. eta ascending.
        $cmp = $a->declaredEtaMs <=> $b->declaredEtaMs;
        if ($cmp !== 0) {
            return [$cmp, 'eta'];
        }
        // 5. tiebreak: sha256(provider_id || task_id) ascending.
        $aHash = hash('sha256', $a->providerId.'||'.$this->taskId);
        $bHash = hash('sha256', $b->providerId.'||'.$this->taskId);

        return [strcmp($aHash, $bHash), 'tiebreak'];
    }
}
