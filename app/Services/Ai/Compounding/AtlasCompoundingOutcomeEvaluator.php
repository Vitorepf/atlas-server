<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiRunOutcome;
use App\Services\Ai\Aemor\Envelope\OutcomeEnvelopeBridge;
use Illuminate\Support\Str;

class AtlasCompoundingOutcomeEvaluator
{
    use CompoundingArrayHelper;

    public const SCHEMA_VERSION = 'atlas.ai.compounding.outcome.v1';

    /**
     * @param  array<string,mixed>  $input
     */
    public function evaluate(array $input): AiRunOutcome
    {
        // No 'passed' default: an outcome nobody reported a status for is not a
        // pass. 'unknown' is already part of this column's vocabulary.
        $status = $this->string($input['outcome_status'] ?? null) ?? $this->string($input['status'] ?? null) ?? 'unknown';
        $flowId = $this->string($input['flow_id'] ?? null) ?? 'atlas_conversation';
        $evidenceRefs = $this->array($input['evidence_refs'] ?? []);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $this->string($input['run_id'] ?? null) ?? 'run_'.Str::uuid()->toString(),
            'trace_id' => $this->string($input['trace_id'] ?? null),
            'flow_id' => $flowId,
            'outcome_status' => $status,
            // These four are scores the CALLER may supply. When it does not, the
            // value below is DERIVED from status/evidence shape — it is not a
            // measurement, and dashboards averaging these columns were
            // presenting derived constants as measured system health. The
            // columns are unsignedTinyInteger NOT NULL, so the value has to
            // stay; what was missing is saying which is which. See
            // quality_provenance in the payload.
            'flow_quality' => $this->score($input['flow_quality'] ?? null, $status === 'passed' ? 90 : 45),
            'retrieval_quality' => $this->score($input['retrieval_quality'] ?? null, empty($input['retrieval_receipt_id']) ? 55 : 85),
            'execution_quality' => $this->score($input['execution_quality'] ?? null, $status === 'passed' ? 90 : 40),
            'evidence_quality' => $this->score($input['evidence_quality'] ?? null, $evidenceRefs === [] ? 35 : 90),
            'human_override' => (bool) ($input['human_override'] ?? false),
            'learning_required' => (bool) ($input['learning_required'] ?? ($status !== 'passed' || $evidenceRefs !== [])),
            'missed_signals' => $this->array($input['missed_signals'] ?? []),
            'evidence_refs' => $evidenceRefs,
            'payload' => $input + [
                'quality_provenance' => [
                    'flow_quality' => is_numeric($input['flow_quality'] ?? null) ? 'measured' : 'derived',
                    'retrieval_quality' => is_numeric($input['retrieval_quality'] ?? null) ? 'measured' : 'derived',
                    'execution_quality' => is_numeric($input['execution_quality'] ?? null) ? 'measured' : 'derived',
                    'evidence_quality' => is_numeric($input['evidence_quality'] ?? null) ? 'measured' : 'derived',
                    'outcome_status' => ($this->string($input['outcome_status'] ?? null) ?? $this->string($input['status'] ?? null)) !== null
                        ? 'reported'
                        : 'absent',
                ],
            ],
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

        $envelope = app(OutcomeEnvelopeBridge::class)->project('compounding', $payload);
        if ($envelope !== null) {
            $payload['outcome_envelope'] = $envelope;
        }

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
