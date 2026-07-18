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
        return 'harbor run --path tasks/{native_task_id} --agent-import-path rivals_harbor_hermes_agent:VerbooHermes --model {cli_model} --env {marathon_env} --allow-agent-host code.verboo.ai --verifier-env OPENAI_BASE_URL=https://code.verboo.ai/router/v1 --verifier-env OPENAI_API_BASE=https://code.verboo.ai/router/v1 --n-attempts 1 --n-concurrent 1 --jobs-dir {jobs_dir} --job-name {run_name} --yes';
    }

    protected function commandTemplateForArm(array $binding): string
    {
        // Braço com-Atlas: mesmo harbor, agente espelho host↔ambiente que roda
        // o bridge governado (scripts/rivals_harbor_atlas_agent.py).
        if (($binding['runtime'] ?? 'bare') === 'atlas_dev') {
            return str_replace(
                'rivals_harbor_hermes_agent:VerbooHermes',
                'rivals_harbor_atlas_agent:AtlasDev',
                $this->commandTemplate(),
            );
        }

        return parent::commandTemplateForArm($binding);
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
            $tokensIn = (int) ($task['usage']['input_tokens'] ?? 0);
            $tokensOut = (int) ($task['usage']['output_tokens'] ?? 0);
            $tokensPresent = ($tokensIn + $tokensOut) > 0;
            $isVerboo = str_contains((string) $model, 'kimi') || str_contains((string) $model, 'verboo');
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
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'cost_usd' => (float) ($task['usage']['cost_usd'] ?? 0.0),
                'field_presence' => [
                    'tokens_in' => [
                        'present' => $tokensPresent,
                        'reason' => $tokensPresent ? null : (
                            $hasEnvironmentError
                                ? 'swe_marathon_env_failure_before_usage'
                                : 'swe_marathon_hermes_usage_not_reported'
                        ),
                    ],
                    'tokens_out' => [
                        'present' => $tokensPresent,
                        'reason' => $tokensPresent ? null : (
                            $hasEnvironmentError
                                ? 'swe_marathon_env_failure_before_usage'
                                : 'swe_marathon_hermes_usage_not_reported'
                        ),
                    ],
                    'cost_usd' => [
                        'present' => $isVerboo && $tokensPresent,
                        'reason' => $isVerboo
                            ? ($tokensPresent ? 'verboo_subscription_marginal' : 'swe_marathon_hermes_usage_not_reported')
                            : 'swe_marathon_native_cost_not_reported',
                    ],
                    'wall_ms' => [
                        'present' => (float) ($task['duration_seconds'] ?? 0) > 0,
                        'reason' => (float) ($task['duration_seconds'] ?? 0) > 0 ? null : 'swe_marathon_duration_missing',
                    ],
                ],
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
