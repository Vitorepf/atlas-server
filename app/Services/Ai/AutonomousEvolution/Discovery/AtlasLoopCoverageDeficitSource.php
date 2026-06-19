<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Constitution\Frozen\AtlasLoopFrozenMutationOperators;

/**
 * §11.6 — COVERAGE-DEFICIT discovery source (de-parasitized).
 *
 * Today, characterization-test work only ever fires as a SIDE-EFFECT of a blocked refactor: the loop
 * notices a file is unverifiable while trying to refactor it, and only THEN proposes a test. That makes
 * coverage a parasite on the refactor lane — a wired, decision-dense, UNTESTED file that nobody is
 * currently refactoring never surfaces as work, so its mutants sit un-killed indefinitely.
 *
 * This source scores a file on its OWN merits, independent of any pending refactor, by MUTATION-SURVIVAL
 * DENSITY: how many radius-1 frozen mutants live in the source (each is a decision a test could pin), and
 * whether a sibling characterization test already exists to refute them. A file with many mutants and NO
 * sibling test is a high coverage deficit — every mutant is a behaviour that could silently flip with
 * nothing to catch it. A file that already has a sibling test, or that has (almost) no mutants, is low.
 *
 * The mutant denominator is the FROZEN kill-vocabulary ({@see AtlasLoopFrozenMutationOperators::neighborhood()},
 * the same pétreo neighbourhood the battery uses), so the deficit cannot be gamed by shrinking the operator
 * set: this source reads the Constitution's neighbourhood, it never invents its own.
 *
 * Pure: {@see score()} and {@see deficitObjective()} are deterministic functions of their inputs — no clock,
 * provider, DB or filesystem (the caller resolves the source text and sibling-test existence and passes them
 * in via the absolute path + a boolean). Wiring into the discovery feed is done separately.
 */
final class AtlasLoopCoverageDeficitSource
{
    public const SHAPE = 'characterization_test';

    /**
     * The PHP open tag (`<?php`) matches the frozen `lt_comparison` operator (`<` not followed by `=`/`<`),
     * so EVERY php source carries at least one structural mutant that is not a real decision point. We treat
     * that one as floor noise: the deficit scale starts counting real decision-density above it.
     */
    private const STRUCTURAL_FLOOR_MUTANTS = 1;

    /**
     * The mutant count (above the structural floor) at which an untested file is a FULLY saturated coverage
     * deficit (deficit → 1.0). A handful of un-killed decisions is already a strong signal, so the scale is
     * deliberately tight; more mutants past this only keep it pinned at the ceiling.
     */
    private const SATURATION_MUTANTS = 6;

    /** deficit at/above this is "high" — worth emitting a characterization_test objective. */
    public const HIGH_DEFICIT_THRESHOLD = 0.5;

    /**
     * Score a file's coverage deficit by mutation-survival density.
     *
     * @param  string  $absFilePath     absolute path to the source file to score
     * @param  bool    $hasSiblingTest  whether a sibling characterization test already exists for it
     * @return array{mutants:int, has_sibling_test:bool, deficit:float, reason:string}
     */
    public function score(string $absFilePath, bool $hasSiblingTest): array
    {
        $source = is_file($absFilePath) ? (string) file_get_contents($absFilePath) : '';
        $mutants = count(AtlasLoopFrozenMutationOperators::neighborhood($source));

        return $this->scoreSource($mutants, $hasSiblingTest);
    }

    /**
     * The pure core — scored straight from a known mutant count (used by {@see score()} after it resolves the
     * neighbourhood, and directly testable without touching the filesystem).
     *
     * @return array{mutants:int, has_sibling_test:bool, deficit:float, reason:string}
     */
    public function scoreSource(int $mutants, bool $hasSiblingTest): array
    {
        // Real decision density = mutants above the unavoidable structural-floor (the `<?php` tag etc.).
        $realMutants = max(0, $mutants - self::STRUCTURAL_FLOOR_MUTANTS);

        // Normalize the surviving-mutant density into [0,1]: 0 real mutants ⇒ 0, saturation ⇒ 1.
        $density = $realMutants <= 0
            ? 0.0
            : min(1.0, $realMutants / self::SATURATION_MUTANTS);

        // A sibling test refutes those mutants, so it scales the deficit DOWN to ~0; no test leaves it HIGH.
        // (A tiny residual remains for a tested-but-dense file so it never reads as a perfect 0, but it stays
        //  far below the HIGH threshold — a tested file is never proposed.)
        $deficit = $hasSiblingTest
            ? round($density * 0.05, 6)
            : round($density, 6);

        $reason = $this->reason($realMutants, $hasSiblingTest, $deficit);

        return [
            'mutants' => $mutants,
            'has_sibling_test' => $hasSiblingTest,
            'deficit' => $deficit,
            'reason' => $reason,
        ];
    }

    /**
     * When the deficit is high, emit a characterization_test objective targeting the file; else null.
     *
     * @param  array{mutants:int, has_sibling_test:bool, deficit:float, reason:string}  $score
     * @return array{objective:string, target_path:string, shape:string}|null
     */
    public function deficitObjective(string $relPath, array $score): ?array
    {
        if ($score['deficit'] < self::HIGH_DEFICIT_THRESHOLD || $score['has_sibling_test']) {
            return null;
        }

        $rel = ltrim(str_replace('\\', '/', $relPath), '/');

        return [
            'objective' => $this->objectiveText($rel, $score['mutants']),
            'target_path' => $rel,
            'shape' => self::SHAPE,
        ];
    }

    private function reason(int $realMutants, bool $hasSiblingTest, float $deficit): string
    {
        if ($realMutants <= 0) {
            return 'no decision density (no surviving mutants above structural floor) — no coverage deficit';
        }
        if ($hasSiblingTest) {
            return "{$realMutants} decision-point mutant(s) but a sibling characterization test exists — covered (deficit {$deficit})";
        }

        return "{$realMutants} decision-point mutant(s) with NO sibling characterization test — un-killed behaviours (deficit {$deficit})";
    }

    private function objectiveText(string $rel, int $mutants): string
    {
        return "ADD a characterization test for {$rel}: it carries {$mutants} radius-1 frozen mutants "
            ."(decision points) with NO sibling test, so its behaviour can silently flip with nothing to catch "
            ."it. Write a sibling test that PINS the file's current observable behaviour — exercising each "
            ."decision branch so a radius-1 mutation of any of them would make the test RED. This is coverage "
            ."debt scored on the file's own mutation-survival density, independent of any refactor.";
    }
}
