<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * THE HEAVY-WORK BRAIN — composes the decision primitives into ONE coherent choice, the operator's
 * full vision in a single deterministic, ungameable function:
 *
 *   1. PANEL ({@see AtlasLoopHeavyWorkPanelService}) — deterministic multi-lens VALUE per candidate
 *      from measured evidence (leverage / debt / failure-corpus × blast-radius reversibility).
 *   2. AMBITION ({@see AtlasLoopAmbitionDecider}) — choose the BIGGEST leap per commit: rank by
 *      magnitude · P(land)^riskTolerance, where magnitude = panel value scaled by scope (node count)
 *      and P(land) is the Bayesian acceptance rate of that work-class. Risk-seeking on AMBITION.
 *   3. TRUST ({@see AtlasLoopTrustLadder}) — risk-AVERSE on the GATE: the chosen leap may auto-merge
 *      ONLY if its class has a proven acceptance record (Wilson lower bound); else it parks.
 *   4. VERIFICATION — the gate tier the leap MUST clear, scaling with its magnitude.
 *
 * Net: ATTEMPT the biggest leap (max evolution per commit), MERGE only what passes proportionally
 * stronger proof and has earned the trust. The brain chooses WHAT to attempt + HOW strongly to gate it;
 * the frozen out-of-process stack still certifies the RESULT. Pure: no provider, no DB, no mutation.
 */
final class AtlasLoopHeavyWorkSelector
{
    public function __construct(
        private readonly ?AtlasLoopHeavyWorkPanelService $panel = null,
        private readonly ?AtlasLoopAmbitionDecider $ambition = null,
        private readonly ?AtlasLoopTrustLadder $trust = null,
    ) {}

    /**
     * @param  list<array<string,mixed>>  $candidates  each: {candidateId, kind, class?, node_count?,
     *     evidence:{refactor_leverage?, cyclomatic_total?, failure_evidence?, blast_radius?}}
     * @param  array<string,mixed>  $context  {class_stats:array<string,{successes,failures}>,
     *     risk_tolerance?:float}
     * @return array{schema_version:string, pick:?array<string,mixed>, ranked:list<array<string,mixed>>,
     *               strategy:string}
     */
    public function select(array $candidates, array $context = []): array
    {
        $panel = $this->panel ?? new AtlasLoopHeavyWorkPanelService();
        $ambition = $this->ambition ?? new AtlasLoopAmbitionDecider();
        $trust = $this->trust ?? new AtlasLoopTrustLadder();
        $classStats = is_array($context['class_stats'] ?? null) ? (array) $context['class_stats'] : [];

        // 1. PANEL value (deterministic, ungameable) per candidate, keyed by id.
        $valueById = [];
        foreach (($panel->decide($candidates)['ranked'] ?? []) as $row) {
            $valueById[(string) $row['candidateId']] = (float) ($row['risk_adjusted_score'] ?? 0.0);
        }

        // 2. AMBITION inputs: magnitude = panel value · scope; P(land) = Bayesian class acceptance.
        $ambitionInputs = [];
        $meta = [];
        foreach ($candidates as $c) {
            $id = trim((string) ($c['candidateId'] ?? ''));
            if ($id === '' || ! isset($valueById[$id])) {
                continue; // under-evidenced candidates were excluded by the panel — they do not compete
            }
            $class = (string) ($c['class'] ?? $c['kind'] ?? 'obra_candidate');
            $nodes = max(1, (int) ($c['node_count'] ?? count((array) ($c['allowed_files'] ?? [])) ?: 1));
            // magnitude (uncapped): the bigger the scope AND the higher the measured value, the bigger
            // the leap. value is 0..100 -> /10 so a 2-file 60-value cluster ~ 12, a 10-file 90 ~ 90.
            $magnitude = ($valueById[$id] / 10.0) * $nodes;
            $pLand = $this->bayes($classStats[$class] ?? []);
            $ambitionInputs[] = ['candidateId' => $id, 'leap_magnitude' => $magnitude, 'p_land' => $pLand, 'cost' => $nodes];
            $meta[$id] = ['class' => $class, 'node_count' => $nodes, 'panel_value' => $valueById[$id]];
        }

        // L3 — THE FIBONACCI WIRE: the rung grows with PROVEN capability. A rising capability_factor (fed by
        // CapabilityTrendService.trend() + per-class clean-streak) lowers the risk-tolerance dial toward
        // pure-magnitude (rt→0), so the loop DARES a bigger, lower-P(land) leap — the SELECTED pick's scope
        // climbs as capability is proven. This is REAL compounding (the SELECTION shifts to bigger work),
        // never a number bump (a uniform magnitude scale would not change the pick = the forbidden proxy).
        // Absent => the §3 default risk-seeking (0.35) stands, so the producer's flag-OFF is byte-identical.
        $rt = isset($context['risk_tolerance']) ? (float) $context['risk_tolerance'] : null;
        if (array_key_exists('capability_factor', $context)) {
            // L3 FIBONACCI LADDER: at cap=0 the loop starts at the EV-OPTIMAL (smallest-safe) rung — rt=1 is
            // risk-neutral EV (magnitude·P(land)), which prefers the high-P(land) small leap; each PROVEN
            // delivery lifts capability => rt falls toward pure-magnitude (rt=0) => the loop DARES the bigger,
            // lower-P(land) leap. The operator's "start small, compound to bigger". Flag-OFF (no
            // capability_factor) => the §3 default risk-seeking (0.35) stands => byte-identical.
            $cap = max(0.0, min(1.0, (float) $context['capability_factor']));
            $rt = max(0.0, 1.0 - $cap);
        }
        $ranked = $ambition->rank($ambitionInputs, $rt)['ranked'] ?? [];

        // 3 + 4. Stamp each ranked candidate with its trust verdict (gate) and required verification.
        foreach ($ranked as &$row) {
            $id = (string) $row['candidateId'];
            $class = (string) ($meta[$id]['class'] ?? 'obra_candidate');
            $t = $trust->assess($classStats[$class] ?? []);
            $row['class'] = $class;
            $row['panel_value'] = $meta[$id]['panel_value'] ?? null;
            $row['node_count'] = $meta[$id]['node_count'] ?? null; // the leap's scope (the rung size)
            $row['trust'] = $t;
            $row['gate'] = $this->resolveGate($row, $t);
        }
        unset($row);

        return [
            'schema_version' => 'atlas.loop.heavy_work_selection.v1',
            'pick' => $ranked[0] ?? null,
            'ranked' => $ranked,
            'strategy' => 'attempt_the_biggest_leap_merge_only_proven_proof',
        ];
    }

    /** Bayesian Laplace acceptance rate (s+1)/(s+f+2). */
    private function bayes(array $stats): float
    {
        $s = max(0, (int) ($stats['successes'] ?? 0));
        $f = max(0, (int) ($stats['failures'] ?? 0));

        return ($s + 1) / ($s + $f + 2);
    }

    /**
     * The leap may auto-merge ONLY if its required tier permits AND the class earned autonomy.
     *
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $trust
     */
    private function resolveGate(array $row, array $trust): string
    {
        $needsTrust = ($row['required_verification']['autonomous_merge_requires'] ?? '') === 'trust_ladder_autonomous';

        return (! $needsTrust || $trust['can_auto_merge'])
            ? ($trust['can_auto_merge'] ? 'autonomous_merge_eligible' : 'operator_review')
            : 'park_for_operator_until_trust_earned';
    }
}
