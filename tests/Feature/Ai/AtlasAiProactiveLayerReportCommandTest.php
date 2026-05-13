<?php

namespace Tests\Feature\Ai;

use App\Models\AiInboxItem;
use App\Models\AtlasInitiativeRun;
use App\Models\AtlasMobileDevice;
use App\Models\MobilePushDelivery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAiProactiveLayerReportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_command_reports_proactive_layer_runs_insights_and_push_deliveries(): void
    {
        config()->set('atlas.mobile.enabled', true);
        AtlasMobileDevice::query()->create([
            'user_id' => 'vitor',
            'device_label' => 'iPhone',
            'platform' => 'ios',
            'expo_push_token' => 'ExponentPushToken[test]',
            'push_token_hash' => 'push-token-hash',
            'device_token_hash' => 'device-token-hash',
            'notification_permissions' => 'granted',
            'last_seen_at' => now(),
            'paired_at' => now(),
            'metadata' => [],
        ]);

        $run = AtlasInitiativeRun::query()->create([
            'kind' => 'insight_watch',
            'status' => 'succeeded',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
            'scope' => ['dry_run' => false],
            'findings' => [
                ['dedupe_key' => 'insight:health:test', 'confidence' => 0.82],
                ['dedupe_key' => 'insight:digital:test', 'confidence' => 0.74],
            ],
            'emitted_inbox_item_ids' => [],
            'metadata' => ['watcher' => 'insight_watcher_v1'],
        ]);
        $item = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'saude',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Readiness caiu',
            'source_type' => 'atlas_initiative_run',
            'source_id' => $run->id,
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:health:test',
            'available_actions' => [['id' => 'discuss']],
            'payload' => ['insight_kind' => 'health_readiness_drop'],
            'push_policy' => ['send' => 'immediate'],
            'priority_score' => 75,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $run->update(['emitted_inbox_item_ids' => [$item->id]]);
        MobilePushDelivery::query()->create([
            'inbox_item_id' => $item->id,
            'status' => 'sent',
            'provider' => 'expo',
            'request_payload' => ['title' => 'Readiness caiu'],
            'response_payload' => ['status' => 'ok'],
            'attempted_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:proactive-layer-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('atlas.proactive_layer_report.v1', data_get($payload, 'proactive_layer.schema_version'));
        $this->assertSame('ok', data_get($payload, 'proactive_layer.status'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.run_count'));
        $this->assertSame(2, data_get($payload, 'proactive_layer.candidate_count'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.emitted_inbox_item_count'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.insight_item_count'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.push_requested_insight_count'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.push_delivery_count'));
        $this->assertSame(['warning' => 1], data_get($payload, 'proactive_layer.severity_counts'));
        $this->assertSame(['sent' => 1], data_get($payload, 'proactive_layer.push_status_counts'));
        $this->assertTrue(data_get($payload, 'proactive_layer.mobile_push_configuration.mobile_enabled'));
        $this->assertTrue(data_get($payload, 'proactive_layer.mobile_push_configuration.push_dispatch_enabled'));
        $this->assertNull(data_get($payload, 'proactive_layer.mobile_push_configuration.push_dispatch_blocked_reason'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.mobile_push_configuration.delivery_diagnostics.active_device_count'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.mobile_push_configuration.delivery_diagnostics.push_token_device_count'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.mobile_push_configuration.delivery_diagnostics.granted_push_device_count'));
        $this->assertFalse(data_get($payload, 'proactive_layer.mobile_push_configuration.delivery_diagnostics.raw_push_token_exposed'));
        $this->assertSame('atlas.proactive_layer.report_safety.v1', data_get($payload, 'proactive_layer.safety.schema_version'));
        $this->assertTrue(data_get($payload, 'proactive_layer.safety.read_model_only'));
        $this->assertFalse(data_get($payload, 'proactive_layer.safety.writes'));
        $this->assertTrue(data_get($payload, 'proactive_layer.safety.push_dispatch_enabled'));
        $this->assertTrue(data_get($payload, 'proactive_layer.safety.push_pointer_only'));
        $this->assertTrue(data_get($payload, 'proactive_layer.safety.authenticated_fetch_required'));
        $this->assertFalse(data_get($payload, 'proactive_layer.safety.raw_context_exposed_in_push'));
        $this->assertFalse(data_get($payload, 'proactive_layer.safety.raw_payload_exposed_in_push'));
        $this->assertFalse(data_get($payload, 'proactive_layer.safety.body_exposed_in_push'));
        $this->assertFalse(data_get($payload, 'proactive_layer.safety.raw_device_id_persisted_in_audit'));
        $this->assertFalse(data_get($payload, 'proactive_layer.safety.agent_auto_resolve_allowed'));
        $this->assertFalse(data_get($payload, 'proactive_layer.safety.agent_auto_dismiss_allowed'));
        $this->assertFalse(data_get($payload, 'proactive_layer.writes'));
        $this->assertSame('continue_proactive_layer_monitoring', data_get($payload, 'proactive_layer.review_signal.recommended_action'));
    }

    public function test_command_warns_when_push_is_requested_but_mobile_push_is_disabled(): void
    {
        config()->set('atlas.mobile.enabled', false);

        AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Atlas warning',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:push-disabled:test',
            'available_actions' => [['id' => 'discuss']],
            'payload' => ['insight_kind' => 'atlas_ai_telemetry_health'],
            'push_policy' => ['send' => 'immediate'],
            'priority_score' => 85,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:proactive-layer-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'proactive_layer.status'));
        $this->assertSame('enable_mobile_push_or_accept_inbox_only_delivery', data_get($payload, 'proactive_layer.review_signal.recommended_action'));
        $this->assertContains('mobile_push_dispatch_disabled', data_get($payload, 'proactive_layer.review_signal.reasons'));
        $this->assertSame('atlas_mobile_disabled', data_get($payload, 'proactive_layer.mobile_push_configuration.push_dispatch_blocked_reason'));
        $this->assertFalse(data_get($payload, 'proactive_layer.safety.push_dispatch_enabled'));
    }

    public function test_command_explains_push_requested_but_no_device_token_registered(): void
    {
        config()->set('atlas.mobile.enabled', true);

        AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Atlas warning sem push token',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:no-push-token:test',
            'available_actions' => [['id' => 'discuss']],
            'payload' => ['insight_kind' => 'atlas_ai_telemetry_health'],
            'push_policy' => ['send' => 'immediate'],
            'priority_score' => 85,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:proactive-layer-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'proactive_layer.status'));
        $this->assertSame('no_registered_push_token', data_get($payload, 'proactive_layer.mobile_push_configuration.push_dispatch_blocked_reason'));
        $this->assertSame('register_mobile_push_token', data_get($payload, 'proactive_layer.review_signal.recommended_action'));
        $this->assertContains('no_registered_push_token', data_get($payload, 'proactive_layer.review_signal.reasons'));
        $this->assertSame(0, data_get($payload, 'proactive_layer.mobile_push_configuration.delivery_diagnostics.push_token_device_count'));
        $this->assertFalse(data_get($payload, 'proactive_layer.mobile_push_configuration.delivery_diagnostics.raw_device_id_exposed'));
    }

    public function test_command_warns_when_insight_watch_runs_failed(): void
    {
        AtlasInitiativeRun::query()->create([
            'kind' => 'insight_watch',
            'status' => 'failed',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
            'scope' => ['dry_run' => false],
            'findings' => [],
            'emitted_inbox_item_ids' => [],
            'error_message' => 'provider health watcher failed',
            'metadata' => ['watcher' => 'insight_watcher_v1'],
        ]);

        $exit = Artisan::call('atlas:ai:proactive-layer-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'proactive_layer.status'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.failed_run_count'));
        $this->assertSame('inspect_failed_insight_watch_runs', data_get($payload, 'proactive_layer.review_signal.recommended_action'));
    }

    public function test_command_warns_when_critical_proactive_insights_are_active(): void
    {
        AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'critical',
            'status' => 'unread',
            'title' => 'Atlas precisa de revisao',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:critical:test',
            'available_actions' => [['id' => 'discuss']],
            'payload' => ['insight_kind' => 'atlas_ai_telemetry_health'],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 95,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:proactive-layer-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('warning', data_get($payload, 'proactive_layer.status'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.critical_insight_item_count'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.active_critical_insight_item_count'));
        $this->assertSame('review_critical_proactive_insights', data_get($payload, 'proactive_layer.review_signal.recommended_action'));
        $this->assertSame('atlas.proactive.critical_review_contract.v1', data_get($payload, 'proactive_layer.critical_review_contract.schema_version'));
        $this->assertSame('human_review_required', data_get($payload, 'proactive_layer.critical_review_contract.status'));
        $this->assertTrue(data_get($payload, 'proactive_layer.critical_review_contract.operator_required'));
        $this->assertFalse(data_get($payload, 'proactive_layer.critical_review_contract.agent_resolution_allowed'));
        $this->assertContains('agent_auto_dismiss_critical_insight', data_get($payload, 'proactive_layer.critical_review_contract.prohibited_actions'));
        $this->assertSame('insight:critical:test', data_get($payload, 'proactive_layer.critical_review_contract.items.0.dedupe_key'));
        $this->assertSame('atlas.proactive.operator_review_plan.v1', data_get($payload, 'proactive_layer.critical_review_contract.operator_review_plan.schema_version'));
        $this->assertSame('pending_operator_review', data_get($payload, 'proactive_layer.critical_review_contract.operator_review_plan.status'));
        $this->assertSame('php artisan atlas:cli:inbox review-critical', data_get($payload, 'proactive_layer.critical_review_contract.operator_review_plan.commands.review_critical'));
        $this->assertSame('php artisan atlas:cli:inbox review-critical --json', data_get($payload, 'proactive_layer.critical_review_contract.operator_review_plan.commands.review_critical_json'));
        $this->assertSame(
            "php artisan atlas:cli:mobile replay-push --apply --confirm-external-dispatch --reason='<operator evidence summary>' --json",
            data_get($payload, 'proactive_layer.mobile_push_configuration.pending_dispatch_commands.apply'),
        );
        $this->assertTrue(data_get($payload, 'proactive_layer.mobile_push_configuration.pending_dispatch_apply_contract.prior_dry_run_required'));
        $this->assertTrue(data_get($payload, 'proactive_layer.mobile_push_configuration.pending_dispatch_apply_contract.confirm_external_dispatch_required'));
        $this->assertTrue(data_get($payload, 'proactive_layer.mobile_push_configuration.pending_dispatch_apply_contract.operator_reason_required'));
        $this->assertTrue(data_get($payload, 'proactive_layer.critical_review_contract.operator_review_plan.rules.operator_required'));
        $this->assertFalse(data_get($payload, 'proactive_layer.critical_review_contract.operator_review_plan.rules.agent_auto_dismiss_allowed'));
        $this->assertStringContainsString('atlas:cli:inbox show', data_get($payload, 'proactive_layer.critical_review_contract.operator_review_plan.item_commands.0.show'));
        $this->assertStringContainsString('--snoozed-until', data_get($payload, 'proactive_layer.critical_review_contract.operator_review_plan.item_commands.0.snooze_with_reason'));
    }

    public function test_cli_review_critical_prints_human_presentation_without_resolving_items(): void
    {
        $item = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'critical',
            'status' => 'unread',
            'title' => 'Telemetry health critical',
            'summary' => 'Telemetry health critical; score 20/100.',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:critical:human-review:test',
            'available_actions' => [['id' => 'discuss'], ['id' => 'mark_read']],
            'payload' => [
                'health' => [
                    'status' => 'critical',
                    'health_score' => 20,
                    'sample' => ['trace_count' => 12, 'confidence' => 'normal'],
                    'issues' => [
                        [
                            'key' => 'final_quality_avg',
                            'severity' => 'critical',
                            'value' => 42,
                            'threshold' => 70,
                            'summary' => 'Quality is too low.',
                        ],
                    ],
                    'actions' => ['Open recent low-score traces and compare context.'],
                ],
            ],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 95,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'critical',
            'status' => 'unread',
            'title' => 'Older active critical',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:critical:older-active:test',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 90,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'critical',
            'status' => 'resolved',
            'title' => 'Already reviewed',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:critical:already-reviewed:test',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 50,
            'resolved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('atlas:cli:inbox', [
            'action' => 'review-critical',
            '--limit' => 1,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.inbox.critical_review.v1', data_get($payload, 'critical_review.schema_version'));
        $this->assertSame('human_review_required', data_get($payload, 'critical_review.status'));
        $this->assertSame(2, data_get($payload, 'critical_review.active_critical_count'));
        $this->assertSame(1, data_get($payload, 'critical_review.returned_item_count'));
        $this->assertSame('atlas.inbox.critical_review_summary.v1', data_get($payload, 'critical_review.review_summary.schema_version'));
        $this->assertSame('returned_items', data_get($payload, 'critical_review.review_summary.scope'));
        $this->assertSame(2, data_get($payload, 'critical_review.review_summary.active_critical_count'));
        $this->assertSame(1, data_get($payload, 'critical_review.review_summary.unread_count'));
        $this->assertSame(0, data_get($payload, 'critical_review.review_summary.read_count'));
        $this->assertSame(1, data_get($payload, 'critical_review.review_summary.health_signal_count'));
        $this->assertSame(0, data_get($payload, 'critical_review.review_summary.performance_report_count'));
        $this->assertSame(1, data_get($payload, 'critical_review.review_summary.still_requires_operator_decision_count'));
        $this->assertFalse(data_get($payload, 'critical_review.review_summary.safety.agent_auto_resolve_allowed'));
        $this->assertContains('review_unread_health_critical_first', data_get($payload, 'critical_review.review_summary.priority_order'));
        $this->assertSame($item->id, data_get($payload, 'critical_review.items.0.id'));
        $this->assertSame('health', data_get($payload, 'critical_review.items.0.review_kind'));
        $this->assertSame('Atlas AI em estado critico - saude 20/100', data_get($payload, 'critical_review.items.0.headline'));
        $this->assertSame('Saude', data_get($payload, 'critical_review.items.0.primary_metric.label'));
        $this->assertStringContainsString('atlas:cli:inbox show', data_get($payload, 'critical_review.items.0.commands.show'));
        $this->assertFalse(data_get($payload, 'critical_review.agent_auto_resolve_allowed'));
        $this->assertFalse(data_get($payload, 'critical_review.agent_auto_dismiss_allowed'));
        $this->assertFalse(data_get($payload, 'critical_review.raw_payload_exposed'));
        $this->assertSame('unread', $item->refresh()->status);
    }

    public function test_command_does_not_warn_for_resolved_critical_insights(): void
    {
        AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'critical',
            'status' => 'resolved',
            'title' => 'Atlas ja revisado',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:critical:resolved:test',
            'available_actions' => [['id' => 'discuss']],
            'payload' => ['insight_kind' => 'atlas_ai_telemetry_health'],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 95,
            'resolved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:proactive-layer-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', data_get($payload, 'proactive_layer.status'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.critical_insight_item_count'));
        $this->assertSame(0, data_get($payload, 'proactive_layer.active_critical_insight_item_count'));
        $this->assertSame('clear', data_get($payload, 'proactive_layer.critical_review_contract.status'));
        $this->assertSame('continue_proactive_layer_monitoring', data_get($payload, 'proactive_layer.review_signal.recommended_action'));
    }

    public function test_command_does_not_warn_for_dismissed_push_requested_insights(): void
    {
        AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas_ai_performance',
            'severity' => 'critical',
            'status' => 'dismissed',
            'title' => 'Atlas stale development telemetry',
            'initiator' => 'atlas',
            'dedupe_key' => 'atlas-ai-performance:daily:dismissed-push:test',
            'available_actions' => [['id' => 'discuss']],
            'payload' => ['insight_kind' => 'atlas_ai_daily_performance'],
            'push_policy' => ['send' => 'immediate'],
            'priority_score' => 95,
            'dismissed_at' => now(),
            'resolved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('atlas:ai:proactive-layer-report', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ok', data_get($payload, 'proactive_layer.status'));
        $this->assertSame(1, data_get($payload, 'proactive_layer.push_requested_insight_count'));
        $this->assertSame(0, data_get($payload, 'proactive_layer.active_push_requested_insight_count'));
        $this->assertSame(0, data_get($payload, 'proactive_layer.mobile_push_configuration.active_push_requested_insight_count'));
        $this->assertSame('clear', data_get($payload, 'proactive_layer.critical_review_contract.status'));
        $this->assertNotContains('push_requested_without_delivery_attempt', data_get($payload, 'proactive_layer.review_signal.reasons'));
        $this->assertSame('continue_proactive_layer_monitoring', data_get($payload, 'proactive_layer.review_signal.recommended_action'));
    }

    private function createTables(): void
    {
        Schema::create('atlas_initiative_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind', 48);
            $table->string('status', 24)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('scope')->default('{}');
            $table->json('findings')->default('[]');
            $table->json('emitted_inbox_item_ids')->default('[]');
            $table->text('error_message')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
        Schema::create('ai_inbox_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor')->index();
            $table->string('type', 40);
            $table->string('category', 40)->nullable();
            $table->string('severity', 16)->default('info');
            $table->string('status', 24)->default('unread');
            $table->string('title', 180);
            $table->text('summary')->nullable();
            $table->text('body')->nullable();
            $table->string('source_type', 64)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('initiator', 32)->default('system');
            $table->uuid('context_bundle_id')->nullable();
            $table->string('dedupe_key', 160)->nullable();
            $table->json('available_actions')->default('[]');
            $table->json('response')->nullable();
            $table->json('payload')->default('{}');
            $table->text('deep_link')->nullable();
            $table->json('push_policy')->default('{}');
            $table->smallInteger('priority_score')->default(50);
            $table->decimal('confidence_score', 4, 3)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('atlas_mobile_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor')->index();
            $table->string('device_label', 80);
            $table->string('platform', 16);
            $table->string('app_version', 32)->nullable();
            $table->string('os_version', 64)->nullable();
            $table->text('expo_push_token')->nullable();
            $table->string('push_token_hash', 128)->nullable()->index();
            $table->string('device_token_hash', 128)->unique();
            $table->string('notification_permissions', 32)->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('paired_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
        Schema::create('mobile_push_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inbox_item_id')->index();
            $table->uuid('device_id')->nullable()->index();
            $table->string('status', 24);
            $table->string('provider', 24)->default('expo');
            $table->text('provider_ticket_id')->nullable();
            $table->text('provider_receipt_id')->nullable();
            $table->json('request_payload')->default('{}');
            $table->json('response_payload')->default('{}');
            $table->text('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('attempted_at')->useCurrent();
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('mobile_push_deliveries');
        Schema::dropIfExists('atlas_mobile_devices');
        Schema::dropIfExists('ai_inbox_items');
        Schema::dropIfExists('atlas_initiative_runs');
    }
}
