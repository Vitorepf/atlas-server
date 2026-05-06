<?php

namespace App\Services\Ai\SelfImprovement;

use App\Models\AtlasInitiativeRun;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasOpenBrainAccessLog;
use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyReadModel;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AtlasSelfImprovementRuntime
{
    public const DEFAULT_REVIEW_WINDOW_HOURS = 24;

    public const MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS = 168;

    public const MAX_FINDINGS_PER_RUN = 20;

    /**
     * @var array<string,mixed>|null
     */
    private ?array $architectureValidationPayload = null;

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AtlasLedgerReplayService $replay,
        private readonly ProposalInboxEmitter $proposals,
        private readonly AtlasAiDomainCatalogService $domainCatalog,
        private readonly AtlasAiArchitectureValidationService $architectureValidation,
        private readonly AtlasArchitectureOperationsCatalog $architectureOperations,
        private readonly AtlasSelfImprovementScheduleService $schedule,
        private readonly AtlasSelfImprovementInput $input,
        private readonly ProviderPerformanceProjection $providerPerformance,
        private readonly AtlasRivalsStrategyReadModel $rivalsStrategy,
    ) {}

    /**
     * @param  array<string,string|null>  $filters
     * @return array{ok:bool,run_id:?string,flow:string,dry_run:bool,hours:int,filters:array<string,string>,findings:array<int,array<string,mixed>>,emitted_item_ids:array<int,string>,emitted_count:int}
     */
    public function nightlyReview(string $flow = 'self_improvement.nightly_review', bool $emit = false, int $hours = self::DEFAULT_REVIEW_WINDOW_HOURS, int $limit = 5, array $filters = []): array
    {
        $flow = $this->normalizeFlow($flow);
        $hours = $this->input->reviewWindowHours($hours);
        $limit = $this->input->findingsLimit($limit);
        $filters = $this->normalizedRuntimeFilters($filters);
        $this->architectureValidationPayload = null;
        $run = $this->startRun($flow, $emit, $hours, $limit);
        $envelopeId = $run ? 'self_improvement_run:'.$run->id : 'self_improvement_run:ad_hoc';

        $this->recordCycleEvent(LedgerEventType::ExecutionStarted, $envelopeId, $run, [
            'flow' => $flow,
            'hours' => $hours,
            'limit' => $limit,
            'emit' => $emit,
            'filters' => $filters,
        ]);

        try {
            $this->recordCycleEvent(LedgerEventType::SelfImprovementScheduleObserved, $envelopeId, $run, [
                'flow' => $flow,
                'schedule_health' => $this->scheduleHealthLedgerProjection($this->schedule->scheduleHealth()),
            ]);

            $events = $this->ledgerEvents($hours);
            $findings = collect($this->findingsForFlow($flow, $events, $filters, $hours))
                ->unique('dedupe_key')
                ->sortByDesc(fn (array $finding): float => (float) ($finding['confidence'] ?? 0))
                ->take($limit)
                ->values()
                ->all();

            $emitted = [];
            $emittedByDedupeKey = [];
            if ($emit) {
                foreach ($findings as $finding) {
                    $item = $this->proposals->emit([
                        ...$finding,
                        'source_type' => 'atlas_initiative_run',
                        'source_id' => $run?->id,
                    ]);
                    if ($item) {
                        $emitted[] = $item->id;
                        $dedupeKey = (string) ($finding['dedupe_key'] ?? '');
                        if ($dedupeKey !== '') {
                            $emittedByDedupeKey[$dedupeKey] = $item->id;
                        }
                    }
                }
            }

            foreach ($findings as $finding) {
                $emittedInboxItemId = $emittedByDedupeKey[(string) ($finding['dedupe_key'] ?? '')] ?? null;
                $this->recordCycleEvent(LedgerEventType::LearningProposed, $envelopeId, $run, [
                    'flow' => $flow,
                    'finding' => $this->ledgerFindingProjection($finding),
                    'emitted' => $emit,
                    'emitted_to_inbox' => $emittedInboxItemId !== null,
                    'emitted_inbox_item_id' => $emittedInboxItemId,
                ]);
            }

            $this->finishRun($run, 'succeeded', $findings, $emitted);
            $this->recordCycleEvent(LedgerEventType::OperationCompleted, $envelopeId, $run, [
                'flow' => $flow,
                'finding_count' => count($findings),
                'emitted_count' => count($emitted),
                'emitted_inbox_item_ids' => array_values($emitted),
            ]);

            return [
                'ok' => true,
                'run_id' => $run?->id,
                'flow' => $flow,
                'dry_run' => ! $emit,
                'hours' => $hours,
                'filters' => $filters,
                'findings' => $findings,
                'emitted_item_ids' => $emitted,
                'emitted_count' => count($emitted),
            ];
        } catch (\Throwable $throwable) {
            $this->finishRun($run, 'failed', [], [], $throwable->getMessage());
            $this->recordCycleEvent(LedgerEventType::OperationFailed, $envelopeId, $run, [
                'flow' => $flow,
                'error_message_hash' => hash('sha256', $throwable->getMessage()),
            ]);

            throw $throwable;
        }
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    private function findingsForFlow(string $flow, Collection $events, array $filters = [], int $hours = 24): array
    {
        return match ($flow) {
            'self_improvement.capability_gap_scan' => [
                ...$this->domainOnboardingFindings($filters),
                ...$this->missingTerminalFindings($events),
                ...$this->toolCoverageFindings($events),
                ...$this->repairLoopFindings($events, $filters),
                ...$this->kernelPipelineFindings($events, $filters),
            ],
            'self_improvement.benchmark_review',
            'self_improvement.provider_performance_review' => [
                ...$this->providerPerformanceFindings($hours, $filters),
                ...$this->sloDriftFindings($events, $filters),
                ...$this->repairLoopFindings($events, $filters),
                ...$this->operationFailureFindings($events),
                ...$this->gateBlockedFindings($events),
            ],
            'self_improvement.memory_quality_review',
            'self_improvement.docs_drift_review',
            'self_improvement.weekly_architecture_audit',
            'self_improvement.domain_learning_review' => [
                ...$this->architectureValidationFindings($filters),
                ...$this->documentationHealthFindings($filters),
                ...$this->ledgerProjectionDriftFindings($filters),
                ...$this->architectureOperationsFindings($filters),
                ...$this->selfImprovementScheduleFindings($filters),
                ...$this->selfImprovementScheduleReplayFindings($hours, $filters),
                ...$this->inboxActionReplayFindings($hours, $filters),
                ...$this->decisionReceiptReplayFindings($events, $filters),
                ...$this->rivalsStrategyFindings($hours, $filters),
                ...$this->openBrainRetrievalFindings($hours, $filters),
                ...$this->domainOnboardingFindings($filters),
                ...$this->sloDriftFindings($events, $filters),
                ...$this->repairLoopFindings($events, $filters),
                ...$this->kernelPipelineFindings($events, $filters),
                ...$this->missingTerminalFindings($events),
                ...$this->operationFailureFindings($events),
                ...$this->gateBlockedFindings($events),
            ],
            'self_improvement.tool_runtime_review' => [
                ...$this->repairLoopFindings($events, $filters),
                ...$this->gateBlockedFindings($events),
                ...$this->toolCoverageFindings($events),
            ],
            'self_improvement.repair_loop_review' => [
                ...$this->repairLoopFindings($events, $filters),
            ],
            'self_improvement.kernel_pipeline_review' => [
                ...$this->kernelPipelineFindings($events, $filters),
            ],
            default => [
                ...$this->architectureValidationFindings($filters),
                ...$this->documentationHealthFindings($filters),
                ...$this->ledgerProjectionDriftFindings($filters),
                ...$this->architectureOperationsFindings($filters),
                ...$this->selfImprovementScheduleReplayFindings($hours, $filters),
                ...$this->inboxActionReplayFindings($hours, $filters),
                ...$this->decisionReceiptReplayFindings($events, $filters),
                ...$this->rivalsStrategyFindings($hours, $filters),
                ...$this->openBrainRetrievalFindings($hours, $filters),
                ...$this->domainOnboardingFindings($filters),
                ...$this->sloDriftFindings($events, $filters),
                ...$this->repairLoopFindings($events, $filters),
                ...$this->kernelPipelineFindings($events, $filters),
                ...$this->missingTerminalFindings($events),
                ...$this->operationFailureFindings($events),
                ...$this->gateBlockedFindings($events),
                ...$this->toolCoverageFindings($events),
            ],
        };
    }

    /**
     * @return Collection<int,AtlasLedgerEvent>
     */
    private function ledgerEvents(int $hours): Collection
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            return new Collection;
        }

        return AtlasLedgerEvent::query()
            ->where('occurred_at', '>=', now()->subHours($hours))
            ->orderBy('occurred_at')
            ->get();
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function openBrainRetrievalFindings(int $hours, array $filters = []): array
    {
        if (! Schema::hasTable('atlas_open_brain_access_logs')) {
            return [];
        }

        $query = AtlasOpenBrainAccessLog::query()
            ->where('accessed_at', '>=', now()->subHours($hours))
            ->whereIn('action', ['context_injection', 'context_injection_preview']);

        if (($filters['surface'] ?? null) !== null) {
            $query->where('surface', $filters['surface']);
        }

        $logs = $query
            ->orderByDesc('accessed_at')
            ->limit(100)
            ->get();

        $blocked = $logs
            ->filter(function (AtlasOpenBrainAccessLog $log): bool {
                $summary = (array) ($log->result_summary_json ?? []);
                $warnings = (array) data_get($summary, 'warnings', []);

                return data_get($summary, 'retrieval_plan.review_signal.status') === 'blocking'
                    || in_array('retrieval_required_source_unavailable', $warnings, true);
            })
            ->values();

        if ($blocked->isEmpty()) {
            return [];
        }

        $requiredUnavailable = $blocked
            ->flatMap(fn (AtlasOpenBrainAccessLog $log): array => (array) data_get($log->result_summary_json, 'retrieval_plan.required_unavailable_sources', []))
            ->filter(fn (mixed $source): bool => is_string($source) && $source !== '')
            ->values()
            ->all();

        $recommendedActions = $blocked
            ->map(fn (AtlasOpenBrainAccessLog $log): ?string => data_get($log->result_summary_json, 'retrieval_plan.review_signal.recommended_action'))
            ->filter(fn (mixed $action): bool => is_string($action) && $action !== '')
            ->values()
            ->all();

        $statusCounts = $blocked
            ->map(fn (AtlasOpenBrainAccessLog $log): string => (string) ($log->status ?? 'unknown'))
            ->countBy()
            ->all();
        $requiredUnavailableSourceCounts = array_count_values($requiredUnavailable);
        $recommendedActionCounts = array_count_values($recommendedActions);
        $primaryAction = $recommendedActions[0] ?? 'refresh_context_sources_before_retry';
        $primarySources = array_values(array_unique($requiredUnavailable));
        $sourceText = $primarySources === [] ? 'fontes obrigatorias desconhecidas' : implode(', ', $primarySources);

        return [[
            'title' => 'Corrigir fontes obrigatorias ausentes no Open Brain',
            'category' => 'self_improvement',
            'finding' => "Open Brain registrou {$blocked->count()} injecao(oes) com retrieval obrigatorio indisponivel na janela analisada: {$sourceText}.",
            'problem' => 'Quando o Context Builder exige evidence replay, code intelligence, memoria ou graph retrieval e a fonte nao esta disponivel, o Atlas pode degradar contexto, bloquear execucao required-mode ou repetir tentativas sem aprender a lacuna.',
            'solution' => 'Promover o review_signal de retrieval para o Curator/Self-Improvement, abrir proposta revisavel para atualizar a fonte ausente e reexecutar o fluxo somente depois que o contexto obrigatorio estiver fresco.',
            'worth_it' => 'Vale porque transforma falhas de contexto em backlog automatico de melhoria, fechando o ciclo Input -> Evidence -> Learning -> Curator sem criar repair paralelo.',
            'best_solution_rationale' => 'Consumir atlas_open_brain_access_logs preserva o contrato do Open Brain como fonte operacional e evita duplicar a logica de disponibilidade dentro do Curator.',
            'alternatives' => ['Aguardar nova tentativa manual com mais contexto anexado.', 'Relaxar a fonte para optional apenas se o Decision Receipt permitir degradacao.'],
            'source_refs' => $blocked
                ->take(5)
                ->map(fn (AtlasOpenBrainAccessLog $log): array => [
                    'type' => 'open_brain_access_log',
                    'id' => $log->id,
                    'surface' => $log->surface,
                    'action' => $log->action,
                    'status' => $log->status,
                    'context_pack_hash' => $log->context_pack_hash,
                    'required_unavailable_sources' => array_values((array) data_get($log->result_summary_json, 'retrieval_plan.required_unavailable_sources', [])),
                    'recommended_action' => data_get($log->result_summary_json, 'retrieval_plan.review_signal.recommended_action'),
                    'accessed_at' => $log->accessed_at?->toJSON(),
                ])
                ->values()
                ->all(),
            'confidence' => $blocked->count() > 1 ? 0.88 : 0.82,
            'dedupe_key' => 'self-improvement:open-brain-retrieval:'.sha1($primaryAction.':'.implode('|', $primarySources)),
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.open_brain_retrieval.v1',
                'review_signal' => [
                    'status' => 'blocking',
                    'severity' => 'high',
                    'reason' => 'required_retrieval_source_unavailable',
                    'recommended_action' => $primaryAction,
                    'required_unavailable_sources' => $primarySources,
                ],
                'log_count' => $blocked->count(),
                'status_counts' => $statusCounts,
                'required_unavailable_source_counts' => $requiredUnavailableSourceCounts,
                'recommended_action_counts' => $recommendedActionCounts,
                'filters' => array_filter($filters, fn (?string $value): bool => $value !== null),
            ],
        ]];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function providerPerformanceFindings(int $hours, array $filters = []): array
    {
        $report = $this->providerPerformance->reportForWindow(
            now()->subHours($hours),
            filters: $this->normalizedProviderPerformanceFilters($filters),
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

        return $findings;
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    private function sloDriftFindings(Collection $events, array $filters = []): array
    {
        $sloEvents = $events->where('event_type', LedgerEventType::SloObserved->value);
        if ($sloEvents->isEmpty()) {
            return [];
        }

        $oldest = CarbonImmutable::parse($sloEvents->min('occurred_at') ?? now()->subDay());
        $newest = CarbonImmutable::parse($sloEvents->max('occurred_at') ?? now())->addSecond();
        $filters = $this->normalizedDimensionFilters($filters);
        $report = $this->replay->sloReportForWindow($oldest, $newest, $filters);
        if (($report['observation_count'] ?? 0) === 0) {
            return [];
        }

        return collect((array) ($report['stages'] ?? []))
            ->filter(fn (array $stage): bool => in_array($stage['worst_status'] ?? null, ['warning', 'breach'], true) || (int) ($stage['failure_count'] ?? 0) > 0)
            ->map(function (array $stage, string $stageName) use ($report, $filters): array {
                $status = (string) ($stage['worst_status'] ?? 'unknown');
                $severity = (string) ($stage['worst_severity'] ?? 'unknown');
                $violationText = implode(', ', (array) ($stage['violations'] ?? []));

                return [
                    'title' => "Investigar SLO drift em {$stageName}",
                    'category' => 'self_improvement',
                    'finding' => "Stage {$stageName} apresentou status {$status} com severidade {$severity} na janela analisada.",
                    'problem' => 'Drift de SLO indica regressao de latencia, falha de runtime, gate instavel ou custo operacional acima do contrato kernel.',
                    'solution' => 'Abrir proposta de investigacao com replay dos envelopes recentes, comparar p50/p95/max, separar causa por provider/surface/domain e adicionar teste ou guardrail antes de alterar runtime.',
                    'worth_it' => 'Vale porque transforma degradacao operacional em backlog mensuravel antes que vire falha percebida pelo usuario.',
                    'best_solution_rationale' => 'Usar o read model do Evidence Ledger preserva causalidade e evita dashboards ou Curator com queries duplicadas.',
                    'alternatives' => ['Observar por mais uma janela antes de agir.', 'Ajustar SLO target somente depois de benchmark e justificativa documentada.'],
                    'source_refs' => collect((array) ($report['recent_breaches'] ?? []))
                        ->where('stage', $stageName)
                        ->take(5)
                        ->map(fn (array $observation): array => [
                            'type' => 'ledger_event',
                            'id' => $observation['event_id'] ?? null,
                            'envelope_id' => $observation['envelope_id'] ?? null,
                            'dimensions' => $observation['dimensions'] ?? [],
                        ])
                        ->values()
                        ->all(),
                    'confidence' => $status === 'breach' ? 0.9 : 0.82,
                    'dedupe_key' => 'self-improvement:slo-drift:'.sha1($stageName.':'.$status.':'.$violationText),
                    'metadata' => [
                        'stage' => $stageName,
                        'status' => $status,
                        'severity' => $severity,
                        'count' => $stage['count'] ?? 0,
                        'failure_count' => $stage['failure_count'] ?? 0,
                        'p50_ms' => $stage['p50_ms'] ?? 0,
                        'p95_ms' => $stage['p95_ms'] ?? 0,
                        'max_ms' => $stage['max_ms'] ?? 0,
                        'violations' => $stage['violations'] ?? [],
                        'dimensions' => $stage['dimensions'] ?? [],
                        'review_signal' => $report['review_signal'] ?? [],
                        'filters' => $filters,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    private function repairLoopFindings(Collection $events, array $filters = []): array
    {
        $repairEvents = $events->filter(fn (AtlasLedgerEvent $event): bool => in_array($event->event_type, [
            LedgerEventType::RepairInitiated->value,
            LedgerEventType::RepairCompleted->value,
        ], true));

        if ($repairEvents->isEmpty()) {
            return [];
        }

        $oldest = CarbonImmutable::parse($repairEvents->min('occurred_at') ?? now()->subDay());
        $newest = CarbonImmutable::parse($repairEvents->max('occurred_at') ?? now())->addSecond();
        $filters = $this->normalizedRepairFilters($filters);
        $report = $this->replay->repairReportForWindow($oldest, $newest, $filters);
        if (($report['repair_event_count'] ?? 0) === 0) {
            return [];
        }

        $findings = [];
        if ((bool) ($report['requires_human_review'] ?? false)) {
            $findings[] = $this->repairFinding(
                title: 'Reduzir repairs que exigem revisao humana',
                finding: 'Repair Loop detectou decisoes com human_review na janela analisada.',
                problem: 'Repairs que exigem humano podem estar corretos para policy/compliance/privacy, mas repeticao indica lacuna de classificacao, contexto, gate ou playbook operacional.',
                solution: 'Agrupar os envelopes por failure_domain e criar proposal para melhorar contexto, policy message, evidencia obrigatoria ou playbook de revisao sem habilitar auto-repair.',
                report: $report,
                dedupeSeed: 'human-review',
                confidence: 0.86,
            );
        }

        $blockedCount = (int) data_get($report, 'status_counts.repair_blocked', 0)
            + (int) data_get($report, 'status_counts.repair_exhausted', 0);
        if ($blockedCount > 0) {
            $findings[] = $this->repairFinding(
                title: 'Desbloquear Repair Loop com evidencia ou policy melhor',
                finding: "{$blockedCount} evento(s) de repair foram bloqueados ou esgotaram tentativas na janela analisada.",
                problem: 'Repair bloqueado/exhausted significa que o kernel evitou uma acao insegura, mas tambem mostra onde faltou evidencia, strategy permitida ou limite bem calibrado.',
                solution: 'Revisar reasons agregadas, checar evidence_refs e criar teste/guardrail para garantir que repairs pesados tenham evidencia antes de serem planejados.',
                report: $report,
                dedupeSeed: 'blocked-exhausted',
                confidence: 0.84,
            );
        }

        $dominantStrategy = collect((array) ($report['strategy_counts'] ?? []))
            ->sortDesc()
            ->filter(fn (int $count): bool => $count >= 2)
            ->keys()
            ->first();
        if (is_string($dominantStrategy) && $dominantStrategy !== '') {
            $findings[] = $this->repairFinding(
                title: "Investigar repair recorrente {$dominantStrategy}",
                finding: "A estrategia {$dominantStrategy} apareceu repetidamente no Repair Loop.",
                problem: 'Estrategia de repair recorrente pode indicar causa-raiz persistente no provider, harness, tool runtime, contexto ou gate.',
                solution: 'Comparar envelopes recentes, failure_domain e reasons antes de adicionar automacao; se a causa for consistente, criar melhoria pequena com teste arquitetural.',
                report: $report,
                dedupeSeed: 'strategy:'.$dominantStrategy,
                confidence: 0.8,
            );
        }

        return $findings;
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function repairFinding(string $title, string $finding, string $problem, string $solution, array $report, string $dedupeSeed, float $confidence): array
    {
        return [
            'title' => $title,
            'category' => 'self_improvement',
            'finding' => $finding,
            'problem' => $problem,
            'solution' => $solution,
            'worth_it' => 'Vale porque usa evidencias reais do Repair Loop para melhorar confiabilidade sem transformar bloqueios seguros em bypass.',
            'best_solution_rationale' => 'O read model do Evidence Ledger preserva causalidade e evita heuristica diferente por surface.',
            'alternatives' => ['Manter apenas observacao por mais uma janela.', 'Abrir review manual sem mudar policy/runtime.'],
            'source_refs' => collect((array) ($report['recent_events'] ?? []))
                ->take(5)
                ->map(fn (array $event): array => [
                    'type' => 'ledger_event',
                    'id' => $event['event_id'] ?? null,
                    'envelope_id' => $event['envelope_id'] ?? null,
                    'event_type' => $event['event_type'] ?? null,
                    'status' => $event['status'] ?? null,
                    'strategy' => $event['strategy'] ?? null,
                    'failure_domain' => $event['failure_domain'] ?? null,
                ])
                ->values()
                ->all(),
            'confidence' => $confidence,
            'dedupe_key' => 'self-improvement:repair-loop:'.sha1($dedupeSeed),
            'metadata' => [
                'repair_event_count' => $report['repair_event_count'] ?? 0,
                'envelope_count' => $report['envelope_count'] ?? 0,
                'initiated_count' => $report['initiated_count'] ?? 0,
                'completed_count' => $report['completed_count'] ?? 0,
                'executed_count' => $report['executed_count'] ?? 0,
                'status_counts' => $report['status_counts'] ?? [],
                'strategy_counts' => $report['strategy_counts'] ?? [],
                'reason_counts' => $report['reason_counts'] ?? [],
                'latest_status' => $report['latest_status'] ?? null,
                'latest_strategy' => $report['latest_strategy'] ?? null,
                'requires_human_review' => (bool) ($report['requires_human_review'] ?? false),
                'review_signal' => $report['review_signal'] ?? [],
                'filters' => $report['filters'] ?? [],
            ],
        ];
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    private function kernelPipelineFindings(Collection $events, array $filters = []): array
    {
        $pipelineEvents = $events->filter(fn (AtlasLedgerEvent $event): bool => in_array($event->event_type, [
            LedgerEventType::KernelPipelineAccepted->value,
            LedgerEventType::KernelPipelineRejected->value,
        ], true));

        if ($pipelineEvents->isEmpty()) {
            return [];
        }

        $oldest = CarbonImmutable::parse($pipelineEvents->min('occurred_at') ?? now()->subDay());
        $newest = CarbonImmutable::parse($pipelineEvents->max('occurred_at') ?? now())->addSecond();
        $filters = $this->normalizedKernelPipelineFilters($filters);
        $report = $this->replay->kernelPipelineReportForWindow($oldest, $newest, $filters);
        if (($report['kernel_pipeline_event_count'] ?? 0) === 0) {
            return [];
        }

        $findings = [];
        if ((bool) ($report['has_rejections'] ?? false)) {
            $findings[] = $this->kernelPipelineFinding(
                title: 'Corrigir surfaces rejeitadas pelo Kernel Pipeline',
                finding: 'Kernel Pipeline detectou contratos rejeitados na janela analisada.',
                problem: 'Rejeicao do contrato atlas.run significa que uma surface tentou seguir com schema, hash, stage order, guards ou binding fora da arquitetura-mae.',
                solution: 'Agrupar os envelopes rejeitados por surface/input mode/violation, corrigir o adapter ou o produtor do dev plan e adicionar teste arquitetural que falhe antes de enfileirar job.',
                report: $report,
                dedupeSeed: 'rejected-contracts',
                confidence: 0.9,
            );
        }

        $dominantSurface = collect((array) ($report['surface_counts'] ?? []))
            ->sortDesc()
            ->filter(fn (int $count): bool => $count >= 2)
            ->keys()
            ->first();
        if (is_string($dominantSurface) && $dominantSurface !== '' && (int) ($report['rejected_count'] ?? 0) > 0) {
            $findings[] = $this->kernelPipelineFinding(
                title: "Investigar drift recorrente em {$dominantSurface}",
                finding: "A surface {$dominantSurface} apareceu repetidamente em eventos do Kernel Pipeline com rejeicoes na janela.",
                problem: 'Drift recorrente por surface normalmente indica adapter divergente, capability presa na surface ou caminho legado tentando decidir/executar fora do kernel.',
                solution: 'Comparar eventos recentes da surface, validar input_mode/flow e mover qualquer decisao duplicada para Core/Domain antes de migrar runtime real.',
                report: $report,
                dedupeSeed: 'surface:'.$dominantSurface,
                confidence: 0.84,
            );
        }

        return $findings;
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function kernelPipelineFinding(string $title, string $finding, string $problem, string $solution, array $report, string $dedupeSeed, float $confidence): array
    {
        return [
            'title' => $title,
            'category' => 'self_improvement',
            'finding' => $finding,
            'problem' => $problem,
            'solution' => $solution,
            'worth_it' => 'Vale porque impede que surface, provider ou runtime voltem a criar fluxo paralelo invisivel enquanto a migracao real do kernel avanca.',
            'best_solution_rationale' => 'Usar o read model do Evidence Ledger preserva causalidade e evita que Curator invente uma auditoria diferente da observability.',
            'alternatives' => ['Manter somente monitoramento por mais uma janela.', 'Bloquear temporariamente a surface afetada se houver rejeicao critica repetida.'],
            'source_refs' => collect((array) ($report['recent_events'] ?? []))
                ->take(5)
                ->map(fn (array $event): array => [
                    'type' => 'ledger_event',
                    'id' => $event['event_id'] ?? null,
                    'envelope_id' => $event['envelope_id'] ?? null,
                    'event_type' => $event['event_type'] ?? null,
                    'status' => $event['status'] ?? null,
                    'surface_id' => $event['surface_id'] ?? null,
                    'emitter_stage' => $event['emitter_stage'] ?? null,
                    'flow' => $event['flow'] ?? null,
                    'input_mode' => $event['input_mode'] ?? null,
                    'violations' => $event['violations'] ?? [],
                ])
                ->values()
                ->all(),
            'confidence' => $confidence,
            'dedupe_key' => 'self-improvement:kernel-pipeline:'.sha1($dedupeSeed),
            'metadata' => [
                'kernel_pipeline_event_count' => $report['kernel_pipeline_event_count'] ?? 0,
                'envelope_count' => $report['envelope_count'] ?? 0,
                'accepted_count' => $report['accepted_count'] ?? 0,
                'rejected_count' => $report['rejected_count'] ?? 0,
                'status_counts' => $report['status_counts'] ?? [],
                'surface_counts' => $report['surface_counts'] ?? [],
                'emitter_stage_counts' => $report['emitter_stage_counts'] ?? [],
                'flow_counts' => $report['flow_counts'] ?? [],
                'input_mode_counts' => $report['input_mode_counts'] ?? [],
                'violation_counts' => $report['violation_counts'] ?? [],
                'latest_status' => $report['latest_status'] ?? null,
                'has_rejections' => (bool) ($report['has_rejections'] ?? false),
                'health' => $report['health'] ?? [],
                'review_signal' => $report['review_signal'] ?? [],
                'filters' => $report['filters'] ?? [],
            ],
        ];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function domainOnboardingFindings(array $filters = []): array
    {
        $catalog = $this->domainCatalog->inspect($this->normalizedDomainOnboardingFilters($filters));
        $domains = collect((array) ($catalog['domains'] ?? []));
        $summary = (array) ($catalog['summary'] ?? []);

        if ($domains->isEmpty()) {
            return [];
        }

        $findings = [];
        $executableIncomplete = $domains
            ->filter(fn (array $domain): bool => data_get($domain, 'onboarding.status') === 'executable_incomplete')
            ->values();
        if ($executableIncomplete->isNotEmpty()) {
            $findings[] = $this->domainOnboardingFinding(
                title: 'Bloquear promocao de dominios executaveis incompletos',
                finding: 'Domain catalog encontrou dominio com runtime/orchestrator executavel, mas onboarding incompleto.',
                problem: 'Um dominio executable_incomplete pode parecer pronto para uma surface, mas ainda falta contrato de contexto, gate, learning ou surface. Isso cria risco de fluxo paralelo ou capacidade parcialmente implementada.',
                solution: 'Manter o dominio fora de rotas automaticas, completar as fases faltantes e adicionar teste de catalogo antes de permitir promocao para ready.',
                domains: $executableIncomplete->all(),
                catalog: $catalog,
                dedupeSeed: 'executable-incomplete',
                confidence: 0.92,
            );
        }

        $scaffolds = $domains
            ->filter(fn (array $domain): bool => data_get($domain, 'onboarding.status') === 'scaffold')
            ->values();
        if ($scaffolds->isNotEmpty()) {
            $findings[] = $this->domainOnboardingFinding(
                title: 'Priorizar dominios scaffold no roadmap de habilidades',
                finding: 'Domain catalog possui dominios catalogados como scaffold, ainda sem maturidade completa.',
                problem: 'Dominios scaffold sao uteis como mapa, mas nao devem ser comunicados como capacidade plena. Se ficarem invisiveis para o Curator, viram backlog permanente e confundem operador/surface.',
                solution: 'Escolher os scaffolds de maior valor, criar runtime/orchestrator/gates dedicados ou declarar explicitamente que permanecem catalog-ready ate nova fase.',
                domains: $scaffolds->take(10)->all(),
                catalog: $catalog,
                dedupeSeed: 'scaffold-domains:'.implode(',', $scaffolds->pluck('id')->take(10)->all()),
                confidence: 0.78,
            );
        }

        return $findings;
    }

    /**
     * @param  array<int,array<string,mixed>>  $domains
     * @param  array<string,mixed>  $catalog
     * @return array<string,mixed>
     */
    private function domainOnboardingFinding(string $title, string $finding, string $problem, string $solution, array $domains, array $catalog, string $dedupeSeed, float $confidence): array
    {
        return [
            'title' => $title,
            'category' => 'self_improvement',
            'finding' => $finding,
            'problem' => $problem,
            'solution' => $solution,
            'worth_it' => 'Vale porque impede que novas habilidades entrem no Atlas como promessa solta sem runtime, gates, learning e surface contract verificaveis.',
            'best_solution_rationale' => 'Usar o Domain Catalog como fonte de verdade reaproveita o mesmo contrato de CLI, API, observability e architecture validate.',
            'alternatives' => ['Manter o dominio como scaffold documentado.', 'Remover o dominio do catalogo se nao houver intencao real de implementacao.'],
            'source_refs' => collect($domains)
                ->map(fn (array $domain): array => [
                    'type' => 'domain_catalog',
                    'id' => $domain['id'] ?? null,
                    'label' => $domain['label'] ?? null,
                    'onboarding_status' => data_get($domain, 'onboarding.status'),
                    'orchestrator' => $domain['orchestrator'] ?? null,
                    'orchestrator_maturity' => $domain['orchestrator_maturity'] ?? null,
                    'missing_phases' => data_get($domain, 'onboarding.missing_phases', []),
                    'next_actions' => data_get($domain, 'onboarding.next_actions', []),
                ])
                ->values()
                ->all(),
            'confidence' => $confidence,
            'dedupe_key' => 'self-improvement:domain-onboarding:'.sha1($dedupeSeed),
            'metadata' => [
                'domain_count' => data_get($catalog, 'summary.domains', 0),
                'flow_count' => data_get($catalog, 'summary.flows', 0),
                'ready_domains' => data_get($catalog, 'summary.ready_domains', 0),
                'scaffold_domains' => data_get($catalog, 'summary.scaffold_domains', 0),
                'executable_incomplete_domains' => data_get($catalog, 'summary.executable_incomplete_domains', 0),
                'onboarding_status_counts' => data_get($catalog, 'summary.onboarding_status_counts', []),
                'filters' => data_get($catalog, 'filters', []),
            ],
        ];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function architectureValidationFindings(array $filters = []): array
    {
        $payload = $this->architectureValidationPayload();
        $summary = (array) data_get($payload, 'kernel.static_scan.summary', []);
        $failedKeys = (array) ($summary['failed_keys'] ?? []);
        $violationCount = (int) ($summary['violation_count'] ?? 0);
        $status = (string) ($payload['status'] ?? 'unknown');

        if ($status === 'ok' && $failedKeys === [] && $violationCount === 0) {
            return [];
        }

        $checks = collect((array) data_get($payload, 'kernel.static_scan', []))
            ->filter(fn (mixed $check, string $key): bool => str_starts_with($key, 'ap') && is_array($check) && ! (bool) ($check['valid'] ?? false));

        return [[
            'title' => 'Corrigir regressao na arquitetura mae do Atlas AI',
            'category' => 'self_improvement',
            'finding' => "Architecture validation retornou status {$status}, com ".count($failedKeys).' AP(s) falhando e '.$violationCount.' violacao(oes).',
            'problem' => 'Falha em AP arquitetural significa que alguma surface, runtime, provider, domain, MCP, observability ou doc voltou a divergir do contrato kernel.',
            'solution' => 'Abrir proposal revisavel, corrigir o menor contrato quebrado primeiro, rodar architecture validate novamente e so entao promover qualquer mudanca de comportamento.',
            'worth_it' => 'Vale porque transforma drift arquitetural em backlog auditavel antes que vire fluxo paralelo invisivel.',
            'best_solution_rationale' => 'Usar o mesmo AtlasAiArchitectureValidationService de CLI/API/Observability/MCP impede que Self-Improvement invente outra regra de arquitetura.',
            'alternatives' => ['Manter bloqueado como finding ate a proxima janela se o AP estiver sendo editado por outra sessao.', 'Criar waiver documentado apenas se o AP estiver temporariamente falso-positivo.'],
            'source_refs' => $checks
                ->map(fn (array $check, string $key): array => [
                    'type' => 'architecture_validation_ap',
                    'id' => $key,
                    'valid' => (bool) ($check['valid'] ?? false),
                    'violations' => array_values((array) ($check['violations'] ?? [])),
                ])
                ->values()
                ->all(),
            'confidence' => $violationCount > 0 ? 0.94 : 0.86,
            'dedupe_key' => 'self-improvement:architecture-validation:'.sha1(implode(',', $failedKeys).':'.$violationCount.':'.$status),
            'metadata' => [
                'status' => $status,
                'schema_version' => $payload['schema_version'] ?? null,
                'validated_at' => $payload['validated_at'] ?? null,
                'failed_keys' => $failedKeys,
                'failed_count' => (int) ($summary['failed_count'] ?? count($failedKeys)),
                'total_count' => (int) ($summary['total_count'] ?? 0),
                'passed_count' => (int) ($summary['passed_count'] ?? 0),
                'violation_count' => $violationCount,
                'capabilities_valid' => (bool) data_get($payload, 'capabilities.valid', false),
                'domains_valid' => (bool) data_get($payload, 'domains.valid', false),
                'orchestrators_valid' => (bool) data_get($payload, 'orchestrators.valid', false),
                'kernel_valid' => (bool) data_get($payload, 'kernel.valid', false),
                'onboarding' => (array) ($payload['onboarding'] ?? []),
                'filters' => $this->normalizedArchitectureValidationFilters($filters),
            ],
        ]];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function ledgerProjectionDriftFindings(array $filters = []): array
    {
        if (array_filter($filters, fn (?string $value): bool => $value !== null && $value !== '') !== []) {
            return [];
        }

        $payload = $this->architectureValidationPayload();
        $drift = (array) data_get($payload, 'kernel.ledger_projections.drift', []);
        $status = (string) ($drift['status'] ?? 'unknown');
        $available = (bool) ($drift['available'] ?? false);
        $attentionCount = (int) ($drift['attention_count'] ?? 0);

        if (! $available || $status !== 'attention_required' || $attentionCount === 0) {
            return [];
        }

        $projections = collect((array) ($drift['projections'] ?? []))
            ->filter(fn (array $projection): bool => (bool) ($projection['needs_attention'] ?? false))
            ->values();

        if ($projections->isEmpty()) {
            return [];
        }

        $projectionIds = $projections
            ->map(fn (array $projection): string => (string) ($projection['id'] ?? 'unknown'))
            ->values()
            ->all();

        return [[
            'title' => 'Corrigir drift das projection tables do Evidence Ledger',
            'category' => 'self_improvement',
            'finding' => 'O Evidence Ledger possui eventos mais recentes ou eventos-fonte sem projection operacional atualizada.',
            'problem' => 'Quando as projection tables atrasam, UI, CLI, dashboards, Curator e replay podem tomar decisoes com leitura antiga enquanto o ledger append-only ja contem a verdade mais recente.',
            'solution' => 'Revisar o worker incremental de projections, reprocessar os eventos-fonte afetados e manter o ledger como fonte de verdade sem atualizar eventos historicos.',
            'worth_it' => 'Vale porque fecha o loop entre event sourcing e operacao diaria, evitando que a arquitetura mae fique correta no ledger mas invisivel nas surfaces.',
            'best_solution_rationale' => 'Atacar projection drift preserva append-only e melhora observabilidade sem relaxar gates nem duplicar regras por surface.',
            'alternatives' => ['Manter como warning ate o worker incremental existir.', 'Reexecutar apenas a projection afetada em dry-run antes de aplicar backfill.'],
            'available_actions' => [
                ['id' => 'run_ledger_projection', 'label' => 'Rodar projection', 'style' => 'primary'],
                ['id' => 'review_patch', 'label' => 'Revisar evidencia', 'style' => 'secondary'],
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
                ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive', 'requires_confirm' => true],
            ],
            'payload' => [
                'projection_health' => [
                    'schema_version' => $drift['schema_version'] ?? null,
                    'status' => $status,
                    'available' => $available,
                    'projection_count' => (int) ($drift['projection_count'] ?? 0),
                    'drifted_count' => (int) ($drift['drifted_count'] ?? 0),
                    'attention_count' => $attentionCount,
                    'ledger_latest_occurred_at' => $drift['ledger_latest_occurred_at'] ?? null,
                ],
                'ledger_projection' => [
                    'hours' => (int) config('atlas_ai.ledger_projection.hours', 24),
                    'limit' => (int) config('atlas_ai.ledger_projection.limit', 500),
                    'dry_run' => false,
                ],
            ],
            'source_refs' => $projections
                ->map(fn (array $projection): array => [
                    'type' => 'ledger_projection_drift',
                    'id' => (string) ($projection['id'] ?? 'unknown'),
                    'table' => (string) ($projection['table'] ?? 'unknown'),
                    'status' => (string) ($projection['status'] ?? 'unknown'),
                    'source_event_count' => (int) ($projection['source_event_count'] ?? 0),
                    'lag_seconds' => $projection['lag_seconds'] ?? null,
                ])
                ->all(),
            'confidence' => 0.88,
            'dedupe_key' => 'self-improvement:ledger-projection-drift:'.sha1(implode(',', $projectionIds).':'.$status.':'.$attentionCount),
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.ledger_projection_drift.v1',
                'drift_schema_version' => $drift['schema_version'] ?? null,
                'status' => $status,
                'available' => $available,
                'projection_count' => (int) ($drift['projection_count'] ?? 0),
                'drifted_count' => (int) ($drift['drifted_count'] ?? 0),
                'attention_count' => $attentionCount,
                'projection_ids' => $projectionIds,
                'ledger_latest_occurred_at' => $drift['ledger_latest_occurred_at'] ?? null,
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => 'medium',
                    'reason' => 'ledger_projection_drift',
                    'recommended_action' => 'open_reviewable_ledger_projection_backfill_proposal',
                ],
                'filters' => $this->normalizedArchitectureValidationFilters($filters),
            ],
        ]];
    }

    /**
     * @return array<string,mixed>
     */
    private function architectureValidationPayload(): array
    {
        if ($this->architectureValidationPayload === null) {
            $payload = $this->architectureValidation->payload();
            $this->architectureValidationPayload = $payload;
        }

        return $this->architectureValidationPayload;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function documentationHealthFindings(array $filters = []): array
    {
        $payload = $this->architectureValidationPayload();
        $oversized = collect((array) data_get($payload, 'documentation.oversized_docs', []));
        $splitRequired = $oversized
            ->filter(fn (array $doc): bool => (string) ($doc['status'] ?? '') === 'split_required')
            ->values();

        if ($splitRequired->isEmpty()) {
            return [];
        }

        $paths = $splitRequired
            ->map(fn (array $doc): string => (string) ($doc['path'] ?? 'unknown'))
            ->values()
            ->all();
        $largest = $splitRequired
            ->sortByDesc(fn (array $doc): int => (int) ($doc['line_count'] ?? 0))
            ->first();

        return [[
            'title' => 'Dividir documentacao ativa acima do limite de contexto',
            'category' => 'self_improvement',
            'finding' => 'Docs canonicos ativos excederam o limite de linhas da Documentation OS e precisam virar specs menores antes de receber novas responsabilidades.',
            'problem' => 'Documentacao grande demais reduz performance de leitura das IAs, aumenta falso negativo sobre o que ja existe e favorece fluxos duplicados fora da arquitetura mae.',
            'solution' => 'Criar specs pequenas por contrato, mover exemplos longos para archive/source material, atualizar README/START_HERE/canonical index e rodar sync + index-code + architecture-validate.',
            'worth_it' => 'Vale porque documentacao organizada e parte do runtime cognitivo do Atlas: uma nova sessao precisa descobrir estado real sem depender do historico do chat.',
            'best_solution_rationale' => 'Usar o mesmo documentation health report do architecture-validate evita uma segunda regra manual de docs e transforma oversize em backlog revisavel.',
            'alternatives' => ['Manter docs grandfathered somente como referencia Layer 1/2.', 'Arquivar source material quando nao for contrato ativo.'],
            'available_actions' => [
                ['id' => 'review_patch', 'label' => 'Revisar plano de split', 'style' => 'secondary'],
                ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
                ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive', 'requires_confirm' => true],
            ],
            'payload' => [
                'schema_version' => 'atlas.self_improvement.documentation_health_gap.v1',
                'recommended_action' => 'split_oversized_active_docs',
                'largest_doc' => $largest,
                'oversized_count' => (int) data_get($payload, 'documentation.summary.oversized_count', $oversized->count()),
                'split_required_count' => $splitRequired->count(),
                'grandfathered_count' => $oversized
                    ->filter(fn (array $doc): bool => (string) ($doc['status'] ?? '') === 'split_required_grandfathered')
                    ->count(),
            ],
            'source_refs' => $splitRequired
                ->take(12)
                ->map(fn (array $doc): array => [
                    'type' => 'documentation_health',
                    'id' => (string) ($doc['path'] ?? 'unknown'),
                    'line_count' => (int) ($doc['line_count'] ?? 0),
                    'limit' => $doc['limit'] ?? null,
                    'status' => (string) ($doc['status'] ?? 'split_required'),
                    'recommended_action' => (string) ($doc['recommended_action'] ?? 'split this active doc into focused specs'),
                ])
                ->values()
                ->all(),
            'confidence' => $splitRequired->count() > 5 ? 0.9 : 0.84,
            'dedupe_key' => 'self-improvement:documentation-health:'.sha1(implode(',', $paths).':'.$splitRequired->count()),
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.documentation_health_gap.v1',
                'documentation_status' => (string) data_get($payload, 'documentation.status', 'unknown'),
                'doc_count' => (int) data_get($payload, 'documentation.summary.doc_count', 0),
                'oversized_count' => (int) data_get($payload, 'documentation.summary.oversized_count', $oversized->count()),
                'split_required_count' => $splitRequired->count(),
                'paths' => $paths,
                'filters' => $this->normalizedArchitectureValidationFilters($filters),
            ],
        ]];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function architectureOperationsFindings(array $filters = []): array
    {
        $summary = $this->architectureOperations->summary();
        $commands = collect((array) ($summary['commands'] ?? []))
            ->map(fn (array $operation): ?string => is_string($operation['command'] ?? null) ? $operation['command'] : null)
            ->filter()
            ->values()
            ->all();
        $expectedCommands = [
            'atlas ai architecture-operations --json',
            'atlas ai architecture-validate',
            'atlas engineering knowledge docs-health --json',
            'atlas engineering knowledge sync --prune --json',
            'atlas engineering knowledge index-code --prune --json',
            'atlas ai slo --hours=24 --json',
            'atlas ai kernel-pipeline-report --hours=24 --json',
            'atlas ai repair-report --hours=24 --json',
            'atlas ai provider-performance --hours=24 --json',
            'atlas ai qualitative-levels --hours=720 --json',
            'atlas ai rivals-strategy report --hours=8760 --json',
            'atlas ai rivals-strategy due-reviews --due-days=30 --json',
            'atlas ai strategic-decision review --json',
            'atlas ai decision-receipt-report --envelope=<id> --json',
            'atlas ledger replay --envelope=<id> --json',
            'atlas ai ledger-project --limit=500 --json',
            'atlas ai self-improvement-schedule-report --hours=24 --json',
            'atlas ai inbox-action-report --hours=24 --json',
        ];
        $missingCommands = array_values(array_diff($expectedCommands, $commands));
        $section = (string) ($summary['section'] ?? '');
        $commandCount = (int) ($summary['command_count'] ?? 0);
        $actualCommandCount = count($commands);
        $countMismatch = $commandCount !== $actualCommandCount;

        if ($section === 'arquitetura_mae' && $missingCommands === [] && ! $countMismatch) {
            return [];
        }

        return [[
            'title' => 'Corrigir catalogo operacional da arquitetura mae',
            'category' => 'self_improvement',
            'finding' => 'Architecture Operations Catalog perdeu comandos criticos, mudou de secao ou reportou contagem divergente.',
            'problem' => 'Se o catalogo operacional diverge, operador, App, MCP, Observability e sessoes auxiliares deixam de descobrir o mesmo control plane da arquitetura mae.',
            'solution' => 'Restaurar `AtlasArchitectureOperationsCatalog`, validar CLI help, Observability, MCP e surfaces diretas, e rodar `atlas:ai:architecture-validate` antes de promover a mudanca.',
            'worth_it' => 'Vale porque descoberta operacional e parte do produto: comando implementado mas invisivel vira fluxo solto.',
            'best_solution_rationale' => 'O Self-Improvement consome o mesmo catalogo compartilhado das surfaces, entao a auditoria nao cria uma segunda lista manual de comandos.',
            'alternatives' => ['Manter o catalogo em observacao se outra sessao estiver migrando nomes.', 'Criar redirect temporario apenas com AP documentado.'],
            'source_refs' => collect($missingCommands === [] ? $commands : $missingCommands)
                ->take(8)
                ->map(fn (string $command): array => [
                    'type' => $missingCommands === [] ? 'architecture_operation' : 'missing_architecture_operation',
                    'id' => $command,
                    'section' => $section,
                ])
                ->values()
                ->all(),
            'confidence' => $missingCommands === [] ? 0.82 : 0.9,
            'dedupe_key' => 'self-improvement:architecture-operations:'.sha1($section.':'.implode(',', $missingCommands).':'.$commandCount.':'.$actualCommandCount),
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.architecture_operations.v1',
                'section' => $section,
                'command_count' => $commandCount,
                'actual_command_count' => $actualCommandCount,
                'count_mismatch' => $countMismatch,
                'missing_commands' => $missingCommands,
                'expected_commands' => $expectedCommands,
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => $missingCommands === [] ? 'medium' : 'high',
                    'reason' => 'architecture_operations_catalog_drift',
                    'recommended_action' => 'restore_architecture_operations_catalog',
                ],
                'filters' => $this->normalizedArchitectureValidationFilters($filters),
            ],
        ]];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function selfImprovementScheduleFindings(array $filters = []): array
    {
        $health = $this->schedule->scheduleHealth();
        $healthStatus = (string) data_get($health, 'health.status', 'unknown');
        $issues = array_values((array) data_get($health, 'health.issues', []));
        $schedulerStatus = (string) data_get($health, 'scheduler_registration.status', 'unknown');

        if ($healthStatus === 'healthy' && $schedulerStatus === 'registered' && $issues === []) {
            return [];
        }

        return [[
            'title' => 'Corrigir schedule recorrente do Self-Improvement',
            'category' => 'self_improvement',
            'finding' => "Self-Improvement schedule retornou health {$healthStatus} e scheduler {$schedulerStatus}.",
            'problem' => 'Se o schedule recorrente esta desabilitado, invalido ou parcialmente registrado, o Atlas perde o ciclo de madrugada que deveria detectar drift, bugs, gaps e regressao arquitetural.',
            'solution' => 'Abrir proposal revisavel para corrigir config de enabled/time/timezone/flows, validar `atlas:ai:self-improve --schedule-health --fail-on-schedule-warning` e rodar architecture validate depois.',
            'worth_it' => 'Vale porque o ciclo de autoavaliacao e o mecanismo que impede capacidades soltas de continuarem invisiveis.',
            'best_solution_rationale' => 'Consumir AtlasSelfImprovementScheduleService preserva o contrato unico usado por CLI, API, Observability, scheduler e MCP.',
            'alternatives' => ['Manter schedule desabilitado apenas em ambiente local com decisao explicita.', 'Criar waiver temporario se a janela recorrente estiver sendo migrada.'],
            'source_refs' => collect($issues === [] ? [$schedulerStatus] : $issues)
                ->map(fn (string $issue): array => [
                    'type' => 'self_improvement_schedule',
                    'id' => $issue,
                    'health_status' => $healthStatus,
                    'scheduler_status' => $schedulerStatus,
                    'skipped_reason' => data_get($health, 'scheduler_registration.skipped_reason'),
                ])
                ->values()
                ->all(),
            'confidence' => $healthStatus === 'warning' ? 0.9 : 0.84,
            'dedupe_key' => 'self-improvement:schedule-health:'.sha1($healthStatus.':'.$schedulerStatus.':'.implode(',', $issues)),
            'metadata' => [
                'health_status' => $healthStatus,
                'issues' => $issues,
                'actions' => array_values((array) data_get($health, 'health.actions', [])),
                'enabled' => (bool) ($health['enabled'] ?? false),
                'schedulable' => (bool) ($health['schedulable'] ?? false),
                'scheduler_registration' => (array) ($health['scheduler_registration'] ?? []),
                'plan_hash' => $health['plan_hash'] ?? null,
                'plan_hash_algorithm' => $health['plan_hash_algorithm'] ?? null,
                'time' => $health['time'] ?? null,
                'timezone' => $health['timezone'] ?? null,
                'next_run_at' => $health['next_run_at'] ?? null,
                'flow_count' => (int) ($health['flow_count'] ?? 0),
                'cadence_counts' => (array) ($health['cadence_counts'] ?? []),
                'invalid_flow_count' => (int) ($health['invalid_flow_count'] ?? 0),
                'defaulted' => (bool) ($health['defaulted'] ?? false),
                'emit' => (bool) ($health['emit'] ?? false),
                'filters' => $this->normalizedArchitectureValidationFilters($filters),
            ],
        ]];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function rivalsStrategyFindings(int $hours, array $filters = []): array
    {
        if ($filters !== []) {
            return [];
        }

        $report = $this->rivalsStrategy->report(now()->subHours($hours), now()->addDays(365));
        if (! (bool) ($report['available'] ?? false)) {
            return [[
                'title' => 'Ativar storage do Rivals Strategy',
                'category' => 'self_improvement',
                'finding' => 'Rivals Strategy nao esta disponivel para medir decisoes assistidas pelo Atlas.',
                'problem' => 'Sem storage longitudinal, o Atlas nao consegue provar se reduziu arrependimento, preservou agencia ou aumentou alinhamento em decisoes estrategicas.',
                'solution' => 'Rodar migrations do Rivals Strategy e registrar casos apenas quando o operador aprovar acompanhamento.',
                'worth_it' => 'Vale porque P4+ depende de evidencia longitudinal, nao de impressao subjetiva.',
                'best_solution_rationale' => 'Ativar storage cria apenas leitura e acompanhamento interno; nao executa decisoes.',
                'alternatives' => ['Manter P4 bloqueado ate haver storage.', 'Usar benchmark manual temporario com ADR.'],
                'source_refs' => [],
                'confidence' => 0.2,
                'dedupe_key' => 'self-improvement:rivals-strategy:'.sha1('storage-missing'),
                'metadata' => [
                    'schema_version' => 'atlas.self_improvement.rivals_strategy.v1',
                    'review_signal' => [
                        'status' => 'warning',
                        'severity' => 'medium',
                        'reason' => 'rivals_strategy_storage_missing',
                        'recommended_action' => 'run_strategy_rivals_migrations',
                    ],
                    'filters' => $this->normalizedArchitectureValidationFilters($filters),
                ],
            ]];
        }

        $caseCount = (int) ($report['case_count'] ?? 0);
        $scoredCount = (int) ($report['scored_review_count'] ?? 0);
        $agency = $report['average_agency_score'] ?? null;
        $multiplier = $report['strategy_multiplier_score'] ?? null;
        $due = $this->rivalsStrategy->dueReviews(30, 20);
        $dueCount = (int) ($due['due_review_count'] ?? 0);

        if ($caseCount === 0) {
            return [[
                'title' => 'Criar primeiro caso de Rivals Strategy',
                'category' => 'self_improvement',
                'finding' => 'Nenhuma decisao estrategica foi registrada para comparacao longitudinal.',
                'problem' => 'Sem casos, o Atlas nao mede se suas revisoes estrategicas estao ajudando ou apenas parecendo sofisticadas.',
                'solution' => 'Registrar caso somente quando uma revisao estrategica importante for aceita pelo operador, preservando baseline e escolha assistida.',
                'worth_it' => 'Vale porque cria dataset proprio para comparar Atlas vs decisao direta.',
                'best_solution_rationale' => 'O registro e explicito e interno; nao muda decisao, apenas agenda revisitas.',
                'alternatives' => ['Esperar proxima decisao high-impact.', 'Criar caso retroativo apenas se houver evidencia suficiente.'],
                'source_refs' => [],
                'confidence' => 0.2,
                'dedupe_key' => 'self-improvement:rivals-strategy:'.sha1('no-cases'),
                'metadata' => [
                    'schema_version' => 'atlas.self_improvement.rivals_strategy.v1',
                    'case_count' => $caseCount,
                    'scored_review_count' => $scoredCount,
                    'review_signal' => [
                        'status' => 'warning',
                        'severity' => 'medium',
                        'reason' => 'no_strategy_rivals_cases',
                        'recommended_action' => 'register_first_strategy_rivals_case',
                    ],
                    'filters' => $this->normalizedArchitectureValidationFilters($filters),
                ],
            ]];
        }

        if (is_numeric($agency) && (float) $agency < 70) {
            return [[
                'title' => 'Bloquear claims P4 por agency score baixo',
                'category' => 'self_improvement',
                'finding' => 'Rivals Strategy detectou agency score medio abaixo do minimo para co-estrategista seguro.',
                'problem' => 'Se o Atlas melhora decisoes mas reduz agencia do operador, o ganho e perigoso e nao deve promover patamar.',
                'solution' => 'Revisar linguagem, defaults, autonomia e gates de decisao estrategica antes de qualquer claim P4+.',
                'worth_it' => 'Vale porque preserva o principio central: Atlas multiplica Vitor, nao substitui Vitor.',
                'best_solution_rationale' => 'Bloquear por agency score usa evidencia longitudinal e impede auto-promocao do sistema.',
                'alternatives' => ['Reduzir autonomia para review-only estrito.', 'Exigir cool-down maior para high/critical.'],
                'source_refs' => collect((array) ($report['recent_cases'] ?? []))
                    ->take(5)
                    ->map(fn (array $case): array => [
                        'type' => 'rivals_strategy_case',
                        'id' => (string) ($case['id'] ?? 'unknown'),
                        'title' => (string) ($case['title'] ?? ''),
                    ])
                    ->values()
                    ->all(),
                'confidence' => 0.93,
                'dedupe_key' => 'self-improvement:rivals-strategy:'.sha1('agency-low:'.(string) $agency),
                'metadata' => [
                    'schema_version' => 'atlas.self_improvement.rivals_strategy.v1',
                    'average_agency_score' => $agency,
                    'strategy_multiplier_score' => $multiplier,
                    'review_signal' => [
                        'status' => 'warning',
                        'severity' => 'high',
                        'reason' => 'agency_score_below_threshold',
                        'recommended_action' => 'pause_p4_claims_and_review_operator_agency',
                    ],
                    'filters' => $this->normalizedArchitectureValidationFilters($filters),
                ],
            ]];
        }

        if ($dueCount > 0) {
            return [[
                'title' => 'Registrar revisitas pendentes do Rivals Strategy',
                'category' => 'self_improvement',
                'finding' => "Rivals Strategy possui {$dueCount} revisita(s) pendente(s) nos proximos 30 dias.",
                'problem' => 'Revisitas pendentes sem score deixam Qualitative Levels e o Curator sem evidencia para validar se Atlas reduziu arrependimento e preservou agencia.',
                'solution' => 'Usar os `record_command` listados para registrar regret, alignment e agency score apos revisao humana.',
                'worth_it' => 'Vale porque transforma acompanhamento agendado em evidencia longitudinal utilizavel.',
                'best_solution_rationale' => 'A fila vem do read model canonico `due-reviews`, entao CLI, API e Self-Improvement enxergam a mesma pendencia.',
                'alternatives' => ['Adiar ate haver evidencia de outcome.', 'Arquivar o caso se a decisao deixou de ser relevante.'],
                'available_actions' => [
                    ['id' => 'record_rivals_review', 'label' => 'Registrar score', 'style' => 'primary'],
                    ['id' => 'discuss', 'label' => 'Discutir com Atlas', 'style' => 'default'],
                    ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive', 'requires_confirm' => true],
                ],
                'payload' => [
                    'rivals_strategy' => [
                        'due_review_count' => $dueCount,
                        'due_until' => $due['due_until'] ?? null,
                        'record_command_template' => 'atlas ai rivals-strategy record-review --review-id=<id> --regret=<0-100> --alignment=<0-100> --agency=<0-100> --json',
                    ],
                    'due_reviews' => collect((array) ($due['due_reviews'] ?? []))
                        ->take(10)
                        ->map(fn (array $review): array => [
                            'review_id' => (string) ($review['id'] ?? 'unknown'),
                            'case_id' => (string) ($review['case_id'] ?? ''),
                            'case_title' => (string) ($review['case_title'] ?? ''),
                            'horizon_days' => (int) ($review['horizon_days'] ?? 0),
                            'review_due_at' => $review['review_due_at'] ?? null,
                            'record_command' => (string) ($review['record_command'] ?? ''),
                        ])
                        ->values()
                        ->all(),
                ],
                'source_refs' => collect((array) ($due['due_reviews'] ?? []))
                    ->take(10)
                    ->map(fn (array $review): array => [
                        'type' => 'rivals_strategy_due_review',
                        'id' => (string) ($review['id'] ?? 'unknown'),
                        'case_id' => (string) ($review['case_id'] ?? ''),
                        'case_title' => (string) ($review['case_title'] ?? ''),
                        'horizon_days' => (int) ($review['horizon_days'] ?? 0),
                        'review_due_at' => $review['review_due_at'] ?? null,
                        'record_command' => (string) ($review['record_command'] ?? ''),
                    ])
                    ->values()
                    ->all(),
                'confidence' => 0.88,
                'dedupe_key' => 'self-improvement:rivals-strategy:'.sha1('due-reviews:'.$dueCount),
                'metadata' => [
                    'schema_version' => 'atlas.self_improvement.rivals_strategy.v1',
                    'case_count' => $caseCount,
                    'scheduled_review_count' => (int) ($report['scheduled_review_count'] ?? 0),
                    'scored_review_count' => $scoredCount,
                    'due_review_count' => $dueCount,
                    'due_until' => $due['due_until'] ?? null,
                    'review_signal' => [
                        'status' => 'warning',
                        'severity' => 'low',
                        'reason' => 'rivals_strategy_reviews_due',
                        'recommended_action' => 'record_due_rivals_strategy_reviews',
                    ],
                    'filters' => $this->normalizedArchitectureValidationFilters($filters),
                ],
            ]];
        }

        if ($scoredCount === 0) {
            return [[
                'title' => 'Pontuar revisitas do Rivals Strategy',
                'category' => 'self_improvement',
                'finding' => 'Rivals Strategy tem casos registrados, mas ainda nao possui revisoes pontuadas.',
                'problem' => 'Casos sem regret, alignment e agency score nao podem alimentar Qualitative Levels nem validar P4/P5.',
                'solution' => 'Quando uma revisita vencer, registrar scores com `atlas:ai:rivals-strategy record-review` ou API equivalente.',
                'worth_it' => 'Vale porque transforma memoria de decisao em evidencia comparavel.',
                'best_solution_rationale' => 'Pontuar revisitas preserva julgamento humano e evita autoelogio sem dado.',
                'alternatives' => ['Aguardar horizonte 30/90/180/365.', 'Marcar caso como arquivado se nao houver evidencia.'],
                'source_refs' => collect((array) ($report['recent_cases'] ?? []))
                    ->take(5)
                    ->map(fn (array $case): array => [
                        'type' => 'rivals_strategy_case',
                        'id' => (string) ($case['id'] ?? 'unknown'),
                        'title' => (string) ($case['title'] ?? ''),
                        'review_count' => (int) ($case['review_count'] ?? 0),
                    ])
                    ->values()
                    ->all(),
                'confidence' => 0.2,
                'dedupe_key' => 'self-improvement:rivals-strategy:'.sha1('unscored-cases:'.$caseCount),
                'metadata' => [
                    'schema_version' => 'atlas.self_improvement.rivals_strategy.v1',
                    'case_count' => $caseCount,
                    'scheduled_review_count' => (int) ($report['scheduled_review_count'] ?? 0),
                    'scored_review_count' => $scoredCount,
                    'review_signal' => [
                        'status' => 'warning',
                        'severity' => 'low',
                        'reason' => 'waiting_for_scored_revisits',
                        'recommended_action' => 'record_due_rivals_strategy_reviews',
                    ],
                    'filters' => $this->normalizedArchitectureValidationFilters($filters),
                ],
            ]];
        }

        return [];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function selfImprovementScheduleReplayFindings(int $hours, array $filters = []): array
    {
        if ($this->normalizedDomainOnboardingFilters($filters) !== []) {
            return [];
        }

        $report = $this->replay->selfImprovementScheduleReportForWindow(now()->subHours($hours));
        $reviewSignal = (array) ($report['review_signal'] ?? []);
        $warningCount = (int) ($report['warning_count'] ?? 0);
        $issueCounts = (array) ($report['issue_counts'] ?? []);
        $missingInboxItemIds = array_values((array) ($report['emitted_inbox_item_missing_ids'] ?? []));

        if (! (bool) ($report['available'] ?? false)) {
            return [];
        }

        $findings = [];
        if ($missingInboxItemIds !== []) {
            $missingEvents = collect((array) ($report['recent_events'] ?? []))
                ->filter(fn (array $event): bool => ((array) ($event['emitted_inbox_item_missing_ids'] ?? [])) !== [])
                ->values();
            $findings[] = [
                'title' => 'Restaurar propostas do Inbox emitidas pelo Self-Improvement',
                'category' => 'self_improvement',
                'finding' => 'Schedule replay encontrou refs de propostas emitidas que nao resolveram para itens acionaveis do Inbox.',
                'problem' => 'Quando `OPERATION_COMPLETED` aponta para uma proposta que nao existe mais no Inbox, auditoria, revisao humana e aprendizado ficam quebrados no ponto mais importante do loop.',
                'solution' => 'Investigar se os itens foram apagados, expirados incorretamente ou se o emitter gravou IDs inconsistentes; restaurar a proposta ou corrigir a emissao para manter Ledger e Inbox alinhados.',
                'worth_it' => 'Vale porque fecha a cadeia Evidence Ledger -> Proposal Inbox -> review humano, que e essencial para autoaprimoramento seguro.',
                'best_solution_rationale' => 'Consumir o gap do replay evita varrer tabelas manualmente e preserva o Ledger como fonte de causalidade.',
                'alternatives' => ['Criar waiver temporario se a proposta foi removida por politica de retencao documentada.', 'Reemitir a proposta com novo ID e registrar link de supersede.'],
                'source_refs' => $missingEvents
                    ->take(5)
                    ->map(fn (array $event): array => [
                        'type' => 'ledger_event',
                        'id' => $event['event_id'] ?? null,
                        'envelope_id' => $event['envelope_id'] ?? null,
                        'emitted_inbox_item_missing_ids' => array_values((array) ($event['emitted_inbox_item_missing_ids'] ?? [])),
                        'emitted_inbox_item_hydration_available' => (bool) ($event['emitted_inbox_item_hydration_available'] ?? false),
                        'occurred_at' => $event['occurred_at'] ?? null,
                    ])
                    ->values()
                    ->all(),
                'confidence' => 0.92,
                'dedupe_key' => 'self-improvement:schedule-replay-inbox-gap:'.sha1(count($missingInboxItemIds).':'.implode(',', $missingInboxItemIds)),
                'metadata' => [
                    'schema_version' => 'atlas.self_improvement.schedule_replay_inbox_gap.v1',
                    'hours' => $hours,
                    'missing_count' => count($missingInboxItemIds),
                    'emitted_inbox_item_missing_ids' => $missingInboxItemIds,
                    'emitted_inbox_item_hydration_available' => (bool) ($report['emitted_inbox_item_hydration_available'] ?? false),
                    'schedule_observation_count' => (int) ($report['schedule_observation_count'] ?? 0),
                    'envelope_count' => (int) ($report['envelope_count'] ?? 0),
                    'review_signal' => [
                        'status' => 'warning',
                        'severity' => 'medium',
                        'review_required' => true,
                        'reason' => 'self_improvement_schedule_replay_missing_inbox_items',
                        'recommended_action' => 'restore_or_reemit_missing_self_improvement_inbox_items',
                    ],
                    'filters' => $this->normalizedArchitectureValidationFilters($filters),
                ],
            ];
        }

        if (! (bool) ($reviewSignal['review_required'] ?? false) || $warningCount === 0) {
            return $findings;
        }

        $warningEvents = collect((array) ($report['recent_events'] ?? []))
            ->filter(fn (array $event): bool => in_array($event['health_status'] ?? null, ['warning', 'disabled'], true)
                || ($event['scheduler_status'] ?? null) === 'skipped'
                || (int) ($event['invalid_flow_count'] ?? 0) > 0
                || ((array) ($event['issues'] ?? [])) !== [])
            ->values();
        $issues = array_keys($issueCounts);
        $issueText = $issues === [] ? 'self_improvement_schedule_warning_observed' : implode(', ', $issues);
        $latestHealthStatus = (string) ($report['latest_health_status'] ?? 'unknown');
        $latestSchedulerStatus = (string) ($report['latest_scheduler_status'] ?? 'unknown');

        $findings[] = [
            'title' => 'Investigar drift recorrente no schedule do Self-Improvement',
            'category' => 'self_improvement',
            'finding' => "Replay do Evidence Ledger encontrou {$warningCount} observacao(oes) de schedule com warning na janela analisada.",
            'problem' => 'O health atual pode estar saudavel, mas warnings recorrentes no ledger indicam drift intermitente de config, scheduler, flows ou ambiente.',
            'solution' => 'Abrir proposal revisavel para comparar eventos recentes, estabilizar enabled/time/timezone/flows, validar o scheduler e manter o fix atras de policy/receipt.',
            'worth_it' => 'Vale porque evita que o ciclo de madrugada pare de funcionar silenciosamente entre uma execucao e outra.',
            'best_solution_rationale' => 'Usar o replay canonico preserva causalidade historica e impede que o Curator crie detector paralelo fora do Evidence Ledger.',
            'alternatives' => ['Continuar observando por mais uma janela se houve migracao recente.', 'Criar waiver temporario apenas com evidencia do ledger e prazo de expiracao.'],
            'source_refs' => $warningEvents
                ->take(5)
                ->map(fn (array $event): array => [
                    'type' => 'ledger_event',
                    'id' => $event['event_id'] ?? null,
                    'envelope_id' => $event['envelope_id'] ?? null,
                    'health_status' => $event['health_status'] ?? null,
                    'scheduler_status' => $event['scheduler_status'] ?? null,
                    'issues' => array_values((array) ($event['issues'] ?? [])),
                    'occurred_at' => $event['occurred_at'] ?? null,
                ])
                ->values()
                ->all(),
            'confidence' => $warningCount > 1 ? 0.9 : 0.84,
            'dedupe_key' => 'self-improvement:schedule-replay:'.sha1($warningCount.':'.$issueText),
            'metadata' => [
                'hours' => $hours,
                'schedule_observation_count' => (int) ($report['schedule_observation_count'] ?? 0),
                'envelope_count' => (int) ($report['envelope_count'] ?? 0),
                'warning_count' => $warningCount,
                'health_status_counts' => (array) ($report['health_status_counts'] ?? []),
                'scheduler_status_counts' => (array) ($report['scheduler_status_counts'] ?? []),
                'issue_counts' => $issueCounts,
                'latest_health_status' => $latestHealthStatus,
                'latest_scheduler_status' => $latestSchedulerStatus,
                'latest_plan_hash' => $report['latest_plan_hash'] ?? null,
                'latest_next_run_at' => $report['latest_next_run_at'] ?? null,
                'review_required' => (bool) ($report['review_required'] ?? false),
                'health' => (array) ($report['health'] ?? []),
                'review_signal' => $reviewSignal,
                'filters' => $this->normalizedArchitectureValidationFilters($filters),
            ],
        ];

        return $findings;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function inboxActionReplayFindings(int $hours, array $filters = []): array
    {
        if ($this->normalizedDomainOnboardingFilters($filters) !== []) {
            return [];
        }

        $report = $this->replay->inboxActionReportForWindow(
            now()->subHours($hours),
            null,
            $this->normalizedInboxActionFilters($filters),
        );
        $reviewSignal = (array) ($report['review_signal'] ?? []);
        $gapReason = 'review_patch_action_without_diff_refs';
        $rivalsGapReason = 'record_rivals_review_action_without_scores';
        $proposalAction = 'open_reviewable_inbox_action_evidence_proposal';

        if (! (bool) ($report['available'] ?? false)
            || ! (bool) ($reviewSignal['review_required'] ?? false)
            || ($reviewSignal['recommended_action'] ?? null) !== $proposalAction) {
            return [];
        }

        if (in_array($rivalsGapReason, (array) ($reviewSignal['reasons'] ?? []), true)) {
            $recentEvents = collect((array) ($report['recent_events'] ?? []))
                ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'record_rivals_review')
                ->filter(fn (array $event): bool => ! is_numeric($event['rivals_regret_score'] ?? null)
                    || ! is_numeric($event['rivals_alignment_score'] ?? null)
                    || ! is_numeric($event['rivals_agency_score'] ?? null))
                ->values();

            if ($recentEvents->isEmpty()) {
                return [];
            }

            return [[
                'title' => 'Corrigir revisao do Rivals Strategy sem scores humanos',
                'category' => 'self_improvement',
                'finding' => 'Replay do Evidence Ledger encontrou action `record_rivals_review` sem regret, alignment ou agency completos.',
                'problem' => 'Rivals Strategy so pode sustentar patamares P4+ quando a revisita tem scores humanos completos. Sem esses campos, o Atlas pode interpretar uma revisita como evidência sem base suficiente.',
                'solution' => 'Revisar o item/ledger event, registrar novamente a revisita via `record_rivals_review` com os tres scores ou marcar o evento antigo como nao confiavel por waiver documentado.',
                'worth_it' => 'Vale porque esse score e a trava que impede claims qualitativos sem evidencia longitudinal real.',
                'best_solution_rationale' => 'Consumir `inboxActionReportForWindow` preserva o Evidence Ledger como fonte unica e evita queries paralelas em tabelas do Rivals Strategy.',
                'alternatives' => ['Abrir discussao com o operador antes de pontuar.', 'Criar waiver apenas se o evento for legado e nao for usado em gates P4+.'],
                'source_refs' => $recentEvents
                    ->take(5)
                    ->map(fn (array $event): array => [
                        'type' => 'ledger_event',
                        'id' => $event['event_id'] ?? null,
                        'envelope_id' => $event['envelope_id'] ?? null,
                        'inbox_item_id' => $event['inbox_item_id'] ?? null,
                        'action' => $event['action'] ?? null,
                        'actor_type' => $event['actor_type'] ?? null,
                        'recommended_action' => $event['recommended_action'] ?? null,
                        'rivals_review_id' => $event['rivals_review_id'] ?? null,
                        'rivals_case_id' => $event['rivals_case_id'] ?? null,
                        'rivals_regret_score' => $event['rivals_regret_score'] ?? null,
                        'rivals_alignment_score' => $event['rivals_alignment_score'] ?? null,
                        'rivals_agency_score' => $event['rivals_agency_score'] ?? null,
                        'occurred_at' => $event['occurred_at'] ?? null,
                    ])
                    ->values()
                    ->all(),
                'confidence' => 0.9,
                'dedupe_key' => 'self-improvement:inbox-action-replay:'.sha1($hours.':'.$rivalsGapReason),
                'metadata' => [
                    'schema_version' => 'atlas.self_improvement.inbox_action_replay_gap.v1',
                    'gap_type' => $rivalsGapReason,
                    'hours' => $hours,
                    'inbox_action_count' => (int) ($report['inbox_action_count'] ?? 0),
                    'rivals_review_recorded_count' => (int) ($report['rivals_review_recorded_count'] ?? 0),
                    'rivals_review_with_scores_count' => (int) ($report['rivals_review_with_scores_count'] ?? 0),
                    'action_counts' => (array) ($report['action_counts'] ?? []),
                    'actor_type_counts' => (array) ($report['actor_type_counts'] ?? []),
                    'recommended_action_counts' => (array) ($report['recommended_action_counts'] ?? []),
                    'review_signal' => $reviewSignal,
                    'filters' => $this->normalizedInboxActionFilters($filters),
                ],
            ]];
        }

        if (! in_array($gapReason, (array) ($reviewSignal['reasons'] ?? []), true)) {
            return [];
        }

        $recentEvents = collect((array) ($report['recent_events'] ?? []))
            ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'review_patch'
                && (int) ($event['diff_ref_count'] ?? 0) === 0)
            ->values();

        if ($recentEvents->isEmpty()) {
            return [];
        }

        return [[
            'title' => 'Restaurar contexto de patch nas revisoes humanas do Inbox',
            'category' => 'self_improvement',
            'finding' => 'Replay do Evidence Ledger encontrou actions `review_patch` sem `diff_refs` preservados.',
            'problem' => 'Quando uma revisao humana confirma ou analisa patch sem refs de diff, o Atlas perde a trilha auditavel entre proposta, artefato revisado e aprendizado posterior.',
            'solution' => 'Corrigir o contrato do emitter/action para sempre anexar `diff_refs` ou marcar explicitamente que nao havia patch aplicavel, mantendo o evento `INBOX_ACTION_RECORDED` reprodutivel.',
            'worth_it' => 'Vale porque revisao humana e uma das barreiras de seguranca do Atlas; sem contexto de patch, o Curator nao consegue aprender com a decisao do operador.',
            'best_solution_rationale' => 'Consumir `inboxActionReportForWindow` mantem o Evidence Ledger como fonte unica e evita novo scanner manual sobre payloads do Inbox.',
            'alternatives' => ['Criar waiver apenas para proposals sem artefato de diff por desenho.', 'Converter actions sem diff em discussao antes de permitir marcar como revisado.'],
            'source_refs' => $recentEvents
                ->take(5)
                ->map(fn (array $event): array => [
                    'type' => 'ledger_event',
                    'id' => $event['event_id'] ?? null,
                    'envelope_id' => $event['envelope_id'] ?? null,
                    'inbox_item_id' => $event['inbox_item_id'] ?? null,
                    'action' => $event['action'] ?? null,
                    'actor_type' => $event['actor_type'] ?? null,
                    'recommended_action' => $event['recommended_action'] ?? null,
                    'diff_ref_count' => (int) ($event['diff_ref_count'] ?? 0),
                    'occurred_at' => $event['occurred_at'] ?? null,
                ])
                ->values()
                ->all(),
            'confidence' => 0.88,
            'dedupe_key' => 'self-improvement:inbox-action-replay:'.sha1($hours.':'.implode(',', (array) ($reviewSignal['reasons'] ?? []))),
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.inbox_action_replay_gap.v1',
                'hours' => $hours,
                'inbox_action_count' => (int) ($report['inbox_action_count'] ?? 0),
                'reviewed_patch_count' => (int) ($report['reviewed_patch_count'] ?? 0),
                'with_diff_refs_count' => (int) ($report['with_diff_refs_count'] ?? 0),
                'action_counts' => (array) ($report['action_counts'] ?? []),
                'actor_type_counts' => (array) ($report['actor_type_counts'] ?? []),
                'recommended_action_counts' => (array) ($report['recommended_action_counts'] ?? []),
                'review_signal' => $reviewSignal,
                'filters' => $this->normalizedInboxActionFilters($filters),
            ],
        ]];
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function decisionReceiptReplayFindings(Collection $events, array $filters = []): array
    {
        if (isset($this->normalizedDomainOnboardingFilters($filters)['onboarding_status'])) {
            return [];
        }

        $decisionEvents = $events
            ->filter(fn (AtlasLedgerEvent $event): bool => $event->event_type === LedgerEventType::DecisionIssued->value)
            ->filter(fn (AtlasLedgerEvent $event): bool => $this->matchesDecisionReceiptFilters($event, $filters))
            ->values();

        if ($decisionEvents->isEmpty()) {
            return [];
        }

        $reports = $decisionEvents
            ->pluck('envelope_id')
            ->filter(fn (mixed $envelopeId): bool => is_string($envelopeId) && $envelopeId !== '')
            ->unique()
            ->map(fn (string $envelopeId): array => $this->replay->decisionReceiptReportForEnvelope($envelopeId))
            ->filter(fn (array $report): bool => (bool) data_get($report, 'review_signal.review_required', false)
                && data_get($report, 'review_signal.recommended_action') === 'open_reviewable_decision_receipt_replay_proposal')
            ->values();

        if ($reports->isEmpty()) {
            return [];
        }

        $invalidEvents = $reports
            ->flatMap(fn (array $report): array => collect((array) ($report['events'] ?? []))
                ->filter(fn (array $event): bool => in_array('mismatch', [
                    $event['receipt_integrity_status'] ?? null,
                    $event['chain_integrity_status'] ?? null,
                ], true))
                ->all())
            ->values();
        $reasons = $reports
            ->flatMap(fn (array $report): array => (array) data_get($report, 'review_signal.reasons', []))
            ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
            ->unique()
            ->values()
            ->all();
        $envelopeIds = $reports
            ->pluck('envelope_id')
            ->filter()
            ->values()
            ->all();

        return [[
            'title' => 'Auditar cadeia de DecisionReceipt adulterada',
            'category' => 'self_improvement',
            'finding' => 'DecisionReceipt replay encontrou divergencia de `receipt_hash` ou `chain_hash` na janela analisada.',
            'problem' => 'Quando a cadeia de DecisionReceipt diverge, o Atlas perde a garantia de que Decide, runtime e replay estao obedecendo o mesmo contrato assinado antes do provider.',
            'solution' => 'Abrir proposal revisavel, comparar eventos DECISION_ISSUED do envelope, corrigir emissao/propagacao do receipt e bloquear qualquer runtime que aceite receipt com hash divergente.',
            'worth_it' => 'Vale porque protege o ponto mais sensivel da arquitetura mae: provider e runtime nao podem operar com uma decisao que nao bate com o contrato assinado.',
            'best_solution_rationale' => 'Consumir `decisionReceiptReportForEnvelope()` preserva uma unica fonte de verificacao criptografica e evita o Curator recalcular hashes com regra paralela.',
            'alternatives' => ['Manter observacao se os eventos vierem de fixture legada explicitamente marcada.', 'Arquivar como falso positivo apenas com evidence ledger e waiver auditavel.'],
            'source_refs' => $invalidEvents
                ->take(5)
                ->map(fn (array $event): array => [
                    'type' => 'ledger_event',
                    'id' => $event['event_id'] ?? null,
                    'envelope_id' => $event['envelope_id'] ?? null,
                    'receipt_id' => $event['receipt_id'] ?? null,
                    'receipt_integrity_status' => $event['receipt_integrity_status'] ?? null,
                    'chain_integrity_status' => $event['chain_integrity_status'] ?? null,
                    'provider' => $event['provider'] ?? null,
                    'model' => $event['model'] ?? null,
                    'occurred_at' => $event['occurred_at'] ?? null,
                ])
                ->values()
                ->all(),
            'confidence' => 0.94,
            'dedupe_key' => 'self-improvement:decision-receipt-replay:'.sha1(implode(',', $envelopeIds).':'.implode(',', $reasons)),
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.decision_receipt_replay_gap.v1',
                'envelope_count' => $reports->count(),
                'decision_event_count' => $reports->sum(fn (array $report): int => (int) ($report['decision_event_count'] ?? 0)),
                'invalid_count' => $reports->sum(fn (array $report): int => (int) ($report['invalid_count'] ?? 0)),
                'valid_receipt_hash_count' => $reports->sum(fn (array $report): int => (int) ($report['valid_receipt_hash_count'] ?? 0)),
                'valid_chain_hash_count' => $reports->sum(fn (array $report): int => (int) ($report['valid_chain_hash_count'] ?? 0)),
                'affected_envelope_ids' => $envelopeIds,
                'reasons' => $reasons,
                'review_signal' => [
                    'status' => 'breach',
                    'severity' => 'high',
                    'review_required' => true,
                    'reasons' => $reasons,
                    'recommended_action' => 'open_reviewable_decision_receipt_replay_proposal',
                ],
                'filters' => $this->normalizedDecisionReceiptFilters($filters),
            ],
        ]];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedDimensionFilters(array $filters): array
    {
        $normalized = [];
        foreach (['domain', 'flow', 'surface_id', 'provider', 'model', 'runtime', 'tool_id'] as $key) {
            $value = $filters[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $normalized[$key] = trim((string) $value);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedRepairFilters(array $filters): array
    {
        $normalized = [];
        foreach (['status', 'strategy', 'failure_domain', 'emitter_stage'] as $key) {
            $value = $filters[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $normalized[$key] = trim((string) $value);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedKernelPipelineFilters(array $filters): array
    {
        $normalized = [];
        foreach (['status', 'surface_id', 'flow', 'input_mode', 'emitter_stage'] as $key) {
            $value = $filters[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $normalized[$key] = trim((string) $value);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedDomainOnboardingFilters(array $filters): array
    {
        $normalized = [];

        foreach ([
            'domain' => 'domain',
            'flow' => 'flow',
            'onboarding_status' => 'onboarding_status',
        ] as $key => $catalogKey) {
            $value = $filters[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $normalized[$catalogKey] = trim((string) $value);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedArchitectureValidationFilters(array $filters): array
    {
        $normalized = [];
        foreach (['status', 'domain', 'surface_id', 'provider', 'flow'] as $key) {
            $value = $filters[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $normalized[$key] = trim((string) $value);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedProviderPerformanceFilters(array $filters): array
    {
        $normalized = [];
        foreach (['provider', 'provider_cli', 'domain', 'flow', 'task_type', 'specialist_profile', 'risk', 'selection_mode'] as $key) {
            $value = $filters[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $normalized[$key === 'provider' ? 'provider_cli' : $key] = trim((string) $value);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedInboxActionFilters(array $filters): array
    {
        $normalized = [];
        foreach (['action', 'actor_type', 'inbox_item_category', 'inbox_item_severity', 'recommended_action', 'source_type'] as $key) {
            $value = $filters[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $normalized[$key] = trim((string) $value);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedDecisionReceiptFilters(array $filters): array
    {
        $normalized = [];
        foreach (['domain', 'flow', 'provider', 'model', 'risk'] as $key) {
            $value = $filters[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $normalized[$key] = trim((string) $value);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,string|null>  $filters
     */
    private function matchesDecisionReceiptFilters(AtlasLedgerEvent $event, array $filters): bool
    {
        foreach ($this->normalizedDecisionReceiptFilters($filters) as $key => $value) {
            $actual = match ($key) {
                'provider' => data_get($event->payload, 'provider_selection.primary', data_get($event->payload, 'provider_selection.provider')),
                'model' => data_get($event->payload, 'provider_selection.model'),
                default => data_get($event->payload, $key),
            };

            if (! is_scalar($actual) || trim((string) $actual) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedRuntimeFilters(array $filters): array
    {
        return [
            ...$this->normalizedDimensionFilters($filters),
            ...$this->normalizedRepairFilters($filters),
            ...$this->normalizedKernelPipelineFilters($filters),
            ...$this->normalizedDomainOnboardingFilters($filters),
            ...$this->normalizedInboxActionFilters($filters),
            ...$this->normalizedDecisionReceiptFilters($filters),
        ];
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    private function missingTerminalFindings(Collection $events): array
    {
        $terminal = [
            LedgerEventType::OperationCompleted->value,
            LedgerEventType::OperationFailed->value,
            LedgerEventType::OperationBlocked->value,
            LedgerEventType::OperationNeedsReview->value,
        ];

        return $events
            ->where('event_type', LedgerEventType::ExecutionStarted->value)
            ->groupBy('envelope_id')
            ->filter(fn (Collection $started, string $envelopeId): bool => $events
                ->where('envelope_id', $envelopeId)
                ->whereIn('event_type', $terminal)
                ->isEmpty())
            ->map(fn (Collection $started, string $envelopeId): array => [
                'title' => 'Fechar envelopes sem evento terminal',
                'category' => 'self_improvement',
                'finding' => "Envelope {$envelopeId} iniciou execucao, mas nao registrou evento terminal no Evidence Ledger.",
                'problem' => 'Sem evento terminal, replay, metricas de sucesso/falha e aprendizado ficam incompletos.',
                'solution' => 'Instrumentar o emissor responsavel para publicar OPERATION_COMPLETED, OPERATION_FAILED, OPERATION_BLOCKED ou OPERATION_NEEDS_REVIEW.',
                'worth_it' => 'Vale porque completa a linha do tempo auditavel e evita que o Atlas aprenda com runs inacabados.',
                'best_solution_rationale' => 'Corrigir a instrumentacao do emissor preserva o contrato do kernel sem inventar regra especial por surface.',
                'alternatives' => ['Manter como warning ate confirmar se o processo ainda estava em andamento.', 'Adicionar TTL antes de considerar o envelope incompleto.'],
                'source_refs' => [['type' => 'ledger_envelope', 'id' => $envelopeId]],
                'confidence' => 0.86,
                'dedupe_key' => 'self-improvement:missing-terminal:'.sha1($envelopeId),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    private function operationFailureFindings(Collection $events): array
    {
        return $events
            ->where('event_type', LedgerEventType::OperationFailed->value)
            ->groupBy('emitter_stage')
            ->filter(fn (Collection $group): bool => $group->count() >= 1)
            ->map(fn (Collection $group, string $stage): array => [
                'title' => "Reduzir falhas em {$stage}",
                'category' => 'self_improvement',
                'finding' => "{$group->count()} operacao(oes) falharam em {$stage} na janela analisada.",
                'problem' => 'Falhas repetidas por stage indicam lacuna de policy, provider, gate, repair ou contexto.',
                'solution' => 'Agrupar por envelope, comparar payload_hash e criar teste/regra de repair para a causa mais comum antes de alterar comportamento.',
                'worth_it' => 'Vale porque transforma falha operacional em backlog priorizado por evidencia real.',
                'best_solution_rationale' => 'Atacar a causa mais frequente reduz risco sem autoaplicar mudanca critica.',
                'alternatives' => ['Apenas observar por mais uma janela.', 'Abrir investigacao manual sem patch.'],
                'source_refs' => $group->take(5)->map(fn (AtlasLedgerEvent $event): array => [
                    'type' => 'ledger_event',
                    'id' => $event->event_id,
                    'envelope_id' => $event->envelope_id,
                ])->values()->all(),
                'confidence' => 0.82,
                'dedupe_key' => 'self-improvement:operation-failed:'.sha1($stage),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    private function gateBlockedFindings(Collection $events): array
    {
        return $events
            ->where('event_type', LedgerEventType::GateBlocked->value)
            ->groupBy(fn (AtlasLedgerEvent $event): string => (string) data_get($event->payload, 'gate_type', $event->emitter_stage))
            ->map(fn (Collection $group, string $gate): array => [
                'title' => "Analisar gate bloqueando {$gate}",
                'category' => 'self_improvement',
                'finding' => "{$group->count()} bloqueio(s) de gate detectados para {$gate}.",
                'problem' => 'Gate bloqueando pode ser exatamente o comportamento correto, mas tambem pode indicar falta de evidencia, normalizer fraco ou threshold mal calibrado.',
                'solution' => 'Criar review de calibracao do gate com exemplos dos envelopes bloqueados, sem relaxar politica automaticamente.',
                'worth_it' => 'Vale porque melhora confianca sem reduzir rigor.',
                'best_solution_rationale' => 'Revisao por evidencias evita transformar bloqueio legitimo em bypass.',
                'alternatives' => ['Manter threshold atual.', 'Adicionar waiver especifico para finding conhecido.'],
                'source_refs' => $group->take(5)->map(fn (AtlasLedgerEvent $event): array => [
                    'type' => 'ledger_event',
                    'id' => $event->event_id,
                    'envelope_id' => $event->envelope_id,
                ])->values()->all(),
                'confidence' => 0.78,
                'dedupe_key' => 'self-improvement:gate-blocked:'.sha1($gate),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    private function toolCoverageFindings(Collection $events): array
    {
        $harnessRuns = $events->filter(fn (AtlasLedgerEvent $event): bool => str_starts_with($event->envelope_id, 'engineering_run:'));
        if ($harnessRuns->isEmpty()) {
            return [];
        }

        $toolEvents = $harnessRuns->where('event_type', LedgerEventType::ToolEvidenceRecorded->value);
        if ($toolEvents->isNotEmpty()) {
            return [];
        }

        return [[
            'title' => 'Aumentar cobertura de tool evidence no Harness',
            'category' => 'self_improvement',
            'finding' => 'Runs do Engineering Harness foram observados sem TOOL_EVIDENCE_RECORDED na mesma janela.',
            'problem' => 'Sem tool evidence, gates e self-improvement dependem mais de scoring agregado do que de sensores normalizados.',
            'solution' => 'Garantir que quality scan, visual smoke ou tool gate relevante rode em pelo menos um perfil do Harness e publique evidencia no ledger.',
            'worth_it' => 'Vale porque fortalece o caminho Atlas Forge com provas verificaveis.',
            'best_solution_rationale' => 'Adicionar evidencia normalizada e melhor que aumentar confianca em resposta de provider.',
            'alternatives' => ['Manter tools apenas em perfis release.', 'Exigir tool evidence somente para tarefas critical.'],
            'source_refs' => $harnessRuns->take(5)->map(fn (AtlasLedgerEvent $event): array => [
                'type' => 'ledger_event',
                'id' => $event->event_id,
                'envelope_id' => $event->envelope_id,
            ])->values()->all(),
            'confidence' => 0.74,
            'dedupe_key' => 'self-improvement:harness-tool-coverage:v1',
        ]];
    }

    private function startRun(string $flow, bool $emit, int $hours, int $limit): ?AtlasInitiativeRun
    {
        if (! Schema::hasTable('atlas_initiative_runs')) {
            return null;
        }

        return AtlasInitiativeRun::query()->create([
            'kind' => str_replace('.', '_', $flow),
            'status' => 'running',
            'started_at' => now(),
            'scope' => [
                'hours' => $hours,
                'emit' => $emit,
                'limit' => $limit,
                'flow' => $flow,
                'source' => 'atlas_ledger_events',
            ],
            'findings' => [],
            'emitted_inbox_item_ids' => [],
            'metadata' => ['runtime' => 'atlas_self_improvement_runtime_v1'],
        ]);
    }

    /**
     * @param  array<int,array<string,mixed>>  $findings
     * @param  array<int,string>  $emitted
     */
    private function finishRun(?AtlasInitiativeRun $run, string $status, array $findings, array $emitted, ?string $error = null): void
    {
        if (! $run) {
            return;
        }

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'findings' => $findings,
            'emitted_inbox_item_ids' => $emitted,
            'error_message' => $error,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function recordCycleEvent(LedgerEventType $type, string $envelopeId, ?AtlasInitiativeRun $run, array $payload = []): void
    {
        $this->ledger->record($type, array_merge([
            'envelope_id' => $envelopeId,
            'self_improvement_run_id' => $run?->id,
            'flow' => (string) ($payload['flow'] ?? 'self_improvement.nightly_review'),
        ], $payload), [
            'tenant_id' => 'default',
            'operator_id' => 'atlas_self_improvement',
            'envelope_id' => $envelopeId,
            'correlation_id' => $envelopeId,
            'emitter_stage' => 'atlas.self_improvement',
            'emitter_version' => 'self-improvement-runtime-v1',
        ]);
    }

    /**
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>
     */
    private function ledgerFindingProjection(array $finding): array
    {
        $metadata = (array) ($finding['metadata'] ?? []);

        return [
            'title' => $finding['title'] ?? null,
            'category' => $finding['category'] ?? null,
            'dedupe_key' => $finding['dedupe_key'] ?? null,
            'confidence' => $finding['confidence'] ?? null,
            'source_ref_count' => count((array) ($finding['source_refs'] ?? [])),
            'schema_version' => $metadata['schema_version'] ?? null,
            'review_signal' => (array) ($metadata['review_signal'] ?? []),
            'source_types' => collect((array) ($finding['source_refs'] ?? []))
                ->map(fn (array $source): ?string => is_string($source['type'] ?? null) ? $source['type'] : null)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $health
     * @return array<string,mixed>
     */
    private function scheduleHealthLedgerProjection(array $health): array
    {
        return [
            'schema_version' => $health['schema_version'] ?? null,
            'status' => $health['status'] ?? null,
            'health_status' => data_get($health, 'health.status'),
            'issues' => array_values((array) data_get($health, 'health.issues', [])),
            'enabled' => (bool) ($health['enabled'] ?? false),
            'schedulable' => (bool) ($health['schedulable'] ?? false),
            'scheduler_registration' => (array) ($health['scheduler_registration'] ?? []),
            'flow_count' => (int) ($health['flow_count'] ?? 0),
            'cadence_counts' => (array) ($health['cadence_counts'] ?? []),
            'invalid_flow_count' => (int) ($health['invalid_flow_count'] ?? 0),
            'defaulted' => (bool) ($health['defaulted'] ?? false),
            'emit' => (bool) ($health['emit'] ?? false),
            'plan_hash' => $health['plan_hash'] ?? null,
            'plan_hash_algorithm' => $health['plan_hash_algorithm'] ?? null,
            'time' => $health['time'] ?? null,
            'timezone' => $health['timezone'] ?? null,
            'next_run_at' => $health['next_run_at'] ?? null,
        ];
    }

    private function normalizeFlow(string $flow): string
    {
        $flow = trim($flow);
        if ($flow === '') {
            return 'self_improvement.nightly_review';
        }

        if (! str_starts_with($flow, 'self_improvement.')) {
            return 'self_improvement.'.$flow;
        }

        return $flow;
    }
}
