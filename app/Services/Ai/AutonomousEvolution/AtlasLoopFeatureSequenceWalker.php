<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ACDE F4 — close the verified orphan: the EXECUTABLE walk of {@see AtlasLoopFeatureSequencePlanner}.
 *
 * F1 partitions a big feature's human-frozen atoms into an ordered chain of small steps and attaches that
 * GROUPING to the packet — but nothing turned the grouping into executable sub-acceptances, so the loop
 * still one-shot the whole feature. This walker closes that gap: for each step it produces the ACTIVE atoms
 * (the new sub-acceptance to turn green) PLUS every prior step's atoms as REGRESSION (held green), so the
 * loop grinds the feature incrementally and a later step can never silently break an earlier certified one.
 * The last step's cumulative atoms are EXACTLY the full feature — the composition of certified steps IS the
 * feature, with no re-authored or weakened criterion (it only ever GROUPS the atoms the human froze).
 *
 * Pure + deterministic (atoms in -> executable steps out); the caller compiles each step's frozen
 * sub-acceptance with the existing {@see AtlasLoopIntentVerifierFactory} (verification_atoms = active_atoms,
 * regression_atoms held as sealed holdouts). No provider, no IO.
 */
final class AtlasLoopFeatureSequenceWalker
{
    public function __construct(private readonly ?AtlasLoopFeatureSequencePlanner $planner = null) {}

    /**
     * Walk the feature's frozen atoms into executable incremental steps.
     *
     * @param  list<array<string,mixed>>  $atoms  the human-frozen verification_atoms
     * @return list<array{step:int, active_atoms:list<array<string,mixed>>, regression_atoms:list<array<string,mixed>>,
     *                cumulative_atoms:list<array<string,mixed>>, is_last:bool}>
     */
    public function steps(array $atoms, int $maxStepSize = 2): array
    {
        $planner = $this->planner ?? new AtlasLoopFeatureSequencePlanner;
        $plan = $planner->plan($atoms, $maxStepSize);
        $total = count($plan);
        if ($total === 0) {
            return [];
        }

        $out = [];
        $regression = [];
        foreach ($plan as $i => $planned) {
            $active = array_values((array) ($planned['atoms'] ?? []));
            $out[] = [
                'step' => (int) ($planned['step'] ?? ($i + 1)),
                'active_atoms' => $active,
                'regression_atoms' => array_values($regression),
                'cumulative_atoms' => array_merge(array_values($regression), $active),
                'is_last' => ($i + 1) === $total,
            ];
            $regression = array_merge($regression, $active);
        }

        return $out;
    }
}
