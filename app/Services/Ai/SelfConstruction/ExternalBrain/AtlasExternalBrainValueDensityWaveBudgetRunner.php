<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Composes six orphan value-density and wave-budget organs into a single
 * priority-optimization plan. Monitors value decay, ranks candidates by
 * value density, samples proof quality, scoreboards wave completion, plans
 * the wave proof budget, and budgets wave risk — so priority ordering
 * maximizes value-density within a proof+risk budget instead of raw
 * arrival order.
 *
 * Pure / deterministic: no I/O, no side effects.
 */
final class AtlasExternalBrainValueDensityWaveBudgetRunner
{
    public const SCHEMA = 'atlas.external_brain.value_density_wave_budget_runner.v1';

    private AtlasExternalBrainValueDecayMonitor $decayMonitor;

    private AtlasExternalBrainValueDensityQueueOptimizer $densityOptimizer;

    private AtlasExternalBrainValueProofSampler $proofSampler;

    private AtlasExternalBrainWaveCompletionScoreboard $scoreboard;

    private AtlasExternalBrainWaveProofBudgetPlanner $proofBudgetPlanner;

    private AtlasExternalBrainWaveRiskBudgeter $riskBudgeter;

    public function __construct(
        ?AtlasExternalBrainValueDecayMonitor $decayMonitor = null,
        ?AtlasExternalBrainValueDensityQueueOptimizer $densityOptimizer = null,
        ?AtlasExternalBrainValueProofSampler $proofSampler = null,
        ?AtlasExternalBrainWaveCompletionScoreboard $scoreboard = null,
        ?AtlasExternalBrainWaveProofBudgetPlanner $proofBudgetPlanner = null,
        ?AtlasExternalBrainWaveRiskBudgeter $riskBudgeter = null,
    ) {
        $this->decayMonitor = $decayMonitor ?? new AtlasExternalBrainValueDecayMonitor;
        $this->densityOptimizer = $densityOptimizer ?? new AtlasExternalBrainValueDensityQueueOptimizer;
        $this->proofSampler = $proofSampler ?? new AtlasExternalBrainValueProofSampler;
        $this->scoreboard = $scoreboard ?? new AtlasExternalBrainWaveCompletionScoreboard;
        $this->proofBudgetPlanner = $proofBudgetPlanner ?? new AtlasExternalBrainWaveProofBudgetPlanner;
        $this->riskBudgeter = $riskBudgeter ?? new AtlasExternalBrainWaveRiskBudgeter;
    }

    /**
     * @param  array<string,mixed>  $input
     *   {
     *     decay_facts:        array  — passed to decayMonitor->monitor()
     *     ranking_input:      array  — passed to densityOptimizer->rankCandidates()
     *     proof_samples:      array  — passed to proofSampler->sampleBatch() (list of records)
     *     scoreboard_facts:   array  — passed to scoreboard->score()
     *     budget_facts:       array  — passed to proofBudgetPlanner->plan()
     *     risk_wave:          array  — passed to riskBudgeter->evaluate()
     *   }
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $decayFacts = is_array($input['decay_facts'] ?? null) ? $input['decay_facts'] : [];
        $decay = $this->decayMonitor->monitor($decayFacts);

        $rankingInput = is_array($input['ranking_input'] ?? null) ? $input['ranking_input'] : [];
        $ranking = $this->densityOptimizer->rankCandidates($rankingInput);

        $proofSamples = is_array($input['proof_samples'] ?? null) ? $input['proof_samples'] : [];
        $proofSample = $this->proofSampler->sampleBatch($proofSamples);

        $scoreboardFacts = is_array($input['scoreboard_facts'] ?? null) ? $input['scoreboard_facts'] : [];
        $scoreboard = $this->scoreboard->score($scoreboardFacts);

        $budgetFacts = is_array($input['budget_facts'] ?? null) ? $input['budget_facts'] : [];
        $proofBudget = $this->proofBudgetPlanner->plan($budgetFacts);

        $riskWave = is_array($input['risk_wave'] ?? null) ? $input['risk_wave'] : [];
        $riskBudget = $this->riskBudgeter->evaluate($riskWave);

        return [
            'schema'         => self::SCHEMA,
            'decay'          => $decay,
            'ranking'        => $ranking,
            'proof_sample'   => $proofSample,
            'scoreboard'     => $scoreboard,
            'proof_budget'   => $proofBudget,
            'risk_budget'    => $riskBudget,
        ];
    }
}
