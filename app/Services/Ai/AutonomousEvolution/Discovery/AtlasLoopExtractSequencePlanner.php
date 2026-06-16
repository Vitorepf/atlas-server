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

        return $worst === null
            ? null
            : ['target_method' => $worst, 'target_method_bare' => self::bareMethodName($worst), 'cyclomatic' => $worstScore];
    }

    /**
     * ACDE R1 identity shim — the per-method census ({@see AtlasLoopSignalAnalyzer::fileComplexity}) keys
     * methods by QUALIFIED identity (`Class::method`, or `\function`) so a relocated method is a distinct
     * identity, but the framework-refactor objective text ({@see AtlasLoopFrameworkRefactorSynthesizer}) and
     * `worst_method` use the BARE method name. When R1 wires nextStep() to DRIVE the sequence, the step's
     * pinned method must be handed to the objective builder as the bare name — else the byte-identical-OFF
     * selection (today's worst_method) and the armed selection diverge on every method. This converts the
     * FQ identity back to the bare name the objective builder consumes.
     */
    public static function bareMethodName(string $identity): string
    {
        $id = trim($identity);
        $pos = strrpos($id, '::');
        if ($pos !== false) {
            $id = substr($id, $pos + 2);
        }

        return ltrim($id, '\\');
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

    /**
     * ACDE R2-read (MULTIPLIER) — pure decision: should the next sequence BACK OFF (attempt fewer/smaller
     * steps) because this structural shape has historically THRASHED? True iff the corpus holds >= minSamples
     * outcomes for the shape AND its certified-rate is below the target. A thin corpus (< minSamples) yields
     * NO signal => false => byte-identical (UNKNOWN never penalises, the Wilson-prior discipline). This is
     * the read-back that bends the curve: delivery N's recorded outcome makes delivery N+1 more certifiable.
     */
    public static function priorBacksOff(int $certified, int $total, int $minSamples, float $targetRate): bool
    {
        if ($total < max(1, $minSamples)) {
            return false; // thin corpus => UNKNOWN => no back-off
        }

        return ($certified / $total) < $targetRate;
    }
}
