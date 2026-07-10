<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/** SWE-Marathon — ultra-long-horizon SWE via Harbor/Modal. */
class SweMarathonAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'swe_marathon';
    }

    protected function commandTemplate(): string
    {
        return 'harbor run --path tasks/{native_task_id} --agent-import-path rivals_harbor_hermes_agent:VerbooHermes --model {cli_model} --env {marathon_env} --allow-agent-host code.verboo.ai --n-attempts 1 --n-concurrent 1 --jobs-dir {jobs_dir} --job-name {run_name} --yes';
    }

    protected function mapResults(array $native): array
    {
        $receipts = [];
        foreach ($native['tasks'] ?? [] as $task) {
            $taskId = $task['task_id'] ?? throw new RuntimeException('swe_marathon_task_id_missing');
            $model = $task['model'] ?? throw new RuntimeException('swe_marathon_model_missing');
            $agent = $task['agent'] ?? throw new RuntimeException('swe_marathon_agent_missing');
            $status = isset($task['resolved']) ? ($task['resolved'] ? 'success' : 'failure') : 'error';
            $hasEnvironmentError = is_array($task['exception_info'] ?? null)
                && $task['exception_info'] !== [];
            $receipts[] = [
                'case_id' => $taskId,
                'task_type' => $this->caseTaskType($taskId) ?? 'long_horizon_engineering',
                'arm_id' => $model.'@bare',
                'repetition' => (int) ($task['attempt'] ?? 1),
                'status' => $status,
                'failure_class' => match (true) {
                    $status === 'success' => null,
                    $hasEnvironmentError => 'environment_failure',
                    $status === 'error' => 'invalid_result',
                    default => 'model_failure',
                },
                'wall_ms' => (int) round((float) ($task['duration_seconds'] ?? 0) * 1000),
                'tokens_in' => (int) ($task['usage']['input_tokens'] ?? 0),
                'tokens_out' => (int) ($task['usage']['output_tokens'] ?? 0),
                'cost_usd' => (float) ($task['usage']['cost_usd'] ?? 0.0),
                'started_at' => $task['started_at'] ?? null,
                'finished_at' => $task['finished_at'] ?? null,
                'metadata' => ['native' => [
                    'cli_model' => $model,
                    'native_agent' => $agent,
                    'source_repo' => 'swe_marathon',
                ]],
            ];
        }

        return $receipts;
    }
}
