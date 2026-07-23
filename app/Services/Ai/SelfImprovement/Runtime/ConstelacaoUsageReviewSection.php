<?php

namespace App\Services\Ai\SelfImprovement\Runtime;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Collection;

/**
 * Constelacao Lente 1 usage-review finding family extracted VERBATIM from AtlasSelfImprovementRuntime
 * (GOD-DEBULK D3 split). The facade keeps a same-signature delegator; the AP-685 scanner pins for
 * this family were relocated to this section home (invariant unchanged).
 */
class ConstelacaoUsageReviewSection
{
    /**
     * @param  Collection<int,AtlasLedgerEvent>  $events
     * @param  array<string,string|null>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function constelacaoUsageReviewFindings(Collection $events, array $filters = []): array
    {
        if ($filters !== [] && ($filters['surface_id'] ?? null) !== 'constelacao') {
            return [];
        }

        $served = $events
            ->filter(fn (AtlasLedgerEvent $event): bool => $event->event_type === LedgerEventType::ConstelacaoPositionsServed->value)
            ->values();

        if ($served->isEmpty()) {
            return [];
        }

        $sourceCounts = [];
        $lensCounts = [];
        $readinessCounts = [];
        $blockedLensRequests = [];
        $graphPromotionAllowed = false;
        $pythonRuntimeAllowed = false;

        foreach ($served as $event) {
            foreach ((array) data_get($event->payload, 'source_counts', []) as $source => $count) {
                $sourceCounts[(string) $source] = ($sourceCounts[(string) $source] ?? 0) + (int) $count;
            }

            $lens = (string) data_get($event->payload, 'lens', 'unknown');
            $lensCounts[$lens] = ($lensCounts[$lens] ?? 0) + 1;

            $readiness = (string) data_get($event->payload, 'semantic_readiness_status', 'unknown');
            $readinessCounts[$readiness] = ($readinessCounts[$readiness] ?? 0) + 1;

            if ((bool) data_get($event->payload, 'lens_gate.blocked', false)) {
                $requestedLens = (string) data_get($event->payload, 'lens_gate.requested_lens', 'unknown');
                $blockedLensRequests[$requestedLens] = ($blockedLensRequests[$requestedLens] ?? 0) + 1;
            }

            $graphPromotionAllowed = $graphPromotionAllowed
                || (bool) data_get($event->payload, 'promotion_gate.graph_rag_promotion_allowed', false);
            $pythonRuntimeAllowed = $pythonRuntimeAllowed
                || (bool) data_get($event->payload, 'promotion_gate.python_runtime_allowed', false);
        }

        $latest = $served->sortByDesc('occurred_at')->first();
        $reviewStatus = ($graphPromotionAllowed || $pythonRuntimeAllowed) ? 'blocked' : 'watch';

        return [[
            'title' => 'Revisar uso real da Constelacao Lente 1',
            'category' => 'self_improvement',
            'finding' => 'A Constelacao serviu posicoes governadas no Ledger; o proximo passo e revisar uso real, taps e serenidade antes de qualquer Lente 2 ou promocao semantica.',
            'problem' => 'Sem review de uso, uma IA pode promover Graph RAG, lineage ou UI operacional cedo demais e destruir a funcao contemplativa da Lente 1.',
            'solution' => 'Manter Lente 1 como Bilderatlas contemplativo, revisar telemetria p2_metadata e coletar evidencias de conexoes uteis antes de Lente 2; Graph RAG/Python segue bloqueado.',
            'worth_it' => 'Vale porque a Constelacao e uma surface sensivel de serendipidade: ela precisa provar valor cognitivo sem virar dashboard, Inbox paralelo ou graph view operacional.',
            'best_solution_rationale' => 'O Curator usa somente eventos `CONSTELACAO_POSITIONS_SERVED` ja redigidos pelo Kernel, preservando privacy, Evidence Ledger e a fronteira AP-683/AP-684.',
            'alternatives' => ['Manter Lente 1 sem mudancas por mais 30 dias.', 'Ajustar apenas UX contemplativa.', 'Abrir AP futuro para Lente 2 se houver evidencia real.'],
            'available_actions' => [
                ['id' => 'review_constelacao_lens1_usage', 'label' => 'Revisar uso Lente 1', 'style' => 'primary'],
                ['id' => 'keep_bilderatlas_only', 'label' => 'Manter Bilderatlas', 'style' => 'secondary'],
                ['id' => 'draft_lens2_ap', 'label' => 'Rascunhar AP Lente 2', 'style' => 'secondary'],
                ['id' => 'discard', 'label' => 'Descartar', 'style' => 'destructive', 'requires_confirm' => true],
            ],
            'source_refs' => [
                ['type' => 'ledger_event', 'id' => LedgerEventType::ConstelacaoPositionsServed->value, 'count' => $served->count()],
                ['type' => 'doc', 'id' => 'docs/engineering-knowledge-base/atlas-constelacao-surface.md'],
                ['type' => 'doc', 'id' => 'docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md'],
                ['type' => 'ap', 'id' => 'docs/ap/AP-683-local-rag-graph-promotion-review.md'],
            ],
            'confidence' => $reviewStatus === 'blocked' ? 0.94 : 0.78,
            'dedupe_key' => 'self-improvement:constelacao-lens1-usage:'.sha1($served->count().':'.json_encode($lensCounts, JSON_THROW_ON_ERROR)),
            'metadata' => [
                'schema_version' => 'atlas.self_improvement.constelacao_usage_review.v1',
                'hours_window_contains_event_count' => $served->count(),
                'latest_event_id' => $latest?->event_id,
                'lens_counts' => $lensCounts,
                'blocked_lens_requests' => $blockedLensRequests,
                'source_counts' => $sourceCounts,
                'semantic_readiness_counts' => $readinessCounts,
                'review_signal' => [
                    'status' => $reviewStatus,
                    'severity' => $reviewStatus === 'blocked' ? 'high' : 'low',
                    'review_required' => true,
                    'promotion_allowed' => false,
                    'reasons' => $reviewStatus === 'blocked'
                        ? ['constelacao_runtime_promotion_gate_was_not_blocked']
                        : ['constelacao_lens1_usage_requires_human_review_before_lens2'],
                    'recommended_action' => $reviewStatus === 'blocked'
                        ? 'block_constelacao_runtime_promotion_and_review_kernel_contract'
                        : 'review_constelacao_lens1_usage_after_observation_window',
                    'next_action' => $reviewStatus === 'blocked'
                        ? 'block_constelacao_runtime_promotion_and_review_kernel_contract'
                        : 'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review',
                ],
                'usage_review_contract' => [
                    'schema_version' => 'atlas.constelacao.lens1_usage_review.v1',
                    'status' => 'proposal_only',
                    'promotion_allowed' => false,
                    'observation_window_days_required' => 30,
                    'human_review_required' => true,
                    'curator_review_required' => true,
                    'decision_receipt_required' => true,
                    'required_human_decision' => 'approve_or_reject_constelacao_lens1_promotion_after_usage_review',
                    'rollback_plan_required' => true,
                    'policy_patch_review_required' => true,
                    'auto_promotion_allowed' => false,
                    'evidence_required' => [
                        'CONSTELACAO_POSITIONS_SERVED',
                        'constelacao_opened',
                        'constelacao_backend_loaded',
                        'constelacao_star_tapped',
                        '30_day_observation_window',
                        'curator_usage_review',
                        'human_review',
                        'decision_receipt_for_future_ap',
                    ],
                    'rollback_required' => [
                        'keep_lens_bilderatlas_only',
                        'disable_command_sky_entrypoint',
                        'disable_lineage_ui',
                        'keep_graph_rag_positioning_disabled',
                        'keep_python_graph_runtime_disabled',
                        'preserve_constelacao_as_contemplative_surface',
                    ],
                    'forbidden_until_review' => [
                        'enable_lens2',
                        'enable_command_sky',
                        'enable_lineage',
                        'enable_graph_rag_positioning',
                        'enable_operational_dashboard',
                        'inject_constelacao_into_provider_prompt',
                        'patch_decide_policy',
                        'auto_apply_policy_patch',
                        'surface_direct_graph_rag_call',
                    ],
                    'allowed_outputs' => [
                        'keep_bilderatlas_only',
                        'request_more_observation',
                        'draft_lens2_ap',
                        'draft_command_sky_ap',
                        'draft_graph_rag_positioning_ap',
                    ],
                    'blocked_targets' => [
                        'lens2',
                        'command_sky',
                        'lineage',
                        'graph_rag_positioning',
                        'operational_dashboard',
                        'decision_surface',
                        'provider_prompt',
                        'policy_patch',
                        'python_graph_rag_runtime',
                    ],
                    'promotion_rule' => 'only_future_ap_with_human_review_curator_proposal_decision_receipt_and_rollback_plan',
                    'next_action' => 'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review',
                ],
                'promotion_gate' => [
                    'promotion_allowed' => false,
                    'graph_rag_promotion_allowed' => false,
                    'python_runtime_allowed' => false,
                    'requires_human_review' => true,
                    'requires_decision_receipt' => true,
                    'next_action' => 'collect_constelacao_lens1_usage_telemetry_for_30_days_before_review',
                ],
                'filters' => array_filter($filters, fn (?string $value): bool => $value !== null),
            ],
        ]];
    }
}
