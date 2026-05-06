<?php

namespace App\Services\Ai\SelfImprovement;

use App\Models\AtlasInitiativeRun;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Architecture\AtlasAiArchitectureValidationService;
use App\Services\Ai\Kernel\Domain\AtlasAiDomainCatalogService;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class AtlasSelfImprovementRuntime
{
    public const DEFAULT_REVIEW_WINDOW_HOURS = 24;

    public const MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS = 168;

    public const MAX_FINDINGS_PER_RUN = 20;

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AtlasLedgerReplayService $replay,
        private readonly ProposalInboxEmitter $proposals,
        private readonly AtlasAiDomainCatalogService $domainCatalog,
        private readonly AtlasAiArchitectureValidationService $architectureValidation,
        private readonly AtlasSelfImprovementScheduleService $schedule,
    ) {}

    /**
     * @param  array<string,string|null>  $filters
     * @return array{ok:bool,run_id:?string,flow:string,dry_run:bool,hours:int,filters:array<string,string>,findings:array<int,array<string,mixed>>,emitted_item_ids:array<int,string>,emitted_count:int}
     */
    public function nightlyReview(string $flow = 'self_improvement.nightly_review', bool $emit = false, int $hours = self::DEFAULT_REVIEW_WINDOW_HOURS, int $limit = 5, array $filters = []): array
    {
        $flow = $this->normalizeFlow($flow);
        $hours = max(1, min(self::MAX_AUTONOMOUS_REVIEW_WINDOW_HOURS, $hours));
        $limit = max(1, min(self::MAX_FINDINGS_PER_RUN, $limit));
        $filters = $this->normalizedRuntimeFilters($filters);
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
            if ($emit) {
                foreach ($findings as $finding) {
                    $item = $this->proposals->emit([
                        ...$finding,
                        'source_type' => 'atlas_initiative_run',
                        'source_id' => $run?->id,
                    ]);
                    if ($item) {
                        $emitted[] = $item->id;
                    }
                }
            }

            foreach ($findings as $finding) {
                $this->recordCycleEvent(LedgerEventType::LearningProposed, $envelopeId, $run, [
                    'flow' => $flow,
                    'finding' => $this->ledgerFindingProjection($finding),
                    'emitted' => $emit,
                ]);
            }

            $this->finishRun($run, 'succeeded', $findings, $emitted);
            $this->recordCycleEvent(LedgerEventType::OperationCompleted, $envelopeId, $run, [
                'flow' => $flow,
                'finding_count' => count($findings),
                'emitted_count' => count($emitted),
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
                ...$this->selfImprovementScheduleFindings($filters),
                ...$this->selfImprovementScheduleReplayFindings($hours, $filters),
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
                ...$this->selfImprovementScheduleReplayFindings($hours, $filters),
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
        $payload = $this->architectureValidation->payload();
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

        if (! (bool) ($report['available'] ?? false) || ! (bool) ($reviewSignal['review_required'] ?? false) || $warningCount === 0) {
            return [];
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

        return [[
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
    private function normalizedRuntimeFilters(array $filters): array
    {
        return [
            ...$this->normalizedDimensionFilters($filters),
            ...$this->normalizedRepairFilters($filters),
            ...$this->normalizedKernelPipelineFilters($filters),
            ...$this->normalizedDomainOnboardingFilters($filters),
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
        return [
            'title' => $finding['title'] ?? null,
            'category' => $finding['category'] ?? null,
            'dedupe_key' => $finding['dedupe_key'] ?? null,
            'confidence' => $finding['confidence'] ?? null,
            'source_ref_count' => count((array) ($finding['source_refs'] ?? [])),
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
