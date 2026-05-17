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

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'retrieval_receipt_id' => $receiptId,
            'flow_id' => $flowId,
            'query_plan_hash' => $this->string($input['query_plan_hash'] ?? null),
            'included_sources' => $this->integer($input['included_sources'] ?? null),
            'used_sources' => $this->integer($input['used_sources'] ?? null),
            'noise_sources' => $this->integer($input['noise_sources'] ?? null),
            'missed_required_sources' => $this->array($input['missed_required_sources'] ?? []),
            'context_sufficiency' => $this->score($input['context_sufficiency'] ?? null),
            'post_execution_utility' => $this->score($input['post_execution_utility'] ?? null),
            'source_utility' => $this->array($input['source_utility'] ?? []),
            'payload' => $input,
        ];
        $payload['feedback_hash'] = CompoundingHash::make([
            'schema' => self::SCHEMA_VERSION,
            'retrieval_receipt_id' => $receiptId,
            'flow_id' => $flowId,
            'source_utility' => $payload['source_utility'],
            'missed_required_sources' => $payload['missed_required_sources'],
        ]);

        return AiRagFeedbackEvent::query()->firstOrCreate(
            ['feedback_hash' => $payload['feedback_hash']],
            $payload,
        );
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
