<?php

namespace App\Services\Ai\Rivals2\Adapters\External;

use RuntimeException;

/**
 * HAL (Holistic Agent Leaderboard) harness: a FONTE de custo/latência por run.
 * cost_usd e wall_ms DEVEM vir do resultado nativo — ausência é fail-closed,
 * nunca 0 inventado. task_type vem do case importado.
 */
class HalHarnessAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'hal_harness';
    }

    protected function commandTemplate(): string
    {
        return 'hal-eval --benchmark {case_id} --agent_dir agents/{arm_id} -A model_name={model} --run_id {rep}';
    }

    protected function mapResults(array $native): array
    {
        $receipts = [];
        foreach ($native['runs'] ?? [] as $run) {
            $taskId = $run['task_id'] ?? throw new RuntimeException('hal_harness_task_id_missing');
            if (! isset($run['total_cost_usd'], $run['latency_sec'])) {
                // HAL existe exatamente para custo/estatística — sem eles o receipt é lixo
                throw new RuntimeException('hal_harness_cost_or_latency_missing:'.$taskId);
            }
            $taskType = $this->caseTaskType($taskId)
                ?? throw new RuntimeException('hal_harness_case_task_type_missing:'.$taskId);
            $receipts[] = [
                'case_id' => $taskId,
                'task_type' => $taskType,
                'arm_id' => ($run['model'] ?? 'unknown').'@'.($run['agent'] ?? 'unknown'),
                'repetition' => (int) ($run['trial'] ?? 1),
                'status' => isset($run['success']) ? ($run['success'] ? 'success' : 'failure') : 'error',
                'wall_ms' => (int) round((float) $run['latency_sec'] * 1000),
                'tokens_in' => (int) ($run['input_tokens'] ?? 0),
                'tokens_out' => (int) ($run['output_tokens'] ?? 0),
                'cost_usd' => (float) $run['total_cost_usd'],
                'started_at' => $run['started_at'] ?? null,
                'finished_at' => $run['finished_at'] ?? null,
            ];
        }

        return $receipts;
    }
}
