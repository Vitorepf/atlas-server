<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use Illuminate\Support\Collection;

final class AiCriticalInboxReviewReadModel
{
    public function __construct(private readonly AiInboxHumanPresentation $presentation) {}

    /**
     * @return array<string,mixed>
     */
    public function review(string $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $query = AiInboxItem::query()
            ->with('contextBundle')
            ->where('user_id', $userId)
            ->where('type', 'insight')
            ->where('initiator', 'atlas')
            ->where('severity', 'critical')
            ->whereNotIn('status', ['resolved', 'dismissed', 'expired']);
        $activeCriticalCount = (clone $query)->count();
        $items = $query
            ->latest()
            ->limit($limit)
            ->get();

        $rows = $items
            ->map(fn (AiInboxItem $item): array => $this->criticalReviewItem($item))
            ->values()
            ->all();

        return [
            'critical_review' => [
                'schema_version' => 'atlas.inbox.critical_review.v1',
                'status' => $activeCriticalCount === 0 ? 'clear' : 'human_review_required',
                'active_critical_count' => $activeCriticalCount,
                'returned_item_count' => $items->count(),
                'operator_required' => $activeCriticalCount > 0,
                'agent_auto_resolve_allowed' => false,
                'agent_auto_dismiss_allowed' => false,
                'raw_payload_exposed' => false,
                'api_contract' => [
                    'schema_version' => 'atlas.inbox.critical_review.api_contract.v1',
                    'review_endpoint' => 'GET /v1/mobile/inbox/critical-review',
                    'respond_endpoint_template' => 'POST /v1/mobile/inbox/{inbox_item_id}/respond',
                    'discuss_endpoint_template' => 'POST /v1/mobile/inbox/{inbox_item_id}/discuss',
                    'allowed_action_ids' => ['discuss', 'mark_read', 'snooze', 'dismiss'],
                    'reason_required_for' => ['mark_read', 'snooze', 'dismiss'],
                    'evidence_required_for' => ['dismiss'],
                    'receipt_event_type' => 'inbox.action.completed',
                    'ledger_schema_version' => 'atlas.inbox_action.receipt.v1',
                    'operator_required' => $activeCriticalCount > 0,
                    'agent_auto_resolve_allowed' => false,
                    'agent_auto_dismiss_allowed' => false,
                ],
                'review_summary' => $this->criticalReviewSummary($rows, $activeCriticalCount),
                'items' => $rows,
                'completion_recheck_command' => 'php artisan atlas:ai:structure-mother-audit --hours=720 --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json',
                'generated_at' => now()->toJSON(),
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function criticalReviewSummary(array $rows, int $activeCriticalCount): array
    {
        $collection = collect($rows);
        $unreadCount = $collection->where('status', 'unread')->count();
        $readCount = $collection->where('status', 'read')->count();
        $healthCount = $collection->filter(fn (array $row): bool => $this->criticalReviewKind($row) === 'health')->count();
        $performanceCount = $collection->filter(fn (array $row): bool => $this->criticalReviewKind($row) === 'performance')->count();
        $costRecoveredCount = $collection->filter(fn (array $row): bool => $this->hasRecoveredCostVisibility($row))->count();

        return [
            'schema_version' => 'atlas.inbox.critical_review_summary.v1',
            'scope' => 'returned_items',
            'active_critical_count' => $activeCriticalCount,
            'returned_item_count' => $collection->count(),
            'unread_count' => $unreadCount,
            'read_count' => $readCount,
            'other_status_count' => max(0, $collection->count() - $unreadCount - $readCount),
            'health_signal_count' => $healthCount,
            'performance_report_count' => $performanceCount,
            'other_kind_count' => max(0, $collection->count() - $healthCount - $performanceCount),
            'cost_visibility_recovered_count' => $costRecoveredCount,
            'still_requires_operator_decision_count' => $collection->count(),
            'priority_order' => [
                'review_unread_health_critical_first',
                'review_performance_quality_latency_or_cost_next',
                'dismiss_or_resolve_only_after_operator_evidence',
            ],
            'recommended_operator_flow' => [
                'Abrir primeiro os itens nao lidos de saude critica.',
                'Nos relatorios de performance com custo recalculado, validar se ainda existe risco de qualidade, latencia ou eficiencia antes de descartar.',
                'Depois da revisao humana, usar mark_read, snooze ou dismiss com motivo e evidencia.',
            ],
            'safety' => [
                'operator_review_required' => $activeCriticalCount > 0,
                'agent_auto_resolve_allowed' => false,
                'agent_auto_dismiss_allowed' => false,
                'summary_is_triage_only' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function criticalReviewItem(AiInboxItem $item): array
    {
        $presentation = $this->presentation->forItem($item);
        $payload = is_array($item->payload) ? $item->payload : [];
        $kind = is_array($payload['health'] ?? null)
            ? 'health'
            : (is_array($payload['report'] ?? null) ? 'performance' : 'generic');

        return [
            'id' => $item->id,
            'deep_link' => $item->deep_link ?: 'atlas://inbox/'.$item->id,
            'review_kind' => $kind,
            'category' => $item->category,
            'severity' => $item->severity,
            'status' => $item->status,
            'status_label' => (string) ($presentation['status_label'] ?? $item->status),
            'created_at' => $item->created_at?->toJSON(),
            'headline' => $presentation['headline'] ?? $item->title,
            'plain_summary' => $presentation['plain_summary'] ?? $item->summary,
            'primary_metric' => $presentation['primary_metric'] ?? null,
            'metrics' => $presentation['metrics'] ?? [],
            'why_this_matters' => $presentation['why_this_matters'] ?? null,
            'operator_next_step' => $presentation['operator_next_step'] ?? 'Revisar manualmente antes de qualquer acao.',
            'decision_options' => $this->decisionOptions($item, $kind),
            'commands' => [
                'show' => "php artisan atlas:cli:inbox show '{$item->id}' --json",
                'discuss' => "php artisan atlas:cli:inbox discuss '{$item->id}' --json",
                'mark_read_after_review' => "php artisan atlas:cli:inbox respond '{$item->id}' --action=mark_read --reason='operator reviewed critical insight' --json",
                'snooze_with_reason' => "php artisan atlas:cli:inbox respond '{$item->id}' --action=snooze --reason='operator needs later review' --snoozed-until='<ISO-8601 future timestamp>' --json",
                'dismiss_after_review' => "php artisan atlas:cli:inbox respond '{$item->id}' --action=dismiss --reason='<operator evidence summary>' --json",
            ],
            'safety' => [
                'operator_review_required' => true,
                'agent_auto_resolve_allowed' => false,
                'agent_auto_dismiss_allowed' => false,
                'raw_payload_exposed' => false,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function decisionOptions(AiInboxItem $item, string $kind): array
    {
        $base = [
            [
                'id' => 'discuss_in_atlas',
                'label' => 'Discutir com Atlas',
                'action_id' => 'discuss',
                'recommended' => $kind === 'health',
                'requires_reason' => false,
                'requires_evidence' => false,
                'state_effect' => 'marks_read_and_opens_thread',
                'status_after' => 'read',
                'allowed_for_agent' => false,
                'allowed_for_operator' => true,
                'explanation' => 'Abre uma conversa com contexto seguro para investigar causas antes de decidir.',
            ],
            [
                'id' => 'mark_read_after_review',
                'label' => 'Marcar como revisado',
                'action_id' => 'mark_read',
                'recommended' => false,
                'requires_reason' => true,
                'requires_evidence' => false,
                'state_effect' => 'keeps_active_but_records_operator_review',
                'status_after' => 'read',
                'allowed_for_agent' => false,
                'allowed_for_operator' => true,
                'explanation' => 'Use quando voce leu o item, mas ele ainda nao deve sair da fila critica.',
            ],
            [
                'id' => 'snooze_with_reason',
                'label' => 'Adiar com motivo',
                'action_id' => 'snooze',
                'recommended' => false,
                'requires_reason' => true,
                'requires_evidence' => false,
                'state_effect' => 'hides_until_snooze_expires',
                'status_after' => 'snoozed',
                'allowed_for_agent' => false,
                'allowed_for_operator' => true,
                'explanation' => 'Use quando a revisao e real, mas precisa esperar nova evidencia.',
            ],
            [
                'id' => 'dismiss_after_evidence',
                'label' => 'Descartar apos evidencia',
                'action_id' => 'dismiss',
                'recommended' => false,
                'requires_reason' => true,
                'requires_evidence' => true,
                'state_effect' => 'removes_from_active_critical_gate',
                'status_after' => 'dismissed',
                'allowed_for_agent' => false,
                'allowed_for_operator' => true,
                'explanation' => 'Use somente depois de validar que nao resta risco operacional ativo.',
            ],
        ];

        if ($kind === 'performance') {
            $base[0]['recommended'] = true;
            $base[0]['explanation'] = 'Compare qualidade, latencia, eficiencia e custo recalculado antes de decidir.';
        }

        if ($item->status === 'read') {
            $base[1]['recommended'] = false;
            $base[1]['explanation'] = 'Este item ja esta lido; use discutir, adiar ou descartar apenas com evidencia.';
        }

        return $base;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function criticalReviewKind(array $row): string
    {
        $kind = (string) ($row['review_kind'] ?? '');
        if (in_array($kind, ['health', 'performance'], true)) {
            return $kind;
        }

        $label = (string) data_get($row, 'primary_metric.label', '');

        return match ($label) {
            'Saude' => 'health',
            'Qualidade media' => 'performance',
            default => 'generic',
        };
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function hasRecoveredCostVisibility(array $row): bool
    {
        /** @var Collection<int,array<string,mixed>> $metrics */
        $metrics = collect($row['metrics'] ?? [])->filter(fn (mixed $metric): bool => is_array($metric));
        $recalculated = $metrics->contains(fn (array $metric): bool => ($metric['label'] ?? null) === 'Custo recalculado'
            && mb_strtolower((string) ($metric['value'] ?? '')) === 'sim');
        $unknownCostCleared = $metrics->contains(fn (array $metric): bool => ($metric['label'] ?? null) === 'Custo desconhecido'
            && (string) ($metric['value'] ?? '') === '0%');

        return $recalculated && $unknownCostCleared;
    }
}
