<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ARBOR-GRAFT DD1 (text) — the engineering-translated idea_drafting rubric, injected at the generation
 * chokepoint to raise candidate quality on a small model (Arbor's highest-value ideation lever). It is
 * CONTEXT ONLY — it shapes the next draft, never certifies. Arbor's research flavor (paper paradigm shifts)
 * is translated to engineering: bottleneck CLASS = perf | correctness | coverage | coupling; mechanism =
 * a new abstraction / refactor / test, not a knob.
 */
final class AtlasLoopIdeaDraftingRubric
{
    public static function text(): string
    {
        return <<<'TXT'
        # IDEA DRAFTING (apply before proposing the objective)

        ## First-Principles Probe (answer all four, each citing >=2 concrete files)
        Q1 Bottleneck CLASS (not instance): perf | correctness | coverage | coupling — cite >=2 files.
        Q2 Hidden assumption the current code silently relies on — and what opens up if dropped.
        Q3 Elephant: the ugly real friction the current design works around.
        Q4 If Q1 were solved, does this module/area meaningfully change? If not, redo Q1.

        Output a PROBE BLOCK:
          PROBE BLOCK
          Q1: <class> — evidence: <path1>, <path2>
          Q2: <assumption> — if dropped: <what opens>
          Q3: <elephant>
          Q4: <yes/no + one sentence>

        ## Four orthogonal moves (sweep all, keep the sharpest)
        A assumption-inversion · B backward-from-success · C analogical-transfer · D failure-reverse-engineering

        ## Per-candidate 5-field declaration
        1 assumption-challenged  2 mechanism-class  3 mechanism+hypothesis (X helps because Y, known if Z)
        4 orthogonality-vs-siblings  5 conflicts-with-prior-pruned-lessons

        ## Kill-filter (drop the candidate if any holds)
        single-knob? · reword-only? · more-X (retries/context/size)? · goal-not-mechanism? · re-treads a
        pruned lesson without a counter? · probe-disconnected (the mechanism does not attack Q1)?
        TXT;
    }
}
