<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * EXPECTED-VALUE / BOTTLENECK-DRIVEN decider — the EXPLICIT math the operator's intuition demands:
 * "using mathematics we can understand the best next leap." It is not a formula that proves the
 * future; it is the DECISION-THEORETIC + CONSTRAINT-THEORETIC + BAYESIAN choice, grounded in measured
 * numbers and self-calibrating as the soak feeds back.
 *
 * For each candidate big-work it computes:
 *
 *     EV = P_success(class) · value · reliefMultiplier(bottleneck) − costPenalty
 *
 *   - P_success(class)  the probability the provider actually LANDS work of this class, estimated
 *                       BAYESIAN (Laplace rule of succession over the observed successes/failures of
 *                       that class): (s+1)/(s+f+2). With zero data it is 0.5 (max uncertainty); every
 *                       soak result updates it. THIS is the loop closing: rank → act → measure → update.
 *   - value             the panel's deterministic risk-adjusted score in [0,1] (already ungameable).
 *   - reliefMultiplier  THEORY OF CONSTRAINTS: the system's grade is limited by its BINDING axis (the
 *                       largest weighted gap weight·(1−value) across the utility-grade axes). Work that
 *                       improves a binding axis is worth more per unit — argmax of marginal system gain.
 *   - costPenalty       provider spend proxy (node count), so a cheap near-equal leap is preferred.
 *
 * The pick = argmax(EV). HONEST: the receipt stamps is_optimal=false / estimate_calibrates=true — it is
 * the OPTIMAL DECISION GIVEN CURRENT GROUNDED BELIEFS, which improve every cycle; never a proof of the
 * outcome. Pure: no provider call, no DB, no mutation. Propose-only; the frozen stack certifies results.
 */
final class AtlasLoopExpectedValueDecider
{
    /** Utility-grade axis weights (must match AtlasLoopUtilityGradeService's U formula). */
    private const AXIS_WEIGHTS = [
        'wired' => 0.35,
        'real_target' => 0.20,
        'non_trivial' => 0.15,
        'compounding' => 0.20,
        'safety' => 0.10,
    ];

    private const RELIEF_WEIGHT = 1.0;   // how strongly bottleneck-relief multiplies value

    private const COST_WEIGHT = 0.15;    // provider-spend penalty per normalized node

    /**
     * @param  list<array<string,mixed>>  $candidates  each: {candidateId, class, value (0..100 from the
     *     panel), touches_axes:list<string> (grade axes this work improves), node_count?}
     * @param  array<string,mixed>  $context  {axis_values:array<string,float> (current grade axis [0,1]),
     *     class_stats:array<string,array{successes:int,failures:int}>, max_node_count?}
     * @return array{schema_version:string, bottleneck:array<string,mixed>, winner:?array<string,mixed>,
     *               ranked:list<array<string,mixed>>, is_optimal:bool, estimate_calibrates:bool}
     */
    public function decide(array $candidates, array $context): array
    {
        $axisValues = is_array($context['axis_values'] ?? null) ? (array) $context['axis_values'] : [];
        $classStats = is_array($context['class_stats'] ?? null) ? (array) $context['class_stats'] : [];
        $maxNodes = max(1, (int) ($context['max_node_count'] ?? 8));

        // BOTTLENECK: the binding axis = the largest weighted gap. Normalize gaps to [0,1] relief.
        $gaps = [];
        foreach (self::AXIS_WEIGHTS as $axis => $weight) {
            $v = max(0.0, min(1.0, (float) ($axisValues[$axis] ?? 1.0)));
            $gaps[$axis] = $weight * (1.0 - $v);
        }
        $maxGap = max(0.0, ...array_values($gaps)) ?: 1.0;
        arsort($gaps);
        $bindingAxis = (string) array_key_first($gaps);

        $ranked = [];
        foreach ($candidates as $c) {
            $id = trim((string) ($c['candidateId'] ?? ''));
            if ($id === '') {
                continue;
            }
            $class = (string) ($c['class'] ?? 'obra_candidate');
            $value = max(0.0, min(1.0, ((float) ($c['value'] ?? 0.0)) / 100.0));
            $nodes = max(1, (int) ($c['node_count'] ?? 1));
            $touches = array_values(array_filter((array) ($c['touches_axes'] ?? []), 'is_string'));

            $p = $this->bayesianSuccessRate($classStats[$class] ?? []);
            // Relief = the share of the BINDING weighted-gap this work would relieve, normalized [0,1].
            $relief = 0.0;
            foreach ($touches as $axis) {
                $relief += ($gaps[$axis] ?? 0.0);
            }
            $relief = min(1.0, $relief / $maxGap);

            $reliefMultiplier = 1.0 + self::RELIEF_WEIGHT * $relief;
            $costPenalty = self::COST_WEIGHT * ($nodes / $maxNodes);
            $ev = round($p * $value * $reliefMultiplier - $costPenalty, 6);

            $ranked[] = [
                'candidateId' => $id,
                'class' => $class,
                'p_success' => round($p, 4),
                'value' => round($value, 4),
                'relief' => round($relief, 4),
                'cost_penalty' => round($costPenalty, 4),
                'ev' => $ev,
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => [$b['ev'], $a['candidateId']] <=> [$a['ev'], $b['candidateId']]);

        return [
            'schema_version' => 'atlas.loop.expected_value_decision.v1',
            'bottleneck' => ['binding_axis' => $bindingAxis, 'weighted_gap' => round($gaps[$bindingAxis] ?? 0.0, 4)],
            'winner' => $ranked[0] ?? null,
            'ranked' => $ranked,
            // HONEST: the OPTIMAL DECISION given current grounded beliefs, which calibrate each cycle —
            // NEVER a proof of the outcome (P_success is estimated, not known).
            'is_optimal' => false,
            'estimate_calibrates' => true,
        ];
    }

    /**
     * Bayesian P(success) — the Laplace rule of succession (Beta(1,1) posterior mean): (s+1)/(s+f+2).
     * Zero observations => 0.5 (honest maximum uncertainty); every observed soak result moves it.
     *
     * @param  array<string,mixed>  $stats  {successes, failures}
     */
    private function bayesianSuccessRate(array $stats): float
    {
        $s = max(0, (int) ($stats['successes'] ?? 0));
        $f = max(0, (int) ($stats['failures'] ?? 0));

        return ($s + 1) / ($s + $f + 2);
    }
}
