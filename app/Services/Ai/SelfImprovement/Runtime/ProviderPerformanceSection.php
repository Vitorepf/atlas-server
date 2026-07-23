<?php

namespace App\Services\Ai\SelfImprovement\Runtime;

use App\Services\Ai\Kernel\Decision\DynamicComputeMarketAdvisor;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;

/**
 * Provider performance + Dynamic Compute Market finding family extracted VERBATIM from
 * AtlasSelfImprovementRuntime (GOD-DEBULK D3 split). The facade keeps same-signature delegators;
 * the AP-99/AP-147 scanner pins for this family were relocated to this section home
 * (invariant unchanged).
 */
class ProviderPerformanceSection
{
    public function __construct(
        private readonly ProviderPerformanceProjection $providerPerformance,
        private readonly DynamicComputeMarketAdvisor $dynamicComputeMarket,
        private readonly FilterNormalizationSection $filterNormalization,
    ) {}

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function providerPerformanceFindings(int $hours, array $filters = []): array
    {
        $report = $this->providerPerformance->reportForWindow(
            now()->subHours($hours),
            filters: $this->filterNormalization->normalizedProviderPerformanceFilters($filters),
        );

        if (! (bool) ($report['available'] ?? false) || (int) ($report['event_count'] ?? 0) === 0) {
            return [[
                'title' => 'Instrumentar Provider Performance da Fase 0',
                'category' => 'self_improvement',
                'finding' => 'Provider performance review nao encontrou eventos normalizados de provider usage na janela analisada.',
                'problem' => 'Sem Provider Usage Event normalizado, o Atlas Decide continua dependendo de health, policy e preferencias estaticas em vez de aprender empiricamente qual provider funciona melhor por dominio e tarefa.',
                'solution' => 'Garantir que PROVIDER_CALLED, PROVIDER_RETURNED e PROVIDER_FALLBACK carreguem schema atlas.provider_usage.v1 e que o projection de performance seja consultavel antes de alterar estrategia default.',
                'worth_it' => 'Vale porque Fase 0 e o ponto em que o Atlas sai do achismo de provider e passa a roteamento baseado em evidencia.',
                'best_solution_rationale' => 'Usar o Evidence Ledger como fonte evita criar tabela paralela, router paralelo ou heuristica solta dentro do Curator.',
                'alternatives' => ['Aguardar mais execucoes reais se a instrumentacao acabou de ser ativada.', 'Rodar um benchmark controlado para popular a janela inicial.'],
                'source_refs' => [],
                'confidence' => 0.76,
                'dedupe_key' => 'self-improvement:provider-performance:'.sha1('missing-provider-usage-events'),
                'metadata' => [
                    'report_available' => (bool) ($report['available'] ?? false),
                    'event_count' => (int) ($report['event_count'] ?? 0),
                    'filters' => $report['filters'] ?? [],
                    'schema_version' => $report['schema_version'] ?? null,
                ],
            ]];
        }

        $findings = [];
        $fallbackCount = (int) ($report['fallback_count'] ?? 0);
        $failureCount = (int) ($report['failure_count'] ?? 0);
        $unknownCostCount = (int) ($report['unknown_cost_count'] ?? 0);
        $successRate = $report['success_rate'];

        if ($fallbackCount > 0 || $failureCount > 0 || (is_numeric($successRate) && (float) $successRate < 0.8)) {
            $findings[] = [
                'title' => 'Revisar matriz empirica de providers',
                'category' => 'self_improvement',
                'finding' => "Provider performance encontrou {$failureCount} falha(s), {$fallbackCount} fallback(s) e success_rate ".($successRate === null ? 'desconhecido' : (string) $successRate).'.',
                'problem' => 'Falhas e fallbacks recorrentes por provider/domain/task_type indicam que a Provider Strategy Matrix pode estar escolhendo uma rota subotima ou que falta policy especifica para o tipo de tarefa.',
                'solution' => 'Comparar grupos por provider_cli + domain + task_type, separar overrides manuais de decisoes automaticas e propor ajuste revisavel na matriz somente com amostra suficiente.',
                'worth_it' => 'Vale porque melhora qualidade e desempenho sem depender de opiniao fixa sobre Claude, Codex ou Gemini.',
                'best_solution_rationale' => 'A projection usa provider usage normalizado no Evidence Ledger, preservando receipt, router decision e outcome no mesmo rastro auditavel.',
                'alternatives' => ['Manter observacao ate haver mais amostras.', 'Executar benchmark pareado antes de promover mudanca de roteamento.'],
                'available_actions' => [
                    ['id' => 'run_provider_benchmark', 'label' => 'Rodar benchmark', 'style' => 'secondary'],
                    ['id' => 'draft_model_selection_policy_patch', 'label' => 'Rascunhar policy patch', 'style' => 'primary'],
                    ['id' => 'configure_provider_cost_rates', 'label' => 'Configurar rates', 'style' => 'secondary'],
                    ['id' => 'review_patch', 'label' => 'Revisar evidencia', 'style' => 'secondary'],
                    ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
                    ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive', 'requires_confirm' => true],
                ],
                'source_refs' => collect((array) ($report['recent_events'] ?? []))
                    ->take(5)
                    ->map(fn (array $event): array => [
                        'type' => 'ledger_event',
                        'id' => $event['event_id'] ?? null,
                        'envelope_id' => $event['envelope_id'] ?? null,
                        'provider_cli' => $event['provider_cli'] ?? null,
                        'model' => $event['model'] ?? null,
                        'domain' => $event['domain'] ?? null,
                        'flow' => $event['flow'] ?? null,
                        'task_type' => $event['task_type'] ?? null,
                        'specialist_profile' => $event['specialist_profile'] ?? null,
                        'selection_mode' => $event['selection_mode'] ?? null,
                        'exit_status' => $event['exit_status'] ?? null,
                        'failure_reason' => $event['failure_reason'] ?? null,
                    ])
                    ->values()
                    ->all(),
                'confidence' => $fallbackCount > 0 ? 0.86 : 0.8,
                'dedupe_key' => 'self-improvement:provider-performance:'.sha1(json_encode($report['failure_reason_counts'] ?? [], JSON_THROW_ON_ERROR).':'.$fallbackCount.':'.$failureCount),
                'metadata' => [
                    'event_count' => $report['event_count'] ?? 0,
                    'returned_count' => $report['returned_count'] ?? 0,
                    'fallback_count' => $fallbackCount,
                    'success_count' => $report['success_count'] ?? 0,
                    'failure_count' => $failureCount,
                    'success_rate' => $successRate,
                    'average_latency_seconds' => $report['average_latency_seconds'] ?? null,
                    'average_repair_count' => $report['average_repair_count'] ?? null,
                    'total_tokens' => $report['total_tokens'] ?? 0,
                    'average_total_tokens' => $report['average_total_tokens'] ?? null,
                    'total_cost_microusd' => $report['total_cost_microusd'] ?? 0,
                    'average_cost_microusd' => $report['average_cost_microusd'] ?? null,
                    'costed_event_count' => $report['costed_event_count'] ?? 0,
                    'unknown_cost_count' => $unknownCostCount,
                    'cost_confidence_counts' => $report['cost_confidence_counts'] ?? [],
                    'cost_mode_counts' => $report['cost_mode_counts'] ?? [],
                    'provider_counts' => $report['provider_counts'] ?? [],
                    'domain_counts' => $report['domain_counts'] ?? [],
                    'task_type_counts' => $report['task_type_counts'] ?? [],
                    'specialist_profile_counts' => $report['specialist_profile_counts'] ?? [],
                    'failure_reason_counts' => $report['failure_reason_counts'] ?? [],
                    'selection_mode_counts' => $report['selection_mode_counts'] ?? [],
                    'groups' => $report['groups'] ?? [],
                    'filters' => $report['filters'] ?? [],
                    'schema_version' => 'atlas.self_improvement.provider_performance.v1',
                    'review_signal' => [
                        'status' => 'warning',
                        'severity' => $fallbackCount > 0 ? 'high' : 'medium',
                        'recommended_action' => 'open_reviewable_provider_policy_patch',
                    ],
                    'policy_patch_candidate' => [
                        'status' => 'proposal_only',
                        'target' => 'atlas_decide_model_selection_policy',
                        'operation' => 'adjust_provider_preference_or_require_benchmark',
                        'dimensions' => ['provider', 'model', 'domain', 'flow', 'task_type', 'specialist_profile'],
                        'requires_human_review' => true,
                    ],
                    'available_actions' => [
                        [
                            'id' => 'run_provider_benchmark',
                            'label' => 'Run provider benchmark',
                            'mode' => 'assisted',
                        ],
                        [
                            'id' => 'draft_model_selection_policy_patch',
                            'label' => 'Draft model selection policy patch',
                            'mode' => 'proposal_only',
                        ],
                        [
                            'id' => 'configure_provider_cost_rates',
                            'label' => 'Configure provider cost rates',
                            'mode' => 'assisted',
                        ],
                    ],
                ],
            ];
        }

        if ($unknownCostCount > 0) {
            $findings[] = [
                'title' => 'Configurar rates de custo dos providers',
                'category' => 'self_improvement',
                'finding' => "AP-99 encontrou {$unknownCostCount} evento(s) com custo desconhecido na janela analisada.",
                'problem' => 'Sem rates de custo, o Dynamic Compute Market consegue comparar qualidade e latencia, mas nao consegue otimizar custo de forma honesta.',
                'solution' => 'Configurar ai_provider_cost_rates para provider/modelos usados e manter cost_confidence, cost_source e cost_mode auditaveis no Provider Usage Event.',
                'worth_it' => 'Vale porque fecha o triangulo qualidade + latencia + custo sem permitir que o Atlas invente numeros.',
                'best_solution_rationale' => 'O custo deve nascer no contrato AP-99 e ser consumido por Decide, Curator, CLI, API e MCP, evitando planilhas ou heuristicas paralelas.',
                'alternatives' => ['Continuar em modo shadow usando apenas qualidade e latencia.', 'Rodar benchmark controlado com custo manual antes de ativar budgets automaticos.'],
                'available_actions' => [
                    ['id' => 'configure_provider_cost_rates', 'label' => 'Configurar rates', 'style' => 'primary'],
                    ['id' => 'review_patch', 'label' => 'Revisar evidencia', 'style' => 'secondary'],
                    ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
                    ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive', 'requires_confirm' => true],
                ],
                'payload' => [
                    'provider_cost_rates' => [
                        'schema_version' => 'atlas.provider_cost_rates.proposal.v1',
                        'unknown_cost_count' => $unknownCostCount,
                        'cost_confidence_counts' => $report['cost_confidence_counts'] ?? [],
                        'cost_mode_counts' => $report['cost_mode_counts'] ?? [],
                    ],
                ],
                'source_refs' => collect((array) ($report['recent_events'] ?? []))
                    ->where('cost_confidence', 'unknown')
                    ->take(5)
                    ->map(fn (array $event): array => [
                        'type' => 'ledger_event',
                        'id' => $event['event_id'] ?? null,
                        'envelope_id' => $event['envelope_id'] ?? null,
                        'provider_cli' => $event['provider_cli'] ?? null,
                        'model' => $event['model'] ?? null,
                        'domain' => $event['domain'] ?? null,
                        'flow' => $event['flow'] ?? null,
                        'task_type' => $event['task_type'] ?? null,
                        'specialist_profile' => $event['specialist_profile'] ?? null,
                        'cost_source' => $event['cost_source'] ?? null,
                        'cost_mode' => $event['cost_mode'] ?? null,
                    ])
                    ->values()
                    ->all(),
                'confidence' => 0.82,
                'dedupe_key' => 'self-improvement:provider-cost-rates:'.sha1(json_encode($report['cost_confidence_counts'] ?? [], JSON_THROW_ON_ERROR).':'.$unknownCostCount),
                'metadata' => [
                    'schema_version' => 'atlas.self_improvement.provider_cost_rates.v1',
                    'unknown_cost_count' => $unknownCostCount,
                    'costed_event_count' => $report['costed_event_count'] ?? 0,
                    'cost_confidence_counts' => $report['cost_confidence_counts'] ?? [],
                    'cost_mode_counts' => $report['cost_mode_counts'] ?? [],
                    'token_source_counts' => $report['token_source_counts'] ?? [],
                    'filters' => $report['filters'] ?? [],
                    'review_signal' => [
                        'status' => 'warning',
                        'severity' => 'medium',
                        'recommended_action' => 'configure_provider_cost_rates',
                    ],
                    'available_actions' => [
                        [
                            'id' => 'configure_provider_cost_rates',
                            'label' => 'Configure provider cost rates',
                            'mode' => 'assisted',
                        ],
                    ],
                ],
            ];
        }

        $findings = [
            ...$findings,
            ...$this->dynamicComputeMarketFindings($report, $filters),
        ];

        return $findings;
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function dynamicComputeMarketFindings(array $report, array $filters = []): array
    {
        $normalizedFilters = $this->filterNormalization->normalizedProviderPerformanceFilters($filters);

        return collect((array) ($report['groups'] ?? []))
            ->filter(fn (mixed $group): bool => is_array($group))
            ->filter(fn (array $group): bool => (string) ($group['provider_cli'] ?? 'unknown') !== 'unknown')
            ->take(8)
            ->map(function (array $group) use ($normalizedFilters): ?array {
                $provider = (string) ($group['provider_cli'] ?? '');
                $domain = $this->filterNormalization->knownProviderDimension($group['domain'] ?? null);
                $taskType = $this->filterNormalization->knownProviderDimension($group['task_type'] ?? null);
                $specialistProfile = $this->filterNormalization->knownProviderDimension($group['specialist_profile'] ?? null);
                $flow = $normalizedFilters['flow'] ?? null;

                $market = $this->dynamicComputeMarket->advise(
                    selectedProvider: $provider,
                    selectedModel: null,
                    policy: array_filter([
                        'domain' => $domain,
                        'flow' => $flow,
                        'profile_id' => $flow,
                        'profile_context' => array_filter([
                            'domain' => $domain,
                            'flow' => $flow,
                        ], fn (?string $value): bool => $value !== null),
                    ], fn (mixed $value): bool => $value !== null && $value !== []),
                    taskProfile: array_filter([
                        'task_type' => $taskType,
                    ], fn (?string $value): bool => $value !== null),
                    specialistProfile: $specialistProfile,
                );

                if (($market['recommendation'] ?? null) !== 'benchmark_lower_latency_alternative') {
                    return null;
                }

                $candidate = $market['benchmark_candidate'] ?? null;
                if (! is_array($candidate)) {
                    return null;
                }

                return [
                    'title' => 'Benchmark revisavel do Dynamic Compute Market',
                    'category' => 'self_improvement',
                    'finding' => "Dynamic Compute Market encontrou alternativa potencial para {$provider} em ".($domain ?? 'domain desconhecido').'.',
                    'problem' => 'AP-99 sugere que outro provider pode entregar latencia, custo ou qualidade melhor para a mesma familia de tarefa, mas trocar rota automaticamente violaria a autoridade do Atlas Decide.',
                    'solution' => 'Rodar benchmark controlado pareado e, se confirmado, abrir policy patch revisavel para Atlas Decide. Nenhuma rota deve mudar sem receipt novo.',
                    'worth_it' => 'Vale porque transforma telemetria real em melhoria de provider sem cair em achismo, override silencioso ou preferencia fixa por modelo.',
                    'best_solution_rationale' => 'O Curator fica proposal-only: ele usa AP-99 para detectar oportunidade, mas deixa benchmark, policy e Decision Receipt como gates obrigatorios.',
                    'alternatives' => ['Continuar observando ate aumentar amostra.', 'Configurar cost rates antes se a melhoria aparente depender de custo.', 'Manter provider atual se a diferenca nao sobreviver ao benchmark.'],
                    'available_actions' => [
                        ['id' => 'run_provider_benchmark', 'label' => 'Rodar benchmark', 'style' => 'primary'],
                        ['id' => 'draft_model_selection_policy_patch', 'label' => 'Rascunhar policy patch', 'style' => 'secondary'],
                        ['id' => 'review_patch', 'label' => 'Revisar evidencia', 'style' => 'secondary'],
                        ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
                        ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive', 'requires_confirm' => true],
                    ],
                    'source_refs' => [[
                        'type' => 'dynamic_compute_market',
                        'provider_cli' => $provider,
                        'candidate_provider' => $candidate['provider'] ?? null,
                        'domain' => $domain,
                        'flow' => $flow,
                        'task_type' => $taskType,
                        'specialist_profile' => $specialistProfile,
                        'recommendation' => $market['recommendation'] ?? null,
                        'recommended_next_action' => $market['recommended_next_action'] ?? null,
                    ]],
                    'confidence' => ($candidate['sample_status'] ?? null) === 'sufficient' ? 0.84 : 0.7,
                    'dedupe_key' => 'self-improvement:dynamic-compute-market:'.sha1(json_encode([
                        'provider' => $provider,
                        'candidate' => $candidate['provider'] ?? null,
                        'domain' => $domain,
                        'flow' => $flow,
                        'task_type' => $taskType,
                        'specialist_profile' => $specialistProfile,
                    ], JSON_THROW_ON_ERROR)),
                    'metadata' => [
                        'schema_version' => 'atlas.self_improvement.dynamic_compute_market.v1',
                        'mode' => 'proposal_only',
                        'review_signal' => [
                            'status' => 'warning',
                            'severity' => ($candidate['sample_status'] ?? null) === 'sufficient' ? 'medium' : 'low',
                            'recommended_action' => 'run_controlled_provider_benchmark_before_policy_change',
                        ],
                        'dynamic_compute_market' => $market,
                        'candidate' => $candidate,
                        'routing_control' => [
                            'changes_provider' => false,
                            'routing_authority' => 'atlas_decide',
                            'provider_change_requires' => ['policy_patch', 'decision_receipt'],
                        ],
                        'policy_patch_candidate' => [
                            'status' => 'proposal_only',
                            'target' => 'atlas_decide_model_selection_policy',
                            'operation' => 'benchmark_then_adjust_provider_preference',
                            'requires_human_review' => true,
                        ],
                        'proposal_evidence_contract' => data_get($market, 'proposal_gate.proposal_evidence_contract'),
                        'available_actions' => [
                            ['id' => 'run_provider_benchmark', 'label' => 'Run provider benchmark', 'mode' => 'assisted'],
                            ['id' => 'draft_model_selection_policy_patch', 'label' => 'Draft model selection policy patch', 'mode' => 'proposal_only'],
                        ],
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
