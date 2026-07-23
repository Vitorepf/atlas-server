<?php

namespace App\Services\Ai\SelfImprovement\Runtime;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;

/**
 * Inbox-action replay finding family extracted VERBATIM from AtlasSelfImprovementRuntime
 * (GOD-DEBULK D3 split). The facade keeps a same-signature delegator; the AP-123/AP-146 scanner
 * pins for this family were relocated to this section home (invariant unchanged).
 */
class InboxActionReplaySection
{
    public function __construct(
        private readonly AtlasLedgerReplayService $replay,
        private readonly FilterNormalizationSection $filterNormalization,
        private readonly ProviderCostRateReplaySection $providerCostRateReplay,
    ) {}

    /**
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function inboxActionReplayFindings(int $hours, array $filters = []): array
    {
        // ponytail: normalizedDomainOnboardingFilters is a facade-only wrapper over
        // normalizedWhitelistFilters (which the section already has); inline its exact key set.
        if ($this->filterNormalization->normalizedWhitelistFilters($filters, [
            'domain',
            'flow',
            'onboarding_status' => 'onboarding_status',
        ]) !== []) {
            return [];
        }

        $report = $this->replay->inboxActionReportForWindow(
            now()->subHours($hours),
            null,
            $this->filterNormalization->normalizedInboxActionFilters($filters),
        );
        $reviewSignal = (array) ($report['review_signal'] ?? []);
        $gapReason = 'review_patch_action_without_diff_refs';
        $rivalsGapReason = 'record_rivals_review_action_without_scores';
        $providerCostRateGapReason = 'configure_provider_cost_rates_action_without_applied_rate';
        $proposalAction = 'open_reviewable_inbox_action_evidence_proposal';

        if (! (bool) ($report['available'] ?? false)
            || ! (bool) ($reviewSignal['review_required'] ?? false)) {
            return [];
        }

        if (in_array($providerCostRateGapReason, (array) ($reviewSignal['reasons'] ?? []), true)) {
            $recentEvents = collect((array) ($report['recent_events'] ?? []))
                ->filter(fn (array $event): bool => ($event['action'] ?? null) === 'configure_provider_cost_rates')
                ->filter(fn (array $event): bool => ! (bool) ($event['provider_cost_rate_applied'] ?? false))
                ->values();

            if ($recentEvents->isEmpty()) {
                return [];
            }

            return [[
                'title' => 'Completar rates de custo dos providers no Inbox',
                'category' => 'self_improvement',
                'finding' => 'Replay do Evidence Ledger encontrou `configure_provider_cost_rates` em preview/template sem rate aplicado.',
                'problem' => 'Enquanto o rate nao e aplicado, findings de custo desconhecido continuam sem fechamento auditavel e o AP-99 nao consegue comparar providers com custo real.',
                'solution' => 'Reabrir a action `configure_provider_cost_rates` no Inbox e informar input/output microusd para o provider/model indicado. O Curator apenas recomenda; nenhum rate e aplicado automaticamente.',
                'worth_it' => 'Vale porque fecha o ciclo humano de custo: Self-Improvement aponta o gap, Inbox coleta a decisao humana, Evidence Ledger prova se o rate entrou de fato.',
                'best_solution_rationale' => 'Consumir `inboxActionReportForWindow` preserva o Evidence Ledger como fonte unica e evita consulta paralela a tabelas de rates ou payloads crus do Inbox.',
                'alternatives' => ['Manter como preview ate o operador confirmar os precos.', 'Abrir benchmark de custo separado se o provider/model ainda nao tiver preco confiavel.'],
                'available_actions' => [
                    ['id' => 'configure_provider_cost_rates', 'label' => 'Configurar rates', 'style' => 'primary'],
                ],
                'source_refs' => $recentEvents
                    ->take(5)
                    ->map(fn (array $event): array => $this->providerCostRateReplay->providerCostRateReplaySourceRef($event))
                    ->values()
                    ->all(),
                'confidence' => 0.91,
                'dedupe_key' => 'self-improvement:inbox-action-replay:'.sha1($hours.':'.$providerCostRateGapReason),
                'metadata' => [
                    'schema_version' => 'atlas.self_improvement.inbox_action_replay_gap.v1',
                    'gap_type' => $providerCostRateGapReason,
                    'hours' => $hours,
                    'inbox_action_count' => (int) ($report['inbox_action_count'] ?? 0),
                    'provider_cost_rate_action_count' => (int) ($report['provider_cost_rate_action_count'] ?? 0),
                    'provider_cost_rate_applied_count' => (int) ($report['provider_cost_rate_applied_count'] ?? 0),
                    'provider_cost_rate_provider_counts' => (array) ($report['provider_cost_rate_provider_counts'] ?? []),
                    'provider_cost_rate_model_counts' => (array) ($report['provider_cost_rate_model_counts'] ?? []),
                    'action_counts' => (array) ($report['action_counts'] ?? []),
                    'actor_type_counts' => (array) ($report['actor_type_counts'] ?? []),
                    'recommended_action_counts' => (array) ($report['recommended_action_counts'] ?? []),
                    'review_signal' => $reviewSignal,
                    'available_actions' => [
                        ['id' => 'configure_provider_cost_rates', 'label' => 'Configurar rates', 'style' => 'primary'],
                    ],
                    'filters' => $this->filterNormalization->normalizedInboxActionFilters($filters),
                ],
                'payload' => [
                    'provider_cost_rates' => [
                        'schema_version' => 'atlas.provider_cost_rates.curator_completion_request.v1',
                        'recommended_action' => 'configure_provider_cost_rates',
                        'missing_applied_rate_count' => $recentEvents->count(),
                        'events' => $recentEvents
                            ->take(5)
                            ->map(fn (array $event): array => $this->providerCostRateReplay->providerCostRateReplayPayloadEvent($event))
                            ->values()
                            ->all(),
                    ],
                ],
            ]];
        }

        if (($reviewSignal['recommended_action'] ?? null) !== $proposalAction) {
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
                    'filters' => $this->filterNormalization->normalizedInboxActionFilters($filters),
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
                'filters' => $this->filterNormalization->normalizedInboxActionFilters($filters),
            ],
        ]];
    }
}
