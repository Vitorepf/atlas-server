<?php

namespace App\Services\Ai\Rivals2\Adapters\External;

use RuntimeException;

/** tau2-bench / BFCL: tool use + function calling com usuário simulado. */
class Tau2BfclAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'tau2_bfcl';
    }

    protected function commandTemplate(): string
    {
        return 'tau2 run --domain airline --agent-llm {model} --task-ids {case_id} --num-trials {rep}';
    }

    protected function mapResults(array $native): array
    {
        $model = $native['agent_llm'] ?? 'unknown';
        $receipts = [];
        foreach ($native['simulations'] ?? [] as $sim) {
            $taskId = $sim['task_id'] ?? throw new RuntimeException('tau2_bfcl_task_id_missing');
            $receipts[] = [
                'case_id' => $taskId,
                'task_type' => 'tool_use_function_calling',
                'arm_id' => $model.'@tau2',
                'repetition' => (int) ($sim['trial'] ?? 1),
                'status' => isset($sim['reward']) ? ((float) $sim['reward'] >= 1.0 ? 'success' : 'failure') : 'error',
                'wall_ms' => (int) round((float) ($sim['duration_sec'] ?? 0) * 1000),
                'tokens_in' => (int) ($sim['usage']['input_tokens'] ?? 0),
                'tokens_out' => (int) ($sim['usage']['output_tokens'] ?? 0),
                'cost_usd' => (float) ($sim['usage']['cost_usd'] ?? 0.0),
                'started_at' => $sim['started_at'] ?? null,
                'finished_at' => $sim['finished_at'] ?? null,
            ];
        }

        return $receipts;
    }
}
