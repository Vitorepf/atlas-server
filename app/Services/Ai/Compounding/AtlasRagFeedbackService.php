<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiRagFeedbackEvent;
use InvalidArgumentException;

class AtlasRagFeedbackService
{
    public const SCHEMA_VERSION = 'atlas.ai.rag.feedback.v1';

    /**
     * @param  array<string,mixed>  $input
     */
    public function record(array $input): AiRagFeedbackEvent
    {
        $receiptId = $this->string($input['retrieval_receipt_id'] ?? null);
        $flowId = $this->string($input['flow_id'] ?? null);
        if ($receiptId === null || $flowId === null) {
            throw new InvalidArgumentException('rag_feedback_requires_receipt_and_flow');
        }

        $missed = $this->array($input['missed_required_sources'] ?? []);
        $noise = $this->integer($input['noise_sources'] ?? null);
        $sufficiency = $this->score($input['context_sufficiency'] ?? null);
        $outcomeStatus = $this->string($input['outcome_status'] ?? null);
        $failureReason = $this->string($input['failure_reason'] ?? null);
        $nextHint = is_array($input['next_retrieval_hint'] ?? null) && $input['next_retrieval_hint'] !== []
            ? $input['next_retrieval_hint']
            : $this->computeNextRetrievalHint($missed, $noise, $sufficiency, $outcomeStatus, $failureReason);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'retrieval_receipt_id' => $receiptId,
            'flow_id' => $flowId,
            'query_plan_hash' => $this->string($input['query_plan_hash'] ?? null),
            'included_sources' => $this->integer($input['included_sources'] ?? null),
            'used_sources' => $this->integer($input['used_sources'] ?? null),
            'noise_sources' => $noise,
            'missed_required_sources' => $missed,
            'context_sufficiency' => $sufficiency,
            'post_execution_utility' => $this->score($input['post_execution_utility'] ?? null),
            'source_utility' => $this->array($input['source_utility'] ?? []),
            'outcome_status' => $outcomeStatus,
            'failure_reason' => $failureReason,
            'next_retrieval_hint' => $nextHint,
            'memory_candidate_id' => $this->string($input['memory_candidate_id'] ?? null),
            'learning_proposal_id' => $this->string($input['learning_proposal_id'] ?? null),
            'run_outcome_id' => $this->string($input['run_outcome_id'] ?? null),
            'payload' => $input,
        ];
        $payload['feedback_hash'] = CompoundingHash::make([
            'schema' => self::SCHEMA_VERSION,
            'retrieval_receipt_id' => $receiptId,
            'flow_id' => $flowId,
            'source_utility' => $payload['source_utility'],
            'missed_required_sources' => $payload['missed_required_sources'],
            'outcome_status' => $payload['outcome_status'],
            'failure_reason' => $payload['failure_reason'],
        ]);

        return AiRagFeedbackEvent::query()->firstOrCreate(
            ['feedback_hash' => $payload['feedback_hash']],
            $payload,
        );
    }

    /**
     * Compute a deterministic, audit-friendly hint for future retrieval based
     * on noise, missing sources and the outcome of the current run. Returns
     * `null` when there is no actionable signal.
     *
     * @param  list<int|string|array<string,mixed>>  $missed
     * @return array<string,mixed>|null
     */
    private function computeNextRetrievalHint(
        array $missed,
        int $noise,
        int $sufficiency,
        ?string $outcomeStatus,
        ?string $failureReason,
    ): ?array {
        $reasons = [];
        if ($missed !== []) {
            $reasons[] = 'missed_required_sources';
        }
        if ($noise >= 2) {
            $reasons[] = 'excess_noise';
        }
        if ($sufficiency > 0 && $sufficiency < 60) {
            $reasons[] = 'low_context_sufficiency';
        }
        if ($outcomeStatus !== null && $outcomeStatus !== 'passed') {
            $reasons[] = 'non_passing_outcome';
        }
        if ($failureReason !== null) {
            $reasons[] = 'failure_reason_present';
        }

        if ($reasons === []) {
            return null;
        }

        return [
            'reasons' => array_values(array_unique($reasons)),
            'should_repromote_sources' => $missed,
            'should_demote_count' => $noise,
            'min_context_sufficiency_target' => 70,
            'advisory' => true,
            'auto_apply' => false,
        ];
    }

    private function integer(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function score(mixed $value): int
    {
        return max(0, min(100, (int) round((float) $value)));
    }

    /**
     * @return array<int|string,mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
