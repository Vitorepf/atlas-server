<?php

namespace App\Services\Ai\Rivals2\Adapters\External;

use RuntimeException;

/**
 * UK AISI Inspect (inspect_evals): log de eval com samples C/I. O task_type
 * vem do case importado (inspect cobre muitos tipos de task) — sem case
 * importado o ingest fail-closes em vez de chutar.
 */
class InspectEvalsAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'inspect_evals';
    }

    protected function commandTemplate(): string
    {
        return 'inspect eval inspect_evals/{case_id} --model {model}';
    }

    protected function mapResults(array $native): array
    {
        $model = $native['eval']['model'] ?? 'unknown';
        $receipts = [];
        foreach ($native['samples'] ?? [] as $sample) {
            $sampleId = $sample['id'] ?? throw new RuntimeException('inspect_evals_sample_id_missing');
            $taskType = $this->caseTaskType($sampleId)
                ?? throw new RuntimeException('inspect_evals_case_task_type_missing:'.$sampleId);
            $receipts[] = [
                'case_id' => $sampleId,
                'task_type' => $taskType,
                'arm_id' => $model.'@inspect',
                'repetition' => (int) ($sample['epoch'] ?? 1),
                'status' => match ($sample['score']['value'] ?? null) {
                    'C' => 'success',
                    'I' => 'failure',
                    default => 'error',
                },
                'wall_ms' => (int) round((float) ($sample['total_time'] ?? 0) * 1000),
                'tokens_in' => (int) ($sample['model_usage']['input_tokens'] ?? 0),
                'tokens_out' => (int) ($sample['model_usage']['output_tokens'] ?? 0),
                'cost_usd' => 0.0, // inspect logs não reportam custo em USD; 0 honesto, nunca estimado
                'started_at' => $sample['started_at'] ?? null,
                'finished_at' => $sample['completed_at'] ?? null,
            ];
        }

        return $receipts;
    }
}
