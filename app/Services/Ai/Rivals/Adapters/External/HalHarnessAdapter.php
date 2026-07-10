<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/** HAL harness: custo/latência obrigatórios; task_type do case importado. */
class HalHarnessAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'hal_harness';
    }

    protected function commandTemplate(): string
    {
        return 'python {atlas_root}/scripts/rivals_hal_verboo.py --benchmark {benchmark} --agent_dir {agent_dir} --agent_function {agent_function} --agent_name {agent_name} -A model_name={cli_model} --task_ids {task_id} --max_tasks 1 --max_concurrent 1 --run_id {run_name} --results_dir {results_parent}';
    }

    protected function mapResults(array $native): array
    {
        $receipts = [];
        foreach ($native['runs'] ?? [] as $run) {
            $taskId = $run['task_id'] ?? throw new RuntimeException('hal_harness_task_id_missing');
            if (! isset($run['total_cost_usd'], $run['latency_sec'])) {
                throw new RuntimeException('hal_harness_cost_or_latency_missing:'.$taskId);
            }
            $taskType = $this->caseTaskType($taskId)
                ?? throw new RuntimeException('hal_harness_case_task_type_missing:'.$taskId);
            $model = $run['model'] ?? throw new RuntimeException('hal_harness_model_missing');
            $agent = $run['agent'] ?? throw new RuntimeException('hal_harness_agent_missing');
            $status = isset($run['success']) ? ($run['success'] ? 'success' : 'failure') : 'error';
            $receipts[] = [
                'case_id' => $taskId,
                'task_type' => $taskType,
                'arm_id' => $model.'@bare',
                'repetition' => (int) ($run['trial'] ?? 1),
                'status' => $status,
                'failure_class' => $status === 'success' ? null : 'model_failure',
                'wall_ms' => (int) round((float) $run['latency_sec'] * 1000),
                'tokens_in' => (int) ($run['input_tokens'] ?? 0),
                'tokens_out' => (int) ($run['output_tokens'] ?? 0),
                'cost_usd' => (float) $run['total_cost_usd'],
                'started_at' => $run['started_at'] ?? null,
                'finished_at' => $run['finished_at'] ?? null,
                'metadata' => ['native' => [
                    'cli_model' => $model,
                    'native_agent' => $agent,
                    'source_repo' => 'hal_harness',
                ]],
            ];
        }

        return $receipts;
    }
}
