<?php

namespace App\Services\Ai\Mobile;

use App\Models\AiInboxItem;
use App\Models\AtlasInitiativeRun;
use App\Models\AtlasMobileDevice;
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
        $mobilePushConfiguration = $this->mobilePushConfiguration($insights, $tables['atlas_mobile_devices']);

        $summary = $this->summary($runs, $insights, $pushDeliveries, $tables['mobile_push_deliveries'], $mobilePushConfiguration);
        $reviewSignal = $this->reviewSignal($summary);

        return [
            'available' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'window' => ['since' => $since->toJSON(), 'until' => $until->toJSON()],
            'tables' => $tables,
            'status' => $reviewSignal['status'] === 'ok' ? 'ok' : 'warning',
            ...$summary,
            'safety' => $this->reportSafety($tables['mobile_push_deliveries'], (bool) data_get($mobilePushConfiguration, 'push_dispatch_enabled')),
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
            'atlas_mobile_devices' => Schema::hasTable('atlas_mobile_devices'),
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
     * @param  array<string,mixed>  $mobilePushConfiguration
     * @return array<string,mixed>
     */
    private function summary(Collection $runs, Collection $insights, Collection $pushDeliveries, bool $pushTableExists, array $mobilePushConfiguration): array
    {
        $emittedIds = $runs
            ->flatMap(fn (AtlasInitiativeRun $run): array => (array) ($run->emitted_inbox_item_ids ?? []))
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values();
        $candidateCount = $runs->sum(fn (AtlasInitiativeRun $run): int => count((array) ($run->findings ?? [])));
        $pushRequestedInsightCount = $insights
            ->filter(fn (AiInboxItem $item): bool => ($item->push_policy['send'] ?? 'auto') !== 'none')
            ->count();
        $activePushRequestedInsightCount = $this->activeInsights($insights)
            ->filter(fn (AiInboxItem $item): bool => ($item->push_policy['send'] ?? 'auto') !== 'none')
            ->count();

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
            'push_requested_insight_count' => $pushRequestedInsightCount,
            'active_push_requested_insight_count' => $activePushRequestedInsightCount,
            'push_delivery_count' => $pushDeliveries->count(),
            'push_status_counts' => $pushDeliveries->pluck('status')->countBy()->all(),
            'mobile_push_configuration' => $mobilePushConfiguration,
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    private function reviewSignal(array $summary): array
    {
        $reasons = [];
        $recommendedAction = 'continue_proactive_layer_monitoring';
        $severity = 'none';

        if ((int) ($summary['failed_run_count'] ?? 0) > 0) {
            $reasons[] = 'insight_watch_runs_failed';
            $recommendedAction = 'inspect_failed_insight_watch_runs';
            $severity = 'medium';
        }

        if ((int) ($summary['active_critical_insight_item_count'] ?? 0) > 0) {
            $reasons[] = 'critical_proactive_insights_active';
            $recommendedAction = 'review_critical_proactive_insights';
            $severity = 'high';
        }

        if ((int) ($summary['active_push_requested_insight_count'] ?? $summary['push_requested_insight_count'] ?? 0) > 0
            && ! (bool) data_get($summary, 'mobile_push_configuration.push_dispatch_enabled')) {
            $reasons[] = 'mobile_push_dispatch_disabled';
            if ($severity === 'none') {
                $severity = 'medium';
                $recommendedAction = 'enable_mobile_push_or_accept_inbox_only_delivery';
            }
        }

        $pushBlockedReason = data_get($summary, 'mobile_push_configuration.push_dispatch_blocked_reason');
        if (is_string($pushBlockedReason) && $pushBlockedReason !== '' && ! in_array($pushBlockedReason, $reasons, true)) {
            $reasons[] = $pushBlockedReason;
            if ($severity === 'none') {
                $severity = 'medium';
            }
            $recommendedAction = match ($pushBlockedReason) {
                'no_registered_push_token' => 'register_mobile_push_token',
                'notification_permission_denied' => 'enable_mobile_notification_permission',
                'mobile_device_table_missing' => 'run_mobile_gateway_migrations',
                default => $recommendedAction,
            };
        }

        if ((int) ($summary['active_push_requested_insight_count'] ?? $summary['push_requested_insight_count'] ?? 0) > 0
            && (int) ($summary['push_delivery_count'] ?? 0) === 0
            && (bool) data_get($summary, 'mobile_push_configuration.push_dispatch_enabled')
            && data_get($summary, 'mobile_push_configuration.push_dispatch_blocked_reason') === null) {
            $reasons[] = 'push_requested_without_delivery_attempt';
            if ($severity === 'none') {
                $severity = 'medium';
            }
            $recommendedAction = 'replay_pending_mobile_push_dispatches';
        }

        if ($reasons !== []) {
            return [
                'status' => 'warning',
                'severity' => $severity,
                'reasons' => array_values(array_unique($reasons)),
                'recommended_action' => $recommendedAction,
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
    private function reportSafety(bool $pushDeliveryAvailable, bool $pushDispatchEnabled): array
    {
        return [
            'schema_version' => 'atlas.proactive_layer.report_safety.v1',
            'read_model_only' => true,
            'writes' => false,
            'atlas_initiated_insights_only' => true,
            'push_delivery_available' => $pushDeliveryAvailable,
            'push_dispatch_enabled' => $pushDispatchEnabled,
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
    private function mobilePushConfiguration(Collection $insights, bool $deviceTableExists): array
    {
        $mobileEnabled = (bool) config('atlas.mobile.enabled', false);
        $pushRequestedInsightCount = $insights
            ->filter(fn (AiInboxItem $item): bool => ($item->push_policy['send'] ?? 'auto') !== 'none')
            ->count();
        $activePushRequestedInsightCount = $this->activeInsights($insights)
            ->filter(fn (AiInboxItem $item): bool => ($item->push_policy['send'] ?? 'auto') !== 'none')
            ->count();
        $userIds = $insights
            ->pluck('user_id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
        $devices = $deviceTableExists
            ? AtlasMobileDevice::query()
                ->when($userIds !== [], fn ($query) => $query->whereIn('user_id', $userIds))
                ->get()
            : collect();
        $activeDevices = $devices->whereNull('revoked_at');
        $pushTokenDevices = $activeDevices->filter(fn (AtlasMobileDevice $device): bool => is_string($device->expo_push_token) && trim($device->expo_push_token) !== '');
        $grantedDevices = $pushTokenDevices->where('notification_permissions', 'granted');
        $permissionDeniedDevices = $activeDevices->where('notification_permissions', 'denied');
        $blockedReason = null;
        if (! $mobileEnabled) {
            $blockedReason = 'atlas_mobile_disabled';
        } elseif (! $deviceTableExists) {
            $blockedReason = 'mobile_device_table_missing';
        } elseif ($activePushRequestedInsightCount > 0 && $pushTokenDevices->isEmpty()) {
            $blockedReason = 'no_registered_push_token';
        } elseif ($activePushRequestedInsightCount > 0 && $grantedDevices->isEmpty() && $permissionDeniedDevices->isNotEmpty()) {
            $blockedReason = 'notification_permission_denied';
        }

        return [
            'schema_version' => 'atlas.proactive.mobile_push_configuration.v1',
            'mobile_enabled' => $mobileEnabled,
            'push_dispatch_enabled' => $mobileEnabled,
            'push_dispatch_blocked_reason' => $blockedReason,
            'push_requested_insight_count' => $pushRequestedInsightCount,
            'active_push_requested_insight_count' => $activePushRequestedInsightCount,
            'pending_dispatch_commands' => [
                'dry_run' => 'php artisan atlas:cli:mobile replay-push --json',
                'apply' => "php artisan atlas:cli:mobile replay-push --apply --confirm-external-dispatch --reason='<operator evidence summary>' --json",
            ],
            'pending_dispatch_apply_contract' => [
                'schema_version' => 'atlas.proactive.pending_push_apply_contract.v1',
                'prior_dry_run_required' => true,
                'prior_dry_run_max_age_minutes' => 15,
                'prior_dry_run_candidate_required' => true,
                'confirm_external_dispatch_required' => true,
                'operator_reason_required' => true,
                'external_notification_possible' => true,
                'receipt_event_type' => 'mobile.push_replay.requested',
            ],
            'delivery_diagnostics' => [
                'schema_version' => 'atlas.proactive.push_delivery_diagnostics.v1',
                'device_table_available' => $deviceTableExists,
                'active_device_count' => $activeDevices->count(),
                'revoked_device_count' => $devices->whereNotNull('revoked_at')->count(),
                'push_token_device_count' => $pushTokenDevices->count(),
                'granted_push_device_count' => $grantedDevices->count(),
                'permission_denied_device_count' => $permissionDeniedDevices->count(),
                'permission_unknown_device_count' => $activeDevices->where('notification_permissions', 'unknown')->count(),
                'raw_push_token_exposed' => false,
                'raw_device_id_exposed' => false,
            ],
            'operator_action' => match ($blockedReason) {
                'atlas_mobile_disabled' => 'set ATLAS_MOBILE_ENABLED=true and restart the Laravel process before expecting push delivery',
                'mobile_device_table_missing' => 'run mobile gateway migrations before expecting push delivery',
                'no_registered_push_token' => 'open Mobile Pairing and register push for this device',
                'notification_permission_denied' => 'enable notifications for Atlas in iOS settings and register push again',
                default => null,
            },
        ];
    }

    /**
     * @param  Collection<int,AiInboxItem>  $insights
     * @return Collection<int,AiInboxItem>
     */
    private function activeInsights(Collection $insights): Collection
    {
        return $insights
            ->filter(fn (AiInboxItem $item): bool => ! in_array($item->status, ['resolved', 'dismissed', 'expired'], true))
            ->values();
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
                'review_critical' => 'php artisan atlas:cli:inbox review-critical',
                'review_critical_json' => 'php artisan atlas:cli:inbox review-critical --json',
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
