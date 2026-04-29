<?php

namespace App\Services\Ai;

use App\Models\AiJob;
use App\Models\AiTrace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AiGatewayService
{
    private const COUNCIL_PROVIDERS = ['claude_cli', 'codex_cli'];

    public function __construct(private readonly AiPromptBuilder $prompts) {}

    public function enqueueInteraction(string $input, array $options = []): AiTrace
    {
        if (! config('atlas.ai.enabled')) {
            throw new RuntimeException('Atlas AI is disabled.');
        }

        $input = trim($input);
        if ($input === '') {
            throw new RuntimeException('AI input cannot be empty.');
        }

        $prompt = $this->prompts->build($input, $options);
        if ($this->shouldRunCouncil($options)) {
            return $this->enqueueCouncilInteraction($input, $options, $prompt);
        }

        $provider = (string) ($options['provider'] ?? config('atlas.ai.default_provider', 'claude_cli'));
        $model = $prompt->model ?: (is_string($options['model'] ?? null) ? $options['model'] : null);
        $now = now();

        return DB::transaction(function () use ($input, $options, $prompt, $provider, $model, $now): AiTrace {
            $trace = AiTrace::query()->create([
                'trace_key' => 'trace_'.Str::orderedUuid()->toString(),
                'source_type' => $options['source_type'] ?? 'app',
                'source_id' => $options['source_id'] ?? null,
                'status' => 'queued',
                'operator_input' => $input,
                'intent' => $prompt->intent,
                'agent_slug' => $prompt->agentSlug,
                'provider' => $provider,
                'model' => $model,
                'skill_versions' => $prompt->skillVersions,
                'context_refs' => $prompt->contextRefs,
                'prompt_hash' => hash('sha256', $prompt->prompt),
                'metadata' => [
                    'mode' => $options['mode'] ?? 'async',
                ],
            ]);

            AiJob::query()->create([
                'trace_id' => $trace->id,
                'client_id' => $options['client_id'] ?? null,
                'kind' => $options['kind'] ?? 'interaction',
                'status' => 'queued',
                'priority' => (int) ($options['priority'] ?? 50),
                'agent_slug' => $prompt->agentSlug,
                'provider' => $provider,
                'model' => $model,
                'input_text' => $input,
                'prompt' => $prompt->prompt,
                'context_refs' => $prompt->contextRefs,
                'payload' => $options['payload'] ?? [],
                'available_at' => $options['available_at'] ?? $now,
                'max_attempts' => (int) ($options['max_attempts'] ?? config('atlas.ai.max_attempts', 2)),
                'timeout_seconds' => (int) ($options['timeout_seconds'] ?? config('atlas.ai.timeout_seconds', 300)),
                'metadata' => [
                    'intent' => $prompt->intent,
                    'skill_versions' => $prompt->skillVersions,
                ],
            ]);

            return $trace->load(['job', 'jobs']);
        });
    }

    public function recordFeedback(AiTrace $trace, array $data): AiTrace
    {
        $trace->update([
            'feedback_score' => $data['feedback_score'] ?? $trace->feedback_score,
            'feedback_action' => $data['feedback_action'] ?? $trace->feedback_action,
            'feedback_comment' => $data['feedback_comment'] ?? $trace->feedback_comment,
        ]);

        return $trace->refresh()->load(['job', 'jobs']);
    }

    private function enqueueCouncilInteraction(string $input, array $options, AiPrompt $prompt): AiTrace
    {
        $providers = $this->councilProviders($options);
        $now = now();

        return DB::transaction(function () use ($input, $options, $prompt, $providers, $now): AiTrace {
            $trace = AiTrace::query()->create([
                'trace_key' => 'trace_'.Str::orderedUuid()->toString(),
                'source_type' => $options['source_type'] ?? 'app',
                'source_id' => $options['source_id'] ?? null,
                'status' => 'queued',
                'operator_input' => $input,
                'intent' => $prompt->intent,
                'agent_slug' => $prompt->agentSlug,
                'provider' => 'claude_codex',
                'model' => null,
                'skill_versions' => $prompt->skillVersions,
                'context_refs' => $prompt->contextRefs,
                'prompt_hash' => hash('sha256', $prompt->prompt),
                'metadata' => [
                    'mode' => $options['mode'] ?? 'async',
                    'execution_policy' => 'dual_review',
                    'council_providers' => $providers,
                    'council_status' => 'queued',
                    'council_progress' => [
                        'queued' => count($providers),
                        'processing' => 0,
                        'succeeded' => 0,
                        'failed' => 0,
                    ],
                ],
            ]);

            foreach ($providers as $index => $provider) {
                $role = $provider === 'codex_cli' ? 'critical_reviewer' : 'primary_planner';
                AiJob::query()->create([
                    'trace_id' => $trace->id,
                    'client_id' => null,
                    'kind' => 'council',
                    'status' => 'queued',
                    'priority' => (int) ($options['priority'] ?? 50) + $index,
                    'agent_slug' => $prompt->agentSlug,
                    'provider' => $provider,
                    'model' => is_string($options['model'] ?? null) ? $options['model'] : null,
                    'input_text' => $input,
                    'prompt' => $this->councilPrompt($prompt->prompt, $provider, $role),
                    'context_refs' => $prompt->contextRefs,
                    'payload' => array_merge($options['payload'] ?? [], [
                        'execution_policy' => 'dual_review',
                        'council_role' => $role,
                        'council_provider' => $provider,
                        'council_providers' => $providers,
                    ]),
                    'available_at' => $options['available_at'] ?? $now,
                    'max_attempts' => (int) ($options['max_attempts'] ?? config('atlas.ai.max_attempts', 2)),
                    'timeout_seconds' => (int) ($options['timeout_seconds'] ?? config('atlas.ai.timeout_seconds', 300)),
                    'metadata' => [
                        'intent' => $prompt->intent,
                        'skill_versions' => $prompt->skillVersions,
                        'execution_policy' => 'dual_review',
                        'council_role' => $role,
                    ],
                ]);
            }

            return $trace->load(['job', 'jobs']);
        });
    }

    private function shouldRunCouncil(array $options): bool
    {
        return data_get($options, 'payload.execution_policy') === 'dual_review'
            || data_get($options, 'payload.requested_provider') === 'claude_codex'
            || ($options['provider'] ?? null) === 'claude_codex';
    }

    /**
     * @return array<int, string>
     */
    private function councilProviders(array $options): array
    {
        $requested = data_get($options, 'payload.council_providers');
        if (! is_array($requested)) {
            return self::COUNCIL_PROVIDERS;
        }

        $providers = array_values(array_intersect($requested, self::COUNCIL_PROVIDERS));

        return count($providers) >= 2 ? $providers : self::COUNCIL_PROVIDERS;
    }

    private function councilPrompt(string $basePrompt, string $provider, string $role): string
    {
        $roleInstruction = $role === 'critical_reviewer'
            ? 'Seu papel nesta rodada e revisar criticamente: encontre falhas, riscos, lacunas, premissas fracas, inconsistencias e pontos que o outro avaliador provavelmente deixaria passar.'
            : 'Seu papel nesta rodada e propor a leitura principal: estruture o caminho recomendado, explicite tradeoffs, ordem de execucao, criterios de verificacao e decisoes praticas.';

        $providerName = $provider === 'codex_cli' ? 'Codex' : 'Claude';

        return <<<PROMPT
{$basePrompt}

# Conselho Atlas: {$providerName}

Voce esta participando de uma rodada dupla Claude + Codex.
{$roleInstruction}

Regras desta rodada:
- Nao execute alteracoes externas.
- Nao trate sua resposta como decisao final isolada.
- Escreva para que o Atlas consiga comparar sua leitura com a do outro provedor.
- Seja especifico sobre riscos, verificacao e proximo passo.
- Se a tarefa pedir implementacao, descreva quem deveria executar e quais revisoes devem acontecer depois.

Formato recomendado:
1. Diagnostico
2. Recomendacao
3. Riscos e lacunas
4. Criterios de verificacao
5. Proximo passo
PROMPT;
    }
}
