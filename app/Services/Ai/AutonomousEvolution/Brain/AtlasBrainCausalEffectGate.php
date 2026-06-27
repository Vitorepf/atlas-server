<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternLearningLedger;

/**
 * EXTERNAL BRAIN · keystone #4 — the CAUSAL effect gate over the pattern/path learning ledger.
 *
 * The existing {@see AtlasLoopPatternLearningLedger} answers "what was this path's success_rate?" — a
 * CORRELATIONAL number. A path can look good simply because it was tried on easy objectives. This gate answers
 * the causal question the meta-learner actually needs: "does USING this path BEAT the baseline (every other
 * path), and is that difference real or noise?" It estimates the effect = p(success | path) − p(success | rest)
 * with a confidence interval, and only ADMITS a path for compounding when the CI excludes zero on the positive
 * side. That is the rigorous form of the "no fabricated compounding signal" floor: a lucky/weak delta can never
 * become memorized policy. {@see isInvariantPositive()} adds the IRM-lite check — the effect must hold across
 * ≥N distinct objective classes — so the brain keeps only levers that GENERALIZE, not ones that won where born.
 *
 * Pure read-model: it never writes (no scalar is stored — the effect is computed at read time from the ledger's
 * raw facts, like the selector's scoring), so it preserves the ledger's no-scalar contract. It is pétreo: a
 * decision organ the brain may never edit (a réu that rewrites its own promotion gate is the Goodhart hole).
 */
final class AtlasBrainCausalEffectGate
{
    /**
     * Minimum samples per arm before any effect is trusted. Small but honest: below this, the gate refuses
     * (insufficient_n) rather than promote on noise.
     * ponytail: a flat floor; upgrade to a power-analysis / sequential test when run volume justifies it.
     */
    private const MIN_N = 5;

    /** 95% two-sided normal quantile. */
    private const Z_95 = 1.959963985;

    public function __construct(private readonly AtlasLoopPatternLearningLedger $ledger) {}

    /**
     * Estimate the causal effect of using $patternId vs the baseline (all other patterns), optionally within a
     * single objective class. Two-proportion difference with a Wald CI.
     * ponytail: Wald two-proportion CI; upgrade to DML/IPS (doubly-robust) when confounding between which path
     * gets tried and objective difficulty actually bites — the ledger already logs objective_class to support it.
     *
     * @return array{pattern_id:string, objective_class:?string, n_treat:int, n_base:int, effect:float, ci_low:float, ci_high:float, admit_compounding:bool, reason:string}
     */
    public function effect(string $patternId, ?string $objectiveClass = null): array
    {
        $patternId = trim($patternId);
        $oc = $objectiveClass !== null ? trim($objectiveClass) : null;

        $rows = $this->ledger->all();
        if ($oc !== null && $oc !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => (string) ($r['objective_class'] ?? '') === $oc
            ));
        }

        $treat = array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['pattern_id'] ?? '') === $patternId));
        $base = array_values(array_filter($rows, static fn (array $r): bool => (string) ($r['pattern_id'] ?? '') !== $patternId));

        $nT = count($treat);
        $nB = count($base);

        if ($nT < self::MIN_N || $nB < self::MIN_N) {
            return $this->envelope($patternId, $oc, $nT, $nB, 0.0, 0.0, 0.0, false, 'insufficient_n');
        }

        $pT = $this->successes($treat) / $nT;
        $pB = $this->successes($base) / $nB;
        $effect = $pT - $pB;
        $se = sqrt(($pT * (1 - $pT) / $nT) + ($pB * (1 - $pB) / $nB));
        $ciLow = $effect - self::Z_95 * $se;
        $ciHigh = $effect + self::Z_95 * $se;

        if ($ciLow > 0.0) {
            return $this->envelope($patternId, $oc, $nT, $nB, $effect, $ciLow, $ciHigh, true, 'admitted');
        }

        // Distinguish "this path is no better / worse" from "promising but not yet proven".
        $reason = $effect <= 0.0 ? 'effect_not_positive' : 'ci_includes_zero';

        return $this->envelope($patternId, $oc, $nT, $nB, $effect, $ciLow, $ciHigh, false, $reason);
    }

    /**
     * IRM-lite invariance: the path's effect must be admitted in at least $minClasses distinct objective
     * classes — a lever that only wins in one regime is not transportable and is NOT kept as a champion.
     */
    public function isInvariantPositive(string $patternId, int $minClasses = 2): bool
    {
        $positive = 0;
        foreach ($this->objectiveClassesFor($patternId) as $oc) {
            if (($this->effect($patternId, $oc)['admit_compounding'] ?? false) === true) {
                $positive++;
            }
        }

        return $positive >= max(1, $minClasses);
    }

    /** @param list<array<string,mixed>> $rows */
    private function successes(array $rows): int
    {
        return count(array_filter(
            $rows,
            static fn (array $r): bool => (string) ($r['result'] ?? '') === AtlasLoopPatternLearningLedger::RESULT_SUCCESS
        ));
    }

    /** @return list<string> distinct objective classes this pattern has been run on. */
    private function objectiveClassesFor(string $patternId): array
    {
        $classes = [];
        foreach ($this->ledger->all() as $row) {
            if ((string) ($row['pattern_id'] ?? '') !== trim($patternId)) {
                continue;
            }
            $oc = trim((string) ($row['objective_class'] ?? ''));
            if ($oc !== '') {
                $classes[$oc] = true;
            }
        }

        return array_keys($classes);
    }

    /**
     * @return array{pattern_id:string, objective_class:?string, n_treat:int, n_base:int, effect:float, ci_low:float, ci_high:float, admit_compounding:bool, reason:string}
     */
    private function envelope(string $patternId, ?string $oc, int $nT, int $nB, float $effect, float $ciLow, float $ciHigh, bool $admit, string $reason): array
    {
        return [
            'pattern_id' => $patternId,
            'objective_class' => $oc,
            'n_treat' => $nT,
            'n_base' => $nB,
            'effect' => round($effect, 6),
            'ci_low' => round($ciLow, 6),
            'ci_high' => round($ciHigh, 6),
            'admit_compounding' => $admit,
            'reason' => $reason,
        ];
    }
}
