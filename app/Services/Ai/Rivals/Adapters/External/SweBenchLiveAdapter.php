<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/**
 * SWE-bench-Live: predictions.jsonl (agente) + evaluation (harness).
 * Tokens/custo do agente devem vir do predictions stage quando disponíveis.
 */
class SweBenchLiveAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'swe_bench_live';
    }

    protected function commandTemplate(): string
    {
        return 'php {atlas_root}/scripts/rivals-swe-live-unit.php --case-file={case_file} --instance={native_task_id} --model={atlas_cli_model} --scratch={eval_scratch_dir}';
    }

    protected function mapResults(array $native): array
    {
        $receipts = [];
        foreach ($native['instances'] ?? [] as $inst) {
            $instanceId = $inst['instance_id'] ?? throw new RuntimeException('swe_bench_live_instance_id_missing');
            $model = $inst['model_name_or_path'] ?? throw new RuntimeException('swe_bench_live_model_missing');
            $status = match (true) {
                ($inst['eval_status'] ?? null) === 'error' => 'error',
                ($inst['resolved'] ?? null) === true => 'success',
                ($inst['resolved'] ?? null) === false => 'failure',
                default => 'error',
            };
            $hasTokens = isset($inst['usage']['input_tokens'], $inst['usage']['output_tokens'], $inst['usage']['cost_usd']);
            $receipts[] = [
                'case_id' => $instanceId,
                'task_type' => $this->caseTaskType($instanceId) ?? 'repair_regression_fixing',
                'arm_id' => $model.'@bare',
                'repetition' => (int) ($inst['repetition'] ?? 1),
                'status' => $status,
                'failure_class' => match ($status) {
                    'success' => null,
                    'failure' => 'model_failure',
                    default => 'environment_failure',
                },
                'wall_ms' => (int) round((float) ($inst['duration_sec'] ?? 0) * 1000),
                'tokens_in' => (int) ($inst['usage']['input_tokens'] ?? 0),
                'tokens_out' => (int) ($inst['usage']['output_tokens'] ?? 0),
                'cost_usd' => (float) ($inst['usage']['cost_usd'] ?? 0.0),
                'field_presence' => [
                    'tokens_in' => ['present' => $hasTokens, 'reason' => $hasTokens ? null : 'eval_harness_omits_agent_usage'],
                    'tokens_out' => ['present' => $hasTokens, 'reason' => $hasTokens ? null : 'eval_harness_omits_agent_usage'],
                    'cost_usd' => ['present' => $hasTokens, 'reason' => $hasTokens ? null : 'eval_harness_omits_agent_usage'],
                ],
                'started_at' => $inst['started_at'] ?? null,
                'finished_at' => $inst['finished_at'] ?? null,
                'metadata' => ['native' => [
                    'cli_model' => $model,
                    'native_agent' => 'swe_bench_live',
                    'source_repo' => 'swe_bench_live',
                    'pipeline' => 'predictions_then_evaluation',
                ]],
            ];
        }

        return $receipts;
    }
}
