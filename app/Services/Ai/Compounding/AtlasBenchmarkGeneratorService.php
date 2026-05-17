<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiBenchmarkCase;
use App\Models\AiRunOutcome;
use Illuminate\Support\Str;

class AtlasBenchmarkGeneratorService
{
    public const SCHEMA_VERSION = 'atlas.ai.compounding.benchmark_case.v1';

    /**
     * @param  array<string,mixed>  $input
     */
    public function fromOutcome(AiRunOutcome $outcome, array $input = []): ?AiBenchmarkCase
    {
        $shouldCreate = (bool) ($input['force'] ?? false)
            || $outcome->outcome_status !== 'passed'
            || $outcome->flow_quality < 70
            || $outcome->retrieval_quality < 70
            || $outcome->human_override;

        if (! $shouldCreate) {
            return null;
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_outcome_id' => $outcome->id,
            'case_id' => $this->string($input['case_id'] ?? null) ?? 'bench_'.Str::uuid()->toString(),
            'source' => $this->string($input['source'] ?? null) ?? 'real_user_run',
            'prompt' => $this->string($input['prompt'] ?? data_get($outcome->payload, 'prompt')),
            'expected_flow' => $this->string($input['expected_flow'] ?? null) ?? $outcome->flow_id,
            'required_evidence' => is_array($input['required_evidence'] ?? null) ? $input['required_evidence'] : $outcome->evidence_refs,
            'rivals' => is_array($input['rivals'] ?? null) ? $input['rivals'] : ['claude_code', 'codex'],
            'status' => 'active',
            'payload' => [
                'outcome_hash' => $outcome->outcome_hash,
                'reason' => $input['reason'] ?? 'outcome_requires_regression_case',
            ],
        ];
        $payload['case_hash'] = CompoundingHash::make([
            'schema' => self::SCHEMA_VERSION,
            'outcome_hash' => $outcome->outcome_hash,
            'expected_flow' => $payload['expected_flow'],
            'prompt' => $payload['prompt'],
        ]);

        return AiBenchmarkCase::query()->firstOrCreate(
            ['case_hash' => $payload['case_hash']],
            $payload,
        );
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
