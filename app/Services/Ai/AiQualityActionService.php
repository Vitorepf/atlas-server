<?php

namespace App\Services\Ai;

use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiTrace;
use App\Services\AuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiQualityActionService
{
    public function __construct(
        private readonly AiGatewayService $gateway,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return Collection<int,AiQualityAction>
     */
    public function planFor(AiTrace $trace, AiQualityEvaluation $evaluation, bool $autoRun = true): Collection
    {
        if (! Schema::hasTable('ai_quality_actions')) {
            return collect();
        }

        $trace = $trace->fresh(['qualityEvaluation']) ?: $trace;
        $flags = collect($evaluation->flags ?? [])->pluck('code')->filter()->values()->all();
        if ($evaluation->status === 'passed' && $flags === []) {
            return collect();
        }

        $plans = $this->actionPlans($trace, $evaluation, $flags);
        $actions = collect();

        foreach ($plans as $plan) {
            $dedupeKey = $this->dedupeKey($trace, $plan['action_type']);
            $action = AiQualityAction::query()->where('dedupe_key', $dedupeKey)->first();
            $created = false;

            if (! $action) {
                $action = AiQualityAction::query()->create([
                    'dedupe_key' => $dedupeKey,
                    'evaluation_id' => $evaluation->id,
                    'trace_id' => $trace->id,
                    'thread_id' => $trace->thread_id,
                    'session_id' => $trace->session_id,
                    'action_type' => $plan['action_type'],
                    'status' => $plan['status'],
                    'priority' => $plan['priority'],
                    'reason' => $plan['reason'],
                    'flags' => $flags,
                    'payload' => $plan['payload'],
                    'result' => [],
                    'error_message' => null,
                    'completed_at' => $plan['status'] === 'blocked' ? now() : null,
                ]);
                $created = true;
            } else {
                $action->update([
                    'evaluation_id' => $evaluation->id,
                    'reason' => $plan['reason'],
                    'flags' => $flags,
                    'payload' => array_merge($action->payload ?? [], $plan['payload']),
                ]);
            }

            if ($autoRun && $created && ($plan['auto_run'] ?? false) === true) {
                $action = $this->run($action, $trace);
            }

            $actions->push($action->refresh());
        }

        $this->writeTraceActionSummary($trace, $actions);

        return $actions;
    }

    public function run(AiQualityAction $action, ?AiTrace $trace = null): AiQualityAction
    {
        if (! in_array($action->status, ['queued', 'failed'], true)) {
            return $action;
        }

        $trace = $trace ?: $action->trace()->first();
        if (! $trace) {
            $action->update([
                'status' => 'failed',
                'error_message' => 'Trace original não encontrada para ação de qualidade.',
                'completed_at' => now(),
            ]);

            return $action->refresh();
        }

        $action->update(['status' => 'running']);

        try {
            $remediationTrace = $this->enqueueRemediation($action, $trace);
            $action->update([
                'status' => 'running',
                'remediation_trace_id' => $remediationTrace->id,
                'result' => [
                    'remediation_trace_id' => $remediationTrace->id,
                    'provider' => $remediationTrace->provider,
                    'status' => $remediationTrace->status,
                ],
                'completed_at' => null,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            $action->update([
                'status' => 'failed',
                'error_message' => Str::limit($exception->getMessage(), 2000, '...'),
                'completed_at' => now(),
            ]);
        }

        $this->audit->record('ai_quality_action_executed', [
            'subject_type' => 'ai_quality_action',
            'subject_id' => $action->id,
            'severity' => $action->refresh()->status === 'failed' ? 'warning' : 'info',
            'summary' => "Acao de qualidade {$action->action_type} executada.",
            'evidence' => [
                'action_type' => $action->action_type,
                'status' => $action->status,
                'trace_id' => $action->trace_id,
                'remediation_trace_id' => $action->remediation_trace_id,
            ],
            'privacy' => $this->privacyFromTrace($trace),
            'refs' => [
                'trace_id' => $action->trace_id,
                'remediation_trace_id' => $action->remediation_trace_id,
                'quality_action_id' => $action->id,
            ],
        ]);

        return $action->refresh();
    }

    /**
     * @param  array<int,string>  $flags
     * @return array<int,array<string,mixed>>
     */
    private function actionPlans(AiTrace $trace, AiQualityEvaluation $evaluation, array $flags): array
    {
        $depth = (int) data_get($trace->metadata, 'quality_loop.depth', 0);
        $maxDepth = (int) config('atlas.ai.quality_auto_remediation_max_depth', 1);

        if ($depth >= $maxDepth || data_get($trace->metadata, 'quality_loop.disable_auto_actions') === true) {
            return [$this->operatorReviewPlan($trace, $evaluation, 'Limite de auto-remediação atingido.')];
        }

        if (in_array('lost_continuity', $flags, true)) {
            return [
                $this->autoPlan(
                    trace: $trace,
                    actionType: 'retry_with_continuity',
                    priority: 0,
                    reason: 'Provider perdeu continuidade apesar de haver contexto disponível.',
                    provider: 'claude_cli',
                ),
            ];
        }

        if (array_intersect($flags, ['internal_context_leak', 'likely_oververbose_or_code_heavy', 'provider_identity_leak'])) {
            return [
                $this->autoPlan(
                    trace: $trace,
                    actionType: 'rewrite_for_operator',
                    priority: 5,
                    reason: 'Resposta precisa ser reescrita antes de virar saída canônica do Atlas.',
                    provider: $this->repairProvider($trace),
                ),
                ...$this->verificationPlanIfNeeded($trace, $flags),
            ];
        }

        if ($evaluation->score < 55 && $trace->provider !== 'claude_codex') {
            return [
                $this->autoPlan(
                    trace: $trace,
                    actionType: 'escalate_to_council',
                    priority: 10,
                    reason: 'Score baixo exige reparo automático pelo Claude Sonnet.',
                    provider: 'claude_cli',
                ),
                ...$this->verificationPlanIfNeeded($trace, $flags),
            ];
        }

        return [
            ...$this->verificationPlanIfNeeded($trace, $flags),
            $this->operatorReviewPlan($trace, $evaluation, 'Resposta marcada para revisão operacional.'),
        ];
    }

    private function autoPlan(AiTrace $trace, string $actionType, int $priority, string $reason, string $provider): array
    {
        return [
            'action_type' => $actionType,
            'status' => 'queued',
            'priority' => $priority,
            'reason' => $reason,
            'auto_run' => true,
            'payload' => [
                'provider' => $provider,
                'mode' => 'quality_repair',
                'source_provider' => $trace->provider,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $flags
     * @return array<int,array<string,mixed>>
     */
    private function verificationPlanIfNeeded(AiTrace $trace, array $flags): array
    {
        if (! in_array('verification_missing', $flags, true)) {
            return [];
        }

        return [[
            'action_type' => 'request_verification',
            'status' => 'blocked',
            'priority' => 20,
            'reason' => 'Tarefa técnica terminou sem verificação explícita.',
            'auto_run' => false,
            'payload' => [
                'required' => true,
                'workspace' => data_get($trace->metadata, 'context_pack.surface.workspace'),
                'recommended_next_step' => 'Rodar validação ou declarar objetivamente por que não foi possível validar.',
            ],
        ]];
    }

    private function operatorReviewPlan(AiTrace $trace, AiQualityEvaluation $evaluation, string $reason): array
    {
        return [
            'action_type' => 'operator_review',
            'status' => 'blocked',
            'priority' => 50,
            'reason' => $reason,
            'auto_run' => false,
            'payload' => [
                'score' => $evaluation->score,
                'quality_status' => $evaluation->status,
                'source_provider' => $trace->provider,
            ],
        ];
    }

    private function enqueueRemediation(AiQualityAction $action, AiTrace $trace): AiTrace
    {
        $provider = (string) data_get($action->payload, 'provider', $this->repairProvider($trace));
        $depth = (int) data_get($trace->metadata, 'quality_loop.depth', 0);
        $input = $this->remediationInput($action, $trace);

        return $this->gateway->enqueueInteraction($input, [
            'source_type' => 'system',
            'thread_id' => $trace->thread_id,
            'session_id' => $trace->session_id,
            'provider' => $provider,
            'agent_slug' => $this->agentSlugForAction($action, $trace),
            'priority' => max(0, (int) $action->priority - 1),
            'include_semantic_context' => true,
            'payload' => [
                'app_surface' => data_get($trace->metadata, 'context_pack.surface.kind', 'atlas_quality_loop'),
                'atlas_workflow_mode' => 'quality_repair',
                'workspace' => data_get($trace->metadata, 'context_pack.surface.workspace'),
                'execution_policy' => $provider === 'claude_codex' ? 'dual_review' : null,
                'council_providers' => $provider === 'claude_codex' ? ['claude_cli', 'codex_cli'] : null,
                'privacy' => $this->privacyFromTrace($trace),
                'quality_loop' => [
                    'depth' => $depth + 1,
                    'parent_trace_id' => $trace->id,
                    'quality_action_id' => $action->id,
                    'action_type' => $action->action_type,
                ],
            ],
        ]);
    }

    private function remediationInput(AiQualityAction $action, AiTrace $trace): string
    {
        $originalInput = trim((string) $trace->operator_input);
        $badResponse = trim((string) $trace->response_text);
        $flags = collect($action->flags ?? [])->implode(', ');

        return match ($action->action_type) {
            'retry_with_continuity' => <<<TXT
O Atlas detectou uma falha grave de continuidade na resposta anterior.

Pedido original do operador:
{$originalInput}

Resposta anterior com problema:
{$badResponse}

Falhas detectadas: {$flags}

Refaça a resposta como Atlas, usando silenciosamente o contexto conversacional, compactações e handoffs disponíveis no prompt. Não diga que não há contexto se houver contexto. Não exponha IDs, traces, context_pack ou detalhes internos. Seja direto e útil.
TXT,
            'escalate_to_council' => <<<TXT
O Atlas marcou a resposta anterior como insuficiente e precisa de uma segunda leitura em conselho.

Pedido original do operador:
{$originalInput}

Resposta anterior:
{$badResponse}

Falhas detectadas: {$flags}

Faça uma resposta final melhor, apontando decisão operacional, riscos reais e verificação necessária. Não despeje código salvo se o operador pedir explicitamente.
TXT,
            default => <<<TXT
Reescreva a resposta abaixo como saída final do Atlas para o operador.

Pedido original:
{$originalInput}

Resposta anterior:
{$badResponse}

Falhas detectadas: {$flags}

Regras:
- remova qualquer vazamento de contexto interno, IDs, traces, prompts, context_pack ou provider;
- não fale como Claude, Codex ou ChatGPT;
- seja claro, curto e acionável;
- não despeje código; cite arquivos e validações quando relevante;
- preserve apenas o conteúdo útil e correto.
TXT,
        };
    }

    private function repairProvider(AiTrace $trace): string
    {
        return 'claude_cli';
    }

    private function agentSlugForAction(AiQualityAction $action, AiTrace $trace): string
    {
        return match ($action->action_type) {
            'rewrite_for_operator' => 'comunicador-claro',
            default => $trace->agent_slug ?: 'orquestrador',
        };
    }

    /**
     * @param  Collection<int,AiQualityAction>  $actions
     */
    private function writeTraceActionSummary(AiTrace $trace, Collection $actions): void
    {
        $metadata = is_array($trace->metadata) ? $trace->metadata : [];
        $metadata['quality_actions'] = $actions
            ->map(fn (AiQualityAction $action): array => [
                'id' => $action->id,
                'action_type' => $action->action_type,
                'status' => $action->status,
                'remediation_trace_id' => $action->remediation_trace_id,
            ])
            ->values()
            ->all();

        $trace->update(['metadata' => $metadata]);
    }

    private function dedupeKey(AiTrace $trace, string $actionType): string
    {
        return "trace:{$trace->id}:{$actionType}";
    }

    private function privacyFromTrace(AiTrace $trace): array
    {
        $privacy = data_get($trace->metadata, 'privacy');

        return is_array($privacy) ? $privacy : ['sensitivity' => 'normal'];
    }
}
