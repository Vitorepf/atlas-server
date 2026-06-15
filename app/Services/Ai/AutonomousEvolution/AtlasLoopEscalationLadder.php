<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * NEXT-LEVER 2 — the ESCALATION LADDER ("runs as many rounds as needed to certify extreme quality").
 *
 * Today the loop gives up at a FIXED N (best-of-N caps at 12, iterate-to-green at 3-4). For extreme
 * quality, when a round fails to certify the loop should ESCALATE to a stronger strategy instead of
 * surrendering — bounded by the QUALITY BAR (certified?) and a round budget, NOT by a fixed N. This is the
 * pure escalation policy: given the latest round's outcome it decides the NEXT tier (or STOP):
 *
 *   1. best_of_n            — N decorrelated attempts, frozen-judge picks the best (Lever 4);
 *   2. repair_from_refutation — feed the cert's EXACT refutation reasons back and fix them (iterate-to-green);
 *   3. decompose            — break the goal into a certified DAG of smaller steps (Lever 1 planner);
 *   4. escalate_provider    — strongest provider / larger budget on the now-decomposed pieces.
 *
 * It DECIDES; the orchestrator EXECUTES each tier. Thrashing (the ledger's signal that the same failure
 * recurs) jumps the loop forward rather than burning another identical round. Pure + deterministic.
 */
final class AtlasLoopEscalationLadder
{
    public const TIERS = ['best_of_n', 'repair_from_refutation', 'decompose', 'escalate_provider'];

    /**
     * @param  array{certified?:bool, round?:int, max_rounds?:int, thrashing?:bool, last_reason?:string, current_tier?:string}  $state
     * @return array{stop:bool, action:string, next_tier:?string, round:int, reason:string}
     */
    public function next(array $state): array
    {
        $certified = (bool) ($state['certified'] ?? false);
        $round = max(0, (int) ($state['round'] ?? 0));
        $maxRounds = max(1, (int) ($state['max_rounds'] ?? 6));
        $thrashing = (bool) ($state['thrashing'] ?? false);
        $currentTier = (string) ($state['current_tier'] ?? '');

        // STOP — the quality bar is met. This, not a fixed N, is the true terminator.
        if ($certified) {
            return ['stop' => true, 'action' => 'accept', 'next_tier' => null, 'round' => $round, 'reason' => 'certified'];
        }
        // STOP — round budget exhausted (the only ceiling besides certification).
        if ($round >= $maxRounds) {
            return ['stop' => true, 'action' => 'give_up', 'next_tier' => null, 'round' => $round, 'reason' => 'budget_exhausted:'.$round.'/'.$maxRounds];
        }

        // ESCALATE — advance to the next, stronger tier.
        $idx = array_search($currentTier, self::TIERS, true);
        $nextIdx = $idx === false ? 0 : $idx + 1;
        if ($nextIdx >= count(self::TIERS)) {
            return ['stop' => true, 'action' => 'give_up', 'next_tier' => null, 'round' => $round, 'reason' => 'ladder_exhausted_uncertified'];
        }

        $nextTier = self::TIERS[$nextIdx];

        return [
            'stop' => false,
            'action' => 'escalate',
            'next_tier' => $nextTier,
            'round' => $round + 1,
            'reason' => ($thrashing ? 'thrashing->' : 'uncertified->').$nextTier,
        ];
    }
}
