<?php

namespace App\Services\Ai\SelfImprovement\Runtime;

use App\Models\AtlasLedgerEvent;
use App\Models\AtlasOpenBrainAccessLog;
use App\Services\Ai\Kernel\Architecture\AtlasArchitectureOperationsCatalog;
use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Learning\ProductiveFailure\ProductiveFailureSessionRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Standalone curator review findings family (Open Brain metrics, Productive Failure, provider release, SLO drift) extracted VERBATIM from AtlasSelfImprovementRuntime
 * (GOD-DEBULK partial split). Scanner-pinned families remain on the facade;
 * the facade keeps same-signature delegators for every method here.
 */
class ReviewFindingsSection
{
    public function __construct(
        private readonly AtlasLedgerReplayService $replay,
        private readonly ProductiveFailureSessionRepository $productiveFailureSessions,
        private readonly AtlasArchitectureOperationsCatalog $architectureOperations,
        private readonly FilterNormalizationSection $filterNormalization,
    ) {}

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function openBrainPromptMetricFindings(int $hours, array $filters = []): array
    {
        if (! Schema::hasTable('atlas_open_brain_access_logs')) {
            return [];
        }

        $query = AtlasOpenBrainAccessLog::query()
            ->where('accessed_at', '>=', now()->subHours($hours))
            ->where('action', 'context_pack_export');

        if (($filters['surface'] ?? null) !== null) {
            $query->where('surface', $filters['surface']);
        }

        $logs = $query
            ->orderByDesc('accessed_at')
            ->limit(100)
            ->get();

        $rows = $logs
            ->map(function (AtlasOpenBrainAccessLog $log): ?array {
                $summary = (array) ($log->result_summary_json ?? []);
                $prompt = data_get($summary, 'prompt');
                if (! is_array($prompt)) {
                    return null;
                }

                return [
                    'id' => $log->id,
                    'surface' => $log->surface,
                    'requester' => $log->requester,
                    'context_pack_hash' => $log->context_pack_hash,
                    'mode' => (string) ($prompt['mode'] ?? 'unknown'),
                    'chars' => (int) ($prompt['chars'] ?? 0),
                    'estimated_tokens' => (int) ($prompt['estimated_tokens'] ?? 0),
                    'saved_chars' => (int) ($prompt['saved_chars'] ?? 0),
                    'estimated_tokens_saved' => (int) ($prompt['estimated_tokens_saved'] ?? 0),
                    'savings_ratio' => (float) ($prompt['savings_ratio'] ?? 0),
                    'raw_prompt_persisted' => (bool) ($prompt['raw_prompt_persisted'] ?? false)
                        || (bool) data_get($summary, 'safety.prompt_raw_prompt_persisted', false)
                        || array_key_exists('prompt_section', $summary),
                    'accessed_at' => $log->accessed_at?->toJSON(),
                ];
            })
            ->filter()
            ->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $compactRows = $rows->where('mode', 'compact')->values();
        $fullRows = $rows->where('mode', 'full')->values();
        $unknownRows = $rows
            ->reject(fn (array $row): bool => in_array($row['mode'], ['compact', 'full'], true))
            ->values();
        $rawPromptViolations = $rows
            ->filter(fn (array $row): bool => (bool) ($row['raw_prompt_persisted'] ?? false))
            ->values();
        $lowSavingsRows = $compactRows
            ->filter(fn (array $row): bool => (float) ($row['savings_ratio'] ?? 0) < 0.25)
            ->values();

        $observedCount = $rows->count();
        $fullModeRatio = $observedCount > 0 ? round($fullRows->count() / $observedCount, 4) : 0.0;
        $fullModeDominant = $observedCount >= 3 && $fullModeRatio > 0.5;
        $reasons = [];
        if ($rawPromptViolations->isNotEmpty()) {
            $reasons[] = 'raw_prompt_persistence_detected';
        }
        if ($lowSavingsRows->isNotEmpty()) {
            $reasons[] = 'compact_prompt_savings_below_threshold';
        }
        if ($fullModeDominant) {
            $reasons[] = 'full_prompt_mode_dominant';
        }
        if ($unknownRows->isNotEmpty()) {
            $reasons[] = 'unknown_prompt_mode_observed';
        }

        if ($reasons === []) {
            return [];
        }

        $modeCounts = $rows
            ->map(fn (array $row): string => (string) ($row['mode'] ?? 'unknown'))
            ->countBy()
            ->all();
        $status = $rawPromptViolations->isNotEmpty() ? 'blocking' : 'review';
        $severity = $rawPromptViolations->isNotEmpty() ? 'high' : 'medium';
        $recommendedAction = $rawPromptViolations->isNotEmpty()
            ? 'remove_raw_prompt_persistence_before_next_open_brain_policy_change'
            : 'review_open_brain_prompt_metric_regression_before_changing_prompt_delivery_policy';

        return [[
            'title' => 'Corrigir regressao de economia de contexto no Open Brain',
            'category' => 'self_improvement',
            'finding' => 'Open Brain registrou '.$observedCount.' export(s) com metricas de prompt e sinalizou regressao: '.implode(', ', $reasons).'.',
            'problem' => 'Quando exports de contexto voltam a usar prompt full, economizam pouco ou persistem prompt bruto, providers externos recebem contexto maior, menos navegavel ou menos seguro.',
            'solution' => 'Abrir proposta revisavel para ajustar a politica compact-first, preservar expansao sob demanda e corrigir qualquer persistencia indevida antes de promover mudancas em AOBG/MCP.',
            'worth_it' => 'Vale porque transforma token bloat e vazamento de prompt em feedback operacional auditavel, fechando o ciclo metricas -> Self-Improvement -> politica de contexto.',
            'best_solution_rationale' => 'Consumir atlas_open_brain_access_logs reaproveita a evidencia operacional do AOBG sem criar memoria paralela nem guardar prompt bruto.',
            'alternatives' => ['Manter apenas alerta manual no maintenance status.', 'Rebaixar full mode dominante para observacao quando for auditoria explicitamente aprovada.'],
            'source_refs' => $rows
                ->take(5)
                ->map(fn (array $row): array => [
                    'type' => 'open_brain_prompt_metric',
                    'id' => $row['id'],
                    'surface' => $row['surface'],
                    'requester' => $row['requester'],
                    'context_pack_hash' => $row['context_pack_hash'],
                    'mode' => $row['mode'],
                    'chars' => $row['chars'],
                    'estimated_tokens' => $row['estimated_tokens'],
                    'saved_chars' => $row['saved_chars'],
                    'estimated_tokens_saved' => $row['estimated_tokens_saved'],
                    'savings_ratio' => $row['savings_ratio'],
                    'raw_prompt_persisted' => $row['raw_prompt_persisted'],
                    'accessed_at' => $row['accessed_at'],
                ])
                ->values()
                ->all(),
            'confidence' => $rawPromptViolations->isNotEmpty() ? 0.92 : 0.84,
            'dedupe_key' => 'self-improvement:open-brain-prompt-metrics:'.sha1(implode('|', $reasons).':'.implode('|', array_keys($modeCounts))),
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.open_brain_prompt_metrics.v1',
                'review_signal' => [
                    'status' => $status,
                    'severity' => $severity,
                    'reasons' => $reasons,
                    'recommended_action' => $recommendedAction,
                ],
                'observed_count' => $observedCount,
                'compact_count' => $compactRows->count(),
                'full_count' => $fullRows->count(),
                'unknown_mode_count' => $unknownRows->count(),
                'mode_counts' => $modeCounts,
                'full_mode_ratio' => $fullModeRatio,
                'raw_prompt_persistence_violation_count' => $rawPromptViolations->count(),
                'low_savings_count' => $lowSavingsRows->count(),
                'avg_chars' => round((float) $rows->avg('chars'), 2),
                'avg_saved_chars' => round((float) $rows->avg('saved_chars'), 2),
                'avg_estimated_tokens_saved' => round((float) $rows->avg('estimated_tokens_saved'), 2),
                'avg_savings_ratio' => round((float) $rows->avg('savings_ratio'), 4),
                'filters' => array_filter($filters, fn (?string $value): bool => $value !== null),
            ],
        ]];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function productiveFailureTransferTestFindings(array $filters = []): array
    {
        $domain = $filters['domain'] ?? null;
        $proposals = $this->productiveFailureSessions->transferTestProposals($domain, 90, dueOnly: true);

        if ($proposals === []) {
            return [];
        }

        $proposalIds = collect($proposals)->pluck('transfer_test_id')->filter()->values()->all();

        return [[
            'title' => 'Revisar transfer_tests vencidos de Productive Failure',
            'category' => 'self_improvement',
            'finding' => 'Existem testes de transferencia do AP-168 ja vencidos; eles precisam de review humano para provar se o erro preditivo virou transferencia real.',
            'problem' => 'Sem uma proposta dedicada, o Atlas pode gerar erro produtivo, mas esquecer a prova futura que transforma insight em maestria transferivel.',
            'solution' => 'Abrir revisao proposal-only dos transfer_tests vencidos, mantendo auto_apply=false e exigindo aceite humano antes de qualquer mudanca de curriculo, mastery ou schedule.',
            'worth_it' => 'Vale porque fecha o ciclo C14/C16/C20: gerar erro, extrair principio e provar transferencia em outro caso sem criar estudo automatico.',
            'best_solution_rationale' => 'O Curator le o read-model do proprio AP-168 e emite uma proposta revisavel; Learning continua dono do flow e Self-Improvement nao auto-matricula nada.',
            'alternatives' => ['Revisar manualmente via CLI.', 'Aguardar mais dados antes de promover UX App/Mobile.', 'Arquivar propostas antigas caso o contexto tenha expirado.'],
            'available_actions' => [
                ['id' => 'review_due_productive_failure_transfer_tests', 'label' => 'Revisar transfer_tests', 'style' => 'primary'],
                ['id' => 'reschedule_transfer_tests', 'label' => 'Reagendar com review', 'style' => 'secondary'],
                ['id' => 'archive_stale_transfer_tests', 'label' => 'Arquivar obsoletos', 'style' => 'secondary'],
            ],
            'source_refs' => [
                ['type' => 'ap', 'id' => 'docs/ap/AP-168-cognitive-productive-failure-flow.md'],
                ['type' => 'command', 'id' => 'php artisan atlas:productive-failure transfer-tests --due-only --json'],
                ['type' => 'domain_flow', 'id' => 'learning.productive_failure'],
            ],
            'confidence' => min(0.96, 0.78 + (count($proposals) * 0.03)),
            'dedupe_key' => 'self-improvement:productive-failure-transfer-tests:'.sha1(implode('|', $proposalIds)),
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.productive_failure_transfer_review.v1',
                'proposal_count' => count($proposals),
                'proposal_ids' => $proposalIds,
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => count($proposals) >= 3 ? 'medium' : 'low',
                    'review_required' => true,
                    'reasons' => ['productive_failure_transfer_tests_due_for_human_review'],
                    'recommended_action' => 'review_due_productive_failure_transfer_tests',
                ],
                'policy_contract' => [
                    'status' => 'proposal_only',
                    'auto_apply' => false,
                    'human_review_required' => true,
                    'forbidden_mutations' => ['curriculum_auto_enroll', 'mastery_auto_promote', 'schedule_auto_write'],
                ],
                'proposals' => array_slice($proposals, 0, 10),
                'filters' => array_filter($filters, fn (?string $value): bool => $value !== null),
            ],
        ]];
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function providerReleaseGovernanceFindings(array $filters = []): array
    {
        $summary = $this->architectureOperations->summary(['kind' => 'provider_evolution']);
        $operationIds = (array) ($summary['operation_ids'] ?? []);
        $missing = [];

        if (! in_array('provider_release_review', $operationIds, true)) {
            $missing[] = 'provider_release_review_operation_missing';
        }
        if (! is_file(base_path('docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md'))) {
            $missing[] = 'provider_evolution_doc_missing';
        }

        if ($missing === []) {
            return [];
        }

        return [[
            'title' => 'Restaurar governanca de Provider Evolution',
            'category' => 'self_improvement',
            'finding' => 'O fluxo de Provider Evolution perdeu parte do contrato executavel que impede lancamentos externos de virarem hardcode ou canal direto.',
            'problem' => 'Sem review governado, Atlas pode reagir a novidades de Claude/OpenAI/Gemini como wrapper fragil em vez de absorver via Decide, Rivals, skill packs e APs.',
            'solution' => 'Restaurar o comando provider-release-review, doc canonico e catalogo de arquitetura antes de qualquer policy ou maturidade de domain baseada em release externo.',
            'worth_it' => 'Vale porque protege a tese central: providers melhoram, Atlas multiplica sem perder o canal unico.',
            'best_solution_rationale' => 'Auditar a superficie executavel no Curator e mais seguro do que depender de memoria humana ou prompt solto.',
            'alternatives' => ['Arquivar o release ate a governanca voltar.', 'Rodar apenas benchmark manual sem promocao para policy.'],
            'source_refs' => [
                ['type' => 'architecture_operations', 'id' => 'provider_evolution'],
                ['type' => 'doc', 'id' => 'docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md'],
            ],
            'dedupe_key' => 'self-improvement:provider-release-governance:'.sha1(implode('|', $missing)),
            'confidence' => 0.96,
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.provider_release_governance.v1',
                'missing' => $missing,
                'filters' => $this->normalizedProviderReleaseFilters($filters),
                'recommended_action' => 'restore_provider_release_governance_contract',
                'review_signal' => [
                    'status' => 'breach',
                    'severity' => 'high',
                    'review_required' => true,
                    'reasons' => $missing,
                    'recommended_action' => 'restore_provider_release_governance_contract',
                ],
            ],
        ]];
    }

    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @return array<int,array<string,mixed>>
     */
    public function sloDriftFindings(Collection $events, array $filters = []): array
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
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedDimensionFilters(array $filters): array
    {
        return $this->filterNormalization->normalizedDimensionFilters($filters);
    }

    /**
     * @param  array<string,string|null>  $filters
     * @return array<string,string>
     */
    private function normalizedProviderReleaseFilters(array $filters): array
    {
        return $this->filterNormalization->normalizedProviderReleaseFilters($filters);
    }
}
