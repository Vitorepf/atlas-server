<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiLearningCandidate;
use App\Models\AiRunOutcome;

class AtlasCompoundingEngineeringIntelligenceService
{
    public const OUTCOME_SCHEMA = AtlasCompoundingOutcomeEvaluator::SCHEMA_VERSION;

    public const LEARNING_SCHEMA = AtlasLearningDistiller::SCHEMA_VERSION;

    public const MEMORY_SCHEMA = AtlasCompoundingMemoryService::SCHEMA_VERSION;

    public const HEURISTIC_SCHEMA = AtlasHeuristicEvolutionService::SCHEMA_VERSION;

    public const RAG_SCHEMA = AtlasRagFeedbackService::SCHEMA_VERSION;

    public const BENCHMARK_SCHEMA = AtlasBenchmarkGeneratorService::SCHEMA_VERSION;

    public const TEMPORAL_SCHEMA = AtlasTemporalCertificationService::SCHEMA_VERSION;

    public const READINESS_SCHEMA = AtlasCompoundingReadinessService::SCHEMA_VERSION;

    public function __construct(
        private readonly AtlasCompoundingRuntimeService $runtime,
        private readonly AtlasCompoundingOutcomeEvaluator $outcomeEvaluator,
        private readonly AtlasLearningDistiller $learningDistiller,
        private readonly AtlasCompoundingMemoryService $memoryService,
        private readonly AtlasRagFeedbackService $ragFeedbackService,
        private readonly AtlasBenchmarkGeneratorService $benchmarkGenerator,
        private readonly AtlasHeuristicEvolutionService $heuristicEvolution,
        private readonly AtlasTemporalCertificationService $temporalCertification,
        private readonly AtlasCompoundingReadinessService $readiness,
    ) {}

    /**
     * @param  array<string,mixed>  $run
     * @return array<string,mixed>
     */
    public function processRun(array $run): array
    {
        return $this->runtime->recordExecution($run);
    }

    /**
     * @param  array<string,mixed>  $run
     */
    public function evaluateOutcome(array $run): AiRunOutcome
    {
        return $this->outcomeEvaluator->evaluate($run);
    }

    /**
     * @param  array<string,mixed>  $run
     */
    public function distillLearning(AiRunOutcome $outcome, array $run = []): AiLearningCandidate
    {
        return $this->learningDistiller->distill($outcome, $run);
    }

    public function promoteMemory(?AiLearningCandidate $candidate): mixed
    {
        return $candidate ? $this->memoryService->promote($candidate) : null;
    }

    /**
     * @param  array<string,mixed>  $rag
     */
    public function recordRagFeedback(AiRunOutcome $outcome, array $rag): mixed
    {
        return $this->ragFeedbackService->record(array_merge(['flow_id' => $outcome->flow_id], $rag));
    }

    /**
     * @param  array<string,mixed>  $run
     */
    public function generateBenchmarkCase(AiRunOutcome $outcome, ?AiLearningCandidate $candidate = null, array $run = []): mixed
    {
        return $this->benchmarkGenerator->fromOutcome($outcome, $run);
    }

    /**
     * @param  array<string,mixed>  $heuristic
     */
    public function proposeHeuristicUpdate(?AiLearningCandidate $candidate, array $heuristic): mixed
    {
        return $heuristic === [] ? null : $this->heuristicEvolution->propose($heuristic);
    }

    public function certifyTemporal(?string $flowId = null): mixed
    {
        return $this->temporalCertification->certify();
    }

    /**
     * @return array<string,mixed>
     */
    public function readiness(): array
    {
        return $this->readiness->inspect();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function approvedMemoriesForRouter(string $flowId): array
    {
        return $this->memoryService->approvedForFlow($flowId);
    }
}
