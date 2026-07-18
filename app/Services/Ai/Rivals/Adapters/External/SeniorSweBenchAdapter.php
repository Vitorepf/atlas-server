<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/**
 * Senior SWE-Bench (Snorkel, Harbor). Subset público 50 tasks.
 * Dimensions nunca colapsam; judge_config obrigatório.
 */
class SeniorSweBenchAdapter extends AbstractExternalSuiteAdapter
{
    public const COVERAGE = 'public_50_only';

    public function suiteId(): string
    {
        return 'senior_swe_bench';
    }

    protected function commandTemplate(): string
    {
        return 'harbor run --path tasks --include-task-name {native_task_id} --agent-import-path rivals_harbor_hermes_agent:VerbooHermes --model {cli_model} --allow-agent-host code.verboo.ai --verifier-env OPENAI_BASE_URL=https://code.verboo.ai/router/v1 --verifier-env OPENAI_API_BASE=https://code.verboo.ai/router/v1 --n-attempts 1 --n-concurrent 1 --jobs-dir {jobs_dir} --job-name {run_name} --yes';
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
        $judge = $native['judge_config'] ?? null;
        if (! is_array($judge) || $judge === []) {
            throw new RuntimeException('senior_swe_bench_judge_config_missing');
        }
        if (($native['coverage'] ?? null) !== self::COVERAGE) {
            throw new RuntimeException('senior_swe_bench_coverage_mismatch:expected='.self::COVERAGE);
        }

        $receipts = [];
        foreach ($native['tasks'] ?? [] as $task) {
            $taskId = $task['task_id'] ?? throw new RuntimeException('senior_swe_bench_task_id_missing');
            $dimensions = $task['verdicts'] ?? null;
            if (! is_array($dimensions)) {
                throw new RuntimeException('senior_swe_bench_verdicts_missing:'.$taskId);
            }
            $taskType = match ($task['task'] ?? null) {
                'feature' => 'feature_under_specified',
                'bug' => 'bug_investigation',
                default => throw new RuntimeException('senior_swe_bench_unknown_task_kind:'.$taskId),
            };
            $model = $task['model'] ?? throw new RuntimeException('senior_swe_bench_model_missing');
            $agent = $task['agent'] ?? throw new RuntimeException('senior_swe_bench_agent_missing');
            $status = isset($task['resolved']) ? ($task['resolved'] ? 'success' : 'failure') : 'error';
            $hasEnvironmentError = is_array($task['exception_info'] ?? null)
                && $task['exception_info'] !== [];
            $tokensIn = (int) ($task['usage']['input_tokens'] ?? 0);
            $tokensOut = (int) ($task['usage']['output_tokens'] ?? 0);
            $tokensPresent = ($tokensIn + $tokensOut) > 0;
            $isVerboo = str_contains((string) $model, 'kimi') || str_contains((string) $model, 'verboo');

            $receipts[] = [
                'case_id' => $taskId,
                'task_type' => $taskType,
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
                                ? 'senior_swe_env_failure_before_usage'
                                : 'senior_swe_hermes_usage_not_reported'
                        ),
                    ],
                    'tokens_out' => [
                        'present' => $tokensPresent,
                        'reason' => $tokensPresent ? null : (
                            $hasEnvironmentError
                                ? 'senior_swe_env_failure_before_usage'
                                : 'senior_swe_hermes_usage_not_reported'
                        ),
                    ],
                    'cost_usd' => [
                        'present' => $isVerboo && $tokensPresent,
                        'reason' => $isVerboo
                            ? ($tokensPresent ? 'verboo_subscription_marginal' : 'senior_swe_hermes_usage_not_reported')
                            : 'senior_swe_native_cost_not_reported',
                    ],
                    'wall_ms' => [
                        'present' => (float) ($task['duration_seconds'] ?? 0) > 0,
                        'reason' => (float) ($task['duration_seconds'] ?? 0) > 0 ? null : 'senior_swe_duration_missing',
                    ],
                ],
                'started_at' => $task['started_at'] ?? null,
                'finished_at' => $task['finished_at'] ?? null,
                'dimensions' => $dimensions,
                'judge_config' => $judge,
                'metadata' => ['native' => [
                    'cli_model' => $model,
                    'native_agent' => $agent,
                    'source_repo' => 'senior_swe_bench',
                ]],
            ];
        }

        return $receipts;
    }
}
