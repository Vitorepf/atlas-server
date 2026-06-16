<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ACDE lever F1 — the in-lane SEQUENCED-FEATURE planner.
 *
 * The weak engine cannot one-shot a huge feature, but it CAN land a small one whose acceptance is a sealed,
 * human-frozen RED test. This planner turns one big feature's verification atoms into an ORDERED chain of
 * small steps — each step a contiguous SUBSET of the atoms — so the loop builds the feature incrementally:
 * grind step 1 to green (its atoms become the frozen sub-acceptance), then step 2 with step 1 held as
 * regression, and so on. The composition of certified small steps IS the big feature.
 *
 * CEILING (honoured by construction): a feature step's bar has NO code-intrinsic re-measure anchor (unlike a
 * refactor's cyclomatic drop), so it MUST be human-frozen. This planner therefore only GROUPS + ORDERS the
 * atoms the human already authored — it never invents, weakens, or re-authors a criterion. Pure + deterministic
 * (atoms in → ordered steps out); the frozen sub-acceptance for each step is exactly its atom subset, compiled
 * by the existing {@see AtlasLoopIntentVerifierFactory} (which already accepts a verification_atoms subset).
 */
final class AtlasLoopFeatureSequencePlanner
{
    /**
     * Partition the (human-frozen) atoms into an ordered chain of steps of at most $maxStepSize atoms each,
     * preserving the author's atom order (the intent order IS the build order — there is no intrinsic
     * complexity metric to reorder features by).
     *
     * @param  list<array<string,mixed>>  $atoms  the human-frozen verification_atoms
     * @return list<array{step:int, atoms:list<array<string,mixed>>}>
     */
    public function plan(array $atoms, int $maxStepSize = 2): array
    {
        $atoms = array_values(array_filter($atoms, 'is_array'));
        if ($atoms === []) {
            return [];
        }
        $maxStepSize = max(1, $maxStepSize);

        $steps = [];
        $step = 1;
        foreach (array_chunk($atoms, $maxStepSize) as $chunk) {
            $steps[] = ['step' => $step, 'atoms' => array_values($chunk)];
            $step++;
        }

        return $steps;
    }

    /**
     * The frozen atom-subset for step N (1-based) — exactly what the verifier factory compiles for that step.
     * Out-of-range => [] (the chain is done).
     *
     * @param  list<array<string,mixed>>  $atoms
     * @return list<array<string,mixed>>
     */
    public function stepAtoms(array $atoms, int $step, int $maxStepSize = 2): array
    {
        foreach ($this->plan($atoms, $maxStepSize) as $planned) {
            if ($planned['step'] === $step) {
                return $planned['atoms'];
            }
        }

        return [];
    }

    /**
     * The number of steps the feature decomposes into (0 when there are no atoms).
     *
     * @param  list<array<string,mixed>>  $atoms
     */
    public function stepCount(array $atoms, int $maxStepSize = 2): int
    {
        return count($this->plan($atoms, $maxStepSize));
    }

    /**
     * A stable id grouping the steps of ONE feature sequence (for observability across grind waves).
     */
    public function sequenceId(string $target, string $intent): string
    {
        return substr(hash('sha256', trim($target).'|'.trim($intent)), 0, 16);
    }
}
