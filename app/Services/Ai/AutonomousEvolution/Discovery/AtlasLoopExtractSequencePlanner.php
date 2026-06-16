<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ACDE lever #6 — the SEQUENCED single-method extract planner (the in-lane decompose of a god-class).
 *
 * The weak engine cannot one-shot a whole god-class extraction AND drive cyclomatic down in a single diff —
 * the existing {@see AtlasLoopExtractClassObjectiveBuilder} emits ONE task and hopes. This planner turns that
 * into an ORDERED sequence of single-method extract steps, each tractable for the weak engine and each
 * certified through the proven Path B framework-refactor cert: step N pins the CURRENT worst method; only
 * after that step commits and the file is RE-MEASURED is step N+1 chosen. The composition of N certified
 * small diffs IS the big delivery — quality from the FLOW, not the model — and it never routes through the
 * blocking obra-DAG.
 *
 * Pure + deterministic (no DB, no provider): it consumes the per-method cyclomatic census from
 * {@see \App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer::fileComplexity} and is fully
 * unit-testable. Re-measuring after each step is what makes the chain self-correcting: one extraction can
 * lower more than one method (or a different one than expected), and {@see nextStep} simply targets whatever
 * is the real current worst — so the "a step simplified a DIFFERENT kept method" case the design flagged is
 * handled structurally, not with a special case.
 */
final class AtlasLoopExtractSequencePlanner
{
    /**
     * The NEXT extract step: the current worst method whose cyclomatic STRICTLY exceeds the tractable
     * threshold, or null when every method is already tractable (the sequence is DONE). Deterministic and
     * order-stable — ties break to the lexicographically smaller identity — so the chain is reproducible.
     *
     * @param  array<string,int>  $perMethod  identity => cyclomatic (fileComplexity()['per_method'])
     * @return array{target_method:string, cyclomatic:int}|null
     */
    public function nextStep(array $perMethod, int $tractableThreshold): ?array
    {
        $threshold = max(1, $tractableThreshold);
        $worst = null;
        $worstScore = 0;
        foreach ($perMethod as $method => $score) {
            $method = (string) $method;
            $score = (int) $score;
            if ($score <= $threshold) {
                continue;
            }
            if ($score > $worstScore || ($score === $worstScore && ($worst === null || strcmp($method, $worst) < 0))) {
                $worst = $method;
                $worstScore = $score;
            }
        }

        return $worst === null ? null : ['target_method' => $worst, 'cyclomatic' => $worstScore];
    }

    /**
     * A STATIC projection of the full worst-first sequence from the initial census — for budgeting and
     * telemetry only. Actual execution RE-MEASURES after each step (see the class doc), so this is an
     * upper-bound plan, not a guarantee. Capped at maxSteps; every entry is one method strictly above the
     * tractable threshold, ordered worst-first with a deterministic name tie-break.
     *
     * @param  array<string,int>  $perMethod
     * @return list<array{step_index:int, target_method:string, cyclomatic:int}>
     */
    public function plan(array $perMethod, int $tractableThreshold, int $maxSteps): array
    {
        $threshold = max(1, $tractableThreshold);
        $cap = max(1, $maxSteps);

        $above = [];
        foreach ($perMethod as $method => $score) {
            if ((int) $score > $threshold) {
                $above[(string) $method] = (int) $score;
            }
        }
        uksort($above, static fn (string $a, string $b): int => ($above[$b] <=> $above[$a]) ?: strcmp($a, $b));

        $plan = [];
        $i = 0;
        foreach ($above as $method => $score) {
            if ($i >= $cap) {
                break;
            }
            $plan[] = ['step_index' => $i, 'target_method' => (string) $method, 'cyclomatic' => (int) $score];
            $i++;
        }

        return $plan;
    }

    /** A stable id grouping every step of one god-class decomposition (keyed on the target file path). */
    public function sequenceId(string $targetPath): string
    {
        return 'xseq-'.substr(hash('sha256', trim($targetPath)), 0, 12);
    }
}
