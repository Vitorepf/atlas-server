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

    /**
     * Teto de tempo do agente — IDÊNTICO nos dois braços, e por isso declarado
     * numa constante em vez de repetido nas duas strings.
     *
     * O default do `tb` é 360s, e ele é INVISÍVEL para o braço bare (one-shot
     * responde em segundos) e FATAL para o braço Atlas (28 subsistemas, ~20 min).
     * Medido: `tb_hello` (trivial) cabia nos 6 min e produzia recibo; `tb_fix_git`
     * e `tb_git-multibranch` (tarefas reais) morriam com "Agent timed out after
     * 360.0s" — e o relatório lia isso como falha de AMBIENTE, não como o teste
     * cortando o Atlas no meio.
     *
     * Um teto que só um dos braços alcança não mede capacidade: mede o teto. E
     * a assimetria some no relatório, porque timeout vira environment_failure e
     * environment_failure é descartado do denominador.
     *
     * O valor é IGUAL nos dois de propósito. Dar mais tempo só ao Atlas seria a
     * assimetria invertida — o Goodhart a favor de quem está sendo medido. O
     * bare não usa o tempo extra (termina em segundos); o Atlas precisa dele; e
     * a comparação continua justa porque o teto é o mesmo.
     */
    private const AGENT_TIMEOUT_SEC = 2400;

    protected function commandTemplateForArm(array $binding): string
    {
        if (($binding['model_id'] ?? null) === 'verboo_kimi_k2_7') {
            $timeout = ' --global-agent-timeout-sec '.self::AGENT_TIMEOUT_SEC;
            if (($binding['runtime'] ?? null) === 'atlas_dev') {
                return 'tb run --dataset terminal-bench-core==0.1.1 --task-id {native_task_id} --agent-import-path rivals_tb_atlas_agent:AtlasDevAgent --model {cli_model} --output-path {output_parent} --run-id {run_name} --n-attempts 1 --n-concurrent 1'.$timeout;
            }

            return 'tb run --dataset terminal-bench-core==0.1.1 --task-id {native_task_id} --agent-import-path rivals_tb_verboo_agent:VerbooAiderAgent --model {cli_model} --output-path {output_parent} --run-id {run_name} --n-attempts 1 --n-concurrent 1'.$timeout;
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
