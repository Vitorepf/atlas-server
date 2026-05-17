<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiRunOutcome;
use Illuminate\Support\Str;

class AtlasCompoundingOutcomeEvaluator
{
    public const SCHEMA_VERSION = 'atlas.ai.compounding.outcome.v1';

    /**
     * @param  array<string,mixed>  $input
     */
    public function evaluate(array $input): AiRunOutcome
    {
        $status = $this->string($input['outcome_status'] ?? null) ?? $this->string($input['status'] ?? null) ?? 'passed';
        $flowId = $this->string($input['flow_id'] ?? null) ?? 'atlas_conversation';
        $evidenceRefs = $this->array($input['evidence_refs'] ?? []);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $this->string($input['run_id'] ?? null) ?? 'run_'.Str::uuid()->toString(),
            'trace_id' => $this->string($input['trace_id'] ?? null),
            'flow_id' => $flowId,
            'outcome_status' => $status,
            'flow_quality' => $this->score($input['flow_quality'] ?? null, $status === 'passed' ? 90 : 45),
            'retrieval_quality' => $this->score($input['retrieval_quality'] ?? null, empty($input['retrieval_receipt_id']) ? 55 : 85),
            'execution_quality' => $this->score($input['execution_quality'] ?? null, $status === 'passed' ? 90 : 40),
            'evidence_quality' => $this->score($input['evidence_quality'] ?? null, $evidenceRefs === [] ? 35 : 90),
            'human_override' => (bool) ($input['human_override'] ?? false),
            'learning_required' => (bool) ($input['learning_required'] ?? ($status !== 'passed' || $evidenceRefs !== [])),
            'missed_signals' => $this->array($input['missed_signals'] ?? []),
            'evidence_refs' => $evidenceRefs,
            'payload' => $input,
            'evaluated_at' => now(),
        ];
        $payload['outcome_hash'] = CompoundingHash::make([
            'schema' => self::SCHEMA_VERSION,
            'run_id' => $payload['run_id'],
            'flow_id' => $payload['flow_id'],
            'status' => $payload['outcome_status'],
            'evidence_refs' => $payload['evidence_refs'],
            'payload_hash' => CompoundingHash::make($this->hashable($input)),
        ]);

        return AiRunOutcome::query()->firstOrCreate(
            ['outcome_hash' => $payload['outcome_hash']],
            $payload,
        );
    }

    private function score(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

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

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function hashable(array $input): array
    {
        unset($input['created_at'], $input['updated_at']);

        return $input;
    }
}
