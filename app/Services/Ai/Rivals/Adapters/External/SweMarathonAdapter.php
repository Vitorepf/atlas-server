<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/**
 * SWE-Marathon (https://www.swe-marathon.org/) — ultra-long-horizon SWE via Harbor.
 * Reward binário (pass@1): resolved true só com todos os verifiers verdes.
 * task_type default = long_horizon_engineering; case importado pode sobrescrever.
 * Execução real (Modal + provider) é fase futura; este adapter só documenta
 * o comando e ingere resultados nativos já produzidos.
 */
class SweMarathonAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'swe_marathon';
    }

    protected function commandTemplate(): string
    {
        // Espelha scripts/run-benchmark.sh do repo (harbor run -p tasks/<id>).
        return 'harbor run -p tasks/{case_id} -a {arm_id} -m {model}';
    }

    protected function mapResults(array $native): array
    {
        $receipts = [];
        foreach ($native['tasks'] ?? [] as $task) {
            $taskId = $task['task_id'] ?? throw new RuntimeException('swe_marathon_task_id_missing');
            $receipts[] = [
                'case_id' => $taskId,
                'task_type' => $this->caseTaskType($taskId) ?? 'long_horizon_engineering',
                'arm_id' => ($task['model'] ?? 'unknown').'@'.($task['agent'] ?? 'unknown'),
                'repetition' => (int) ($task['attempt'] ?? 1),
                'status' => isset($task['resolved']) ? ($task['resolved'] ? 'success' : 'failure') : 'error',
                'wall_ms' => (int) round((float) ($task['duration_seconds'] ?? 0) * 1000),
                'tokens_in' => (int) ($task['usage']['input_tokens'] ?? 0),
                'tokens_out' => (int) ($task['usage']['output_tokens'] ?? 0),
                'cost_usd' => (float) ($task['usage']['cost_usd'] ?? 0.0),
                'started_at' => $task['started_at'] ?? null,
                'finished_at' => $task['finished_at'] ?? null,
            ];
        }

        return $receipts;
    }
}
