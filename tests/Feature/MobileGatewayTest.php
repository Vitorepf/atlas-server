<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateMobileDevice;
use App\Jobs\SendMobilePushJob;
use App\Models\AiInboxItem;
use App\Models\AiJob;
use App\Models\AiPerformanceRecommendation;
use App\Models\AiQualityEvaluation;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasInitiativeRun;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasMobileDevice;
use App\Models\AuditEvent;
use App\Models\HealthSnapshot;
use App\Models\MobilePairingCode;
use App\Models\MobilePushDelivery;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOperatorReviewMobilePublisher;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Mobile\AutoImprovementProposalScanner;
use App\Services\Ai\Mobile\ContextBundleService;
use App\Services\Ai\Mobile\DiscussionBootstrapper;
use App\Services\Ai\Mobile\ExpoCircuitBreaker;
use App\Services\Ai\Mobile\InboxActionRegistry;
use App\Services\Ai\Mobile\InsightInboxEmitter;
use App\Services\Ai\Mobile\InsightWatcherService;
use App\Services\Ai\Mobile\JobResultInboxEmitter;
use App\Services\Ai\Mobile\MobilePairingService;
use App\Services\Ai\Mobile\MobilePushService;
use App\Services\Ai\Mobile\MobileReliabilityMonitor;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\Mobile\SelfDiagnosticEmitter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MobileGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');
        config()->set('atlas.mobile.enabled', false);
        config()->set('atlas.ai_metrics.performance_report_emit', false);
        Cache::flush();
        app(ExpoCircuitBreaker::class)->reset();
        $this->createMobileTables();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->dropMobileTables();

        parent::tearDown();
    }

    public function test_pairing_confirms_device_and_mobile_bearer_authenticates_requests(): void
    {
        $init = $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/v1/mobile/pairing/initiate', ['device_label' => 'iPhone Test'])
            ->assertOk()
            ->assertJsonStructure(['code', 'expires_at', 'pairing_id'])
            ->json();

        $confirm = $this
            ->postJson('/v1/mobile/pairing/confirm', [
                'code' => $init['code'],
                'platform' => 'ios',
                'device_label' => 'iPhone Test',
                'expo_push_token' => 'ExponentPushToken[test]',
                'notification_permissions' => 'granted',
            ])
            ->assertOk()
            ->assertJsonPath('device.device_label', 'iPhone Test')
            ->assertJsonPath('device.platform', 'ios')
            ->json();

        $token = $confirm['device_token'];
        $deviceId = $confirm['device']['id'];

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/devices')
            ->assertOk()
            ->assertJsonPath('current_device_id', $deviceId)
            ->assertJsonPath('current_device.id', $deviceId)
            ->assertJsonCount(1, 'devices');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/v1/mobile/devices/'.$deviceId)
            ->assertOk()
            ->assertJsonPath('device.revoked_at', fn (?string $value): bool => is_string($value));

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/devices')
            ->assertUnauthorized();
    }

    public function test_mobile_device_notification_preferences_can_be_updated(): void
    {
        $token = $this->pairedDeviceToken('ExponentPushToken[test]');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/devices/notification-preferences', [
                'critical_push_enabled' => false,
                'daily_report_push_enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('device.notification_preferences.critical_push_enabled', false)
            ->assertJsonPath('device.notification_preferences.telemetry_health_push_enabled', true)
            ->assertJsonPath('device.notification_preferences.daily_report_push_enabled', false)
            ->assertJsonPath('device.notification_preferences.quiet_hours_enabled', false)
            ->assertJsonPath('device.notification_preferences.proactive_push_enabled', true)
            ->assertJsonPath('device.notification_preferences.manual_eclipse_enabled', false);

        $device = AtlasMobileDevice::query()->firstOrFail();
        $this->assertFalse(data_get($device->metadata, 'notification_preferences.critical_push_enabled'));
        $this->assertFalse(data_get($device->metadata, 'notification_preferences.daily_report_push_enabled'));
    }

    public function test_mobile_bearer_recovers_from_stale_serialized_device_cache(): void
    {
        $token = $this->pairedDeviceToken();
        $hash = app(MobilePairingService::class)->hashSecret($token);
        $cacheKey = AuthenticateMobileDevice::deviceCacheKey($hash);

        Cache::put($cacheKey, unserialize('O:19:"MissingMobileDevice":0:{}'), now()->addMinute());

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/devices')
            ->assertOk()
            ->assertJsonCount(1, 'devices');

        $this->assertSame(AtlasMobileDevice::query()->firstOrFail()->id, Cache::get($cacheKey));
    }

    public function test_pairing_tracks_invalid_attempts_and_locks_code_when_pairing_id_is_provided(): void
    {
        $init = $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/v1/mobile/pairing/initiate', ['device_label' => 'iPhone Test'])
            ->assertOk()
            ->json();

        for ($i = 1; $i <= 5; $i++) {
            $this
                ->postJson('/v1/mobile/pairing/confirm', [
                    'pairing_id' => $init['pairing_id'],
                    'code' => 'WRONG-'.$i,
                    'platform' => 'ios',
                    'device_label' => 'iPhone Test',
                ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.code.0', 'Codigo de pareamento invalido.');
        }

        $pairing = MobilePairingCode::query()->findOrFail($init['pairing_id']);
        $this->assertSame(5, $pairing->attempts);
        $this->assertTrue($pairing->locked_until?->isFuture());

        $this
            ->postJson('/v1/mobile/pairing/confirm', [
                'pairing_id' => $init['pairing_id'],
                'code' => $init['code'],
                'platform' => 'ios',
                'device_label' => 'iPhone Test',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Codigo temporariamente bloqueado por tentativas invalidas.');

        $this->assertDatabaseCount('atlas_mobile_devices', 0);
    }

    public function test_pairing_expired_code_fails_closed(): void
    {
        $init = $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/v1/mobile/pairing/initiate', ['device_label' => 'iPhone Test'])
            ->assertOk()
            ->json();

        MobilePairingCode::query()
            ->whereKey($init['pairing_id'])
            ->update(['expires_at' => now()->subMinute()]);

        $this
            ->postJson('/v1/mobile/pairing/confirm', [
                'pairing_id' => $init['pairing_id'],
                'code' => $init['code'],
                'platform' => 'ios',
                'device_label' => 'iPhone Test',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Codigo de pareamento expirado.');

        $this->assertDatabaseCount('atlas_mobile_devices', 0);
    }

    public function test_mobile_cli_pair_outputs_pairing_id_for_manual_flow(): void
    {
        $exitCode = Artisan::call('atlas:cli:mobile', [
            'action' => 'pair',
            '--label' => 'iPhone Test',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Codigo de pareamento Atlas Mobile', $output);
        $this->assertStringContainsString('Pairing ID:', $output);
        $this->assertDatabaseCount('mobile_pairing_codes', 1);
    }

    public function test_pairing_initiate_is_rate_limited(): void
    {
        config()->set('atlas.mobile.rate_limits.pairing_initiate_max_attempts', 2);
        config()->set('atlas.mobile.rate_limits.pairing_initiate_decay_seconds', 60);
        $userId = 'rate-limit-'.Str::uuid();

        for ($i = 1; $i <= 2; $i++) {
            $this
                ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
                ->postJson('/v1/mobile/pairing/initiate', [
                    'device_label' => 'iPhone '.$i,
                    'user_id' => $userId,
                ])
                ->assertOk();
        }

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/v1/mobile/pairing/initiate', [
                'device_label' => 'iPhone blocked',
                'user_id' => $userId,
            ])
            ->assertStatus(429)
            ->assertJsonPath('bucket', 'pairing_initiate')
            ->assertJsonPath('message', 'Muitas tentativas. Aguarde antes de tentar novamente.');
    }

    public function test_pairing_confirm_is_rate_limited_before_attempt_lock(): void
    {
        config()->set('atlas.mobile.rate_limits.pairing_confirm_max_attempts', 2);
        config()->set('atlas.mobile.rate_limits.pairing_confirm_decay_seconds', 60);
        $init = $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/v1/mobile/pairing/initiate', ['device_label' => 'iPhone Test'])
            ->assertOk()
            ->json();

        for ($i = 1; $i <= 2; $i++) {
            $this
                ->postJson('/v1/mobile/pairing/confirm', [
                    'pairing_id' => $init['pairing_id'],
                    'code' => 'WRONG-'.$i,
                    'platform' => 'ios',
                    'device_label' => 'iPhone Test',
                ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.code.0', 'Codigo de pareamento invalido.');
        }

        $this
            ->postJson('/v1/mobile/pairing/confirm', [
                'pairing_id' => $init['pairing_id'],
                'code' => 'WRONG-3',
                'platform' => 'ios',
                'device_label' => 'iPhone Test',
            ])
            ->assertStatus(429)
            ->assertJsonPath('bucket', 'pairing_confirm');

        $pairing = MobilePairingCode::query()->findOrFail($init['pairing_id']);
        $this->assertSame(2, $pairing->attempts);
        $this->assertNull($pairing->locked_until);
    }

    public function test_mobile_inbox_lists_items_and_discuss_seeds_contextual_thread_idempotently(): void
    {
        $token = $this->pairedDeviceToken();
        $bundle = app(ContextBundleService::class)->create([
            'purpose' => 'insight',
            'title' => 'Metricas do Atlas',
            'summary' => 'Resumo para UI.',
            'body_for_thread' => 'Contexto detalhado para conversar com Atlas.',
            'metric_refs' => [['name' => 'quality_score_7d', 'value' => 71]],
        ]);
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'title' => 'Atlas encontrou uma metrica relevante',
            'summary' => 'Qualidade caiu nos ultimos dias.',
            'body' => 'Explicacao longa para o operador.',
            'initiator' => 'atlas',
            'context_bundle_id' => $bundle->id,
            'dedupe_key' => 'insight:quality:7d',
        ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/inbox?status=unread')
            ->assertOk()
            ->assertJsonPath('items.0.id', $item->id)
            ->assertJsonPath('items.0.type', 'insight')
            ->assertJsonPath('unread_count', 1);

        $first = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Idempotency-Key', 'discuss-test')
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discuss')
            ->assertOk()
            ->assertJsonPath('result.deep_link', fn (string $value): bool => str_starts_with($value, 'atlas://thread/'))
            ->json();

        $second = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Idempotency-Key', 'discuss-test')
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discuss')
            ->assertOk()
            ->json();

        $this->assertSame($first['result']['thread_id'], $second['result']['thread_id']);
        $this->assertDatabaseCount('ai_threads', 1);
        $this->assertDatabaseCount('ai_messages', 2);
        $this->assertSame('read', $item->refresh()->status);
    }

    public function test_mobile_inbox_critical_review_returns_human_triage_without_resolving_items(): void
    {
        $token = $this->pairedDeviceToken();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'critical',
            'status' => 'unread',
            'title' => 'Telemetry health critical',
            'summary' => 'Telemetry health critical; score 20/100.',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:critical:mobile-review:test',
            'available_actions' => [['id' => 'discuss'], ['id' => 'mark_read']],
            'payload' => [
                'health' => [
                    'status' => 'critical',
                    'health_score' => 20,
                    'sample' => ['trace_count' => 12, 'confidence' => 'normal'],
                    'issues' => [[
                        'key' => 'final_quality_avg',
                        'severity' => 'critical',
                        'value' => 42,
                        'threshold' => 70,
                        'summary' => 'Quality is too low.',
                    ]],
                    'actions' => ['Open recent low-score traces and compare context.'],
                ],
            ],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 95,
        ]);

        app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'category' => 'atlas_ai_performance',
            'severity' => 'critical',
            'status' => 'read',
            'title' => 'Performance report',
            'summary' => 'Performance critical.',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:critical:mobile-performance:test',
            'available_actions' => [['id' => 'discuss']],
            'payload' => [
                'report' => [
                    'status' => 'critical',
                    'report_date' => '2026-05-12',
                    'summary' => [
                        'traces' => 16,
                        'quality_avg' => 77.9,
                        'efficiency_avg' => 74.6,
                        'unknown_cost_rate' => 0,
                    ],
                ],
            ],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 90,
        ]);

        AiInboxItem::query()->create([
            'user_id' => 'other-user',
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'critical',
            'status' => 'unread',
            'title' => 'Other user critical',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:critical:other-user:test',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/inbox/critical-review?limit=10')
            ->assertOk()
            ->assertJsonPath('critical_review.schema_version', 'atlas.inbox.critical_review.v1')
            ->assertJsonPath('critical_review.status', 'human_review_required')
            ->assertJsonPath('critical_review.active_critical_count', 2)
            ->assertJsonPath('critical_review.returned_item_count', 2)
            ->assertJsonPath('critical_review.review_summary.schema_version', 'atlas.inbox.critical_review_summary.v1')
            ->assertJsonPath('critical_review.review_summary.unread_count', 1)
            ->assertJsonPath('critical_review.review_summary.read_count', 1)
            ->assertJsonPath('critical_review.review_summary.health_signal_count', 1)
            ->assertJsonPath('critical_review.review_summary.performance_report_count', 1)
            ->assertJsonPath('critical_review.review_summary.still_requires_operator_decision_count', 2)
            ->assertJsonPath('critical_review.review_summary.safety.agent_auto_resolve_allowed', false)
            ->assertJsonPath('critical_review.agent_auto_resolve_allowed', false)
            ->assertJsonPath('critical_review.raw_payload_exposed', false)
            ->assertJsonPath('critical_review.api_contract.schema_version', 'atlas.inbox.critical_review.api_contract.v1')
            ->assertJsonPath('critical_review.api_contract.respond_endpoint_template', 'POST /v1/mobile/inbox/{inbox_item_id}/respond')
            ->assertJsonPath('critical_review.api_contract.receipt_event_type', 'inbox.action.completed')
            ->assertJsonPath('critical_review.api_contract.ledger_schema_version', 'atlas.inbox_action.receipt.v1')
            ->assertJsonPath('critical_review.api_contract.agent_auto_dismiss_allowed', false)
            ->json();

        $reviewedItem = collect(data_get($payload, 'critical_review.items'))->firstWhere('id', $item->id);
        $this->assertSame('health', data_get($reviewedItem, 'review_kind'));
        $this->assertSame('Atlas AI em estado critico - saude 20/100', data_get($reviewedItem, 'headline'));
        $this->assertSame('discuss_in_atlas', data_get($reviewedItem, 'decision_options.0.id'));
        $this->assertSame('discuss', data_get($reviewedItem, 'decision_options.0.action_id'));
        $this->assertTrue(data_get($reviewedItem, 'decision_options.0.recommended'));
        $this->assertFalse(data_get($reviewedItem, 'decision_options.0.allowed_for_agent'));
        $this->assertTrue(data_get($reviewedItem, 'decision_options.3.requires_evidence'));
        $this->assertSame('removes_from_active_critical_gate', data_get($reviewedItem, 'decision_options.3.state_effect'));

        $this->assertSame('unread', $item->refresh()->status);
    }

    public function test_discuss_repairs_stale_idempotent_response_without_thread_id(): void
    {
        $token = $this->pairedDeviceToken();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'title' => 'Discussao antiga sem thread',
            'summary' => 'Resposta idempotente ficou incompleta.',
            'available_actions' => [['id' => 'discuss', 'label' => 'Discutir com Atlas']],
        ]);
        $thread = AiThread::query()->create([
            'title' => 'Discussao antiga sem thread',
            'summary' => 'Resposta idempotente ficou incompleta.',
            'status' => 'active',
            'surface' => 'mobile',
            'source_type' => 'inbox_item',
            'source_id' => $item->id,
            'message_count' => 0,
            'metadata' => [
                'source_type' => 'ai_inbox_item',
                'inbox_item_id' => $item->id,
                'capability_profile' => 'mobile_operational_read',
            ],
        ]);
        $item->forceFill([
            'payload' => [
                ...($item->payload ?? []),
                'discussion_thread_id' => $thread->id,
            ],
            'response' => [
                'action' => 'discuss',
                'idempotency_key' => 'discuss-stale',
                'result' => [],
                'responded_at' => now()->toJSON(),
            ],
        ])->save();

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Idempotency-Key', 'discuss-stale')
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discuss')
            ->assertOk()
            ->assertJsonPath('result.thread_id', $thread->id)
            ->assertJsonPath('result.deep_link', 'atlas://thread/'.$thread->id);

        $this->assertSame($thread->id, data_get($item->refresh()->response, 'result.thread_id'));
        $this->assertDatabaseCount('ai_threads', 1);
    }

    public function test_mobile_recommendation_api_lists_shows_and_transitions_recommendations(): void
    {
        $token = $this->pairedDeviceToken();
        Carbon::setTestNow(Carbon::parse('2026-05-04 07:00:00'));
        $recommendation = AiPerformanceRecommendation::query()->create([
            'user_id' => 'vitor',
            'origin_report_date' => '2026-05-01',
            'kind' => 'quality_drop',
            'target_metric' => 'final_quality_avg',
            'target_dimension' => ['provider' => 'claude_cli'],
            'expected_impact' => ['direction' => 'increase', 'magnitude' => 0.10, 'confidence' => 0.81],
            'state' => 'proposed',
            'state_history' => [[
                'state' => 'proposed',
                'at' => now()->toJSON(),
                'reason' => 'test_seed',
            ]],
            'baseline_snapshot' => ['mean' => 70.0, 'sample_n' => 12],
            'measurement_window_days' => 14,
            'priority_score' => 80,
        ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/recommendations?state=open')
            ->assertOk()
            ->assertJsonPath('items.0.id', $recommendation->id)
            ->assertJsonPath('items.0.state', 'proposed')
            ->assertJsonPath('items.0.target_metric', 'final_quality_avg');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/ai/recommendations/'.$recommendation->id)
            ->assertOk()
            ->assertJsonPath('item.id', $recommendation->id)
            ->assertJsonPath('item.target_dimension.provider', 'claude_cli');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/ai/recommendations/'.$recommendation->id.'/transition', [
                'state' => 'acknowledged',
                'reason' => 'vi no app',
            ])
            ->assertOk()
            ->assertJsonPath('item.state', 'acknowledged');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/ai/recommendations/'.$recommendation->id.'/transition', [
                'state' => 'applied',
                'reason' => 'ajuste aplicado',
            ])
            ->assertOk()
            ->assertJsonPath('item.state', 'applied')
            ->assertJsonPath('item.measurement_due_at', '2026-05-18T07:00:00.000000Z');

        $this->assertSame('applied', $recommendation->refresh()->state);
        Carbon::setTestNow();
    }

    public function test_mobile_inbox_snooze_and_dismiss_keep_recommendation_state_in_sync(): void
    {
        $token = $this->pairedDeviceToken();
        Carbon::setTestNow(Carbon::parse('2026-05-04 07:00:00'));
        $recommendation = AiPerformanceRecommendation::query()->create([
            'user_id' => 'vitor',
            'origin_report_date' => '2026-05-01',
            'kind' => 'quality_drop',
            'target_metric' => 'final_quality_avg',
            'target_dimension' => ['provider' => 'claude_cli'],
            'expected_impact' => ['direction' => 'increase', 'magnitude' => 0.10, 'confidence' => 0.81],
            'state' => 'proposed',
            'state_history' => [[
                'state' => 'proposed',
                'at' => now()->toJSON(),
                'reason' => 'test_seed',
            ]],
            'measurement_window_days' => 14,
            'priority_score' => 80,
        ]);
        $item = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas_ai_recommendation',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Atlas: recomendacao para final_quality_avg',
            'summary' => 'Ajuste recomendado.',
            'source_type' => 'ai_performance_recommendation',
            'source_id' => $recommendation->id,
            'available_actions' => [
                ['id' => 'acknowledge_recommendation', 'label' => 'Reconhecer'],
                ['id' => 'apply_recommendation', 'label' => 'Marcar aplicada'],
                ['id' => 'reject_recommendation', 'label' => 'Rejeitar'],
                ['id' => 'snooze', 'label' => 'Adiar'],
                ['id' => 'dismiss', 'label' => 'Descartar'],
            ],
            'payload' => [
                'recommendation' => [
                    'id' => $recommendation->id,
                    'state' => 'proposed',
                    'target_metric' => 'final_quality_avg',
                ],
            ],
            'push_policy' => ['send' => 'none'],
        ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/snooze', [
                'snoozed_until' => '2026-05-06T07:00:00Z',
                'reason' => 'aguardar janela',
            ])
            ->assertOk()
            ->assertJsonPath('item.status', 'snoozed')
            ->assertJsonPath('item.payload.recommendation.state', 'snoozed');

        $recommendation->refresh();
        $this->assertSame('snoozed', $recommendation->state);
        $this->assertSame('2026-05-06T07:00:00.000000Z', $recommendation->snoozed_until?->toJSON());

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/dismiss', [
                'reason' => 'nao aplicar',
            ])
            ->assertOk()
            ->assertJsonPath('item.status', 'dismissed')
            ->assertJsonPath('item.payload.recommendation.state', 'rejected');

        $recommendation->refresh();
        $this->assertSame('rejected', $recommendation->state);
        $this->assertSame('nao aplicar', $recommendation->closed_reason);
        Carbon::setTestNow();
    }

    public function test_mobile_inbox_supports_active_severity_filter_and_cursor(): void
    {
        $token = $this->pairedDeviceToken();
        $inbox = app(AtlasInboxService::class);

        Carbon::setTestNow(Carbon::parse('2026-04-30 10:00:00'));
        $olderWarning = $inbox->create([
            'type' => 'alert',
            'severity' => 'warning',
            'title' => 'Alerta antigo',
            'summary' => 'Primeiro alerta ativo.',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-30 11:00:00'));
        $inbox->create([
            'type' => 'insight',
            'severity' => 'info',
            'title' => 'Insight informativo',
            'summary' => 'Nao deve aparecer no filtro warning.',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-30 12:00:00'));
        $newerWarning = $inbox->create([
            'type' => 'job_result',
            'severity' => 'warning',
            'title' => 'Job com warning',
            'summary' => 'Segundo alerta ativo.',
        ]);
        Carbon::setTestNow();

        $first = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/inbox?status=active&severity=warning&limit=1')
            ->assertOk()
            ->assertJsonPath('items.0.id', $newerWarning->id)
            ->assertJsonPath('items.0.severity', 'warning')
            ->assertJsonPath('unread_count', 3)
            ->json();

        $this->assertIsString($first['next_cursor']);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/inbox?status=active&severity=warning&limit=1&cursor='.urlencode($first['next_cursor']))
            ->assertOk()
            ->assertJsonPath('items.0.id', $olderWarning->id)
            ->assertJsonPath('next_cursor', null);
    }

    public function test_mobile_inbox_snooze_endpoint_hides_item_from_active_until_due(): void
    {
        $token = $this->pairedDeviceToken();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'title' => 'Insight para adiar',
            'summary' => 'Pode ser revisado depois.',
        ]);
        $until = now()->addDays(7)->toJSON();

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/snooze', [
                'snoozed_until' => $until,
                'reason' => 'Adiado pelo app.',
            ])
            ->assertOk()
            ->assertJsonPath('item.status', 'snoozed')
            ->assertJsonPath('item.snoozed_until', fn (string $value): bool => Carbon::parse($value)->isFuture());

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/inbox?status=active')
            ->assertOk()
            ->assertJsonPath('items', []);
    }

    public function test_dismiss_and_snooze_endpoints_are_idempotent_with_header(): void
    {
        $token = $this->pairedDeviceToken();
        $dismissed = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'title' => 'Insight para dismiss idempotente',
        ]);

        for ($i = 1; $i <= 2; $i++) {
            $this
                ->withHeader('Authorization', 'Bearer '.$token)
                ->withHeader('Idempotency-Key', 'dismiss-idempotent-test')
                ->postJson('/v1/mobile/inbox/'.$dismissed->id.'/dismiss', [
                    'reason' => 'sem acao agora',
                ])
                ->assertOk()
                ->assertJsonPath('item.status', 'dismissed');
        }

        $dismissed->refresh();
        $this->assertSame('dismiss-idempotent-test', $dismissed->response['idempotency_key'] ?? null);
        $this->assertSame('dismiss', $dismissed->response['action'] ?? null);

        $snoozed = app(AtlasInboxService::class)->create([
            'type' => 'self_diagnostic',
            'title' => 'Diagnostico para snooze idempotente',
        ]);
        $until = now()->addDays(2)->toJSON();

        for ($i = 1; $i <= 2; $i++) {
            $this
                ->withHeader('Authorization', 'Bearer '.$token)
                ->withHeader('Idempotency-Key', 'snooze-idempotent-test')
                ->postJson('/v1/mobile/inbox/'.$snoozed->id.'/snooze', [
                    'snoozed_until' => $until,
                    'reason' => 'revisar depois',
                ])
                ->assertOk()
                ->assertJsonPath('item.status', 'snoozed');
        }

        $snoozed->refresh();
        $this->assertSame('snooze-idempotent-test', $snoozed->response['idempotency_key'] ?? null);
        $this->assertSame('snooze', $snoozed->response['action'] ?? null);
    }

    public function test_mobile_thread_reply_uses_device_bearer_and_preserves_context(): void
    {
        $token = $this->pairedDeviceToken();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'title' => 'Discutir queda de qualidade',
            'summary' => 'Contexto pronto.',
            'available_actions' => [['id' => 'discuss', 'label' => 'Discutir com Atlas']],
        ]);
        $threadId = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discuss')
            ->json('result.thread_id');
        $clientId = (string) Str::uuid();

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($threadId, $clientId): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->with('Resposta pelo app', \Mockery::on(function (array $options) use ($threadId, $clientId): bool {
                    return ($options['client_id'] ?? null) === $clientId
                        && ($options['thread_id'] ?? null) === $threadId
                        && ($options['new_thread'] ?? true) === false
                        && ($options['source_type'] ?? null) === 'app'
                        && data_get($options, 'payload.app_surface') === 'mobile_thread'
                        && data_get($options, 'payload.thread_source') === 'mobile_gateway_inbox'
                        && data_get($options, 'payload.atlas_focus') === 'operational'
                        && data_get($options, 'payload.capability_profile') === 'mobile_operational_read'
                        && is_string(data_get($options, 'payload.mobile_device_id'));
                }))
                ->andReturn(tap(new AiTrace, fn (AiTrace $trace) => $trace->forceFill([
                    'id' => (string) Str::uuid(),
                    'trace_key' => 'trace_mobile_test',
                    'thread_id' => $threadId,
                    'status' => 'queued',
                    'operator_input' => 'Resposta pelo app',
                    'agent_slug' => 'orquestrador',
                    'provider' => 'claude_cli',
                    'skill_versions' => [],
                    'context_refs' => [],
                    'metadata' => ['client_id' => $clientId],
                    'created_at' => now(),
                    'updated_at' => now(),
                ])));
        });

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/threads/'.$threadId.'/reply', [
                'input_text' => 'Resposta pelo app',
                'client_id' => $clientId,
                'payload' => ['custom' => 'kept'],
            ])
            ->assertAccepted()
            ->assertJsonPath('trace.thread_id', $threadId)
            ->assertJsonPath('thread.id', $threadId);
    }

    public function test_discuss_creates_atlas_ai_operational_thread_contract(): void
    {
        $token = $this->pairedDeviceToken();
        $bundle = app(ContextBundleService::class)->create([
            'purpose' => 'telemetry_health',
            'title' => 'Contexto de revisao operacional',
            'summary' => 'Resumo seguro para a conversa.',
            'body_for_thread' => 'Scorecard, traces, custo, latencia e continuidade devem ser revisados antes de alterar prompts ou automacoes.',
            'source_refs' => [['type' => 'report', 'id' => 'daily']],
            'trace_refs' => [['id' => (string) Str::uuid()]],
            'metric_refs' => [['name' => 'final_quality_avg', 'value' => 48.67]],
            'file_refs' => [['path' => 'docs/atlas-ai-performance-reports.md']],
        ]);
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'category' => 'telemetry_health',
            'title' => 'Saude do Atlas precisa de revisao',
            'summary' => 'Contexto operacional pronto.',
            'context_bundle_id' => $bundle->id,
        ]);

        $threadId = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discuss')
            ->assertOk()
            ->json('result.thread_id');

        $thread = AiThread::query()->findOrFail($threadId);
        $this->assertSame('mobile', $thread->surface);
        $this->assertSame('inbox_item', $thread->source_type);
        $this->assertSame($item->id, $thread->source_id);
        $this->assertSame('operational', data_get($thread->metadata, 'atlas_focus'));
        $this->assertSame('operational', data_get($thread->metadata, 'initial_focus'));
        $this->assertSame('ai_inbox_item', data_get($thread->metadata, 'source_type'));
        $this->assertSame($item->id, data_get($thread->metadata, 'source_id'));
        $this->assertSame('Inbox - Telemetry Health', data_get($thread->metadata, 'context_label'));
        $this->assertSame('mobile_operational_read', data_get($thread->metadata, 'capability_profile'));
        $this->assertSame('read_only_until_approval', data_get($thread->metadata, 'permission_policy'));
        $this->assertSame('no_code_execution', data_get($thread->metadata, 'execution_policy'));
        $this->assertSame(
            'Vamos discutir este item com o Atlas.',
            $thread->messages()->where('position', 2)->value('content'),
        );

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/threads/'.$threadId)
            ->assertOk()
            ->assertJsonPath('mobile_context.source.id', $item->id)
            ->assertJsonPath('mobile_context.source.type', 'ai_inbox_item')
            ->assertJsonPath('mobile_context.context_bundle.id', $bundle->id)
            ->assertJsonPath('mobile_context.context_bundle.title', 'Contexto de revisao operacional')
            ->assertJsonPath('mobile_context.policy.atlas_focus', 'operational')
            ->assertJsonPath('mobile_context.policy.capability_profile', 'mobile_operational_read')
            ->assertJsonPath('mobile_context.policy.allows_code_execution', false)
            ->assertJsonPath('mobile_context.policy.requires_approval_for_changes', true)
            ->assertJsonPath('mobile_context.refs_count.sources', 1)
            ->assertJsonPath('mobile_context.refs_count.traces', 1)
            ->assertJsonPath('mobile_context.refs_count.metrics', 1)
            ->assertJsonPath('mobile_context.refs_count.files', 1)
            ->assertJsonPath('mobile_context.refs_count.total', 4);
    }

    public function test_discuss_invokes_bootstrapper_and_returns_bootstrap_contract(): void
    {
        $token = $this->pairedDeviceToken();
        $traceId = (string) Str::uuid();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'category' => 'telemetry_health',
            'title' => 'Atlas precisa discutir automaticamente',
            'summary' => 'Nao deve exigir reenvio manual de contexto.',
        ]);

        $this->mock(DiscussionBootstrapper::class, function (MockInterface $mock) use ($item, $traceId): void {
            $mock
                ->shouldReceive('bootstrap')
                ->once()
                ->with(
                    \Mockery::on(fn (AiInboxItem $argument): bool => $argument->id === $item->id),
                    \Mockery::on(fn (AiThread $thread): bool => $thread->source_id === $item->id),
                    'operational',
                )
                ->andReturn([
                    'status' => 'queued',
                    'trace_id' => $traceId,
                    'context_bundle_id' => null,
                ]);
        });

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discuss')
            ->assertOk()
            ->assertJsonPath('result.focus', 'operational')
            ->assertJsonPath('result.bootstrap.status', 'queued')
            ->assertJsonPath('result.bootstrap.trace_id', $traceId);
    }

    public function test_discuss_persists_failed_bootstrap_status_on_thread_metadata(): void
    {
        $token = $this->pairedDeviceToken();
        $this->createGatewaySchemaReadyTables();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'category' => 'telemetry_health',
            'title' => 'Atlas precisa discutir com falha de bootstrap',
            'summary' => 'Falha deve aparecer na conversa.',
        ]);

        $this->mock(AiGatewayService::class, function (MockInterface $mock): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->andThrow(new RuntimeException('gateway indisponivel'));
        });

        $threadId = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discuss')
            ->assertOk()
            ->assertJsonPath('result.bootstrap.status', 'failed')
            ->assertJsonPath('result.bootstrap.reason', 'gateway indisponivel')
            ->json('result.thread_id');

        $thread = AiThread::query()->findOrFail($threadId);
        $this->assertSame('failed', data_get($thread->metadata, 'discussion_bootstrap_status'));
        $this->assertSame('gateway indisponivel', data_get($thread->metadata, 'discussion_bootstrap_error'));
        $this->assertNull(data_get($thread->metadata, 'discussion_bootstrap_trace_id'));
        $this->assertSame($item->context_bundle_id, data_get($thread->metadata, 'discussion_bootstrap_context_bundle_id'));

        $item->refresh();
        $this->assertSame('failed', data_get($item->payload, 'discussion_bootstrap_status'));
        $this->assertSame('gateway indisponivel', data_get($item->payload, 'discussion_bootstrap_error'));
    }

    public function test_discuss_existing_failed_bootstrap_reports_failed_trace(): void
    {
        $token = $this->pairedDeviceToken();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'category' => 'telemetry_health',
            'title' => 'Atlas precisa reabrir falha antiga',
            'summary' => 'Falha antiga nao deve parecer em fila.',
        ]);
        $thread = AiThread::query()->create([
            'title' => $item->title,
            'summary' => $item->summary,
            'status' => 'active',
            'surface' => 'mobile',
            'source_type' => 'inbox_item',
            'source_id' => $item->id,
            'message_count' => 0,
            'metadata' => [
                'atlas_focus' => 'operational',
                'source_type' => 'ai_inbox_item',
                'source_id' => $item->id,
                'inbox_item_id' => $item->id,
                'capability_profile' => 'mobile_operational_read',
            ],
        ]);
        $trace = new AiTrace;
        $trace->forceFill([
            'id' => (string) Str::uuid(),
            'trace_key' => 'trace_bootstrap_failed_existing',
            'thread_id' => $thread->id,
            'source_type' => 'inbox_item',
            'source_id' => $item->id,
            'status' => 'failed',
            'operator_input' => 'Analise este item operacional do Inbox antes da primeira mensagem do operador.',
            'agent_slug' => 'orquestrador',
            'provider' => 'codex_cli',
            'skill_versions' => [],
            'context_refs' => [],
            'metadata' => [
                'client_id' => 'inbox-discuss-bootstrap-'.$item->id,
                'error' => 'provider falhou',
            ],
        ]);
        $trace->save();
        $item->update([
            'payload' => [
                'discussion_thread_id' => $thread->id,
                'discussion_bootstrap_trace_id' => $trace->id,
                'discussion_bootstrap_status' => 'queued',
                'discussion_bootstrap_attempt' => 1,
            ],
        ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discuss')
            ->assertOk()
            ->assertJsonPath('result.thread_id', $thread->id)
            ->assertJsonPath('result.bootstrap.status', 'failed')
            ->assertJsonPath('result.bootstrap.trace_id', $trace->id)
            ->assertJsonPath('result.bootstrap.reason', 'provider falhou');

        $thread->refresh();
        $item->refresh();
        $this->assertSame('failed', data_get($thread->metadata, 'discussion_bootstrap_status'));
        $this->assertSame('provider falhou', data_get($thread->metadata, 'discussion_bootstrap_error'));
        $this->assertSame('failed', data_get($item->payload, 'discussion_bootstrap_status'));
        $this->assertSame('provider falhou', data_get($item->payload, 'discussion_bootstrap_error'));
    }

    public function test_discussion_bootstrap_retry_reuses_thread_and_creates_new_attempt(): void
    {
        $token = $this->pairedDeviceToken();
        $this->createGatewaySchemaReadyTables();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'category' => 'telemetry_health',
            'title' => 'Atlas precisa recuperar bootstrap',
            'summary' => 'Retry deve reaproveitar a mesma conversa.',
        ]);
        $retryTraceId = (string) Str::uuid();
        $capturedRetryOptions = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($retryTraceId, &$capturedRetryOptions): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->ordered()
                ->andThrow(new RuntimeException('gateway indisponivel'));

            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->ordered()
                ->with(\Mockery::type('string'), \Mockery::on(function (array $options) use (&$capturedRetryOptions): bool {
                    $capturedRetryOptions = $options;

                    return str_contains((string) ($options['client_id'] ?? ''), '-retry-2')
                        && data_get($options, 'payload.discussion_bootstrap.retry') === true
                        && data_get($options, 'payload.discussion_bootstrap.attempt') === 2;
                }))
                ->andReturnUsing(function (string $input, array $options) use ($retryTraceId): AiTrace {
                    $trace = new AiTrace;
                    $trace->forceFill([
                        'id' => $retryTraceId,
                        'trace_key' => 'trace_bootstrap_retry_test',
                        'thread_id' => $options['thread_id'] ?? null,
                        'source_type' => $options['source_type'] ?? 'inbox_item',
                        'source_id' => $options['source_id'] ?? null,
                        'status' => 'queued',
                        'operator_input' => $input,
                        'agent_slug' => $options['agent_slug'] ?? 'orquestrador',
                        'provider' => null,
                        'skill_versions' => [],
                        'context_refs' => [],
                        'metadata' => ['client_id' => $options['client_id'] ?? 'retry-test'],
                    ]);
                    $trace->save();

                    return $trace;
                });
        });

        $threadId = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discuss')
            ->assertOk()
            ->assertJsonPath('result.bootstrap.status', 'failed')
            ->json('result.thread_id');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discussion-bootstrap/retry')
            ->assertOk()
            ->assertJsonPath('result.thread_id', $threadId)
            ->assertJsonPath('result.bootstrap.status', 'queued')
            ->assertJsonPath('result.bootstrap.trace_id', $retryTraceId)
            ->assertJsonPath('result.bootstrap.attempt', 2);

        $thread = AiThread::query()->findOrFail($threadId);
        $this->assertSame('queued', data_get($thread->metadata, 'discussion_bootstrap_status'));
        $this->assertSame($retryTraceId, data_get($thread->metadata, 'discussion_bootstrap_trace_id'));
        $this->assertSame(2, data_get($thread->metadata, 'discussion_bootstrap_attempt'));
        $this->assertSame($threadId, data_get($capturedRetryOptions, 'thread_id'));

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discussion-bootstrap/retry')
            ->assertOk()
            ->assertJsonPath('result.thread_id', $threadId)
            ->assertJsonPath('result.bootstrap.status', 'already_queued')
            ->assertJsonPath('result.bootstrap.trace_id', $retryTraceId)
            ->assertJsonPath('result.bootstrap.attempt', 2);

        $item->refresh();
        $this->assertSame('queued', data_get($item->payload, 'discussion_bootstrap_status'));
        $this->assertSame($retryTraceId, data_get($item->payload, 'discussion_bootstrap_trace_id'));
        $this->assertSame(2, data_get($item->payload, 'discussion_bootstrap_attempt'));
    }

    public function test_mobile_thread_reply_forces_safe_read_runtime_policy(): void
    {
        $token = $this->pairedDeviceToken();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'title' => 'Discutir runtime seguro',
            'summary' => 'Contexto pronto.',
            'available_actions' => [['id' => 'discuss', 'label' => 'Discutir com Atlas']],
        ]);
        $threadId = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discuss')
            ->json('result.thread_id');
        $clientId = (string) Str::uuid();

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($threadId, $clientId): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->with('Preciso entender o alerta', \Mockery::on(function (array $options) use ($threadId, $clientId): bool {
                    return ($options['client_id'] ?? null) === $clientId
                        && ($options['thread_id'] ?? null) === $threadId
                        && data_get($options, 'payload.custom') === 'kept'
                        && data_get($options, 'payload.permission_mode') === 'read'
                        && data_get($options, 'payload.tool_permissions.mode') === 'read'
                        && data_get($options, 'payload.tool_permissions.confirmed') === false
                        && data_get($options, 'payload.tool_permissions.allow_unsandboxed_provider') === false
                        && data_get($options, 'payload.mobile_runtime_policy.allows_code_execution') === false;
                }))
                ->andReturn(tap(new AiTrace, fn (AiTrace $trace) => $trace->forceFill([
                    'id' => (string) Str::uuid(),
                    'trace_key' => 'trace_mobile_safe_policy_test',
                    'thread_id' => $threadId,
                    'status' => 'queued',
                    'operator_input' => 'Preciso entender o alerta',
                    'agent_slug' => 'orquestrador',
                    'provider' => 'claude_cli',
                    'skill_versions' => [],
                    'context_refs' => [],
                    'metadata' => ['client_id' => $clientId],
                    'created_at' => now(),
                    'updated_at' => now(),
                ])));
        });

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/threads/'.$threadId.'/reply', [
                'input_text' => 'Preciso entender o alerta',
                'client_id' => $clientId,
                'payload' => [
                    'custom' => 'kept',
                    'permission_mode' => 'danger',
                    'tool_permissions' => [
                        'mode' => 'danger',
                        'confirmed' => true,
                        'allow_unsandboxed_provider' => true,
                    ],
                ],
            ])
            ->assertAccepted()
            ->assertJsonPath('trace.thread_id', $threadId);
    }

    public function test_atlas_ai_sheet_cannot_escalate_operational_mobile_thread_runtime_policy(): void
    {
        $token = $this->pairedDeviceToken();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'category' => 'telemetry_health',
            'title' => 'Revisar saude operacional',
            'summary' => 'Contexto operacional seguro.',
        ]);
        $threadId = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discuss')
            ->json('result.thread_id');
        $clientId = (string) Str::uuid();
        $capturedOptions = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($threadId, $clientId, &$capturedOptions): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->with('Quero revisar pelo Atlas principal', \Mockery::on(function (array $options) use ($threadId, $clientId, &$capturedOptions): bool {
                    $capturedOptions = $options;

                    return ($options['client_id'] ?? null) === $clientId
                        && ($options['thread_id'] ?? null) === $threadId;
                }))
                ->andReturn(tap(new AiTrace, fn (AiTrace $trace) => $trace->forceFill([
                    'id' => (string) Str::uuid(),
                    'trace_key' => 'trace_main_sheet_safe_policy_test',
                    'thread_id' => $threadId,
                    'status' => 'queued',
                    'operator_input' => 'Quero revisar pelo Atlas principal',
                    'agent_slug' => 'orquestrador',
                    'provider' => 'codex_cli',
                    'skill_versions' => [],
                    'context_refs' => [],
                    'metadata' => ['client_id' => $clientId],
                    'created_at' => now(),
                    'updated_at' => now(),
                ])));
        });

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/interactions', [
                'input_text' => 'Quero revisar pelo Atlas principal',
                'client_id' => $clientId,
                'thread_id' => $threadId,
                'new_thread' => false,
                'agent_slug' => 'orquestrador',
                'provider' => 'codex_cli',
                'kind' => 'analysis',
                'source_type' => 'app',
                'payload' => [
                    'app_surface' => 'atlas_ai_sheet',
                    'permission_mode' => 'danger',
                    'execution_policy' => 'single_provider',
                    'tool_permissions' => [
                        'mode' => 'danger',
                        'confirmed' => true,
                        'allow_unsandboxed_provider' => true,
                    ],
                    'mobile_runtime_policy' => [
                        'allows_code_execution' => true,
                    ],
                ],
            ])
            ->assertAccepted()
            ->assertJsonPath('trace.thread_id', $threadId);

        $this->assertSame('atlas_ai_sheet', data_get($capturedOptions, 'payload.app_surface'));
        $this->assertSame('mobile_gateway_inbox', data_get($capturedOptions, 'payload.thread_source'));
        $this->assertSame($item->id, data_get($capturedOptions, 'payload.inbox_item_id'));
        $this->assertSame('atlas_full_access', data_get($capturedOptions, 'payload.capability_profile'));
        $this->assertSame('danger', data_get($capturedOptions, 'payload.permission_mode'));
        $this->assertSame('full_access', data_get($capturedOptions, 'payload.permission_policy'));
        $this->assertSame('provider_execution_allowed', data_get($capturedOptions, 'payload.execution_policy'));
        $this->assertSame('danger', data_get($capturedOptions, 'payload.tool_permissions.mode'));
        $this->assertTrue(data_get($capturedOptions, 'payload.tool_permissions.confirmed'));
        $this->assertTrue(data_get($capturedOptions, 'payload.tool_permissions.allow_unsandboxed_provider'));
        $this->assertTrue(data_get($capturedOptions, 'payload.mobile_runtime_policy.allows_code_execution'));
    }

    public function test_atlas_sheet_preserves_execution_permissions_for_non_operational_session(): void
    {
        $clientId = (string) Str::uuid();

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->with('Execute um webscrape controlado', \Mockery::on(function (array $options) use ($clientId): bool {
                    return ($options['client_id'] ?? null) === $clientId
                        && ($options['thread_id'] ?? null) === null
                        && data_get($options, 'payload.app_surface') === 'atlas_ai_sheet'
                        && data_get($options, 'payload.atlas_focus') === 'programming'
                        && data_get($options, 'payload.permission_mode') === 'danger'
                        && data_get($options, 'payload.execution_policy') === 'single_provider'
                        && data_get($options, 'payload.tool_permissions.mode') === 'danger'
                        && data_get($options, 'payload.tool_permissions.confirmed') === true
                        && data_get($options, 'payload.tool_permissions.allow_unsandboxed_provider') === true;
                }))
                ->andReturn(tap(new AiTrace, fn (AiTrace $trace) => $trace->forceFill([
                    'id' => (string) Str::uuid(),
                    'trace_key' => 'trace_main_sheet_dev_policy_test',
                    'thread_id' => (string) Str::uuid(),
                    'status' => 'queued',
                    'operator_input' => 'Execute um webscrape controlado',
                    'agent_slug' => 'desenvolvedor',
                    'provider' => 'codex_cli',
                    'skill_versions' => [],
                    'context_refs' => [],
                    'metadata' => ['client_id' => $clientId],
                    'created_at' => now(),
                    'updated_at' => now(),
                ])));
        });

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/interactions', [
                'input_text' => 'Execute um webscrape controlado',
                'client_id' => $clientId,
                'new_thread' => true,
                'agent_slug' => 'desenvolvedor',
                'provider' => 'codex_cli',
                'kind' => 'interaction',
                'source_type' => 'app',
                'payload' => [
                    'app_surface' => 'atlas_ai_sheet',
                    'atlas_focus' => 'programming',
                    'permission_mode' => 'danger',
                    'execution_policy' => 'single_provider',
                    'tool_permissions' => [
                        'mode' => 'danger',
                        'confirmed' => true,
                        'allow_unsandboxed_provider' => true,
                    ],
                ],
            ])
            ->assertAccepted();
    }

    public function test_atlas_sheet_returns_validation_error_when_gateway_rejects_interaction(): void
    {
        $clientId = (string) Str::uuid();

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($clientId): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->with('Me fala sobre esses', \Mockery::on(fn (array $options): bool => ($options['client_id'] ?? null) === $clientId))
                ->andThrow(new RuntimeException('Provider sem suporte a imagem.'));
        });

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/interactions', [
                'input_text' => 'Me fala sobre esses',
                'client_id' => $clientId,
                'new_thread' => true,
                'agent_slug' => 'orquestrador',
                'provider' => 'claude_cli',
                'kind' => 'interaction',
                'source_type' => 'app',
                'payload' => [
                    'app_surface' => 'atlas_ai_sheet',
                    'visual_input' => ['image_count' => 1],
                    'attachments' => [
                        'images' => [
                            ['path' => '/tmp/screen.png', 'mime_type' => 'image/png'],
                        ],
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'ai_interaction_rejected')
            ->assertJsonPath('message', 'Provider sem suporte a imagem.');
    }

    public function test_atlas_sheet_respects_requested_mode_on_operational_thread(): void
    {
        $token = $this->pairedDeviceToken();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'category' => 'telemetry_health',
            'title' => 'Promover para programacao',
            'summary' => 'Contexto operacional precisa de execucao tecnica.',
        ]);
        $threadId = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/discuss')
            ->json('result.thread_id');
        $clientId = (string) Str::uuid();
        $capturedOptions = null;

        $this->mock(AiGatewayService::class, function (MockInterface $mock) use ($threadId, $clientId, &$capturedOptions): void {
            $mock
                ->shouldReceive('enqueueInteraction')
                ->once()
                ->with('Agora desenvolva a correcao', \Mockery::on(function (array $options) use ($threadId, $clientId, &$capturedOptions): bool {
                    $capturedOptions = $options;

                    return ($options['client_id'] ?? null) === $clientId
                        && ($options['thread_id'] ?? null) === $threadId;
                }))
                ->andReturn(tap(new AiTrace, fn (AiTrace $trace) => $trace->forceFill([
                    'id' => (string) Str::uuid(),
                    'trace_key' => 'trace_operational_thread_programming_mode_test',
                    'thread_id' => $threadId,
                    'status' => 'queued',
                    'operator_input' => 'Agora desenvolva a correcao',
                    'agent_slug' => 'desenvolvedor',
                    'provider' => 'codex_cli',
                    'skill_versions' => [],
                    'context_refs' => [],
                    'metadata' => ['client_id' => $clientId],
                    'created_at' => now(),
                    'updated_at' => now(),
                ])));
        });

        $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/ai/interactions', [
                'input_text' => 'Agora desenvolva a correcao',
                'client_id' => $clientId,
                'thread_id' => $threadId,
                'new_thread' => false,
                'agent_slug' => 'desenvolvedor',
                'provider' => 'codex_cli',
                'kind' => 'analysis',
                'source_type' => 'app',
                'payload' => [
                    'app_surface' => 'atlas_ai_sheet',
                    'atlas_focus' => 'programming',
                    'atlas_mode' => 'programming',
                    'routing_task' => 'dev',
                    'permission_mode' => 'danger',
                    'execution_policy' => 'single_provider',
                    'tool_permissions' => [
                        'mode' => 'danger',
                        'confirmed' => true,
                        'allow_unsandboxed_provider' => true,
                    ],
                ],
            ])
            ->assertAccepted()
            ->assertJsonPath('trace.thread_id', $threadId);

        $this->assertSame('programming', data_get($capturedOptions, 'payload.atlas_focus'));
        $this->assertSame('programming', data_get($capturedOptions, 'payload.atlas_mode'));
        $this->assertSame('dev', data_get($capturedOptions, 'payload.routing_task'));
        $this->assertSame('atlas_full_access', data_get($capturedOptions, 'payload.capability_profile'));
        $this->assertSame('danger', data_get($capturedOptions, 'payload.permission_mode'));
        $this->assertTrue(data_get($capturedOptions, 'payload.mobile_runtime_policy.allows_code_execution'));
    }

    public function test_mobile_thread_show_requires_thread_linked_to_device_inbox_item(): void
    {
        $token = $this->pairedDeviceToken();
        $thread = AiThread::query()->create([
            'title' => 'Thread solta',
            'status' => 'active',
            'surface' => 'mobile',
            'metadata' => [],
        ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/threads/'.$thread->id)
            ->assertNotFound();
    }

    public function test_inbox_service_deduplicates_active_items(): void
    {
        $first = app(AtlasInboxService::class)->create([
            'type' => 'job_result',
            'title' => 'Job finalizado',
            'summary' => 'Primeira ocorrencia.',
            'initiator' => 'job',
            'dedupe_key' => 'job:daily-report',
        ]);

        $second = app(AtlasInboxService::class)->create([
            'type' => 'job_result',
            'title' => 'Job finalizado novamente',
            'summary' => 'Segunda ocorrencia.',
            'initiator' => 'job',
            'dedupe_key' => 'job:daily-report',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('ai_inbox_items', 1);
        $this->assertSame(2, $second->payload['occurrence_count']);
        $this->assertSame('Job finalizado novamente', $second->title);
        $this->assertSame('atlas.proactive.delivery_contract.v1', data_get($second->payload, 'proactive_delivery_contract.schema_version'));
        $this->assertTrue(data_get($second->payload, 'proactive_delivery_contract.push_pointer_only'));
        $this->assertTrue(data_get($second->payload, 'proactive_delivery_contract.authenticated_fetch_required'));
        $this->assertFalse(data_get($second->payload, 'proactive_delivery_contract.raw_payload_exposed_in_push'));
        $this->assertFalse(data_get($second->payload, 'proactive_delivery_contract.auto_action_allowed'));
        $this->assertSame(hash('sha256', 'job:daily-report'), data_get($second->payload, 'proactive_delivery_contract.dedupe_key_hash'));
        $this->assertIsString(data_get($second->payload, 'proactive_delivery_contract.contract_hash'));
    }

    public function test_inbox_service_persists_proactive_delivery_contract_for_push_boundary(): void
    {
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'severity' => 'warning',
            'title' => 'Insight proativo',
            'summary' => 'Resumo seguro para inbox.',
            'body' => 'Texto completo fica atras da API autenticada.',
            'context_bundle_id' => (string) Str::uuid(),
            'dedupe_key' => 'insight:proactive-contract',
            'push_policy' => ['send' => 'immediate', 'target' => 'atlas_ai'],
            'payload' => [
                'raw_context_marker' => 'available only in authenticated inbox detail',
            ],
        ]);

        $contract = data_get($item->payload, 'proactive_delivery_contract');

        $this->assertSame('atlas.proactive.delivery_contract.v1', data_get($contract, 'schema_version'));
        $this->assertSame($item->id, data_get($contract, 'inbox_item_id'));
        $this->assertSame('mobile_inbox', data_get($contract, 'surface'));
        $this->assertSame('immediate', data_get($contract, 'push_send_mode'));
        $this->assertTrue(data_get($contract, 'push_pointer_only'));
        $this->assertTrue(data_get($contract, 'authenticated_fetch_required'));
        $this->assertTrue(data_get($contract, 'deep_link_only_delivery'));
        $this->assertTrue(data_get($contract, 'context_bundle_api_only'));
        $this->assertFalse(data_get($contract, 'raw_context_exposed_in_push'));
        $this->assertFalse(data_get($contract, 'raw_payload_exposed_in_push'));
        $this->assertFalse(data_get($contract, 'body_exposed_in_push'));
        $this->assertFalse(data_get($contract, 'auto_action_allowed'));
        $this->assertTrue(data_get($contract, 'action_execution_requires_registry'));
        $this->assertSame('atlas.proactive.presence_eclipse.v1', data_get($contract, 'presence_eclipse_governance.schema_version'));
        $this->assertTrue(data_get($contract, 'presence_eclipse_governance.explicit_opt_out_supported'));
        $this->assertTrue(data_get($contract, 'presence_eclipse_governance.manual_eclipse_supported'));
        $this->assertTrue(data_get($contract, 'presence_eclipse_governance.no_surveillance_default'));
        $this->assertSame('warning', data_get($contract, 'severity'));
        $this->assertSame(hash('sha256', 'insight:proactive-contract'), data_get($contract, 'dedupe_key_hash'));
        $this->assertIsString(data_get($contract, 'deep_link_hash'));
        $this->assertIsString(data_get($contract, 'contract_hash'));
        $this->assertContains('inbox_id', data_get($contract, 'push_data_fields'));
        $this->assertContains('deep_link', data_get($contract, 'push_data_fields'));
    }

    public function test_inbox_service_does_not_dedupe_against_expired_items(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 10:00:00'));
        $expired = app(AtlasInboxService::class)->create([
            'type' => 'job_result',
            'title' => 'Job antigo',
            'summary' => 'Ja venceu.',
            'initiator' => 'job',
            'dedupe_key' => 'job:expired-report',
            'expires_at' => now()->subMinute(),
        ]);

        $fresh = app(AtlasInboxService::class)->create([
            'type' => 'job_result',
            'title' => 'Job novo',
            'summary' => 'Nova ocorrencia depois do vencimento.',
            'initiator' => 'job',
            'dedupe_key' => 'job:expired-report',
        ]);
        Carbon::setTestNow();

        $this->assertNotSame($expired->id, $fresh->id);
        $this->assertSame('expired', $expired->refresh()->status);
        $this->assertSame('Job novo', $fresh->title);
        $this->assertDatabaseCount('ai_inbox_items', 2);
    }

    public function test_context_bundle_redacts_secrets_before_persisting_thread_context(): void
    {
        $secret = 'sk-proj-abcdefghijklmnopqrstuvwxyz1234567890';
        $bundle = app(ContextBundleService::class)->create([
            'purpose' => 'security_test',
            'title' => 'Token '.$secret,
            'summary' => 'Authorization: Bearer abcdefghijklmnopqrstuvwxyz',
            'body_for_thread' => 'Use github_pat_abcdefghijklmnopqrstuvwxyz1234567890 para teste.',
            'raw_payload' => [
                'api_key' => $secret,
                'nested' => [
                    'text' => 'Bearer abcdefghijklmnopqrstuvwxyz',
                ],
            ],
            'source_refs' => [
                ['authorization' => 'Bearer abcdefghijklmnopqrstuvwxyz'],
            ],
        ]);

        $this->assertSame('redacted', $bundle->redaction_status);
        $this->assertStringNotContainsString($secret, $bundle->title);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz1234567890', $bundle->body_for_thread);
        $this->assertSame('[redacted]', $bundle->raw_payload['api_key']);
        $this->assertSame('Bearer [redacted]', $bundle->raw_payload['nested']['text']);
        $this->assertSame('Bearer [redacted]', $bundle->source_refs[0]['authorization']);
    }

    public function test_mobile_inbox_active_filter_keeps_pending_read_items_and_hides_closed_or_future_snoozed(): void
    {
        $token = $this->pairedDeviceToken();
        $inbox = app(AtlasInboxService::class);

        $read = $inbox->create([
            'type' => 'proposal',
            'title' => 'Proposta lida ainda pendente',
            'status' => 'read',
        ]);
        $unread = $inbox->create([
            'type' => 'insight',
            'title' => 'Insight ainda pendente',
            'status' => 'unread',
        ]);
        $dismissed = $inbox->create([
            'type' => 'job_result',
            'title' => 'Job descartado',
        ]);
        $inbox->dismiss($dismissed);
        $resolved = $inbox->create([
            'type' => 'approval',
            'title' => 'Approval resolvido',
            'status' => 'resolved',
        ]);
        $snoozed = $inbox->create([
            'type' => 'self_diagnostic',
            'title' => 'Diagnostico adiado',
        ]);
        $inbox->snooze($snoozed, now()->addDay(), 'teste');
        $expired = $inbox->create([
            'type' => 'proposal',
            'title' => 'Proposta vencida',
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/inbox?status=active')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->json('items');

        $ids = collect($response)->pluck('id')->all();
        $this->assertContains($read->id, $ids);
        $this->assertContains($unread->id, $ids);
        $this->assertNotContains($dismissed->id, $ids);
        $this->assertNotContains($resolved->id, $ids);
        $this->assertNotContains($snoozed->id, $ids);
        $this->assertNotContains($expired->id, $ids);

        $exitCode = Artisan::call('atlas:cli:inbox', [
            'action' => 'list',
            '--filter' => 'active',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertCount(2, $payload['items'] ?? []);
        $this->assertArrayHasKey('next_cursor', $payload);
    }

    public function test_inbox_action_without_idempotency_key_does_not_replay_previous_different_action(): void
    {
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'title' => 'Insight para discutir',
            'summary' => 'Primeiro marcar como lido, depois discutir.',
        ]);

        $first = app(InboxActionRegistry::class)->handle($item, 'mark_read');
        $second = app(InboxActionRegistry::class)->handle($item->refresh(), 'discuss');

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['idempotent']);
        $this->assertIsString($second['result']['thread_id'] ?? null);
        $this->assertDatabaseCount('ai_threads', 1);
        $this->assertSame('discuss', $item->refresh()->response['action']);
    }

    public function test_approval_response_resolves_item(): void
    {
        $token = $this->pairedDeviceToken();
        config()->set('atlas.mobile.approval_ttl_minutes', 30);
        $item = app(AtlasInboxService::class)->create([
            'type' => 'approval',
            'title' => 'Atlas precisa editar arquivo',
            'summary' => 'Permissao para tool runtime.',
            'payload' => ['tool' => 'shell', 'risk' => 'medium'],
        ]);

        $this->assertTrue($item->expires_at?->isFuture());

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Idempotency-Key', 'approve-once-test')
            ->postJson('/v1/mobile/inbox/'.$item->id.'/respond', [
                'action' => 'approve_once',
                'data' => ['reason' => 'ok'],
            ])
            ->assertOk()
            ->assertJsonPath('item.status', 'resolved')
            ->assertJsonPath('item.response.action', 'approve_once');

        $this->assertSame('resolved', $item->refresh()->status);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('Idempotency-Key', 'approve-again-test')
            ->postJson('/v1/mobile/inbox/'.$item->id.'/respond', [
                'action' => 'approve_session',
                'data' => ['reason' => 'late'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.action.0', 'Item ja esta fechado.');

        $this->assertSame('approve_once', $item->refresh()->response['action']);
    }

    public function test_expired_approval_fails_closed(): void
    {
        $token = $this->pairedDeviceToken();
        config()->set('atlas.mobile.approval_ttl_minutes', 5);
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:00:00'));

        $item = app(AtlasInboxService::class)->create([
            'type' => 'approval',
            'title' => 'Approval temporaria',
            'summary' => 'Deve expirar antes de aprovar.',
            'payload' => ['tool' => 'shell', 'risk' => 'high'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:06:00'));
        try {
            $this
                ->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/v1/mobile/inbox/'.$item->id.'/respond', [
                    'action' => 'approve_once',
                    'data' => ['reason' => 'tarde demais'],
                ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.action.0', 'Item expirado.');

            $this->assertSame('unread', $item->refresh()->status);
            $this->assertNull($item->response);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_workspace_approval_records_scope_workspace_and_expiration(): void
    {
        $token = $this->pairedDeviceToken();
        Carbon::setTestNow(Carbon::parse('2026-04-30 10:00:00'));

        try {
            $item = app(AtlasInboxService::class)->create([
                'type' => 'approval',
                'title' => 'Atlas precisa executar comando no workspace',
                'summary' => 'Permissao temporaria para runtime.',
                'payload' => [
                    'tool' => 'shell',
                    'risk' => 'high',
                    'workspace' => '/Users/vitorepf/Develop/atlas/atlas-server',
                ],
            ]);

            $this
                ->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/v1/mobile/inbox/'.$item->id.'/respond', [
                    'action' => 'approve_workspace_1h',
                    'data' => ['reason' => 'janela curta'],
                ])
                ->assertOk()
                ->assertJsonPath('item.status', 'resolved')
                ->assertJsonPath('item.response.action', 'approve_workspace_1h')
                ->assertJsonPath('item.response.approval_grant.scope', 'workspace')
                ->assertJsonPath('item.response.approval_grant.workspace', '/Users/vitorepf/Develop/atlas/atlas-server')
                ->assertJsonPath('item.response.approval_grant.expires_at', Carbon::parse('2026-04-30 11:00:00')->toJSON());

            $response = $item->refresh()->response;
            $this->assertSame('shell', data_get($response, 'approval_grant.tool'));
            $this->assertSame('high', data_get($response, 'approval_grant.risk'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_workspace_approval_requires_workspace_context(): void
    {
        $token = $this->pairedDeviceToken();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'approval',
            'title' => 'Approval sem workspace',
            'summary' => 'Nao deve aprovar escopo amplo sem workspace.',
            'payload' => ['tool' => 'shell', 'risk' => 'high'],
        ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/respond', [
                'action' => 'approve_workspace_1h',
                'data' => ['reason' => 'faltando contexto'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.workspace.0', 'Workspace obrigatorio para approve_workspace_1h.');

        $this->assertSame('unread', $item->refresh()->status);
        $this->assertNull($item->response);
    }

    public function test_sensitive_mobile_actions_are_rate_limited(): void
    {
        config()->set('atlas.mobile.rate_limits.sensitive_action_max_attempts', 2);
        config()->set('atlas.mobile.rate_limits.sensitive_action_decay_seconds', 60);
        $token = $this->pairedDeviceToken();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'approval',
            'title' => 'Approval sensivel sem workspace',
            'summary' => 'Nao deve aceitar spam de actions sensiveis.',
            'payload' => ['tool' => 'shell', 'risk' => 'high'],
        ]);

        for ($i = 1; $i <= 2; $i++) {
            $this
                ->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/v1/mobile/inbox/'.$item->id.'/respond', [
                    'action' => 'approve_workspace_1h',
                    'data' => ['reason' => 'tentativa '.$i],
                ])
                ->assertUnprocessable()
                ->assertJsonPath('errors.workspace.0', 'Workspace obrigatorio para approve_workspace_1h.');
        }

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/respond', [
                'action' => 'approve_workspace_1h',
                'data' => ['reason' => 'bloquear'],
            ])
            ->assertStatus(429)
            ->assertJsonPath('bucket', 'sensitive_action');

        $this->assertSame('unread', $item->refresh()->status);
        $this->assertNull($item->response);
    }

    public function test_mobile_gateway_records_audit_events_for_pairing_revoke_and_sensitive_actions(): void
    {
        $init = $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/v1/mobile/pairing/initiate', ['device_label' => 'Audit iPhone'])
            ->assertOk()
            ->json();

        $confirm = $this
            ->postJson('/v1/mobile/pairing/confirm', [
                'pairing_id' => $init['pairing_id'],
                'code' => $init['code'],
                'platform' => 'ios',
                'device_label' => 'Audit iPhone',
            ])
            ->assertOk()
            ->json();

        $token = $confirm['device_token'];
        $deviceId = $confirm['device']['id'];

        $approval = app(AtlasInboxService::class)->create([
            'type' => 'approval',
            'title' => 'Approval auditavel',
            'payload' => [
                'tool' => 'shell',
                'risk' => 'high',
                'workspace' => '/Users/vitorepf/Develop/atlas/atlas-server',
            ],
        ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$approval->id.'/respond', [
                'action' => 'approve_workspace_1h',
                'data' => ['reason' => 'auditar approval'],
            ])
            ->assertOk();

        $diagnostic = app(AtlasInboxService::class)->create([
            'type' => 'self_diagnostic',
            'title' => 'Diagnostico auditavel',
            'summary' => 'Ignorar por 30 dias.',
            'payload' => ['category' => 'quality_score_regression'],
        ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$diagnostic->id.'/respond', [
                'action' => 'ignore_30d',
                'data' => [],
            ])
            ->assertOk();

        $proposal = app(ProposalInboxEmitter::class)->emit([
            'title' => 'Proposal auditavel',
            'finding' => 'Encontrou duplicacao pequena.',
            'problem' => 'Duplicacao pode crescer.',
            'solution' => 'Extrair helper.',
            'worth_it' => 'Baixo custo.',
            'best_solution_rationale' => 'Mantem revisao humana.',
            'dedupe_key' => 'proposal:audit-test',
        ]);
        $this->assertNotNull($proposal);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$proposal->id.'/respond', [
                'action' => 'discard',
                'data' => ['reason' => 'nao aplicar agora'],
            ])
            ->assertOk();

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/v1/mobile/devices/'.$deviceId)
            ->assertOk();

        $eventTypes = AuditEvent::query()->pluck('event_type')->all();
        $this->assertContains('mobile.pairing.initiated', $eventTypes);
        $this->assertContains('mobile.pairing.confirmed', $eventTypes);
        $this->assertContains('mobile.device.revoked', $eventTypes);
        $this->assertContains('inbox.action.requested', $eventTypes);
        $this->assertContains('inbox.action.completed', $eventTypes);

        $completed = AuditEvent::query()->where('event_type', 'inbox.action.completed')->get();
        $this->assertTrue($completed->contains(fn (AuditEvent $event): bool => data_get($event->evidence, 'action') === 'approve_workspace_1h'));
        $this->assertTrue($completed->contains(fn (AuditEvent $event): bool => data_get($event->evidence, 'action') === 'ignore_30d'));
        $this->assertTrue($completed->contains(fn (AuditEvent $event): bool => data_get($event->evidence, 'action') === 'discard'));

        $this->assertTrue(AuditEvent::query()
            ->where('event_type', 'mobile.device.revoked')
            ->where('subject_id', $deviceId)
            ->exists());
    }

    public function test_code_actions_require_passed_gate_and_still_fail_closed_without_safe_handler(): void
    {
        $token = $this->pairedDeviceToken();
        $blocked = app(AtlasInboxService::class)->create([
            'type' => 'proposal',
            'title' => 'Open PR sem gate',
            'summary' => 'Nao pode executar action de codigo.',
            'available_actions' => [['id' => 'open_pr', 'label' => 'Abrir PR', 'style' => 'primary']],
            'payload' => [
                'quality_gate_status' => 'failed',
                'policy' => ['auto_merge' => false],
            ],
        ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$blocked->id.'/respond', [
                'action' => 'open_pr',
                'data' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.action.0', 'Action de codigo exige quality_gate_status=passed.');

        $this->assertSame('unread', $blocked->refresh()->status);
        $this->assertNull($blocked->response);

        $passedGate = app(AtlasInboxService::class)->create([
            'type' => 'proposal',
            'title' => 'Open PR com gate',
            'summary' => 'Ainda precisa handler seguro.',
            'available_actions' => [['id' => 'open_pr', 'label' => 'Abrir PR', 'style' => 'primary']],
            'payload' => [
                'quality_gate_status' => 'passed',
                'policy' => ['auto_merge' => false],
            ],
        ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$passedGate->id.'/respond', [
                'action' => 'open_pr',
                'data' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.action.0', 'Action de codigo ainda nao possui handler mobile seguro.');

        $this->assertSame('unread', $passedGate->refresh()->status);
        $this->assertNull($passedGate->response);
    }

    public function test_mobile_inbox_rejects_actions_on_future_snoozed_items(): void
    {
        $token = $this->pairedDeviceToken();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'title' => 'Insight adiado',
            'summary' => 'Nao deve discutir antes do retorno.',
        ]);

        app(AtlasInboxService::class)->snooze($item, now()->addDays(3), 'teste');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/respond', [
                'action' => 'discuss',
                'data' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.action.0', fn (string $message): bool => str_starts_with($message, 'Item adiado ate '));

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/inbox/'.$item->id.'/dismiss', [
                'reason' => 'descartar mesmo adiado',
            ])
            ->assertOk()
            ->assertJsonPath('item.status', 'dismissed');
    }

    public function test_cli_inbox_show_respond_and_discuss_use_same_handlers(): void
    {
        if (! Schema::hasTable('atlas_ledger_events')) {
            (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        }

        $bundle = app(ContextBundleService::class)->create([
            'purpose' => 'insight',
            'title' => 'Contexto CLI',
            'summary' => 'Resumo para show.',
            'body_for_thread' => 'Contexto sem duplicar regra entre CLI e app.',
        ]);
        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'title' => 'Insight via CLI',
            'summary' => 'Resumo do insight.',
            'body' => 'Body completo para operador.',
            'context_bundle_id' => $bundle->id,
            'dedupe_key' => 'cli:insight:test',
        ]);

        $showExit = Artisan::call('atlas:cli:inbox', [
            'action' => 'show',
            'id' => $item->id,
            '--json' => true,
        ]);
        $showPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $showExit);
        $this->assertSame($item->id, data_get($showPayload, 'item.id'));
        $this->assertSame('Body completo para operador.', data_get($showPayload, 'item.body'));
        $this->assertSame('Contexto CLI', data_get($showPayload, 'item.context_bundle.title'));

        $respondExit = Artisan::call('atlas:cli:inbox', [
            'action' => 'respond',
            'id' => $item->id,
            '--action' => 'mark_read',
            '--json' => true,
        ]);
        $respondPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $respondExit);
        $this->assertSame('read', data_get($respondPayload, 'item.status'));
        $this->assertSame('mark_read', data_get($respondPayload, 'item.response.action'));

        $markReadLedgerEvent = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::InboxActionRecorded->value)
            ->where('correlation_id', $item->id)
            ->firstOrFail();

        $this->assertSame('atlas.inbox_action.receipt.v1', data_get($markReadLedgerEvent->payload, 'action_receipt.schema_version'));
        $this->assertSame('mark_read', data_get($markReadLedgerEvent->payload, 'action_receipt.action'));
        $this->assertSame('operator_cli', data_get($markReadLedgerEvent->payload, 'action_receipt.actor_type'));
        $this->assertSame('sha256', data_get($markReadLedgerEvent->payload, 'action_receipt.receipt_hash_algorithm'));
        $this->assertContains('result_hash', data_get($markReadLedgerEvent->payload, 'action_receipt.receipt_hash_fields'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($markReadLedgerEvent->payload, 'action_receipt.receipt_hash'));

        $snoozeItem = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'title' => 'Insight para snooze via CLI',
            'summary' => 'Resumo do insight adiado.',
            'dedupe_key' => 'cli:insight:snooze-test',
        ]);
        $snoozedUntil = now()->addDay()->toJSON();
        $snoozeExit = Artisan::call('atlas:cli:inbox', [
            'action' => 'respond',
            'id' => $snoozeItem->id,
            '--action' => 'snooze',
            '--reason' => 'operador revisara depois',
            '--snoozed-until' => $snoozedUntil,
            '--json' => true,
        ]);
        $snoozePayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $snoozeExit);
        $this->assertSame('snoozed', data_get($snoozePayload, 'item.status'));
        $this->assertSame('snooze', data_get($snoozePayload, 'item.response.action'));
        $this->assertSame('operador revisara depois', data_get($snoozePayload, 'item.response.reason'));

        $costRateItem = AiInboxItem::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => 'vitor',
            'type' => 'proposal',
            'category' => 'self_improvement',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Configurar rates de custo via CLI',
            'source_type' => 'atlas_initiative_run',
            'source_id' => (string) Str::uuid(),
            'initiator' => 'system',
            'dedupe_key' => 'cli:provider-cost-rates:'.(string) Str::uuid(),
            'available_actions' => [
                ['id' => 'configure_provider_cost_rates', 'label' => 'Configurar rates', 'style' => 'primary'],
            ],
            'payload' => ['proposal_contract' => ['source_refs' => []]],
        ]);
        $costRateExit = Artisan::call('atlas:cli:inbox', [
            'action' => 'respond',
            'id' => $costRateItem->id,
            '--action' => 'configure_provider_cost_rates',
            '--provider' => 'codex_cli',
            '--model' => 'gpt-5.5',
            '--input-microusd' => '120',
            '--output-microusd' => '480',
            '--currency' => 'USD',
            '--json' => true,
        ]);
        $costRatePayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $costRateExit);
        $this->assertTrue((bool) data_get($costRatePayload, 'result.applied'));
        $this->assertSame('configure_provider_cost_rates', data_get($costRatePayload, 'item.response.action'));
        $this->assertDatabaseHas('ai_provider_cost_rates', [
            'provider' => 'codex_cli',
            'model' => 'gpt-5.5',
            'input_microusd_per_1k' => 120,
            'output_microusd_per_1k' => 480,
            'currency' => 'USD',
        ]);

        $discussExit = Artisan::call('atlas:cli:inbox', [
            'action' => 'discuss',
            'id' => $item->id,
            '--json' => true,
        ]);
        $discussPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $discussExit);
        $this->assertIsString($discussPayload['thread_id'] ?? null);
        $this->assertStringStartsWith('atlas://thread/', $discussPayload['deep_link'] ?? '');
        $this->assertDatabaseCount('ai_threads', 1);
        $this->assertDatabaseCount('ai_messages', 2);

        $proposal = app(ProposalInboxEmitter::class)->emit([
            'title' => 'Review patch via CLI',
            'problem' => 'CLI precisa receber contrato estruturado da action.',
            'solution' => 'Retornar result junto do item no JSON.',
            'worth_it' => 'Evita parser local no CLI.',
            'dedupe_key' => 'cli:proposal:review-patch',
            'diff_refs' => [['path' => 'app/Console/Commands/AtlasCliInboxCommand.php']],
            'metadata' => [
                'review_signal' => [
                    'status' => 'warning',
                    'severity' => 'medium',
                    'recommended_action' => 'review_cli_proposal_contract',
                ],
            ],
        ]);

        $reviewExit = Artisan::call('atlas:cli:inbox', [
            'action' => 'respond',
            'id' => $proposal?->id,
            '--action' => 'review_patch',
            '--json' => true,
        ]);
        $reviewPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $reviewExit);
        $this->assertSame('review_patch', data_get($reviewPayload, 'result.payload.action'));
        $this->assertSame('review_cli_proposal_contract', data_get($reviewPayload, 'result.payload.recommended_action'));
        $this->assertSame('app/Console/Commands/AtlasCliInboxCommand.php', data_get($reviewPayload, 'result.payload.diff_refs.0.path'));
        $this->assertSame($proposal?->id, data_get($reviewPayload, 'item.id'));
    }

    public function test_push_dispatch_sends_immediate_notification_with_badge_count(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-1']], 200)]);
        $this->pairedDeviceToken('ExponentPushToken[test]');

        $item = app(AtlasInboxService::class)->create([
            'type' => 'alert',
            'severity' => 'warning',
            'title' => 'Alerta importante',
            'summary' => 'Resumo seguro para push.',
            'push_policy' => ['send' => 'immediate'],
        ]);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['to'] === 'ExponentPushToken[test]'
            && $request['badge'] === 1
            && $request['data']['inbox_id'] === $item->id
            && $request['data']['deep_link'] === 'atlas://inbox/'.$item->id);
        $delivery = MobilePushDelivery::query()->firstOrFail();
        $this->assertSame('sent', $delivery->status);

        $audit = AuditEvent::query()->where('event_type', 'push.sent')->firstOrFail();
        $contract = data_get($audit->evidence, 'delivery_attempt_contract');
        $this->assertSame('atlas.proactive.push_delivery_attempt.v1', data_get($contract, 'schema_version'));
        $this->assertSame($delivery->id, data_get($contract, 'delivery_id'));
        $this->assertSame($item->id, data_get($contract, 'inbox_item_id'));
        $this->assertSame('atlas.proactive.delivery_contract.v1', data_get($contract, 'proactive_delivery_contract_schema'));
        $this->assertSame(data_get($item->payload, 'proactive_delivery_contract.contract_hash'), data_get($contract, 'proactive_delivery_contract_hash'));
        $this->assertSame(hash('sha256', json_encode($delivery->request_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''), data_get($contract, 'request_payload_hash'));
        $this->assertTrue(data_get($contract, 'push_pointer_only'));
        $this->assertTrue(data_get($contract, 'authenticated_fetch_required'));
        $this->assertFalse(data_get($contract, 'raw_payload_exposed_in_push'));
        $this->assertFalse(data_get($contract, 'body_exposed_in_push'));
        $this->assertFalse(data_get($contract, 'raw_device_id_persisted_in_audit'));
        $this->assertFalse(data_get($contract, 'auto_action_allowed'));
        $this->assertContains('deep_link', data_get($contract, 'provider_payload_data_keys'));
        $this->assertIsString(data_get($contract, 'contract_hash'));
        $this->assertSame(hash('sha256', AtlasMobileDevice::query()->firstOrFail()->id), data_get($audit->evidence, 'device_id_hash'));
        $this->assertFalse(data_get($audit->evidence, 'raw_device_id_persisted'));
        $this->assertArrayNotHasKey('device_id', $audit->evidence ?? []);
    }

    public function test_mobile_cli_push_test_creates_real_test_item_for_selected_device(): void
    {
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-cli-test']], 200)]);
        $this->pairedDeviceToken('ExponentPushToken[test]');
        $device = AtlasMobileDevice::query()->firstOrFail();

        $exitCode = Artisan::call('atlas:cli:mobile', [
            'action' => 'push-test',
            '--device' => $device->id,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok'] ?? false);
        $this->assertSame($device->id, $payload['device_id'] ?? null);

        $item = AiInboxItem::query()->findOrFail($payload['inbox_item_id']);
        $this->assertSame('alert', $item->type);
        $this->assertTrue((bool) data_get($item->payload, 'test'));
        $this->assertSame('none', data_get($item->push_policy, 'send'));
        $this->assertNotNull($item->context_bundle_id);

        $delivery = MobilePushDelivery::query()->firstOrFail();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame($item->id, $delivery->inbox_item_id);

        Http::assertSent(fn ($request): bool => $request['to'] === 'ExponentPushToken[test]'
            && $request['data']['inbox_id'] === $item->id
            && $request['data']['deep_link'] === 'atlas://inbox/'.$item->id);
    }

    public function test_mobile_cli_replay_push_reprocesses_requested_items_without_delivery_attempts(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        config()->set('atlas.mobile.retry.queue_enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-replay']], 200)]);
        $this->pairedDeviceToken('ExponentPushToken[test]');
        $item = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Replay push pendente',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:push-replay:test',
            'available_actions' => [['id' => 'discuss']],
            'payload' => [],
            'deep_link' => 'atlas://inbox/pending',
            'push_policy' => ['send' => 'immediate'],
            'priority_score' => 80,
        ]);

        $dryRunExit = Artisan::call('atlas:cli:mobile', [
            'action' => 'replay-push',
            '--json' => true,
        ]);
        $dryRun = json_decode(Artisan::output(), true);

        $this->assertSame(0, $dryRunExit);
        $this->assertTrue($dryRun['dry_run']);
        $this->assertSame(1, $dryRun['candidate_count']);
        $this->assertSame(0, MobilePushDelivery::query()->count());
        $dryRunReceipt = AuditEvent::query()
            ->where('event_type', 'mobile.push_replay.requested')
            ->where('actor_id', 'cli')
            ->latest()
            ->firstOrFail();
        $this->assertTrue((bool) data_get($dryRunReceipt->evidence, 'dry_run'));
        $this->assertSame(1, data_get($dryRunReceipt->evidence, 'candidate_count'));
        $this->assertFalse((bool) data_get($dryRunReceipt->privacy, 'raw_push_tokens_exposed'));

        $applyExit = Artisan::call('atlas:cli:mobile', [
            'action' => 'replay-push',
            '--apply' => true,
            '--json' => true,
        ]);
        $blocked = json_decode(Artisan::output(), true);

        $this->assertSame(1, $applyExit);
        $this->assertSame('confirmation_required', $blocked['status'] ?? null);
        $this->assertSame(0, MobilePushDelivery::query()->count());

        $applyExit = Artisan::call('atlas:cli:mobile', [
            'action' => 'replay-push',
            '--apply' => true,
            '--confirm-external-dispatch' => true,
            '--reason' => 'operator confirmed CLI replay after dry-run',
            '--json' => true,
        ]);
        $applied = json_decode(Artisan::output(), true);

        $this->assertSame(0, $applyExit);
        $this->assertFalse($applied['dry_run']);
        $this->assertSame(1, $applied['candidate_count']);
        $this->assertSame(1, $applied['dispatched_count']);
        $delivery = MobilePushDelivery::query()->firstOrFail();
        $this->assertSame($item->id, $delivery->inbox_item_id);
        $this->assertSame('sent', $delivery->status);
        $applyReceipt = AuditEvent::query()
            ->where('event_type', 'mobile.push_replay.requested')
            ->where('actor_id', 'cli')
            ->where('severity', 'warning')
            ->firstOrFail();
        $this->assertFalse((bool) data_get($applyReceipt->evidence, 'dry_run'));
        $this->assertSame(1, data_get($applyReceipt->evidence, 'dispatched_count'));
        $this->assertTrue((bool) data_get($applyReceipt->evidence, 'external_dispatch_confirmed'));
        $this->assertNotNull(data_get($applyReceipt->evidence, 'operator_reason_hash'));
    }

    public function test_mobile_cli_replay_push_apply_requires_recent_dry_run(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        config()->set('atlas.mobile.retry.queue_enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-replay-blocked']], 200)]);
        $this->pairedDeviceToken('ExponentPushToken[test]');
        AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Replay push sem dry-run',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:push-replay:no-dry-run',
            'available_actions' => [['id' => 'discuss']],
            'payload' => [],
            'deep_link' => 'atlas://inbox/no-dry-run',
            'push_policy' => ['send' => 'immediate'],
            'priority_score' => 80,
        ]);

        $applyExit = Artisan::call('atlas:cli:mobile', [
            'action' => 'replay-push',
            '--apply' => true,
            '--confirm-external-dispatch' => true,
            '--reason' => 'operator confirmed replay without dry-run',
            '--json' => true,
        ]);
        $blocked = json_decode(Artisan::output(), true);

        $this->assertSame(1, $applyExit);
        $this->assertSame('prior_dry_run_required', $blocked['status'] ?? null);
        $this->assertTrue((bool) data_get($blocked, 'rules.prior_dry_run_required'));
        $this->assertSame(0, MobilePushDelivery::query()->count());
    }

    public function test_mobile_push_replay_api_requires_confirmation_before_external_dispatch(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        config()->set('atlas.mobile.retry.queue_enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-replay-api']], 200)]);
        $this->pairedDeviceToken('ExponentPushToken[test]');
        AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Replay push via API',
            'initiator' => 'atlas',
            'dedupe_key' => 'insight:push-replay-api:test',
            'available_actions' => [['id' => 'discuss']],
            'payload' => [],
            'deep_link' => 'atlas://inbox/pending-api',
            'push_policy' => ['send' => 'immediate'],
            'priority_score' => 80,
        ]);

        $headers = ['X-Atlas-Token' => 'testing-atlas-token-with-enough-length'];

        $this->withHeader('X-Atlas-Token', 'wrong-token-with-enough-length')
            ->postJson('/ai/mobile/push/replay', ['limit' => 10])
            ->assertUnauthorized()
            ->assertJsonPath('error.message', 'Invalid or missing X-Atlas-Token.');

        $this->postJson('/ai/mobile/push/replay', ['apply' => true], $headers)
            ->assertStatus(422)
            ->assertJsonPath('status', 'confirmation_required');

        $this->postJson('/ai/mobile/push/replay', [
            'limit' => 10,
            'apply' => true,
            'confirm_external_dispatch' => true,
            'reason' => 'operator confirmed replay without prior dry-run',
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('status', 'prior_dry_run_required')
            ->assertJsonPath('rules.prior_dry_run_required', true)
            ->assertJsonPath('rules.prior_dry_run_candidate_required', true);
        $this->assertSame(0, MobilePushDelivery::query()->count());

        $this->postJson('/ai/mobile/push/replay', ['limit' => 10], $headers)
            ->assertOk()
            ->assertJsonPath('push_replay.dry_run', true)
            ->assertJsonPath('push_replay.candidate_count', 1);
        $this->assertSame(0, MobilePushDelivery::query()->count());
        $dryRunReceipt = AuditEvent::query()
            ->where('event_type', 'mobile.push_replay.requested')
            ->latest()
            ->firstOrFail();
        $this->assertTrue((bool) data_get($dryRunReceipt->evidence, 'dry_run'));
        $this->assertFalse((bool) data_get($dryRunReceipt->privacy, 'raw_push_tokens_exposed'));

        $this->postJson('/ai/mobile/push/replay', [
            'limit' => 10,
            'apply' => true,
            'confirm_external_dispatch' => true,
            'reason' => 'operator confirmed replay after delivery diagnosis',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('push_replay.dry_run', false)
            ->assertJsonPath('push_replay.dispatched_count', 1)
            ->assertJsonPath('push_replay.external_dispatch_confirmed', true);
        $this->assertSame(1, MobilePushDelivery::query()->count());
        $applyReceipt = AuditEvent::query()
            ->where('event_type', 'mobile.push_replay.requested')
            ->where('severity', 'warning')
            ->firstOrFail();
        $this->assertFalse((bool) data_get($applyReceipt->evidence, 'dry_run'));
        $this->assertSame(1, data_get($applyReceipt->evidence, 'dispatched_count'));
        $this->assertNotNull(data_get($applyReceipt->evidence, 'operator_reason_hash'));
        $this->assertTrue((bool) data_get($applyReceipt->privacy, 'operator_reason_stored_as_hash'));
    }

    public function test_push_payload_does_not_include_sensitive_inbox_body_or_summary(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-sensitive']], 200)]);
        $this->pairedDeviceToken('ExponentPushToken[test]');
        $secret = 'sk-proj-abcdefghijklmnopqrstuvwxyz1234567890';

        app(AtlasInboxService::class)->create([
            'type' => 'alert',
            'severity' => 'warning',
            'title' => 'Token '.$secret,
            'summary' => 'Resumo com '.$secret,
            'body' => 'Body com '.$secret,
            'push_policy' => ['send' => 'immediate'],
        ]);

        $payload = MobilePushDelivery::query()->firstOrFail()->request_payload;
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->assertSame('Atlas detectou um alerta importante.', $payload['body']);
        $this->assertStringNotContainsString($secret, (string) $encoded);
        $this->assertArrayNotHasKey('summary', $payload['data']);
        $this->assertArrayNotHasKey('body', $payload['data']);
        $this->assertArrayNotHasKey('title', $payload['data']);
    }

    public function test_push_dispatch_fails_closed_when_delivery_table_is_missing(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-missing-table']], 200)]);
        $this->pairedDeviceToken('ExponentPushToken[test]');
        Schema::dropIfExists('mobile_push_deliveries');

        $item = app(AtlasInboxService::class)->create([
            'type' => 'alert',
            'severity' => 'warning',
            'title' => 'Alerta preservado sem push',
            'summary' => 'Inbox deve sobreviver a infraestrutura push incompleta.',
            'push_policy' => ['send' => 'immediate'],
        ]);

        $this->assertInstanceOf(AiInboxItem::class, $item);
        $this->assertDatabaseHas('ai_inbox_items', ['id' => $item->id]);
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'push.unavailable')->count());
        $audit = AuditEvent::query()->where('event_type', 'push.unavailable')->firstOrFail();
        $this->assertSame('push_infrastructure_unavailable', data_get($audit->evidence, 'reason'));
        $this->assertSame(['mobile_push_deliveries'], data_get($audit->evidence, 'missing_tables'));
        Http::assertSentCount(0);
    }

    public function test_telemetry_health_push_uses_specific_operational_copy(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-health']], 200)]);
        $this->pairedDeviceToken('ExponentPushToken[test]');

        $item = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'category' => 'atlas',
            'severity' => 'critical',
            'title' => 'Atlas precisa de revisao operacional',
            'summary' => 'Telemetry health critical; score 0/100.',
            'body' => 'Payload tecnico nao deve ir para o push.',
            'payload' => [
                'insight_kind' => 'atlas_ai_telemetry_health',
                'health' => [
                    'status' => 'critical',
                    'health_score' => 0,
                ],
            ],
            'push_policy' => ['send' => 'immediate'],
        ]);

        $payload = MobilePushDelivery::query()->firstOrFail()->request_payload;

        $this->assertSame('Atlas precisa de revisao', $payload['title']);
        $this->assertSame('Saude do Atlas esta critica: score 0/100. Toque para ver causas e proximos passos.', $payload['body']);
        $this->assertSame('atlas://inbox/'.$item->id.'/discuss', $payload['data']['deep_link']);
        $this->assertSame('discuss', $payload['data']['open_action']);
        $this->assertSame('operational', $payload['data']['atlas_mode']);
        $this->assertSame('atlas_ai', $payload['data']['target']);
        $this->assertStringNotContainsString('Payload tecnico', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    public function test_insight_emitter_can_create_non_interruptive_operational_item_without_push(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-health']], 200)]);
        $this->pairedDeviceToken('ExponentPushToken[test]');

        $item = app(InsightInboxEmitter::class)->emit([
            'title' => 'Atlas precisa de revisao operacional',
            'summary' => 'Saude critica: score 0/100, amostra pequena.',
            'body' => 'Diagnostico importante, mas nao interruptivo.',
            'category' => 'atlas',
            'insight_kind' => 'atlas_ai_telemetry_health',
            'severity' => 'critical',
            'dedupe_key' => 'insight:atlas-ai-telemetry-health:test:critical',
            'push_policy' => [
                'send' => 'none',
                'reason' => 'telemetry_health_daily_digest',
            ],
        ]);

        $this->assertInstanceOf(AiInboxItem::class, $item);
        $this->assertSame('none', data_get($item->push_policy, 'send'));
        $this->assertSame('telemetry_health_daily_digest', data_get($item->push_policy, 'reason'));
        $this->assertDatabaseCount('mobile_push_deliveries', 0);
        Http::assertSentCount(0);
    }

    public function test_push_dispatch_dedupes_devices_with_same_expo_token(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-deduped']], 200)]);
        $this->pairedDeviceToken('ExponentPushToken[same]');

        AtlasMobileDevice::query()->create([
            'user_id' => 'vitor',
            'device_label' => 'iPhone Test Duplicate',
            'platform' => 'ios',
            'expo_push_token' => 'ExponentPushToken[same]',
            'device_token_hash' => 'duplicate-device-token-hash',
            'notification_permissions' => 'granted',
            'last_seen_at' => now()->addMinute(),
            'paired_at' => now(),
        ]);

        app(AtlasInboxService::class)->create([
            'type' => 'alert',
            'severity' => 'critical',
            'title' => 'Alerta unico',
            'push_policy' => ['send' => 'immediate'],
        ]);

        $this->assertDatabaseCount('mobile_push_deliveries', 1);
        Http::assertSentCount(1);
    }

    public function test_disabled_critical_push_preference_blocks_push_dispatch(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-disabled']], 200)]);
        $token = $this->pairedDeviceToken('ExponentPushToken[test]');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/devices/notification-preferences', [
                'critical_push_enabled' => false,
            ])
            ->assertOk();

        app(AtlasInboxService::class)->create([
            'type' => 'alert',
            'severity' => 'critical',
            'title' => 'Alerta silenciado',
            'push_policy' => ['send' => 'immediate', 'force' => true],
        ]);

        $this->assertDatabaseCount('mobile_push_deliveries', 0);
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'push.skipped')->count());
        $skipAudit = AuditEvent::query()->where('event_type', 'push.skipped')->first();
        $this->assertSame('critical_push_disabled', data_get($skipAudit?->evidence, 'reason'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($skipAudit?->evidence, 'device_id_hash'));
        $this->assertFalse(data_get($skipAudit?->evidence, 'raw_device_id_persisted'));
        $this->assertArrayNotHasKey('device_id', $skipAudit?->evidence ?? []);
        Http::assertSentCount(0);
    }

    public function test_disabled_telemetry_health_preference_blocks_health_push(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-health-disabled']], 200)]);
        $token = $this->pairedDeviceToken('ExponentPushToken[test]');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/devices/notification-preferences', [
                'telemetry_health_push_enabled' => false,
            ])
            ->assertOk();

        app(InsightInboxEmitter::class)->emit([
            'title' => 'Atlas precisa de revisao',
            'summary' => 'Saude critica.',
            'body' => 'Saude critica.',
            'category' => 'atlas',
            'insight_kind' => 'atlas_ai_telemetry_health',
            'severity' => 'critical',
            'dedupe_key' => 'insight:atlas-ai-telemetry-health:disabled:critical',
            'push_policy' => ['send' => 'immediate', 'force' => true],
            'payload' => ['health' => ['status' => 'critical', 'health_score' => 0]],
        ]);

        $this->assertDatabaseCount('mobile_push_deliveries', 0);
        $this->assertSame('telemetry_health_push_disabled', data_get(AuditEvent::query()->where('event_type', 'push.skipped')->first()?->evidence, 'reason'));
        Http::assertSentCount(0);
    }

    public function test_presence_eclipse_preferences_block_non_critical_proactive_push(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-eclipse']], 200)]);
        $token = $this->pairedDeviceToken('ExponentPushToken[test]');

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/v1/mobile/devices/notification-preferences', [
                'proactive_push_enabled' => false,
                'manual_eclipse_enabled' => true,
            ])
            ->assertOk()
            ->assertJsonPath('device.notification_preferences.proactive_push_enabled', false)
            ->assertJsonPath('device.notification_preferences.manual_eclipse_enabled', true);

        app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'severity' => 'warning',
            'title' => 'Insight nao interruptivo',
            'summary' => 'Deve ficar so no Inbox durante eclipse.',
            'push_policy' => ['send' => 'immediate'],
        ]);

        $this->assertDatabaseCount('mobile_push_deliveries', 0);
        $audit = AuditEvent::query()->where('event_type', 'push.skipped')->firstOrFail();
        $this->assertSame('manual_eclipse_active', data_get($audit->evidence, 'reason'));
        Http::assertSentCount(0);
    }

    public function test_quiet_hours_defer_non_critical_push_and_critical_bypasses(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.quiet_hours.enabled', true);
        config()->set('atlas.mobile.quiet_hours.start', '00:00');
        config()->set('atlas.mobile.quiet_hours.end', '23:59');
        config()->set('atlas.mobile.quiet_hours.severity_threshold', 'critical');
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-quiet']], 200)]);
        $this->pairedDeviceToken('ExponentPushToken[test]');

        app(AtlasInboxService::class)->create([
            'type' => 'alert',
            'severity' => 'info',
            'title' => 'Nao urgente',
            'push_policy' => ['send' => 'immediate'],
        ]);

        Http::assertSentCount(0);
        $this->assertSame('deferred_quiet_hours', MobilePushDelivery::query()->firstOrFail()->status);

        app(AtlasInboxService::class)->create([
            'type' => 'alert',
            'severity' => 'critical',
            'title' => 'Critico',
            'push_policy' => ['send' => 'immediate'],
        ]);

        Http::assertSentCount(1);
        $this->assertSame(1, MobilePushDelivery::query()->where('status', 'sent')->count());
    }

    public function test_batching_groups_non_critical_updates_until_flush(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.batching.enabled', true);
        config()->set('atlas.mobile.quiet_hours.enabled', false);
        Http::fake(['*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-batch']], 200)]);
        $this->pairedDeviceToken('ExponentPushToken[test]');

        $first = app(AtlasInboxService::class)->create([
            'type' => 'insight',
            'severity' => 'info',
            'title' => 'Insight 1',
        ]);
        $second = app(AtlasInboxService::class)->create([
            'type' => 'job_result',
            'severity' => 'info',
            'title' => 'Job 1',
        ]);

        Http::assertSentCount(0);
        $this->assertSame(['batched', 'batched'], MobilePushDelivery::query()->pluck('status')->all());

        $sent = app(MobilePushService::class)->flushBatched();

        $this->assertSame(1, $sent);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['data']['type'] === 'batch'
            && $request['data']['deep_link'] === 'atlas://inbox'
            && $request['data']['inbox_ids'] === [$first->id, $second->id]);
        $this->assertSame(['sent', 'sent'], MobilePushDelivery::query()->pluck('status')->all());
    }

    public function test_push_receipts_mark_success_and_permanent_errors(): void
    {
        $this->pairedDeviceToken('ExponentPushToken[test]');
        $device = AtlasMobileDevice::query()->firstOrFail();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'alert',
            'title' => 'Receipt test',
            'push_policy' => ['send' => 'none'],
        ]);

        MobilePushDelivery::query()->create([
            'inbox_item_id' => $item->id,
            'device_id' => $device->id,
            'status' => 'sent',
            'provider' => 'expo',
            'provider_ticket_id' => 'ticket-ok',
            'request_payload' => [],
            'response_payload' => [],
            'attempted_at' => now(),
        ]);
        MobilePushDelivery::query()->create([
            'inbox_item_id' => $item->id,
            'device_id' => $device->id,
            'status' => 'sent',
            'provider' => 'expo',
            'provider_ticket_id' => 'ticket-error',
            'request_payload' => [],
            'response_payload' => [],
            'attempted_at' => now(),
        ]);

        Http::fake([
            'https://exp.host/--/api/v2/push/getReceipts' => Http::response([
                'data' => [
                    'ticket-ok' => ['status' => 'ok'],
                    'ticket-error' => [
                        'status' => 'error',
                        'message' => 'Device not registered',
                        'details' => ['error' => 'DeviceNotRegistered'],
                    ],
                ],
            ], 200),
        ]);

        $result = app(MobilePushService::class)->fetchReceipts();

        $this->assertSame(2, $result['checked']);
        $this->assertSame(1, $result['receipt_ok']);
        $this->assertSame(1, $result['receipt_error']);
        $this->assertSame('receipt_ok', MobilePushDelivery::query()->where('provider_ticket_id', 'ticket-ok')->value('status'));
        $this->assertSame('receipt_error', MobilePushDelivery::query()->where('provider_ticket_id', 'ticket-error')->value('status'));
        $this->assertNull($device->refresh()->expo_push_token);
    }

    public function test_important_job_result_emits_inbox_item_with_context_and_dedupe(): void
    {
        $job = AiJob::query()->create([
            'kind' => 'daily_briefing',
            'status' => 'succeeded',
            'priority' => 10,
            'agent_slug' => 'atlas',
            'provider' => 'claude_cli',
            'model' => 'test-model',
            'input_text' => 'Rodar briefing importante.',
            'prompt' => 'Prompt seguro',
            'context_refs' => [],
            'payload' => ['importance' => 'high'],
            'result_text' => 'Briefing terminou com 3 achados importantes.',
            'result_json' => ['summary' => 'Briefing pronto.'],
            'started_at' => now()->subSeconds(3),
            'finished_at' => now(),
            'attempts' => 1,
            'max_attempts' => 1,
            'metadata' => [],
        ]);

        $item = app(JobResultInboxEmitter::class)->emitIfImportant($job, 'succeeded');

        $this->assertInstanceOf(AiInboxItem::class, $item);
        $this->assertSame('job_result', $item->type);
        $this->assertSame('ai_job', $item->source_type);
        $this->assertSame($job->id, $item->source_id);
        $this->assertSame('job_result:'.$job->id, $item->dedupe_key);
        $this->assertSame('high', $item->payload['importance']);
        $this->assertContains('discuss', collect($item->available_actions)->pluck('id')->all());
        $this->assertDatabaseCount('ai_inbox_items', 1);
        $this->assertDatabaseCount('ai_context_bundles', 1);

        $again = app(JobResultInboxEmitter::class)->emitIfImportant($job, 'succeeded');

        $this->assertSame($item->id, $again?->id);
        $this->assertDatabaseCount('ai_inbox_items', 1);
        $this->assertDatabaseCount('ai_context_bundles', 1);

        $ordinary = AiJob::query()->create([
            'kind' => 'routine_check',
            'status' => 'succeeded',
            'priority' => 50,
            'prompt' => 'Prompt comum',
            'context_refs' => [],
            'payload' => ['importance' => 'normal'],
            'result_text' => 'Ok.',
            'attempts' => 1,
            'max_attempts' => 1,
            'metadata' => [],
        ]);

        $this->assertNull(app(JobResultInboxEmitter::class)->emitIfImportant($ordinary, 'succeeded'));
        $this->assertDatabaseCount('ai_inbox_items', 1);
    }

    public function test_self_diagnostic_emits_only_on_confirmed_quality_regression(): void
    {
        config()->set('atlas.mobile.self_diagnostic.min_recent_samples', 3);
        config()->set('atlas.mobile.self_diagnostic.min_baseline_samples', 4);
        config()->set('atlas.mobile.self_diagnostic.score_drop_threshold', 12);

        $this->seedQualityRegression();

        $result = app(SelfDiagnosticEmitter::class)->run();

        $this->assertTrue($result['emitted']);
        $this->assertSame('emitted', $result['reason']);
        $item = AiInboxItem::query()->findOrFail($result['item_id']);
        $this->assertSame('self_diagnostic', $item->type);
        $this->assertSame('quality_score_regression', $item->category);
        $this->assertSame('atlas', $item->initiator);
        $this->assertSame('quality_score_regression', $item->payload['category']);
        $this->assertContains('discuss', collect($item->available_actions)->pluck('id')->all());
        $this->assertContains('create_proposal', collect($item->available_actions)->pluck('id')->all());
        $this->assertDatabaseCount('ai_context_bundles', 1);

        $again = app(SelfDiagnosticEmitter::class)->run();
        $this->assertFalse($again['emitted']);
        $this->assertSame('deduped_active_item', $again['reason']);
        $this->assertSame($item->id, $again['item_id']);
        $this->assertDatabaseCount('ai_inbox_items', 1);

        app(InboxActionRegistry::class)->handle($item->refresh(), 'ignore_30d', [], 'ignore-self-diagnostic-test');
        $ignored = app(SelfDiagnosticEmitter::class)->run();
        $this->assertFalse($ignored['emitted']);
        $this->assertSame('ignored_30d_active', $ignored['reason']);
        $this->assertSame($item->id, $ignored['item_id']);
        $this->assertNotNull($ignored['metrics']['ignored_until'] ?? null);
        $this->assertDatabaseCount('ai_inbox_items', 1);
    }

    public function test_self_diagnostic_respects_confidence_threshold(): void
    {
        config()->set('atlas.mobile.self_diagnostic.min_recent_samples', 3);
        config()->set('atlas.mobile.self_diagnostic.min_baseline_samples', 4);
        config()->set('atlas.mobile.self_diagnostic.score_drop_threshold', 12);
        config()->set('atlas.mobile.self_diagnostic.confidence_threshold', 0.96);

        $this->seedQualityRegression();

        $result = app(SelfDiagnosticEmitter::class)->run();

        $this->assertFalse($result['emitted']);
        $this->assertSame('below_confidence_threshold', $result['reason']);
        $this->assertDatabaseCount('ai_inbox_items', 0);
        $this->assertDatabaseCount('ai_context_bundles', 0);
    }

    public function test_self_diagnostic_create_proposal_action_creates_safe_proposal(): void
    {
        $diagnostic = app(AtlasInboxService::class)->create([
            'type' => 'self_diagnostic',
            'category' => 'quality_score_regression',
            'severity' => 'warning',
            'title' => 'Qualidade em modo dev caiu',
            'summary' => 'Score caiu de 84 para 70.',
            'body' => 'O Atlas detectou regressao operacional.',
            'initiator' => 'atlas',
            'dedupe_key' => 'self_diagnostic:test-create-proposal',
            'confidence_score' => 0.81,
            'payload' => [
                'observation' => 'Score medio caiu no periodo recente.',
                'hypothesis' => 'Contexto excessivo em skills de dev pode estar reduzindo foco.',
                'proposed_fix' => 'Ajustar ativacao da skill dev-quality-gate e medir nova janela.',
            ],
        ]);

        $result = app(InboxActionRegistry::class)->handle($diagnostic, 'create_proposal', [], 'diagnostic-proposal-test');

        $this->assertTrue($result['ok']);
        $proposalId = $result['result']['proposal_item_id'] ?? null;
        $this->assertIsString($proposalId);

        $proposal = AiInboxItem::query()->findOrFail($proposalId);
        $this->assertSame('proposal', $proposal->type);
        $this->assertSame('self_diagnostic', $proposal->category);
        $this->assertSame('ai_inbox_item', $proposal->source_type);
        $this->assertSame($diagnostic->id, $proposal->source_id);
        $this->assertFalse($proposal->payload['policy']['auto_commit']);
        $this->assertNotContains('commit', collect($proposal->available_actions)->pluck('id')->all());
        $this->assertSame($proposal->id, $diagnostic->refresh()->payload['proposal_inbox_item_id']);
        $this->assertSame('create_proposal', $diagnostic->response['action']);

        $again = app(InboxActionRegistry::class)->handle($diagnostic->refresh(), 'create_proposal', [], 'diagnostic-proposal-test');

        $this->assertTrue($again['idempotent']);
        $this->assertSame($proposal->id, $again['result']['proposal_item_id']);
        $this->assertDatabaseCount('ai_inbox_items', 2);
        $this->assertDatabaseCount('ai_context_bundles', 1);
    }

    public function test_insight_emitter_creates_contextual_inbox_item_and_dedupes(): void
    {
        $item = app(InsightInboxEmitter::class)->emit([
            'title' => 'Metrica de sono mudou',
            'summary' => 'Sono profundo caiu nos ultimos dias.',
            'body' => 'O Atlas detectou queda consistente e sugere discutir ajustes.',
            'category' => 'saude',
            'severity' => 'warning',
            'dedupe_key' => 'insight:saude:sono-profundo',
            'metric_refs' => [['name' => 'deep_sleep_minutes_7d', 'value' => 42]],
            'confidence' => 0.82,
        ]);

        $this->assertInstanceOf(AiInboxItem::class, $item);
        $this->assertSame('insight', $item->type);
        $this->assertSame('saude', $item->category);
        $this->assertSame('warning', $item->severity);
        $this->assertSame('atlas', $item->initiator);
        $this->assertSame('insight:saude:sono-profundo', $item->dedupe_key);
        $this->assertContains('discuss', collect($item->available_actions)->pluck('id')->all());
        $this->assertDatabaseCount('ai_context_bundles', 1);

        $again = app(InsightInboxEmitter::class)->emit([
            'title' => 'Metrica de sono mudou',
            'summary' => 'Sono profundo continua menor.',
            'category' => 'saude',
            'dedupe_key' => 'insight:saude:sono-profundo',
        ]);

        $this->assertSame($item->id, $again?->id);
        $this->assertDatabaseCount('ai_inbox_items', 1);
        $this->assertDatabaseCount('ai_context_bundles', 1);
    }

    public function test_insight_watcher_emits_health_readiness_drop_with_context_and_dedupe(): void
    {
        config()->set('atlas.mobile.insight_watch.min_confidence', 0.65);
        config()->set('atlas.mobile.insight_watch.health_baseline_days', 10);
        config()->set('atlas.mobile.insight_watch.health_min_baseline_samples', 3);
        config()->set('atlas.mobile.insight_watch.health_readiness_drop_threshold', 15);

        foreach (range(5, 1) as $daysAgo) {
            HealthSnapshot::query()->create([
                'client_id' => (string) Str::uuid(),
                'source' => 'atlas_app',
                'snapshot_date' => now()->subDays($daysAgo)->toDateString(),
                'snapshot_timezone' => 'America/Sao_Paulo',
                'computed_at' => now()->subDays($daysAgo),
                'signal_count' => 8,
                'readiness_score' => 84,
                'sleep_duration_hours' => 7.2,
                'confidence' => 0.9,
            ]);
        }

        HealthSnapshot::query()->create([
            'client_id' => (string) Str::uuid(),
            'source' => 'atlas_app',
            'snapshot_date' => now()->toDateString(),
            'snapshot_timezone' => 'America/Sao_Paulo',
            'computed_at' => now(),
            'signal_count' => 8,
            'readiness_score' => 55,
            'sleep_duration_hours' => 5.4,
            'confidence' => 0.9,
        ]);

        $result = app(InsightWatcherService::class)->run();

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['dry_run']);
        $this->assertCount(1, $result['candidates']);
        $this->assertSame(1, $result['emitted_count']);

        $run = AtlasInitiativeRun::query()->where('kind', 'insight_watch')->firstOrFail();
        $item = AiInboxItem::query()->where('type', 'insight')->firstOrFail();

        $this->assertSame('succeeded', $run->status);
        $this->assertSame([$item->id], $run->emitted_inbox_item_ids);
        $this->assertSame('saude', $item->category);
        $this->assertSame('health_readiness_drop', $item->payload['insight_kind']);
        $this->assertSame('atlas_initiative_run', $item->source_type);
        $this->assertSame($run->id, $item->source_id);
        $this->assertContains('discuss', collect($item->available_actions)->pluck('id')->all());
        $this->assertDatabaseCount('ai_context_bundles', 1);

        $again = app(InsightWatcherService::class)->run();

        $this->assertSame($item->id, $again['emitted_item_ids'][0] ?? null);
        $this->assertDatabaseCount('atlas_initiative_runs', 2);
        $this->assertDatabaseCount('ai_inbox_items', 1);
        $this->assertDatabaseCount('ai_context_bundles', 1);
    }

    public function test_proposal_emitter_creates_safe_review_item_without_commit_action(): void
    {
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();

        $item = app(ProposalInboxEmitter::class)->emit([
            'title' => 'Refatorar MobilePushService',
            'finding' => 'Batching e quiet hours estao no mesmo metodo.',
            'problem' => 'A logica esta crescendo e pode ficar dificil de testar.',
            'solution' => 'Extrair decision policy para classe dedicada mantendo contrato atual.',
            'worth_it' => 'Vale porque reduz risco nos proximos ajustes de receipts.',
            'branch' => 'atlas/proposal/mobile-push-policy',
            'dedupe_key' => 'proposal:mobile-push-policy',
            'diff_refs' => [['path' => 'app/Services/Ai/Mobile/MobilePushService.php']],
            'confidence' => 0.78,
        ]);

        $this->assertInstanceOf(AiInboxItem::class, $item);
        $this->assertSame('proposal', $item->type);
        $this->assertSame('atlas', $item->initiator);
        $this->assertSame('atlas/proposal/mobile-push-policy', $item->payload['branch']);
        $this->assertFalse($item->payload['policy']['auto_commit']);
        $this->assertFalse($item->payload['policy']['auto_merge']);
        $actions = collect($item->available_actions)->pluck('id')->all();
        $this->assertContains('review_patch', $actions);
        $this->assertContains('discuss', $actions);
        $this->assertContains('discard', $actions);
        $this->assertNotContains('commit', $actions);
        $this->assertDatabaseCount('ai_context_bundles', 1);

        $review = app(InboxActionRegistry::class)->handle($item->refresh(), 'review_patch', [], 'review-proposal-mobile-push-policy');

        $this->assertSame('review_patch', data_get($review, 'result.payload.action'));
        $this->assertSame('app/Services/Ai/Mobile/MobilePushService.php', data_get($review, 'result.payload.diff_refs.0.path'));
        $this->assertSame('app/Services/Ai/Mobile/MobilePushService.php', data_get($review, 'result.payload.proposal_contract.diff_refs.0.path'));
        $this->assertSame('proposal:mobile-push-policy', data_get($review, 'item.dedupe_key'));
        $ledgerEvent = AtlasLedgerEvent::query()
            ->where('event_type', LedgerEventType::InboxActionRecorded->value)
            ->firstOrFail();
        $this->assertSame('atlas.inbox_action.v1', data_get($ledgerEvent->payload, 'schema_version'));
        $this->assertSame('review_patch', data_get($ledgerEvent->payload, 'action'));
        $this->assertSame($item->id, data_get($ledgerEvent->payload, 'inbox_item.id'));
        $this->assertSame('operator_cli', data_get($ledgerEvent->payload, 'actor.type'));
        $this->assertSame('app/Services/Ai/Mobile/MobilePushService.php', data_get($ledgerEvent->payload, 'result.payload.diff_refs.0.path'));

        $again = app(ProposalInboxEmitter::class)->emit([
            'title' => 'Refatorar MobilePushService',
            'problem' => 'Duplicado',
            'solution' => 'Duplicado',
            'dedupe_key' => 'proposal:mobile-push-policy',
        ]);

        $this->assertSame($item->id, $again?->id);
        $this->assertDatabaseCount('ai_inbox_items', 1);
        $this->assertDatabaseCount('ai_context_bundles', 1);
    }

    public function test_mobile_inbox_can_approve_parked_loop_proposal_through_governed_queue(): void
    {
        $this->bootLoopRuntimeTables();
        config(['atlas.ai.loop.value_gate_enabled' => false]);
        $token = $this->pairedDeviceToken();
        $target = 'app/Support/MobileLoopProbe.php';
        $original = "<?php\nfunction mobile_loop_probe(){ return 1; }\n";
        $modified = "<?php\nfunction mobile_loop_probe(){ return 2; }\n";
        $repo = $this->loopReviewRepo($target, $original);
        $this->authorizeLoopRepoForGovernedMerge($repo);

        try {
            $campaign = AtlasLoopCampaign::query()->create([
                'schema_version' => 'atlas.loop.campaign.v1',
                'status' => AtlasLoopCampaign::STATUS_RUNNING,
                'goal' => 'l5-12-mobile-operator-review-test',
                'base_workspace' => $repo,
                'config' => [],
                'max_seconds' => 3600,
            ]);
            $proposal = AtlasLoopProposal::query()->create([
                'campaign_id' => $campaign->id,
                'schema_version' => 'atlas.loop.proposal.v1',
                'status' => AtlasLoopProposal::STATUS_CERTIFIED,
                'objective' => 'mobile operator approves parked loop proposal',
                'target_path' => $target,
                'diff_text' => $this->loopReviewDiff($repo, $target, $modified),
                'proposal_hash' => 'l5-12-mobile-approve-'.bin2hex(random_bytes(4)),
                'metric' => null,
                'quality' => [
                    '_operator_review' => [
                        'schema_version' => 'atlas.loop.operator_review.v1',
                        'status' => 'parked_for_operator_review',
                        'reason' => 'operator_mobile_review_fixture',
                        'operator_id' => 'auto_merge',
                        'reviewed_at' => now()->toIso8601String(),
                        'decision' => 'park',
                    ],
                    '_acceptance_contract' => [
                        'commands' => ["/opt/homebrew/bin/php -r \"require '{$target}'; exit(mobile_loop_probe()===2?0:1);\""],
                        'allowed_globs' => [$target],
                        'frozen_globs' => ['composer.json'],
                        'metric_kind' => 'mobile_loop_review',
                    ],
                ],
                'reviewed_at' => now(),
            ]);

            $item = app(ProposalInboxEmitter::class)->emit([
                'title' => 'Revisar proposta parked do Loop',
                'problem' => 'Uma proposta certificada esta parked aguardando revisao humana.',
                'solution' => 'Aprovar pelo mobile usando a fila governada existente do Loop.',
                'worth_it' => 'Prova o caminho telefone -> inbox -> queue governada sem bypass.',
                'category' => 'loop_operator_review',
                'source_type' => 'atlas_loop_proposal',
                'source_id' => (string) $proposal->getKey(),
                'dedupe_key' => 'loop-operator-review:'.$proposal->proposal_hash,
                'diff_refs' => [['path' => $target]],
                'payload' => [
                    'loop_operator_review' => [
                        'schema_version' => 'atlas.mobile.loop_operator_review.v1',
                        'proposal_ref' => (string) $proposal->getKey(),
                        'proposal_hash' => (string) $proposal->proposal_hash,
                        'base_path' => $repo,
                    ],
                ],
                'available_actions' => [
                    ['id' => 'loop_operator_review_approve', 'label' => 'Aprovar', 'style' => 'primary'],
                    ['id' => 'loop_operator_review_reject', 'label' => 'Rejeitar', 'style' => 'destructive'],
                ],
            ]);
            $this->assertNotNull($item);

            $this
                ->withHeader('Authorization', 'Bearer '.$token)
                ->withHeader('Idempotency-Key', 'l5-12-mobile-loop-approve')
                ->postJson('/v1/mobile/inbox/'.$item->id.'/respond', [
                    'action' => 'loop_operator_review_approve',
                    'data' => ['reason' => 'approved from mobile fixture'],
                ])
                ->assertOk()
                ->assertJsonPath('item.status', 'resolved')
                ->assertJsonPath('item.response.action', 'loop_operator_review_approve')
                ->assertJsonPath('result.payload.loop_operator_review.decision_status', 'merged')
                ->assertJsonPath('result.payload.loop_operator_review.resolved', true);

            $this->assertSame($modified, File::get($repo.'/'.$target));
            $this->assertTrue((bool) $proposal->fresh()->merged_to_main);
            $this->assertSame('merged', $proposal->fresh()->quality['_operator_review']['status']);
            $this->assertStringContainsString('atlas loop auto-merge', $this->loopReviewGit($repo, ['log', '-1', '--pretty=%s']));
            $this->assertNotContains('commit', collect($item->available_actions)->pluck('id')->all());
            $this->assertNotContains('merge', collect($item->available_actions)->pluck('id')->all());
        } finally {
            File::deleteDirectory($repo);
        }
    }

    public function test_publisher_bridges_governed_queue_to_mobile_inbox_and_operator_approves_end_to_end(): void
    {
        $this->bootLoopRuntimeTables();
        config(['atlas.ai.loop.value_gate_enabled' => false]);
        $token = $this->pairedDeviceToken();
        $target = 'app/Support/MobileLoopPublishProbe.php';
        $original = "<?php\nfunction mobile_loop_publish_probe(){ return 1; }\n";
        $modified = "<?php\nfunction mobile_loop_publish_probe(){ return 2; }\n";
        $repo = $this->loopReviewRepo($target, $original);
        $this->authorizeLoopRepoForGovernedMerge($repo);

        try {
            $campaign = AtlasLoopCampaign::query()->create([
                'schema_version' => 'atlas.loop.campaign.v1',
                'status' => AtlasLoopCampaign::STATUS_RUNNING,
                'goal' => 'l5-12-publisher-approve-test',
                'base_workspace' => $repo,
                'config' => [],
                'max_seconds' => 3600,
            ]);
            $proposal = AtlasLoopProposal::query()->create([
                'campaign_id' => $campaign->id,
                'schema_version' => 'atlas.loop.proposal.v1',
                'status' => AtlasLoopProposal::STATUS_CERTIFIED,
                'objective' => 'publisher bridges parked proposal to mobile then operator approves',
                'target_path' => $target,
                'diff_text' => $this->loopReviewDiff($repo, $target, $modified),
                'proposal_hash' => 'l5-12-publish-approve-'.bin2hex(random_bytes(4)),
                'metric' => null,
                'quality' => [
                    '_operator_review' => [
                        'schema_version' => 'atlas.loop.operator_review.v1',
                        'status' => 'parked_for_operator_review',
                        'reason' => 'forbidden_self_target',
                        'operator_id' => 'auto_merge',
                        'reviewed_at' => now()->toIso8601String(),
                        'decision' => 'park',
                    ],
                    '_acceptance_contract' => [
                        'commands' => ["/opt/homebrew/bin/php -r \"require '{$target}'; exit(mobile_loop_publish_probe()===2?0:1);\""],
                        'allowed_globs' => [$target],
                        'frozen_globs' => ['composer.json'],
                        'metric_kind' => 'mobile_loop_review',
                    ],
                ],
                'reviewed_at' => now(),
            ]);

            // Keystone under test: the governed queue becomes a mobile inbox item via the
            // PRODUCTION publisher (not a hand-crafted fixture item).
            $publish = app(AtlasLoopOperatorReviewMobilePublisher::class)->publish(10);
            $this->assertSame('ok', $publish['status']);
            $this->assertSame(1, $publish['published_count']);
            $publishedRef = $publish['published'][0]['proposal_ref'];
            $this->assertSame((string) $proposal->getKey(), $publishedRef);

            $item = AiInboxItem::query()->findOrFail($publish['published'][0]['inbox_item_id']);
            $this->assertSame('proposal', $item->type);
            $actionIds = collect($item->available_actions)->pluck('id')->all();
            $this->assertContains('loop_operator_review_approve', $actionIds);
            $this->assertContains('loop_operator_review_reject', $actionIds);
            // never-merge: the bridge NEVER ships a code action id.
            $this->assertNotContains('commit', $actionIds);
            $this->assertNotContains('merge', $actionIds);
            $this->assertNotContains('apply_patch', $actionIds);

            // Idempotent: re-publishing the same parked proposal does not duplicate.
            $republish = app(AtlasLoopOperatorReviewMobilePublisher::class)->publish(10);
            $this->assertSame(1, $republish['published_count']);
            $this->assertSame($item->id, $republish['published'][0]['inbox_item_id']);
            $this->assertSame(1, AiInboxItem::query()->where('type', 'proposal')->count());

            // Operator taps Approve on the phone -> governed queue -> re-proof -> real merge.
            $this
                ->withHeader('Authorization', 'Bearer '.$token)
                ->withHeader('Idempotency-Key', 'l5-12-publish-approve')
                ->postJson('/v1/mobile/inbox/'.$item->id.'/respond', [
                    'action' => 'loop_operator_review_approve',
                    'data' => ['reason' => 'approved from published mobile item'],
                ])
                ->assertOk()
                ->assertJsonPath('item.status', 'resolved')
                ->assertJsonPath('item.response.action', 'loop_operator_review_approve')
                ->assertJsonPath('result.payload.loop_operator_review.decision_status', 'merged')
                ->assertJsonPath('result.payload.loop_operator_review.resolved', true);

            $this->assertSame($modified, File::get($repo.'/'.$target));
            $this->assertTrue((bool) $proposal->fresh()->merged_to_main);
            $this->assertSame('merged', $proposal->fresh()->quality['_operator_review']['status']);
            $this->assertStringContainsString('atlas loop auto-merge', $this->loopReviewGit($repo, ['log', '-1', '--pretty=%s']));
        } finally {
            File::deleteDirectory($repo);
        }
    }

    public function test_publisher_bridges_governed_queue_and_operator_rejects_end_to_end(): void
    {
        $this->bootLoopRuntimeTables();
        config(['atlas.ai.loop.value_gate_enabled' => false]);
        $token = $this->pairedDeviceToken();
        $target = 'app/Services/Ai/AutonomousEvolution/AtlasLoopProposalPromotionGate.php';
        $repo = $this->loopReviewRepo($target, "<?php\n// frozen judge target\n");

        try {
            $campaign = AtlasLoopCampaign::query()->create([
                'schema_version' => 'atlas.loop.campaign.v1',
                'status' => AtlasLoopCampaign::STATUS_RUNNING,
                'goal' => 'l5-12-publisher-reject-test',
                'base_workspace' => $repo,
                'config' => [],
                'max_seconds' => 3600,
            ]);
            $proposal = AtlasLoopProposal::query()->create([
                'campaign_id' => $campaign->id,
                'schema_version' => 'atlas.loop.proposal.v1',
                'status' => AtlasLoopProposal::STATUS_CERTIFIED,
                'objective' => 'forbidden self-target proposal parked for operator',
                'target_path' => $target,
                'diff_text' => "diff --git a/{$target} b/{$target}\n",
                'proposal_hash' => 'l5-12-publish-reject-'.bin2hex(random_bytes(4)),
                'metric' => null,
                'quality' => [
                    '_operator_review' => [
                        'schema_version' => 'atlas.loop.operator_review.v1',
                        'status' => 'parked_for_operator_review',
                        'reason' => 'forbidden_self_target',
                        'operator_id' => 'auto_merge',
                        'reviewed_at' => now()->toIso8601String(),
                        'decision' => 'park',
                    ],
                ],
                'reviewed_at' => now(),
            ]);

            $publish = app(AtlasLoopOperatorReviewMobilePublisher::class)->publish(10);
            $this->assertSame(1, $publish['published_count']);
            $item = AiInboxItem::query()->findOrFail($publish['published'][0]['inbox_item_id']);

            $this
                ->withHeader('Authorization', 'Bearer '.$token)
                ->withHeader('Idempotency-Key', 'l5-12-publish-reject')
                ->postJson('/v1/mobile/inbox/'.$item->id.'/respond', [
                    'action' => 'loop_operator_review_reject',
                    'data' => ['reason' => 'rejected from published mobile item'],
                ])
                ->assertOk()
                ->assertJsonPath('item.status', 'resolved')
                ->assertJsonPath('item.response.action', 'loop_operator_review_reject')
                ->assertJsonPath('result.payload.loop_operator_review.decision_status', 'rejected')
                ->assertJsonPath('result.payload.loop_operator_review.resolved', true);

            // never-merge: a reject NEVER touches main and quarantines the target.
            $fresh = $proposal->fresh();
            $this->assertFalse((bool) $fresh->merged_to_main);
            $this->assertSame('rejected', $fresh->quality['_operator_review']['status']);
            // Once rejected, the proposal leaves the governed queue, so re-publish is empty.
            $republish = app(AtlasLoopOperatorReviewMobilePublisher::class)->publish(10);
            $this->assertSame(0, $republish['published_count']);
        } finally {
            File::deleteDirectory($repo);
        }
    }

    public function test_auto_improvement_proposal_scanner_emits_marker_findings_and_records_run(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-proposal-scan-'.Str::uuid();

        try {
            File::ensureDirectoryExists($workspace.'/app/Services');
            $marker = 'ATLAS'.'_PROPOSAL: Extrair validacao de payload para reduzir duplicacao antes de novas actions mobile.';

            File::put($workspace.'/app/Services/FooService.php', <<<PHP
<?php

namespace App\Services;

// {$marker}
class FooService
{
    public function handle(): void
    {
    }
}
PHP);

            $result = app(AutoImprovementProposalScanner::class)->scan($workspace, true, 3);

            $this->assertTrue($result['ok']);
            $this->assertFalse($result['dry_run']);
            $this->assertCount(1, $result['findings']);
            $this->assertSame(1, $result['emitted_count']);

            $run = AtlasInitiativeRun::query()->firstOrFail();
            $item = AiInboxItem::query()->where('type', 'proposal')->firstOrFail();

            $this->assertSame('refactor_scan', $run->kind);
            $this->assertSame('succeeded', $run->status);
            $this->assertCount(1, $run->findings);
            $this->assertSame([$item->id], $run->emitted_inbox_item_ids);
            $this->assertSame('atlas_initiative_run', $item->source_type);
            $this->assertSame($run->id, $item->source_id);
            $this->assertSame('proposal', $item->type);
            $this->assertStringContainsString('Proposta marcada', $item->title);
            $this->assertContains('review_patch', collect($item->available_actions)->pluck('id')->all());
            $this->assertNotContains('commit', collect($item->available_actions)->pluck('id')->all());
            $this->assertDatabaseCount('ai_context_bundles', 1);

            $again = app(AutoImprovementProposalScanner::class)->scan($workspace, true, 3);

            $this->assertTrue($again['ok']);
            $this->assertSame($item->id, $again['emitted_item_ids'][0] ?? null);
            $this->assertDatabaseCount('atlas_initiative_runs', 2);
            $this->assertDatabaseCount('ai_inbox_items', 1);
            $this->assertDatabaseCount('ai_context_bundles', 1);
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_initiatives_command_runs_refactor_scan_dry_run_without_inbox_emit(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-initiatives-scan-'.Str::uuid();

        try {
            File::ensureDirectoryExists($workspace.'/app/Services');
            $marker = 'ATLAS'.'_PROPOSAL: Separar regra de elegibilidade antes de acoplar mais actions.';
            File::put($workspace.'/app/Services/EligibilityService.php', <<<PHP
<?php

namespace App\Services;

// {$marker}
class EligibilityService
{
    public function handle(): void
    {
    }
}
PHP);

            $exitCode = Artisan::call('atlas:initiatives', [
                'operation' => 'run',
                'initiative' => 'refactor-scan',
                '--workspace' => $workspace,
                '--limit' => 1,
                '--dry-run' => true,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);

            $this->assertSame(0, $exitCode);
            $this->assertSame('refactor-scan', $payload['initiative'] ?? null);
            $this->assertTrue($payload['dry_run'] ?? false);
            $this->assertFalse($payload['emits_inbox'] ?? true);
            $this->assertSame(1, data_get($payload, 'result.findings') ? count(data_get($payload, 'result.findings')) : 0);
            $this->assertDatabaseCount('atlas_initiative_runs', 1);
            $this->assertDatabaseCount('ai_inbox_items', 0);
            $this->assertSame('refactor_scan', AtlasInitiativeRun::query()->firstOrFail()->kind);
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    public function test_expire_stale_command_transitions_items_past_expires_at_when_applied(): void
    {
        $stale = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'approval',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Stale approval',
            'summary' => null,
            'body' => null,
            'initiator' => 'system',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 50,
            'expires_at' => now()->subMinutes(10),
        ]);

        $fresh = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'severity' => 'info',
            'status' => 'unread',
            'title' => 'Fresh insight',
            'summary' => null,
            'body' => null,
            'initiator' => 'system',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 50,
            'expires_at' => now()->addDay(),
        ]);

        $resolved = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'approval',
            'severity' => 'warning',
            'status' => 'resolved',
            'title' => 'Already resolved',
            'summary' => null,
            'body' => null,
            'initiator' => 'system',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 50,
            'expires_at' => now()->subDay(),
            'resolved_at' => now()->subDay(),
        ]);

        $dryExit = Artisan::call('atlas:cli:mobile', [
            'action' => 'expire-stale',
            '--json' => true,
        ]);
        $dryPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $dryExit);
        $this->assertTrue($dryPayload['dry_run'] ?? false);
        $this->assertSame(1, $dryPayload['transitioned'] ?? 0);
        $this->assertSame('unread', $stale->refresh()->status);

        $applyExit = Artisan::call('atlas:cli:mobile', [
            'action' => 'expire-stale',
            '--apply' => true,
            '--json' => true,
        ]);
        $applyPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $applyExit);
        $this->assertFalse($applyPayload['dry_run'] ?? true);
        $this->assertSame(1, $applyPayload['transitioned'] ?? 0);
        $this->assertSame('expired', $stale->refresh()->status);
        $this->assertNotNull($stale->refresh()->resolved_at);
        $this->assertSame('unread', $fresh->refresh()->status);
        $this->assertSame('resolved', $resolved->refresh()->status);

        $this->assertDatabaseHas('audit_events', ['event_type' => 'inbox.expired_batch']);
    }

    public function test_cleanup_command_removes_aged_pairing_codes_inbox_bundles_and_deliveries_when_applied(): void
    {
        $consumedCode = MobilePairingCode::query()->create([
            'user_id' => 'vitor',
            'code_hash' => hash('sha256', 'old-consumed-code'),
            'device_label' => 'old',
            'expires_at' => now()->subDays(60),
            'consumed_at' => now()->subDays(40),
        ]);
        $expiredCode = MobilePairingCode::query()->create([
            'user_id' => 'vitor',
            'code_hash' => hash('sha256', 'old-expired-code'),
            'device_label' => 'old-expired',
            'expires_at' => now()->subDays(30),
        ]);
        $recentCode = MobilePairingCode::query()->create([
            'user_id' => 'vitor',
            'code_hash' => hash('sha256', 'recent-code'),
            'device_label' => 'recent',
            'expires_at' => now()->addHour(),
        ]);

        $oldResolved = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'severity' => 'info',
            'status' => 'resolved',
            'title' => 'Old resolved',
            'summary' => null,
            'body' => null,
            'initiator' => 'system',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 50,
            'resolved_at' => now()->subDays(120),
        ]);
        $oldResolved->forceFill(['updated_at' => now()->subDays(120)])->save();

        $recentResolved = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'severity' => 'info',
            'status' => 'resolved',
            'title' => 'Recent resolved',
            'summary' => null,
            'body' => null,
            'initiator' => 'system',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 50,
            'resolved_at' => now()->subDays(10),
        ]);

        $bundleService = app(ContextBundleService::class);
        $orphanBundle = $bundleService->create([
            'user_id' => 'vitor',
            'purpose' => 'orphan_test',
            'title' => 'Orphan bundle',
            'summary' => 'sumario',
            'body_for_thread' => 'corpo',
            'raw_payload' => [],
            'expires_at' => now()->subDays(60),
        ]);
        $orphanBundle->forceFill(['updated_at' => now()->subDays(60)])->save();

        $referencedBundle = $bundleService->create([
            'user_id' => 'vitor',
            'purpose' => 'referenced_test',
            'title' => 'Referenced bundle',
            'summary' => 'sumario',
            'body_for_thread' => 'corpo',
            'raw_payload' => [],
            'expires_at' => now()->subDays(60),
        ]);
        $referencedBundle->forceFill(['updated_at' => now()->subDays(60)])->save();

        $linkedItem = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'severity' => 'info',
            'status' => 'unread',
            'title' => 'Linked',
            'summary' => null,
            'body' => null,
            'initiator' => 'system',
            'context_bundle_id' => $referencedBundle->id,
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 50,
        ]);

        $oldDelivery = MobilePushDelivery::query()->create([
            'inbox_item_id' => $linkedItem->id,
            'device_id' => null,
            'status' => 'sent',
            'provider' => 'expo',
            'request_payload' => [],
            'response_payload' => [],
            'attempted_at' => now()->subDays(120),
            'created_at' => now()->subDays(120),
            'updated_at' => now()->subDays(120),
        ]);
        $oldDelivery->forceFill(['created_at' => now()->subDays(120)])->save();

        $recentDelivery = MobilePushDelivery::query()->create([
            'inbox_item_id' => $linkedItem->id,
            'device_id' => null,
            'status' => 'sent',
            'provider' => 'expo',
            'request_payload' => [],
            'response_payload' => [],
            'attempted_at' => now(),
        ]);

        $dryExit = Artisan::call('atlas:cli:mobile', [
            'action' => 'cleanup',
            '--json' => true,
        ]);
        $dryPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $dryExit);
        $this->assertTrue($dryPayload['dry_run'] ?? false);
        $this->assertSame(2, $dryPayload['pairing_codes'] ?? 0);
        $this->assertSame(1, $dryPayload['inbox_items'] ?? 0);
        $this->assertSame(1, $dryPayload['context_bundles'] ?? 0);
        $this->assertSame(1, $dryPayload['push_deliveries'] ?? 0);
        $this->assertDatabaseCount('mobile_pairing_codes', 3);

        $applyExit = Artisan::call('atlas:cli:mobile', [
            'action' => 'cleanup',
            '--apply' => true,
            '--json' => true,
        ]);
        $applyPayload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $applyExit);
        $this->assertFalse($applyPayload['dry_run'] ?? true);
        $this->assertNull(MobilePairingCode::query()->find($consumedCode->id));
        $this->assertNull(MobilePairingCode::query()->find($expiredCode->id));
        $this->assertNotNull(MobilePairingCode::query()->find($recentCode->id));
        $this->assertNull(AiInboxItem::query()->find($oldResolved->id));
        $this->assertNotNull(AiInboxItem::query()->find($recentResolved->id));
        $this->assertDatabaseMissing('ai_context_bundles', ['id' => $orphanBundle->id]);
        $this->assertDatabaseHas('ai_context_bundles', ['id' => $referencedBundle->id]);
        $this->assertNull(MobilePushDelivery::query()->find($oldDelivery->id));
        $this->assertNotNull(MobilePushDelivery::query()->find($recentDelivery->id));

        $this->assertDatabaseHas('audit_events', ['event_type' => 'mobile.cleanup.completed']);
    }

    public function test_mobile_health_endpoint_returns_snapshot_for_paired_device(): void
    {
        $token = $this->pairedDeviceToken('ExponentPushToken[health]');
        $device = AtlasMobileDevice::query()->firstOrFail();

        $item = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'severity' => 'info',
            'status' => 'unread',
            'title' => 'Some unread insight',
            'summary' => null,
            'body' => null,
            'initiator' => 'system',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 50,
        ]);

        foreach (range(1, 8) as $i) {
            MobilePushDelivery::query()->create([
                'inbox_item_id' => $item->id,
                'device_id' => $device->id,
                'status' => 'sent',
                'provider' => 'expo',
                'request_payload' => [],
                'response_payload' => [],
                'attempted_at' => now()->subMinutes($i),
            ]);
        }
        MobilePushDelivery::query()->create([
            'inbox_item_id' => $item->id,
            'device_id' => $device->id,
            'status' => 'failed_transient',
            'provider' => 'expo',
            'request_payload' => [],
            'response_payload' => [],
            'attempted_at' => now()->subMinutes(2),
        ]);

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/health')
            ->assertOk()
            ->json();

        $this->assertSame('healthy', $response['status']);
        $this->assertSame('closed', data_get($response, 'expo.circuit.state'));
        $this->assertSame(9, data_get($response, 'push_24h.total'));
        $this->assertSame(8, data_get($response, 'push_24h.sent'));
        $this->assertSame(1, data_get($response, 'push_24h.failed'));
        $this->assertGreaterThan(0.6, data_get($response, 'push_24h.success_rate'));
        $this->assertSame(1, data_get($response, 'devices.active'));
        $this->assertSame(1, data_get($response, 'devices.with_push_token'));
        $this->assertSame(1, data_get($response, 'inbox.unread'));
        $this->assertSame(1, data_get($response, 'inbox.active'));
    }

    public function test_circuit_breaker_opens_after_threshold_and_defers_subsequent_pushes(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.circuit.failure_threshold', 2);
        config()->set('atlas.mobile.circuit.failure_window_seconds', 60);
        config()->set('atlas.mobile.circuit.open_seconds', 300);
        config()->set('atlas.mobile.batching.enabled', false);
        config()->set('atlas.mobile.retry.queue_enabled', false);

        $this->pairedDeviceToken('ExponentPushToken[circuit]');
        $device = AtlasMobileDevice::query()->firstOrFail();

        $bundle = app(ContextBundleService::class)->create([
            'user_id' => 'vitor',
            'purpose' => 'circuit_test',
            'title' => 'Circuit Bundle',
            'summary' => 'sumario',
            'body_for_thread' => 'body',
            'raw_payload' => [],
        ]);

        $inbox = app(AtlasInboxService::class);

        Http::fake(['*' => Http::response(['errors' => [['message' => 'oops']]], 500)]);

        $inbox->create([
            'user_id' => 'vitor',
            'type' => 'alert',
            'severity' => 'warning',
            'title' => 'first',
            'summary' => null,
            'body' => null,
            'initiator' => 'system',
            'context_bundle_id' => $bundle->id,
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'auto'],
        ]);

        $inbox->create([
            'user_id' => 'vitor',
            'type' => 'alert',
            'severity' => 'warning',
            'title' => 'second',
            'summary' => null,
            'body' => null,
            'initiator' => 'system',
            'context_bundle_id' => $bundle->id,
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'auto'],
        ]);

        $this->assertTrue(app(ExpoCircuitBreaker::class)->isOpen());

        $third = $inbox->create([
            'user_id' => 'vitor',
            'type' => 'alert',
            'severity' => 'warning',
            'title' => 'third',
            'summary' => null,
            'body' => null,
            'initiator' => 'system',
            'context_bundle_id' => $bundle->id,
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'auto'],
        ]);

        $deferredCount = MobilePushDelivery::query()
            ->where('inbox_item_id', $third->id)
            ->where('status', 'deferred_circuit_open')
            ->count();
        $this->assertSame(1, $deferredCount);

        $this->assertDatabaseHas('audit_events', ['event_type' => 'mobile.expo.circuit.opened']);
    }

    public function test_circuit_breaker_closes_after_recovery_audit(): void
    {
        config()->set('atlas.mobile.circuit.failure_threshold', 2);

        $circuit = app(ExpoCircuitBreaker::class);
        $circuit->recordFailure();
        $circuit->recordFailure();

        $this->assertTrue($circuit->isOpen());

        $circuit->recordSuccess();

        $this->assertFalse($circuit->isOpen());
        $this->assertSame('closed', $circuit->state()['state']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'mobile.expo.circuit.closed']);
    }

    public function test_send_mobile_push_job_marks_failed_permanent_after_failed_lifecycle(): void
    {
        $this->pairedDeviceToken('ExponentPushToken[job]');
        $device = AtlasMobileDevice::query()->firstOrFail();

        $item = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'alert',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Job retry test',
            'summary' => null,
            'body' => null,
            'initiator' => 'system',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 50,
        ]);

        $delivery = MobilePushDelivery::query()->create([
            'inbox_item_id' => $item->id,
            'device_id' => $device->id,
            'status' => 'queued',
            'provider' => 'expo',
            'request_payload' => [],
            'response_payload' => [],
            'attempted_at' => now(),
        ]);

        $job = new SendMobilePushJob($delivery->id, $device->id, ['to' => $device->expo_push_token]);
        $job->failed(new RuntimeException('exhausted retries'));

        $this->assertSame('failed_permanent', $delivery->refresh()->status);
    }

    public function test_atlas_cli_mobile_status_outputs_snapshot_in_json(): void
    {
        config()->set('atlas.mobile.enabled', true);
        $this->pairedDeviceToken('ExponentPushToken[status]');

        $exitCode = Artisan::call('atlas:cli:mobile', [
            'action' => 'status',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('expo', $payload);
        $this->assertArrayHasKey('configuration', $payload);
        $this->assertArrayHasKey('push_24h', $payload);
        $this->assertArrayHasKey('devices', $payload);
        $this->assertArrayHasKey('inbox', $payload);
        $this->assertSame('healthy', data_get($payload, 'status'));
        $this->assertTrue((bool) data_get($payload, 'configuration.mobile_enabled'));
        $this->assertSame(1, data_get($payload, 'devices.active'));
    }

    public function test_atlas_cli_mobile_status_reports_disabled_push_configuration(): void
    {
        config()->set('atlas.mobile.enabled', false);
        $this->pairedDeviceToken('ExponentPushToken[status-disabled]');

        $exitCode = Artisan::call('atlas:cli:mobile', [
            'action' => 'status',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('disabled', data_get($payload, 'status'));
        $this->assertFalse((bool) data_get($payload, 'configuration.mobile_enabled'));
        $this->assertFalse((bool) data_get($payload, 'configuration.push_dispatch_enabled'));
        $this->assertSame('atlas_mobile_disabled', data_get($payload, 'configuration.push_dispatch_blocked_reason'));
        $this->assertSame(1, data_get($payload, 'devices.with_push_token'));
    }

    public function test_mobile_alert_check_reports_disabled_push_configuration(): void
    {
        config()->set('atlas.mobile.enabled', false);
        Cache::put(MobileReliabilityMonitor::SCHEDULER_TICK_KEY, now()->toJSON(), now()->addHour());

        $snapshot = app(MobileReliabilityMonitor::class)->snapshot();
        $checks = collect($snapshot['checks'])->keyBy('name');

        $this->assertSame('warning', $snapshot['status']);
        $this->assertSame('warning', data_get($checks, 'mobile_push_configuration.status'));
        $this->assertSame('atlas_mobile_disabled', data_get($checks, 'mobile_push_configuration.evidence.blocked_reason'));
    }

    public function test_mobile_alert_check_dry_run_reports_scheduler_stale_without_webhook(): void
    {
        config()->set('atlas.mobile.enabled', true);
        Cache::put(MobileReliabilityMonitor::SCHEDULER_TICK_KEY, now()->subMinutes(10)->toJSON(), now()->addHour());
        Http::fake();

        $exitCode = Artisan::call('atlas:cli:mobile', [
            'action' => 'alert-check',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['dry_run'] ?? false);
        $this->assertSame('critical', $payload['status'] ?? null);
        $this->assertSame('dry_run', data_get($payload, 'alert.reason'));
        $this->assertSame('dry_run', data_get($payload, 'transition.reason'));
        $this->assertSame('critical', collect($payload['checks'])->firstWhere('name', 'scheduler_stale')['status'] ?? null);
        Http::assertNothingSent();
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_mobile_reliability_monitor_waits_for_first_performance_report_delivery_before_alerting(): void
    {
        config()->set('atlas.ai_metrics.performance_report_enabled', true);
        config()->set('atlas.ai_metrics.performance_report_emit', true);
        config()->set('atlas.ai_metrics.performance_report_timezone', 'America/Sao_Paulo');
        config()->set('atlas.ai_metrics.performance_report_time', '07:05');
        config()->set('atlas.ai_metrics.performance_report_grace_minutes', 30);
        Carbon::setTestNow(Carbon::parse('2026-05-01 08:00:00', 'America/Sao_Paulo'));
        Cache::put(MobileReliabilityMonitor::SCHEDULER_TICK_KEY, now()->toJSON(), now()->addHour());

        $snapshot = app(MobileReliabilityMonitor::class)->snapshot();
        $checks = collect($snapshot['checks'])->keyBy('name');

        $this->assertSame('healthy', data_get($checks, 'performance_report_fresh.status'));
        $this->assertTrue(data_get($checks, 'performance_report_fresh.evidence.first_delivery_pending'));
        $this->assertSame('2026-04-30', data_get($checks, 'performance_report_fresh.evidence.report_date'));
    }

    public function test_mobile_reliability_monitor_detects_missing_daily_performance_report_after_grace(): void
    {
        config()->set('atlas.ai_metrics.performance_report_enabled', true);
        config()->set('atlas.ai_metrics.performance_report_emit', true);
        config()->set('atlas.ai_metrics.performance_report_timezone', 'America/Sao_Paulo');
        config()->set('atlas.ai_metrics.performance_report_time', '07:05');
        config()->set('atlas.ai_metrics.performance_report_grace_minutes', 30);
        Carbon::setTestNow(Carbon::parse('2026-05-01 08:00:00', 'America/Sao_Paulo'));
        Cache::put(MobileReliabilityMonitor::SCHEDULER_TICK_KEY, now()->toJSON(), now()->addHour());
        AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas_ai_performance',
            'severity' => 'info',
            'status' => 'unread',
            'title' => 'Atlas: relatorio de performance de 29/04/2026',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'dedupe_key' => 'atlas-ai-performance:daily:2026-04-29',
            'priority_score' => 50,
        ]);

        $missing = app(MobileReliabilityMonitor::class)->snapshot();
        $missingChecks = collect($missing['checks'])->keyBy('name');

        $this->assertSame('critical', $missing['status']);
        $this->assertSame('critical', data_get($missingChecks, 'performance_report_fresh.status'));
        $this->assertSame('2026-04-30', data_get($missingChecks, 'performance_report_fresh.evidence.report_date'));

        AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'atlas_ai_performance',
            'severity' => 'info',
            'status' => 'unread',
            'title' => 'Atlas: relatorio de performance de 30/04/2026',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'dedupe_key' => 'atlas-ai-performance:daily:2026-04-30',
            'priority_score' => 50,
        ]);

        $fresh = app(MobileReliabilityMonitor::class)->snapshot();
        $freshChecks = collect($fresh['checks'])->keyBy('name');

        $this->assertSame('healthy', data_get($freshChecks, 'performance_report_fresh.status'));
    }

    public function test_mobile_alert_check_apply_sends_webhook_once_per_cooldown_and_records_recovery(): void
    {
        config()->set('atlas.mobile.enabled', true);
        config()->set('atlas.mobile.alerts.webhook_url', 'https://alerts.test/atlas');
        config()->set('atlas.mobile.alerts.cooldown_minutes', 30);
        Cache::put(MobileReliabilityMonitor::SCHEDULER_TICK_KEY, now()->subMinutes(10)->toJSON(), now()->addHour());
        Http::fake(['https://alerts.test/atlas' => Http::response(['ok' => true], 200)]);

        $firstExit = Artisan::call('atlas:cli:mobile', [
            'action' => 'alert-check',
            '--apply' => true,
            '--json' => true,
        ]);
        $first = json_decode(Artisan::output(), true);

        $this->assertSame(0, $firstExit);
        $this->assertFalse($first['dry_run'] ?? true);
        $this->assertSame('critical', $first['status'] ?? null);
        $this->assertTrue(data_get($first, 'alert.sent'));
        $this->assertSame('system.health.degraded', data_get($first, 'transition.event_type'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://alerts.test/atlas'
            && $request['source'] === 'atlas_mobile_reliability_monitor'
            && $request['status'] === 'critical');
        $this->assertDatabaseHas('audit_events', ['event_type' => 'system.health.degraded']);

        $secondExit = Artisan::call('atlas:cli:mobile', [
            'action' => 'alert-check',
            '--apply' => true,
            '--json' => true,
        ]);
        $second = json_decode(Artisan::output(), true);

        $this->assertSame(0, $secondExit);
        $this->assertSame('cooldown', data_get($second, 'alert.reason'));
        Http::assertSentCount(1);
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'system.health.degraded')->count());

        Cache::put(MobileReliabilityMonitor::SCHEDULER_TICK_KEY, now()->toJSON(), now()->addHour());

        $recoveryExit = Artisan::call('atlas:cli:mobile', [
            'action' => 'alert-check',
            '--apply' => true,
            '--json' => true,
        ]);
        $recovery = json_decode(Artisan::output(), true);

        $this->assertSame(0, $recoveryExit);
        $this->assertSame('healthy', $recovery['status'] ?? null);
        $this->assertSame('healthy', data_get($recovery, 'alert.reason'));
        $this->assertSame('system.health.recovered', data_get($recovery, 'transition.event_type'));
        $this->assertDatabaseHas('audit_events', ['event_type' => 'system.health.recovered']);
    }

    public function test_mobile_alert_check_writes_local_jsonl_without_webhook(): void
    {
        config()->set('atlas.mobile.enabled', true);
        $path = storage_path('framework/testing/atlas-health-alerts-'.Str::uuid().'.jsonl');
        config()->set('atlas.mobile.alerts.webhook_url', null);
        config()->set('atlas.mobile.alerts.local_log_enabled', true);
        config()->set('atlas.mobile.alerts.local_log_path', $path);
        Cache::put(MobileReliabilityMonitor::SCHEDULER_TICK_KEY, now()->subMinutes(10)->toJSON(), now()->addHour());

        try {
            $degradedExit = Artisan::call('atlas:cli:mobile', [
                'action' => 'alert-check',
                '--apply' => true,
                '--json' => true,
            ]);
            $degraded = json_decode(Artisan::output(), true);

            $this->assertSame(0, $degradedExit);
            $this->assertSame('critical', $degraded['status'] ?? null);
            $this->assertSame('webhook_not_configured', data_get($degraded, 'alert.reason'));
            $this->assertTrue(File::exists($path));

            $lines = array_values(array_filter(explode("\n", trim(File::get($path)))));
            $this->assertCount(1, $lines);
            $first = json_decode($lines[0], true);
            $this->assertSame('system.health.degraded', $first['event_type'] ?? null);
            $this->assertSame('critical', $first['status'] ?? null);

            Cache::put(MobileReliabilityMonitor::SCHEDULER_TICK_KEY, now()->toJSON(), now()->addHour());
            $recoveryExit = Artisan::call('atlas:cli:mobile', [
                'action' => 'alert-check',
                '--apply' => true,
                '--json' => true,
            ]);

            $this->assertSame(0, $recoveryExit);
            $lines = array_values(array_filter(explode("\n", trim(File::get($path)))));
            $this->assertCount(2, $lines);
            $second = json_decode($lines[1], true);
            $this->assertSame('system.health.recovered', $second['event_type'] ?? null);
            $this->assertSame('healthy', $second['status'] ?? null);
        } finally {
            File::delete($path);
        }
    }

    public function test_mobile_reliability_monitor_detects_push_circuit_and_job_degradation(): void
    {
        config()->set('atlas.mobile.alerts.push_min_sample', 5);
        config()->set('atlas.mobile.alerts.push_sample_size', 5);
        config()->set('atlas.mobile.alerts.push_success_rate_threshold', 0.5);
        config()->set('atlas.mobile.alerts.circuit_stuck_minutes', 1);
        config()->set('atlas.mobile.alerts.jobs_silent_hours', 1);
        config()->set('atlas.mobile.circuit.failure_threshold', 1);
        config()->set('atlas.mobile.circuit.open_seconds', 3600);

        Cache::put(MobileReliabilityMonitor::SCHEDULER_TICK_KEY, now()->toJSON(), now()->addHour());

        $item = AiInboxItem::query()->create([
            'user_id' => 'vitor',
            'type' => 'alert',
            'severity' => 'warning',
            'status' => 'unread',
            'title' => 'Reliability sample',
            'available_actions' => [],
            'payload' => [],
            'push_policy' => ['send' => 'none'],
            'priority_score' => 50,
        ]);

        foreach (range(1, 5) as $i) {
            MobilePushDelivery::query()->create([
                'inbox_item_id' => $item->id,
                'device_id' => null,
                'status' => $i === 1 ? 'sent' : 'failed_permanent',
                'provider' => 'expo',
                'request_payload' => [],
                'response_payload' => [],
                'attempted_at' => now()->subMinutes($i),
            ]);
        }

        app(ExpoCircuitBreaker::class)->recordFailure();
        Cache::put('atlas:mobile:expo:circuit:state', [
            'state' => 'open',
            'opened_at' => now()->subMinutes(5)->toJSON(),
            'opens_until' => now()->addHour()->toJSON(),
            'trigger_count' => 1,
        ], now()->addHour());

        AiJob::query()->create([
            'kind' => 'silent_job',
            'status' => 'succeeded',
            'prompt' => 'Finished long ago',
            'context_refs' => [],
            'payload' => [],
            'finished_at' => now()->subHours(2),
            'attempts' => 1,
            'max_attempts' => 1,
            'metadata' => [],
        ]);
        AiJob::query()->create([
            'kind' => 'silent_job',
            'status' => 'processing',
            'prompt' => 'Still processing',
            'context_refs' => [],
            'payload' => [],
            'attempts' => 1,
            'max_attempts' => 1,
            'metadata' => [],
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $snapshot = app(MobileReliabilityMonitor::class)->snapshot();
        $checks = collect($snapshot['checks'])->keyBy('name');

        $this->assertSame('warning', $snapshot['status']);
        $this->assertSame('warning', data_get($checks, 'push_degraded.status'));
        $this->assertSame('warning', data_get($checks, 'circuit_stuck.status'));
        $this->assertSame('warning', data_get($checks, 'jobs_silent.status'));
        $this->assertSame('healthy', data_get($checks, 'scheduler_stale.status'));
    }

    public function test_mobile_inbox_index_returns_compact_fast_payload(): void
    {
        $token = $this->pairedDeviceToken();
        $userId = AtlasMobileDevice::query()->firstOrFail()->user_id;

        // Heavy body + payload on every row: a *full* list of these would be
        // very large and slow. The compact list endpoint must strip them.
        $heavyBody = str_repeat('conteudo pesado de corpo do item operacional. ', 200);
        $heavyPayload = ['blob' => str_repeat('x', 4000), 'rows' => array_fill(0, 40, ['k' => 'v', 'n' => 123])];

        for ($i = 0; $i < 22; $i++) {
            AiInboxItem::query()->create([
                'user_id' => $userId,
                'type' => 'approval',
                'category' => 'atlas',
                'severity' => $i % 5 === 0 ? 'critical' : 'info',
                'status' => 'unread',
                'title' => "Item operacional {$i}",
                'summary' => "Resumo do item {$i}",
                'body' => $heavyBody,
                'initiator' => 'atlas',
                'dedupe_key' => "approval:op:{$userId}:{$i}",
                'available_actions' => [['id' => 'approve', 'label' => 'Aprovar'], ['id' => 'dismiss', 'label' => 'Descartar']],
                'payload' => $heavyPayload,
                'push_policy' => ['send' => 'none'],
                'priority_score' => 50 + $i,
                'created_at' => now()->subMinutes(22 - $i),
                'updated_at' => now()->subMinutes(22 - $i),
            ]);
        }

        $start = microtime(true);
        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/inbox?status=active&limit=12')
            ->assertOk();
        $elapsedMs = (microtime(true) - $start) * 1000;

        $json = $response->json();
        $this->assertCount(12, $json['items'], 'limit must cap the list at 12');
        $this->assertNotNull($json['next_cursor'], 'cursor present when more rows than the limit');
        $this->assertSame(22, $json['unread_count']);

        foreach ($json['items'] as $item) {
            $this->assertNull($item['body'], 'compact list must not ship body');
            $this->assertSame([], $item['payload'], 'compact list must not ship payload');
            $this->assertNull($item['presentation'], 'compact list must not compute heavy presentation');
            $this->assertArrayHasKey('title', $item);
            $this->assertArrayHasKey('available_actions', $item);
            $this->assertArrayHasKey('severity', $item);
        }

        // 12 rows each carrying ~9KB of body+payload would be >100KB if shipped
        // full; the compact payload must stay small and cheap.
        $encodedBytes = strlen((string) $response->getContent());
        $this->assertLessThan(30000, $encodedBytes, "compact list payload too large: {$encodedBytes} bytes");

        // Route + middleware + query + serialization must be fast in-process
        // (catches N+1 / heavy per-item work — generous bound to avoid flake).
        $this->assertLessThan(2000, $elapsedMs, "inbox index too slow in-process: {$elapsedMs}ms");
    }

    public function test_mobile_inbox_index_respects_active_status_and_limit(): void
    {
        $token = $this->pairedDeviceToken();
        $userId = AtlasMobileDevice::query()->firstOrFail()->user_id;

        foreach ([0, 1, 2] as $i) {
            AiInboxItem::query()->create([
                'user_id' => $userId, 'type' => 'approval', 'severity' => 'info', 'status' => 'unread',
                'title' => "active {$i}", 'initiator' => 'atlas', 'dedupe_key' => "active:{$userId}:{$i}",
                'available_actions' => [], 'payload' => [], 'push_policy' => ['send' => 'none'],
                'priority_score' => 50, 'created_at' => now()->subMinutes($i), 'updated_at' => now()->subMinutes($i),
            ]);
        }
        AiInboxItem::query()->create([
            'user_id' => $userId, 'type' => 'approval', 'severity' => 'info', 'status' => 'resolved',
            'title' => 'resolved', 'initiator' => 'atlas', 'dedupe_key' => "terminal:{$userId}:resolved",
            'available_actions' => [], 'payload' => [], 'push_policy' => ['send' => 'none'],
            'priority_score' => 50, 'resolved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        AiInboxItem::query()->create([
            'user_id' => $userId, 'type' => 'approval', 'severity' => 'info', 'status' => 'dismissed',
            'title' => 'dismissed', 'initiator' => 'atlas', 'dedupe_key' => "terminal:{$userId}:dismissed",
            'available_actions' => [], 'payload' => [], 'push_policy' => ['send' => 'none'],
            'priority_score' => 50, 'dismissed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $json = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/inbox?status=active&limit=2')
            ->assertOk()
            ->json();

        $this->assertCount(2, $json['items'], 'limit must be respected');
        foreach ($json['items'] as $item) {
            $this->assertNotContains($item['status'], ['resolved', 'dismissed', 'expired'], 'active must exclude terminal statuses');
        }
    }

    public function test_mobile_inbox_show_returns_full_payload(): void
    {
        $token = $this->pairedDeviceToken();
        $userId = AtlasMobileDevice::query()->firstOrFail()->user_id;

        $item = AiInboxItem::query()->create([
            'user_id' => $userId, 'type' => 'approval', 'category' => 'atlas', 'severity' => 'info', 'status' => 'unread',
            'title' => 'Detalhe', 'summary' => 'resumo', 'body' => 'corpo completo detalhado do item',
            'initiator' => 'atlas', 'dedupe_key' => "detail:{$userId}:1",
            'available_actions' => [['id' => 'approve', 'label' => 'Aprovar']], 'payload' => ['k' => 'v'],
            'push_policy' => ['send' => 'none'], 'priority_score' => 60, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $json = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/v1/mobile/inbox/'.$item->id)
            ->assertOk()
            ->json();

        $this->assertSame('corpo completo detalhado do item', $json['item']['body'], 'detail must ship the full body');
        $this->assertNotNull($json['item']['presentation'], 'detail must include rich human presentation');
        $this->assertSame(['k' => 'v'], $json['item']['payload'], 'detail must ship the full payload');
    }

    private function pairedDeviceToken(?string $expoPushToken = null): string
    {
        $init = $this
            ->withHeader('X-Atlas-Token', 'testing-atlas-token-with-enough-length')
            ->postJson('/v1/mobile/pairing/initiate', ['device_label' => 'iPhone Test'])
            ->json();

        return $this
            ->postJson('/v1/mobile/pairing/confirm', [
                'code' => $init['code'],
                'platform' => 'ios',
                'device_label' => 'iPhone Test',
                'expo_push_token' => $expoPushToken,
            ])
            ->json('device_token');
    }

    private function seedQualityRegression(): void
    {
        foreach (range(1, 6) as $i) {
            $evaluation = AiQualityEvaluation::query()->create([
                'provider' => 'claude_cli',
                'evaluator_version' => 'test',
                'score' => 90,
                'status' => 'passed',
                'dimensions' => [],
                'flags' => [],
                'suggested_actions' => [],
                'metadata' => ['sample' => 'baseline-'.$i],
            ]);
            $evaluation->forceFill([
                'created_at' => now()->subDays(20),
                'updated_at' => now()->subDays(20),
            ])->save();
        }

        foreach (range(1, 4) as $i) {
            $evaluation = AiQualityEvaluation::query()->create([
                'provider' => 'claude_cli',
                'evaluator_version' => 'test',
                'score' => 62,
                'status' => $i === 1 ? 'failed' : 'needs_review',
                'dimensions' => [],
                'flags' => [],
                'suggested_actions' => [],
                'metadata' => ['sample' => 'recent-'.$i],
            ]);
            $evaluation->forceFill([
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ])->save();
        }
    }

    private function createMobileTables(): void
    {
        $this->dropMobileTables();

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 120);
            $table->string('subject_type', 120)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('actor_type', 80)->default('system');
            $table->string('actor_id', 160)->nullable();
            $table->string('severity', 20)->default('info');
            $table->text('summary');
            $table->json('evidence')->default('{}');
            $table->json('privacy')->default('{}');
            $table->json('refs')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('atlas_mobile_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor');
            $table->string('device_label');
            $table->string('platform');
            $table->string('app_version')->nullable();
            $table->string('os_version')->nullable();
            $table->text('expo_push_token')->nullable();
            $table->string('push_token_hash')->nullable();
            $table->string('device_token_hash')->unique();
            $table->string('notification_permissions')->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('mobile_pairing_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor');
            $table->string('code_hash')->unique();
            $table->string('device_label');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_context_bundles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor');
            $table->string('purpose');
            $table->string('title');
            $table->text('summary');
            $table->text('body_for_thread');
            $table->json('source_refs')->nullable();
            $table->json('trace_refs')->nullable();
            $table->json('job_refs')->nullable();
            $table->json('metric_refs')->nullable();
            $table->json('file_refs')->nullable();
            $table->json('diff_refs')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('redaction_status')->default('clean');
            $table->integer('token_estimate')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_inbox_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('user_id')->default('vitor');
            $table->string('type');
            $table->string('category')->nullable();
            $table->string('severity')->default('info');
            $table->string('status')->default('unread');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('body')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('initiator')->default('system');
            $table->uuid('context_bundle_id')->nullable();
            $table->string('dedupe_key')->nullable();
            $table->json('available_actions')->nullable();
            $table->json('response')->nullable();
            $table->json('payload')->nullable();
            $table->text('deep_link')->nullable();
            $table->json('push_policy')->nullable();
            $table->smallInteger('priority_score')->default(50);
            $table->decimal('confidence_score', 4, 3)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
        });

        Schema::getConnection()->statement(<<<'SQL'
            CREATE UNIQUE INDEX ai_inbox_items_active_dedupe_unique
            ON ai_inbox_items (user_id, dedupe_key)
            WHERE dedupe_key IS NOT NULL
              AND status NOT IN ('resolved', 'dismissed', 'expired')
        SQL);

        Schema::create('mobile_push_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('inbox_item_id');
            $table->uuid('device_id')->nullable();
            $table->string('status');
            $table->string('provider')->default('expo');
            $table->text('provider_ticket_id')->nullable();
            $table->text('provider_receipt_id')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_initiative_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind');
            $table->string('status')->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('scope')->nullable();
            $table->json('findings')->nullable();
            $table->json('emitted_inbox_item_ids')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('health_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->unique();
            $table->string('source')->default('atlas_app');
            $table->date('snapshot_date');
            $table->string('snapshot_timezone');
            $table->timestamp('computed_at');
            $table->integer('signal_count')->default(0);
            $table->smallInteger('readiness_score')->nullable();
            $table->float('sleep_duration_hours')->nullable();
            $table->float('confidence')->nullable();
            $table->json('metrics')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->string('client_id')->nullable();
            $table->string('kind')->default('interaction');
            $table->string('status')->default('queued');
            $table->integer('priority')->default(50);
            $table->string('agent_slug')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('input_text')->nullable();
            $table->text('prompt');
            $table->json('context_refs')->nullable();
            $table->json('payload')->nullable();
            $table->text('result_text')->nullable();
            $table->json('result_json')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(1);
            $table->integer('timeout_seconds')->nullable();
            $table->string('worker_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_traces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('trace_key')->unique();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('source_type')->default('app');
            $table->uuid('source_id')->nullable();
            $table->string('status')->default('queued');
            $table->text('operator_input');
            $table->string('intent')->nullable();
            $table->string('agent_slug')->default('orquestrador');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->json('skill_versions')->nullable();
            $table->json('context_refs')->nullable();
            $table->string('prompt_hash')->nullable();
            $table->string('response_hash')->nullable();
            $table->text('response_text')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->integer('feedback_score')->nullable();
            $table->string('feedback_action')->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_provider_cost_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 80);
            $table->string('model', 120);
            $table->unsignedInteger('input_microusd_per_1k');
            $table->unsignedInteger('output_microusd_per_1k');
            $table->string('currency', 8)->default('USD');
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_until')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_quality_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('trace_id')->nullable();
            $table->uuid('thread_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->string('evaluator_version')->default('heuristic-v1');
            $table->smallInteger('score');
            $table->string('status');
            $table->json('dimensions')->nullable();
            $table->json('flags')->nullable();
            $table->json('suggested_actions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        (require database_path('migrations/2026_05_01_011000_create_ai_performance_recommendations.php'))->up();

        Schema::create('ai_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('title');
            $table->text('summary')->nullable();
            $table->string('status')->default('active');
            $table->string('surface')->default('app');
            $table->string('workspace')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->uuid('last_trace_id')->nullable();
            $table->string('last_provider')->nullable();
            $table->integer('message_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->uuid('trace_id')->nullable();
            $table->integer('position');
            $table->string('role');
            $table->string('status')->default('final');
            $table->text('content');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('agent_slug')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function dropMobileTables(): void
    {
        Schema::dropIfExists('atlas_loop_targets');
        Schema::dropIfExists('atlas_loop_proposals');
        Schema::dropIfExists('atlas_loop_tasks');
        Schema::dropIfExists('atlas_loop_campaigns');
        Schema::dropIfExists('atlas_ledger_events');
        Schema::dropIfExists('ai_provider_handoffs');
        Schema::dropIfExists('ai_context_snapshots');
        Schema::dropIfExists('ai_compactions');
        Schema::dropIfExists('ai_session_states');
        Schema::dropIfExists('ai_sessions');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_threads');
        Schema::dropIfExists('ai_performance_recommendations');
        Schema::dropIfExists('ai_quality_evaluations');
        Schema::dropIfExists('ai_provider_cost_rates');
        Schema::dropIfExists('ai_traces');
        Schema::dropIfExists('ai_jobs');
        Schema::dropIfExists('health_snapshots');
        Schema::dropIfExists('atlas_initiative_runs');
        Schema::dropIfExists('mobile_push_deliveries');
        Schema::dropIfExists('ai_inbox_items');
        Schema::dropIfExists('ai_context_bundles');
        Schema::dropIfExists('mobile_pairing_codes');
        Schema::dropIfExists('atlas_mobile_devices');
        Schema::dropIfExists('audit_events');
    }

    private function createGatewaySchemaReadyTables(): void
    {
        foreach ([
            'ai_sessions',
            'ai_session_states',
            'ai_compactions',
            'ai_context_snapshots',
            'ai_provider_handoffs',
        ] as $tableName) {
            if (Schema::hasTable($tableName)) {
                continue;
            }

            Schema::create($tableName, function (Blueprint $table): void {
                $table->uuid('id')->primary();
            });
        }
    }

    private function bootLoopRuntimeTables(): void
    {
        foreach ([
            '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
            '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
        ] as $file) {
            (require base_path('database/migrations/'.$file))->up();
        }
        AtlasLoopProposal::$governedMergeInProgress = false;
    }

    /**
     * L5-9 governed merge door: a FOREIGN repo only crosses when the operator has enabled
     * multi-repo AND allow-listed the canonical absolute path. The frozen tests must supply
     * that authorization exactly as the operator does — never weaken the door.
     */
    private function authorizeLoopRepoForGovernedMerge(string $repo): void
    {
        $canonical = realpath($repo) ?: $repo;
        config([
            'atlas.ai.loop.multi_repo.enabled' => true,
            'atlas.ai.loop.multi_repo.allowed_repos' => [$canonical],
        ]);
    }

    private function loopReviewRepo(string $path, string $contents): string
    {
        $dir = sys_get_temp_dir().'/atlas-mobile-loop-review-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists(dirname($dir.'/'.$path));
        File::put($dir.'/'.$path, $contents);
        File::put($dir.'/composer.json', "{}\n");
        $this->loopReviewGit($dir, ['init', '-q']);
        $this->loopReviewGit($dir, ['add', '-A']);
        $this->loopReviewGit($dir, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        return $dir;
    }

    private function loopReviewDiff(string $repo, string $path, string $modified): string
    {
        File::put($repo.'/'.$path, $modified);
        $diff = $this->loopReviewGit($repo, ['diff']);
        $this->loopReviewGit($repo, ['checkout', '--', $path]);

        return $diff;
    }

    /**
     * @param  list<string>  $argv
     */
    private function loopReviewGit(string $cwd, array $argv): string
    {
        $process = new Process(array_merge(['git'], $argv), $cwd);
        $process->run();

        return trim($process->getOutput());
    }
}
