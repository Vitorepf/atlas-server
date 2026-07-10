<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/** tau2-bench: tool use / agent dialog com usuário simulado. */
class Tau2BenchAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'tau2_bench';
    }

    protected function commandTemplate(): string
    {
        return 'tau2 run --domain {native_domain} --agent-llm {cli_model} --user-llm {cli_model} --task-ids {native_task_id} --num-trials 1 --seed {seed} --save-to {run_name} --auto-resume';
    }

    protected function mapResults(array $native): array
    {
        $model = $native['agent_llm']
            ?? $native['info']['agent_info']['llm']
            ?? throw new RuntimeException('tau2_bench_agent_llm_missing');
        $receipts = [];
        foreach ($native['simulations'] ?? [] as $sim) {
            $taskId = $sim['task_id'] ?? throw new RuntimeException('tau2_bench_task_id_missing');
            $reward = $sim['reward'] ?? $sim['reward_info']['reward'] ?? null;
            $termination = (string) ($sim['termination_reason'] ?? '');
            $timedOut = str_contains(strtolower($termination), 'timeout');
            $status = match (true) {
                $timedOut => 'timeout',
                is_numeric($reward) => (float) $reward >= 1.0 ? 'success' : 'failure',
                default => 'error',
            };
            $usage = (array) ($sim['usage'] ?? []);
            $receipts[] = [
                'case_id' => $taskId,
                'task_type' => 'tool_use_function_calling',
                'arm_id' => $model.'@bare', // temporary; ingest remaps via plan binding
                'repetition' => (int) ($sim['rivals_repetition'] ?? $sim['trial'] ?? 1),
                'status' => $status,
                'failure_class' => $status === 'success'
                    ? null
                    : ($status === 'timeout' ? 'timeout' : ($status === 'failure' ? 'model_failure' : 'invalid_result')),
                'wall_ms' => (int) round((float) ($sim['duration_sec'] ?? $sim['duration'] ?? 0) * 1000),
                'tokens_in' => (int) ($usage['input_tokens'] ?? $sim['input_tokens'] ?? 0),
                'tokens_out' => (int) ($usage['output_tokens'] ?? $sim['output_tokens'] ?? 0),
                'cost_usd' => (float) ($usage['cost_usd'] ?? $sim['agent_cost'] ?? 0.0),
                'started_at' => $sim['started_at'] ?? $sim['start_time'] ?? null,
                'finished_at' => $sim['finished_at'] ?? $sim['end_time'] ?? null,
                'metadata' => [
                    'native' => [
                        'cli_model' => $model,
                        'native_agent' => 'tau2',
                        'source_repo' => 'tau2_bench',
                    ],
                ],
            ];
        }

        return $receipts;
    }
}
