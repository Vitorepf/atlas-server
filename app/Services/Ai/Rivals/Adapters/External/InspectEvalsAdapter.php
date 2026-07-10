<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/** UK AISI Inspect evals. */
class InspectEvalsAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'inspect_evals';
    }

    protected function commandTemplate(): string
    {
        return 'inspect eval {task_ref} --model {cli_model} --sample-id {sample_id} --epochs 1 --log-dir {log_dir} --log-format eval';
    }

    protected function mapResults(array $native): array
    {
        $model = $native['eval']['model']
            ?? $native['model']
            ?? throw new RuntimeException('inspect_evals_model_missing');
        $receipts = [];
        foreach ($native['samples'] ?? [] as $sample) {
            $sampleId = (string) ($sample['id'] ?? throw new RuntimeException('inspect_evals_sample_id_missing'));
            $taskType = $this->caseTaskType($sampleId)
                ?? $this->caseTaskType((string) ($native['eval']['task_display_name'] ?? ''))
                ?? $this->inferTaskType($sampleId, $native);
            $scoreValue = $sample['score']['value']
                ?? $sample['scores']['match']['value']
                ?? $sample['scores'][array_key_first((array) ($sample['scores'] ?? []))]['value']
                ?? null;
            $status = match (true) {
                $scoreValue === 'C', $scoreValue === 1, $scoreValue === 1.0, $scoreValue === true => 'success',
                $scoreValue === 'I', $scoreValue === 0, $scoreValue === 0.0, $scoreValue === false => 'failure',
                default => 'error',
            };
            $usage = $sample['model_usage'] ?? [];
            if (isset($usage[$model]) && is_array($usage[$model])) {
                $usage = $usage[$model];
            } elseif ($usage !== [] && ! isset($usage['input_tokens'])) {
                $first = reset($usage);
                $usage = is_array($first) ? $first : [];
            }
            $costPresent = array_key_exists('cost_usd', $usage)
                || array_key_exists('total_cost', $usage);
            $harnessOnly = str_starts_with($model, 'mockllm')
                || (($native['harness_note'] ?? null) !== null);
            $receipts[] = [
                'case_id' => $sampleId,
                'task_type' => $taskType,
                'arm_id' => $model.'@bare',
                'repetition' => (int) ($sample['epoch'] ?? $sample['repetition'] ?? 1),
                'status' => $status,
                'failure_class' => $status === 'success' ? null : ($status === 'error' ? 'invalid_result' : 'model_failure'),
                'wall_ms' => (int) round((float) ($sample['total_time'] ?? 0) * 1000),
                'tokens_in' => (int) ($usage['input_tokens'] ?? 0),
                'tokens_out' => (int) ($usage['output_tokens'] ?? 0),
                'cost_usd' => (float) ($usage['cost_usd'] ?? $usage['total_cost'] ?? 0.0),
                'field_presence' => [
                    'cost_usd' => [
                        'present' => $costPresent,
                        'reason' => $costPresent ? null : 'inspect_logs_omit_usd',
                    ],
                ],
                'started_at' => $sample['started_at'] ?? null,
                'finished_at' => $sample['completed_at'] ?? $sample['finished_at'] ?? null,
                'harness_only' => $harnessOnly,
                'metadata' => ['native' => [
                    'cli_model' => $model,
                    'native_agent' => 'inspect',
                    'source_repo' => 'inspect_evals',
                    'task' => $native['eval']['task'] ?? null,
                ]],
            ];
        }

        return $receipts;
    }

    /** @param array<string, mixed> $native */
    private function inferTaskType(string $sampleId, array $native): string
    {
        $task = (string) ($native['eval']['task'] ?? $native['eval']['task_display_name'] ?? $sampleId);
        if (str_contains($task, 'gaia') || str_contains($sampleId, 'gaia')) {
            return 'tool_use_function_calling';
        }

        // gsm8k / coding-style inspect tasks map to coding_patch for Rivals taxonomy
        return 'coding_patch';
    }
}
