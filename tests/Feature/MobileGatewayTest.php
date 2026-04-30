<?php

namespace Tests\Feature;

use App\Models\AiInboxItem;
use App\Models\AiJob;
use App\Models\AiQualityEvaluation;
use App\Models\AtlasInitiativeRun;
use App\Models\AtlasMobileDevice;
use App\Models\MobilePushDelivery;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Mobile\AutoImprovementProposalScanner;
use App\Services\Ai\Mobile\ContextBundleService;
use App\Services\Ai\Mobile\InsightInboxEmitter;
use App\Services\Ai\Mobile\JobResultInboxEmitter;
use App\Services\Ai\Mobile\MobilePushService;
use App\Services\Ai\Mobile\ProposalInboxEmitter;
use App\Services\Ai\Mobile\SelfDiagnosticEmitter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'testing-atlas-token-with-enough-length');
        config()->set('atlas.mobile.enabled', false);
        $this->createMobileTables();
    }

    protected function tearDown(): void
    {
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
    }

    public function test_approval_response_resolves_item(): void
    {
        $token = $this->pairedDeviceToken();
        $item = app(AtlasInboxService::class)->create([
            'type' => 'approval',
            'title' => 'Atlas precisa editar arquivo',
            'summary' => 'Permissao para tool runtime.',
            'payload' => ['tool' => 'shell', 'risk' => 'medium'],
        ]);

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
        $this->assertSame('sent', MobilePushDelivery::query()->firstOrFail()->status);
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

        $result = app(SelfDiagnosticEmitter::class)->run();

        $this->assertTrue($result['emitted']);
        $this->assertSame('emitted', $result['reason']);
        $item = AiInboxItem::query()->findOrFail($result['item_id']);
        $this->assertSame('self_diagnostic', $item->type);
        $this->assertSame('quality_score_regression', $item->category);
        $this->assertSame('atlas', $item->initiator);
        $this->assertSame('quality_score_regression', $item->payload['category']);
        $this->assertContains('discuss', collect($item->available_actions)->pluck('id')->all());
        $this->assertDatabaseCount('ai_context_bundles', 1);

        $again = app(SelfDiagnosticEmitter::class)->run();
        $this->assertFalse($again['emitted']);
        $this->assertSame('deduped_active_item', $again['reason']);
        $this->assertSame($item->id, $again['item_id']);
        $this->assertDatabaseCount('ai_inbox_items', 1);
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

    public function test_proposal_emitter_creates_safe_review_item_without_commit_action(): void
    {
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

    private function createMobileTables(): void
    {
        $this->dropMobileTables();

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
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_threads');
        Schema::dropIfExists('ai_quality_evaluations');
        Schema::dropIfExists('ai_jobs');
        Schema::dropIfExists('atlas_initiative_runs');
        Schema::dropIfExists('mobile_push_deliveries');
        Schema::dropIfExists('ai_inbox_items');
        Schema::dropIfExists('ai_context_bundles');
        Schema::dropIfExists('mobile_pairing_codes');
        Schema::dropIfExists('atlas_mobile_devices');
    }
}
