<?php

namespace App\Services\Ai\SelfImprovement;

use App\Models\AtlasInitiativeRun;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasOpenBrainAccessLog;
use App\Services\Ai\Learning\ProductiveFailure\ProductiveFailureSessionRepository;
use App\Services\Ai\Context\LocalRagBenchmarkService;
use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use App\Services\Ai\Kernel\Decision\DynamicComputeMarketAdvisor;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\ProviderPerformanceProjection;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\SelfImprovement\Support\SelfImprovementProjectionSupport;
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

    private readonly Runtime\FilterNormalizationSection $filterNormalization;

    private readonly Runtime\RunLifecycleSection $runLifecycle;

    private readonly Runtime\CycleHealthFindingsSection $cycleHealth;

    private readonly Runtime\ReviewFindingsSection $reviewFindings;

    private readonly Runtime\ProviderCostRateReplaySection $providerCostRateReplay;

    private readonly Runtime\ProviderPerformanceSection $providerPerformanceSection;

    private readonly Runtime\InboxActionReplaySection $inboxActionReplay;

    private readonly Runtime\ConstelacaoUsageReviewSection $constelacaoUsageReview;

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
        private readonly DynamicComputeMarketAdvisor $dynamicComputeMarket,
        private readonly LocalRagBenchmarkService $localRagBenchmark,
        private readonly ProductiveFailureSessionRepository $productiveFailureSessions,
    ) {
        $this->filterNormalization = new Runtime\FilterNormalizationSection;
        $this->runLifecycle = new Runtime\RunLifecycleSection($this->ledger);
        $this->cycleHealth = new Runtime\CycleHealthFindingsSection;
        $this->reviewFindings = new Runtime\ReviewFindingsSection(
            $this->replay,
            $this->productiveFailureSessions,
            $this->architectureOperations,
            $this->filterNormalization,
        );
        $this->providerCostRateReplay = new Runtime\ProviderCostRateReplaySection;
        $this->providerPerformanceSection = new Runtime\ProviderPerformanceSection(
            $this->providerPerformance,
            $this->dynamicComputeMarket,
            $this->filterNormalization,
        );
        $this->inboxActionReplay = new Runtime\InboxActionReplaySection(
            $this->replay,
            $this->filterNormalization,
            $this->providerCostRateReplay,
        );
        $this->constelacaoUsageReview = new Runtime\ConstelacaoUsageReviewSection;
    }

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
            $findings = collect($this->findingsForFlow($flow, $events, $filters, $hours, $envelopeId))
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
    private function findingsForFlow(string $flow, Collection $events, array $filters = [], int $hours = 24, ?string $currentEnvelopeId = null): array
    {
        return match ($flow) {
            'self_improvement.capability_gap_scan' => [
                ...$this->domainOnboardingFindings($filters),
                ...$this->missingTerminalFindings($events, $currentEnvelopeId),
                ...$this->toolCoverageFindings($events),
                ...$this->repairLoopFindings($events, $filters),
                ...$this->kernelPipelineFindings($events, $filters),
            ],
            'self_improvement.benchmark_review',
            'self_improvement.provider_performance_review' => [
                ...$this->providerPerformanceFindings($hours, $filters),
                ...$this->agentBehaviorReplayFindings($hours, $filters),
                ...$this->sloDriftFindings($events, $filters),
                ...$this->repairLoopFindings($events, $filters),
                ...$this->operationFailureFindings($events),
                ...$this->gateBlockedFindings($events),
            ],
            'self_improvement.agent_behavior_review' => [
                ...$this->agentBehaviorReplayFindings($hours, $filters),
            ],
            'self_improvement.provider_release_review' => [
                ...$this->providerReleaseGovernanceFindings($filters),
            ],
            'self_improvement.voice_realtime_review' => [
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
                ...$this->agentBehaviorReplayFindings($hours, $filters),
                ...$this->decisionReceiptReplayFindings($events, $filters),
                ...$this->openBrainRetrievalFindings($hours, $filters),
                ...$this->openBrainPromptMetricFindings($hours, $filters),
                ...$this->localRagPromotionFindings($filters),
                ...$this->constelacaoUsageReviewFindings($events, $filters),
                ...$this->productiveFailureTransferTestFindings($filters),
                ...$this->domainOnboardingFindings($filters),
                ...$this->sloDriftFindings($events, $filters),
                ...$this->repairLoopFindings($events, $filters),
                ...$this->kernelPipelineFindings($events, $filters),
                ...$this->missingTerminalFindings($events, $currentEnvelopeId),
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
                ...$this->agentBehaviorReplayFindings($hours, $filters),
                ...$this->decisionReceiptReplayFindings($events, $filters),
                ...$this->openBrainRetrievalFindings($hours, $filters),
                ...$this->openBrainPromptMetricFindings($hours, $filters),
                ...$this->localRagPromotionFindings($filters),
                ...$this->constelacaoUsageReviewFindings($events, $filters),
                ...$this->productiveFailureTransferTestFindings($filters),
                ...$this->domainOnboardingFindings($filters),
                ...$this->sloDriftFindings($events, $filters),
                ...$this->repairLoopFindings($events, $filters),
                ...$this->kernelPipelineFindings($events, $filters),
                ...$this->missingTerminalFindings($events, $currentEnvelopeId),
                ...$this->operationFailureFindings($events),
                ...$this->gateBlockedFindings($events),
                ...$this->toolCoverageFindings($events),
            ],
        };
    }

    private function ledgerEvents(int $hours): Collection
    {
        return $this->runLifecycle->ledgerEvents($hours);
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

    private function openBrainPromptMetricFindings(int $hours, array $filters = []): array
    {
        return $this->reviewFindings->openBrainPromptMetricFindings($hours, $filters);
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function localRagPromotionFindings(array $filters = []): array
    {
        $report = $this->localRagBenchmark->report();

        if (($report['status'] ?? null) !== 'passed') {
            return [];
        }

        $evidenceLedger = $this->localRagBenchmark->evidenceLedgerReport($report);

        if (data_get($evidenceLedger, 'promotion_evidence_satisfied') !== true) {
            return [[
                'title' => 'Corrigir evidencia operacional Local RAG antes de Graph RAG',
                'category' => 'self_improvement',
                'finding' => 'Local RAG passou no corpus controlado, mas a evidencia LOCAL_RAG_* nao foi persistida; a revisao de Graph RAG/Python deve permanecer bloqueada.',
                'problem' => 'Sem evento LOCAL_RAG_GRAPH_PROMOTION_BLOCKED persistido, uma proposta de promocao nao tem replay/auditoria suficiente e pode virar decisao fora do Evidence Ledger.',
                'solution' => 'Restaurar a persistencia de atlas_ledger_events, rerodar atlas:ai:local-rag-benchmark --json e so entao permitir proposta proposal-only para review humano/Curator.',
                'worth_it' => 'Vale porque garante que benchmark verde nunca substitui evidencia operacional persistida.',
                'best_solution_rationale' => 'AP-683 exige evidence_ledger.promotion_evidence_satisfied=true antes de qualquer review de promocao; falhar fechado preserva Kernel, replay e governanca.',
                'alternatives' => ['Manter Graph RAG como future_governed.', 'Rerodar somente readiness sem abrir proposta.', 'Investigar indisponibilidade do Evidence Ledger antes de qualquer AP futuro.'],
                'available_actions' => [
                    ['id' => 'restore_local_rag_evidence', 'label' => 'Restaurar evidencia', 'style' => 'primary'],
                    ['id' => 'keep_future_governed', 'label' => 'Manter bloqueado', 'style' => 'secondary'],
                ],
                'source_refs' => [
                    ['type' => 'command', 'id' => 'php artisan atlas:ai:local-rag-benchmark --json'],
                    ['type' => 'ap', 'id' => 'docs/ap/AP-683-local-rag-graph-promotion-review.md'],
                    ['type' => 'ledger_contract', 'id' => data_get($report, 'ledger_contract.schema_version')],
                ],
                'confidence' => 0.92,
                'dedupe_key' => 'self-improvement:local-rag-evidence-blocked:'.sha1((string) data_get($report, 'quality_corpus.corpus_id')),
                'metadata' => [
                    'schema_version' => 'atlas.self_improvement.local_rag_graph_promotion_evidence_block.v1',
                    'filters' => array_filter($filters, fn (?string $value): bool => $value !== null),
                    'benchmark' => [
                        'status' => $report['status'] ?? null,
                        'readiness_status' => $report['readiness_status'] ?? null,
                        'quality_corpus_status' => data_get($report, 'quality_corpus.status'),
                        'completed_prerequisites' => data_get($report, 'promotion_gate.completed_prerequisites', []),
                        'remaining_prerequisites' => data_get($report, 'promotion_gate.remaining_prerequisites', []),
                        'evidence_ledger' => [
                            'status' => data_get($evidenceLedger, 'status'),
                            'promotion_evidence_satisfied' => data_get($evidenceLedger, 'promotion_evidence_satisfied'),
                            'missing_reason' => data_get($evidenceLedger, 'missing_reason'),
                            'expected_event_count' => data_get($evidenceLedger, 'expected_event_count'),
                            'recorded_event_count' => data_get($evidenceLedger, 'recorded_event_count'),
                        ],
                    ],
                    'review_signal' => [
                        'status' => 'blocking',
                        'severity' => 'high',
                        'review_required' => false,
                        'proposal_allowed' => false,
                        'reasons' => ['local_rag_promotion_requires_persisted_evidence_ledger'],
                        'recommended_action' => 'restore_local_rag_evidence_ledger_before_graph_rag_review',
                        'review_ap' => 'docs/ap/AP-683-local-rag-graph-promotion-review.md',
                        'supersedes_event_required' => data_get($report, 'promotion_gate.supersedes_event_required'),
                        'supersede_authority' => data_get($report, 'promotion_gate.supersede_authority'),
                    ],
                    'policy_patch_candidate' => [
                        'status' => 'blocked_until_evidence_persisted',
                        'target' => 'context_retrieval_router_graph_rag_runtime',
                        'operation' => 'none',
                        'requires_human_review' => true,
                        'requires_future_ap' => true,
                        'requires_decision_receipt' => true,
                        'requires_rollback_plan' => true,
                        'auto_apply' => false,
                    ],
                ],
            ]];
        }

        $remaining = (array) data_get($report, 'promotion_gate.remaining_prerequisites', []);
        if (! in_array('human_review_or_curator_proposal', $remaining, true)) {
            return [];
        }

        return [[
            'title' => 'Revisar promocao de Graph RAG/Python',
            'category' => 'self_improvement',
            'finding' => 'Local RAG passou readiness, corpus controlado, latencia p95, privacy boundary e contrato LOCAL_RAG_*; Graph RAG/Python continua bloqueado ate review.',
            'problem' => 'Sem proposta revisavel, uma IA pode interpretar benchmark verde como permissao para criar Graph RAG em Python e acabar criando memoria paralela ao Kernel.',
            'solution' => 'Abrir proposta proposal-only para revisar se Graph RAG/Python deve avancar para AP/runtime, exigindo corpus real, policy patch, Decision Receipt e veto humano antes de qualquer promocao.',
            'worth_it' => 'Vale porque transforma o proximo passo de performance local em decisao auditavel, preservando a tese: Python pode pensar pesado, mas Laravel/Kernel decide.',
            'best_solution_rationale' => 'Usar Self-Improvement como review gate evita promocao automatica e registra a decisao no mesmo fluxo Evidence -> Learning -> Proposal.',
            'alternatives' => ['Manter Graph RAG como future_governed.', 'Expandir primeiro o corpus real de retrieval answer quality.', 'Promover somente Vector RAG/reranker sem graph.'],
            'available_actions' => [
                ['id' => 'review_graph_rag_promotion', 'label' => 'Revisar promocao', 'style' => 'primary'],
                ['id' => 'draft_graph_rag_ap', 'label' => 'Rascunhar AP Graph RAG', 'style' => 'secondary'],
                ['id' => 'keep_future_governed', 'label' => 'Manter bloqueado', 'style' => 'secondary'],
            ],
            'source_refs' => [
                ['type' => 'command', 'id' => 'php artisan atlas:ai:local-rag-benchmark --json'],
                ['type' => 'doc', 'id' => 'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md'],
                ['type' => 'ap', 'id' => 'docs/ap/AP-683-local-rag-graph-promotion-review.md'],
                ['type' => 'ledger_contract', 'id' => data_get($report, 'ledger_contract.schema_version')],
            ],
            'confidence' => 0.9,
            'dedupe_key' => 'self-improvement:local-rag-graph-promotion:'.sha1((string) data_get($report, 'quality_corpus.corpus_id')),
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.local_rag_graph_promotion.v1',
                'filters' => array_filter($filters, fn (?string $value): bool => $value !== null),
                'benchmark' => [
                    'status' => $report['status'] ?? null,
                    'readiness_status' => $report['readiness_status'] ?? null,
                    'quality_corpus_status' => data_get($report, 'quality_corpus.status'),
                    'latency_p95_ms' => data_get($report, 'quality_corpus.metrics.latency_p95_ms'),
                    'completed_prerequisites' => data_get($report, 'promotion_gate.completed_prerequisites', []),
                    'remaining_prerequisites' => $remaining,
                    'evidence_ledger' => [
                        'status' => data_get($evidenceLedger, 'status'),
                        'promotion_evidence_satisfied' => data_get($evidenceLedger, 'promotion_evidence_satisfied'),
                        'missing_reason' => data_get($evidenceLedger, 'missing_reason'),
                        'expected_event_count' => data_get($evidenceLedger, 'expected_event_count'),
                        'recorded_event_count' => data_get($evidenceLedger, 'recorded_event_count'),
                    ],
                ],
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => 'medium',
                    'review_required' => true,
                    'proposal_allowed' => true,
                    'reasons' => ['graph_rag_promotion_requires_human_or_curator_review'],
                    'recommended_action' => 'open_reviewable_graph_rag_promotion_proposal',
                    'review_ap' => 'docs/ap/AP-683-local-rag-graph-promotion-review.md',
                    'supersedes_event_required' => data_get($report, 'promotion_gate.supersedes_event_required'),
                    'supersede_authority' => data_get($report, 'promotion_gate.supersede_authority'),
                    'review_packet' => data_get($report, 'promotion_review_contract.review_packet'),
                ],
                'policy_patch_candidate' => [
                    'status' => 'proposal_only',
                    'target' => 'context_retrieval_router_graph_rag_runtime',
                    'operation' => 'promote_python_graph_rag_from_future_governed_to_reviewed_runtime_candidate',
                    'requires_human_review' => true,
                    'requires_future_ap' => in_array('future_graph_rag_python_ap', $remaining, true),
                    'requires_decision_receipt' => in_array('decision_receipt_for_runtime_promotion', $remaining, true),
                    'requires_rollback_plan' => in_array('reviewable_policy_patch_with_rollback', $remaining, true),
                    'auto_apply' => false,
                ],
            ],
        ]];
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function constelacaoUsageReviewFindings(Collection $events, array $filters = []): array
    {
        return $this->constelacaoUsageReview->constelacaoUsageReviewFindings($events, $filters);
    }

    private function productiveFailureTransferTestFindings(array $filters = []): array
    {
        return $this->reviewFindings->productiveFailureTransferTestFindings($filters);
    }

    private function providerReleaseGovernanceFindings(array $filters = []): array
    {
        return $this->reviewFindings->providerReleaseGovernanceFindings($filters);
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function providerPerformanceFindings(int $hours, array $filters = []): array
    {
        return $this->providerPerformanceSection->providerPerformanceFindings($hours, $filters);
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function dynamicComputeMarketFindings(array $report, array $filters = []): array
    {
        return $this->providerPerformanceSection->dynamicComputeMarketFindings($report, $filters);
    }

    private function sloDriftFindings(Collection $events, array $filters = []): array
    {
        return $this->reviewFindings->sloDriftFindings($events, $filters);
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
        $operationsById = collect((array) ($summary['commands'] ?? []))
            ->filter(fn (array $operation): bool => is_string($operation['id'] ?? null) && $operation['id'] !== '')
            ->keyBy(fn (array $operation): string => (string) $operation['id']);
        $commands = collect((array) ($summary['commands'] ?? []))
            ->map(fn (array $operation): ?string => is_string($operation['command'] ?? null) ? $operation['command'] : null)
            ->filter()
            ->values()
            ->all();
        $expectedCommands = [
            'php artisan atlas:ai:architecture-operations --json',
            'php artisan atlas:ai:architecture-validate',
            'atlas engineering knowledge docs-health --json',
            'atlas engineering knowledge sync --prune --json',
            'atlas engineering knowledge index-code --prune --summary-only --json',
            'php artisan atlas:ai:slo --hours=24 --json',
            'php artisan atlas:ai:voice contract --json',
            'php artisan atlas:ai:voice bootstrap --json',
            'php artisan atlas:ai:voice dependencies --json',
            'php artisan atlas:ai:voice scripted-example --json',
            'php artisan atlas:ai:voice scripted-smoke --json',
            'php artisan atlas:ai:voice callback-smoke --json',
            'php artisan atlas:ai:voice callback-sequence-smoke --json',
            'php artisan atlas:ai:voice callback-loop-check --json',
            'php artisan atlas:ai:voice preflight --json',
            'php artisan atlas:ai:voice activation-contract --json',
            'php artisan atlas:ai:voice sdk-check --json',
            'php artisan atlas:ai:voice worker-plan --json',
            'php artisan atlas:ai:voice production-loop-plan --json',
            'php artisan atlas:ai:voice production-loop-smoke --json',
            'php artisan atlas:ai:voice worker-start-check --json',
            'php artisan atlas:ai:voice runtime-certify --json',
            'php artisan atlas:ai:voice readiness --hours=24 --json',
            'PYTHONPATH=runtimes/python/voice_realtime python3 -m unittest discover -s runtimes/python/voice_realtime/tests',
            'php artisan atlas:ai:kernel-pipeline-report --hours=24 --json',
            'php artisan atlas:ai:repair-report --hours=24 --json',
            'php artisan atlas:ai:provider-performance --hours=24 --json',
            'php artisan atlas:ai:provider-release-review --provider=<provider> --title="<release>" --json',
            'php artisan atlas:ai:agent-behavior-report --hours=24 --json',
            'php artisan atlas:ai:dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json',
            'php artisan atlas:ai:self-improve --flow=provider_performance_review --hours=168 --json',
            'php artisan atlas:ai:self-improve --flow=provider_release_review --hours=168 --json',
            'php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json',
            'php artisan atlas:ai:self-improve --flow=voice_realtime_review --hours=168 --json',
            'php artisan atlas:ai:telemetry:cost-rates --missing --hours=168 --json',
            'php artisan atlas:ai:telemetry:cost-rates --provider=<provider> --model=<model> --input-microusd=<input> --output-microusd=<output> --json',
            'php artisan atlas:ai:qualitative-levels --hours=720 --json',
            'php artisan atlas:ai:strategic-decision review --json',
            'php artisan atlas:ai:decision-receipt-report --envelope=<id> --json',
            'php artisan atlas:ai:ledger <id> --json',
            'php artisan atlas:ai:ledger-project --limit=500 --json',
            'php artisan atlas:ai:self-improvement-schedule-report --hours=24 --json',
            'php artisan atlas:ai:inbox-action-report --hours=24 --json',
        ];
        $expectedApiEndpoints = [
            'provider_release_review' => '/ai/provider-release-review',
            'voice_realtime_contract' => '/ai/voice/runtime/contract',
            'voice_realtime_bootstrap' => '/ai/voice/runtime/bootstrap',
            'voice_realtime_dependencies' => '/ai/voice/runtime/dependencies',
            'voice_realtime_runtime_certification' => '/ai/voice/runtime/certification',
            'voice_realtime_readiness' => '/ai/voice/readiness',
        ];
        $expectedMobileEndpoints = [
            'voice_realtime_contract' => '/v1/mobile/ai/voice/runtime/contract',
            'voice_realtime_bootstrap' => '/v1/mobile/ai/voice/runtime/bootstrap',
            'voice_realtime_dependencies' => '/v1/mobile/ai/voice/runtime/dependencies',
            'voice_realtime_runtime_certification' => '/v1/mobile/ai/voice/runtime/certification',
            'voice_realtime_readiness' => '/v1/mobile/ai/voice/readiness',
        ];
        $missingCommands = array_values(array_diff($expectedCommands, $commands));
        $missingApiEndpoints = $this->missingArchitectureOperationEndpoints($operationsById->all(), $expectedApiEndpoints, 'api_endpoint');
        $missingMobileEndpoints = $this->missingArchitectureOperationEndpoints($operationsById->all(), $expectedMobileEndpoints, 'mobile_endpoint');
        $section = (string) ($summary['section'] ?? '');
        $commandCount = (int) ($summary['command_count'] ?? 0);
        $actualCommandCount = count($commands);
        $countMismatch = $commandCount !== $actualCommandCount;

        if ($section === 'arquitetura_mae' && $missingCommands === [] && $missingApiEndpoints === [] && $missingMobileEndpoints === [] && ! $countMismatch) {
            return [];
        }

        if ($missingCommands === []) {
            $endpointRefs = collect($missingApiEndpoints)
                ->merge($missingMobileEndpoints)
                ->take(8)
                ->map(fn (array $endpoint, string $operationId): array => [
                    'type' => 'missing_architecture_operation_endpoint',
                    'id' => $operationId,
                    'section' => $section,
                    'endpoint_field' => $endpoint['field'],
                    'expected' => $endpoint['expected'],
                    'actual' => $endpoint['actual'],
                ]);

            $sourceRefs = $endpointRefs->isNotEmpty()
                ? $endpointRefs
                : collect($commands)
                    ->take(8)
                    ->map(fn (string $command): array => [
                        'type' => 'architecture_operation',
                        'id' => $command,
                        'section' => $section,
                    ]);
        } else {
            $sourceRefs = collect($missingCommands)
                ->take(8)
                ->map(fn (string $command): array => [
                    'type' => 'missing_architecture_operation',
                    'id' => $command,
                    'section' => $section,
                ]);
        }

        $hasCriticalMissingContract = $missingCommands !== [] || $missingApiEndpoints !== [] || $missingMobileEndpoints !== [];

        return [[
            'title' => 'Corrigir catalogo operacional da arquitetura mae',
            'category' => 'self_improvement',
            'finding' => 'Architecture Operations Catalog perdeu comandos ou endpoints criticos, mudou de secao ou reportou contagem divergente.',
            'problem' => 'Se o catalogo operacional diverge, operador, App, MCP, Observability e sessoes auxiliares deixam de descobrir o mesmo control plane da arquitetura mae.',
            'solution' => 'Restaurar `AtlasArchitectureOperationsCatalog`, validar CLI help, API, mobile, Observability, MCP e surfaces diretas, e rodar `atlas:ai:architecture-validate` antes de promover a mudanca.',
            'worth_it' => 'Vale porque descoberta operacional e parte do produto: comando implementado mas invisivel vira fluxo solto.',
            'best_solution_rationale' => 'O Self-Improvement consome o mesmo catalogo compartilhado das surfaces, entao a auditoria nao cria uma segunda lista manual de comandos.',
            'alternatives' => ['Manter o catalogo em observacao se outra sessao estiver migrando nomes.', 'Criar redirect temporario apenas com AP documentado.'],
            'source_refs' => $sourceRefs
                ->values()
                ->all(),
            'confidence' => $hasCriticalMissingContract ? 0.9 : 0.82,
            'dedupe_key' => 'self-improvement:architecture-operations:'.sha1($section.':'.implode(',', $missingCommands).':'.json_encode($missingApiEndpoints).':'.json_encode($missingMobileEndpoints).':'.$commandCount.':'.$actualCommandCount),
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.architecture_operations.v1',
                'section' => $section,
                'command_count' => $commandCount,
                'actual_command_count' => $actualCommandCount,
                'count_mismatch' => $countMismatch,
                'missing_commands' => $missingCommands,
                'expected_commands' => $expectedCommands,
                'missing_api_endpoints' => $missingApiEndpoints,
                'expected_api_endpoints' => $expectedApiEndpoints,
                'missing_mobile_endpoints' => $missingMobileEndpoints,
                'expected_mobile_endpoints' => $expectedMobileEndpoints,
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => $hasCriticalMissingContract ? 'high' : 'medium',
                    'reason' => 'architecture_operations_catalog_drift',
                    'recommended_action' => 'restore_architecture_operations_catalog',
                ],
                'filters' => $this->normalizedArchitectureValidationFilters($filters),
            ],
        ]];
    }

    /**
     * @param  array<string,array<string,mixed>>  $operationsById
     * @param  array<string,string>  $expectedEndpoints
     * @return array<string,array{field:string,expected:string,actual:mixed}>
     */
    private function missingArchitectureOperationEndpoints(array $operationsById, array $expectedEndpoints, string $field): array
    {
        return SelfImprovementProjectionSupport::missingArchitectureOperationEndpoints(
            $operationsById,
            $expectedEndpoints,
            $field,
        );
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
        return $this->inboxActionReplay->inboxActionReplayFindings($hours, $filters);
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    private function agentBehaviorReplayFindings(int $hours, array $filters = []): array
    {
        if ($this->normalizedDomainOnboardingFilters($filters) !== []) {
            return [];
        }

        $normalizedFilters = $this->normalizedAgentBehaviorFilters($filters);
        $report = $this->replay->agentBehaviorReportForWindow(
            now()->subHours($hours),
            null,
            $normalizedFilters,
        );
        $reviewSignal = (array) ($report['review_signal'] ?? []);

        if (! (bool) ($report['available'] ?? false)
            || ! (bool) ($reviewSignal['review_required'] ?? false)
            || ($reviewSignal['recommended_action'] ?? null) !== 'open_reviewable_agent_behavior_quality_proposal') {
            return [];
        }

        $recentEvents = collect((array) ($report['recent_events'] ?? []))
            ->filter(fn (array $event): bool => (array) ($event['finding_codes'] ?? []) !== [])
            ->values();

        if ($recentEvents->isEmpty()) {
            return [];
        }

        $findingCodeCounts = (array) ($report['finding_code_counts'] ?? []);
        $topFindingCode = collect($findingCodeCounts)
            ->sortDesc()
            ->keys()
            ->first() ?: 'agent.behavior';
        $availableActions = [
            ['id' => 'review_patch', 'label' => 'Revisar contrato comportamental', 'style' => 'primary'],
            ['id' => 'discuss', 'label' => 'Discutir comportamento do agente', 'style' => 'default'],
            ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive', 'requires_confirm' => true],
        ];

        return [[
            'title' => 'Corrigir recorrencia de comportamento dos agentes',
            'category' => 'self_improvement',
            'finding' => 'Replay do Evidence Ledger encontrou findings comportamentais recorrentes em execucoes de IA.',
            'problem' => 'Falhas repetidas como falta de verificacao, diff lateral ou quebra de contrato degradam qualidade de programacao e tornam o contrato comportamental apenas decorativo.',
            'solution' => 'Revisar AgentBehaviorContract, gates, prompts e surfaces afetadas; reforcar verificacao, disciplina de diff e evidencia antes de ajustar provider/model.',
            'worth_it' => 'Vale porque transforma comportamento de agente em loop mensuravel de melhoria continua, com replay e proposta revisavel.',
            'best_solution_rationale' => 'Consumir `agentBehaviorReportForWindow` preserva o Evidence Ledger como fonte unica e evita um scanner paralelo sobre traces, prompts ou logs crus.',
            'alternatives' => ['Criar waiver temporario para findings legados explicitamente marcados.', 'Elevar severidade apenas quando houver amostra maior por provider/model.'],
            'available_actions' => $availableActions,
            'policy' => [
                'auto_commit' => false,
                'auto_merge' => false,
                'auto_apply_behavior_change' => false,
                'requires_operator_review' => true,
                'requires_tests_passed' => true,
                'requires_architecture_validate' => true,
            ],
            'payload' => [
                'agent_behavior_replay' => [
                    'schema_version' => 'atlas.self_improvement.agent_behavior_replay.proposal_payload.v1',
                    'hours' => $hours,
                    'review_signal' => $reviewSignal,
                    'finding_code_counts' => $findingCodeCounts,
                    'provider_counts' => (array) ($report['provider_counts'] ?? []),
                    'agent_slug_counts' => (array) ($report['agent_slug_counts'] ?? []),
                    'source_event_count' => $recentEvents->count(),
                    'filters' => $normalizedFilters,
                    'governance' => [
                        'auto_apply' => false,
                        'critical_behavior_change_requires_human_review' => true,
                    ],
                ],
            ],
            'source_refs' => $recentEvents
                ->take(5)
                ->map(fn (array $event): array => [
                    'type' => 'ledger_event',
                    'id' => $event['event_id'] ?? null,
                    'event_id' => $event['event_id'] ?? null,
                    'envelope_id' => $event['envelope_id'] ?? null,
                    'trace_id' => $event['trace_id'] ?? null,
                    'status' => $event['status'] ?? null,
                    'score' => $event['score'] ?? null,
                    'provider' => $event['provider'] ?? null,
                    'model' => $event['model'] ?? null,
                    'agent_slug' => $event['agent_slug'] ?? null,
                    'contract_id' => $event['contract_id'] ?? null,
                    'finding_codes' => (array) ($event['finding_codes'] ?? []),
                    'occurred_at' => $event['occurred_at'] ?? null,
                ])
                ->values()
                ->all(),
            'confidence' => 0.87,
            'dedupe_key' => 'self-improvement:agent-behavior-replay:'.sha1($hours.':'.implode(',', array_keys($findingCodeCounts))),
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.agent_behavior_replay.v1',
                'hours' => $hours,
                'agent_behavior_event_count' => (int) ($report['agent_behavior_event_count'] ?? 0),
                'finding_count' => (int) ($report['finding_count'] ?? 0),
                'finding_code_counts' => $findingCodeCounts,
                'finding_severity_counts' => (array) ($report['finding_severity_counts'] ?? []),
                'status_counts' => (array) ($report['status_counts'] ?? []),
                'provider_counts' => (array) ($report['provider_counts'] ?? []),
                'agent_slug_counts' => (array) ($report['agent_slug_counts'] ?? []),
                'average_score' => $report['average_score'] ?? null,
                'top_finding_code' => (string) $topFindingCode,
                'review_signal' => $reviewSignal,
                'available_actions' => $availableActions,
                'filters' => $normalizedFilters,
            ],
        ]];
    }

    private function providerCostRateReplaySourceRef(array $event): array
    {
        return $this->providerCostRateReplay->providerCostRateReplaySourceRef($event);
    }

    private function providerCostRateReplayPayloadEvent(array $event): array
    {
        return $this->providerCostRateReplay->providerCostRateReplayPayloadEvent($event);
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

    private function normalizedWhitelistFilters(array $filters, array $keys): array
    {
        return $this->filterNormalization->normalizedWhitelistFilters($filters, $keys);
    }

    private function normalizedDimensionFilters(array $filters): array
    {
        return $this->filterNormalization->normalizedDimensionFilters($filters);
    }

    private function normalizedRepairFilters(array $filters): array
    {
        return $this->filterNormalization->normalizedRepairFilters($filters);
    }

    private function normalizedKernelPipelineFilters(array $filters): array
    {
        return $this->filterNormalization->normalizedKernelPipelineFilters($filters);
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedDomainOnboardingFilters(array $filters): array
    {
        return $this->normalizedWhitelistFilters($filters, [
            'domain',
            'flow',
            'onboarding_status' => 'onboarding_status',
        ]);
    }

    private function normalizedArchitectureValidationFilters(array $filters): array
    {
        return $this->filterNormalization->normalizedArchitectureValidationFilters($filters);
    }

    private function normalizedProviderPerformanceFilters(array $filters): array
    {
        return $this->filterNormalization->normalizedProviderPerformanceFilters($filters);
    }

    private function knownProviderDimension(mixed $value): ?string
    {
        return $this->filterNormalization->knownProviderDimension($value);
    }

    private function normalizedInboxActionFilters(array $filters): array
    {
        return $this->filterNormalization->normalizedInboxActionFilters($filters);
    }

    private function normalizedDecisionReceiptFilters(array $filters): array
    {
        return $this->filterNormalization->normalizedDecisionReceiptFilters($filters);
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedAgentBehaviorFilters(array $filters): array
    {
        return $this->normalizedWhitelistFilters($filters, ['status', 'provider', 'model', 'agent_slug', 'finding_code', 'contract_id']);
    }

    private function matchesDecisionReceiptFilters(AtlasLedgerEvent $event, array $filters): bool
    {
        return $this->filterNormalization->matchesDecisionReceiptFilters($event, $filters);
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
            ...$this->normalizedAgentBehaviorFilters($filters),
            ...$this->normalizedProviderReleaseFilters($filters),
        ];
    }

    private function normalizedProviderReleaseFilters(array $filters): array
    {
        return $this->filterNormalization->normalizedProviderReleaseFilters($filters);
    }

    private function missingTerminalFindings(Collection $events, ?string $currentEnvelopeId = null): array
    {
        return $this->cycleHealth->missingTerminalFindings($events, $currentEnvelopeId);
    }

    private function operationFailureFindings(Collection $events): array
    {
        return $this->cycleHealth->operationFailureFindings($events);
    }

    private function gateBlockedFindings(Collection $events): array
    {
        return $this->cycleHealth->gateBlockedFindings($events);
    }

    private function toolCoverageFindings(Collection $events): array
    {
        return $this->cycleHealth->toolCoverageFindings($events);
    }

    private function startRun(string $flow, bool $emit, int $hours, int $limit): ?AtlasInitiativeRun
    {
        return $this->runLifecycle->startRun($flow, $emit, $hours, $limit);
    }

    private function finishRun(?AtlasInitiativeRun $run, string $status, array $findings, array $emitted, ?string $error = null): void
    {
        $this->runLifecycle->finishRun($run, $status, $findings, $emitted, $error);
    }

    private function recordCycleEvent(LedgerEventType $type, string $envelopeId, ?AtlasInitiativeRun $run, array $payload = []): void
    {
        $this->runLifecycle->recordCycleEvent($type, $envelopeId, $run, $payload);
    }

    private function ledgerFindingProjection(array $finding): array
    {
        return SelfImprovementProjectionSupport::ledgerFindingProjection($finding);
    }


    private function scheduleHealthLedgerProjection(array $health): array
    {
        return SelfImprovementProjectionSupport::scheduleHealthLedgerProjection($health);
    }


    private function normalizeFlow(string $flow): string
    {
        return $this->filterNormalization->normalizeFlow($flow);
    }
}
