<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiRunOutcome;

class AtlasCompoundingRuntimeService
{
    public function __construct(
        private readonly AtlasCompoundingOutcomeEvaluator $outcomeEvaluator,
        private readonly AtlasLearningDistiller $learningDistiller,
        private readonly AtlasCompoundingMemoryService $memoryService,
        private readonly AtlasRagFeedbackService $ragFeedbackService,
        private readonly AtlasBenchmarkGeneratorService $benchmarkGenerator,
        private readonly AtlasHeuristicEvolutionService $heuristicEvolution,
        private readonly AtlasTemporalCertificationService $temporalCertification,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordExecution(array $input): array
    {
        $outcome = $this->outcomeEvaluator->evaluate($input);
        $candidate = $this->learningDistiller->distill($outcome, is_array($input['learning_signal'] ?? null) ? $input['learning_signal'] : $input);
        $memory = null;
        $memoryBlockedReason = null;

        if ($candidate->promotion_allowed) {
            try {
                $memory = $this->memoryService->promote($candidate);
            } catch (\Throwable $exception) {
                $memoryBlockedReason = $exception->getMessage();
            }
        } else {
            $memoryBlockedReason = 'learning_candidate_not_promotable';
        }

        $ragFeedback = null;
        if (is_array($input['rag_feedback'] ?? null)) {
            $ragFeedback = $this->ragFeedbackService->record(array_merge(
                ['flow_id' => $outcome->flow_id],
                $input['rag_feedback'],
            ));
        }

        $benchmark = $this->benchmarkGenerator->fromOutcome(
            $outcome,
            is_array($input['benchmark_case'] ?? null) ? $input['benchmark_case'] : [],
        );

        $heuristicUpdate = null;
        if (is_array($input['heuristic_update'] ?? null)) {
            $heuristicUpdate = $this->heuristicEvolution->propose($input['heuristic_update']);
        }

        $certification = $this->temporalCertification->certify();

        return [
            'schema_version' => 'atlas.ai.compounding.runtime_record.v1',
            'status' => 'recorded',
            'outcome' => $this->outcomePayload($outcome),
            'learning_candidate' => [
                'id' => $candidate->id,
                'status' => $candidate->status,
                'decision' => $candidate->decision,
                'promotion_allowed' => $candidate->promotion_allowed,
                'receipt_hash' => $candidate->receipt_hash,
            ],
            'compounding_memory' => $memory ? [
                'id' => $memory->id,
                'memory_hash' => $memory->memory_hash,
                'status' => $memory->status,
            ] : null,
            'memory_blocked_reason' => $memoryBlockedReason,
            'rag_feedback' => $ragFeedback ? [
                'id' => $ragFeedback->id,
                'feedback_hash' => $ragFeedback->feedback_hash,
                'context_sufficiency' => $ragFeedback->context_sufficiency,
            ] : null,
            'benchmark_case' => $benchmark ? [
                'id' => $benchmark->id,
                'case_id' => $benchmark->case_id,
                'case_hash' => $benchmark->case_hash,
            ] : null,
            'heuristic_update' => $heuristicUpdate ? [
                'id' => $heuristicUpdate->id,
                'status' => $heuristicUpdate->status,
                'receipt_hash' => $heuristicUpdate->receipt_hash,
            ] : null,
            'temporal_certification' => [
                'id' => $certification->id,
                'status' => $certification->status,
                'certification_hash' => $certification->certification_hash,
                'blockers' => $certification->blockers,
            ],
            'writes' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function outcomePayload(AiRunOutcome $outcome): array
    {
        return [
            'id' => $outcome->id,
            'schema_version' => $outcome->schema_version,
            'run_id' => $outcome->run_id,
            'flow_id' => $outcome->flow_id,
            'outcome_status' => $outcome->outcome_status,
            'flow_quality' => $outcome->flow_quality,
            'retrieval_quality' => $outcome->retrieval_quality,
            'execution_quality' => $outcome->execution_quality,
            'evidence_quality' => $outcome->evidence_quality,
            'learning_required' => $outcome->learning_required,
            'outcome_hash' => $outcome->outcome_hash,
        ];
    }
}
