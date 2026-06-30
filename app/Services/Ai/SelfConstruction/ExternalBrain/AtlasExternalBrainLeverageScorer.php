<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Ranks brain opportunities by compounding leverage instead of ease.
 *
 * Scoring dimensions (weights sum to 1.0):
 *   - capability_unlock       (0.30) New architectural capability unlocked.
 *   - dependency_unblock      (0.20) Downstream tasks / capabilities directly unblocked.
 *   - implementation_evidence (0.20) Evidence that this is feasible and well-scoped.
 *   - repeated_pain           (0.15) Recurrence — recurring problems compound leverage.
 *   - blast_radius_safety     (0.15) Containment (high = safe, low = wide blast radius).
 *
 * Anti-proxy penalties (additive, capped at 1.0, then applied multiplicatively):
 *   - cosmetic_cli        -0.25  CLI wrapper adding no new capability behind it.
 *   - one_test_microtask  -0.20  Single-test scope with no systemic reach.
 *   - duplicated_target   -0.30  Target already covered by another open task.
 *   - already_satisfied   -0.35  Capability already verified as present.
 *
 * Pure / deterministic. No I/O.
 */
final class AtlasExternalBrainLeverageScorer
{
    public const SCHEMA = 'atlas.external_brain.leverage_scorer.v1';

    private const WEIGHTS = [
        'capability_unlock'       => 0.30,
        'dependency_unblock'      => 0.20,
        'implementation_evidence' => 0.20,
        'repeated_pain'           => 0.15,
        'blast_radius_safety'     => 0.15,
    ];

    private const PENALTY_FACTORS = [
        'cosmetic_cli'       => 0.25,
        'one_test_microtask' => 0.20,
        'duplicated_target'  => 0.30,
        'already_satisfied'  => 0.35,
    ];

    // Unproven-high-claim: capability_unlock above this with weak proof_weight incurs a penalty.
    private const PROOF_REQUIRED_THRESHOLD    = 0.70;
    private const WEAK_PROOF_THRESHOLD        = 0.30;
    private const UNPROVEN_CLAIM_PENALTY      = 0.20;

    /**
     * Score a single opportunity, including a compound-impact receipt.
     *
     * compound_impact fields are extracted from the opportunity input:
     *   - unlocked_capabilities    list<string>  capabilities this opportunity makes possible
     *   - downstream_unblock_count int           number of downstream tasks/caps directly freed
     *   - risk_reduction_signals   list<string>  named risks this removes or reduces
     *   - autonomy_gain_signals    list<string>  named autonomy dimensions this improves
     *
     * why_this_beats_next is null here; rank() fills it after ordering.
     *
     * @param  array<string, mixed>  $opportunity
     * @return array<string, mixed>
     */
    public function score(array $opportunity): array
    {
        $label = (string) ($opportunity['label'] ?? '');

        $dimensionScores = [];
        $weightedSum = 0.0;
        foreach (self::WEIGHTS as $dim => $weight) {
            $clamped = max(0.0, min(1.0, (float) ($opportunity[$dim] ?? 0.0)));
            $dimensionScores[$dim] = $clamped;
            $weightedSum += $clamped * $weight;
        }

        // proof_weight = implementation_evidence boosted by each evidence_ref (capped at 1.0).
        $evidenceRefs = is_array($opportunity['evidence_refs'] ?? null) ? $opportunity['evidence_refs'] : [];
        $proofWeight  = min(1.0, $dimensionScores['implementation_evidence'] + 0.1 * count($evidenceRefs));

        $penalty = 0.0;
        $triggeredPenalties = [];
        foreach (self::PENALTY_FACTORS as $flag => $factor) {
            if ((bool) ($opportunity[$flag] ?? false)) {
                $penalty += $factor;
                $triggeredPenalties[] = $flag;
            }
        }

        // Penalise high capability_unlock claims that lack implementation evidence or evidence_refs.
        if ($dimensionScores['capability_unlock'] > self::PROOF_REQUIRED_THRESHOLD && $proofWeight < self::WEAK_PROOF_THRESHOLD) {
            $penalty            += self::UNPROVEN_CLAIM_PENALTY;
            $triggeredPenalties[] = 'unproven_high_claim';
        }

        $penalty = min(1.0, $penalty);

        $compoundImpact = [
            'unlocked_capabilities'    => is_array($opportunity['unlocked_capabilities'] ?? null)
                ? array_values($opportunity['unlocked_capabilities'])
                : [],
            'downstream_unblock_count' => max(0, (int) ($opportunity['downstream_unblock_count'] ?? 0)),
            'risk_reduction_signals'   => is_array($opportunity['risk_reduction_signals'] ?? null)
                ? array_values($opportunity['risk_reduction_signals'])
                : [],
            'autonomy_gain_signals'    => is_array($opportunity['autonomy_gain_signals'] ?? null)
                ? array_values($opportunity['autonomy_gain_signals'])
                : [],
        ];

        return [
            'schema'              => self::SCHEMA,
            'label'               => $label,
            'weighted_sum'        => round($weightedSum, 4),
            'penalty'             => round($penalty, 4),
            'final_score'         => round($weightedSum * (1.0 - $penalty), 4),
            'proof_weight'        => round($proofWeight, 4),
            'dimension_scores'    => $dimensionScores,
            'triggered_penalties' => $triggeredPenalties,
            'compound_impact'     => $compoundImpact,
            'why_this_beats_next' => null,
        ];
    }

    /**
     * Score and rank opportunities by final_score descending, then annotate each entry
     * with a deterministic why_this_beats_next explanation comparing it to the item below.
     *
     * @param  list<array<string,mixed>>  $opportunities
     * @return list<array<string,mixed>>
     */
    public function rank(array $opportunities): array
    {
        $scored = array_map(fn (array $opp): array => $this->score($opp), $opportunities);
        usort($scored, static fn (array $a, array $b): int => $b['final_score'] <=> $a['final_score']);
        $scored = array_values($scored);

        foreach ($scored as $i => $item) {
            $scored[$i]['why_this_beats_next'] = isset($scored[$i + 1])
                ? $this->explainWin($item, $scored[$i + 1])
                : 'last_in_ranking';
        }

        return $scored;
    }

    /**
     * Produce a deterministic, pipe-separated explanation of why $winner outranks $next.
     *
     * @param  array<string,mixed>  $winner
     * @param  array<string,mixed>  $next
     */
    private function explainWin(array $winner, array $next): string
    {
        $parts = [];

        $scoreDiff = round((float) $winner['final_score'] - (float) $next['final_score'], 4);
        $parts[]   = 'score_advantage:'.$scoreDiff;

        $penaltyDiff = round((float) $next['penalty'] - (float) $winner['penalty'], 4);
        if ($penaltyDiff > 0.0) {
            $parts[] = 'fewer_penalties:'.$penaltyDiff;
        }

        $wImpact = $winner['compound_impact'] ?? [];
        $nImpact = $next['compound_impact']   ?? [];

        $wUnblock = (int) ($wImpact['downstream_unblock_count'] ?? 0);
        $nUnblock = (int) ($nImpact['downstream_unblock_count'] ?? 0);
        if ($wUnblock > $nUnblock) {
            $parts[] = 'more_downstream_unblocks:'.$wUnblock.'_vs_'.$nUnblock;
        }

        $wCaps = count((array) ($wImpact['unlocked_capabilities'] ?? []));
        $nCaps = count((array) ($nImpact['unlocked_capabilities'] ?? []));
        if ($wCaps > $nCaps) {
            $parts[] = 'more_unlocked_capabilities:'.$wCaps.'_vs_'.$nCaps;
        }

        $wRisk = count((array) ($wImpact['risk_reduction_signals'] ?? []));
        $nRisk = count((array) ($nImpact['risk_reduction_signals'] ?? []));
        if ($wRisk > $nRisk) {
            $parts[] = 'more_risk_reduction_signals:'.$wRisk.'_vs_'.$nRisk;
        }

        $wAuto = count((array) ($wImpact['autonomy_gain_signals'] ?? []));
        $nAuto = count((array) ($nImpact['autonomy_gain_signals'] ?? []));
        if ($wAuto > $nAuto) {
            $parts[] = 'more_autonomy_gain_signals:'.$wAuto.'_vs_'.$nAuto;
        }

        $wProof = (float) ($winner['proof_weight'] ?? 0.0);
        $nProof = (float) ($next['proof_weight']   ?? 0.0);
        if ($wProof > $nProof + 0.05) {
            $parts[] = 'proof_weight:'.round($wProof, 2).'_vs_'.round($nProof, 2);
        }

        return implode('|', $parts);
    }

    /** @return list<array{dimension:string,weight:float}> */
    public function dimensions(): array
    {
        return array_map(
            static fn (string $dim, float $w): array => ['dimension' => $dim, 'weight' => $w],
            array_keys(self::WEIGHTS),
            array_values(self::WEIGHTS),
        );
    }

    /** @return list<array{penalty:string,factor:float}> */
    public function penalties(): array
    {
        return array_map(
            static fn (string $p, float $f): array => ['penalty' => $p, 'factor' => $f],
            array_keys(self::PENALTY_FACTORS),
            array_values(self::PENALTY_FACTORS),
        );
    }
}
