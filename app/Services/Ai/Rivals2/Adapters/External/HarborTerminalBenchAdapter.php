<?php

namespace App\Services\Ai\Rivals2\Adapters\External;

use RuntimeException;

/** Terminal-Bench via Harbor: episódios de agente em terminal. */
class HarborTerminalBenchAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'harbor_terminal_bench';
    }

    protected function commandTemplate(): string
    {
        return 'harbor run --dataset terminal-bench-core --agent {arm_id} --model {model} --task-id {case_id}';
    }

    protected function mapResults(array $native): array
    {
        $receipts = [];
        foreach ($native['episodes'] ?? [] as $ep) {
            $episodeId = $ep['episode_id'] ?? throw new RuntimeException('harbor_terminal_bench_episode_id_missing');
            $receipts[] = [
                'case_id' => $episodeId,
                'task_type' => 'terminal_agent',
                'arm_id' => ($ep['model'] ?? 'unknown').'@'.($ep['agent'] ?? 'unknown'),
                'repetition' => (int) ($ep['trial'] ?? 1),
                'status' => match ($ep['exit_status'] ?? null) {
                    'completed' => 'success',
                    'failed' => 'failure',
                    'timeout' => 'timeout',
                    default => 'error', // exit_status ausente/desconhecido = medição de erro, não invenção
                },
                'wall_ms' => (int) round((float) ($ep['duration_sec'] ?? 0) * 1000),
                'tokens_in' => (int) ($ep['input_tokens'] ?? 0),
                'tokens_out' => (int) ($ep['output_tokens'] ?? 0),
                'cost_usd' => (float) ($ep['cost_usd'] ?? 0.0),
                'started_at' => $ep['started_at'] ?? null,
                'finished_at' => $ep['ended_at'] ?? null,
            ];
        }

        return $receipts;
    }
}
