<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiHeuristicUpdate;
use App\Models\AiLearningCandidate;
use App\Models\AiLearningProposal;
use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Models\AiTemporalCertification;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryCanon;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryRecorder;
use App\Services\Ai\Support\DatabaseTableAvailability;

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
        private readonly AtlasLearningProposalService $learningProposalService,
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

        $proposals = [];
        $ragFeedback = $this->recordRagFeedback($outcome, $candidate, $input, $proposals);
        $proposals = array_merge($proposals, $this->maybeProposeFromRagFeedback($outcome, $candidate, $ragFeedback));

        if ($ragFeedback !== null && $proposals !== []) {
            $primary = $proposals[0];
            if ($ragFeedback->learning_proposal_id === null) {
                $ragFeedback->forceFill(['learning_proposal_id' => $primary->id])->save();
            }
        }

        $benchmark = $this->benchmarkGenerator->fromOutcome(
            $outcome,
            is_array($input['benchmark_case'] ?? null) ? $input['benchmark_case'] : [],
        );

        $heuristicResult = $this->maybeRecordHeuristic($outcome, $candidate, $input);
        $proposals = array_merge($proposals, $heuristicResult['proposals']);
        $heuristicUpdate = $heuristicResult['heuristic_update'];

        $explicitProposal = $this->maybeProposeFromInput($outcome, $candidate, $ragFeedback, $input);
        if ($explicitProposal !== null) {
            $proposals[] = $explicitProposal;
        }

        $certification = $this->temporalCertification->certify();

        $this->emitRuntimeTelemetry($outcome, $ragFeedback, $certification, $proposals, $input);

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
                'outcome_status' => $ragFeedback->outcome_status,
                'failure_reason' => $ragFeedback->failure_reason,
                'next_retrieval_hint' => $ragFeedback->next_retrieval_hint,
                'memory_candidate_id' => $ragFeedback->memory_candidate_id,
                'learning_proposal_id' => $ragFeedback->learning_proposal_id,
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
            'learning_proposals' => array_map(fn (AiLearningProposal $proposal): array => [
                'id' => $proposal->id,
                'kind' => $proposal->kind,
                'status' => $proposal->status,
                'summary' => $proposal->summary,
                'proposal_hash' => $proposal->proposal_hash,
                'requires_human_review' => $proposal->requires_human_review,
            ], $proposals),
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

    /**
     * @param  array<string,mixed>  $input
     * @param  list<AiLearningProposal>  $proposals
     */
    private function recordRagFeedback(
        AiRunOutcome $outcome,
        AiLearningCandidate $candidate,
        array $input,
        array &$proposals,
    ): ?AiRagFeedbackEvent {
        if (! is_array($input['rag_feedback'] ?? null)) {
            return null;
        }

        $rag = $input['rag_feedback'];
        $rag['flow_id'] = $rag['flow_id'] ?? $outcome->flow_id;
        $rag['outcome_status'] = $rag['outcome_status'] ?? $outcome->outcome_status;
        $rag['failure_reason'] = $rag['failure_reason'] ?? $this->deriveFailureReason($outcome, $input);
        $rag['memory_candidate_id'] = $rag['memory_candidate_id'] ?? $candidate->id;
        $rag['run_outcome_id'] = $rag['run_outcome_id'] ?? $outcome->id;

        return $this->ragFeedbackService->record($rag);
    }

    /**
     * Auto-propose a retrieval_hint or failure_pattern proposal when the RAG
     * feedback signals a gap. Always proposal-only — never applied.
     *
     * @return list<AiLearningProposal>
     */
    private function maybeProposeFromRagFeedback(
        AiRunOutcome $outcome,
        AiLearningCandidate $candidate,
        ?AiRagFeedbackEvent $ragFeedback,
    ): array {
        if ($ragFeedback === null) {
            return [];
        }

        $missed = is_array($ragFeedback->missed_required_sources) ? $ragFeedback->missed_required_sources : [];
        $noise = (int) ($ragFeedback->noise_sources ?? 0);
        $sufficiency = (int) ($ragFeedback->context_sufficiency ?? 0);
        $needsProposal = $missed !== []
            || $outcome->outcome_status !== 'passed'
            || ($sufficiency > 0 && $sufficiency < 60)
            || $noise >= 3;

        if (! $needsProposal) {
            return [];
        }

        $kind = $missed !== [] ? 'retrieval_hint' : ($outcome->outcome_status !== 'passed' ? 'failure_pattern' : 'retrieval_hint');
        $evidenceRefs = array_values(array_unique(array_merge(
            ['outcome:'.$outcome->outcome_hash, 'rag_feedback:'.$ragFeedback->feedback_hash],
            array_filter([$candidate->receipt_hash !== null ? 'learning_candidate:'.$candidate->receipt_hash : null]),
        )));

        return [
            $this->learningProposalService->propose([
                'kind' => $kind,
                'scope' => $candidate->scope ?? 'global',
                'flow_id' => $outcome->flow_id,
                'summary' => $this->summaryForRagGap($outcome, $missed, $noise, $sufficiency),
                'current_state' => [
                    'context_sufficiency' => $sufficiency,
                    'noise_sources' => $noise,
                    'missed_required_sources' => $missed,
                ],
                'proposed_state' => [
                    'should_repromote_sources' => $missed,
                    'should_demote_noise_count' => $noise,
                    'target_context_sufficiency_min' => 70,
                ],
                'evidence_refs' => $evidenceRefs,
                'run_outcome_id' => $outcome->id,
                'learning_candidate_id' => $candidate->id,
                'rag_feedback_id' => $ragFeedback->id,
                'payload' => [
                    'next_retrieval_hint' => $ragFeedback->next_retrieval_hint,
                    'outcome_status' => $outcome->outcome_status,
                ],
            ]),
        ];
    }

    /**
     * Heuristic updates touching policy/routing/gate/benchmark may never be
     * auto-applied from the runtime path. They are forced into status=proposed
     * and a parallel AiLearningProposal is created.
     *
     * @param  array<string,mixed>  $input
     * @return array{heuristic_update: ?AiHeuristicUpdate, proposals: list<AiLearningProposal>}
     */
    private function maybeRecordHeuristic(
        AiRunOutcome $outcome,
        AiLearningCandidate $candidate,
        array $input,
    ): array {
        if (! is_array($input['heuristic_update'] ?? null)) {
            return ['heuristic_update' => null, 'proposals' => []];
        }

        $payload = $input['heuristic_update'];
        $key = isset($payload['heuristic_key']) && is_string($payload['heuristic_key']) ? $payload['heuristic_key'] : '';
        $criticalKind = AtlasLearningProposalService::kindForHeuristicKey($key);

        // Default-deny (sweep O-1): o caminho de RUNTIME nunca auto-aplica heurística —
        // a classificação "crítica" era por prefixo controlado pelo caller, então
        // apply=true em qualquer key fora dos prefixos gravava status='applied' sem
        // revisão (incl. eval_gate.*, que a taxonomia crítica do classificador lista).
        $payload['apply'] = false;

        $proposals = [];
        if ($criticalKind !== null) {
            $proposals[] = $this->learningProposalService->propose([
                'kind' => $criticalKind,
                'scope' => 'atlas-server',
                'flow_id' => $payload['flow_id'] ?? $outcome->flow_id,
                'summary' => 'Critical heuristic update for `'.$key.'` requires human review before apply.',
                'current_state' => is_array($payload['before_state'] ?? null) ? $payload['before_state'] : [],
                'proposed_state' => is_array($payload['after_state'] ?? null) ? $payload['after_state'] : [],
                'evidence_refs' => is_array($payload['evidence_refs'] ?? null) && $payload['evidence_refs'] !== []
                    ? $payload['evidence_refs']
                    : ['outcome:'.$outcome->outcome_hash],
                'run_outcome_id' => $outcome->id,
                'learning_candidate_id' => $candidate->id,
                'payload' => [
                    'heuristic_key' => $key,
                    'rollback_plan' => $payload['rollback_plan'] ?? null,
                    'test_refs' => $payload['test_refs'] ?? null,
                ],
            ]);
        }

        return [
            'heuristic_update' => $this->heuristicEvolution->propose($payload),
            'proposals' => $proposals,
        ];
    }

    /**
     * Allow callers to supply an explicit `learning_proposal` payload that is
     * routed through the proposal service. Auto-apply is rejected by the
     * service, so any caller asking for it gets a thrown error before this
     * point.
     *
     * @param  array<string,mixed>  $input
     */
    private function maybeProposeFromInput(
        AiRunOutcome $outcome,
        AiLearningCandidate $candidate,
        ?AiRagFeedbackEvent $ragFeedback,
        array $input,
    ): ?AiLearningProposal {
        if (! is_array($input['learning_proposal'] ?? null) || $input['learning_proposal'] === []) {
            return null;
        }

        $payload = $input['learning_proposal'];
        $payload['run_outcome_id'] = $payload['run_outcome_id'] ?? $outcome->id;
        $payload['learning_candidate_id'] = $payload['learning_candidate_id'] ?? $candidate->id;
        $payload['rag_feedback_id'] = $payload['rag_feedback_id'] ?? ($ragFeedback?->id);
        if (! isset($payload['evidence_refs']) || ! is_array($payload['evidence_refs']) || $payload['evidence_refs'] === []) {
            $payload['evidence_refs'] = ['outcome:'.$outcome->outcome_hash];
        }

        return $this->learningProposalService->propose($payload);
    }

    /**
     * @param  list<int|string|array<string,mixed>>  $missed
     */
    private function summaryForRagGap(AiRunOutcome $outcome, array $missed, int $noise, int $sufficiency): string
    {
        $parts = [];
        if ($missed !== []) {
            $parts[] = count($missed).' missed required sources';
        }
        if ($noise >= 3) {
            $parts[] = $noise.' noisy sources';
        }
        if ($sufficiency > 0 && $sufficiency < 60) {
            $parts[] = 'low context sufficiency ('.$sufficiency.')';
        }
        if ($outcome->outcome_status !== 'passed') {
            $parts[] = 'outcome '.$outcome->outcome_status;
        }
        $detail = $parts === [] ? 'rag feedback signal' : implode(', ', $parts);

        return 'Flow '.$outcome->flow_id.': '.$detail.' — propose retrieval/policy review (no auto-apply).';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function deriveFailureReason(AiRunOutcome $outcome, array $input): ?string
    {
        if ($outcome->outcome_status === 'passed') {
            return null;
        }
        if (isset($input['failure_reason']) && is_string($input['failure_reason']) && trim($input['failure_reason']) !== '') {
            return trim($input['failure_reason']);
        }
        if (isset($input['missed_signals']) && is_array($input['missed_signals']) && $input['missed_signals'] !== []) {
            return (string) $input['missed_signals'][0];
        }

        return 'unspecified_failure:'.$outcome->outcome_status;
    }

    /**
     * Used by readiness/diagnostic services that may run before migration.
     */
    public static function learningProposalsTableReady(): bool
    {
        return DatabaseTableAvailability::has('ai_learning_proposals');
    }

    /**
     * Emit a Programming Runtime telemetry event summarising this execution.
     * Best-effort: any failure (missing table, container miss, schema mismatch)
     * is silently swallowed — telemetry must NEVER block the runtime path and
     * must NEVER carry secrets.
     *
     * @param  list<AiLearningProposal>  $proposals
     * @param  array<string,mixed>  $input
     */
    private function emitRuntimeTelemetry(
        AiRunOutcome $outcome,
        ?AiRagFeedbackEvent $ragFeedback,
        AiTemporalCertification $certification,
        array $proposals,
        array $input,
    ): void {
        try {
            $recorder = app(ProgrammingRuntimeTelemetryRecorder::class);
        } catch (\Throwable) {
            return;
        }

        try {
            $blockers = is_array($certification->blockers) ? $certification->blockers : [];
            $ragGateStatus = $this->deriveRagGateStatus($ragFeedback);

            $recorder->record([
                'event_name' => 'runtime_record_completed',
                'event_phase' => 'compounding_runtime',
                'flow' => $outcome->flow_id,
                'selected_core' => $this->deriveSelectedCore($outcome->flow_id),
                'run_id' => $outcome->run_id,
                'mission_id' => $input['mission_id'] ?? null,
                'work_order_id' => $input['work_order_id'] ?? null,
                'obra_id' => $input['obra_id'] ?? null,
                'route_decision_id' => $input['route_decision_id'] ?? null,
                'rag_gate_status' => $ragGateStatus,
                'context_sufficiency' => $ragFeedback?->context_sufficiency,
                'execution_status' => $outcome->outcome_status,
                'test_status' => $this->normaliseTestStatus($input['test_status'] ?? null),
                'repair_attempt_count' => $this->resolveRepairAttempts($input),
                'evidence_completeness' => $outcome->evidence_quality,
                'certification_status' => $certification->status,
                'blocker_count' => count($blockers),
                'duration_ms' => $input['duration_ms'] ?? null,
                // L3-10: forward the real cost signals so the recorder can
                // MEASURE the cost (provider-reported tokens, or runtime for
                // local providers). If a measured cost is already known we keep
                // it; otherwise the recorder derives one from these — never faked.
                'cost_estimate_usd' => $input['cost_estimate_usd'] ?? null,
                'provider' => $input['provider'] ?? null,
                'model' => $input['model'] ?? null,
                'tokens_in' => $input['tokens_in'] ?? $input['input_tokens'] ?? $input['prompt_tokens'] ?? null,
                'tokens_out' => $input['tokens_out'] ?? $input['output_tokens'] ?? $input['completion_tokens'] ?? null,
                'total_tokens' => $input['total_tokens'] ?? null,
                'metadata' => [
                    'outcome_hash' => $outcome->outcome_hash,
                    'rag_feedback_hash' => $ragFeedback?->feedback_hash,
                    'certification_hash' => $certification->certification_hash,
                    'learning_proposals_count' => count($proposals),
                    'human_override' => (bool) $outcome->human_override,
                ],
            ]);
        } catch (\Throwable) {
            // Telemetry is best-effort and must not interfere with the runtime.
        }
    }

    private function deriveSelectedCore(?string $flowId): ?string
    {
        if (! is_string($flowId) || $flowId === '') {
            return null;
        }
        if ($flowId === 'atlas_forge') {
            return ProgrammingRuntimeTelemetryCanon::SELECTED_CORE_FORGE;
        }
        if (in_array($flowId, ['atlas_dev', 'atlas_debug', 'atlas_review', 'atlas_research'], true)) {
            return ProgrammingRuntimeTelemetryCanon::SELECTED_CORE_DEV;
        }
        if ($flowId === 'atlas_dev_to_forge') {
            return ProgrammingRuntimeTelemetryCanon::SELECTED_CORE_DEV_TO_FORGE;
        }

        return null;
    }

    private function deriveRagGateStatus(?AiRagFeedbackEvent $ragFeedback): ?string
    {
        if ($ragFeedback === null) {
            return 'skipped';
        }
        $missed = is_array($ragFeedback->missed_required_sources) ? $ragFeedback->missed_required_sources : [];
        if ($missed !== []) {
            return 'failed_closed';
        }
        $sufficiency = (int) ($ragFeedback->context_sufficiency ?? 0);
        if ($sufficiency >= 70) {
            return 'passed';
        }

        return 'degraded';
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function resolveRepairAttempts(array $input): ?int
    {
        if (isset($input['repair_attempt_count']) && is_numeric($input['repair_attempt_count'])) {
            return max(0, (int) $input['repair_attempt_count']);
        }
        if (isset($input['rag_feedback']) && is_array($input['rag_feedback'])) {
            $rag = $input['rag_feedback'];
            if (isset($rag['repair_attempt_count']) && is_numeric($rag['repair_attempt_count'])) {
                return max(0, (int) $rag['repair_attempt_count']);
            }
        }

        return null;
    }

    private function normaliseTestStatus(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = strtolower(trim($value));
        if (in_array($value, ProgrammingRuntimeTelemetryCanon::TEST_STATUSES, true)) {
            return $value;
        }

        return null;
    }
}
