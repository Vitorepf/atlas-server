<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Governs frontier harvesting by composing four organs:
 *   1. YieldModel::model() — how much yield to expect from a research source
 *   2. DistillationPatternLibrary::distill() — extract reusable patterns
 *   3. LiftBenchmarkHarness::measure() — real capability lift measurement
 *   4. ExhaustionEscalationLadder::evaluate() — escalate exhausted scopes
 *
 * Pure/read-only: never dispatches, never mutates state.
 */
final class AtlasExternalBrainFrontierHarvestGovernanceRunner
{
    public const SCHEMA = 'atlas.external_brain.frontier_harvest_governance_runner.v1';

    public function __construct(
        private readonly AtlasExternalBrainFrontierHarvestYieldModel $yieldModel = new AtlasExternalBrainFrontierHarvestYieldModel,
        private readonly AtlasExternalBrainFrontierDistillationPatternLibrary $distillationLibrary = new AtlasExternalBrainFrontierDistillationPatternLibrary,
        private readonly AtlasExternalBrainFrontierLiftBenchmarkHarness $liftBenchmark = new AtlasExternalBrainFrontierLiftBenchmarkHarness,
        private readonly AtlasExternalBrainFrontierExhaustionEscalationLadder $escalationLadder = new AtlasExternalBrainFrontierExhaustionEscalationLadder,
    ) {}

    public function govern(array $yieldInput, array $distillInput, array $benchmarkInput, array $escalationFacts): array
    {
        $yield = $this->yieldModel->model($yieldInput);
        $distillation = $this->distillationLibrary->distill($distillInput);
        $benchmark = $this->liftBenchmark->measure($benchmarkInput);
        $escalation = $this->escalationLadder->evaluate($escalationFacts);

        // Extract yield score from the first result (or 0 if no frontiers).
        $yieldResults = (array) ($yield['results'] ?? []);
        $yieldScore = 0.0;
        $yieldDecision = 'stop_frontier_harvest';
        if ($yieldResults !== []) {
            $firstResult = $yieldResults[0] ?? [];
            $yieldScore = (float) ($firstResult['expected_yield'] ?? 0.0);
            $yieldDecision = (string) ($firstResult['decision'] ?? 'stop_frontier_harvest');
        }

        $isExhausted = (bool) ($escalation['exhausted'] ?? false);
        $escalationAction = (string) ($escalation['next_action'] ?? 'continue');

        $blockers = [];
        if ($isExhausted) {
            $blockers[] = 'scope_exhausted:'.$escalationAction;
        }

        $shouldHarvest = ! $isExhausted && $yieldScore > 0.2 && $yieldDecision !== 'stop_frontier_harvest';
        $action = match (true) {
            $isExhausted => $escalationAction,
            $yieldScore <= 0.2 || $yieldDecision === 'stop_frontier_harvest' => 'deprioritize_low_yield',
            default => 'harvest_and_distill',
        };

        return [
            'schema' => self::SCHEMA,
            'action' => $action,
            'should_harvest' => $shouldHarvest,
            'yield' => $yield,
            'distillation' => $distillation,
            'benchmark' => $benchmark,
            'escalation' => $escalation,
            'blockers' => $blockers,
        ];
    }
}
