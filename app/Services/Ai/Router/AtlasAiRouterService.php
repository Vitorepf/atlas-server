<?php

declare(strict_types=1);

namespace App\Services\Ai\Router;

use App\Services\Ai\Compounding\AtlasCompoundingMemoryService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

final class AtlasAiRouterService
{
    private AtlasAiIntentKernelService $intentKernel;

    public function __construct(?AtlasAiIntentKernelService $intentKernel = null)
    {
        $this->intentKernel = $intentKernel ?? new AtlasAiIntentKernelService;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function decide(array $data): AtlasAiRouterDecision
    {
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $surfaceId = $this->string(data_get($payload, 'surface_id'))
            ?? $this->string(data_get($payload, 'app_surface'))
            ?? (string) ($data['source_type'] ?? 'app');
        $rawIntent = $this->string($data['input_text'] ?? null)
            ?? $this->string(data_get($payload, 'raw_intent'))
            ?? '';
        $workspace = $this->workspace($payload);
        $slash = $this->slashCommand($rawIntent, $payload);
        $task = $this->normalizedTask($payload);
        $mode = $this->normalizedMode($payload);
        $attachments = is_array(data_get($payload, 'attachments')) ? (array) data_get($payload, 'attachments') : [];
        $intent = $this->intentKernel->classify($data);

        if ($slash !== null) {
            return $this->decision(
                flowId: $slash['flow_id'],
                origin: 'slash_command',
                command: $slash['command_intent'],
                reason: 'slash_command:'.$slash['slash'],
                confidence: 'confirmed',
                surfaceId: $surfaceId,
                workspace: $workspace,
                rawIntent: $rawIntent,
                alternatives: [],
            );
        }

        // LEI DA ESCOLHA EXPLÍCITA: quando o operador seleciona um domínio/
        // flow no picker (selection_source explicit_*), a escolha É a rota —
        // nenhuma heurística, kernel ou Hyperflow pode sobrepor. Antes deste
        // lane o patch do domain-catalog (payload.flow_id) era hint que o
        // router IGNORAVA: escolher "Finanças" no desktop só funcionava se o
        // Hyperflow reclassificasse igual. "Se eu pedir, ele precisa entender
        // sem erros" — pedido explícito não passa por classificador.
        $explicitFlow = $this->explicitOperatorFlow($payload);
        if ($explicitFlow !== null) {
            return $this->decision(
                flowId: $explicitFlow,
                origin: 'operator_override',
                command: str_starts_with($explicitFlow, 'atlas_') ? substr($explicitFlow, strlen('atlas_')) : $explicitFlow,
                reason: 'explicit_domain_catalog_selection',
                confidence: 'confirmed',
                surfaceId: $surfaceId,
                workspace: $workspace,
                rawIntent: $rawIntent,
                alternatives: [],
                intent: $intent,
            );
        }

        if ($surfaceId === 'atlas_code') {
            return $this->decision(
                flowId: AtlasAiRouterDecision::FLOW_FORGE,
                origin: 'operator_override',
                command: 'forge',
                reason: 'atlas_code_surface_requires_forge',
                confidence: 'confirmed',
                surfaceId: $surfaceId,
                workspace: $workspace,
                rawIntent: $rawIntent,
                alternatives: [AtlasAiRouterDecision::FLOW_DEV],
            );
        }

        if ($mode === 'programming' && $task === 'plan') {
            return $this->decision(
                flowId: AtlasAiRouterDecision::FLOW_PLAN,
                origin: 'operator_override',
                command: 'plan',
                reason: $workspace !== null ? 'programming_plan_with_workspace' : 'programming_plan_without_workspace',
                confidence: 'confirmed',
                surfaceId: $surfaceId,
                workspace: $workspace,
                rawIntent: $rawIntent,
                alternatives: $workspace !== null ? [AtlasAiRouterDecision::FLOW_DEV] : [AtlasAiRouterDecision::FLOW_RESEARCH],
                intent: $intent,
            );
        }

        if ($mode === 'programming' && in_array($task, ['dev', 'direct', 'debug', 'review'], true) && $workspace !== null) {
            $flow = match ($task) {
                'debug' => AtlasAiRouterDecision::FLOW_DEBUG,
                'review' => AtlasAiRouterDecision::FLOW_REVIEW,
                default => AtlasAiRouterDecision::FLOW_DEV,
            };

            return $this->decision(
                flowId: $flow,
                origin: 'operator_override',
                command: $task === 'plan' ? 'plan' : $task,
                reason: 'programming_mode_with_workspace',
                confidence: 'confirmed',
                surfaceId: $surfaceId,
                workspace: $workspace,
                rawIntent: $rawIntent,
                alternatives: $flow === AtlasAiRouterDecision::FLOW_DEV ? [] : [AtlasAiRouterDecision::FLOW_DEV],
                intent: $intent,
            );
        }

        // Hyperflow organ: the RouterRuntime chain (intent → domain → flow)
        // already decided on this payload — AtlasHyperflowEntryService runs
        // first in AiInteractionController. Consume that decision instead of
        // re-deriving one from keyword heuristics, so there is ONE auto-routing
        // brain and the canon-only flows (finance/marketing/strategy/cyber/
        // automation/personal_development) become reachable downstream.
        // Operator-explicit branches above (slash, atlas_code surface,
        // programming mode) still win. atlas_conversation is NOT consumed:
        // it is the RouterRuntime fallback and the legacy heuristics below see
        // signals the intent kernel does not (diff/PR attachments), so they
        // keep the final word before falling back to conversation themselves.
        $hyperflowDecision = $this->hyperflowRuntimeDecision($payload, $surfaceId, $workspace, $rawIntent, $intent);
        if ($hyperflowDecision !== null) {
            return $hyperflowDecision;
        }

        $haystack = Str::lower($rawIntent."\n".$this->attachmentText($attachments));

        if ($this->hasDiffOrPr($attachments, $haystack)) {
            return $this->decision(AtlasAiRouterDecision::FLOW_REVIEW, 'router_auto', 'review', 'diff_or_pr_attachment', 'strong', $surfaceId, $workspace, $rawIntent, [AtlasAiRouterDecision::FLOW_DEV], $intent);
        }

        if ($this->containsAny($haystack, ['stack trace', 'stacktrace', 'traceback', 'debug ', 'debugue', 'logs', 'log ', 'erro em producao', 'erro em produção', 'exception', 'observability'])) {
            return $this->decision(
                AtlasAiRouterDecision::FLOW_DEBUG,
                'router_auto',
                'debug',
                'logs_or_stacktrace_signal',
                'strong',
                $surfaceId,
                $workspace,
                $rawIntent,
                $workspace !== null ? [AtlasAiRouterDecision::FLOW_DEV] : [AtlasAiRouterDecision::FLOW_RESEARCH],
                $intent,
            );
        }

        if ($this->containsAny($haystack, ['obra ', 'multi-semana', 'multi semana', 'sistema inteiro', 'sistema todo', 'app inteiro', 'one shot enterprise', 'one-shot enterprise'])) {
            return $this->decision(AtlasAiRouterDecision::FLOW_FORGE, 'router_auto', 'promote_to_forge', 'obra_or_enterprise_scope_signal', 'strong', $surfaceId, $workspace, $rawIntent, [AtlasAiRouterDecision::FLOW_DEV], $intent);
        }

        if ($this->containsAny($haystack, ['planeje', 'planejar', 'plano', 'plan ', 'planning', 'roadmap', 'estruture', 'arquitetura antes', 'antes de implementar'])) {
            return $this->decision(
                AtlasAiRouterDecision::FLOW_PLAN,
                'router_auto',
                'plan',
                'plan_like_intent',
                'strong',
                $surfaceId,
                $workspace,
                $rawIntent,
                $workspace !== null ? [AtlasAiRouterDecision::FLOW_DEV] : [AtlasAiRouterDecision::FLOW_RESEARCH],
                $intent,
            );
        }

        if ($this->isPatchLike($haystack)) {
            if ($workspace !== null) {
                return $this->decision(AtlasAiRouterDecision::FLOW_DEV, 'router_auto', 'patch', 'patch_like_with_workspace', 'strong', $surfaceId, $workspace, $rawIntent, [], $intent);
            }

            return $this->decision(AtlasAiRouterDecision::FLOW_PLAN, 'router_auto', 'plan', 'patch_like_without_workspace_requires_plan', 'medium', $surfaceId, null, $rawIntent, [AtlasAiRouterDecision::FLOW_RESEARCH], $intent);
        }

        if ($this->containsAny($haystack, ['explique', 'explica', 'explain', 'resuma', 'summarize'])) {
            return $this->decision(
                AtlasAiRouterDecision::FLOW_EXPLAIN,
                'router_auto',
                'explain',
                $workspace !== null ? 'explain_like_workspace_bound' : 'explain_like_general',
                'strong',
                $surfaceId,
                $workspace,
                $rawIntent,
                $workspace !== null ? [AtlasAiRouterDecision::FLOW_REVIEW] : [AtlasAiRouterDecision::FLOW_CONVERSATION],
                $intent,
            );
        }

        if ($this->containsAny($haystack, ['pesquisa', 'pesquise', 'pesquisar', 'research', 'fontes', 'referencias', 'referências', 'estado da arte', 'como funciona', 'how does', 'difference between', 'qual a diferença'])) {
            return $this->decision(AtlasAiRouterDecision::FLOW_RESEARCH, 'router_auto', 'research', 'research_like_intent', 'strong', $surfaceId, $workspace, $rawIntent, [AtlasAiRouterDecision::FLOW_CONVERSATION], $intent);
        }

        // Kernel-authoritative catch: quando o intent kernel classificou com
        // confiança um intent de ENGENHARIA que as heurísticas legadas acima
        // não têm keyword para pegar, a classificação vira rota em vez de cair
        // em conversa. Incidente 02/07: "realiza uma verificação rigorosa do
        // fluxo atlas dev" (workspace aberto) caiu em chat Hermes sem tools e
        // o modelo FINGIU executar código. Só classes de engenharia, só com
        // confiança strong — conversation/low continua no fallback.
        $kernelClass = (string) ($intent['intent_class'] ?? '');
        $kernelConfident = ($intent['confidence'] ?? '') === 'strong';
        if ($kernelConfident) {
            $kernelFlow = match ($kernelClass) {
                'review' => AtlasAiRouterDecision::FLOW_REVIEW,
                'debug' => AtlasAiRouterDecision::FLOW_DEBUG,
                'dev' => $workspace !== null ? AtlasAiRouterDecision::FLOW_DEV : null,
                'plan' => AtlasAiRouterDecision::FLOW_PLAN,
                'research' => AtlasAiRouterDecision::FLOW_RESEARCH,
                'explain' => AtlasAiRouterDecision::FLOW_EXPLAIN,
                default => null,
            };
            if ($kernelFlow !== null) {
                return $this->decision(
                    $kernelFlow,
                    'router_auto',
                    $kernelClass,
                    'intent_kernel_class:'.$kernelClass,
                    'strong',
                    $surfaceId,
                    $workspace,
                    $rawIntent,
                    $workspace !== null ? [AtlasAiRouterDecision::FLOW_DEV] : [AtlasAiRouterDecision::FLOW_CONVERSATION],
                    $intent,
                );
            }
        }

        // ÁRBITRO DO KERNEL CANÔNICO: antes de cair em conversa, consulta a
        // classificação do IntentKernelService (Hyperflow) que já viajou no
        // envelope — 13 tipos, cobre finanças/marketing/cyber/estratégia/
        // pessoal/automação que as heurísticas legadas NÃO têm. Conversa deixa
        // de ser ralo de "nenhuma keyword casou": só é rota quando o kernel
        // canônico classificou conversation/unknown de verdade. Fecha o buraco
        // "Hyperflow indisponível ⇒ domínios não-engenharia nunca roteiam".
        $arbitrated = $this->canonicalIntentArbiter($payload, $surfaceId, $workspace, $rawIntent, $intent);
        if ($arbitrated !== null) {
            return $arbitrated;
        }

        // INVERSÃO DO FALLBACK (03/07, decisão do operador): frase que nenhuma
        // regra reconheceu NÃO cai mais em "conversa" — com workspace presente
        // vai para o GATEWAY AGÊNTICO (mesmo desenho do Claude Code/Codex:
        // não existe router; o modelo executor lê a mensagem com ferramentas
        // na mão e ELE decide se conversa ou trabalha). O léxico vira só
        // atalho de confiança-forte; nunca mais um ralo. Sem workspace,
        // conversa segue sendo o desfecho honesto.
        $fallbackDecision = $workspace !== null ? 'agentic_gateway_default' : 'fallback_conversation';

        // LEDGER DE CANDIDATOS A MISS: toda queda no fallback com intent
        // não-trivial fica registrada (append-only, local, zero provider,
        // fail-open) — colheita para novos atalhos do kernel e para o teste
        // congelado de frases reais. HERMÉTICO: testes nunca escrevem no
        // ledger vivo (fixtures poluiriam a colheita).
        if (! app()->runningUnitTests() && mb_strlen(trim($rawIntent)) > 12) {
            try {
                \App\Services\Ai\Support\AppendOnlyJsonlStore::append(
                    storage_path('atlas/router/misroute_candidates.jsonl'),
                    [
                        'schema_version' => 'atlas.router.misroute_candidate.v1',
                        'recorded_at' => now()->toIso8601String(),
                        'surface_id' => $surfaceId,
                        'intent' => mb_substr(trim($rawIntent), 0, 500),
                        'decision' => $fallbackDecision,
                    ],
                );
            } catch (\Throwable) {
                // fail-open: registrar candidato nunca bloqueia o roteamento
            }
        }

        if ($workspace !== null) {
            // Review agêntico read-only: o AiInteractionController mapeia
            // atlas_review+workspace → atlas_mode=programming/routing_task=
            // review (AiWorker agêntico com cwd=workspace, sandbox read).
            // Papo? O modelo responde. Trabalho? Investiga com contexto real.
            // Escrita governada continua exigindo o pipeline Dev (atalho de
            // confiança-forte ou picker explícito) — o gateway não edita.
            return $this->decision(AtlasAiRouterDecision::FLOW_REVIEW, 'router_auto', 'review', 'agentic_gateway_default', 'low', $surfaceId, $workspace, $rawIntent, [AtlasAiRouterDecision::FLOW_CONVERSATION], $intent);
        }

        return $this->decision(AtlasAiRouterDecision::FLOW_CONVERSATION, 'router_auto', 'converse', 'fallback_conversation', 'low', $surfaceId, $workspace, $rawIntent, [], $intent);
    }

    /**
     * Lei da escolha explícita: flow selecionado pelo operador no picker
     * (domain catalog selection_source explicit_flow/explicit_domain, ou
     * payload.flow_id validado) vira rota confirmada. Retorna null quando a
     * seleção é ux_mapping/ausente — aí a autodetecção decide.
     *
     * @param  array<string,mixed>  $payload
     */
    private function explicitOperatorFlow(array $payload): ?string
    {
        $selection = is_array($payload['domain_catalog_selection'] ?? null) ? $payload['domain_catalog_selection'] : [];
        $source = (string) ($selection['selection_source'] ?? '');
        if (! in_array($source, ['explicit_flow', 'explicit_domain'], true)) {
            return null;
        }

        $flowId = $this->string($selection['flow_id'] ?? null) ?? $this->string($payload['flow_id'] ?? null);
        if ($flowId === null || ! in_array($flowId, AtlasAiRouterDecision::FLOWS, true)) {
            return null;
        }

        return $flowId;
    }

    /**
     * Árbitro do kernel canônico para a cauda ambígua: consome o intent do
     * envelope Hyperflow (intent.type + confidence) quando NENHUMA heurística
     * legada casou. Mapeia intent→flow pelo canon; conversation/unknown
     * retornam null (fallback conversa é legítimo aí). Threshold baixo de
     * propósito: para pedido de TRABALHO, rotear ao domínio com confiança
     * média-baixa erra menos que cair num chat sem tools.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $intent
     */
    private function canonicalIntentArbiter(
        array $payload,
        string $surfaceId,
        ?string $workspace,
        string $rawIntent,
        array $intent,
    ): ?AtlasAiRouterDecision {
        $envelope = is_array($payload['hyperflow_runtime'] ?? null) ? $payload['hyperflow_runtime'] : [];
        $kernelIntent = is_array($envelope['intent'] ?? null) ? $envelope['intent'] : [];
        $type = (string) ($kernelIntent['type'] ?? '');
        $confidence = (float) ($kernelIntent['confidence'] ?? 0.0);

        // Envelope ausente (Hyperflow desligado/erro): roda o MESMO kernel
        // canônico em forma pura (sem persistência) — fonte única de verdade
        // independente do pipeline estar vivo.
        if ($type === '' && $rawIntent !== '') {
            try {
                $shape = (new \App\Services\Ai\RouterRuntime\IntentKernelService)->classifyShape($rawIntent);
                $type = (string) $shape['intent_type'];
                $confidence = (float) $shape['confidence'];
            } catch (\Throwable) {
                return null;
            }
        }

        if ($type === '' || $type === 'conversation' || $type === 'unknown' || $confidence < 0.35) {
            return null;
        }

        $flowId = \App\Services\Ai\RouterRuntime\RouterRuntimeCanon::INTENT_TO_FLOW[$type] ?? null;
        if ($flowId === null || $flowId === AtlasAiRouterDecision::FLOW_CONVERSATION) {
            return null;
        }

        // Escrita de código sem workspace não tem onde editar: degrade honesto
        // para plan (mesmo contrato da heurística patch-like legada).
        if ($flowId === AtlasAiRouterDecision::FLOW_DEV && $workspace === null) {
            $flowId = AtlasAiRouterDecision::FLOW_PLAN;
        }

        return $this->decision(
            flowId: $flowId,
            origin: 'router_auto',
            command: str_starts_with($flowId, 'atlas_') ? substr($flowId, strlen('atlas_')) : $flowId,
            reason: 'canonical_intent_arbiter:'.$type,
            confidence: $confidence >= 0.75 ? 'strong' : 'medium',
            surfaceId: $surfaceId,
            workspace: $workspace,
            rawIntent: $rawIntent,
            alternatives: [AtlasAiRouterDecision::FLOW_CONVERSATION],
            intent: $intent,
        );
    }

    /**
     * Consume the RouterRuntime/Hyperflow flow decision carried on the
     * payload. Returns null (keyword heuristics run) unless the envelope is
     * present with status=ready, its flow is a known non-conversation flow,
     * and the kill-switch is on.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $intent
     */
    private function hyperflowRuntimeDecision(
        array $payload,
        string $surfaceId,
        ?string $workspace,
        string $rawIntent,
        array $intent,
    ): ?AtlasAiRouterDecision {
        try {
            $enabled = (bool) config('atlas.ai.router.consume_hyperflow_runtime', true);
        } catch (\Throwable) {
            $enabled = true;
        }
        if (! $enabled) {
            return null;
        }

        $envelope = $payload['hyperflow_runtime'] ?? null;
        if (! is_array($envelope) || ($envelope['status'] ?? null) !== 'ready') {
            return null;
        }

        $flowId = $this->string($envelope['flow_id'] ?? null);
        if ($flowId === null
            || $flowId === AtlasAiRouterDecision::FLOW_CONVERSATION
            || ! in_array($flowId, AtlasAiRouterDecision::FLOWS, true)
        ) {
            return null;
        }

        $confidence = (float) ($envelope['routing_confidence'] ?? 0.0);
        $alternatives = array_values(array_filter(
            array_map($this->string(...), (array) ($envelope['fallback_flows'] ?? [])),
            static fn (?string $flow): bool => $flow !== null
                && $flow !== $flowId
                && in_array($flow, AtlasAiRouterDecision::FLOWS, true),
        ));

        return $this->decision(
            flowId: $flowId,
            origin: 'router_runtime',
            command: str_starts_with($flowId, 'atlas_') ? substr($flowId, strlen('atlas_')) : $flowId,
            reason: 'hyperflow_runtime_flow_decision',
            confidence: $confidence >= 0.75 ? 'strong' : ($confidence >= 0.45 ? 'medium' : 'low'),
            surfaceId: $surfaceId,
            workspace: $workspace,
            rawIntent: $rawIntent,
            alternatives: $alternatives,
            intent: $intent,
        );
    }

    private function decision(string $flowId, string $origin, string $command, string $reason, string $confidence, string $surfaceId, ?string $workspace, string $rawIntent, array $alternatives, array $intent = []): AtlasAiRouterDecision
    {
        $compoundingMemories = $this->approvedCompoundingMemories($flowId);

        return new AtlasAiRouterDecision(
            flowId: $flowId,
            flowOrigin: $origin,
            commandIntent: $command,
            routingReason: $reason,
            routingConfidence: $confidence,
            handoffPayload: [
                'surface_id' => $surfaceId,
                'workspace_present' => $workspace !== null,
                'workspace' => $workspace,
                'intent_summary' => Str::limit($rawIntent, 240, ''),
                'intent_kernel' => $intent,
                'compounding_memories' => $compoundingMemories,
            ],
            alternativeFlowIds: $alternatives,
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function approvedCompoundingMemories(string $flowId): array
    {
        if (! DatabaseTableAvailability::has('ai_compounding_memories')) {
            return [];
        }

        try {
            return app(AtlasCompoundingMemoryService::class)->approvedForFlow($flowId);
        } catch (\Throwable) {
            return [];
        }
    }

    private function normalizedMode(array $payload): string
    {
        $mode = $this->string(data_get($payload, 'atlas_mode')) ?? $this->string(data_get($payload, 'current_mode')) ?? $this->string(data_get($payload, 'composer_mode'));

        return match ($mode) {
            'programming', 'dev', 'programacao', 'programação' => 'programming',
            default => 'general',
        };
    }

    private function normalizedTask(array $payload): string
    {
        $task = $this->string(data_get($payload, 'routing_task')) ?? $this->string(data_get($payload, 'composer_task')) ?? 'direct';

        return match ($task) {
            'dev', 'plan', 'debug', 'review' => $task,
            'repair' => 'debug',
            default => 'direct',
        };
    }

    private function workspace(array $payload): ?string
    {
        return $this->string(data_get($payload, 'workspace'))
            ?? $this->string(data_get($payload, 'tool_permissions.workspace'))
            ?? $this->string(data_get($payload, 'forge_workspace.workspace_path'));
    }

    private function slashCommand(string $rawIntent, array $payload): ?array
    {
        $slash = $this->string(data_get($payload, 'slash_command'));
        if ($slash === null && preg_match('/^\\s*\\/(dev|research|explain|debug|review|plan|forge|chat)\\b/i', $rawIntent, $matches)) {
            $slash = '/'.strtolower($matches[1]);
        }
        if ($slash === null) {
            return null;
        }

        return match (strtolower($slash)) {
            '/dev' => ['slash' => '/dev', 'flow_id' => AtlasAiRouterDecision::FLOW_DEV, 'command_intent' => 'dev'],
            '/research' => ['slash' => '/research', 'flow_id' => AtlasAiRouterDecision::FLOW_RESEARCH, 'command_intent' => 'research'],
            '/explain' => ['slash' => '/explain', 'flow_id' => AtlasAiRouterDecision::FLOW_EXPLAIN, 'command_intent' => 'explain'],
            '/debug' => ['slash' => '/debug', 'flow_id' => AtlasAiRouterDecision::FLOW_DEBUG, 'command_intent' => 'debug'],
            '/review' => ['slash' => '/review', 'flow_id' => AtlasAiRouterDecision::FLOW_REVIEW, 'command_intent' => 'review'],
            '/plan' => ['slash' => '/plan', 'flow_id' => AtlasAiRouterDecision::FLOW_PLAN, 'command_intent' => 'plan'],
            '/forge' => ['slash' => '/forge', 'flow_id' => AtlasAiRouterDecision::FLOW_FORGE, 'command_intent' => 'forge'],
            '/chat' => ['slash' => '/chat', 'flow_id' => AtlasAiRouterDecision::FLOW_CONVERSATION, 'command_intent' => 'converse'],
            default => null,
        };
    }

    private function isPatchLike(string $haystack): bool
    {
        return $this->containsAny($haystack, ['implemente', 'implementa', 'corrija', 'corrigir', 'refatore', 'refactor', 'mude ', 'altere ', 'crie ', 'adicione ', 'fix ', 'implement ', 'patch']);
    }

    private function hasDiffOrPr(array $attachments, string $haystack): bool
    {
        return $this->containsAny($haystack, ['diff', 'pull request', ' pr ', 'review do diff'])
            || collect($attachments)->contains(fn (mixed $attachment): bool => is_array($attachment)
                && $this->containsAny(Str::lower((string) ($attachment['kind'] ?? '').' '.(string) ($attachment['name'] ?? '')), ['diff', 'patch', 'pull request']));
    }

    private function attachmentText(array $attachments): string
    {
        return collect($attachments)
            ->filter(fn (mixed $attachment): bool => is_array($attachment))
            ->map(fn (array $attachment): string => (string) ($attachment['kind'] ?? '').' '.(string) ($attachment['name'] ?? ''))
            ->implode("\n");
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
