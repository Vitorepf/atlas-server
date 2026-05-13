<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AtlasInitiativeRun;
use App\Models\MobilePushDelivery;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class ProactiveLayerReadModel
{
    public const SCHEMA_VERSION = 'atlas.proactive_layer_report.v1';

    /**
     * @return array<string,mixed>
     */
    public function report(?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subHours(24);
        $until ??= now();
        $tables = $this->tables();

        if (! $tables['atlas_initiative_runs'] || ! $tables['ai_inbox_items']) {
            return [
                'available' => false,
                'schema_version' => self::SCHEMA_VERSION,
                'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
                'tables' => $tables,
                'status' => 'storage_unavailable',
                'writes' => false,
                'review_signal' => [
                    'status' => 'unknown',
                    'severity' => 'medium',
                    'reasons' => ['proactive_layer_tables_missing'],
                    'recommended_action' => 'run_mobile_inbox_and_initiative_migrations',
                ],
            ];
        }

        $runs = AtlasInitiativeRun::query()
            ->where('kind', 'insight_watch')
            ->whereBetween('created_at', [$since, $until])
            ->latest()
            ->get();
        $insights = AiInboxItem::query()
            ->where('type', 'insight')
            ->where('initiator', 'atlas')
            ->whereBetween('created_at', [$since, $until])
            ->latest()
            ->get();
        $pushDeliveries = $this->pushDeliveries($insights, $tables['mobile_push_deliveries']);

        $summary = $this->summary($runs, $insights, $pushDeliveries, $tables['mobile_push_deliveries']);
        $reviewSignal = $this->reviewSignal($summary);

        return [
            'available' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
            'tables' => $tables,
            'status' => $reviewSignal['status'] === 'ok' ? 'ok' : 'warning',
            ...$summary,
            'safety' => $this->reportSafety($tables['mobile_push_deliveries']),
            'review_signal' => $reviewSignal,
            'critical_review_contract' => $this->criticalReviewContract($insights),
            'latest_runs' => $runs->take(10)->map(fn (AtlasInitiativeRun $run): array => $this->runPayload($run))->values()->all(),
            'recent_insights' => $insights->take(10)->map(fn (AiInboxItem $item): array => $this->insightPayload($item))->values()->all(),
            'writes' => false,
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function tables(): array
    {
        return [
            'atlas_initiative_runs' => Schema::hasTable('atlas_initiative_runs'),
            'ai_inbox_items' => Schema::hasTable('ai_inbox_items'),
            'mobile_push_deliveries' => Schema::hasTable('mobile_push_deliveries'),
        ];
    }

    /**
     * @param  Collection<int,AiInboxItem>  $insights
     * @return Collection<int,MobilePushDelivery>
     */
    private function pushDeliveries(Collection $insights, bool $tableExists): Collection
    {
        if (! $tableExists || $insights->isEmpty()) {
            return collect();
        }

        return MobilePushDelivery::query()
            ->whereIn('inbox_item_id', $insights->pluck('id')->all())
            ->latest('attempted_at')
            ->get();
    }

    /**
     * @param  Collection<int,AtlasInitiativeRun>  $runs
     * @param  Collection<int,AiInboxItem>  $insights
     * @param  Collection<int,MobilePushDelivery>  $pushDeliveries
     * @return array<string,mixed>
     */
    private function summary(Collection $runs, Collection $insights, Collection $pushDeliveries, bool $pushTableExists): array
    {
        $emittedIds = $runs
            ->flatMap(fn (AtlasInitiativeRun $run): array => (array) ($run->emitted_inbox_item_ids ?? []))
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();
        $candidateCount = $runs->sum(fn (AtlasInitiativeRun $run): int => count((array) ($run->findings ?? [])));

        return [
            'run_count' => $runs->count(),
            'succeeded_run_count' => $runs->where('status', 'succeeded')->count(),
            'failed_run_count' => $runs->where('status', 'failed')->count(),
            'candidate_count' => $candidateCount,
            'emitted_inbox_item_count' => $emittedIds->count(),
            'insight_item_count' => $insights->count(),
            'active_insight_item_count' => $insights->whereNotIn('status', ['resolved', 'dismissed', 'expired'])->count(),
            'unread_insight_item_count' => $insights->where('status', 'unread')->count(),
            'critical_insight_item_count' => $insights->where('severity', 'critical')->count(),
            'active_critical_insight_item_count' => $insights
                ->where('severity', 'critical')
                ->whereNotIn('status', ['resolved', 'dismissed', 'expired'])
                ->count(),
            'severity_counts' => $insights->pluck('severity')->countBy()->all(),
            'category_counts' => $insights->pluck('category')->filter()->countBy()->all(),
            'push_delivery_available' => $pushTableExists,
            'push_delivery_count' => $pushDeliveries->count(),
            'push_status_counts' => $pushDeliveries->pluck('status')->countBy()->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function reviewSignal(array $summary): array
    {
        if ((int) ($summary['failed_run_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'medium',
                'reasons' => ['insight_watch_runs_failed'],
                'recommended_action' => 'inspect_failed_insight_watch_runs',
            ];
        }

        if ((int) ($summary['active_critical_insight_item_count'] ?? 0) > 0) {
            return [
                'status' => 'warning',
                'severity' => 'high',
                'reasons' => ['critical_proactive_insights_active'],
                'recommended_action' => 'review_critical_proactive_insights',
            ];
        }

        return [
            'status' => 'ok',
            'severity' => 'none',
            'reasons' => [],
            'recommended_action' => 'continue_proactive_layer_monitoring',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function reportSafety(bool $pushDeliveryAvailable): array
    {
        return [
            'schema_version' => 'atlas.proactive_layer.report_safety.v1',
            'read_model_only' => true,
            'writes' => false,
            'atlas_initiated_insights_only' => true,
            'push_delivery_available' => $pushDeliveryAvailable,
            'push_pointer_only' => true,
            'authenticated_fetch_required' => true,
            'deep_link_only_delivery' => true,
            'raw_context_exposed_in_push' => false,
            'raw_payload_exposed_in_push' => false,
            'body_exposed_in_push' => false,
            'raw_device_id_persisted_in_audit' => false,
            'agent_auto_resolve_allowed' => false,
            'agent_auto_dismiss_allowed' => false,
            'operator_review_required_for_critical' => true,
        ];
    }

    /**
     * @param  Collection<int,AiInboxItem>  $insights
     * @return array<string,mixed>
     */
    private function criticalReviewContract(Collection $insights): array
    {
        $critical = $insights
            ->filter(fn (AiInboxItem $item): bool => $item->severity === 'critical'
                && ! in_array($item->status, ['resolved', 'dismissed', 'expired'], true))
            ->values();

        return [
            'schema_version' => 'atlas.proactive.critical_review_contract.v1',
            'status' => $critical->isEmpty() ? 'clear' : 'human_review_required',
            'critical_active_count' => $critical->count(),
            'operator_required' => ! $critical->isEmpty(),
            'agent_resolution_allowed' => false,
            'auto_dismiss_allowed' => false,
            'push_escalation_allowed_without_preferences' => false,
            'allowed_actions' => $critical->isEmpty() ? [] : [
                'open_inbox_item',
                'discuss_in_atlas_thread',
                'operator_mark_read',
                'operator_snooze',
                'operator_dismiss_after_review',
                'operator_resolve_after_evidence',
            ],
            'prohibited_actions' => $critical->isEmpty() ? [] : [
                'agent_auto_resolve_critical_insight',
                'agent_auto_dismiss_critical_insight',
                'send_push_without_device_preference_contract',
                'treat_critical_insight_warning_as_structure_mother_complete',
            ],
            'operator_review_plan' => $this->operatorReviewPlan($critical),
            'items' => $critical
                ->take(10)
                ->map(fn (AiInboxItem $item): array => [
                    'id' => $item->id,
                    'category' => $item->category,
                    'status' => $item->status,
                    'dedupe_key' => $item->dedupe_key,
                    'deep_link' => $item->deep_link,
                    'created_at' => $item->created_at?->toJSON(),
                    'review_path' => $item->deep_link ?: 'atlas://inbox/'.$item->id,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int,AiInboxItem>  $critical
     * @return array<string,mixed>
     */
    private function operatorReviewPlan(Collection $critical): array
    {
        if ($critical->isEmpty()) {
            return [
                'schema_version' => 'atlas.proactive.operator_review_plan.v1',
                'status' => 'clear',
                'commands' => [],
                'item_commands' => [],
                'rules' => [
                    'operator_required' => false,
                    'agent_auto_resolve_allowed' => false,
                    'agent_auto_dismiss_allowed' => false,
                ],
            ];
        }

        return [
            'schema_version' => 'atlas.proactive.operator_review_plan.v1',
            'status' => 'pending_operator_review',
            'commands' => [
                'list_critical' => 'php artisan atlas:cli:inbox list --filter=insight --severity=critical --json',
                'report' => 'php artisan atlas:ai:proactive-layer-report --hours=720 --json',
            ],
            'item_commands' => $critical
                ->take(10)
                ->map(fn (AiInboxItem $item): array => [
                    'id' => $item->id,
                    'show' => "php artisan atlas:cli:inbox show '{$item->id}' --json",
                    'discuss' => "php artisan atlas:cli:inbox discuss '{$item->id}' --json",
                    'mark_read_after_review' => "php artisan atlas:cli:inbox respond '{$item->id}' --action=mark_read --reason='operator reviewed critical insight' --json",
                    'snooze_with_reason' => "php artisan atlas:cli:inbox respond '{$item->id}' --action=snooze --reason='operator needs later review' --snoozed-until='<ISO-8601 future timestamp>' --json",
                    'dismiss_after_review' => "php artisan atlas:cli:inbox respond '{$item->id}' --action=dismiss --reason='<operator evidence summary>' --json",
                ])
                ->values()
                ->all(),
            'rules' => [
                'operator_required' => true,
                'agent_auto_resolve_allowed' => false,
                'agent_auto_dismiss_allowed' => false,
                'reason_required_for_snooze_or_dismiss' => true,
                'review_evidence_required_before_dismiss' => true,
                'completion_gate_recheck_command' => 'php artisan atlas:ai:structure-mother-audit --hours=720 --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runPayload(AtlasInitiativeRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'candidate_count' => count((array) ($run->findings ?? [])),
            'emitted_inbox_item_count' => count((array) ($run->emitted_inbox_item_ids ?? [])),
            'started_at' => $run->started_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
            'created_at' => $run->created_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function insightPayload(AiInboxItem $item): array
    {
        return [
            'id' => $item->id,
            'category' => $item->category,
            'severity' => $item->severity,
            'status' => $item->status,
            'source_type' => $item->source_type,
            'source_id' => $item->source_id,
            'dedupe_key' => $item->dedupe_key,
            'deep_link' => $item->deep_link,
            'created_at' => $item->created_at?->toJSON(),
        ];
    }
}
