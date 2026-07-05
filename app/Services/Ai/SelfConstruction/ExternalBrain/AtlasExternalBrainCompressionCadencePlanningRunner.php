<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Composes six orphan compression-planning organs into a single cadence+design
 * plan. Decides WHEN to compress (cadence controller), WHICH design path to
 * take (design path selector), WHAT invariants to preserve (invariant
 * synthesizer), AT WHAT evidence cost (evidence budgeter), WITH WHAT learned
 * strategy bias (learning synthesizer), and BENCHMARKS the candidate design
 * (benchmark suite).
 *
 * When the cadence controller decides anything other than create_batch,
 * downstream organs are skipped (the system isn't going to compress this
 * cycle).
 *
 * Pure / deterministic: no I/O, no side effects.
 */
final class AtlasExternalBrainCompressionCadencePlanningRunner
{
    public const SCHEMA = 'atlas.external_brain.compression_cadence_planning_runner.v1';

    public const DECISION_CREATE_BATCH = 'create_batch';

    private AtlasExternalBrainCompressionCadenceController $cadenceController;

    private AtlasExternalBrainCompressionDesignPathSelector $designPathSelector;

    private AtlasExternalBrainCompressionInvariantSynthesizer $invariantSynthesizer;

    private AtlasExternalBrainCompressionEvidenceBudgeter $evidenceBudgeter;

    private AtlasExternalBrainCompressionLearningSynthesizer $learningSynthesizer;

    private AtlasExternalBrainCompressionBenchmarkSuite $benchmarkSuite;

    public function __construct(
        ?AtlasExternalBrainCompressionCadenceController $cadenceController = null,
        ?AtlasExternalBrainCompressionDesignPathSelector $designPathSelector = null,
        ?AtlasExternalBrainCompressionInvariantSynthesizer $invariantSynthesizer = null,
        ?AtlasExternalBrainCompressionEvidenceBudgeter $evidenceBudgeter = null,
        ?AtlasExternalBrainCompressionLearningSynthesizer $learningSynthesizer = null,
        ?AtlasExternalBrainCompressionBenchmarkSuite $benchmarkSuite = null,
    ) {
        $this->cadenceController = $cadenceController ?? new AtlasExternalBrainCompressionCadenceController;
        $this->designPathSelector = $designPathSelector ?? new AtlasExternalBrainCompressionDesignPathSelector;
        $this->invariantSynthesizer = $invariantSynthesizer ?? new AtlasExternalBrainCompressionInvariantSynthesizer;
        $this->evidenceBudgeter = $evidenceBudgeter ?? new AtlasExternalBrainCompressionEvidenceBudgeter;
        $this->learningSynthesizer = $learningSynthesizer ?? new AtlasExternalBrainCompressionLearningSynthesizer;
        $this->benchmarkSuite = $benchmarkSuite ?? new AtlasExternalBrainCompressionBenchmarkSuite;
    }

    /**
     * @param  array<string,mixed>  $input
     *   {
     *     cadence_facts:      array  — passed to cadenceController->decide()
     *     design_facts:       array  — passed to designPathSelector->select()
     *     budget_facts:       array  — passed to evidenceBudgeter->evaluate()
     *     benchmark_facts:    array  — passed to benchmarkSuite->evaluate()
     *     learning_facts:     array  — passed to learningSynthesizer->synthesize()
     *   }
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $cadenceFacts = is_array($input['cadence_facts'] ?? null) ? $input['cadence_facts'] : [];

        $cadence = $this->cadenceController->decide($cadenceFacts);

        // When the cadence controller does NOT authorise a new batch, skip
        // downstream planning — compression is not happening this cycle.
        if ($cadence['decision'] !== self::DECISION_CREATE_BATCH) {
            return [
                'schema'            => self::SCHEMA,
                'cadence'           => $cadence,
                'design_path'       => null,
                'invariants'        => null,
                'evidence_budget'   => null,
                'learning'          => null,
                'benchmark'         => null,
            ];
        }

        $designFacts = is_array($input['design_facts'] ?? null) ? $input['design_facts'] : [];
        $designPath = $this->designPathSelector->select($designFacts);

        $invariants = $this->invariantSynthesizer->synthesize([
            'action' => $designPath['design_path'],
        ]);

        $budgetFacts = is_array($input['budget_facts'] ?? null) ? $input['budget_facts'] : [];
        $evidenceBudget = $this->evidenceBudgeter->evaluate($budgetFacts);

        $learningFacts = is_array($input['learning_facts'] ?? null) ? $input['learning_facts'] : [];
        $learning = $this->learningSynthesizer->synthesize($learningFacts);

        $benchmarkFacts = is_array($input['benchmark_facts'] ?? null) ? $input['benchmark_facts'] : [];
        $benchmark = $this->benchmarkSuite->evaluate($benchmarkFacts);

        return [
            'schema'            => self::SCHEMA,
            'cadence'           => $cadence,
            'design_path'       => $designPath,
            'invariants'        => $invariants,
            'evidence_budget'   => $evidenceBudget,
            'learning'          => $learning,
            'benchmark'         => $benchmark,
        ];
    }
}
