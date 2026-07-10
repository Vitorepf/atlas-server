<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/** Terminal-Bench via Harbor: episódios de agente em terminal. */
class HarborTerminalBenchAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'terminal_bench';
    }

    protected function commandTemplate(): string
    {
        return 'tb run --dataset terminal-bench-core==0.1.1 --task-id {native_task_id} --agent {native_agent} --model {cli_model} --output-path {output_parent} --run-id {run_name} --n-attempts 1 --n-concurrent 1';
    }

    protected function commandTemplateForArm(array $binding): string
    {
        if (($binding['model_id'] ?? null) === 'verboo_kimi_k2_7') {
            return 'tb run --dataset terminal-bench-core==0.1.1 --task-id {native_task_id} --agent-import-path rivals_tb_verboo_agent:VerbooAiderAgent --model {cli_model} --output-path {output_parent} --run-id {run_name} --n-attempts 1 --n-concurrent 1';
        }

        return parent::commandTemplateForArm($binding);
    }

    protected function mapResults(array $native): array
    {
        $receipts = [];
        foreach ($native['episodes'] ?? [] as $ep) {
            $episodeId = $ep['episode_id'] ?? throw new RuntimeException('terminal_bench_episode_id_missing');
            $model = $ep['model'] ?? throw new RuntimeException('terminal_bench_model_missing');
            $agent = $ep['agent'] ?? throw new RuntimeException('terminal_bench_agent_missing');
            $status = match ($ep['exit_status'] ?? null) {
                'completed' => 'success',
                'failed' => 'failure',
                'timeout' => 'timeout',
                default => 'error',
            };
            $receipts[] = [
                'case_id' => $episodeId,
                'task_type' => 'terminal_agent',
                'arm_id' => $model.'@bare',
                'repetition' => (int) ($ep['trial'] ?? 1),
                'status' => $status,
                'failure_class' => match ($status) {
                    'success' => null,
                    'timeout' => 'timeout',
                    'error' => 'environment_failure',
                    default => 'model_failure',
                },
                'wall_ms' => (int) round((float) ($ep['duration_sec'] ?? 0) * 1000),
                'tokens_in' => (int) ($ep['input_tokens'] ?? 0),
                'tokens_out' => (int) ($ep['output_tokens'] ?? 0),
                'cost_usd' => (float) ($ep['cost_usd'] ?? 0.0),
                'field_presence' => (array) ($ep['field_presence'] ?? []),
                'started_at' => $ep['started_at'] ?? null,
                'finished_at' => $ep['ended_at'] ?? null,
                'metadata' => ['native' => [
                    'cli_model' => $model,
                    'native_agent' => $agent,
                    'source_repo' => 'terminal_bench',
                ]],
            ];
        }

        return $receipts;
    }
}
