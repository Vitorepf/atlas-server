<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Lever 3 — the behavioral-equivalence STRENGTH gate.
 *
 * For an extremely complex refactor, "the frozen sibling test is green" is necessary but WEAK evidence of
 * behaviour preservation: a thin suite passes while untested branches silently break. The strongest cheap
 * proxy for "the suite is strong enough to have CAUGHT a behaviour change" is the mutation KILL RATIO —
 * killed / sampled over the exhaustively sampled decision mutants. This gate refuses a refactor whose
 * suite is too weak to clear a configurable floor, raising the trust floor toward the >93%-confidence bar.
 *
 * Pure + deterministic (unit-testable in isolation). Fail-OPEN by construction: floor <= 0 (OFF) or zero
 * mutants sampled never penalises — so it can never cause a false-reject when the signal is absent.
 */
final class AtlasLoopBehavioralEquivalenceGate
{
    /**
     * @return array{passes:bool, kill_ratio:?float, floor:float, mutants_sampled:int, reason:?string}
     */
    public function evaluate(int $mutantsSampled, int $mutantsKilled, float $floor): array
    {
        $floor = max(0.0, min(1.0, $floor));
        $sampled = max(0, $mutantsSampled);
        $killed = max(0, min($sampled, $mutantsKilled));
        $ratio = $sampled > 0 ? round($killed / $sampled, 3) : null;

        // OFF, or no signal => never gate (fail-open: no false-reject when the signal is absent).
        if ($floor <= 0.0 || $sampled <= 0) {
            return ['passes' => true, 'kill_ratio' => $ratio, 'floor' => $floor, 'mutants_sampled' => $sampled, 'reason' => null];
        }

        $passes = ($killed / $sampled) + 1e-9 >= $floor;

        return [
            'passes' => $passes,
            'kill_ratio' => $ratio,
            'floor' => $floor,
            'mutants_sampled' => $sampled,
            'reason' => $passes ? null : 'behavioral_equivalence:kill_ratio_below_floor:'.$ratio.'<'.$floor,
        ];
    }
}
