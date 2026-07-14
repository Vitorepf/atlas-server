<?php

namespace App\Services\Ai\Rivals\Adapters\External;

use RuntimeException;

/** UK AISI Inspect evals. */
class InspectEvalsAdapter extends AbstractExternalSuiteAdapter
{
    public function suiteId(): string
    {
        return 'inspect_evals';
    }

    protected function commandTemplate(): string
    {
        // {cli_model} = openai-api/verboo/kimi-k2.7 (ver config native_models):
        // provider compatível de terceiros. `responses_api=false` era gambiarra
        // para o provider `openai`; o openai-api não precisa e não aceita.
        //
        // --reasoning-tokens DECLARA que o kimi-k2.7 é modelo de raciocínio (ele
        // emite bloco de reasoning nativamente). Sem isso, tasks não-CoT como
        // mmlu_0_shot aplicam cap de 16 tokens ("basta para 'ANSWER: B'"): o
        // raciocínio come o orçamento, o texto sai VAZIO e o scorer lê 0%.
        // O próprio inspect trata isso — `get_max_tokens()` retorna None "for
        // reasoning models to avoid truncating thinking tokens" — mas só se o
        // config declarar. Medido: mmlu_0_shot 0% → 80% só com esta flag; gsm8k
        // segue 1.000 (sem regressão). Sem a flag mede-se o cap, não o modelo.
        return 'inspect eval {task_ref} --model {cli_model} --model-base-url https://code.verboo.ai/router/v1 --reasoning-tokens 2048 --sample-id {sample_id} --epochs 1 --log-dir {log_dir} --log-format eval';
    }

    protected function mapResults(array $native): array
    {
        $model = $native['eval']['model']
            ?? $native['model']
            ?? throw new RuntimeException('inspect_evals_model_missing');
        $receipts = [];
        foreach ($native['samples'] ?? [] as $sample) {
            $sampleId = (string) ($sample['id'] ?? throw new RuntimeException('inspect_evals_sample_id_missing'));
            $taskType = $this->caseTaskType($sampleId)
                ?? $this->caseTaskType((string) ($native['eval']['task_display_name'] ?? ''))
                ?? $this->inferTaskType($sampleId, $native);
            $scoreValue = $sample['score']['value']
                ?? $sample['scores']['match']['value']
                ?? $sample['scores'][array_key_first((array) ($sample['scores'] ?? []))]['value']
                ?? null;
            // Erro de execução no sample (API 400, role incompatível, timeout,
            // conexão) NÃO é o modelo errando a tarefa — é falha de ambiente/fluxo.
            // Sem isto, gsm8k com "developer role unsupported" virava "raciocínio 0%".
            $sampleError = trim((string) ($sample['error'] ?? ''));
            $isExecutionError = $sampleError !== '' && (bool) preg_match(
                '/BadRequestError|error code: \d{3}|unsupported_message_role|invalid_request_error|ConnectionError|Timeout|RateLimit|ServiceUnavailable|InternalServerError|API/i',
                $sampleError,
            );
            $status = match (true) {
                $isExecutionError => 'environment_failure',
                $scoreValue === 'C', $scoreValue === 1, $scoreValue === 1.0, $scoreValue === true => 'success',
                $scoreValue === 'I', $scoreValue === 0, $scoreValue === 0.0, $scoreValue === false => 'failure',
                default => 'error',
            };
            $usage = $sample['model_usage'] ?? [];
            if (isset($usage[$model]) && is_array($usage[$model])) {
                $usage = $usage[$model];
            } elseif ($usage !== [] && ! isset($usage['input_tokens'])) {
                $first = reset($usage);
                $usage = is_array($first) ? $first : [];
            }
            $isVerboo = str_contains($model, 'kimi-k2.7');
            $costPresent = $isVerboo
                || array_key_exists('cost_usd', $usage)
                || array_key_exists('total_cost', $usage);
            $tokensPresent = isset($usage['input_tokens'], $usage['output_tokens']);
            $harnessOnly = str_starts_with($model, 'mockllm')
                || (($native['harness_note'] ?? null) !== null);
            $receipts[] = [
                'case_id' => $sampleId,
                'task_type' => $taskType,
                'arm_id' => $model.'@bare',
                'repetition' => (int) ($sample['epoch'] ?? $sample['repetition'] ?? 1),
                'status' => $status === 'environment_failure' ? 'error' : $status,
                'failure_class' => match ($status) {
                    'success' => null,
                    'environment_failure' => 'environment_failure',
                    'error' => 'invalid_result',
                    default => 'model_failure',
                },
                'environment_error' => $isExecutionError ? mb_substr($sampleError, 0, 240) : null,
                'wall_ms' => (int) round((float) ($sample['total_time'] ?? 0) * 1000),
                'tokens_in' => (int) ($usage['input_tokens'] ?? 0),
                'tokens_out' => (int) ($usage['output_tokens'] ?? 0),
                'cost_usd' => (float) ($usage['cost_usd'] ?? $usage['total_cost'] ?? 0.0),
                'field_presence' => [
                    'cost_usd' => [
                        'present' => $costPresent,
                        'reason' => $isVerboo
                            ? 'verboo_subscription_marginal'
                            : ($costPresent ? null : 'inspect_logs_omit_usd'),
                    ],
                    'tokens_in' => [
                        'present' => $tokensPresent,
                        'reason' => $tokensPresent ? null : 'inspect_logs_omit_usage',
                    ],
                    'tokens_out' => [
                        'present' => $tokensPresent,
                        'reason' => $tokensPresent ? null : 'inspect_logs_omit_usage',
                    ],
                ],
                'started_at' => $sample['started_at'] ?? null,
                'finished_at' => $sample['completed_at'] ?? $sample['finished_at'] ?? null,
                'harness_only' => $harnessOnly,
                'metadata' => ['native' => [
                    'cli_model' => $model,
                    'native_agent' => 'inspect',
                    'source_repo' => 'inspect_evals',
                    'task' => $native['eval']['task'] ?? null,
                ]],
            ];
        }

        return $receipts;
    }

    /** @param array<string, mixed> $native */
    private function inferTaskType(string $sampleId, array $native): string
    {
        $task = (string) ($native['eval']['task'] ?? $native['eval']['task_display_name'] ?? $sampleId);
        if (str_contains($task, 'gaia') || str_contains($sampleId, 'gaia')) {
            return 'tool_use_function_calling';
        }
        // gsm8k é matemática passo a passo, não código: rotular 'coding_patch'
        // punha raciocínio dentro da capacidade Programação e mentia sobre a tarefa.
        if (str_contains($task, 'gsm8k') || str_contains($sampleId, 'gsm8k')) {
            return 'math_reasoning';
        }

        return 'coding_patch';
    }
}
