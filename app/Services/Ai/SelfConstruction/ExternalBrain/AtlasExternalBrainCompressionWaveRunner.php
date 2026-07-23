<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Orchestrates a compression wave through the full analysis pipeline:
 *   1. Opportunity mining — rank candidates by leverage
 *   2. Mutation risk evaluation — guard requirements per action type
 *   3. Change budgeting — size the wave by risk, proof, rollback, capacity
 *   4. North-star scorecard — strategic fitness signal
 *   5. Regression guard — invariant floors before vs. after
 *   6. Control plane — per-candidate go/hold, safe-waves, blocked-deletions
 *
 * Pure / read-only: never dispatches, never mutates state.
 */
final class AtlasExternalBrainCompressionWaveRunner
{
    public const SCHEMA = 'atlas.external_brain.compression_wave_runner.v1';

    public const VERDICT_GO        = 'go';
    public const VERDICT_HOLD      = 'hold';
    public const VERDICT_REGRESSED = 'regressed';

    public function __construct(
        private readonly AtlasExternalBrainCompressionOpportunityMiner $opportunityMiner = new AtlasExternalBrainCompressionOpportunityMiner,
        private readonly AtlasExternalBrainCompressionMutationRiskModel $mutationRiskModel = new AtlasExternalBrainCompressionMutationRiskModel,
        private readonly AtlasExternalBrainCompressionChangeBudget $changeBudget = new AtlasExternalBrainCompressionChangeBudget,
        private readonly AtlasExternalBrainCompressionNorthStarScorecard $northStarScorecard = new AtlasExternalBrainCompressionNorthStarScorecard,
        private readonly AtlasExternalBrainCompressionRegressionGuard $regressionGuard = new AtlasExternalBrainCompressionRegressionGuard,
        private readonly AtlasExternalBrainCompressionControlPlane $controlPlane = new AtlasExternalBrainCompressionControlPlane,
    ) {}

    /**
     * Run a full compression wave through the analysis pipeline.
     *
     * Input contract (all keys optional; missing keys produce safe defaults):
     *   wave_id?:            string          — explicit id; auto-generated if absent
     *   candidates?:         list<array>     — candidate facts for OpportunityMiner
     *   actions?:            list<array>     — action facts for MutationRiskModel
     *   budget_facts?:       array           — {risk_level, proof_coverage, has_rollback_path, worker_capacity}
     *   scorecard_input?:    array           — {fitness_gain, autonomy_gain, deletion_gain, proof_health, regression_rate, blockers}
     *   before_floors?:      array           — {test_count, docs_sync, capability_coverage, worker_yield}
     *   after_floors?:       array           — {test_count, docs_sync, capability_coverage, worker_yield}
     *   control_candidates?: list<array>     — candidates for ControlPlane (defaults to mined opportunities)
     *
     * @param  array<string,mixed>  $facts
     * @return array{
     *     schema:          string,
     *     wave_id:         string,
     *     opportunities:   array,
     *     risk:            array,
     *     budget:          array,
     *     scorecard:       array,
     *     regression_guard: array,
     *     control_plane:   array,
     *     verdict:         string,
     * }
     */
    public function run(array $facts): array
    {
        $waveId = (string) ($facts['wave_id'] ?? '');
        if ($waveId === '') {
            $waveId = 'wave_'.bin2hex(random_bytes(4));
        }

        // 1. Opportunity mining
        $opportunities = $this->opportunityMiner->mine([
            'candidates' => $facts['candidates'] ?? [],
        ]);

        // 2. Mutation risk evaluation
        $risk = $this->mutationRiskModel->evaluate([
            'actions' => $facts['actions'] ?? [],
        ]);

        // 3. Change budgeting
        $budget = $this->changeBudget->budget([
            'facts' => $facts['budget_facts'] ?? [],
        ]);

        // 4. North-star scorecard
        $scorecard = $this->northStarScorecard->score(
            $facts['scorecard_input'] ?? []
        );

        // 5. Regression guard (invariant floors)
        $regressionGuardResult = $this->regressionGuard->evaluate([
            'before' => $facts['before_floors'] ?? [],
            'after'  => $facts['after_floors'] ?? [],
        ]);

        // If regression guard failed, verdict is 'regressed' — short-circuit.
        if ($regressionGuardResult['decision'] === AtlasExternalBrainCompressionRegressionGuard::DECISION_HOLD) {
            return [
                'schema'           => self::SCHEMA,
                'wave_id'          => $waveId,
                'opportunities'    => $opportunities,
                'risk'             => $risk,
                'budget'           => $budget,
                'scorecard'        => $scorecard,
                'regression_guard' => $regressionGuardResult,
                'control_plane'    => null,
                'verdict'          => self::VERDICT_REGRESSED,
            ];
        }

        // 6. Control plane — use explicitly provided control candidates, or fall back
        //    to mined opportunities.
        $controlCandidates = $facts['control_candidates']
            ?? $opportunities['candidates']
            ?? [];

        $controlPlaneResult = $this->controlPlane->evaluate([
            'candidates' => $controlCandidates,
        ]);

        // Determine verdict: go if control plane says go or partial_go,
        // hold if control plane says hold (no safe waves).
        $readiness = $controlPlaneResult['readiness'] ?? AtlasExternalBrainCompressionControlPlane::DECISION_HOLD;
        $verdict = match ($readiness) {
            AtlasExternalBrainCompressionControlPlane::DECISION_GO, 'partial_go' => self::VERDICT_GO,
            default => self::VERDICT_HOLD,
        };

        return [
            'schema'           => self::SCHEMA,
            'wave_id'          => $waveId,
            'opportunities'    => $opportunities,
            'risk'             => $risk,
            'budget'           => $budget,
            'scorecard'        => $scorecard,
            'regression_guard' => $regressionGuardResult,
            'control_plane'    => $controlPlaneResult,
            'verdict'          => $verdict,
        ];
    }
}
